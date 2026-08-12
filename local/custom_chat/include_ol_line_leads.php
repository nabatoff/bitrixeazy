<?php
/**
 * Отдельный лид на каждую открытую линию.
 *
 * Если к лиду привязан OL-чат, а на лиде уже есть чат с другой CONFIG_ID —
 * создаём новый лид и переносим текущий чат.
 *
 * Подключение в bitrix/php_interface/init.php:
 *
 *   $olLeads = $_SERVER['DOCUMENT_ROOT'] . '/local/custom_chat/include_ol_line_leads.php';
 *   if (is_file($olLeads)) { require_once $olLeads; }
 *
 * Отладка (под админом): /local/custom_chat/ol_line_leads_run.php?leadId=331574
 * Логи: AddMessage2Log → ol_line_leads
 */
if (!defined('B_PROLOG_INCLUDED') && !defined('BX_ROOT') && empty($_SERVER['DOCUMENT_ROOT'])) {
	return;
}

try {
	if (!class_exists('\Bitrix\Main\EventManager', true)) {
		return;
	}

	$em = \Bitrix\Main\EventManager::getInstance();

	// Активности CRM (разные имена событий на разных сборках)
	$em->addEventHandlerCompatible('crm', 'OnActivityAdd', 'olLineLeadsOnActivityAdd');
	$em->addEventHandlerCompatible('crm', 'OnAfterCrmActivityAdd', 'olLineLeadsOnAfterActivityAdd');
	$em->addEventHandlerCompatible('crm', 'OnActivityUpdate', 'olLineLeadsOnActivityUpdate');
	$em->addEventHandlerCompatible('crm', 'OnAfterCrmDealAdd', 'olLineLeadsOnAfterDealAdd');
	$em->addEventHandlerCompatible('crm', 'OnAfterCrmLeadConvert', 'olLineLeadsOnAfterLeadConvert');

	// ORM: сессия ОЛ создана / обновлена (CRM-поля часто появляются тут)
	$em->addEventHandler(
		'imopenlines',
		'\\Bitrix\\ImOpenLines\\Model\\Session::OnAfterAdd',
		'olLineLeadsOnSessionOrmEvent'
	);
	$em->addEventHandler(
		'imopenlines',
		'\\Bitrix\\ImOpenLines\\Model\\Session::OnAfterUpdate',
		'olLineLeadsOnSessionOrmEvent'
	);

	// Агент — страховка, если события не долетели (ChatApp / фон)
	olLineLeadsEnsureAgent();
} catch (\Throwable $e) {
	if (function_exists('AddMessage2Log')) {
		AddMessage2Log('olLineLeads init: ' . $e->getMessage(), 'ol_line_leads');
	}
}

function olLineLeadsEnsureAgent()
{
	if (!function_exists('CAgent') && !class_exists('CAgent')) {
		return;
	}
	try {
		if (!\Bitrix\Main\Loader::includeModule('main')) {
			return;
		}
	} catch (\Throwable $e) {
		return;
	}

	$exists = \CAgent::GetList([], ['NAME' => 'olLineLeadsAgent();'])->Fetch();
	if ($exists) {
		return;
	}
	\CAgent::AddAgent(
		'olLineLeadsAgent();',
		'main',
		'N',
		60,
		'',
		'Y',
		'',
		100,
		false,
		false
	);
	olLineLeadsLog('agent registered');
}

/**
 * Агент: последние сессии ОЛ за 15 минут — проверить split.
 * @return string
 */
function olLineLeadsAgent()
{
	try {
		if (!\Bitrix\Main\Loader::includeModule('imopenlines')
			|| !\Bitrix\Main\Loader::includeModule('crm')
			|| !\Bitrix\Main\Loader::includeModule('im')
		) {
			return 'olLineLeadsAgent();';
		}

		if (!class_exists('\Bitrix\ImOpenLines\Model\SessionTable')) {
			return 'olLineLeadsAgent();';
		}

		$since = ConvertTimeStamp(time() - 900, 'FULL');
		$rows = \Bitrix\ImOpenLines\Model\SessionTable::getList([
			'filter' => ['>=DATE_CREATE' => $since],
			'order' => ['ID' => 'DESC'],
			'limit' => 40,
			'select' => ['ID', 'CHAT_ID', 'CONFIG_ID', 'DATE_CREATE'],
		]);

		while ($row = $rows->fetch()) {
			$chatId = (int)($row['CHAT_ID'] ?? 0);
			if ($chatId <= 0) {
				continue;
			}
			$leadId = olLineLeadsGetCrmLeadForChat($chatId);
			if ($leadId > 0) {
				olLineLeadsProcessSplit($leadId, $chatId, 0);
			}
		}
	} catch (\Throwable $e) {
		olLineLeadsLog('agent: ' . $e->getMessage());
	}

	return 'olLineLeadsAgent();';
}

function olLineLeadsOnAfterActivityAdd($idOrFields, $fields = null)
{
	try {
		$fields = olLineLeadsNormalizeActivityArgs($idOrFields, $fields);
		olLineLeadsOnActivityAdd($fields);
	} catch (\Throwable $e) {
		olLineLeadsLog('OnAfterCrmActivityAdd: ' . $e->getMessage());
	}
}

function olLineLeadsOnActivityUpdate($idOrFields, $fields = null)
{
	try {
		$fields = olLineLeadsNormalizeActivityArgs($idOrFields, $fields);
		olLineLeadsOnActivityAdd($fields);
	} catch (\Throwable $e) {
		olLineLeadsLog('OnActivityUpdate: ' . $e->getMessage());
	}
}

function olLineLeadsNormalizeActivityArgs($idOrFields, $fields = null)
{
	if (is_array($idOrFields) && $fields === null) {
		return $idOrFields;
	}
	$fields = is_array($fields) ? $fields : [];
	if (!isset($fields['ID']) && (int)$idOrFields > 0) {
		$fields['ID'] = (int)$idOrFields;
	}
	return $fields;
}

/**
 * @param array $fields
 */
function olLineLeadsOnActivityAdd(&$fields)
{
	try {
		if (!is_array($fields)) {
			return;
		}

		$id = (int)($fields['ID'] ?? 0);
		// Догружаем полную активность — в событии часто нет PROVIDER/SETTINGS
		if ($id > 0) {
			$full = \CCrmActivity::GetByID($id, false);
			if (is_array($full)) {
				$fields = array_merge($full, $fields);
			}
		}

		if (!olLineLeadsActivityLooksLikeOpenLine($fields)) {
			return;
		}

		$ownerTypeId = (int)($fields['OWNER_TYPE_ID'] ?? 0);
		$ownerId = (int)($fields['OWNER_ID'] ?? 0);
		$leadType = class_exists('CCrmOwnerType') ? (int)\CCrmOwnerType::Lead : 1;
		if ($ownerId <= 0 || $ownerTypeId !== $leadType) {
			return;
		}

		$chatId = olLineLeadsExtractChatIdFromActivity($fields);
		if ($chatId <= 0) {
			olLineLeadsLog('activity #' . $id . ': no chatId');
			return;
		}

		olLineLeadsLog('activity trigger lead=' . $ownerId . ' chat=' . $chatId);
		olLineLeadsScheduleSplit($ownerId, $chatId, $id);
	} catch (\Throwable $e) {
		olLineLeadsLog('OnActivityAdd: ' . $e->getMessage());
	}
}

function olLineLeadsActivityLooksLikeOpenLine(array $fields)
{
	$provider = strtoupper((string)($fields['PROVIDER_ID'] ?? ''));
	$providerType = strtoupper((string)($fields['PROVIDER_TYPE_ID'] ?? ''));
	$subj = (string)($fields['SUBJECT'] ?? '');

	if (strpos($provider, 'IMOPENLINE') !== false) {
		return true;
	}
	if (strpos($providerType, 'SESSION') !== false || strpos($providerType, 'IMOPENLINE') !== false) {
		return true;
	}
	if (preg_match('/чат открытой линии|open.?line|whatsapp|green-api|chatapp/iu', $subj)) {
		return true;
	}
	// ASSOCIATED_ENTITY_ID часто = CHAT_ID для ОЛ
	$assoc = (int)($fields['ASSOCIATED_ENTITY_ID'] ?? 0);
	if ($assoc > 0 && olLineLeadsGetLineIdForChat($assoc) > 0) {
		return true;
	}
	return false;
}

/**
 * ORM Session OnAfterAdd / OnAfterUpdate
 * @param \Bitrix\Main\Event $event
 */
function olLineLeadsOnSessionOrmEvent(\Bitrix\Main\Event $event)
{
	try {
		$params = $event->getParameters();
		$fields = [];
		if (isset($params['fields']) && is_array($params['fields'])) {
			$fields = $params['fields'];
		} elseif (isset($params['object']) && is_object($params['object']) && method_exists($params['object'], 'collectValues')) {
			$fields = $params['object']->collectValues();
		} elseif (isset($params[0]) && is_array($params[0])) {
			$fields = $params[0];
		}

		$id = (int)($fields['ID'] ?? $params['id'] ?? $params['primary']['ID'] ?? 0);
		$chatId = (int)($fields['CHAT_ID'] ?? 0);

		if ($chatId <= 0 && $id > 0 && class_exists('\Bitrix\ImOpenLines\Model\SessionTable')) {
			$row = \Bitrix\ImOpenLines\Model\SessionTable::getById($id)->fetch();
			if (is_array($row)) {
				$fields = array_merge($row, $fields);
				$chatId = (int)($fields['CHAT_ID'] ?? 0);
			}
		}
		if ($chatId <= 0) {
			return;
		}

		// CRM может проставиться с задержкой — несколько попыток
		olLineLeadsScheduleSplitDeferred($chatId);
	} catch (\Throwable $e) {
		olLineLeadsLog('SessionOrm: ' . $e->getMessage());
	}
}

function olLineLeadsScheduleSplitDeferred($chatId)
{
	$chatId = (int)$chatId;
	if ($chatId <= 0) {
		return;
	}

	static $queuedChat = [];
	if (isset($queuedChat[$chatId])) {
		return;
	}
	$queuedChat[$chatId] = true;

	$runner = static function () use ($chatId) {
		for ($i = 0; $i < 5; $i++) {
			if ($i > 0) {
				sleep(2);
			}
			$leadId = olLineLeadsGetCrmLeadForChat($chatId);
			if ($leadId > 0) {
				olLineLeadsLog("deferred hit chat={$chatId} lead={$leadId} try={$i}");
				olLineLeadsProcessSplit($leadId, $chatId, 0);
				return;
			}
		}
		olLineLeadsLog("deferred: no lead for chat {$chatId}");
	};

	try {
		if (class_exists('\Bitrix\Main\Application', true)) {
			\Bitrix\Main\Application::getInstance()->addBackgroundJob($runner);
			return;
		}
	} catch (\Throwable $e) {
		/* sync */
	}

	// Без background job — только быстрая попытка (sleep в HTTP-запросе опасен)
	$leadId = olLineLeadsGetCrmLeadForChat($chatId);
	if ($leadId > 0) {
		olLineLeadsProcessSplit($leadId, $chatId, 0);
	}
}

function olLineLeadsScheduleSplit($leadId, $chatId, $activityId = 0)
{
	$leadId = (int)$leadId;
	$chatId = (int)$chatId;
	if ($leadId <= 0 || $chatId <= 0) {
		return;
	}

	static $queued = [];
	$key = $leadId . ':' . $chatId;
	if (isset($queued[$key])) {
		return;
	}
	$queued[$key] = true;

	$job = static function () use ($leadId, $chatId, $activityId) {
		// CRM/линия могут дописаться чуть позже активности
		usleep(500000);
		olLineLeadsProcessSplit($leadId, $chatId, $activityId);
		usleep(1500000);
		olLineLeadsProcessSplit($leadId, $chatId, $activityId);
	};

	try {
		if (class_exists('\Bitrix\Main\Application', true)) {
			\Bitrix\Main\Application::getInstance()->addBackgroundJob($job);
			return;
		}
	} catch (\Throwable $e) {
		/* sync */
	}
	olLineLeadsProcessSplit($leadId, $chatId, $activityId);
}

function olLineLeadsProcessSplit($leadId, $chatId, $activityId = 0, $force = false)
{
	static $busy = [];
	static $done = [];
	$key = (int)$leadId . ':' . (int)$chatId;
	if (isset($busy[$key]) || isset($done[$key])) {
		return;
	}
	$busy[$key] = true;

	try {
		if (!\Bitrix\Main\Loader::includeModule('crm')
			|| !\Bitrix\Main\Loader::includeModule('im')
			|| !\Bitrix\Main\Loader::includeModule('imopenlines')
		) {
			return;
		}

		// Уже перенесён по OWNER/session?
		$currentLead = olLineLeadsGetCrmLeadForChat($chatId);
		if ($currentLead > 0 && $currentLead !== (int)$leadId) {
			if ($force) {
				// OWNER уже на новом лиде, а binding остался на старом — добить rebind
				olLineLeadsLog("chat {$chatId} OWNER on {$currentLead}, fix bindings from {$leadId}");
				olLineLeadsRebindChatToLead($chatId, $leadId, $currentLead, $activityId);
				$done[$key] = true;
				return;
			}
			olLineLeadsLog("chat {$chatId} already on lead {$currentLead}, skip old {$leadId}");
			$done[$key] = true;
			return;
		}
		if ($currentLead > 0) {
			$leadId = $currentLead;
		}

		$meta = olLineLeadsGetChatMeta($chatId, $leadId);
		$lineId = (int)$meta['line_id'];
		$myKey = (string)$meta['key'];
		// если пришёл SESSION_ID — дальше работаем с реальным CHAT_ID
		$resolvedChatId = (int)($meta['debug']['CHAT_ID'] ?? 0);
		if ($resolvedChatId > 0) {
			$chatId = $resolvedChatId;
		}
		if ($myKey === '' && $lineId > 0) {
			$myKey = 'L:' . $lineId;
		}
		if ($myKey === '') {
			if (!$force) {
				olLineLeadsLog("chat {$chatId}: identity unknown, skip debug=" . json_encode($meta['debug'], JSON_UNESCAPED_UNICODE));
				return;
			}
			$myKey = 'C:' . (int)$chatId;
			olLineLeadsLog("chat {$chatId}: force weak identity {$myKey}");
		}

		$others = olLineLeadsGetChatsForLead($leadId);
		olLineLeadsLog(
			'lead=' . $leadId . ' chat=' . $chatId
			. ' line=' . $lineId . ' key=' . $myKey
			. ' others=' . count($others)
		);

		$conflict = false;
		foreach ($others as $row) {
			$oid = (int)$row['CHAT_ID'];
			if ($oid === (int)$chatId) {
				continue;
			}
			$otherMeta = olLineLeadsGetChatMeta($oid, $leadId);
			$oline = (int)$otherMeta['line_id'];
			$okey = (string)$otherMeta['key'];
			if ($okey === '' && $oline > 0) {
				$okey = 'L:' . $oline;
			}
			if ($okey === '' && $force) {
				$okey = 'C:' . $oid;
			}
			if ($okey === '' || $okey === $myKey) {
				continue;
			}
			// Числовые линии: конфликт только если обе известны и разные
			if ($lineId > 0 && $oline > 0 && $lineId === $oline) {
				continue;
			}
			$conflict = true;
			olLineLeadsLog("conflict with chat={$oid} line={$oline} key={$okey}");
			break;
		}
		if (!$conflict) {
			return;
		}

		$newLeadId = olLineLeadsCloneLeadForLine($leadId, $lineId, $chatId, $myKey);
		if ($newLeadId <= 0) {
			olLineLeadsLog("failed to create lead for chat {$chatId}");
			return;
		}

		olLineLeadsRebindChatToLead($chatId, $leadId, $newLeadId, $activityId);
		$done[$key] = true;
		olLineLeadsLog("ok: chat {$chatId} lead {$leadId} → {$newLeadId}");
	} catch (\Throwable $e) {
		olLineLeadsLog('process: ' . $e->getMessage());
	} finally {
		unset($busy[$key]);
	}
}

function olLineLeadsExtractChatIdFromActivity(array $fields)
{
	$assoc = (int)($fields['ASSOCIATED_ENTITY_ID'] ?? 0);
	if ($assoc > 0) {
		$resolved = olLineLeadsResolveIds($assoc);
		if ((int)$resolved['chatId'] > 0) {
			return (int)$resolved['chatId'];
		}
		return $assoc;
	}
	$settings = $fields['SETTINGS'] ?? null;
	if (is_string($settings) && $settings !== '') {
		$decoded = @unserialize($settings);
		if ($decoded === false) {
			$decoded = json_decode($settings, true);
		}
		if (is_array($decoded)) {
			$settings = $decoded;
		}
	}
	if (is_array($settings)) {
		foreach (['CHAT_ID', 'chatId', 'DIALOG_ID', 'SESSION_ID', 'SESSION'] as $k) {
			if (empty($settings[$k])) {
				continue;
			}
			$v = $settings[$k];
			if (is_array($v)) {
				$v = $v['CHAT_ID'] ?? $v['ID'] ?? $v['chatId'] ?? 0;
			}
			if (is_string($v) && preg_match('/(\d{2,})/', $v, $m)) {
				$id = (int)$m[1];
				$resolved = olLineLeadsResolveIds($id);
				return (int)$resolved['chatId'] > 0 ? (int)$resolved['chatId'] : $id;
			}
			$id = (int)$v;
			if ($id > 0) {
				$resolved = olLineLeadsResolveIds($id);
				return (int)$resolved['chatId'] > 0 ? (int)$resolved['chatId'] : $id;
			}
		}
	}
	$subj = (string)($fields['SUBJECT'] ?? '');
	if (preg_match('/chat(\d+)/i', $subj, $m)) {
		return (int)$m[1];
	}
	return 0;
}

/**
 * ASSOCIATED_ENTITY_ID в CRM часто = SESSION_ID, не CHAT_ID.
 * @return array{chatId:int,sessionId:int,session:?array,chat:?array,errors:array}
 */
