<?php
/**
 * Fix Voximplant/CRM phone when office SIP sends sip35/sip36 instead of real CID.
 * Asterisk logs real number via /local/crm/cid_bridge.php, patch on onCallEnd.
 *
 * init.php:
 *   $waVoxCidFix = $_SERVER['DOCUMENT_ROOT'] . '/local/crm/include_vox_cid_fix.php';
 *   if (is_file($waVoxCidFix)) { require_once $waVoxCidFix; }
 */

if (!function_exists('waVoxCidFix_bridgeFile')) {
	function waVoxCidFix_bridgeFile(): string
	{
		return $_SERVER['DOCUMENT_ROOT'] . '/local/crm/cid-bridge/recent.jsonl';
	}
}

if (!function_exists('waVoxCidFix_isBadPhone')) {
	function waVoxCidFix_isBadPhone($phone): bool
	{
		$p = strtolower(trim((string)$phone));
		if ($p === '' || $p === '0') {
			return true;
		}
		if (in_array($p, ['sip35', 'sip36', '35', '36'], true)) {
			return true;
		}
		$digits = preg_replace('/\D+/', '', $p);
		if ($digits === '') {
			return true;
		}
		if (strlen($digits) < 10) {
			return true;
		}
		return false;
	}
}

if (!function_exists('waVoxCidFix_normPhone')) {
	function waVoxCidFix_normPhone($phone): string
	{
		$c = trim((string)$phone);
		$digits = preg_replace('/\D+/', '', $c);
		if ($digits === '') {
			return '';
		}
		if (strlen($digits) === 11 && $digits[0] === '8') {
			$digits = '7' . substr($digits, 1);
		}
		if (strlen($digits) === 11 && $digits[0] === '7') {
			return '+' . $digits;
		}
		if (strlen($digits) === 10 && $digits[0] === '7') {
			return '+' . $digits;
		}
		return '+' . $digits;
	}
}

if (!function_exists('waVoxCidFix_portalToDid')) {
	function waVoxCidFix_portalToDid(string $portal): string
	{
		$p = strtolower(trim($portal));
		if ($p === 'sip35' || $p === '3888' || strpos($p, '3888') !== false) {
			return '3888';
		}
		if ($p === 'sip36' || $p === '8099' || strpos($p, '8099') !== false) {
			return '8099';
		}
		return '';
	}
}

if (!function_exists('waVoxCidFix_lookupCid')) {
	function waVoxCidFix_lookupCid(string $did, $callStart): ?string
	{
		$file = waVoxCidFix_bridgeFile();
		if (!is_file($file)) {
			return null;
		}
		$ts = 0;
		if ($callStart instanceof \Bitrix\Main\Type\DateTime) {
			$ts = $callStart->getTimestamp();
		} elseif (is_string($callStart) && $callStart !== '') {
			$ts = strtotime($callStart) ?: 0;
		}
		if ($ts <= 0) {
			$ts = time();
		}

		$bestCid = null;
		$bestDelta = 999999;
		$lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		if (!is_array($lines)) {
			return null;
		}
		foreach (array_reverse($lines) as $line) {
			$row = json_decode($line, true);
			if (!is_array($row) || empty($row['did']) || empty($row['cid'])) {
				continue;
			}
			if ((string)$row['did'] !== $did) {
				continue;
			}
			$rowTs = strtotime((string)($row['ts'] ?? '')) ?: 0;
			if ($rowTs <= 0) {
				continue;
			}
			$delta = abs($rowTs - $ts);
			if ($delta > 180) {
				continue;
			}
			if ($delta < $bestDelta) {
				$bestDelta = $delta;
				$bestCid = waVoxCidFix_normPhone($row['cid']);
			}
		}
		return $bestCid ?: null;
	}
}

