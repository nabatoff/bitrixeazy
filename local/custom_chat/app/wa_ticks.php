<?php
/**
 * Статусы исходящих WhatsApp из Green API outgoingMessageStatus.
 * Ключ — chatId (77071234567@c.us / 1203...@g.us) и голые цифры телефона.
 */

function waCcTicksStorePath()
{
	$dir = $_SERVER['DOCUMENT_ROOT'] . '/local/custom_chat/var';
	if (!is_dir($dir)) {
		@mkdir($dir, 0775, true);
	}
	return $dir . '/wa_ticks.json';
}

function waCcTicksRank($status)
{
	$map = [
		'sent' => 1,
		'delivered' => 2,
		'read' => 3,
	];
	return (int)($map[strtolower((string)$status)] ?? 0);
}

function waCcTicksNormalizeKey($raw)
{
	$s = strtolower(trim((string)$raw));
	if ($s === '') {
		return '';
	}
	if (preg_match('/(\d{10,20})@c\.us/', $s, $m)) {
		return $m[1] . '@c.us';
	}
	if (preg_match('/((?:\d{5,20}(?:-\d{5,20})?|\d{10,20})@g\.us)/', $s, $m)) {
		return $m[1];
	}
	$digits = preg_replace('/\D+/', '', $s);
	if (strlen($digits) >= 10) {
		if ($digits[0] === '8' && strlen($digits) === 11) {
			$digits = '7' . substr($digits, 1);
		}
		return $digits;
	}
	return '';
}

function waCcTicksKeysFromChatId($chatId)
{
	$keys = [];
	$norm = waCcTicksNormalizeKey($chatId);
	if ($norm !== '') {
		$keys[] = $norm;
	}
	if (preg_match('/(\d{10,20})@c\.us/i', (string)$chatId, $m)) {
		$keys[] = $m[1];
		if (strlen($m[1]) === 11 && $m[1][0] === '7') {
			$keys[] = substr($m[1], 1);
		}
	}
	return array_values(array_unique(array_filter($keys)));
}

function waCcTicksReadAll()
{
	$path = waCcTicksStorePath();
	if (!is_file($path)) {
		return [];
	}
	$raw = @file_get_contents($path);
	$data = json_decode((string)$raw, true);
	return is_array($data) ? $data : [];
}

function waCcTicksSaveAll(array $data)
{
	$path = waCcTicksStorePath();
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

function waCcTicksApplyWebhook(array $hook)
{
	if (strtolower((string)($hook['typeWebhook'] ?? '')) !== 'outgoingmessagestatus') {
		return false;
	}
	$status = strtolower((string)($hook['status'] ?? ''));
	$rank = waCcTicksRank($status);
	if ($rank < 1) {
		return false;
	}
	$chatId = (string)($hook['chatId'] ?? '');
	$keys = waCcTicksKeysFromChatId($chatId);
	if (!$keys) {
		return false;
	}
	$path = waCcTicksStorePath();
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
	$ts = (int)($hook['timestamp'] ?? time());
	$idMessage = (string)($hook['idMessage'] ?? '');
	foreach ($keys as $key) {
		$prev = is_array($data[$key] ?? null) ? $data[$key] : [];
		$prevRank = waCcTicksRank($prev['status'] ?? '');
		if ($rank < $prevRank) {
			continue;
		}
		$data[$key] = [
			'status' => $status,
			'ts' => $ts,
			'idMessage' => $idMessage,
			'chatId' => $chatId,
		];
	}
	ftruncate($fp, 0);
	rewind($fp);
	fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE));
	flock($fp, LOCK_UN);
	fclose($fp);
	return true;
}

function waCcTicksBestForKeys(array $keys)
{
	$all = waCcTicksReadAll();
	$best = null;
	$bestRank = 0;
	foreach ($keys as $raw) {
		$key = waCcTicksNormalizeKey($raw);
		if ($key === '') {
			continue;
		}
		$candidates = [$key];
		if (ctype_digit($key) && strlen($key) >= 10) {
			$candidates[] = $key . '@c.us';
			if ($key[0] === '7' && strlen($key) === 11) {
				$candidates[] = substr($key, 1);
			}
		}
		foreach ($candidates as $k) {
			$row = $all[$k] ?? null;
			if (!is_array($row)) {
				continue;
			}
			$rank = waCcTicksRank($row['status'] ?? '');
			if ($rank > $bestRank) {
				$bestRank = $rank;
				$best = $row;
			}
		}
	}
	return $best;
}

function waCcTicksCredForLine($lineId)
{
	$lineId = (int)$lineId;
	$file = __DIR__ . '/green_api_instances.local.php';
	if (!is_file($file)) {
		return null;
	}
	$cfg = include $file;
	if (!is_array($cfg)) {
		return null;
	}
	if ($lineId > 0 && !empty($cfg['lines'][$lineId]) && is_array($cfg['lines'][$lineId])) {
		return $cfg['lines'][$lineId];
	}
	return is_array($cfg['default'] ?? null) ? $cfg['default'] : null;
}