function olLineLeadsResolveIds($id)
{
	$id = (int)$id;
	$out = [
		'chatId' => 0,
		'sessionId' => 0,
		'session' => null,
		'chat' => null,
		'errors' => [],
	];
	if ($id <= 0) {
		return $out;
	}

	try {
		\Bitrix\Main\Loader::includeModule('im');
		\Bitrix\Main\Loader::includeModule('imopenlines');
	} catch (\Throwable $e) {
		$out['errors'][] = 'loader: ' . $e->getMessage();
	}

	// 1) как SESSION primary
	try {
		if (class_exists('\Bitrix\ImOpenLines\Model\SessionTable')) {
			$session = \Bitrix\ImOpenLines\Model\SessionTable::getById($id)->fetch();
			if (is_array($session) && !empty($session['ID'])) {
				$out['session'] = $session;
				$out['sessionId'] = (int)$session['ID'];
				$out['chatId'] = (int)($session['CHAT_ID'] ?? 0);
			}
		}
	} catch (\Throwable $e) {
		$out['errors'][] = 'sessionById: ' . $e->getMessage();
	}

	// 2) как CHAT primary
	try {
		if (class_exists('\Bitrix\Im\Model\ChatTable')) {
			$chat = \Bitrix\Im\Model\ChatTable::getById($id)->fetch();
			if (is_array($chat) && !empty($chat['ID'])) {
				$out['chat'] = $chat;
				$out['chatId'] = (int)$chat['ID'];
			}
		}
	} catch (\Throwable $e) {
		$out['errors'][] = 'chatById: ' . $e->getMessage();
	}

	// 3) session по CHAT_ID (если нашли чат или id был chat)
	if (!is_array($out['session']) && $out['chatId'] > 0) {
		try {
			if (class_exists('\Bitrix\ImOpenLines\Model\SessionTable')) {
				$session = \Bitrix\ImOpenLines\Model\SessionTable::getList([
					'filter' => ['=CHAT_ID' => $out['chatId']],
					'order' => ['ID' => 'DESC'],
					'limit' => 1,
				])->fetch();
				if (is_array($session)) {
					$out['session'] = $session;
					$out['sessionId'] = (int)$session['ID'];
				}
			}
		} catch (\Throwable $e) {
			$out['errors'][] = 'sessionByChat: ' . $e->getMessage();
		}
	}

	// 4) chat по session.CHAT_ID
	if (!is_array($out['chat']) && $out['chatId'] > 0 && $out['chatId'] !== $id) {
		try {
			$chat = \Bitrix\Im\Model\ChatTable::getById($out['chatId'])->fetch();
			if (is_array($chat)) {
				$out['chat'] = $chat;
			}
		} catch (\Throwable $e) {
			$out['errors'][] = 'chatBySession: ' . $e->getMessage();
		}
	}

	// 5) raw SQL fallback (ChatApp / странные ORM)
	if (!is_array($out['chat']) || !is_array($out['session'])) {
		try {
			$con = \Bitrix\Main\Application::getConnection();
			if (!is_array($out['session'])) {
				$sql = 'SELECT * FROM b_imopenlines_session WHERE ID=' . $id . ' OR CHAT_ID=' . $id
					. ' ORDER BY ID DESC LIMIT 1';
				$rs = $con->query($sql);
				if ($row = $rs->fetch()) {
					$out['session'] = $row;
					$out['sessionId'] = (int)$row['ID'];
					if ($out['chatId'] <= 0) {
						$out['chatId'] = (int)($row['CHAT_ID'] ?? 0);
					}
				}
			}
			$cid = $out['chatId'] > 0 ? $out['chatId'] : $id;
			if (!is_array($out['chat'])) {
				$rs = $con->query('SELECT * FROM b_im_chat WHERE ID=' . (int)$cid . ' LIMIT 1');
				if ($row = $rs->fetch()) {
					$out['chat'] = $row;
					$out['chatId'] = (int)$row['ID'];
				}
			}
		} catch (\Throwable $e) {
			$out['errors'][] = 'sql: ' . $e->getMessage();
		}
	}

	if ($out['chatId'] <= 0) {
		$out['chatId'] = $id; // как пришло из активности — для rebind/логов
	}

	return $out;
}

/**
 * SUBJECT активностей, где ASSOCIATED = этот id (chat или session).
 */
function olLineLeadsFindActivitySubjects($assocId, $leadId = 0)
{
	$assocId = (int)$assocId;
	$subjects = [];
	if ($assocId <= 0) {
		return $subjects;
	}
	try {
		\Bitrix\Main\Loader::includeModule('crm');
		$filter = [
			'ASSOCIATED_ENTITY_ID' => $assocId,
			'CHECK_PERMISSIONS' => 'N',
		];
		$res = \CCrmActivity::GetList(
			['ID' => 'DESC'],
			$filter,
			false,
			['nTopCount' => 10],
			['ID', 'SUBJECT', 'ASSOCIATED_ENTITY_ID', 'OWNER_ID', 'OWNER_TYPE_ID', 'PROVIDER_ID']
		);
		while ($act = $res->Fetch()) {
			$subjects[] = (string)($act['SUBJECT'] ?? '');
		}
	} catch (\Throwable $e) {
		/* ignore */
	}

	if (!$subjects && $leadId > 0) {
		try {
			$leadType = class_exists('CCrmOwnerType') ? (int)\CCrmOwnerType::Lead : 1;
			$res = \CCrmActivity::GetList(
				['ID' => 'DESC'],
				[
					'OWNER_TYPE_ID' => $leadType,
					'OWNER_ID' => (int)$leadId,
					'CHECK_PERMISSIONS' => 'N',
				],
				false,
				['nTopCount' => 80],
				['ID', 'SUBJECT', 'ASSOCIATED_ENTITY_ID', 'PROVIDER_ID', 'SETTINGS']
			);
			while ($act = $res->Fetch()) {
				$aid = (int)($act['ASSOCIATED_ENTITY_ID'] ?? 0);
				$extracted = olLineLeadsExtractChatIdFromActivity($act);
				if ($aid === $assocId || $extracted === $assocId) {
					$subjects[] = (string)($act['SUBJECT'] ?? '');
				}
			}
		} catch (\Throwable $e) {
			/* ignore */
		}
	}

	return array_values(array_filter($subjects));
}

/**
 * Парсит CONFIG_ID из USER_CODE / ENTITY_ID вида connector|lineId|...
 */
function olLineLeadsParseLineIdFromPipe($raw)
{
	$raw = (string)$raw;
	if ($raw === '') {
		return 0;
	}
	$parts = explode('|', $raw);
	// connector|configId|client|...
	if (isset($parts[1]) && preg_match('/^\d{1,8}$/', (string)$parts[1])) {
		return (int)$parts[1];
	}
	// chatapp|whatsapp|configId|...
	if (isset($parts[2]) && preg_match('/^\d{1,8}$/', (string)$parts[2])) {
		return (int)$parts[2];
	}
	foreach ($parts as $piece) {
		if (preg_match('/^\d{1,6}$/', (string)$piece) && (int)$piece > 0 && (int)$piece < 100000) {
			return (int)$piece;
		}
	}
	return 0;
}

/**
 * Хинт линии из TITLE чата / SUBJECT активности:
 * «... — Уральск Райхан 7 776...» → «уральск райхан»
 */
function olLineLeadsExtractLineHintFromTitle($title)
{
	$title = trim((string)$title);
	if ($title === '') {
		return '';
	}
	$t = preg_replace('/\+?\d[\d\s\-\(\)]{7,}/u', '', $title);
	$t = preg_replace('/\s+/u', ' ', trim((string)$t));
	if (preg_match('/[-–—]\s*([^\-–—]{2,60})$/u', $t, $m)) {
		$hint = mb_strtolower(trim($m[1]));
		$hint = preg_replace('/\b(whatsapp|green-?api\.?com|telegram|chatapp|art)\b/iu', '', $hint);
		$hint = trim(preg_replace('/\s+/u', ' ', (string)$hint));
		if (mb_strlen($hint) >= 3) {
			return $hint;
		}
	}
	// «Уральск Райхан» / «Уральск Мария» без тире в конце
	if (preg_match('/(уральск\s+[а-яёa-z]+)/iu', $title, $m)) {
		return mb_strtolower(trim($m[1]));
	}
	return '';
}

/**
 * Мета чата: numeric LINE_ID + стабильный identity key (когда CONFIG_ID = 0).
 * @return array{line_id:int,key:string,debug:array}
 */
function olLineLeadsGetChatMeta($chatId, $leadId = 0)
{
	$rawId = (int)$chatId;
	$meta = [
		'line_id' => 0,
		'key' => '',
		'debug' => [
			'RAW_ID' => $rawId,
			'CHAT_ID' => null,
			'SESSION_ID' => null,
			'CONFIG_ID' => null,
			'USER_CODE' => null,
			'ENTITY_ID' => null,
			'TITLE' => null,
			'HINT' => null,
			'SUBJECTS' => [],
			'ERRORS' => [],
			'RESOLVED_FROM' => null,
		],
	];
	if ($rawId <= 0) {
		return $meta;
	}

	$resolved = olLineLeadsResolveIds($rawId);
	$meta['debug']['ERRORS'] = $resolved['errors'];
	$meta['debug']['CHAT_ID'] = $resolved['chatId'] ?: null;
	$meta['debug']['SESSION_ID'] = $resolved['sessionId'] ?: null;
	if (is_array($resolved['session']) && (int)$resolved['sessionId'] === $rawId && (int)$resolved['chatId'] !== $rawId) {
		$meta['debug']['RESOLVED_FROM'] = 'session';
	} elseif (is_array($resolved['chat'])) {
		$meta['debug']['RESOLVED_FROM'] = 'chat';
	}

	$session = $resolved['session'];
	$chat = $resolved['chat'];
	$realChatId = (int)$resolved['chatId'] ?: $rawId;

	if (is_array($session)) {
		$configId = (int)($session['CONFIG_ID'] ?? $session['LINE_ID'] ?? 0);
		$userCode = (string)($session['USER_CODE'] ?? '');
		$meta['debug']['CONFIG_ID'] = $configId;
		$meta['debug']['USER_CODE'] = $userCode !== '' ? $userCode : null;
		if ($configId > 0) {
			$meta['line_id'] = $configId;
			$meta['key'] = 'L:' . $configId;
			return $meta;
		}
		$fromUc = olLineLeadsParseLineIdFromPipe($userCode);
		if ($fromUc > 0) {
			$meta['line_id'] = $fromUc;
			$meta['key'] = 'L:' . $fromUc;
			return $meta;
		}
		$ucParts = array_values(array_filter(explode('|', $userCode), static function ($p) {
			return $p !== '';
		}));
		if (count($ucParts) >= 2) {
			$meta['key'] = 'U:' . $ucParts[0] . '|' . $ucParts[1];
		}
	}

	if (is_array($chat)) {
		$eid = (string)($chat['ENTITY_ID'] ?? '');
		$title = (string)($chat['TITLE'] ?? '');
		$meta['debug']['ENTITY_ID'] = $eid !== '' ? $eid : null;
		$meta['debug']['TITLE'] = $title !== '' ? $title : null;

		$fromEid = olLineLeadsParseLineIdFromPipe($eid);
		if ($fromEid > 0) {
			$meta['line_id'] = $fromEid;
			$meta['key'] = 'L:' . $fromEid;
			return $meta;
		}

		foreach (['ENTITY_DATA_1', 'ENTITY_DATA_2', 'ENTITY_DATA_3'] as $k) {
			$raw = (string)($chat[$k] ?? '');
			if ($raw === '') {
				continue;
			}
			$fromData = olLineLeadsParseLineIdFromPipe($raw);
			if ($fromData > 0) {
				$meta['line_id'] = $fromData;
				$meta['key'] = 'L:' . $fromData;
				return $meta;
			}
		}

		$eParts = array_values(array_filter(explode('|', $eid), static function ($p) {
			return $p !== '';
		}));
		if ($meta['key'] === '' && count($eParts) >= 2) {
			$keep = [];
			foreach ($eParts as $i => $p) {
				if ($i >= 3) {
					break;
				}
				if (preg_match('/^\d{10,15}$/', $p)) {
					break;
				}
				$keep[] = $p;
			}
			if (count($keep) >= 2) {
				$meta['key'] = 'E:' . implode('|', $keep);
			} elseif ($eid !== '') {
				$meta['key'] = 'E:' . md5($eid);
			}
		}
	}

	// SUBJECT всегда — даже если chat/session не найдены (главный фикс для green-api)
	$subjects = olLineLeadsFindActivitySubjects($rawId, (int)$leadId);
	if ($realChatId !== $rawId) {
		$subjects = array_merge($subjects, olLineLeadsFindActivitySubjects($realChatId, (int)$leadId));
	}
	if (is_array($session) && (int)$session['ID'] > 0) {
		$subjects = array_merge($subjects, olLineLeadsFindActivitySubjects((int)$session['ID'], (int)$leadId));
	}
	$subjects = array_values(array_unique(array_filter($subjects)));
	$meta['debug']['SUBJECTS'] = $subjects;

	$hint = '';
	if (is_array($chat) && !empty($chat['TITLE'])) {
		$hint = olLineLeadsExtractLineHintFromTitle((string)$chat['TITLE']);
	}
	if ($hint === '') {
		foreach ($subjects as $subj) {
			$hint = olLineLeadsExtractLineHintFromTitle($subj);
			if ($hint !== '') {
				if (empty($meta['debug']['TITLE'])) {
					$meta['debug']['TITLE'] = $subj;
				}
				break;
			}
		}
	}
	$meta['debug']['HINT'] = $hint !== '' ? $hint : null;
	if ($hint !== '') {
		$meta['key'] = 'H:' . $hint;
	}

	if ($meta['key'] === '' && is_array($session) && !empty($session['USER_CODE'])) {
		$meta['key'] = 'U:' . md5((string)$session['USER_CODE']);
	}

	return $meta;
}

function olLineLeadsGetLineIdForChat($chatId)
{
	$meta = olLineLeadsGetChatMeta($chatId);
	return (int)$meta['line_id'];
}

function olLineLeadsGetChatIdentityKey($chatId)
{
	$meta = olLineLeadsGetChatMeta($chatId);
	if ($meta['key'] !== '') {
		return $meta['key'];
	}
	if ((int)$meta['line_id'] > 0) {
		return 'L:' . (int)$meta['line_id'];
	}
	return '';
}

/**
 * @return array<int, array{CHAT_ID:int,LINE_ID:int,KEY:string,DEBUG?:array}>
 */
function olLineLeadsGetChatsForLead($leadId)
{
	$leadId = (int)$leadId;
	$result = [];
	$seen = [];

	$add = static function ($cid, $line = 0) use (&$result, &$seen, $leadId) {
		$cid = (int)$cid;
		if ($cid <= 0) {
			return;
		}
		$resolved = olLineLeadsResolveIds($cid);
		$realCid = (int)$resolved['chatId'] > 0 ? (int)$resolved['chatId'] : $cid;
		if (isset($seen[$realCid]) || isset($seen[$cid])) {
			return;
		}
		$seen[$realCid] = true;
		$seen[$cid] = true;
		$meta = olLineLeadsGetChatMeta($cid, $leadId);
		$lineId = (int)$line ?: (int)$meta['line_id'];
		$key = (string)$meta['key'];
		if ($key === '' && $lineId > 0) {
			$key = 'L:' . $lineId;
		}
		$result[] = [
			'CHAT_ID' => $realCid,
			'RAW_ID' => $cid,
			'LINE_ID' => $lineId,
			'KEY' => $key,
			'DEBUG' => $meta['debug'],
		];
	};

	// 1) Сессии с CRM_ENTITY_* = этот лид
	try {
		if (class_exists('\Bitrix\ImOpenLines\Model\SessionTable')) {
			$filterVariants = [
				['=CRM_ENTITY_TYPE' => 'LEAD', '=CRM_ENTITY_ID' => $leadId],
				['=CRM_ENTITY_TYPE' => 'lead', '=CRM_ENTITY_ID' => $leadId],
				['=CRM_CREATE_ENTITY' => 'LEAD', '=CRM_CREATE_ID' => $leadId],
			];
			foreach ($filterVariants as $filter) {
				try {
					$rs = \Bitrix\ImOpenLines\Model\SessionTable::getList([
						'filter' => $filter,
						'order' => ['ID' => 'DESC'],
						'limit' => 30,
						'select' => ['ID', 'CHAT_ID', 'CONFIG_ID'],
					]);
					while ($row = $rs->fetch()) {
						$add((int)$row['CHAT_ID'], (int)($row['CONFIG_ID'] ?? 0));
					}
				} catch (\Throwable $e) {
					/* поле может отсутствовать */
				}
			}
		}
	} catch (\Throwable $e) {
		/* ignore */
	}

	// 2) ImOpenLines\Crm\Common
	try {
		if (class_exists('\Bitrix\ImOpenLines\Crm\Common')
			&& method_exists('\Bitrix\ImOpenLines\Crm\Common', 'getChatsByEntity')
		) {
			$list = \Bitrix\ImOpenLines\Crm\Common::getChatsByEntity('LEAD', $leadId);
			if (is_array($list)) {
				foreach ($list as $item) {
					$add((int)($item['CHAT_ID'] ?? $item['chatId'] ?? $item['ID'] ?? 0));
				}
			}
		}
	} catch (\Throwable $e) {
		/* ignore */
	}

	// 3) Активности лида (OWNER)
	try {
		$leadType = class_exists('CCrmOwnerType') ? \CCrmOwnerType::Lead : 1;
		$res = \CCrmActivity::GetList(
			['ID' => 'DESC'],
			[
				'OWNER_TYPE_ID' => $leadType,
				'OWNER_ID' => $leadId,
				'CHECK_PERMISSIONS' => 'N',
			],
			false,
			['nTopCount' => 100],
			['ID', 'PROVIDER_ID', 'PROVIDER_TYPE_ID', 'ASSOCIATED_ENTITY_ID', 'SUBJECT', 'SETTINGS']
		);
		while ($act = $res->Fetch()) {
			if (!olLineLeadsActivityLooksLikeOpenLine($act)) {
				continue;
			}
			$cid = olLineLeadsExtractChatIdFromActivity($act);
			if ($cid) {
				$add($cid);
			}
		}
	} catch (\Throwable $e) {
		/* ignore */
	}

	// 4) Binding к лиду (OWNER мог уехать, чат всё ещё в таймлайне)
	try {
		$leadType = class_exists('CCrmOwnerType') ? (int)\CCrmOwnerType::Lead : 1;
		if (class_exists('\Bitrix\Crm\ActivityBindingTable')) {
			$rs = \Bitrix\Crm\ActivityBindingTable::getList([
				'filter' => [
					'=OWNER_TYPE_ID' => $leadType,
					'=OWNER_ID' => $leadId,
				],
				'select' => ['ACTIVITY_ID'],
				'limit' => 100,
			]);
			while ($b = $rs->fetch()) {
				$aid = (int)($b['ACTIVITY_ID'] ?? 0);
				if ($aid <= 0) {
					continue;
				}
				$act = \CCrmActivity::GetByID($aid, false);
				if (!is_array($act) || !olLineLeadsActivityLooksLikeOpenLine($act)) {
					continue;
				}
				$cid = olLineLeadsExtractChatIdFromActivity($act);
				if ($cid) {
					$add($cid);
				}
			}
		}
	} catch (\Throwable $e) {
		/* ignore */
	}

	return $result;
}

