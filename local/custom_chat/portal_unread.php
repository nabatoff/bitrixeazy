<?php
/**
 * JSON: число непрочитанных WhatsApp OL-чатов текущего пользователя.
 * Лёгкий SQL + короткий кэш. Без Im\Recent / GetRecentList / SHOW TABLES.
 */
define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_CHECK', true);
define('DisableEventsCheck', true);
define('NOT_CHECK_PERMISSIONS', true);
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

global $USER;
if (!$USER || !$USER->IsAuthorized()) {
	http_response_code(401);
	echo json_encode(['ok' => false, 'count' => 0, 'error' => 'auth']);
	die();
}

$userId = (int)$USER->GetID();

function waCcPortalIsWaEntity($entityId)
{
	$s = strtolower((string)$entityId);
	return (strpos($s, 'fos_green') !== false
		|| strpos($s, 'whatsapp') !== false
		|| strpos($s, '@c.us') !== false
		|| strpos($s, '@g.us') !== false);
}

function waCcPortalUnreadCompute($userId)
{
	$count = 0;
	$chats = [];
	try {
		$conn = \Bitrix\Main\Application::getConnection();
		$uid = (int)$userId;
		$res = $conn->query("
			SELECT r.ITEM_CID ID, LEFT(c.TITLE, 80) TITLE, c.ENTITY_ID
			FROM b_im_recent r
			INNER JOIN b_im_chat c ON c.ID = r.ITEM_CID
			WHERE r.USER_ID = {$uid}
				AND r.ITEM_TYPE = 'L'
				AND r.UNREAD = 'Y'
			ORDER BY r.DATE_UPDATE DESC
			LIMIT 120
		");
		while ($row = $res->fetch()) {
			if (!waCcPortalIsWaEntity($row['ENTITY_ID'] ?? '')) {
				continue;
			}
			$count++;
			if (count($chats) >= 3) {
				continue;
			}
			$cid = (int)$row['ID'];
			if ($cid <= 0) {
				continue;
			}
			$chats[] = [
				'chatId' => $cid,
				'dialogId' => 'chat' . $cid,
				'title' => (string)($row['TITLE'] ?? ''),
			];
		}
	} catch (\Throwable $e) {
		return ['count' => 0, 'chats' => []];
	}
	return ['count' => $count, 'chats' => $chats];
}

function waCcPortalUnreadSyncCounter($userId, $count)
{
	try {
		if (!class_exists('CUserCounter')) {
			return;
		}
		$siteId = defined('SITE_ID') && SITE_ID ? SITE_ID : 's1';
		$prev = 0;
		if (method_exists('CUserCounter', 'GetValue')) {
			$prev = (int)\CUserCounter::GetValue($userId, 'wa_cc_unread', $siteId);
		}
		if ($prev !== (int)$count) {
			\CUserCounter::Set($userId, 'wa_cc_unread', (int)$count, $siteId);
		}
	} catch (\Throwable $e) {
		// ignore
	}
}

$payload = null;
$cacheTtl = 10;
$cacheId = 'wa_cc_unr_' . $userId;
$cacheDir = '/wa_cc_unread';
$cache = null;

try {
	if (class_exists('\Bitrix\Main\Data\Cache', true)) {
		$cache = \Bitrix\Main\Data\Cache::createInstance();
		if ($cache->initCache($cacheTtl, $cacheId, $cacheDir)) {
			$cached = $cache->getVars();
			if (is_array($cached) && isset($cached['count'])) {
				$payload = $cached;
			}
		}
	}
} catch (\Throwable $e) {
	$cache = null;
	$payload = null;
}

if ($payload === null) {
	$payload = waCcPortalUnreadCompute($userId);
	waCcPortalUnreadSyncCounter($userId, (int)$payload['count']);
	try {
		if ($cache && $cache->startDataCache()) {
			$cache->endDataCache($payload);
		}
	} catch (\Throwable $e) {
		// ignore
	}
}

echo json_encode([
	'ok' => true,
	'count' => (int)$payload['count'],
	'chats' => is_array($payload['chats'] ?? null) ? $payload['chats'] : [],
], JSON_UNESCAPED_UNICODE);
die();
