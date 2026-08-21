<?php
/**
 * Кэш реальных имён WhatsApp-групп (@g.us) из Green API webhook (senderData.chatName).
 */

function waCcGroupTitlesStorePath()
{
	$dir = $_SERVER['DOCUMENT_ROOT'] . '/local/custom_chat/var';
	if (!is_dir($dir)) {
		@mkdir($dir, 0775, true);
	}
	return $dir . '/wa_group_titles.json';
}

function waCcGroupTitlesNormalizeId($raw)
{
	$s = strtolower(trim((string)$raw));
	if ($s === '') {
		return '';
	}
	if (preg_match('/((?:\d{5,20}(?:-\d{5,20})?|\d{10,20})@g\.us)/', $s, $m)) {
		return $m[1];
	}
	return '';
}

function waCcGroupTitlesPickName($value)
{
	$s = trim((string)$value);
	if ($s === '') {
		return '';
	}
	if (preg_match('/@g\.us|@c\.us|green-?api|whatsapp|documentmessage|imagemessage|videomessage|audiomessage/i', $s)) {
		return '';
	}
	if (preg_match('/^\+?\d[\d\s\-()]{7,}$/', $s)) {
		return '';
	}
	if (mb_strlen($s) < 2) {
		return '';
	}
	return $s;
}

function waCcGroupTitlesReadAll()
{
	$path = waCcGroupTitlesStorePath();
	if (!is_file($path)) {
		return [];
	}
	$data = json_decode((string)@file_get_contents($path), true);
	return is_array($data) ? $data : [];
}

function waCcGroupTitlesSaveAll(array $data)
{
	$path = waCcGroupTitlesStorePath();
	$fp = @fopen($path, 'c+');
	if (!$fp) {
		return false;
	}
	flock($fp, LOCK_EX);
	ftruncate($fp, 0);
	rewind($fp);
	fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE));
	flock($fp, LOCK_UN);
	fclose($fp);
	return true;
}

function waCcGroupTitlesSet($groupId, $title, array $meta = [])
{
	$key = waCcGroupTitlesNormalizeId($groupId);
	$title = waCcGroupTitlesPickName($title);
	if ($key === '' || $title === '') {
		return false;
	}

	$path = waCcGroupTitlesStorePath();
	$fp = @fopen($path, 'c+');
	if (!$fp) {
		return false;
	}
	flock($fp, LOCK_EX);
	$raw = stream_get_contents($fp);
	$data = json_decode((string)$raw, true);
	if (!is_array($data)) {
		$data = [];
	}
	$prev = is_array($data[$key] ?? null) ? $data[$key] : [];
	$data[$key] = array_merge($prev, $meta, [
		'title' => $title,
		'ts' => time(),
		'groupId' => $key,
	]);
	ftruncate($fp, 0);
	rewind($fp);
	fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE));
	flock($fp, LOCK_UN);
	fclose($fp);
	return true;
}

function waCcGroupTitlesGet($groupId)
{
	$key = waCcGroupTitlesNormalizeId($groupId);
	if ($key === '') {
		return '';
	}
	$all = waCcGroupTitlesReadAll();
	$row = $all[$key] ?? null;
	if (!is_array($row)) {
		return '';
	}
	return waCcGroupTitlesPickName($row['title'] ?? '');
}

function waCcGroupTitlesApplyWebhook(array $hook)
{
	if (strtolower((string)($hook['typeWebhook'] ?? '')) !== 'incomingmessagereceived') {
		return false;
	}
	$sender = is_array($hook['senderData'] ?? null) ? $hook['senderData'] : [];
	$chatId = (string)($sender['chatId'] ?? '');
	if ($chatId === '' || stripos($chatId, '@g.us') === false) {
		return false;
	}
	$title = waCcGroupTitlesPickName($sender['chatName'] ?? '');
	if ($title === '' && isset($hook['messageData']) && is_array($hook['messageData'])) {
		$md = $hook['messageData'];
		if (($md['typeMessage'] ?? '') === 'groupInviteMessage') {
			$title = waCcGroupTitlesPickName($md['groupInviteMessageData']['groupName'] ?? '');
		}
	}
	if ($title === '') {
		return false;
	}
	return waCcGroupTitlesSet($chatId, $title, [
		'source' => 'webhook',
		'idMessage' => (string)($hook['idMessage'] ?? ''),
	]);
}

function waCcGroupTitlesExtractFromEntityId($entityId)
{
	$entityId = trim((string)$entityId);
	if ($entityId === '') {
		return '';
	}
	$parts = array_values(array_filter(explode('|', $entityId), static function ($p) {
		return $p !== '';
	}));
	if (count($parts) >= 3 && stripos($parts[2], '@g.us') !== false) {
		return waCcGroupTitlesNormalizeId($parts[2]);
	}
	return waCcGroupTitlesNormalizeId($entityId);
}

