<?php
/**
 * Ручной прогон split лидов (под админом).
 * Пример: /local/custom_chat/ol_line_leads_run.php?leadId=331574
 * Debug:  /local/custom_chat/ol_line_leads_run.php?leadId=331574&debug=1
 */
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

global $USER;
header('Content-Type: text/plain; charset=utf-8');

if (!$USER || !$USER->IsAdmin()) {
	http_response_code(403);
	echo "Admin only\n";
	die();
}

$include = $_SERVER['DOCUMENT_ROOT'] . '/local/custom_chat/include_ol_line_leads.php';
if (!is_file($include)) {
	echo "Missing include_ol_line_leads.php\n";
	die();
}
require_once $include;

$leadId = (int)($_GET['leadId'] ?? 0);
$dealId = (int)($_GET['dealId'] ?? 0);
$forceChatId = (int)($_GET['chatId'] ?? 0);
$forceLineId = (int)($_GET['lineId'] ?? 0);
$prune = !empty($_GET['prune']);
$timeline = !empty($_GET['timeline']);
$dealOpts = [
	'chatId' => $forceChatId,
	'leadId' => $leadId,
	'lineId' => $forceLineId,
];

if ($leadId > 0 && $timeline) {
	\Bitrix\Main\Loader::includeModule('crm');
	\Bitrix\Main\Loader::includeModule('im');
	\Bitrix\Main\Loader::includeModule('imopenlines');
	echo "Attach OL chat → lead #{$leadId} timeline\n";
	$report = olLineLeadsAttachChatToLeadTimeline($leadId, $forceChatId);
	echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
	echo "Check AddMessage2Log tag: ol_line_leads\n";
	require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_after.php';
	die();
}

if ($dealId > 0) {
	\Bitrix\Main\Loader::includeModule('crm');
	\Bitrix\Main\Loader::includeModule('im');
	\Bitrix\Main\Loader::includeModule('imopenlines');
	$deal = \CCrmDeal::GetByID($dealId, false);
	$fromLead = (int)($deal['LEAD_ID'] ?? 0);
	if ($fromLead <= 0) {
		$fromLead = $leadId;
	}

	if ($prune) {
		echo "Prune extra OL on deal #{$dealId}\n";
		$report = olLineLeadsPruneExtraDealOlChats($dealId, $dealOpts);
		echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
	} elseif ($fromLead > 0) {
		echo "Rebind OL lead #{$fromLead} → deal #{$dealId}\n";
		$ok = olLineLeadsRebindLeadOlToDeal($fromLead, $dealId, $dealOpts);
		echo $ok ? "ok\n" : "no OL activity on lead\n";
	} else {
		echo "Rebind OL → deal #{$dealId} (no LEAD_ID; by contact phone";
		if ($forceChatId > 0) {
			echo ", chatId={$forceChatId}";
		}
		if ($forceLineId > 0) {
			echo ", lineId={$forceLineId}";
		}
		echo ")\n";
		$report = olLineLeadsRebindOlToDeal($dealId, $dealOpts);
		echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
	}
	echo "Check AddMessage2Log tag: ol_line_leads\n";
	require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_after.php';
	die();
}

if ($leadId <= 0) {
	echo "Usage:\n";
	echo "  ?leadId=123[&debug=1][&fixBind=1]\n";
	echo "  ?leadId=332315&timeline=1 — привязать OL-чат к таймлайну лида\n";
	echo "  ?leadId=332315&timeline=1&chatId=328515 — явный chatId\n";
	echo "  ?dealId=215072   — перенести чат ОЛ на сделку (LEAD_ID или телефон контакта)\n";
	echo "  ?dealId=215118&chatId=123456 — явный chatId (проверка телефона)\n";
	echo "  ?dealId=215118&prune=1 — убрать лишние OL, оставить линию ответственного\n";
	echo "  ?dealId=215118&lineId=49 — явная линия ОЛ\n";
	die();
}

$debug = !empty($_GET['debug']);
$fixBind = !empty($_GET['fixBind']);

echo "Repair lead #{$leadId}\n";
echo "========================\n";

\Bitrix\Main\Loader::includeModule('crm');
\Bitrix\Main\Loader::includeModule('im');
\Bitrix\Main\Loader::includeModule('imopenlines');

