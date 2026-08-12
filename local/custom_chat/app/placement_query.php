<?php
/**
 * Разбор PLACEMENT_OPTIONS / activity → query для КЦ (leadId/dealId/chatId).
 * Activity читаем через REST + AUTH_ID (без prolog — BitrixMobile).
 *
 * @param array<string, mixed> $options
 * @param string $placement
 * @return array<string, scalar>
 */
function waCcAppBuildQueryFromPlacement(array $options, $placement = '')
{
	$query = [];
	$placementUp = strtoupper((string)$placement);

	$entityId = (int)($options['ID'] ?? $options['id'] ?? 0);
	if ($entityId > 0) {
		if (strpos($placementUp, 'LEAD') !== false) {
			$query['leadId'] = $entityId;
		} elseif (strpos($placementUp, 'DEAL') !== false) {
			$query['dealId'] = $entityId;
		}
	}

	foreach (['leadId', 'LEAD_ID', 'dealId', 'DEAL_ID', 'chatId', 'CHAT_ID'] as $k) {
		if (empty($options[$k])) {
			continue;
		}
		$v = (int)$options[$k];
		if ($v <= 0) {
			continue;
		}
		$lk = strtolower($k);
		if (strpos($lk, 'lead') !== false) {
			$query['leadId'] = $v;
		} elseif (strpos($lk, 'deal') !== false) {
			$query['dealId'] = $v;
		} elseif (strpos($lk, 'chat') !== false) {
			$query['chatId'] = $v;
		}
	}

	$ownerId = (int)($options['owner_id'] ?? $options['OWNER_ID'] ?? 0);
	$ownerType = (int)($options['owner_type_id'] ?? $options['OWNER_TYPE_ID'] ?? 0);
	if ($ownerId > 0 && $ownerType > 0) {
		if ($ownerType === 1) {
			$query['leadId'] = $ownerId;
		} elseif ($ownerType === 2) {
			$query['dealId'] = $ownerId;
		}
	}

	$activityId = (int)($options['activity_id'] ?? $options['ACTIVITY_ID'] ?? 0);
	if ($activityId > 0) {
		$fromAct = waCcAppQueryFromActivityIdRest($activityId);
		$query = array_merge($fromAct, $query);
	}

	if (empty($query['leadId']) && empty($query['dealId']) && empty($query['chatId'])) {
		$query = array_merge(waCcAppQueryFromRequestFallback($options), $query);
	}

	return $query;
}

/**
 * @param array<string, mixed> $options
 * @return array<string, scalar>
 */
function waCcAppQueryFromRequestFallback(array $options)
{
	$out = [];
	foreach (['ENTITY_ID', 'entity_id', 'OWNER_ID', 'owner_id'] as $k) {
		if (empty($options[$k])) {
			continue;
		}
		$id = (int)$options[$k];
		$type = (int)($options['OWNER_TYPE_ID'] ?? $options['owner_type_id'] ?? $options['ENTITY_TYPE_ID'] ?? 0);
		if ($id > 0 && $type === 1) {
			$out['leadId'] = $id;
		}
		if ($id > 0 && $type === 2) {
			$out['dealId'] = $id;
		}
	}
	return $out;
}

/**
 * crm.activity.get через AUTH_ID приложения (без prolog).
 *
 * @return array<string, scalar>
 */
function waCcAppQueryFromActivityIdRest($activityId)
{
	$activityId = (int)$activityId;
	$out = [];
	if ($activityId <= 0 || !function_exists('waCcAppRestCallAuth')) {
		return $out;
	}

	$ctx = function_exists('waCcAppRequestAuthContext') ? waCcAppRequestAuthContext() : [
		'authId' => (string)($_REQUEST['AUTH_ID'] ?? $_REQUEST['auth'] ?? ''),
		'domain' => preg_replace('/:(443|80)$/', '', (string)($_REQUEST['DOMAIN'] ?? $_SERVER['HTTP_HOST'] ?? '')),
		'scheme' => 'https',
	];
	$ctx['domain'] = preg_replace('/:(443|80)$/', '', (string)$ctx['domain']);
	if ($ctx['authId'] === '' || $ctx['domain'] === '') {
		return $out;
	}

	$res = waCcAppRestCallAuth($ctx['scheme'], $ctx['domain'], $ctx['authId'], 'crm.activity.get', [
		'id' => $activityId,
	]);
	if (empty($res['ok'])) {
		$res = waCcAppRestCallAuth($ctx['scheme'], $ctx['domain'], $ctx['authId'], 'crm.activity.get', [
			'ID' => $activityId,
		]);
	}
	if (empty($res['ok']) || !is_array($res['result'])) {
		return $out;
	}
	$act = $res['result'];

	$ownerType = (int)($act['OWNER_TYPE_ID'] ?? $act['ownerTypeId'] ?? 0);
	$ownerId = (int)($act['OWNER_ID'] ?? $act['ownerId'] ?? 0);
	if ($ownerId > 0) {
		if ($ownerType === 1) {
			$out['leadId'] = $ownerId;
		} elseif ($ownerType === 2) {
			$out['dealId'] = $ownerId;
		}
	}

	$settings = $act['SETTINGS'] ?? $act['settings'] ?? null;
	if (is_string($settings) && $settings !== '') {
		$decoded = json_decode($settings, true);
		if (!is_array($decoded)) {
			$u = @unserialize($settings);
			$decoded = is_array($u) ? $u : [];
		}
		$settings = $decoded;
	}
	if (is_array($settings)) {
		if (!empty($settings['wa_lead_id'])) {
			$out['leadId'] = (int)$settings['wa_lead_id'];
		}
		if (!empty($settings['wa_deal_id'])) {
			$out['dealId'] = (int)$settings['wa_deal_id'];
		}
		if (!empty($settings['wa_chat_id'])) {
			$out['chatId'] = (int)$settings['wa_chat_id'];
		}
	}

	return $out;
}