function olLineLeadsGetCrmLeadForChat($chatId)
{
	$chatId = (int)$chatId;
	$leadType = class_exists('CCrmOwnerType') ? (int)\CCrmOwnerType::Lead : 1;

	try {
		if (class_exists('\Bitrix\ImOpenLines\Model\SessionTable')) {
			$row = \Bitrix\ImOpenLines\Model\SessionTable::getList([
				'filter' => ['=CHAT_ID' => $chatId],
				'order' => ['ID' => 'DESC'],
				'limit' => 1,
			])->fetch();
			if (is_array($row)) {
				$type = strtoupper((string)($row['CRM_ENTITY_TYPE'] ?? $row['CRM_CREATE_ENTITY'] ?? ''));
				$eid = (int)($row['CRM_ENTITY_ID'] ?? $row['CRM_CREATE_ID'] ?? 0);
				if ($eid > 0 && ($type === 'LEAD' || $type === '')) {
					// пустой type + id — уточним через активность
					if ($type === 'LEAD') {
						return $eid;
					}
				}
				if (!empty($row['CRM_ACTIVITY_ID'])) {
					$act = \CCrmActivity::GetByID((int)$row['CRM_ACTIVITY_ID'], false);
					if (is_array($act) && (int)$act['OWNER_TYPE_ID'] === $leadType) {
						return (int)$act['OWNER_ID'];
					}
				}
			}
		}
	} catch (\Throwable $e) {
		/* ignore */
	}

	try {
		$assocIds = [$chatId];
		$resolved = olLineLeadsResolveIds($chatId);
		if ((int)$resolved['sessionId'] > 0) {
			$assocIds[] = (int)$resolved['sessionId'];
		}
		if ((int)$resolved['chatId'] > 0) {
			$assocIds[] = (int)$resolved['chatId'];
		}
		$assocIds = array_values(array_unique(array_filter($assocIds)));

		foreach ($assocIds as $assoc) {
			$res = \CCrmActivity::GetList(
				['ID' => 'DESC'],
				[
					'ASSOCIATED_ENTITY_ID' => $assoc,
					'CHECK_PERMISSIONS' => 'N',
				],
				false,
				['nTopCount' => 10],
				['ID', 'OWNER_TYPE_ID', 'OWNER_ID', 'PROVIDER_ID', 'ASSOCIATED_ENTITY_ID', 'SUBJECT']
			);
			while ($act = $res->Fetch()) {
				if ((int)$act['OWNER_TYPE_ID'] === $leadType && (int)$act['OWNER_ID'] > 0) {
					return (int)$act['OWNER_ID'];
				}
			}
		}
	} catch (\Throwable $e) {
		/* ignore */
	}

	// Поиск по SETTINGS / всем активностям дорогой — через сессию USER_CODE иногда есть CRM в chat
	try {
		$chat = \Bitrix\Im\Model\ChatTable::getById($chatId)->fetch();
		if (is_array($chat)) {
			foreach (['ENTITY_DATA_1', 'ENTITY_DATA_2', 'ENTITY_DATA_3'] as $k) {
				$raw = (string)($chat[$k] ?? '');
				// часто: LEAD|123 или CRM=LEAD|123
				if (preg_match('/(?:^|[|])LEAD[|=](\d+)/i', $raw, $m)) {
					return (int)$m[1];
				}
			}
		}
	} catch (\Throwable $e) {
		/* ignore */
	}

	return 0;
}

function olLineLeadsGetLineName($lineId)
{
	$lineId = (int)$lineId;
	try {
		if (class_exists('\Bitrix\ImOpenLines\Model\ConfigTable')) {
			$row = \Bitrix\ImOpenLines\Model\ConfigTable::getById($lineId)->fetch();
			if ($row) {
				foreach (['LINE_NAME', 'NAME'] as $k) {
					if (!empty($row[$k])) {
						return (string)$row[$k];
					}
				}
			}
		}
	} catch (\Throwable $e) {
		/* ignore */
	}
	return 'OL#' . $lineId;
}

/**
 * Ответственный для нового лида = оператор чата/линии, не ответственный исходного лида.
 * Приоритет: OPERATOR_ID сессии → owner диалога → очередь линии → fallback.
 */
function olLineLeadsResolveAssigneeForChat($chatId, $lineId = 0, $fallbackUserId = 0)
{
	$chatId = (int)$chatId;
	$lineId = (int)$lineId;
	$fallbackUserId = (int)$fallbackUserId;
	$picked = 0;
	$from = 'fallback';

	$isRealUser = static function ($uid) {
		$uid = (int)$uid;
		if ($uid <= 0) {
			return false;
		}
		try {
			$user = \CUser::GetByID($uid)->Fetch();
			if (!is_array($user) || empty($user['ID'])) {
				return false;
			}
			if (($user['ACTIVE'] ?? 'Y') === 'N') {
				return false;
			}
			// боты/системные OL-пользователи
			$ext = (string)($user['EXTERNAL_AUTH_ID'] ?? '');
			if ($ext !== '' && stripos($ext, 'imconnector') !== false) {
				return false;
			}
			if ($ext !== '' && stripos($ext, 'bot') !== false) {
				return false;
			}
			return true;
		} catch (\Throwable $e) {
			return false;
		}
	};

	try {
		$resolved = olLineLeadsResolveIds($chatId);
		$session = is_array($resolved['session'] ?? null) ? $resolved['session'] : null;
		if (is_array($session)) {
			$op = (int)($session['OPERATOR_ID'] ?? 0);
			if ($isRealUser($op)) {
				$picked = $op;
				$from = 'session.OPERATOR_ID';
			}
			if ($lineId <= 0) {
				$lineId = (int)($session['CONFIG_ID'] ?? 0);
			}
		}

		if ($picked <= 0 && class_exists('\Bitrix\Im\Model\ChatTable')) {
			$realChatId = (int)($resolved['chatId'] ?? 0) ?: $chatId;
			$chat = is_array($resolved['chat'] ?? null)
				? $resolved['chat']
				: \Bitrix\Im\Model\ChatTable::getById($realChatId)->fetch();
			if (is_array($chat)) {
				$author = (int)($chat['AUTHOR_ID'] ?? 0);
				if ($isRealUser($author)) {
					$picked = $author;
					$from = 'chat.AUTHOR_ID';
				}
			}
		}

		// Очередь открытой линии (первый активный оператор)
		if ($picked <= 0 && $lineId > 0 && class_exists('\Bitrix\ImOpenLines\Model\QueueTable')) {
			try {
				$rs = \Bitrix\ImOpenLines\Model\QueueTable::getList([
					'filter' => ['=CONFIG_ID' => $lineId],
					'order' => ['ID' => 'ASC'],
					'limit' => 20,
					'select' => ['ID', 'USER_ID', 'CONFIG_ID'],
				]);
				while ($row = $rs->fetch()) {
					$uid = (int)($row['USER_ID'] ?? 0);
					if ($isRealUser($uid)) {
						$picked = $uid;
						$from = 'queue.USER_ID line=' . $lineId;
						break;
					}
				}
			} catch (\Throwable $e) {
				/* ignore */
			}
		}
	} catch (\Throwable $e) {
		olLineLeadsLog('resolveAssignee: ' . $e->getMessage());
	}

	if ($picked <= 0 && $isRealUser($fallbackUserId)) {
		$picked = $fallbackUserId;
		$from = 'source.ASSIGNED_BY_ID';
	}
	if ($picked <= 0) {
		$picked = 1;
		$from = 'admin';
	}

	olLineLeadsLog("assignee chat={$chatId} line={$lineId} → user={$picked} ({$from})");
	return $picked;
}

function olLineLeadsCloneLeadForLine($sourceLeadId, $lineId, $chatId, $identityKey = '')
{
	$sourceLeadId = (int)$sourceLeadId;
	$lineId = (int)$lineId;
	if ($sourceLeadId <= 0) {
		return 0;
	}

	$src = \CCrmLead::GetByID($sourceLeadId, false);
	if (!is_array($src) || empty($src['ID'])) {
		return 0;
	}

	$lineName = $lineId > 0 ? olLineLeadsGetLineName($lineId) : '';
	if ($lineName === '' || $lineName === ('OL#' . $lineId)) {
		if (is_string($identityKey) && strpos($identityKey, 'H:') === 0) {
			$lineName = trim(substr($identityKey, 2));
		} elseif (is_string($identityKey) && $identityKey !== '') {
			$lineName = $identityKey;
		} else {
			$lineName = 'OL chat #' . (int)$chatId;
		}
	}
	$title = trim((string)($src['TITLE'] ?? ''));
	if ($title === '') {
		$title = trim(($src['NAME'] ?? '') . ' ' . ($src['LAST_NAME'] ?? ''));
	}
	if ($title === '') {
		$title = 'WhatsApp ' . $lineName;
	}
	if ($lineName !== '' && mb_stripos($title, $lineName) === false) {
		$title .= ' — ' . $lineName;
	}

	$assignee = olLineLeadsResolveAssigneeForChat(
		$chatId,
		$lineId,
		(int)($src['ASSIGNED_BY_ID'] ?? 0)
	);

	$fields = [
		'TITLE' => $title,
		'NAME' => $src['NAME'] ?? '',
		'SECOND_NAME' => $src['SECOND_NAME'] ?? '',
		'LAST_NAME' => $src['LAST_NAME'] ?? '',
		'STATUS_ID' => 'NEW',
		'SOURCE_ID' => $src['SOURCE_ID'] ?? '',
		'SOURCE_DESCRIPTION' => trim(
			($src['SOURCE_DESCRIPTION'] ?? '')
			. ' | split from lead #' . $sourceLeadId
			. ' line ' . ($lineId > 0 ? $lineId : $identityKey)
			. ' assignee ' . $assignee
		),
		'ASSIGNED_BY_ID' => $assignee,
		'OPENED' => $src['OPENED'] ?? 'Y',
		'CURRENCY_ID' => $src['CURRENCY_ID'] ?? '',
		'COMMENTS' => 'Авто: отдельный лид для линии «' . $lineName
			. '» (чат #' . (int)$chatId . ', исходный лид #' . $sourceLeadId
			. ', ответственный #' . $assignee . ').',
	];

	$fm = [];
	$multi = \CCrmFieldMulti::GetList(['ID' => 'ASC'], ['ENTITY_ID' => 'LEAD', 'ELEMENT_ID' => $sourceLeadId]);
	while ($row = $multi->Fetch()) {
		$type = $row['TYPE_ID'];
		if ($type !== 'PHONE' && $type !== 'EMAIL') {
			continue;
		}
		$fm[$type][] = [
			'VALUE' => $row['VALUE'],
			'VALUE_TYPE' => $row['VALUE_TYPE'] ?: 'WORK',
		];
	}
	if ($fm) {
		$fields['FM'] = $fm;
	}

	$lead = new \CCrmLead(false);
	$id = (int)$lead->Add($fields, true, [
		'CURRENT_USER' => (int)($fields['ASSIGNED_BY_ID'] ?: 1),
		'DISABLE_USER_FIELD_CHECK' => true,
	]);
	if ($id <= 0) {
		olLineLeadsLog('CCrmLead::Add error: ' . $lead->LAST_ERROR);
		return 0;
	}
	return $id;
}

function olLineLeadsRebindChatToLead($chatId, $oldLeadId, $newLeadId, $activityId = 0)
{
	$chatId = (int)$chatId;
	$oldLeadId = (int)$oldLeadId;
	$newLeadId = (int)$newLeadId;
	$leadType = class_exists('CCrmOwnerType') ? (int)\CCrmOwnerType::Lead : 1;

	$resolved = olLineLeadsResolveIds($chatId);
	$sessionId = (int)($resolved['sessionId'] ?? 0);
	$realChatId = (int)($resolved['chatId'] ?? 0) ?: $chatId;
	$session = is_array($resolved['session']) ? $resolved['session'] : null;

	$moved = [];
	$moveActivity = static function ($actId) use ($leadType, $newLeadId, $oldLeadId, &$moved) {
		$actId = (int)$actId;
		if ($actId <= 0 || isset($moved[$actId])) {
			return false;
		}
		$moved[$actId] = true;
		$ok = false;

		// 1) Смена владельца + bindings (иначе чат остаётся в таймлайне старого лида)
		try {
			$fields = [
				'OWNER_TYPE_ID' => $leadType,
				'OWNER_ID' => $newLeadId,
				'BINDINGS' => [
					['OWNER_TYPE_ID' => $leadType, 'OWNER_ID' => $newLeadId],
				],
			];
			$ok = (bool)\CCrmActivity::Update($actId, $fields, false, true, [
				'REGISTER_SONET_EVENT' => false,
				'SKIP_USER_FIELD_CHECK' => true,
			]);
			if (!$ok) {
				olLineLeadsLog("activity {$actId} Update failed");
			}
		} catch (\Throwable $e) {
			olLineLeadsLog("activity {$actId} Update: " . $e->getMessage());
		}

		// 2) ChangeOwner если есть
		try {
			if (method_exists('CCrmActivity', 'ChangeOwner')) {
				\CCrmActivity::ChangeOwner($actId, $leadType, $newLeadId);
				$ok = true;
			}
		} catch (\Throwable $e) {
			olLineLeadsLog("activity {$actId} ChangeOwner: " . $e->getMessage());
		}

		// 3) Явно снять binding со старого лида / повесить на новый
		if (class_exists('\Bitrix\Crm\ActivityBindingTable')) {
			try {
				$rsDel = \Bitrix\Crm\ActivityBindingTable::getList([
					'filter' => [
						'=ACTIVITY_ID' => $actId,
						'=OWNER_TYPE_ID' => $leadType,
						'=OWNER_ID' => $oldLeadId,
					],
				]);
				while ($b = $rsDel->fetch()) {
					$pk = $b['ID'] ?? null;
					if ($pk) {
						\Bitrix\Crm\ActivityBindingTable::delete($pk);
					} else {
						\Bitrix\Crm\ActivityBindingTable::delete([
							'ACTIVITY_ID' => $actId,
							'OWNER_ID' => $oldLeadId,
							'OWNER_TYPE_ID' => $leadType,
						]);
					}
				}
				$exists = \Bitrix\Crm\ActivityBindingTable::getList([
					'filter' => [
						'=ACTIVITY_ID' => $actId,
						'=OWNER_TYPE_ID' => $leadType,
						'=OWNER_ID' => $newLeadId,
					],
					'limit' => 1,
				])->fetch();
				if (!$exists) {
					\Bitrix\Crm\ActivityBindingTable::add([
						'ACTIVITY_ID' => $actId,
						'OWNER_TYPE_ID' => $leadType,
						'OWNER_ID' => $newLeadId,
					]);
				}
				$ok = true;
			} catch (\Throwable $e) {
				olLineLeadsLog("activity {$actId} bindingTable: " . $e->getMessage());
			}
		}

		olLineLeadsLog("activity {$actId} moved {$oldLeadId} → {$newLeadId} ok=" . ($ok ? 'Y' : 'N'));
		return $ok;
	};

	try {
		if ($activityId > 0) {
			$moveActivity($activityId);
		}
		if (is_array($session) && !empty($session['CRM_ACTIVITY_ID'])) {
			$moveActivity((int)$session['CRM_ACTIVITY_ID']);
		}

		// Активности старого лида (по OWNER)
		$res = \CCrmActivity::GetList(
			['ID' => 'DESC'],
			[
				'OWNER_TYPE_ID' => $leadType,
				'OWNER_ID' => $oldLeadId,
				'CHECK_PERMISSIONS' => 'N',
			],
			false,
			['nTopCount' => 100],
			['ID', 'ASSOCIATED_ENTITY_ID', 'PROVIDER_ID', 'SUBJECT', 'SETTINGS']
		);
		while ($act = $res->Fetch()) {
			$assoc = (int)($act['ASSOCIATED_ENTITY_ID'] ?? 0);
			$cid = olLineLeadsExtractChatIdFromActivity($act);
			if (
				$cid === $realChatId
				|| $cid === $chatId
				|| $assoc === $realChatId
				|| $assoc === $chatId
				|| ($sessionId > 0 && $assoc === $sessionId)
			) {
				$moveActivity((int)$act['ID']);
			}
		}

		// Активности, прибитые binding'ом к старому лиду (OWNER уже мог смениться)
		if (class_exists('\Bitrix\Crm\ActivityBindingTable')) {
			$rs = \Bitrix\Crm\ActivityBindingTable::getList([
				'filter' => [
					'=OWNER_TYPE_ID' => $leadType,
					'=OWNER_ID' => $oldLeadId,
				],
				'select' => ['ACTIVITY_ID'],
				'limit' => 100,
			]);
			while ($b = $rs->fetch()) {
				$aid = (int)($b['ACTIVITY_ID'] ?? 0);
				if ($aid <= 0) {
					continue;
				}
				$act = \CCrmActivity::GetByID($aid, false);
				if (!is_array($act)) {
					continue;
				}
				$assoc = (int)($act['ASSOCIATED_ENTITY_ID'] ?? 0);
				$cid = olLineLeadsExtractChatIdFromActivity($act);
				if (
					$cid === $realChatId
					|| $cid === $chatId
					|| $assoc === $realChatId
					|| $assoc === $chatId
					|| ($sessionId > 0 && $assoc === $sessionId)
				) {
					$moveActivity($aid);
				}
			}
		}
	} catch (\Throwable $e) {
		olLineLeadsLog('activity update: ' . $e->getMessage());
	}

	try {
		if (class_exists('\Bitrix\ImOpenLines\Crm\Common')) {
			$entityOld = [['ENTITY_TYPE_ID' => $leadType, 'ENTITY_ID' => $oldLeadId]];
			$entityNew = [['ENTITY_TYPE_ID' => $leadType, 'ENTITY_ID' => $newLeadId]];
			if (method_exists('\Bitrix\ImOpenLines\Crm\Common', 'unbind')) {
				\Bitrix\ImOpenLines\Crm\Common::unbind($realChatId, $entityOld);
			}
			if (method_exists('\Bitrix\ImOpenLines\Crm\Common', 'bind')) {
				\Bitrix\ImOpenLines\Crm\Common::bind($realChatId, $entityNew);
			}
		}
	} catch (\Throwable $e) {
		olLineLeadsLog('crm common bind: ' . $e->getMessage());
	}

	// Сессия: на этой сборке нет CRM_ENTITY_ID — есть CRM_ACTIVITY_ID / флаги CRM_*
	try {
		if (class_exists('\Bitrix\ImOpenLines\Model\SessionTable') && $sessionId > 0) {
			$row = \Bitrix\ImOpenLines\Model\SessionTable::getById($sessionId)->fetch();
			if (!is_array($row)) {
				$row = \Bitrix\ImOpenLines\Model\SessionTable::getList([
					'filter' => ['=CHAT_ID' => $realChatId],
					'order' => ['ID' => 'DESC'],
					'limit' => 1,
				])->fetch();
			}
			if (is_array($row) && !empty($row['ID'])) {
				$candidates = [];
				// новые поля (если появятся)
				foreach (['CRM_ENTITY_ID', 'CRM_CREATE_ID'] as $k) {
					if (array_key_exists($k, $row)) {
						$candidates[$k] = $newLeadId;
					}
				}
				foreach (['CRM_ENTITY_TYPE', 'CRM_CREATE_ENTITY'] as $k) {
					if (array_key_exists($k, $row)) {
						$candidates[$k] = 'LEAD';
					}
				}
				// CRM_CREATE_LEAD на части сборок = ID лида, на части = Y/N — пишем ID только если там уже число
				if (array_key_exists('CRM_CREATE_LEAD', $row)) {
					$cur = $row['CRM_CREATE_LEAD'];
					if (is_numeric($cur) || (int)$cur > 1) {
						$candidates['CRM_CREATE_LEAD'] = $newLeadId;
					}
				}
				if ($candidates) {
					try {
						\Bitrix\ImOpenLines\Model\SessionTable::update((int)$row['ID'], $candidates);
					} catch (\Throwable $e) {
						foreach ($candidates as $k => $v) {
							try {
								\Bitrix\ImOpenLines\Model\SessionTable::update((int)$row['ID'], [$k => $v]);
							} catch (\Throwable $e2) {
								/* skip */
							}
						}
					}
				}
			}
		}
	} catch (\Throwable $e) {
		olLineLeadsLog('session update: ' . $e->getMessage());
	}

	olLineLeadsLog(
		"rebind chat={$realChatId} session={$sessionId} {$oldLeadId}→{$newLeadId} activities="
		. implode(',', array_keys($moved))
	);
}