function waCcGroupTitlesFromChatId($chatId)
{
	$chatId = (int)$chatId;
	if ($chatId <= 0) {
		return ['groupId' => '', 'title' => ''];
	}
	try {
		\Bitrix\Main\Loader::includeModule('im');
	} catch (\Throwable $e) {
		return ['groupId' => '', 'title' => ''];
	}
	$chat = null;
	try {
		if (class_exists('\Bitrix\Im\Model\ChatTable')) {
			$chat = \Bitrix\Im\Model\ChatTable::getById($chatId)->fetch();
		}
	} catch (\Throwable $e) {
		$chat = null;
	}
	if (!is_array($chat)) {
		return ['groupId' => '', 'title' => ''];
	}

	$groupId = '';
	foreach (['ENTITY_ID', 'ENTITY_DATA_1', 'ENTITY_DATA_2', 'ENTITY_DATA_3'] as $field) {
		$groupId = waCcGroupTitlesExtractFromEntityId($chat[$field] ?? '');
		if ($groupId !== '') {
			break;
		}
	}
	if ($groupId === '') {
		return ['groupId' => '', 'title' => ''];
	}

	$cached = waCcGroupTitlesGet($groupId);
	if ($cached !== '') {
		return ['groupId' => $groupId, 'title' => $cached];
	}

	$apiTitle = waCcGroupTitlesFetchFromGreenApi($groupId, $chat);
	if ($apiTitle !== '') {
		waCcGroupTitlesSet($groupId, $apiTitle, ['source' => 'getGroupData']);
		return ['groupId' => $groupId, 'title' => $apiTitle];
	}

	return ['groupId' => $groupId, 'title' => ''];
}

function waCcGreenApiInstancesConfigPath()
{
	return __DIR__ . '/green_api_instances.local.php';
}

function waCcGreenApiInstancesConfig()
{
	static $cfg = null;
	if ($cfg !== null) {
		return $cfg;
	}
	$cfg = ['default' => null, 'lines' => []];
	$file = waCcGreenApiInstancesConfigPath();
	if (is_file($file)) {
		$loaded = include $file;
		if (is_array($loaded)) {
			$cfg = array_merge($cfg, $loaded);
		}
	}
	return $cfg;
}

function waCcGreenApiCredForLine($lineId)
{
	$lineId = (int)$lineId;
	$cfg = waCcGreenApiInstancesConfig();
	if ($lineId > 0 && !empty($cfg['lines'][$lineId]) && is_array($cfg['lines'][$lineId])) {
		return $cfg['lines'][$lineId];
	}
	return is_array($cfg['default'] ?? null) ? $cfg['default'] : null;
}

function waCcGreenApiLineFromEntityId($entityId)
{
	$parts = array_values(array_filter(explode('|', (string)$entityId), static function ($p) {
		return $p !== '';
	}));
	if (count($parts) >= 2 && ctype_digit((string)$parts[1])) {
		return (int)$parts[1];
	}
	return 0;
}

function waCcGroupTitlesFetchFromGreenApi($groupId, array $chat = null)
{
	$groupId = waCcGroupTitlesNormalizeId($groupId);
	if ($groupId === '') {
		return '';
	}

	$lineId = 0;
	if (is_array($chat)) {
		$lineId = waCcGreenApiLineFromEntityId($chat['ENTITY_ID'] ?? '');
	}
	$cred = waCcGreenApiCredForLine($lineId);
	if (!$cred) {
		return '';
	}

	$idInstance = trim((string)($cred['idInstance'] ?? $cred['ID_INSTANCE'] ?? ''));
	$apiToken = trim((string)($cred['apiTokenInstance'] ?? $cred['API_TOKEN'] ?? $cred['apiToken'] ?? ''));
	$apiUrl = rtrim(trim((string)($cred['apiUrl'] ?? $cred['API_URL'] ?? 'https://api.green-api.com')), '/');
	if ($idInstance === '' || $apiToken === '') {
		return '';
	}

	$url = $apiUrl . '/waInstance' . rawurlencode($idInstance) . '/getGroupData/' . rawurlencode($apiToken);
	$body = json_encode(['groupId' => $groupId], JSON_UNESCAPED_UNICODE);
	$ctx = stream_context_create([
		'http' => [
			'method' => 'POST',
			'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
			'content' => $body,
			'timeout' => 12,
			'ignore_errors' => true,
		],
		'ssl' => [
			'verify_peer' => true,
			'verify_peer_name' => true,
		],
	]);
	$raw = @file_get_contents($url, false, $ctx);
	if ($raw === false || $raw === '') {
		return '';
	}
	$data = json_decode($raw, true);
	if (!is_array($data)) {
		return '';
	}
	return waCcGroupTitlesPickName($data['subject'] ?? $data['name'] ?? '');
}
