<?php
/**
 * Batch UF для покраски канбана. Только авторизованный пользователь CRM.
 * POST: ids[]=1&ids[]=2  или  ids=1,2,3
 */
define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_CHECK', true);
define('DisableEventsCheck', true);
define('NOT_CHECK_PERMISSIONS', false);
define('StopBuffering', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

header('Content-Type: application/json; charset=UTF-8');

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\Web\Json;

$answer = static function (array $payload, int $code = 200) {
	http_response_code($code);
	echo Json::encode($payload);
	die();
};

if (!check_bitrix_sessid()) {
	$answer(['status' => 'error', 'message' => 'bad sessid'], 403);
}

global $USER;
if (!$USER || !$USER->IsAuthorized()) {
	$answer(['status' => 'error', 'message' => 'auth'], 401);
}

if (!Loader::includeModule('crm')) {
	$answer(['status' => 'error', 'message' => 'crm'], 500);
}

$request = Application::getInstance()->getContext()->getRequest();
$raw = $request->getPost('ids');
if ($raw === null || $raw === '') {
	$raw = $request->getQuery('ids');
}

$ids = [];
if (is_array($raw)) {
	foreach ($raw as $v) {
		$id = (int)$v;
		if ($id > 0) {
			$ids[$id] = $id;
		}
	}
} else {
	foreach (preg_split('/[,\s]+/', (string)$raw) as $v) {
		$id = (int)$v;
		if ($id > 0) {
			$ids[$id] = $id;
		}
	}
}

$ids = array_values($ids);
if (!$ids) {
	$answer(['status' => 'success', 'deals' => []]);
}
if (count($ids) > 80) {
	$ids = array_slice($ids, 0, 80);
}

$ufPrepay = 'UF_CRM_1764332847245';
$ufApproveNoPrepay = 'UF_CRM_1764577192130';
$ufBought = 'UF_CRM_1783486791226';
$ufPaid = 'UF_CRM_1764577842986';
$ufIssued = 'UF_CRM_1784524115744';

$select = [
	'ID',
	'STAGE_ID',
	'CATEGORY_ID',
	$ufPrepay,
	$ufApproveNoPrepay,
	$ufBought,
	$ufPaid,
	$ufIssued,
];

$deals = [];
$res = CCrmDeal::GetListEx(
	[],
	[
		'@ID' => $ids,
		'CHECK_PERMISSIONS' => 'Y',
	],
	false,
	['nTopCount' => count($ids)],
	$select
);

while ($row = $res->Fetch()) {
	$id = (int)$row['ID'];
	$deals[(string)$id] = [
		'ID' => $id,
		'STAGE_ID' => (string)($row['STAGE_ID'] ?? ''),
		'CATEGORY_ID' => (int)($row['CATEGORY_ID'] ?? 0),
		$ufPrepay => $row[$ufPrepay] ?? null,
		$ufApproveNoPrepay => $row[$ufApproveNoPrepay] ?? null,
		$ufBought => $row[$ufBought] ?? null,
		$ufPaid => $row[$ufPaid] ?? null,
		$ufIssued => $row[$ufIssued] ?? null,
	];
}

$answer(['status' => 'success', 'deals' => $deals]);