/**
 * Лид → сделка: штатный конверт не тащит IMOL-активность в таймлайн сделки.
 */
function olLineLeadsOnAfterDealAdd(&$fields)
{
	try {
		if (!is_array($fields)) {
			return;
		}
		$dealId = (int)($fields['ID'] ?? 0);
		$leadId = (int)($fields['LEAD_ID'] ?? 0);
		if ($dealId <= 0 || $leadId <= 0) {
			return;
		}
		olLineLeadsScheduleLeadToDeal($leadId, $dealId);
	} catch (\Throwable $e) {
		olLineLeadsLog('OnAfterDealAdd: ' . $e->getMessage());
	}
}

function olLineLeadsOnAfterLeadConvert($leadId)
{
	try {
		$leadId = (int)$leadId;
		if ($leadId <= 0) {
			return;
		}
		if (!\Bitrix\Main\Loader::includeModule('crm')) {
			return;
		}
		$deal = \CCrmDeal::GetListEx(
			['ID' => 'DESC'],
			['LEAD_ID' => $leadId, 'CHECK_PERMISSIONS' => 'N'],
			false,
			['nTopCount' => 1],
			['ID', 'LEAD_ID']
		);
		$row = $deal ? $deal->Fetch() : false;
		if (!$row && class_exists('\CCrmDeal')) {
			$res = \CCrmDeal::GetList(['ID' => 'DESC'], ['LEAD_ID' => $leadId], ['ID', 'LEAD_ID'], 1);
			$row = $res ? $res->Fetch() : false;
		}
		$dealId = (int)($row['ID'] ?? 0);
		if ($dealId <= 0) {
			olLineLeadsLog("convert lead={$leadId}: deal not found");
			return;
		}
		olLineLeadsScheduleLeadToDeal($leadId, $dealId);
	} catch (\Throwable $e) {
		olLineLeadsLog('OnAfterLeadConvert: ' . $e->getMessage());
	}
}

function olLineLeadsScheduleLeadToDeal($leadId, $dealId)
{
	$leadId = (int)$leadId;
	$dealId = (int)$dealId;
	if ($leadId <= 0 || $dealId <= 0) {
		return;
	}
	static $queued = [];
	$key = $leadId . ':' . $dealId;
	if (isset($queued[$key])) {
		return;
	}
	$queued[$key] = true;

	$job = static function () use ($leadId, $dealId) {
		for ($i = 0; $i < 4; $i++) {
			if ($i > 0) {
				sleep(2);
			}
			olLineLeadsRebindLeadOlToDeal($leadId, $dealId);
		}
	};
	try {
		if (class_exists('\Bitrix\Main\Application')) {
			\Bitrix\Main\Application::getInstance()->addBackgroundJob($job);
			return;
		}
	} catch (\Throwable $e) {
		/* fall through */
	}
	$job();
}

function olLineLeadsNormalizePhoneDigits($value)
{
	return preg_replace('/\D+/', '', (string)$value);
}

function olLineLeadsPhoneTail($value)
{
	$digits = olLineLeadsNormalizePhoneDigits($value);
	if (strlen($digits) < 10) {
		return '';
	}
	return substr($digits, -10);
}

/**
 * @return int[]
 */
function olLineLeadsGetDealContactIds($dealId)
{
	$dealId = (int)$dealId;
	$contactIds = [];
	if ($dealId <= 0 || !\Bitrix\Main\Loader::includeModule('crm')) {
		return $contactIds;
	}

	$deal = \CCrmDeal::GetByID($dealId, false);
	if (is_array($deal)) {
		$cid = (int)($deal['CONTACT_ID'] ?? 0);
		if ($cid > 0) {
			$contactIds[$cid] = $cid;
		}
	}

	try {
		if (class_exists('\Bitrix\Crm\Binding\DealContactTable')) {
			$rows = \Bitrix\Crm\Binding\DealContactTable::getDealContactIDs($dealId);
			if (is_array($rows)) {
				foreach ($rows as $rowCid) {
					$rowCid = (int)$rowCid;
					if ($rowCid > 0) {
						$contactIds[$rowCid] = $rowCid;
					}
				}
			}
		}
	} catch (\Throwable $e) {
		/* ignore */
	}

	if (count($contactIds) <= 1) {
		try {
			$res = \CCrmDeal::GetContactIDs($dealId);
			if (is_array($res)) {
				foreach ($res as $rowCid) {
					$rowCid = (int)$rowCid;
					if ($rowCid > 0) {
						$contactIds[$rowCid] = $rowCid;
					}
				}
			}
		} catch (\Throwable $e) {
			/* ignore */
		}
	}

	return array_values($contactIds);
}

/**
 * @return string[]
 */
function olLineLeadsGetDealContactPhones($dealId)
{
	$dealId = (int)$dealId;
	$phones = [];
	if ($dealId <= 0 || !\Bitrix\Main\Loader::includeModule('crm')) {
		return $phones;
	}

	$contactIds = olLineLeadsGetDealContactIds($dealId);

	$pushPhone = static function ($value) use (&$phones) {
		$tail = olLineLeadsPhoneTail($value);
		if ($tail !== '') {
			$phones[$tail] = $tail;
		}
	};

	foreach ($contactIds as $contactId) {
		try {
			$multi = \CCrmFieldMulti::GetList(
				['ID' => 'ASC'],
				[
					'ENTITY_ID' => 'CONTACT',
					'ELEMENT_ID' => (int)$contactId,
					'TYPE_ID' => 'PHONE',
				]
			);
			while ($row = $multi->Fetch()) {
				$pushPhone($row['VALUE'] ?? '');
			}
		} catch (\Throwable $e) {
			/* ignore */
		}
	}

	return array_values($phones);
}

/**
 * @return string[]
 */
function olLineLeadsGetChatPhoneTails($chatId)
{
	$chatId = (int)$chatId;
	$tails = [];
	if ($chatId <= 0) {
		return $tails;
	}

	$addFromText = static function ($text) use (&$tails) {
		$text = (string)$text;
		if ($text === '') {
			return;
		}
		if (preg_match_all('/(\d{10,15})/', $text, $matches)) {
			foreach ($matches[1] as $digits) {
				$tail = olLineLeadsPhoneTail($digits);
				if ($tail !== '') {
					$tails[$tail] = $tail;
				}
			}
		}
	};

	$resolved = olLineLeadsResolveIds($chatId);
	$chat = is_array($resolved['chat']) ? $resolved['chat'] : null;
	$session = is_array($resolved['session']) ? $resolved['session'] : null;

	if ($chat) {
		$addFromText($chat['ENTITY_ID'] ?? '');
		foreach (['ENTITY_DATA_1', 'ENTITY_DATA_2', 'ENTITY_DATA_3'] as $key) {
			$addFromText($chat[$key] ?? '');
		}
	}
	if ($session) {
		$addFromText($session['USER_CODE'] ?? '');
	}

	return array_values($tails);
}

function olLineLeadsChatMatchesPhones($chatId, array $phones)
{
	$want = [];
	foreach ($phones as $phone) {
		$tail = olLineLeadsPhoneTail($phone);
		if ($tail !== '') {
			$want[$tail] = $tail;
		}
	}
	$want = array_values($want);
	if (!$want) {
		return true;
	}
	$have = olLineLeadsGetChatPhoneTails($chatId);
	if (!$have) {
		return false;
	}
	foreach ($want as $tail) {
		if (in_array($tail, $have, true)) {
			return true;
		}
	}
	return false;
}

/**
 * @return array<int, array{CHAT_ID:int,LINE_ID:int,KEY:string,DEBUG?:array}>
 */
function olLineLeadsGetChatsForDeal($dealId)
{
	$dealId = (int)$dealId;
	$result = [];
	$seen = [];

	$add = static function ($cid, $line = 0) use (&$result, &$seen, $dealId) {
		$cid = (int)$cid;
		if ($cid <= 0) {
			return;
		}
		$resolved = olLineLeadsResolveIds($cid);
		$realCid = (int)$resolved['chatId'] > 0 ? (int)$resolved['chatId'] : $cid;
		if (isset($seen[$realCid]) || isset($seen[$cid])) {
			return;
		}
		$seen[$realCid] = true;
		$seen[$cid] = true;
		$meta = olLineLeadsGetChatMeta($cid, 0);
		$lineId = (int)$line ?: (int)$meta['line_id'];
		$key = (string)$meta['key'];
		if ($key === '' && $lineId > 0) {
			$key = 'L:' . $lineId;
		}
		$result[] = [
			'CHAT_ID' => $realCid,
			'RAW_ID' => $cid,
			'LINE_ID' => $lineId,
			'KEY' => $key,
			'PHONE_TAILS' => olLineLeadsGetChatPhoneTails($realCid),
			'DEBUG' => $meta['debug'],
		];
	};

	try {
		if (class_exists('\Bitrix\ImOpenLines\Model\SessionTable')) {
			foreach (olLineLeadsGetDealSessionFilters($dealId) as $filter) {
				try {
					$rs = \Bitrix\ImOpenLines\Model\SessionTable::getList([
						'filter' => $filter,
						'order' => ['ID' => 'DESC'],
						'limit' => 30,
						'select' => ['ID', 'CHAT_ID', 'CONFIG_ID'],
					]);
					while ($row = $rs->fetch()) {
						$add((int)$row['CHAT_ID'], (int)($row['CONFIG_ID'] ?? 0));
					}
				} catch (\Throwable $e) {
					/* ignore */
				}
			}
		}
	} catch (\Throwable $e) {
		/* ignore */
	}

	try {
		if (class_exists('\Bitrix\ImOpenLines\Crm\Common')
			&& method_exists('\Bitrix\ImOpenLines\Crm\Common', 'getChatsByEntity')
		) {
			$list = \Bitrix\ImOpenLines\Crm\Common::getChatsByEntity('DEAL', $dealId);
			if (is_array($list)) {
				foreach ($list as $item) {
					$add((int)($item['CHAT_ID'] ?? $item['chatId'] ?? $item['ID'] ?? 0));
				}
			}
		}
	} catch (\Throwable $e) {
		/* ignore */
	}

	try {
		$dealType = class_exists('CCrmOwnerType') ? (int)\CCrmOwnerType::Deal : 2;
		$res = \CCrmActivity::GetList(
			['ID' => 'DESC'],
			[
				'OWNER_TYPE_ID' => $dealType,
				'OWNER_ID' => $dealId,
				'CHECK_PERMISSIONS' => 'N',
			],
			false,
			['nTopCount' => 100],
			['ID', 'PROVIDER_ID', 'PROVIDER_TYPE_ID', 'ASSOCIATED_ENTITY_ID', 'SUBJECT', 'SETTINGS']
		);
		while ($act = $res->Fetch()) {
			if (!olLineLeadsActivityLooksLikeOpenLine($act)) {
				continue;
			}
			$cid = olLineLeadsExtractChatIdFromActivity($act);
			if ($cid) {
				$add($cid);
			}
		}
	} catch (\Throwable $e) {
		/* ignore */
	}

	return $result;
}

/**
 * @param array<int, array<string, mixed>> $chats
 * @return array<int, array<string, mixed>>
 */
function olLineLeadsFilterChatsByAssigneeContact(array $chats, $dealId, array $opts = [])
{
	$dealId = (int)$dealId;
	$phones = olLineLeadsGetDealContactPhones($dealId);
	$targetLines = olLineLeadsResolveTargetLineIdsForDeal($dealId, $opts);
	$out = [];

	foreach ($chats as $row) {
		$cid = (int)($row['CHAT_ID'] ?? 0);
		$lineId = (int)($row['LINE_ID'] ?? 0);
		if ($cid <= 0) {
			continue;
		}
		if ($phones && !olLineLeadsChatMatchesPhones($cid, $phones)) {
			continue;
		}
		if ($targetLines && ($lineId <= 0 || !in_array($lineId, $targetLines, true))) {
			continue;
		}
		$forceChatId = (int)($opts['chatId'] ?? 0);
		if ($forceChatId > 0 && $cid !== olLineLeadsResolveRealChatId($forceChatId)) {
			continue;
		}
		if ($forceChatId > 0) {
			$keepMeta = olLineLeadsGetChatMeta($forceChatId, 0);
			$keepHead = olLineLeadsGetConnectorHeadFromUserCode((string)($keepMeta['debug']['USER_CODE'] ?? ''));
			if ($keepHead !== '') {
				$meta = olLineLeadsGetChatMeta($cid, 0);
				$head = olLineLeadsGetConnectorHeadFromUserCode((string)($meta['debug']['USER_CODE'] ?? ''));
				if ($head === '' || $head !== $keepHead) {
					continue;
				}
			}
		}
		$out[] = $row;
	}

	return $out;
}

/**
 * @return int[]
 */
function olLineLeadsFindChatsByPhones(array $phones)
{
	$tails = [];
	foreach ($phones as $phone) {
		$tail = olLineLeadsPhoneTail($phone);
		if ($tail !== '') {
			$tails[$tail] = $tail;
		}
	}
	$tails = array_values($tails);
	if (!$tails) {
		return [];
	}

	$found = [];
	$add = static function ($chatId) use (&$found) {
		$chatId = (int)$chatId;
		if ($chatId > 0) {
			$found[$chatId] = $chatId;
		}
	};

	foreach ($tails as $tail) {
		try {
			if (class_exists('\Bitrix\ImOpenLines\Model\SessionTable')) {
				$rs = \Bitrix\ImOpenLines\Model\SessionTable::getList([
					'filter' => ['%USER_CODE' => $tail],
					'order' => ['ID' => 'DESC'],
					'limit' => 10,
					'select' => ['CHAT_ID', 'USER_CODE'],
				]);
				while ($row = $rs->fetch()) {
					$add((int)($row['CHAT_ID'] ?? 0));
				}
			}
		} catch (\Throwable $e) {
			/* ignore */
		}

		try {
			if (class_exists('\Bitrix\Im\Model\ChatTable')) {
				$rs = \Bitrix\Im\Model\ChatTable::getList([
					'filter' => [
						'LOGIC' => 'OR',
						['%ENTITY_ID' => $tail],
						['%ENTITY_DATA_1' => $tail],
						['%ENTITY_DATA_2' => $tail],
						['%ENTITY_DATA_3' => $tail],
					],
					'order' => ['ID' => 'DESC'],
					'limit' => 10,
					'select' => ['ID', 'ENTITY_ID'],
				]);
				while ($row = $rs->fetch()) {
					$add((int)($row['ID'] ?? 0));
				}
			}
		} catch (\Throwable $e) {
			/* ignore */
		}
	}

	$matched = [];
	foreach (array_values($found) as $chatId) {
		if (olLineLeadsChatMatchesPhones($chatId, $phones)) {
			$matched[$chatId] = $chatId;
		}
	}

	return array_values($matched);
}

function olLineLeadsGetDealAssigneeId($dealId)
{
	$dealId = (int)$dealId;
	if ($dealId <= 0 || !\Bitrix\Main\Loader::includeModule('crm')) {
		return 0;
	}
	$deal = \CCrmDeal::GetByID($dealId, false);
	return is_array($deal) ? (int)($deal['ASSIGNED_BY_ID'] ?? 0) : 0;
}

/**
 * Линии ОЛ, в очереди которых состоит пользователь.
 *
 * @return int[]
 */
function olLineLeadsGetUserQueueLineIds($userId)
{
	$userId = (int)$userId;
	$out = [];
	if ($userId <= 0 || !class_exists('\Bitrix\ImOpenLines\Model\QueueTable')) {
		return $out;
	}
	try {
		$rs = \Bitrix\ImOpenLines\Model\QueueTable::getList([
			'filter' => ['=USER_ID' => $userId],
			'select' => ['CONFIG_ID'],
		]);
		while ($row = $rs->fetch()) {
			$lineId = (int)($row['CONFIG_ID'] ?? 0);
			if ($lineId > 0) {
				$out[$lineId] = $lineId;
			}
		}
	} catch (\Throwable $e) {
		/* ignore */
	}
	return array_values($out);
}

/**
 * Целевые CONFIG_ID для сделки: очередь ответственного или ?lineId=.
 *
 * @return int[]
 */
function olLineLeadsResolveTargetLineIdsForDeal($dealId, array $opts = [])
{
	$forceLineId = (int)($opts['lineId'] ?? 0);
	if ($forceLineId > 0) {
		return [$forceLineId];
	}

	$lineIds = olLineLeadsGetUserQueueLineIds(olLineLeadsGetDealAssigneeId($dealId));

	$forceChatId = (int)($opts['chatId'] ?? 0);
	if ($forceChatId > 0) {
		$meta = olLineLeadsGetChatMeta($forceChatId, 0);
		$fromChat = (int)$meta['line_id'];
		if ($fromChat > 0) {
			$lineIds[$fromChat] = $fromChat;
		}
	}

	return array_values($lineIds);
}

