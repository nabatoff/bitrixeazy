<?php
/**
 * Статусы исходящих WhatsApp из Green API outgoingMessageStatus / lastOutgoingMessages.
 *
 * Важно: статус per idMessage; сводка по чату = последнее сообщение по ts
 * (можно «откатиться» с read→delivered, если ушло новое непрочитанное).
 * readTs = max(ts) среди сообщений со status=read в этом чате.
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
		return ['_messages' => []];
	}
	$raw = @file_get_contents($path);
	$data = json_decode((string)$raw, true);
	if (!is_array($data)) {
		return ['_messages' => []];
	}
	if (!isset($data['_messages']) || !is_array($data['_messages'])) {
		$data['_messages'] = [];
	}
	return $data;
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

/**
 * Обновить статус одного исходящего сообщения + сводку чата.
 * Для одного idMessage ранг только растёт.
 * Для чата: берём сообщение с большим ts (при равном ts — больший ранг).
 */
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
	$ts = (int)$ts ?: time();
	$idMessage = trim((string)$idMessage);
	$chatId = (string)$chatId;

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
	if (!isset($data['_messages']) || !is_array($data['_messages'])) {
		$data['_messages'] = [];
	}

	$changed = false;

	if ($idMessage !== '') {
		$prevMsg = is_array($data['_messages'][$idMessage] ?? null) ? $data['_messages'][$idMessage] : [];
		$prevRank = waCcTicksRank($prevMsg['status'] ?? '');
		if ($rank >= $prevRank) {
			$data['_messages'][$idMessage] = [
				'status' => $status,
				'ts' => max($ts, (int)($prevMsg['ts'] ?? 0)),
				'chatId' => $chatId,
			];
			$changed = true;
		} else {
			/* оставляем более «сильный» статус того же сообщения */
			$status = strtolower((string)($prevMsg['status'] ?? $status));
			$rank = waCcTicksRank($status);
			$ts = max($ts, (int)($prevMsg['ts'] ?? 0));
		}
	}

	foreach ($keys as $key) {
		$prev = is_array($data[$key] ?? null) ? $data[$key] : [];
		$prevTs = (int)($prev['ts'] ?? 0);
		$prevRank = waCcTicksRank($prev['status'] ?? '');
		$prevReadTs = (int)($prev['readTs'] ?? 0);
		if ($status === 'read' || (!empty($prev['status']) && strtolower((string)$prev['status']) === 'read' && $prevReadTs <= 0)) {
			/* миграция: старый sticky read без readTs */
			if ($status === 'read') {
				$prevReadTs = max($prevReadTs, $ts);
			} elseif ($prevReadTs <= 0 && $prevRank >= 3) {
				$prevReadTs = $prevTs;
			}
		}

		$readTs = $prevReadTs;
		if ($rank >= 3) {
			$readTs = max($readTs, $ts);
		}

		$sameMsg = ($idMessage !== '' && $idMessage === (string)($prev['idMessage'] ?? ''));
		$newer = ($ts > $prevTs);
		$sameTimeBetter = ($ts === $prevTs && $rank >= $prevRank);
		$replaceLatest = ($prevTs <= 0) || $newer || $sameTimeBetter || $sameMsg;

		if (!$replaceLatest && $readTs === $prevReadTs) {
			continue;
		}

		if ($replaceLatest) {
			$data[$key] = [
				'status' => $status,
				'ts' => $ts,
				'idMessage' => $idMessage !== '' ? $idMessage : (string)($prev['idMessage'] ?? ''),
				'chatId' => $chatId,
				'readTs' => $readTs,
			];
		} else {
			$data[$key] = [
				'status' => (string)($prev['status'] ?? ''),
				'ts' => $prevTs,
				'idMessage' => (string)($prev['idMessage'] ?? ''),
				'chatId' => (string)($prev['chatId'] ?? $chatId),
				'readTs' => $readTs,
			];
		}
		$changed = true;
	}

	if ($changed) {
		/* не раздувать _messages бесконечно */
		if (count($data['_messages']) > 8000) {
			uasort($data['_messages'], static function ($a, $b) {
				return ((int)($b['ts'] ?? 0)) <=> ((int)($a['ts'] ?? 0));
			});
			$data['_messages'] = array_slice($data['_messages'], 0, 5000, true);
		}
		ftruncate($fp, 0);
		rewind($fp);
		fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE));
	}
	flock($fp, LOCK_UN);
	fclose($fp);
	return $changed;
}

