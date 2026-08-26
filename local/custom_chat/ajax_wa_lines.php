<?php
/**
 * Доступные текущему оператору WhatsApp-линии для нового исходящего диалога.
 */
define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_CHECK', true);
define('DisableEventsCheck', true);
define('NOT_CHECK_PERMISSIONS', true);
define('BX_SECURITY_SHOW_MESSAGE', false);

if (empty($GLOBALS['WA_CC_MEDIA_AUTHED'])) {
	require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
}

global $USER;
$userId = !empty($GLOBALS['WA_CC_FORCED_USER_ID'])
	? (int)$GLOBALS['WA_CC_FORCED_USER_ID']
	: (($USER && $USER->IsAuthorized()) ? (int)$USER->GetID() : 0);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=60');
if ($userId <= 0) {
	http_response_code(401);
	echo json_encode(['ok' => false, 'error' => 'auth']);
	exit;
}

try {
	\Bitrix\Main\Loader::includeModule('imopenlines');
	$conn = \Bitrix\Main\Application::getConnection();
	$isAdmin = $USER && is_object($USER) && $USER->IsAdmin();

	$allowed = [];
	if (class_exists('\Bitrix\ImOpenLines\Model\QueueTable')) {
		$rs = \Bitrix\ImOpenLines\Model\QueueTable::getList([
			'filter' => ['=USER_ID' => $userId],
			'select' => ['CONFIG_ID'],
		]);
		while ($row = $rs->fetch()) {
			$id = (int)($row['CONFIG_ID'] ?? 0);
			if ($id > 0) {
				$allowed[$id] = true;
			}
		}
	}

	$where = $isAdmin
		? ''
		: ($allowed ? ' AND s.LINE IN (' . implode(',', array_map('intval', array_keys($allowed))) . ')' : ' AND 1=0');
	$res = $conn->query("
		SELECT s.LINE, s.CONNECTOR, s.DATA, c.LINE_NAME
		FROM b_imconnectors_status s
		INNER JOIN b_imopenlines_config c ON c.ID = s.LINE
		WHERE s.ACTIVE = 'Y'
			AND s.CONNECTION = 'Y'
			AND s.REGISTER = 'Y'
			AND s.CONNECTOR LIKE 'fos_green%'
			AND c.ACTIVE = 'Y'
			{$where}
		ORDER BY c.LINE_NAME, s.LINE
	");

	$lines = [];
	while ($row = $res->fetch()) {
		$lineId = (int)$row['LINE'];
		$data = @unserialize((string)($row['DATA'] ?? ''), ['allowed_classes' => false]);
		$number = '';
		if (is_array($data) && !empty($data['url']) && preg_match('/wa\.me\/(\d+)/', (string)$data['url'], $m)) {
			$number = $m[1];
		}
		if ($number === '' && preg_match('/wa\.me[\\\\\/]+(\d+)/', (string)($row['DATA'] ?? ''), $m)) {
			$number = $m[1];
		}
		$lines[] = [
			'id' => $lineId,
			'connector' => (string)$row['CONNECTOR'],
			'name' => (string)$row['LINE_NAME'],
			'number' => $number,
		];
	}

	echo json_encode(['ok' => true, 'lines' => $lines], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
	http_response_code(500);
	echo json_encode(['ok' => false, 'error' => 'lines']);
}
exit;