function olLineLeadsGetChatLineId($chatId)
{
	$meta = olLineLeadsGetChatMeta((int)$chatId, 0);
	return (int)$meta['line_id'];
}

/**
 * @return int[]
 */
function olLineLeadsChatAssocIds($chatId)
{
	$chatId = (int)$chatId;
	$ids = [$chatId];
	$resolved = olLineLeadsResolveIds($chatId);
	if ((int)($resolved['sessionId'] ?? 0) > 0) {
		$ids[] = (int)$resolved['sessionId'];
	}
	if ((int)($resolved['chatId'] ?? 0) > 0) {
		$ids[] = (int)$resolved['chatId'];
	}
	return array_values(array_unique(array_filter($ids)));
}

function olLineLeadsScoreChatForDeal($chatId, $dealId, array $opts = [])
{
	$chatId = (int)$chatId;
	$dealId = (int)$dealId;
	if ($chatId <= 0 || $dealId <= 0) {
		return -1e9;
	}

	$score = 0;
	$assignee = olLineLeadsGetDealAssigneeId($dealId);
	$targetLines = olLineLeadsResolveTargetLineIdsForDeal($dealId, $opts);
	$chatLineId = olLineLeadsGetChatLineId($chatId);
	$resolved = olLineLeadsResolveIds($chatId);
	$session = is_array($resolved['session'] ?? null) ? $resolved['session'] : null;

	if ((int)($opts['chatId'] ?? 0) === $chatId) {
		$score += 1000;
	}

	if ($targetLines) {
		if ($chatLineId > 0 && in_array($chatLineId, $targetLines, true)) {
			$score += 300;
		} elseif ($chatLineId > 0) {
			$score -= 800;
		}
	}

	if ($assignee > 0 && is_array($session) && (int)($session['OPERATOR_ID'] ?? 0) === $assignee) {
		$score += 180;
	}

	if ($assignee > 0 && $chatLineId > 0) {
		$userLines = olLineLeadsGetUserQueueLineIds($assignee);
		if (in_array($chatLineId, $userLines, true)) {
			$score += 150;
		}
	}

	if (is_array($session)) {
		$crmType = strtoupper((string)($session['CRM_ENTITY_TYPE'] ?? ''));
		$crmId = (int)($session['CRM_ENTITY_ID'] ?? 0);
		if ($crmType === 'DEAL' && $crmId === $dealId) {
			$score += 90;
		}
		$score += min(40, (int)($session['ID'] ?? 0) / 50000);
	}

	return $score;
}

/**
 * Один чат: линия ответственного на сделке + score.
 */
function olLineLeadsPickBestChatForDeal(array $chatIds, $dealId, array $opts = [])
{
	$chatIds = array_values(array_unique(array_map('intval', $chatIds)));
	$chatIds = array_values(array_filter($chatIds, static function ($id) {
		return $id > 0;
	}));
	if (!$chatIds) {
		return 0;
	}

	$targetLines = olLineLeadsResolveTargetLineIdsForDeal($dealId, $opts);
	$filtered = [];
	foreach ($chatIds as $chatId) {
		$chatLineId = olLineLeadsGetChatLineId($chatId);
		if (!$targetLines) {
			$filtered[] = $chatId;
			continue;
		}
		if ($chatLineId <= 0 || in_array($chatLineId, $targetLines, true)) {
			$filtered[] = $chatId;
		}
	}
	if (!$filtered) {
		$filtered = $chatIds;
	}

	$bestId = 0;
	$bestScore = -1e9;
	foreach ($filtered as $chatId) {
		$score = olLineLeadsScoreChatForDeal($chatId, $dealId, $opts);
		if ($score > $bestScore) {
			$bestScore = $score;
			$bestId = $chatId;
		}
	}

	return $bestId;
}

/**
 * @return array<int, array<string, mixed>>
 */
function olLineLeadsGetDealSessionFilters($dealId)
{
	$dealId = (int)$dealId;
	$dealType = class_exists('CCrmOwnerType') ? (int)\CCrmOwnerType::Deal : 2;
	return [
		['=CRM_ENTITY_TYPE' => 'DEAL', '=CRM_ENTITY_ID' => $dealId],
		['=CRM_ENTITY_TYPE' => 'deal', '=CRM_ENTITY_ID' => $dealId],
		['=CRM_CREATE_ENTITY' => 'DEAL', '=CRM_CREATE_ID' => $dealId],
		['=CRM_CREATE_ENTITY' => 'deal', '=CRM_CREATE_ID' => $dealId],
		['=CRM_ENTITY_TYPE' => (string)$dealType, '=CRM_ENTITY_ID' => $dealId],
		['=CRM_CREATE_ENTITY' => (string)$dealType, '=CRM_CREATE_ID' => $dealId],
	];
}

function olLineLeadsSessionPointsToDeal(array $row, $dealId)
{
	$dealId = (int)$dealId;
	$pairs = [
		['CRM_ENTITY_TYPE', 'CRM_ENTITY_ID'],
		['CRM_CREATE_ENTITY', 'CRM_CREATE_ID'],
	];
	foreach ($pairs as [$typeKey, $idKey]) {
		if (!array_key_exists($typeKey, $row) && !array_key_exists($idKey, $row)) {
			continue;
		}
		$eid = (int)($row[$idKey] ?? 0);
		if ($eid !== $dealId) {
			continue;
		}
		$type = strtoupper((string)($row[$typeKey] ?? ''));
		if ($type === 'DEAL' || $type === '2' || $type === (string)(class_exists('CCrmOwnerType') ? (int)\CCrmOwnerType::Deal : 2)) {
			return true;
		}
		if ($type === '' && $eid === $dealId) {
			return true;
		}
	}
	return false;
}

function olLineLeadsClearSessionCrmDealLink(array $row, $dealId, $chatId)
{
	$dealId = (int)$dealId;
	$chatId = olLineLeadsResolveRealChatId($chatId);
	if ($dealId <= 0 || $chatId <= 0 || empty($row['ID']) || !olLineLeadsSessionPointsToDeal($row, $dealId)) {
		return false;
	}

	$leadId = olLineLeadsGetCrmLeadForChat($chatId);
	$upd = [];
	foreach (['CRM_ENTITY_TYPE', 'CRM_CREATE_ENTITY'] as $k) {
		if (array_key_exists($k, $row)) {
			$upd[$k] = $leadId > 0 ? 'LEAD' : '';
		}
	}
	foreach (['CRM_ENTITY_ID', 'CRM_CREATE_ID'] as $k) {
		if (array_key_exists($k, $row)) {
			$upd[$k] = $leadId > 0 ? $leadId : 0;
		}
	}
	if (!$upd || !class_exists('\Bitrix\ImOpenLines\Model\SessionTable')) {
		return false;
	}

	try {
		\Bitrix\ImOpenLines\Model\SessionTable::update((int)$row['ID'], $upd);
		return true;
	} catch (\Throwable $e) {
		olLineLeadsLog("clear session deal link {$row['ID']}: " . $e->getMessage());
		return false;
	}
}

/**
 * @return int[]
 */
function olLineLeadsClearChatSessionsFromDeal($chatId, $dealId)
{
	$chatId = olLineLeadsResolveRealChatId($chatId);
	$dealId = (int)$dealId;
	$cleared = [];
	if ($chatId <= 0 || $dealId <= 0 || !class_exists('\Bitrix\ImOpenLines\Model\SessionTable')) {
		return $cleared;
	}

	try {
		$rs = \Bitrix\ImOpenLines\Model\SessionTable::getList([
			'filter' => ['=CHAT_ID' => $chatId],
			'select' => ['ID', 'CHAT_ID', 'CRM_ENTITY_TYPE', 'CRM_ENTITY_ID', 'CRM_CREATE_ENTITY', 'CRM_CREATE_ID'],
		]);
		while ($row = $rs->fetch()) {
			if (olLineLeadsClearSessionCrmDealLink($row, $dealId, $chatId)) {
				$cleared[] = (int)$row['ID'];
			}
		}
	} catch (\Throwable $e) {
		olLineLeadsLog("clear chat {$chatId} sessions from deal {$dealId}: " . $e->getMessage());
	}

	return $cleared;
}

function olLineLeadsIsOlLineActive($lineId)
{
	$lineId = (int)$lineId;
	if ($lineId <= 0) {
		return false;
	}
	try {
		if (class_exists('\Bitrix\ImOpenLines\Model\ConfigTable')) {
			$row = \Bitrix\ImOpenLines\Model\ConfigTable::getById($lineId)->fetch();
			if (!is_array($row) || empty($row['ID'])) {
				return false;
			}
			foreach (['ACTIVE', 'LINE_ACTIVE'] as $k) {
				if (isset($row[$k]) && strtoupper((string)$row[$k]) === 'N') {
					return false;
				}
			}
			return true;
		}
	} catch (\Throwable $e) {
		/* ignore */
	}
	return false;
}

function olLineLeadsGetActivityLineId(array $act)
{
	$lineId = 0;
	$assoc = (int)($act['ASSOCIATED_ENTITY_ID'] ?? 0);
	if ($assoc > 0) {
		$resolved = olLineLeadsResolveIds($assoc);
		$sess = is_array($resolved['session'] ?? null) ? $resolved['session'] : null;
		if ($sess) {
			$lineId = (int)($sess['CONFIG_ID'] ?? 0);
		}
		if ($lineId <= 0) {
			$cid = (int)($resolved['chatId'] ?? 0) ?: $assoc;
			$lineId = olLineLeadsGetChatLineId($cid);
		}
	}
	if ($lineId <= 0) {
		$cid = olLineLeadsExtractChatIdFromActivity($act);
		if ($cid > 0) {
			$lineId = olLineLeadsGetChatLineId($cid);
		}
	}
	return $lineId;
}

function olLineLeadsGetConnectorHeadFromUserCode($userCode)
{
	$userCode = (string)$userCode;
	if ($userCode === '') {
		return '';
	}
	$parts = explode('|', $userCode);
	if (count($parts) < 2) {
		return '';
	}
	return implode('|', array_slice($parts, 0, 2));
}

function olLineLeadsGetActivityConnectorHead(array $act)
{
	$assoc = (int)($act['ASSOCIATED_ENTITY_ID'] ?? 0);
	if ($assoc > 0) {
		$resolved = olLineLeadsResolveIds($assoc);
		$sess = is_array($resolved['session'] ?? null) ? $resolved['session'] : null;
		if ($sess && !empty($sess['USER_CODE'])) {
			$head = olLineLeadsGetConnectorHeadFromUserCode((string)$sess['USER_CODE']);
			if ($head !== '') {
				return $head;
			}
		}
	}
	$cid = olLineLeadsExtractChatIdFromActivity($act);
	if ($cid > 0) {
		$meta = olLineLeadsGetChatMeta($cid, 0);
		$head = olLineLeadsGetConnectorHeadFromUserCode((string)($meta['debug']['USER_CODE'] ?? ''));
		if ($head !== '') {
			return $head;
		}
	}
	return '';
}

/**
 * Снять лишние CRM-bindings (Common::getChatsByEntity) со сделки.
 *
 * @return int[]
 */
function olLineLeadsPurgeDealCrmCommonBindings($dealId, $keepChatId)
{
	$dealId = (int)$dealId;
	$keepChatId = olLineLeadsResolveRealChatId($keepChatId);
	$purged = [];
	if ($dealId <= 0 || !class_exists('\Bitrix\ImOpenLines\Crm\Common')) {
		return $purged;
	}

	$dealType = class_exists('CCrmOwnerType') ? (int)\CCrmOwnerType::Deal : 2;
	$entityVariants = [
		[['ENTITY_TYPE_ID' => $dealType, 'ENTITY_ID' => $dealId]],
		[['ENTITY_TYPE' => 'DEAL', 'ENTITY_ID' => $dealId]],
		[['ENTITY_TYPE' => 'deal', 'ENTITY_ID' => $dealId]],
	];

	$list = [];
	try {
		if (method_exists('\Bitrix\ImOpenLines\Crm\Common', 'getChatsByEntity')) {
			$list = \Bitrix\ImOpenLines\Crm\Common::getChatsByEntity('DEAL', $dealId);
		}
	} catch (\Throwable $e) {
		olLineLeadsLog('purge common get: ' . $e->getMessage());
	}
	if (!is_array($list)) {
		$list = [];
	}

	foreach ($list as $item) {
		$cid = (int)($item['CHAT_ID'] ?? $item['chatId'] ?? $item['ID'] ?? 0);
		if ($cid <= 0) {
			continue;
		}
		$real = olLineLeadsResolveRealChatId($cid);
		if ($real <= 0 || $real === $keepChatId) {
			continue;
		}
		if (method_exists('\Bitrix\ImOpenLines\Crm\Common', 'unbind')) {
			foreach ($entityVariants as $entity) {
				foreach ([$real, $cid] as $bindId) {
					try {
						\Bitrix\ImOpenLines\Crm\Common::unbind($bindId, $entity);
					} catch (\Throwable $e) {
						/* ignore */
					}
				}
			}
		}
		olLineLeadsClearChatSessionsFromDeal($real, $dealId);
		$purged[$real] = $real;
	}

	return array_values($purged);
}

/**
 * Только живые CRM-привязки (сессии + Common), без истории активностей.
 *
 * @return array<int, array<string, mixed>>
 */
function olLineLeadsGetDealOlBindings($dealId)
{
	$dealId = (int)$dealId;
	$result = [];
	$seen = [];

	$add = static function ($cid, $line = 0) use (&$result, &$seen) {
		$cid = (int)$cid;
		if ($cid <= 0) {
			return;
		}
		$resolved = olLineLeadsResolveIds($cid);
		$realCid = (int)$resolved['chatId'] > 0 ? (int)$resolved['chatId'] : $cid;
		if (isset($seen[$realCid])) {
			return;
		}
		$seen[$realCid] = true;
		$meta = olLineLeadsGetChatMeta($cid, 0);
		$lineId = (int)$line ?: (int)$meta['line_id'];
		$key = (string)$meta['key'];
		if ($key === '' && $lineId > 0) {
			$key = 'L:' . $lineId;
		}
		$result[] = [
			'CHAT_ID' => $realCid,
			'RAW_ID' => $cid,
			'LINE_ID' => $lineId,
			'KEY' => $key,
			'PHONE_TAILS' => olLineLeadsGetChatPhoneTails($realCid),
			'DEBUG' => $meta['debug'],
		];
	};

	if (class_exists('\Bitrix\ImOpenLines\Model\SessionTable')) {
		foreach (olLineLeadsGetDealSessionFilters($dealId) as $filter) {
			try {
				$rs = \Bitrix\ImOpenLines\Model\SessionTable::getList([
					'filter' => $filter,
					'order' => ['ID' => 'DESC'],
					'limit' => 50,
					'select' => ['ID', 'CHAT_ID', 'CONFIG_ID'],
				]);
				while ($row = $rs->fetch()) {
					$add((int)$row['CHAT_ID'], (int)($row['CONFIG_ID'] ?? 0));
				}
			} catch (\Throwable $e) {
				/* поле может отсутствовать */
			}
		}
	}

	try {
		if (class_exists('\Bitrix\ImOpenLines\Crm\Common')
			&& method_exists('\Bitrix\ImOpenLines\Crm\Common', 'getChatsByEntity')
		) {
			$list = \Bitrix\ImOpenLines\Crm\Common::getChatsByEntity('DEAL', $dealId);
			if (is_array($list)) {
				foreach ($list as $item) {
					$add((int)($item['CHAT_ID'] ?? $item['chatId'] ?? $item['ID'] ?? 0));
				}
			}
		}
	} catch (\Throwable $e) {
		/* ignore */
	}

	return $result;
}

function olLineLeadsUnbindChatFromDeal($chatId, $dealId)
{
	$chatId = (int)$chatId;
	$dealId = (int)$dealId;
	if ($chatId <= 0 || $dealId <= 0) {
		return false;
	}
	$dealType = class_exists('CCrmOwnerType') ? (int)\CCrmOwnerType::Deal : 2;
	$realChatId = olLineLeadsResolveRealChatId($chatId);

	try {
		if (class_exists('\Bitrix\ImOpenLines\Crm\Common') && method_exists('\Bitrix\ImOpenLines\Crm\Common', 'unbind')) {
			$entityVariants = [
				[['ENTITY_TYPE_ID' => $dealType, 'ENTITY_ID' => $dealId]],
				[['ENTITY_TYPE' => 'DEAL', 'ENTITY_ID' => $dealId]],
				[['ENTITY_TYPE' => 'deal', 'ENTITY_ID' => $dealId]],
			];
			foreach ($entityVariants as $entity) {
				foreach ([$realChatId, $chatId] as $bindId) {
					if ($bindId <= 0) {
						continue;
					}
					try {
						\Bitrix\ImOpenLines\Crm\Common::unbind($bindId, $entity);
					} catch (\Throwable $e) {
						/* ignore */
					}
				}
			}
		}
	} catch (\Throwable $e) {
		olLineLeadsLog("unbind chat {$chatId} deal {$dealId}: " . $e->getMessage());
	}

	olLineLeadsClearChatSessionsFromDeal($realChatId ?: $chatId, $dealId);

	try {
		if ($realChatId > 0 && class_exists('\Bitrix\ImOpenLines\Model\SessionTable')) {
			foreach (olLineLeadsGetDealSessionFilters($dealId) as $filter) {
				$filter['=CHAT_ID'] = $realChatId;
				try {
					$rs = \Bitrix\ImOpenLines\Model\SessionTable::getList([
						'filter' => $filter,
						'select' => ['ID', 'CHAT_ID', 'CRM_ENTITY_TYPE', 'CRM_ENTITY_ID', 'CRM_CREATE_ENTITY', 'CRM_CREATE_ID'],
					]);
					while ($row = $rs->fetch()) {
						olLineLeadsClearSessionCrmDealLink($row, $dealId, $realChatId);
					}
				} catch (\Throwable $e) {
					/* ignore */
				}
			}
		}
	} catch (\Throwable $e) {
		olLineLeadsLog("unbind sessions chat {$chatId}: " . $e->getMessage());
	}

	try {
		$resolved = olLineLeadsResolveIds($chatId);
		$row = is_array($resolved['session'] ?? null) ? $resolved['session'] : null;
		if (is_array($row) && !empty($row['ID'])) {
			if (olLineLeadsSessionPointsToDeal($row, $dealId)) {
				olLineLeadsClearSessionCrmDealLink($row, $dealId, $chatId);
			}
		}
	} catch (\Throwable $e) {
		olLineLeadsLog("unbind session chat {$chatId}: " . $e->getMessage());
	}

	return true;
}