// Добить bindings, если OWNER уже на другом лиде, а таймлайн старого ещё показывает чат
if ($fixBind) {
	$chatId = $forceChatId > 0 ? $forceChatId : 0;
	if ($chatId <= 0) {
		// Мария / второй чат: ищем binding OL на этом лиде с линией != keep
		$chats = olLineLeadsGetChatsForLead($leadId);
		foreach ($chats as $c) {
			if ((int)$c['LINE_ID'] === 49 || (string)($c['KEY'] ?? '') === 'L:49') {
				$chatId = (int)$c['CHAT_ID'];
				break;
			}
		}
		if ($chatId <= 0 && count($chats) >= 2) {
			// не keep (max chat)
			$max = 0;
			$keep = 0;
			foreach ($chats as $c) {
				if ((int)$c['CHAT_ID'] > $max) {
					$max = (int)$c['CHAT_ID'];
					$keep = $max;
				}
			}
			foreach ($chats as $c) {
				if ((int)$c['CHAT_ID'] !== $keep) {
					$chatId = (int)$c['CHAT_ID'];
					break;
				}
			}
		}
	}
	if ($chatId <= 0) {
		// явный fallback из прошлого прогона
		$chatId = 328613;
	}
	$target = olLineLeadsGetCrmLeadForChat($chatId);
	echo "fixBind chat={$chatId} targetLead=" . (int)$target . "\n";
	if ($target > 0 && $target !== $leadId) {
		olLineLeadsRebindChatToLead($chatId, $leadId, $target, 0);
		echo "rebind done: lead {$leadId} → {$target}\n";
	} else {
		olLineLeadsProcessSplit($leadId, $chatId, 0, true);
		$target2 = olLineLeadsGetCrmLeadForChat($chatId);
		echo "processSplit done, now on lead " . (int)$target2 . "\n";
	}
	echo "------------------------\n";
}

if ($debug) {
	echo "DEBUG resolve + meta:\n";
	$chats = olLineLeadsGetChatsForLead($leadId);
	foreach ($chats as $c) {
		$rid = (int)($c['RAW_ID'] ?? $c['CHAT_ID']);
		$resolved = olLineLeadsResolveIds($rid);
		$meta = olLineLeadsGetChatMeta($rid, $leadId);
		echo "--- raw={$rid} ---\n";
		echo "resolve: " . json_encode([
			'chatId' => $resolved['chatId'],
			'sessionId' => $resolved['sessionId'],
			'hasSession' => is_array($resolved['session']),
			'hasChat' => is_array($resolved['chat']),
			'errors' => $resolved['errors'],
			'sessionKeys' => is_array($resolved['session']) ? array_keys($resolved['session']) : [],
			'chatTitle' => is_array($resolved['chat']) ? ($resolved['chat']['TITLE'] ?? null) : null,
			'chatEntity' => is_array($resolved['chat']) ? ($resolved['chat']['ENTITY_ID'] ?? null) : null,
			'config' => is_array($resolved['session']) ? ($resolved['session']['CONFIG_ID'] ?? null) : null,
			'userCode' => is_array($resolved['session']) ? ($resolved['session']['USER_CODE'] ?? null) : null,
		], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
		echo "meta: " . json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
	}

	echo "DEBUG lead activities (OL-like):\n";
	$leadType = class_exists('CCrmOwnerType') ? (int)\CCrmOwnerType::Lead : 1;
	$res = \CCrmActivity::GetList(
		['ID' => 'DESC'],
		[
			'OWNER_TYPE_ID' => $leadType,
			'OWNER_ID' => $leadId,
			'CHECK_PERMISSIONS' => 'N',
		],
		false,
		['nTopCount' => 40],
		['ID', 'SUBJECT', 'ASSOCIATED_ENTITY_ID', 'PROVIDER_ID', 'PROVIDER_TYPE_ID']
	);
	while ($act = $res->Fetch()) {
		if (!olLineLeadsActivityLooksLikeOpenLine($act) && (int)($act['ASSOCIATED_ENTITY_ID'] ?? 0) <= 0) {
			continue;
		}
		echo json_encode([
			'ID' => (int)$act['ID'],
			'ASSOC' => (int)($act['ASSOCIATED_ENTITY_ID'] ?? 0),
			'PROVIDER' => (string)($act['PROVIDER_ID'] ?? ''),
			'TYPE' => (string)($act['PROVIDER_TYPE_ID'] ?? ''),
			'SUBJECT' => (string)($act['SUBJECT'] ?? ''),
			'extracted' => olLineLeadsExtractChatIdFromActivity($act),
		], JSON_UNESCAPED_UNICODE) . "\n";
	}
	echo "------------------------\n";
}

$report = olLineLeadsRepairLead($leadId);
echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
echo "\nCheck AddMessage2Log tag: ol_line_leads\n";

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_after.php';
