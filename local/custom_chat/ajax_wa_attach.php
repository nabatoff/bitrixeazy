<?php
/**
 * Привязать созданный исходящий OL-чат к открытому лиду/сделке.
 */
define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_CHECK', true);
define('DisableEventsCheck', true);
define('NOT_CHECK_PERMISSIONS', false);
define('BX_SECURITY_SHOW_MESSAGE', false);

if (empty($GLOBALS['WA_CC_MEDIA_AUTHED'])) {
	require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
}
global $USER;
$userId = !empty($GLOBALS['WA_CC_FORCED_USER_ID'])
	? (int)$GLOBALS['WA_CC_FORCED_USER_ID']
	: (($USER && $USER->IsAuthorized()) ? (int)$USER->GetID() : 0);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($userId <= 0) {
	http_response_code(401);
	echo json_encode(['ok' => false, 'error' => 'auth']);
	exit;
}

$chatId = (int)($_POST['chatId'] ?? $_REQUEST['chatId'] ?? 0);
$entityId = (int)($_POST['entityId'] ?? $_REQUEST['entityId'] ?? 0);
$entityType = strtolower(trim((string)($_POST['entityType'] ?? $_REQUEST['entityType'] ?? '')));
if ($chatId <= 0 || $entityId <= 0 || !in_array($entityType, ['lead', 'deal'], true)) {
	http_response_code(400);
	echo json_encode(['ok' => false, 'error' => 'params']);
	exit;
}

try {
	if (!\Bitrix\Main\Loader::includeModule('crm')) {
		throw new \RuntimeException('crm');
	}
	$allowed = false;
	if ($entityType === 'lead') {
		$rs = \CCrmLead::GetListEx([], ['=ID' => $entityId, 'CHECK_PERMISSIONS' => 'Y'], false, ['nTopCount' => 1], ['ID']);
		$allowed = (bool)$rs->Fetch();
	} else {
		$rs = \CCrmDeal::GetListEx([], ['=ID' => $entityId, 'CHECK_PERMISSIONS' => 'Y'], false, ['nTopCount' => 1], ['ID']);
		$allowed = (bool)$rs->Fetch();
	}
	if (!$allowed) {
		http_response_code(403);
		echo json_encode(['ok' => false, 'error' => 'denied']);
		exit;
	}

	require_once __DIR__ . '/include_ol_line_leads.php';
	$report = $entityType === 'lead'
		? olLineLeadsAttachChatToLeadTimeline($entityId, $chatId)
		: olLineLeadsAttachChatToDealTimeline($entityId, $chatId);
	echo json_encode(['ok' => empty($report['error']), 'report' => $report], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
	http_response_code(500);
	echo json_encode(['ok' => false, 'error' => 'attach']);
}
exit;