/**
 * Снять CRM_ENTITY=DEAL со всех OL-сессий сделки, кроме keepChatId.
 *
 * @return int[]
 */
function olLineLeadsClearDealCrmSessionsExcept($dealId, $keepChatId)
{
	$dealId = (int)$dealId;
	$keepChatId = olLineLeadsResolveRealChatId($keepChatId);
	$cleared = [];
	if ($dealId <= 0 || !class_exists('\Bitrix\ImOpenLines\Model\SessionTable')) {
		return $cleared;
	}

	foreach (olLineLeadsGetDealSessionFilters($dealId) as $filter) {
		try {
			$rs = \Bitrix\ImOpenLines\Model\SessionTable::getList([
				'filter' => $filter,
				'select' => ['ID', 'CHAT_ID', 'CRM_ENTITY_TYPE', 'CRM_ENTITY_ID', 'CRM_CREATE_ENTITY', 'CRM_CREATE_ID'],
			]);
			while ($row = $rs->fetch()) {
				$cid = olLineLeadsResolveRealChatId((int)($row['CHAT_ID'] ?? 0));
				if ($cid <= 0 || $cid === $keepChatId) {
					continue;
				}
				if (olLineLeadsClearSessionCrmDealLink($row, $dealId, $cid)) {
					$cleared[$cid] = $cid;
				}
			}
		} catch (\Throwable $e) {
			/* ignore */
		}
	}

	return array_values($cleared);
}

function olLineLeadsResolveRealChatId($rawId)
{
	$rawId = (int)$rawId;
	if ($rawId <= 0) {
		return 0;
	}
	$resolved = olLineLeadsResolveIds($rawId);
	return (int)($resolved['chatId'] ?? 0) ?: $rawId;
}

/**
 * OL на сделке = только keepChat + линии ответственного + телефон контакта + connector keep-чата.
 */
function olLineLeadsDealOlMatchesAssigneeContactPolicy(array $act, $dealId, $keepChatId, array $opts = [])
{
	$keepChatId = olLineLeadsResolveRealChatId($keepChatId);
	$dealId = (int)$dealId;
	if ($keepChatId <= 0 || $dealId <= 0 || !olLineLeadsActivityLooksLikeOpenLine($act)) {
		return false;
	}

	$actChatId = olLineLeadsExtractChatIdFromActivity($act);
	if ($actChatId <= 0) {
		return false;
	}
	$realAct = olLineLeadsResolveRealChatId($actChatId);
	if ($realAct !== $keepChatId) {
		return false;
	}

	$phones = olLineLeadsGetDealContactPhones($dealId);
	if ($phones && !olLineLeadsChatMatchesPhones($realAct, $phones)) {
		return false;
	}

	$targetLines = olLineLeadsResolveTargetLineIdsForDeal($dealId, array_merge($opts, ['chatId' => $keepChatId]));
	$actLine = olLineLeadsGetActivityLineId($act);
	if (!$targetLines || $actLine <= 0 || !in_array($actLine, $targetLines, true)) {
		return false;
	}

	$keepMeta = olLineLeadsGetChatMeta($keepChatId, 0);
	$keepHead = olLineLeadsGetConnectorHeadFromUserCode((string)($keepMeta['debug']['USER_CODE'] ?? ''));
	$actHead = olLineLeadsGetActivityConnectorHead($act);
	if ($keepHead === '' || $actHead === '' || $actHead !== $keepHead) {
		return false;
	}

	$keepAssocIds = olLineLeadsChatAssocIds($keepChatId);
	$assoc = (int)($act['ASSOCIATED_ENTITY_ID'] ?? 0);
	if ($assoc <= 0 || !in_array($assoc, $keepAssocIds, true)) {
		return false;
	}

	return true;
}

/**
 * @return int[]
 */
function olLineLeadsCollectDealOlActivityIds($dealId, array $hintChatIds = [])
{
	$dealId = (int)$dealId;
	$ids = [];
	if ($dealId <= 0 || !\Bitrix\Main\Loader::includeModule('crm')) {
		return $ids;
	}

	$dealType = class_exists('CCrmOwnerType') ? (int)\CCrmOwnerType::Deal : 2;
	$contactType = class_exists('CCrmOwnerType') ? (int)\CCrmOwnerType::Contact : 3;

	$add = static function ($actId) use (&$ids) {
		$actId = (int)$actId;
		if ($actId > 0) {
			$ids[$actId] = $actId;
		}
	};

	try {
		if (class_exists('\Bitrix\Crm\ActivityBindingTable')) {
			$rs = \Bitrix\Crm\ActivityBindingTable::getList([
				'filter' => [
					'=OWNER_TYPE_ID' => $dealType,
					'=OWNER_ID' => $dealId,
				],
				'select' => ['ACTIVITY_ID'],
			]);
			while ($b = $rs->fetch()) {
				$add((int)($b['ACTIVITY_ID'] ?? 0));
			}
		}
	} catch (\Throwable $e) {
		/* ignore */
	}

	try {
		$res = \CCrmActivity::GetList(
			['ID' => 'DESC'],
			[
				'OWNER_TYPE_ID' => $dealType,
				'OWNER_ID' => $dealId,
				'CHECK_PERMISSIONS' => 'N',
			],
			false,
			false,
			['ID']
		);
		while ($row = $res->Fetch()) {
			$add((int)($row['ID'] ?? 0));
		}
	} catch (\Throwable $e) {
		/* ignore */
	}

	foreach (olLineLeadsGetDealContactIds($dealId) as $contactId) {
		try {
			if (class_exists('\Bitrix\Crm\ActivityBindingTable')) {
				$rs = \Bitrix\Crm\ActivityBindingTable::getList([
					'filter' => [
						'=OWNER_TYPE_ID' => $contactType,
						'=OWNER_ID' => (int)$contactId,
					],
					'select' => ['ACTIVITY_ID'],
				]);
				while ($b = $rs->fetch()) {
					$actId = (int)($b['ACTIVITY_ID'] ?? 0);
					if ($actId <= 0) {
						continue;
					}
					try {
						$dealBind = \Bitrix\Crm\ActivityBindingTable::getList([
							'filter' => [
								'=ACTIVITY_ID' => $actId,
								'=OWNER_TYPE_ID' => $dealType,
								'=OWNER_ID' => $dealId,
							],
							'limit' => 1,
						])->fetch();
						if ($dealBind) {
							$add($actId);
						}
					} catch (\Throwable $e) {
						/* ignore */
					}
				}
			}
		} catch (\Throwable $e) {
			/* ignore */
		}
	}

	foreach ($hintChatIds as $chatId) {
		$chatId = (int)$chatId;
		if ($chatId <= 0) {
			continue;
		}
		foreach (olLineLeadsChatAssocIds($chatId) as $assoc) {
			try {
				$res = \CCrmActivity::GetList(
					['ID' => 'DESC'],
					[
						'ASSOCIATED_ENTITY_ID' => $assoc,
						'CHECK_PERMISSIONS' => 'N',
					],
					false,
					false,
					['ID', 'OWNER_TYPE_ID', 'OWNER_ID']
				);
				while ($row = $res->Fetch()) {
					$actId = (int)($row['ID'] ?? 0);
					if ($actId <= 0) {
						continue;
					}
					$linked = ((int)($row['OWNER_TYPE_ID'] ?? 0) === $dealType && (int)($row['OWNER_ID'] ?? 0) === $dealId);
					if (!$linked && class_exists('\Bitrix\Crm\ActivityBindingTable')) {
						try {
							$linked = (bool)\Bitrix\Crm\ActivityBindingTable::getList([
								'filter' => [
									'=ACTIVITY_ID' => $actId,
									'=OWNER_TYPE_ID' => $dealType,
									'=OWNER_ID' => $dealId,
								],
								'limit' => 1,
							])->fetch();
						} catch (\Throwable $e) {
							/* ignore */
						}
					}
					if ($linked) {
						$add($actId);
					}
				}
			} catch (\Throwable $e) {
				/* ignore */
			}
		}
	}

	return array_values($ids);
}

function olLineLeadsActivityBelongsToKeepChat(array $act, $keepChatId, $dealId = 0, array $opts = [])
{
	return olLineLeadsDealOlMatchesAssigneeContactPolicy($act, (int)$dealId, $keepChatId, $opts);
}

/**
 * Убрать OL-активность из таймлайна сделки (binding + owner при необходимости).
 */
function olLineLeadsDetachOlActivityFromDeal($actId, $dealId, $targetLeadId = 0)
{
	$actId = (int)$actId;
	$dealId = (int)$dealId;
	$targetLeadId = (int)$targetLeadId;
	if ($actId <= 0 || $dealId <= 0 || !\Bitrix\Main\Loader::includeModule('crm')) {
		return false;
	}

	$dealType = class_exists('CCrmOwnerType') ? (int)\CCrmOwnerType::Deal : 2;
	$leadType = class_exists('CCrmOwnerType') ? (int)\CCrmOwnerType::Lead : 1;
	$contactType = class_exists('CCrmOwnerType') ? (int)\CCrmOwnerType::Contact : 3;
	$removedBindings = 0;

	if (class_exists('\Bitrix\Crm\ActivityBindingTable')) {
		try {
			$rows = \Bitrix\Crm\ActivityBindingTable::getList([
				'filter' => [
					'=ACTIVITY_ID' => $actId,
					'=OWNER_TYPE_ID' => $dealType,
					'=OWNER_ID' => $dealId,
				],
			]);
			while ($b = $rows->fetch()) {
				\Bitrix\Crm\ActivityBindingTable::delete($b['ID']);
				$removedBindings++;
			}
		} catch (\Throwable $e) {
			olLineLeadsLog("detach act {$actId} binding: " . $e->getMessage());
		}
	}

	$act = \CCrmActivity::GetByID($actId, false);
	if (!is_array($act)) {
		return $removedBindings > 0;
	}

	if ((int)($act['OWNER_TYPE_ID'] ?? 0) === $dealType && (int)($act['OWNER_ID'] ?? 0) === $dealId) {
		if ($targetLeadId <= 0) {
			$cid = olLineLeadsExtractChatIdFromActivity($act);
			if ($cid > 0) {
				$targetLeadId = olLineLeadsGetCrmLeadForChat($cid);
			}
		}
		try {
			if ($targetLeadId > 0) {
				if (method_exists('CCrmActivity', 'ChangeOwner')) {
					\CCrmActivity::ChangeOwner($actId, $leadType, $targetLeadId);
				} else {
					\CCrmActivity::Update($actId, [
						'OWNER_TYPE_ID' => $leadType,
						'OWNER_ID' => $targetLeadId,
					], false, true, [
						'REGISTER_SONET_EVENT' => false,
						'SKIP_USER_FIELD_CHECK' => true,
					]);
				}
			} else {
				$contactIds = olLineLeadsGetDealContactIds($dealId);
				$contactId = $contactIds ? (int)reset($contactIds) : 0;
				if ($contactId <= 0) {
					$deal = \CCrmDeal::GetByID($dealId, false);
					$contactId = is_array($deal) ? (int)($deal['CONTACT_ID'] ?? 0) : 0;
				}
				if ($contactId > 0) {
					if (method_exists('CCrmActivity', 'ChangeOwner')) {
						\CCrmActivity::ChangeOwner($actId, $contactType, $contactId);
					} else {
						\CCrmActivity::Update($actId, [
							'OWNER_TYPE_ID' => $contactType,
							'OWNER_ID' => $contactId,
						], false, true, [
							'REGISTER_SONET_EVENT' => false,
							'SKIP_USER_FIELD_CHECK' => true,
						]);
					}
				}
			}
		} catch (\Throwable $e) {
			olLineLeadsLog("detach act {$actId} owner: " . $e->getMessage());
		}
	} elseif ($removedBindings > 0) {
		// binding с deal снят, но owner другой — всё равно уводим на контакт/лид
		if ($targetLeadId <= 0) {
			$cid = olLineLeadsExtractChatIdFromActivity($act);
			if ($cid > 0) {
				$targetLeadId = olLineLeadsGetCrmLeadForChat($cid);
			}
		}
		$ownerType = (int)($act['OWNER_TYPE_ID'] ?? 0);
		$ownerId = (int)($act['OWNER_ID'] ?? 0);
		if ($ownerType === $dealType && $ownerId === $dealId) {
			/* уже обработано выше */
		} elseif ($targetLeadId > 0 && $ownerType !== $leadType) {
			try {
				if (method_exists('CCrmActivity', 'ChangeOwner')) {
					\CCrmActivity::ChangeOwner($actId, $leadType, $targetLeadId);
				}
			} catch (\Throwable $e) {
				/* ignore */
			}
		}
	}

	return $removedBindings > 0 || is_array($act);
}

/**
 * Снять с таймлайна сделки все OL-активности, кроме keep по политике ответственный+контакт.
 *
 * @return array{detached:int[], kept:int[]}
 */
function olLineLeadsPruneDealOlTimelineBindings($dealId, $keepChatId, array $opts = [])
{
	$dealId = (int)$dealId;
	$keepChatId = (int)$keepChatId;
	$report = ['detached' => [], 'kept' => []];
	if ($dealId <= 0 || $keepChatId <= 0 || !\Bitrix\Main\Loader::includeModule('crm')) {
		return $report;
	}

	$hintChatIds = [];
	foreach (olLineLeadsGetChatsForDeal($dealId) as $row) {
		$cid = (int)($row['CHAT_ID'] ?? 0);
		if ($cid > 0) {
			$hintChatIds[] = $cid;
		}
	}
	$hintChatIds[] = $keepChatId;
	$hintChatIds = array_values(array_unique($hintChatIds));

	$candidates = [];
	foreach (olLineLeadsCollectDealOlActivityIds($dealId, $hintChatIds) as $actId) {
		$act = \CCrmActivity::GetByID($actId, false);
		if (!is_array($act) || !olLineLeadsActivityLooksLikeOpenLine($act)) {
			continue;
		}
		if (olLineLeadsDealOlMatchesAssigneeContactPolicy($act, $dealId, $keepChatId, $opts)) {
			$candidates[] = $actId;
			continue;
		}

		$targetLead = 0;
		$cid = olLineLeadsExtractChatIdFromActivity($act);
		if ($cid > 0) {
			$targetLead = olLineLeadsGetCrmLeadForChat($cid);
		}
		olLineLeadsDetachOlActivityFromDeal($actId, $dealId, $targetLead);
		$report['detached'][] = $actId;
	}

	if ($candidates) {
		rsort($candidates, SORT_NUMERIC);
		$report['kept'][] = $candidates[0];
		foreach (array_slice($candidates, 1) as $actId) {
			$act = \CCrmActivity::GetByID($actId, false);
			$targetLead = 0;
			if (is_array($act)) {
				$cid = olLineLeadsExtractChatIdFromActivity($act);
				if ($cid > 0) {
					$targetLead = olLineLeadsGetCrmLeadForChat($cid);
				}
			}
			olLineLeadsDetachOlActivityFromDeal($actId, $dealId, $targetLead);
			$report['detached'][] = $actId;
		}
	}

	return $report;
}

/**
 * Снять deal-binding / owner с OL-активностей контактов сделки (история контакта в таймлайне).
 *
 * @return array{contactIds:int[], detached:int[]}
 */
function olLineLeadsPruneContactOlDealBindings($dealId, $keepChatId, array $opts = [])
{
	$dealId = (int)$dealId;
	$keepChatId = (int)$keepChatId;
	$report = ['contactIds' => olLineLeadsGetDealContactIds($dealId), 'detached' => []];
	if ($dealId <= 0 || $keepChatId <= 0 || !\Bitrix\Main\Loader::includeModule('crm')) {
		return $report;
	}

	$dealType = class_exists('CCrmOwnerType') ? (int)\CCrmOwnerType::Deal : 2;
	$seen = [];

	$process = static function ($actId) use ($dealId, $keepChatId, $dealType, &$report, &$seen, $opts) {
		$actId = (int)$actId;
		if ($actId <= 0 || isset($seen[$actId])) {
			return;
		}
		$seen[$actId] = true;

		$act = \CCrmActivity::GetByID($actId, false);
		if (!is_array($act) || !olLineLeadsActivityLooksLikeOpenLine($act)) {
			return;
		}
		if (olLineLeadsDealOlMatchesAssigneeContactPolicy($act, $dealId, $keepChatId, $opts)) {
			return;
		}

		$hasDealBinding = false;
		if ((int)($act['OWNER_TYPE_ID'] ?? 0) === $dealType && (int)($act['OWNER_ID'] ?? 0) === $dealId) {
			$hasDealBinding = true;
		}
		if (class_exists('\Bitrix\Crm\ActivityBindingTable')) {
			try {
				$row = \Bitrix\Crm\ActivityBindingTable::getList([
					'filter' => [
						'=ACTIVITY_ID' => $actId,
						'=OWNER_TYPE_ID' => $dealType,
						'=OWNER_ID' => $dealId,
					],
					'limit' => 1,
				])->fetch();
				if ($row) {
					$hasDealBinding = true;
				}
			} catch (\Throwable $e) {
				/* ignore */
			}
		}
		if (!$hasDealBinding) {
			return;
		}

		$targetLead = 0;
		$cid = olLineLeadsExtractChatIdFromActivity($act);
		if ($cid > 0) {
			$targetLead = olLineLeadsGetCrmLeadForChat($cid);
		}
		olLineLeadsDetachOlActivityFromDeal($actId, $dealId, $targetLead);
		$report['detached'][] = $actId;
	};

	foreach ($report['contactIds'] as $contactId) {
		$contactId = (int)$contactId;
		if ($contactId <= 0) {
			continue;
		}

		try {
			if (class_exists('\Bitrix\Crm\ActivityBindingTable')) {
				$rs = \Bitrix\Crm\ActivityBindingTable::getList([
					'filter' => [
						'=OWNER_TYPE_ID' => $contactType,
						'=OWNER_ID' => $contactId,
					],
					'select' => ['ACTIVITY_ID'],
				]);
				while ($b = $rs->fetch()) {
					$process((int)($b['ACTIVITY_ID'] ?? 0));
				}
			}
		} catch (\Throwable $e) {
			/* ignore */
		}

		try {
			$res = \CCrmActivity::GetList(
				['ID' => 'DESC'],
				[
					'OWNER_TYPE_ID' => $contactType,
					'OWNER_ID' => $contactId,
					'CHECK_PERMISSIONS' => 'N',
				],
				false,
				['nTopCount' => 500],
				['ID']
			);
			while ($row = $res->Fetch()) {
				$process((int)($row['ID'] ?? 0));
			}
		} catch (\Throwable $e) {
			/* ignore */
		}
	}

	return $report;
}

