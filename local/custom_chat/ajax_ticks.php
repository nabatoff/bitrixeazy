<?php
/**
 * Лёгкий endpoint галочек WhatsApp. Без парсинга index.php (~8500 строк).
 */
define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_CHECK', true);
define('DisableEventsCheck', true);
define('NOT_CHECK_PERMISSIONS', true);
define('BX_SECURITY_SHOW_MESSAGE', false);

if (empty($GLOBALS['WA_CC_MEDIA_AUTHED'])) {
	require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
	global $USER;
	if (!$USER || !$USER->IsAuthorized()) {
		http_response_code(401);
		header('Content-Type: application/json; charset=utf-8');
		echo json_encode(['ok' => false, 'error' => 'auth']);
		exit;
	}
}

require_once __DIR__ . '/app/wa_ticks.php';

$rawKeys = (string)($_GET['keys'] ?? $_GET['wa_ticks'] ?? '');
$keys = array_values(array_filter(array_map('trim', explode(',', $rawKeys))));
$lineId = (int)($_GET['line'] ?? $_GET['lineId'] ?? 0);
$row = function_exists('waCcTicksBestForKeysWithPoll')
	? waCcTicksBestForKeysWithPoll($keys, $lineId, !empty($_GET['force']) || !empty($_GET['fresh']))
	: waCcTicksBestForKeys($keys);
$row = is_array($row) ? $row : [];

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode([
	'ok' => true,
	'status' => $row['status'] ?? '',
	'ts' => (int)($row['ts'] ?? 0),
	'readTs' => (int)($row['readTs'] ?? 0),
	'idMessage' => $row['idMessage'] ?? '',
], JSON_UNESCAPED_UNICODE);
exit;
