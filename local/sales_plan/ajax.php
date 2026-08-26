<?php
define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_CHECK', true);
define('DisableEventsCheck', true);
define('NOT_CHECK_PERMISSIONS', true);
define('BX_SECURITY_SHOW_MESSAGE', false);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

if (!\Bitrix\Main\Loader::includeModule('artflowers.salesplan')) {
	header('Content-Type: application/json; charset=utf-8');
	http_response_code(500);
	echo json_encode(['ok' => false, 'error' => 'Module not installed'], JSON_UNESCAPED_UNICODE);
	exit;
}

\Artflowers\Salesplan\Controller\Api::handle();