function olLineLeadsMoveDealChatActivitiesOffDeal($chatId, $dealId)
{
	$chatId = (int)$chatId;
	$dealId = (int)$dealId;
	if ($chatId <= 0 || $dealId <= 0 || !\Bitrix\Main\Loader::includeModule('crm')) {
		return 0;
	}

	$dealType = class_exists('CCrmOwnerType') ? (int)\CCrmOwnerType::Deal : 2;
	$leadType = class_exists('CCrmOwnerType') ? (int)\CCrmOwnerType::Lead : 1;
	$leadId = olLineLeadsGetCrmLeadForChat($chatId);
	$assocIds = olLineLeadsChatAssocIds($chatId);
	$moved = 0;

	try {
		$res = \CCrmActivity::GetList(
			['ID' => 'DESC'],
			[
				'OWNER_TYPE_ID' => $dealType,
				'OWNER_ID' => $dealId,
				'CHECK_PERMISSIONS' => 'N',
			],
			false,
			['nTopCount' => 200],
			['ID', 'ASSOCIATED_ENTITY_ID', 'OWNER_TYPE_ID', 'OWNER_ID', 'PROVIDER_ID', 'PROVIDER_TYPE_ID', 'SUBJECT']
		);
		while ($act = $res->Fetch()) {
			if (!olLineLeadsActivityLooksLikeOpenLine($act)) {
				continue;
			}
			$assoc = (int)($act['ASSOCIATED_ENTITY_ID'] ?? 0);
			if ($assoc <= 0 || !in_array($assoc, $assocIds, true)) {
				continue;
			}
			$actId = (int)($act['ID'] ?? 0);
			if ($actId <= 0) {
				continue;
			}

			if ($leadId > 0) {
				try {
					if (method_exists('CCrmActivity', 'ChangeOwner')) {
						\CCrmActivity::ChangeOwner($actId, $leadType, $leadId);
					} else {
						\CCrmActivity::Update($actId, [
							'OWNER_TYPE_ID' => $leadType,
							'OWNER_ID' => $leadId,
						], false, true, [
							'REGISTER_SONET_EVENT' => false,
							'SKIP_USER_FIELD_CHECK' => true,
						]);
					}
					$moved++;
				} catch (\Throwable $e) {
					olLineLeadsLog("move act {$actId} off deal: " . $e->getMessage());
				}
			} elseif (class_exists('\Bitrix\Crm\ActivityBindingTable')) {
				try {
					$rows = \Bitrix\Crm\ActivityBindingTable::getList([
						'filter' => [
							'=ACTIVITY_ID' => $actId,
							'=OWNER_TYPE_ID' => $dealType,
							'=OWNER_ID' => $dealId,
						],
					]);
					while ($b = $rows->fetch()) {
						\Bitrix\Crm\ActivityBindingTable::delete($b['ID']);
						$moved++;
					}
				} catch (\Throwable $e) {
					olLineLeadsLog("drop deal binding act {$actId}: " . $e->getMessage());
				}
			}
		}
	} catch (\Throwable $e) {
		olLineLeadsLog('move activities off deal: ' . $e->getMessage());
	}

	return $moved;
}

/**
 * Убрать с сделки лишние OL (оставить одну линию ответственного).
 *
 * @return array<string, mixed>
 */
function olLineLeadsPruneExtraDealOlChats($dealId, array $opts = [])
{
	$dealId = (int)$dealId;
	$report = [
		'dealId' => $dealId,
		'targetLineIds' => olLineLeadsResolveTargetLineIdsForDeal($dealId, $opts),
		'assigneeId' => olLineLeadsGetDealAssigneeId($dealId),
		'dealChats' => [],
		'kept' => 0,
		'removed' => [],
		'activitiesMoved' => [],
		'error' => null,
		'sessionsCleared' => [],
		'crmBindingsPurged' => [],
	];
	if ($dealId <= 0) {
		$report['error'] = 'bad_deal_id';
		return $report;
	}
	if (!\Bitrix\Main\Loader::includeModule('crm')
		|| !\Bitrix\Main\Loader::includeModule('im')
		|| !\Bitrix\Main\Loader::includeModule('imopenlines')
	) {
		$report['error'] = 'modules';
		return $report;
	}

	$phones = olLineLeadsGetDealContactPhones($dealId);
	$dealChats = olLineLeadsGetChatsForDeal($dealId);
	$report['dealChats'] = $dealChats;

	$chatIds = [];
	foreach ($dealChats as $row) {
		$cid = (int)($row['CHAT_ID'] ?? 0);
		if ($cid <= 0) {
			continue;
		}
		if ($phones && !olLineLeadsChatMatchesPhones($cid, $phones)) {
			continue;
		}
		$chatIds[] = $cid;
	}
	if (!$chatIds) {
		foreach ($dealChats as $row) {
			$cid = (int)($row['CHAT_ID'] ?? 0);
			if ($cid > 0) {
				$chatIds[] = $cid;
			}
		}
	}

	$keep = olLineLeadsPickBestChatForDeal($chatIds, $dealId, $opts);
	$report['kept'] = $keep;
	if ($keep <= 0) {
		$report['error'] = 'no_chat_to_keep';
		return $report;
	}

	foreach ($chatIds as $cid) {
		if ((int)$cid === (int)$keep) {
			continue;
		}
		olLineLeadsUnbindChatFromDeal($cid, $dealId);
		$moved = olLineLeadsMoveDealChatActivitiesOffDeal($cid, $dealId);
		$report['removed'][] = (int)$cid;
		$report['activitiesMoved'][(int)$cid] = $moved;
	}

	$report['crmBindingsPurged'] = olLineLeadsPurgeDealCrmCommonBindings($dealId, $keep);
	$report['sessionsCleared'] = olLineLeadsClearDealCrmSessionsExcept($dealId, $keep);

	$timeline = olLineLeadsPruneDealOlTimelineBindings($dealId, $keep, $opts);
	$contactTimeline = olLineLeadsPruneContactOlDealBindings($dealId, $keep, $opts);
	$timeline2 = olLineLeadsPruneDealOlTimelineBindings($dealId, $keep, $opts);
	$report['crmBindingsPurged'] = array_values(array_unique(array_merge(
		$report['crmBindingsPurged'],
		olLineLeadsPurgeDealCrmCommonBindings($dealId, $keep)
	)));
	$sessionsCleared2 = olLineLeadsClearDealCrmSessionsExcept($dealId, $keep);

	$report['timelineDetached'] = array_values(array_unique(array_merge(
		$timeline['detached'],
		$contactTimeline['detached'],
		$timeline2['detached']
	)));
	$report['timelineKept'] = array_values(array_unique(array_merge(
		$timeline['kept'],
		$timeline2['kept']
	)));
	$report['dealContactIds'] = $contactTimeline['contactIds'];
	$report['dealContactPhones'] = olLineLeadsGetDealContactPhones($dealId);
	$report['sessionsCleared'] = array_values(array_unique(array_merge(
		$report['sessionsCleared'],
		$sessionsCleared2
	)));

	olLineLeadsBindChatToDeal($keep, $dealId);
	$dealOptsKeep = array_merge($opts, ['chatId' => $keep]);
	$report['dealChatsAfter'] = olLineLeadsGetDealOlBindings($dealId);
	$report['dealChatsAfterAll'] = olLineLeadsGetChatsForDeal($dealId);
	$report['dealChatsAfterFiltered'] = olLineLeadsFilterChatsByAssigneeContact(
		$report['dealChatsAfterAll'],
		$dealId,
		$dealOptsKeep
	);

	olLineLeadsLog(
		'prune deal ' . $dealId . ' keep=' . $keep . ' removed=' . implode(',', $report['removed'])
		. ' timelineDetached=' . implode(',', $report['timelineDetached'])
		. ' sessionsCleared=' . implode(',', $report['sessionsCleared'])
		. ' crmBindingsPurged=' . implode(',', $report['crmBindingsPurged'])
	);
	return $report;
}

/**
 * @return int[]
 */
function olLineLeadsDiscoverLeadIdsForDeal($dealId)
{
	$dealId = (int)$dealId;
	$ids = [];
	if ($dealId <= 0 || !\Bitrix\Main\Loader::includeModule('crm')) {
		return $ids;
	}

	$deal = \CCrmDeal::GetByID($dealId, false);
	if (is_array($deal)) {
		$leadId = (int)($deal['LEAD_ID'] ?? 0);
		if ($leadId > 0) {
			$ids[$leadId] = $leadId;
		}
		$contactId = (int)($deal['CONTACT_ID'] ?? 0);
		if ($contactId > 0) {
			try {
				$res = \CCrmLead::GetList(
					['ID' => 'DESC'],
					['CONTACT_ID' => $contactId, 'CHECK_PERMISSIONS' => 'N'],
					false,
					['nTopCount' => 10],
					['ID']
				);
				while ($row = $res->Fetch()) {
					$lid = (int)($row['ID'] ?? 0);
					if ($lid > 0) {
						$ids[$lid] = $lid;
					}
				}
			} catch (\Throwable $e) {
				/* ignore */
			}
		}
	}

	foreach (olLineLeadsGetChatsForDeal($dealId) as $chat) {
		$lid = olLineLeadsGetCrmLeadForChat((int)$chat['CHAT_ID']);
		if ($lid > 0) {
			$ids[$lid] = $lid;
		}
	}

	return array_values($ids);
}

function olLineLeadsBindChatToDeal($chatId, $dealId)
{
	$chatId = (int)$chatId;
	$dealId = (int)$dealId;
	if ($chatId <= 0 || $dealId <= 0) {
		return false;
	}
	$dealType = class_exists('CCrmOwnerType') ? (int)\CCrmOwnerType::Deal : 2;

	try {
		if (class_exists('\Bitrix\ImOpenLines\Crm\Common') && method_exists('\Bitrix\ImOpenLines\Crm\Common', 'bind')) {
			\Bitrix\ImOpenLines\Crm\Common::bind($chatId, [
				['ENTITY_TYPE_ID' => $dealType, 'ENTITY_ID' => $dealId],
			]);
		}
	} catch (\Throwable $e) {
		olLineLeadsLog("deal chat {$chatId} crm bind: " . $e->getMessage());
	}

	try {
		$resolved = olLineLeadsResolveIds($chatId);
		$sessionId = (int)($resolved['sessionId'] ?? 0);
		$row = is_array($resolved['session']) ? $resolved['session'] : null;
		if ($sessionId > 0 && class_exists('\Bitrix\ImOpenLines\Model\SessionTable')) {
			if (!is_array($row)) {
				$row = \Bitrix\ImOpenLines\Model\SessionTable::getById($sessionId)->fetch();
			}
		}
		if (is_array($row) && !empty($row['ID'])) {
			$upd = [];
			foreach (['CRM_ENTITY_ID', 'CRM_CREATE_ID'] as $k) {
				if (array_key_exists($k, $row)) {
					$upd[$k] = $dealId;
				}
			}
			foreach (['CRM_ENTITY_TYPE', 'CRM_CREATE_ENTITY'] as $k) {
				if (array_key_exists($k, $row)) {
					$upd[$k] = 'DEAL';
				}
			}
			if ($upd) {
				\Bitrix\ImOpenLines\Model\SessionTable::update((int)$row['ID'], $upd);
			}
		}
	} catch (\Throwable $e) {
		olLineLeadsLog("deal chat {$chatId} session: " . $e->getMessage());
	}

	return true;
}

function olLineLeadsRebindChatOlToDeal($chatId, $dealId, $keepLeadId = 0)
{
	$chatId = (int)$chatId;
	$dealId = (int)$dealId;
	$keepLeadId = (int)$keepLeadId;
	if ($chatId <= 0 || $dealId <= 0 || !\Bitrix\Main\Loader::includeModule('crm')) {
		return false;
	}

	$leadType = class_exists('CCrmOwnerType') ? (int)\CCrmOwnerType::Lead : 1;
	$dealType = class_exists('CCrmOwnerType') ? (int)\CCrmOwnerType::Deal : 2;
	$acts = [];
	$assocIds = [$chatId];
	$resolved = olLineLeadsResolveIds($chatId);
	if ((int)($resolved['sessionId'] ?? 0) > 0) {
		$assocIds[] = (int)$resolved['sessionId'];
	}
	if ((int)($resolved['chatId'] ?? 0) > 0) {
		$assocIds[] = (int)$resolved['chatId'];
	}
	$assocIds = array_values(array_unique(array_filter($assocIds)));

	foreach ($assocIds as $assoc) {
		try {
			$res = \CCrmActivity::GetList(
				['ID' => 'DESC'],
				[
					'ASSOCIATED_ENTITY_ID' => $assoc,
					'CHECK_PERMISSIONS' => 'N',
				],
				false,
				['nTopCount' => 50],
				['ID', 'SUBJECT', 'ASSOCIATED_ENTITY_ID', 'OWNER_ID', 'OWNER_TYPE_ID', 'PROVIDER_ID', 'PROVIDER_TYPE_ID', 'SETTINGS']
			);
			while ($act = $res->Fetch()) {
				if (!olLineLeadsActivityLooksLikeOpenLine($act)) {
					continue;
				}
				$actId = (int)($act['ID'] ?? 0);
				if ($actId > 0) {
					$acts[$actId] = $act;
				}
			}
		} catch (\Throwable $e) {
			olLineLeadsLog('chat→deal list: ' . $e->getMessage());
		}
	}

	if (!$acts) {
		olLineLeadsLog("chat→deal {$chatId}→{$dealId}: no OL activity");
		olLineLeadsBindChatToDeal($chatId, $dealId);
		return true;
	}

	$bindings = [
		['OWNER_TYPE_ID' => $dealType, 'OWNER_ID' => $dealId],
	];
	if ($keepLeadId > 0) {
		$bindings[] = ['OWNER_TYPE_ID' => $leadType, 'OWNER_ID' => $keepLeadId];
	}

	foreach ($acts as $actId => $act) {
		try {
			\CCrmActivity::Update($actId, [
				'OWNER_TYPE_ID' => $dealType,
				'OWNER_ID' => $dealId,
				'BINDINGS' => $bindings,
			], false, true, [
				'REGISTER_SONET_EVENT' => false,
				'SKIP_USER_FIELD_CHECK' => true,
			]);
		} catch (\Throwable $e) {
			olLineLeadsLog("chat→deal act {$actId} Update: " . $e->getMessage());
		}
		try {
			if (method_exists('CCrmActivity', 'ChangeOwner')) {
				\CCrmActivity::ChangeOwner($actId, $dealType, $dealId);
			}
		} catch (\Throwable $e) {
			olLineLeadsLog("chat→deal act {$actId} ChangeOwner: " . $e->getMessage());
		}
		if (class_exists('\Bitrix\Crm\ActivityBindingTable')) {
			try {
				$exists = \Bitrix\Crm\ActivityBindingTable::getList([
					'filter' => [
						'=ACTIVITY_ID' => $actId,
						'=OWNER_TYPE_ID' => $dealType,
						'=OWNER_ID' => $dealId,
					],
					'limit' => 1,
				])->fetch();
				if (!$exists) {
					\Bitrix\Crm\ActivityBindingTable::add([
						'ACTIVITY_ID' => $actId,
						'OWNER_TYPE_ID' => $dealType,
						'OWNER_ID' => $dealId,
					]);
				}
			} catch (\Throwable $e) {
				olLineLeadsLog("chat→deal act {$actId} bindDeal: " . $e->getMessage());
			}
		}
	}

	olLineLeadsBindChatToDeal($chatId, $dealId);
	olLineLeadsLog(
		"chat→deal {$chatId}→{$dealId} acts=" . implode(',', array_keys($acts))
	);
	return true;
}

/**
 * Rebind OL на сделку без LEAD_ID: по телефону контакта или ?chatId=.
 *
 * @return array<string, mixed>
 */
function olLineLeadsRebindOlToDeal($dealId, array $opts = [])
{
	$dealId = (int)$dealId;
	$report = [
		'dealId' => $dealId,
		'phones' => [],
		'discoveredLeads' => [],
		'dealChats' => [],
		'matchedChats' => [],
		'actions' => [],
		'error' => null,
	];
	if ($dealId <= 0) {
		$report['error'] = 'bad_deal_id';
		return $report;
	}
	if (!\Bitrix\Main\Loader::includeModule('crm')
		|| !\Bitrix\Main\Loader::includeModule('im')
		|| !\Bitrix\Main\Loader::includeModule('imopenlines')
	) {
		$report['error'] = 'modules';
		return $report;
	}

	$phones = olLineLeadsGetDealContactPhones($dealId);
	$report['phones'] = $phones;
	$report['dealChats'] = olLineLeadsGetChatsForDeal($dealId);
	$report['discoveredLeads'] = olLineLeadsDiscoverLeadIdsForDeal($dealId);

	$forceChatId = (int)($opts['chatId'] ?? 0);
	$hintLeadId = (int)($opts['leadId'] ?? 0);
	$matched = [];

	if ($forceChatId > 0) {
		if ($phones && !olLineLeadsChatMatchesPhones($forceChatId, $phones)) {
			$report['error'] = 'chat_phone_mismatch';
			$report['chatPhoneTails'] = olLineLeadsGetChatPhoneTails($forceChatId);
			return $report;
		}
		$matched[] = $forceChatId;
	} else {
		$matched = olLineLeadsFindChatsByPhones($phones);
	}

	$targetLineIds = olLineLeadsResolveTargetLineIdsForDeal($dealId, $opts);
	$report['targetLineIds'] = $targetLineIds;
	$report['assigneeId'] = olLineLeadsGetDealAssigneeId($dealId);
	$report['allMatchedChats'] = $matched;

	if ($matched) {
		$best = olLineLeadsPickBestChatForDeal($matched, $dealId, $opts);
		if ($best > 0) {
			$matched = [$best];
		} else {
			$matched = [reset($matched)];
		}
		$skipped = array_values(array_diff($report['allMatchedChats'], $matched));
		if ($skipped) {
			$report['skippedChats'] = $skipped;
		}
	}

	$report['matchedChats'] = $matched;
	if (!$matched) {
		$report['error'] = 'no_chat_for_contact_phone';
		$report['hint'] = 'Укажите ?chatId= если чат известен, или ?leadId= для переноса с лида';
		return $report;
	}

	foreach ($matched as $chatId) {
		$done = false;
		if ($hintLeadId > 0) {
			$done = olLineLeadsRebindLeadOlToDeal($hintLeadId, $dealId, $opts);
			if ($done) {
				$report['actions'][] = "lead #{$hintLeadId} → deal #{$dealId}";
			}
		}
		if (!$done) {
			$keepLead = 0;
			foreach ($report['discoveredLeads'] as $leadId) {
				if ((int)$leadId === $hintLeadId) {
					$keepLead = (int)$leadId;
					break;
				}
			}
			olLineLeadsRebindChatOlToDeal($chatId, $dealId, $keepLead);
			$report['actions'][] = "chat #{$chatId} → deal #{$dealId}";
		} else {
			olLineLeadsBindChatToDeal($chatId, $dealId);
		}
	}

	return $report;
}