if (!function_exists('waVoxCidFix_updateCrmPhone')) {
	function waVoxCidFix_updateCrmPhone(string $entityType, int $entityId, string $phone): void
	{
		if ($entityId <= 0 || $phone === '') {
			return;
		}
		$entityType = strtoupper($entityType);
		if (!in_array($entityType, ['LEAD', 'CONTACT', 'COMPANY', 'DEAL'], true)) {
			return;
		}

		$rs = \CCrmFieldMulti::GetList(
			['ID' => 'ASC'],
			['ENTITY_ID' => $entityType, 'ELEMENT_ID' => $entityId, 'TYPE_ID' => 'PHONE']
		);
		$fm = new \CCrmFieldMulti();
		while ($row = $rs->Fetch()) {
			if (waVoxCidFix_isBadPhone($row['VALUE'] ?? '')) {
				$fm->Delete((int)$row['ID']);
			}
		}

		$exists = false;
		$rs2 = \CCrmFieldMulti::GetList(
			[],
			['ENTITY_ID' => $entityType, 'ELEMENT_ID' => $entityId, 'TYPE_ID' => 'PHONE']
		);
		while ($row = $rs2->Fetch()) {
			$val = preg_replace('/\D+/', '', (string)($row['VALUE'] ?? ''));
			if ($val === preg_replace('/\D+/', '', $phone)) {
				$exists = true;
				break;
			}
		}
		if (!$exists) {
			$fm->Add([
				'ENTITY_ID' => $entityType,
				'ELEMENT_ID' => $entityId,
				'TYPE_ID' => 'PHONE',
				'VALUE_TYPE' => 'WORK',
				'VALUE' => $phone,
			]);
		}
	}
}

if (!function_exists('waVoxCidFix_applyForCallId')) {
	function waVoxCidFix_applyForCallId(string $callId, string $realPhone): bool
	{
		if ($callId === '' || $realPhone === '') {
			return false;
		}
		if (!\Bitrix\Main\Loader::includeModule('voximplant') || !\Bitrix\Main\Loader::includeModule('crm')) {
			return false;
		}

		$changed = false;
		$stats = \Bitrix\Voximplant\StatisticTable::getList([
			'filter' => ['=CALL_ID' => $callId],
			'select' => ['ID', 'PHONE_NUMBER', 'CRM_ENTITY_TYPE', 'CRM_ENTITY_ID', 'PORTAL_NUMBER', 'CALL_START_DATE'],
		]);
		while ($stat = $stats->fetch()) {
			if (!waVoxCidFix_isBadPhone($stat['PHONE_NUMBER'] ?? '')) {
				continue;
			}
			\Bitrix\Voximplant\StatisticTable::update((int)$stat['ID'], [
				'PHONE_NUMBER' => $realPhone,
			]);
			$changed = true;
			if (!empty($stat['CRM_ENTITY_TYPE']) && !empty($stat['CRM_ENTITY_ID'])) {
				waVoxCidFix_updateCrmPhone(
					(string)$stat['CRM_ENTITY_TYPE'],
					(int)$stat['CRM_ENTITY_ID'],
					$realPhone
				);
			}
		}
		return $changed;
	}
}

if (!function_exists('waVoxCidFix_onCallEnd')) {
	function waVoxCidFix_onCallEnd(array $fields): void
	{
		static $busy = [];
		$callId = (string)($fields['CALL_ID'] ?? '');
		if ($callId === '' || isset($busy[$callId])) {
			return;
		}
		$phone = (string)($fields['PHONE_NUMBER'] ?? '');
		if (!waVoxCidFix_isBadPhone($phone)) {
			return;
		}

		$portal = (string)($fields['PORTAL_NUMBER'] ?? '');
		$did = waVoxCidFix_portalToDid($portal);
		if ($did === '') {
			return;
		}

		$real = waVoxCidFix_lookupCid($did, $fields['CALL_START_DATE'] ?? null);
		if (!$real) {
			return;
		}

		$busy[$callId] = true;
		waVoxCidFix_applyForCallId($callId, $real);
	}
}

$em = \Bitrix\Main\EventManager::getInstance();
$em->addEventHandler('voximplant', 'onCallEnd', 'waVoxCidFix_onCallEnd');

$em->addEventHandler('crm', 'OnAfterCrmLeadAdd', static function (&$arFields) {
	if (!\Bitrix\Main\Loader::includeModule('crm')) {
		return;
	}
	$leadId = (int)($arFields['ID'] ?? 0);
	if ($leadId <= 0) {
		return;
	}
	$rs = \CCrmFieldMulti::GetList([], [
		'ENTITY_ID' => 'LEAD',
		'ELEMENT_ID' => $leadId,
		'TYPE_ID' => 'PHONE',
	]);
	$bad = false;
	while ($row = $rs->Fetch()) {
		if (waVoxCidFix_isBadPhone($row['VALUE'] ?? '')) {
			$bad = true;
			break;
		}
	}
	if (!$bad) {
		return;
	}
	foreach (['3888', '8099'] as $did) {
		$real = waVoxCidFix_lookupCid($did, date('Y-m-d H:i:s'));
		if ($real) {
			waVoxCidFix_updateCrmPhone('LEAD', $leadId, $real);
			break;
		}
	}
});