function waCcTicksHttpGet($url, $timeout = 8)
{
	$ctx = stream_context_create([
		'http' => [
			'method' => 'GET',
			'header' => "Accept: application/json\r\n",
			'timeout' => $timeout,
			'ignore_errors' => true,
		],
		'ssl' => [
			'verify_peer' => true,
			'verify_peer_name' => true,
		],
	]);
	$raw = @file_get_contents($url, false, $ctx);
	if ($raw === false || $raw === '') {
		return null;
	}
	$data = json_decode($raw, true);
	return is_array($data) ? $data : null;
}

function waCcTicksNormalizeJournalRows($payload)
{
	if (!is_array($payload)) {
		return [];
	}
	if (isset($payload[0]) && is_array($payload[0])) {
		return $payload;
	}
	foreach (['messages', 'data', 'result'] as $k) {
		if (isset($payload[$k]) && is_array($payload[$k])) {
			return array_values($payload[$k]);
		}
	}
	return [];
}

function waCcTicksApplyStatus($chatId, $status, $ts, $idMessage)
{
	$status = strtolower(trim((string)$status));
	$rank = waCcTicksRank($status);
	if ($rank < 1) {
		return false;
	}
	$keys = waCcTicksKeysFromChatId($chatId);
	if (!$keys) {
		return false;
	}
	$path = waCcTicksStorePath();
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
	$ts = (int)$ts ?: time();
	$idMessage = (string)$idMessage;
	$chatId = (string)$chatId;
	$changed = false;
	foreach ($keys as $key) {
		$prev = is_array($data[$key] ?? null) ? $data[$key] : [];
		$prevRank = waCcTicksRank($prev['status'] ?? '');
		if ($rank < $prevRank) {
			continue;
		}
		$data[$key] = [
			'status' => $status,
			'ts' => $ts,
			'idMessage' => $idMessage,
			'chatId' => $chatId,
		];
		$changed = true;
	}
	if ($changed) {
		ftruncate($fp, 0);
		rewind($fp);
		fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE));
	}
	flock($fp, LOCK_UN);
	fclose($fp);
	return $changed;
}

function waCcTicksPollStampPath()
{
	return dirname(waCcTicksStorePath()) . '/wa_ticks_poll.json';
}

function waCcTicksShouldPoll($idInstance)
{
	$idInstance = trim((string)$idInstance);
	if ($idInstance === '') {
		return false;
	}
	$path = waCcTicksPollStampPath();
	$data = [];
	if (is_file($path)) {
		$decoded = json_decode((string)@file_get_contents($path), true);
		if (is_array($decoded)) {
			$data = $decoded;
		}
	}
	$prev = (int)($data[$idInstance] ?? 0);
	if ($prev > 0 && (time() - $prev) < 20) {
		return false;
	}
	$data[$idInstance] = time();
	@file_put_contents($path, json_encode($data));
	return true;
}

function waCcTicksPollLine($lineId, $minutes = 180)
{
	$cred = waCcTicksCredForLine($lineId);
	if (!$cred) {
		return false;
	}
	$idInstance = trim((string)($cred['idInstance'] ?? ''));
	$apiToken = trim((string)($cred['apiTokenInstance'] ?? $cred['apiToken'] ?? ''));
	$apiUrl = rtrim(trim((string)($cred['apiUrl'] ?? 'https://api.green-api.com')), '/');
	if ($idInstance === '' || $apiToken === '') {
		return false;
	}
	if (!waCcTicksShouldPoll($idInstance)) {
		return false;
	}
	$minutes = max(15, min(1440, (int)$minutes));
	$url = $apiUrl . '/waInstance' . rawurlencode($idInstance)
		. '/lastOutgoingMessages/' . rawurlencode($apiToken)
		. '?minutes=' . $minutes;
	$payload = waCcTicksHttpGet($url, 10);
	$rows = waCcTicksNormalizeJournalRows($payload);
	if (!$rows) {
		return false;
	}
	$applied = 0;
	foreach ($rows as $row) {
		if (!is_array($row)) {
			continue;
		}
		$chatId = (string)($row['chatId'] ?? '');
		$status = (string)($row['statusMessage'] ?? $row['status'] ?? '');
		if ($chatId === '' || $status === '') {
			continue;
		}
		if (waCcTicksApplyStatus(
			$chatId,
			$status,
			(int)($row['timestamp'] ?? 0),
			(string)($row['idMessage'] ?? '')
		)) {
			$applied++;
		}
	}
	return $applied > 0;
}

function waCcTicksBestForKeysWithPoll(array $keys, $lineId = 0)
{
	$row = waCcTicksBestForKeys($keys);
	$rank = waCcTicksRank($row['status'] ?? '');
	$age = time() - (int)($row['ts'] ?? 0);
	if ($rank >= 3 && $age >= 0 && $age < 25) {
		return $row;
	}
	if ((int)$lineId > 0) {
		waCcTicksPollLine((int)$lineId);
		$row = waCcTicksBestForKeys($keys);
	}
	return $row;
}