/**
 * Перенос «Чат с клиентом» с лида на сделку (OWNER + BINDINGS + OL CRM).
 */
function olLineLeadsRebindLeadOlToDeal($leadId, $dealId, array $opts = [])
{
	$leadId = (int)$leadId;
	$dealId = (int)$dealId;
	if ($leadId <= 0 || $dealId <= 0) {
		return false;
	}
	if (!\Bitrix\Main\Loader::includeModule('crm')) {
		return false;
	}
	$leadType = class_exists('CCrmOwnerType') ? (int)\CCrmOwnerType::Lead : 1;
	$dealType = class_exists('CCrmOwnerType') ? (int)\CCrmOwnerType::Deal : 2;

	$acts = [];
	$chatIds = [];

	$collect = static function ($act) use (&$acts, &$chatIds) {
		if (!is_array($act) || !olLineLeadsActivityLooksLikeOpenLine($act)) {
			return;
		}
		$id = (int)($act['ID'] ?? 0);
		if ($id <= 0 || isset($acts[$id])) {
			return;
		}
		$acts[$id] = $act;
		$cid = olLineLeadsExtractChatIdFromActivity($act);
		if ($cid > 0) {
			$chatIds[$cid] = $cid;
		}
	};

	try {
		$res = \CCrmActivity::GetList(
			['ID' => 'DESC'],
			[
				'OWNER_TYPE_ID' => $leadType,
				'OWNER_ID' => $leadId,
				'CHECK_PERMISSIONS' => 'N',
			],
			false,
			['nTopCount' => 100],
			['ID', 'SUBJECT', 'ASSOCIATED_ENTITY_ID', 'OWNER_ID', 'OWNER_TYPE_ID', 'PROVIDER_ID', 'PROVIDER_TYPE_ID', 'SETTINGS']
		);
		while ($act = $res->Fetch()) {
			$collect($act);
		}
	} catch (\Throwable $e) {
		olLineLeadsLog('lead→deal list: ' . $e->getMessage());
	}

	if (class_exists('\Bitrix\Crm\ActivityBindingTable')) {
		try {
			$rs = \Bitrix\Crm\ActivityBindingTable::getList([
				'filter' => [
					'=OWNER_TYPE_ID' => $leadType,
					'=OWNER_ID' => $leadId,
				],
				'select' => ['ACTIVITY_ID'],
				'limit' => 100,
			]);
			while ($b = $rs->fetch()) {
				$aid = (int)($b['ACTIVITY_ID'] ?? 0);
				if ($aid <= 0 || isset($acts[$aid])) {
					continue;
				}
				$act = \CCrmActivity::GetByID($aid, false);
				$collect($act);
			}
		} catch (\Throwable $e) {
			olLineLeadsLog('lead→deal bindings: ' . $e->getMessage());
		}
	}

	if (!$acts) {
		olLineLeadsLog("lead→deal {$leadId}→{$dealId}: no OL activity");
		return false;
	}

	$bestChatId = olLineLeadsPickBestChatForDeal(array_values($chatIds), $dealId, $opts);
	if ($bestChatId > 0) {
		$bestAssoc = olLineLeadsChatAssocIds($bestChatId);
		$filteredActs = [];
		foreach ($acts as $actId => $act) {
			$assoc = (int)($act['ASSOCIATED_ENTITY_ID'] ?? 0);
			$cid = olLineLeadsExtractChatIdFromActivity($act);
			if ($cid === $bestChatId || ($assoc > 0 && in_array($assoc, $bestAssoc, true))) {
				$filteredActs[$actId] = $act;
			}
		}
		if ($filteredActs) {
			$acts = $filteredActs;
			$chatIds = [$bestChatId => $bestChatId];
			olLineLeadsLog("lead→deal {$leadId}→{$dealId}: pick chat {$bestChatId} lines=" . implode(',', olLineLeadsResolveTargetLineIdsForDeal($dealId, $opts)));
		}
	}

	foreach ($acts as $actId => $act) {
		try {
			\CCrmActivity::Update($actId, [
				'OWNER_TYPE_ID' => $dealType,
				'OWNER_ID' => $dealId,
				'BINDINGS' => [
					['OWNER_TYPE_ID' => $dealType, 'OWNER_ID' => $dealId],
					['OWNER_TYPE_ID' => $leadType, 'OWNER_ID' => $leadId],
				],
			], false, true, [
				'REGISTER_SONET_EVENT' => false,
				'SKIP_USER_FIELD_CHECK' => true,
			]);
		} catch (\Throwable $e) {
			olLineLeadsLog("lead→deal act {$actId} Update: " . $e->getMessage());
		}
		try {
			if (method_exists('CCrmActivity', 'ChangeOwner')) {
				\CCrmActivity::ChangeOwner($actId, $dealType, $dealId);
			}
		} catch (\Throwable $e) {
			olLineLeadsLog("lead→deal act {$actId} ChangeOwner: " . $e->getMessage());
		}
		if (class_exists('\Bitrix\Crm\ActivityBindingTable')) {
			try {
				$exists = \Bitrix\Crm\ActivityBindingTable::getList([
					'filter' => [
						'=ACTIVITY_ID' => $actId,
						'=OWNER_TYPE_ID' => $dealType,
						'=OWNER_ID' => $dealId,
					],
					'limit' => 1,
				])->fetch();
				if (!$exists) {
					\Bitrix\Crm\ActivityBindingTable::add([
						'ACTIVITY_ID' => $actId,
						'OWNER_TYPE_ID' => $dealType,
						'OWNER_ID' => $dealId,
					]);
				}
			} catch (\Throwable $e) {
				olLineLeadsLog("lead→deal act {$actId} bindDeal: " . $e->getMessage());
			}
		}
	}

	foreach ($chatIds as $chatId) {
		olLineLeadsBindChatToDeal($chatId, $dealId);
	}

	olLineLeadsLog(
		"lead→deal {$leadId}→{$dealId} acts=" . implode(',', array_keys($acts))
		. ' chats=' . implode(',', $chatIds)
	);
	return true;
}

/**
 * Привязать OL-чат к таймлайну лида (OWNER + binding + Crm\Common).
 *
 * @return array<string, mixed>
 */
function olLineLeadsAttachChatToLeadTimeline($leadId, $chatId = 0)
{
	$leadId = (int)$leadId;
	$chatId = (int)$chatId;
	$report = [
		'leadId' => $leadId,
		'chatId' => 0,
		'activityId' => 0,
		'created' => false,
		'bound' => false,
		'error' => null,
		'chats' => [],
	];
	if ($leadId <= 0) {
		$report['error'] = 'bad_lead_id';
		return $report;
	}
	if (!\Bitrix\Main\Loader::includeModule('crm')
		|| !\Bitrix\Main\Loader::includeModule('im')
		|| !\Bitrix\Main\Loader::includeModule('imopenlines')
	) {
		$report['error'] = 'modules';
		return $report;
	}

	$leadType = class_exists('CCrmOwnerType') ? (int)\CCrmOwnerType::Lead : 1;
	$chats = olLineLeadsGetChatsForLead($leadId);
	$report['chats'] = $chats;

	if ($chatId <= 0 && $chats) {
		$chatId = (int)$chats[0]['CHAT_ID'];
	}
	if ($chatId <= 0) {
		$phones = [];
		$lead = \CCrmLead::GetByID($leadId, false);
		$contactId = is_array($lead) ? (int)($lead['CONTACT_ID'] ?? 0) : 0;
		if ($contactId > 0) {
			try {
				$multi = \CCrmFieldMulti::GetList(
					['ID' => 'ASC'],
					['ENTITY_ID' => 'CONTACT', 'ELEMENT_ID' => $contactId, 'TYPE_ID' => 'PHONE']
				);
				while ($row = $multi->Fetch()) {
					$tail = olLineLeadsPhoneTail($row['VALUE'] ?? '');
					if ($tail !== '') {
						$phones[$tail] = $tail;
					}
				}
			} catch (\Throwable $e) {
				/* ignore */
			}
		}
		try {
			$multi = \CCrmFieldMulti::GetList(
				['ID' => 'ASC'],
				['ENTITY_ID' => 'LEAD', 'ELEMENT_ID' => $leadId, 'TYPE_ID' => 'PHONE']
			);
			while ($row = $multi->Fetch()) {
				$tail = olLineLeadsPhoneTail($row['VALUE'] ?? '');
				if ($tail !== '') {
					$phones[$tail] = $tail;
				}
			}
		} catch (\Throwable $e) {
			/* ignore */
		}
		if ($phones) {
			$found = olLineLeadsFindChatsByPhones(array_values($phones));
			if ($found) {
				$chatId = (int)$found[0];
			}
		}
	}

	$report['chatId'] = $chatId;
	if ($chatId <= 0) {
		$report['error'] = 'no_chat';
		return $report;
	}

	$realChatId = olLineLeadsResolveRealChatId($chatId) ?: $chatId;
	$report['chatId'] = $realChatId;
	$assocIds = olLineLeadsChatAssocIds($realChatId);
	$actId = 0;

	try {
		foreach ($assocIds as $assoc) {
			$res = \CCrmActivity::GetList(
				['ID' => 'DESC'],
				['ASSOCIATED_ENTITY_ID' => $assoc, 'CHECK_PERMISSIONS' => 'N'],
				false,
				['nTopCount' => 20],
				['ID', 'OWNER_TYPE_ID', 'OWNER_ID', 'PROVIDER_ID', 'PROVIDER_TYPE_ID', 'SUBJECT', 'ASSOCIATED_ENTITY_ID']
			);
			while ($act = $res->Fetch()) {
				if (!olLineLeadsActivityLooksLikeOpenLine($act)) {
					continue;
				}
				$actId = (int)$act['ID'];
				break 2;
			}
		}
	} catch (\Throwable $e) {
		olLineLeadsLog('attach timeline list: ' . $e->getMessage());
	}

	if ($actId <= 0) {
		$resolved = olLineLeadsResolveIds($realChatId);
		$sessionId = (int)($resolved['sessionId'] ?? 0);
		$assoc = $sessionId > 0 ? $sessionId : $realChatId;
		$subject = 'Чат открытой линии';
		$meta = olLineLeadsGetChatMeta($realChatId, $leadId);
		if (!empty($meta['debug']['TITLE'])) {
			$subject = 'Чат открытой линии - "' . $meta['debug']['TITLE'] . '"';
		} elseif (!empty($meta['debug']['USER_CODE'])) {
			$subject = 'Чат открытой линии - "' . $meta['debug']['USER_CODE'] . '"';
		}

		$fields = [
			'TYPE_ID' => 6,
			'PROVIDER_ID' => 'IMOPENLINES_SESSION',
			'PROVIDER_TYPE_ID' => 'SESSION',
			'SUBJECT' => $subject,
			'COMPLETED' => 'Y',
			'RESPONSIBLE_ID' => (int)($GLOBALS['USER'] ? $GLOBALS['USER']->GetID() : 1),
			'AUTHOR_ID' => (int)($GLOBALS['USER'] ? $GLOBALS['USER']->GetID() : 1),
			'OWNER_TYPE_ID' => $leadType,
			'OWNER_ID' => $leadId,
			'ASSOCIATED_ENTITY_ID' => $assoc,
			'DESCRIPTION' => 'WA custom attach lead #' . $leadId . ' chat #' . $realChatId,
			'DESCRIPTION_TYPE' => 1,
			'DIRECTION' => 1,
			'BINDINGS' => [
				['OWNER_TYPE_ID' => $leadType, 'OWNER_ID' => $leadId],
			],
		];
		try {
			$actId = (int)\CCrmActivity::Add($fields, false, true, [
				'REGISTER_SONET_EVENT' => true,
				'SKIP_USER_FIELD_CHECK' => true,
			]);
			$report['created'] = $actId > 0;
		} catch (\Throwable $e) {
			$report['error'] = 'activity_add: ' . $e->getMessage();
			olLineLeadsLog($report['error']);
			return $report;
		}
		if ($actId <= 0) {
			global $APPLICATION;
			$ex = is_object($APPLICATION) ? $APPLICATION->GetException() : null;
			$report['error'] = 'activity_add_failed' . ($ex ? (': ' . $ex->GetString()) : '');
			return $report;
		}
	}

	$report['activityId'] = $actId;

	try {
		if (method_exists('CCrmActivity', 'ChangeOwner')) {
			\CCrmActivity::ChangeOwner($actId, $leadType, $leadId);
		} else {
			\CCrmActivity::Update($actId, [
				'OWNER_TYPE_ID' => $leadType,
				'OWNER_ID' => $leadId,
			], false, true, [
				'REGISTER_SONET_EVENT' => false,
				'SKIP_USER_FIELD_CHECK' => true,
			]);
		}
	} catch (\Throwable $e) {
		olLineLeadsLog("attach timeline owner: " . $e->getMessage());
	}

	if (class_exists('\Bitrix\Crm\ActivityBindingTable')) {
		try {
			$exists = \Bitrix\Crm\ActivityBindingTable::getList([
				'filter' => [
					'=ACTIVITY_ID' => $actId,
					'=OWNER_TYPE_ID' => $leadType,
					'=OWNER_ID' => $leadId,
				],
				'limit' => 1,
			])->fetch();
			if (!$exists) {
				\Bitrix\Crm\ActivityBindingTable::add([
					'ACTIVITY_ID' => $actId,
					'OWNER_TYPE_ID' => $leadType,
					'OWNER_ID' => $leadId,
				]);
			}
			$report['bound'] = true;
		} catch (\Throwable $e) {
			olLineLeadsLog('attach timeline bind: ' . $e->getMessage());
		}
	}

	try {
		if (class_exists('\Bitrix\ImOpenLines\Crm\Common') && method_exists('\Bitrix\ImOpenLines\Crm\Common', 'bind')) {
			\Bitrix\ImOpenLines\Crm\Common::bind($realChatId, [
				['ENTITY_TYPE_ID' => $leadType, 'ENTITY_ID' => $leadId],
			]);
		}
	} catch (\Throwable $e) {
		olLineLeadsLog('attach timeline crm bind: ' . $e->getMessage());
	}

	try {
		$resolved = olLineLeadsResolveIds($realChatId);
		$row = is_array($resolved['session'] ?? null) ? $resolved['session'] : null;
		if (is_array($row) && !empty($row['ID']) && class_exists('\Bitrix\ImOpenLines\Model\SessionTable')) {
			$upd = [];
			foreach (['CRM_ENTITY_ID', 'CRM_CREATE_ID'] as $k) {
				if (array_key_exists($k, $row)) {
					$upd[$k] = $leadId;
				}
			}
			foreach (['CRM_ENTITY_TYPE', 'CRM_CREATE_ENTITY'] as $k) {
				if (array_key_exists($k, $row)) {
					$upd[$k] = 'LEAD';
				}
			}
			if ($upd) {
				\Bitrix\ImOpenLines\Model\SessionTable::update((int)$row['ID'], $upd);
			}
		}
	} catch (\Throwable $e) {
		/* ignore */
	}

	olLineLeadsLog("attach timeline lead={$leadId} chat={$realChatId} act={$actId}");
	return $report;
}

function olLineLeadsLog($msg)
{
	if (function_exists('AddMessage2Log')) {
		AddMessage2Log((string)$msg, 'ol_line_leads');
	}
}

/**
 * Ручной прогон по лиду (для отладки / починки уже склеенных).
 * @return array
 */
function olLineLeadsRepairLead($leadId)
{
	$leadId = (int)$leadId;
	$report = ['leadId' => $leadId, 'chats' => [], 'actions' => []];
	if ($leadId <= 0) {
		return $report;
	}
	if (!\Bitrix\Main\Loader::includeModule('crm')
		|| !\Bitrix\Main\Loader::includeModule('im')
		|| !\Bitrix\Main\Loader::includeModule('imopenlines')
	) {
		$report['error'] = 'modules';
		return $report;
	}

	$chats = olLineLeadsGetChatsForLead($leadId);
	$report['chats'] = $chats;

	// Группируем по identity key (L:/H:/E:/U:/C:), не только по numeric CONFIG_ID
	$byKey = [];
	foreach ($chats as $c) {
		$key = (string)($c['KEY'] ?? '');
		if ($key === '') {
			$meta = olLineLeadsGetChatMeta((int)$c['CHAT_ID'], $leadId);
			$key = (string)$meta['key'];
			if ($key === '' && (int)$meta['line_id'] > 0) {
				$key = 'L:' . (int)$meta['line_id'];
			}
			$c['KEY'] = $key;
			$c['LINE_ID'] = (int)$meta['line_id'];
			$c['DEBUG'] = $meta['debug'];
		}
		if ($key === '') {
			// ручной repair: 2+ OL-чата на одном лиде без meta → всё равно разлепить
			$key = 'C:' . (int)$c['CHAT_ID'];
			$c['KEY'] = $key;
		}
		$byKey[$key][] = $c;
	}
	$report['groups'] = array_map(static function ($list) {
		return array_map(static function ($c) {
			return [
				'CHAT_ID' => (int)$c['CHAT_ID'],
				'LINE_ID' => (int)$c['LINE_ID'],
				'KEY' => (string)($c['KEY'] ?? ''),
			];
		}, $list);
	}, $byKey);

	if (count($byKey) < 2) {
		$report['actions'][] = 'no conflict (need >=2 different line identities)';
		return $report;
	}

	// Оставляем группу с наибольшим chatId, остальные — split
	$keepKey = null;
	$maxChat = -1;
	foreach ($byKey as $k => $list) {
		foreach ($list as $c) {
			$cid = (int)$c['CHAT_ID'];
			if ($cid > $maxChat) {
				$maxChat = $cid;
				$keepKey = $k;
			}
		}
	}

	foreach ($byKey as $key => $list) {
		if ($key === $keepKey) {
			continue;
		}
		foreach ($list as $c) {
			$chatId = (int)$c['CHAT_ID'];
			olLineLeadsProcessSplit($leadId, $chatId, 0, true);
			$report['actions'][] = "split chat {$chatId} key {$key}";
		}
	}

	if (!$report['actions']) {
		$report['actions'][] = 'conflict detected but split produced no actions (check logs)';
	}

	return $report;
}