function waCcTicksApplyWebhook(array $hook)
{
	if (strtolower((string)($hook['typeWebhook'] ?? '')) !== 'outgoingmessagestatus') {
		return false;
	}
	return waCcTicksApplyStatus(
		(string)($hook['chatId'] ?? ''),
		(string)($hook['status'] ?? ''),
		(int)($hook['timestamp'] ?? time()),
		(string)($hook['idMessage'] ?? '')
	);
}

function waCcTicksNormalizeChatRow(array $row)
{
	$status = strtolower((string)($row['status'] ?? ''));
	$ts = (int)($row['ts'] ?? 0);
	$readTs = (int)($row['readTs'] ?? 0);
	if ($readTs <= 0 && $status === 'read' && $ts > 0) {
		$readTs = $ts;
	}
	return [
		'status' => $status,
		'ts' => $ts,
		'idMessage' => (string)($row['idMessage'] ?? ''),
		'chatId' => (string)($row['chatId'] ?? ''),
		'readTs' => $readTs,
	];
}

function waCcTicksBestForKeys(array $keys)
{
	$all = waCcTicksReadAll();
	$best = null;
	$bestTs = -1;
	$bestRank = -1;
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
			if ($k === '_messages' || !isset($all[$k]) || !is_array($all[$k])) {
				continue;
			}
			$row = waCcTicksNormalizeChatRow($all[$k]);
			$ts = $row['ts'];
			$rank = waCcTicksRank($row['status']);
			if ($ts > $bestTs || ($ts === $bestTs && $rank > $bestRank)) {
				$bestTs = $ts;
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

function waCcTicksPollStampPath()
{
	return dirname(waCcTicksStorePath()) . '/wa_ticks_poll.json';
}

function waCcTicksShouldPoll($idInstance, $minInterval = 20)
{
	$idInstance = trim((string)$idInstance);
	if ($idInstance === '') {
		return false;
	}
	$minInterval = max(2, min(60, (int)$minInterval));
	$path = waCcTicksPollStampPath();
	$data = [];
	if (is_file($path)) {
		$decoded = json_decode((string)@file_get_contents($path), true);
		if (is_array($decoded)) {
			$data = $decoded;
		}
	}
	$prev = (int)($data[$idInstance] ?? 0);
	if ($prev > 0 && (time() - $prev) < $minInterval) {
		return false;
	}
	$data[$idInstance] = time();
	@file_put_contents($path, json_encode($data));
	return true;
}

function waCcTicksPollLine($lineId, $minutes = 180, $minInterval = 20)
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
	if (!waCcTicksShouldPoll($idInstance, $minInterval)) {
		return false;
	}
	$minutes = max(15, min(1440, (int)$minutes));
	$url = $apiUrl . '/waInstance' . rawurlencode($idInstance)
		. '/lastOutgoingMessages/' . rawurlencode($apiToken)
		. '?minutes=' . $minutes;
	$payload = waCcTicksHttpGet($url, 8);
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

function waCcTicksBestForKeysWithPoll(array $keys, $lineId = 0, $force = false)
{
	$row = waCcTicksBestForKeys($keys);
	if ((int)$lineId > 0) {
		/* force (открытый чат / после send): poll каждые 3с; иначе 12с */
		waCcTicksPollLine((int)$lineId, $force ? 60 : 180, $force ? 3 : 12);
		$row = waCcTicksBestForKeys($keys);
	}
	return $row;
}
