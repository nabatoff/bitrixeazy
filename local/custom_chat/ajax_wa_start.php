<?php
/**
 * Создать исходящую OL-сессию для номера, с которым ещё не было переписки.
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

$answer = static function (array $payload, int $status = 200): void {
	http_response_code($status);
	echo json_encode($payload, JSON_UNESCAPED_UNICODE);
	exit;
};

if ($userId <= 0) {
	$answer(['ok' => false, 'error' => 'auth'], 401);
}

$lineId = (int)($_POST['lineId'] ?? $_REQUEST['lineId'] ?? 0);
$phone = preg_replace('/\D+/', '', (string)($_POST['phone'] ?? $_REQUEST['phone'] ?? ''));
if (strlen($phone) === 10) {
	$phone = '7' . $phone;
} elseif (strlen($phone) === 11 && $phone[0] === '8') {
	$phone = '7' . substr($phone, 1);
}
if ($lineId <= 0 || strlen($phone) < 10 || strlen($phone) > 15) {
	$answer(['ok' => false, 'error' => 'params'], 400);
}

try {
	if (
		!\Bitrix\Main\Loader::includeModule('im')
		|| !\Bitrix\Main\Loader::includeModule('imconnector')
		|| !\Bitrix\Main\Loader::includeModule('imopenlines')
	) {
		throw new \RuntimeException('modules');
	}

	$conn = \Bitrix\Main\Application::getConnection();
	$isAdmin = $USER && is_object($USER) && $USER->IsAdmin();
	$line = $conn->query("
		SELECT s.LINE, s.CONNECTOR, c.LINE_NAME
		FROM b_imconnectors_status s
		INNER JOIN b_imopenlines_config c ON c.ID = s.LINE
		WHERE s.LINE = " . $lineId . "
			AND s.ACTIVE = 'Y'
			AND s.CONNECTION = 'Y'
			AND s.REGISTER = 'Y'
			AND s.CONNECTOR LIKE 'fos_green%'
			AND c.ACTIVE = 'Y'
		LIMIT 1
	")->fetch();
	if (!$line) {
		$answer(['ok' => false, 'error' => 'line_unavailable'], 400);
	}

	if (!$isAdmin) {
		$queue = \Bitrix\ImOpenLines\Model\QueueTable::getList([
			'filter' => ['=CONFIG_ID' => $lineId, '=USER_ID' => $userId],
			'select' => ['ID'],
			'limit' => 1,
		])->fetch();
		if (!$queue) {
			$answer(['ok' => false, 'error' => 'line_denied'], 403);
		}
	}

	$connectorId = (string)$line['CONNECTOR'];
	$waId = $phone . '@c.us';

	// Регистрируем внешнего клиента тем же способом, что входящий коннектор,
	// но без фальшивого входящего сообщения.
	$connector = new \Bitrix\ImConnector\Connectors\Base($connectorId);
	$preparedResult = $connector->processingInputTypingStatus([
		'user' => [
			'id' => $waId,
			'name' => '+' . $phone,
			'last_name' => '',
			'phone' => '+' . $phone,
		],
		'chat' => [
			'id' => $waId,
			'name' => '+' . $phone,
		],
	], $lineId);
	if (!$preparedResult->isSuccess()) {
		$answer([
			'ok' => false,
			'error' => 'client_create',
			'message' => implode('; ', $preparedResult->getErrorMessages()),
		], 500);
	}

	$prepared = $preparedResult->getResult();
	$externalUserId = (int)($prepared['user'] ?? 0);
	if ($externalUserId <= 0) {
		$answer(['ok' => false, 'error' => 'client_id'], 500);
	}

	$userCode = $connectorId . '|' . $lineId . '|' . $waId . '|' . $externalUserId;
	$session = new \Bitrix\ImOpenLines\Session();
	$loaded = $session->load([
		'USER_CODE' => $userCode,
		'CONFIG_ID' => $lineId,
		'MODE' => \Bitrix\ImOpenLines\Session::MODE_OUTPUT,
		'OPERATOR_ID' => $userId,
		'CONNECTOR' => $prepared,
		'CRM_SKIP_PHONE_VALIDATE' => 'N',
	]);
	$chatId = $loaded ? (int)$session->getData('CHAT_ID') : 0;
	if (!$loaded || $chatId <= 0) {
		$answer(['ok' => false, 'error' => 'session_create'], 500);
	}

	$answer([
		'ok' => true,
		'chatId' => $chatId,
		'userCode' => $userCode,
	]);
} catch (\Throwable $e) {
	$answer(['ok' => false, 'error' => 'start_failed', 'message' => $e->getMessage()], 500);
}
