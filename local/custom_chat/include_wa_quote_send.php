<?php
/**
 * Нативная цитата WhatsApp: send через Green API с quotedMessageId.
 * IM-сообщение появится через webhook outgoingMessageReceived — без im.message.add.
 */

function waCcQuoteSendJson(array $payload, $code = 200)
{
	http_response_code((int)$code);
	header('Content-Type: application/json; charset=utf-8');
	header('Cache-Control: no-store');
	echo json_encode($payload, JSON_UNESCAPED_UNICODE);
	exit;
}

function waCcQuoteSendAuthed()
{
	global $USER;
	if ($USER && is_object($USER) && $USER->IsAuthorized()) {
		return true;
	}
	return !empty($GLOBALS['WA_CC_MEDIA_AUTHED']) || !empty($GLOBALS['WA_CC_MEDIA_AUTHED']);
}

function waCcQuoteSendParseEntity($entityId)
{
	$entityId = (string)$entityId;
	$lineId = 0;
	$chatId = '';
	$parts = array_values(array_filter(explode('|', $entityId), static function ($p) {
		return $p !== '';
	}));
	if (isset($parts[1]) && ctype_digit((string)$parts[1])) {
		$lineId = (int)$parts[1];
	}
	if (preg_match('/(\d{5,32}(?:-\d{5,20})?@(?:c|g)\.us)/i', $entityId, $m)) {
		$chatId = strtolower($m[1]);
	} elseif (isset($parts[2]) && strpos($parts[2], '@') !== false) {
		$chatId = strtolower(trim((string)$parts[2]));
	}
	return [$lineId, $chatId];
}

function waCcQuoteSendPickMid($raw)
{
	if (is_array($raw)) {
		foreach ($raw as $v) {
			$got = waCcQuoteSendPickMid($v);
			if ($got !== '') {
				return $got;
			}
		}
		return '';
	}
	$s = trim((string)$raw);
	if ($s === '') {
		return '';
	}
	if ($s[0] === '{' || $s[0] === '[') {
		$decoded = json_decode($s, true);
		if (is_array($decoded)) {
			return waCcQuoteSendPickMid($decoded);
		}
	}
	if (!preg_match('/^[A-Za-z0-9_-]{10,80}$/', $s)) {
		return '';
	}
	/* коннектор иногда пишет CONNECTOR_MID с хвостом: 3EB0...B527DD5_c */
	if (preg_match('/^([A-Za-z0-9]{10,80})_[A-Za-z0-9]{1,6}$/', $s, $m)) {
		return $m[1];
	}
	return $s;
}

/**
 * Все кандидаты WA idMessage для IM-сообщения, в порядке доверия.
 */
function waCcQuoteSendResolveMids($messageId, $hint = '')
{
	$found = [];
	$push = static function ($raw) use (&$found) {
		$mid = waCcQuoteSendPickMid($raw);
		if ($mid !== '' && !in_array($mid, $found, true)) {
			$found[] = $mid;
		}
	};

	$push($hint);

	$messageId = (int)$messageId;
	if ($messageId <= 0) {
		return $found;
	}

	if (class_exists('CIMMessageParam')) {
		try {
			$params = \CIMMessageParam::Get($messageId);
			if (is_array($params)) {
				foreach (['CONNECTOR_MID', 'EXTERNAL_ID', 'connector_mid'] as $key) {
					if (!empty($params[$key])) {
						$push($params[$key]);
					}
				}
			}
		} catch (\Throwable $e) {
			/* ignore */
		}
	}

	try {
		$conn = \Bitrix\Main\Application::getConnection();
		$sql = "SELECT PARAM_VALUE FROM b_im_message_param
			WHERE MESSAGE_ID=" . $messageId . "
			AND PARAM_NAME IN ('CONNECTOR_MID','EXTERNAL_ID')";
		$res = $conn->query($sql);
		while ($row = $res->fetch()) {
			$push($row['PARAM_VALUE'] ?? '');
		}
	} catch (\Throwable $e) {
		/* ignore */
	}

	return $found;
}

function waCcQuoteSendResolveMid($messageId, $hint = '')
{
	$list = waCcQuoteSendResolveMids($messageId, $hint);
	return $list ? $list[0] : '';
}

function waCcQuoteSendMediaUrl($apiUrl)
{
	$apiUrl = rtrim((string)$apiUrl, '/');
	if (strpos($apiUrl, '.api.') !== false) {
		return str_replace('.api.', '.media.', $apiUrl);
	}
	if (preg_match('#^https://(\d+)\.api\.green-?api\.com#i', $apiUrl, $m)) {
		return 'https://' . $m[1] . '.media.green-api.com';
	}
	return str_replace('api.green-api.com', 'media.green-api.com', $apiUrl);
}

function waCcQuoteSendHttpJson($url, array $body, $timeout = 25)
{
	$payload = json_encode($body, JSON_UNESCAPED_UNICODE);
	if (!function_exists('curl_init')) {
		$ctx = stream_context_create([
			'http' => [
				'method' => 'POST',
				'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
				'content' => $payload,
				'timeout' => $timeout,
				'ignore_errors' => true,
			],
			'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
		]);
		$raw = @file_get_contents($url, false, $ctx);
		$data = is_string($raw) ? json_decode($raw, true) : null;
		return [is_string($raw) ? 200 : 0, is_array($data) ? $data : null, (string)$raw];
	}
	$ch = curl_init($url);
	curl_setopt_array($ch, [
		CURLOPT_POST => true,
		CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
		CURLOPT_POSTFIELDS => $payload,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_CONNECTTIMEOUT => 8,
		CURLOPT_TIMEOUT => $timeout,
		CURLOPT_SSL_VERIFYPEER => true,
	]);
	$raw = curl_exec($ch);
	$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);
	$data = is_string($raw) ? json_decode($raw, true) : null;
	return [$code, is_array($data) ? $data : null, is_string($raw) ? $raw : ''];
}

function waCcQuoteSendHttpFile($url, array $fields, $filePath, $fileName, $mime, $timeout = 60)
{
	if (!function_exists('curl_init') || !class_exists('CURLFile')) {
		return [0, null, 'curl_file_unavailable'];
	}
	$post = $fields;
	$post['file'] = new CURLFile($filePath, $mime ?: 'application/octet-stream', $fileName);
	$ch = curl_init($url);
	curl_setopt_array($ch, [
		CURLOPT_POST => true,
		CURLOPT_POSTFIELDS => $post,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_CONNECTTIMEOUT => 8,
		CURLOPT_TIMEOUT => $timeout,
		CURLOPT_SSL_VERIFYPEER => true,
	]);
	$raw = curl_exec($ch);
	$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);
	$data = is_string($raw) ? json_decode($raw, true) : null;
	return [$code, is_array($data) ? $data : null, is_string($raw) ? $raw : ''];
}

function waCcQuoteSendCred($lineId)
{
	require_once __DIR__ . '/app/wa_ticks.php';
	$cred = waCcTicksCredForLine($lineId);
	if (!is_array($cred)) {
		return null;
	}
	$idInstance = trim((string)($cred['idInstance'] ?? ''));
	$token = trim((string)($cred['apiTokenInstance'] ?? $cred['apiToken'] ?? ''));
	$apiUrl = rtrim(trim((string)($cred['apiUrl'] ?? 'https://api.green-api.com')), '/');
	$mediaUrl = rtrim(trim((string)($cred['mediaUrl'] ?? '')), '/');
	if ($mediaUrl === '') {
		$mediaUrl = waCcQuoteSendMediaUrl($apiUrl);
	}
	if ($idInstance === '' || $token === '') {
		return null;
	}
	return [
		'idInstance' => $idInstance,
		'token' => $token,
		'apiUrl' => $apiUrl,
		'mediaUrl' => $mediaUrl,
	];
}

function waCcQuoteSendEndpoint(array $cred, $method, $media = false)
{
	$base = $media ? $cred['mediaUrl'] : $cred['apiUrl'];
	return $base . '/waInstance' . rawurlencode($cred['idInstance'])
		. '/' . $method . '/' . rawurlencode($cred['token']);
}

function waCcQuoteSendValidateMid(array $cred, $greenChat, $quotedId)
{
	[$code, $data] = waCcQuoteSendHttpJson(
		waCcQuoteSendEndpoint($cred, 'getMessage'),
		['chatId' => $greenChat, 'idMessage' => $quotedId],
		12
	);
	if ($code === 200 && is_array($data) && !empty($data['idMessage'])) {
		return true;
	}
	/* метод может быть выключен — не блокируем send */
	if ($code === 404 || $code === 400 || $code === 466) {
		return null;
	}
	return $code >= 200 && $code < 300;
}

function waCcQuoteSendGuessMid(array $cred, $greenChat, array $imMsg)
{
	[$code, $data] = waCcQuoteSendHttpJson(
		waCcQuoteSendEndpoint($cred, 'getChatHistory'),
		['chatId' => $greenChat, 'count' => 40],
		15
	);
	$rows = [];
	if (is_array($data)) {
		$rows = isset($data[0]) || $data === [] ? $data : (isset($data['messages']) && is_array($data['messages']) ? $data['messages'] : []);
	}
	if ($code !== 200 || !$rows) {
		return '';
	}
	$text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string)($imMsg['MESSAGE'] ?? '')), ENT_QUOTES, 'UTF-8')));
	$date = $imMsg['DATE_CREATE'] ?? null;
	$ts = 0;
	if (is_object($date) && method_exists($date, 'getTimestamp')) {
		$ts = (int)$date->getTimestamp();
	} elseif ($date) {
		$ts = (int)strtotime((string)$date);
	}
	$best = '';
	$bestDiff = 12;
	foreach ($rows as $row) {
		if (!is_array($row)) {
			continue;
		}
		$id = trim((string)($row['idMessage'] ?? ''));
		if ($id === '' || !preg_match('/^[A-Za-z0-9_-]{10,80}$/', $id)) {
			continue;
		}
		$rts = (int)($row['timestamp'] ?? 0);
		$rtext = (string)($row['textMessage'] ?? '');
		if ($rtext === '' && !empty($row['extendedTextMessage']['text'])) {
			$rtext = (string)$row['extendedTextMessage']['text'];
		}
		$rtext = trim(preg_replace('/\s+/u', ' ', $rtext));
		$diff = $ts > 0 && $rts > 0 ? abs($rts - $ts) : 99;
		$textOk = ($text !== '' && $rtext !== '' && (
			mb_strtolower($text) === mb_strtolower($rtext)
			|| mb_strpos(mb_strtolower($rtext), mb_strtolower(mb_substr($text, 0, 48))) !== false
			|| mb_strpos(mb_strtolower($text), mb_strtolower(mb_substr($rtext, 0, 48))) !== false
		));
		if ($textOk && $diff < $bestDiff) {
			$bestDiff = $diff;
			$best = $id;
		}
	}
	return $best;
}

function waCcQuoteSendCurrentUserId()
{
	global $USER;
	if ($USER && is_object($USER) && $USER->IsAuthorized()) {
		return (int)$USER->GetID();
	}
	return (int)($GLOBALS['WA_CC_FORCED_USER_ID'] ?? 0);
}

function waCcQuoteSendPreviewFromIm(array $imMsg)
{
	$text = html_entity_decode(strip_tags((string)($imMsg['MESSAGE'] ?? '')), ENT_QUOTES, 'UTF-8');
	$text = trim(preg_replace('/\s+/u', ' ', $text));
	if ($text !== '' && function_exists('mb_strlen') && mb_strlen($text) > 140) {
		$text = mb_substr($text, 0, 137) . '...';
	} elseif ($text !== '' && strlen($text) > 140) {
		$text = substr($text, 0, 137) . '...';
	}
	return $text;
}

function waCcQuoteSendFileIds($value)
{
	$out = [];
	$walk = static function ($v) use (&$out, &$walk) {
		if (is_array($v)) {
			foreach ($v as $item) {
				$walk($item);
			}
			return;
		}
		if (is_object($v)) {
			$walk((array)$v);
			return;
		}
		$s = trim((string)$v);
		if ($s === '') {
			return;
		}
		if ($s[0] === '{' || $s[0] === '[') {
			$json = json_decode($s, true);
			if (is_array($json)) {
				$walk($json);
				return;
			}
		}
		if (preg_match('/^(?:a|s|i|O):/', $s)) {
			$decoded = @unserialize($s, ['allowed_classes' => false]);
			if ($decoded !== false || $s === 'b:0;') {
				$walk($decoded);
				return;
			}
		}
		if (preg_match_all('/(?:^|[^\d])n?(\d{1,12})(?:[^\d]|$)/', $s, $matches)) {
			foreach ($matches[1] as $id) {
				$id = (int)$id;
				if ($id > 0) {
					$out[$id] = $id;
				}
			}
		}
	};
	$walk($value);
	return array_values($out);
}

function waCcQuoteSendMediaKind($name)
{
	$ext = strtolower(pathinfo((string)$name, PATHINFO_EXTENSION));
	if (preg_match('/^(jpe?g|png|gif|webp|bmp|heic|heif)$/', $ext)) {
		return 'image';
	}
	if (preg_match('/^(mp4|mov|avi|mkv|webm)$/', $ext)) {
		return 'video';
	}
	if (preg_match('/^(mp3|ogg|oga|wav|m4a|opus|aac)$/', $ext)) {
		return 'audio';
	}
	return $ext !== '' ? 'file' : '';
}

/**
 * Green уже доставил сообщение в WhatsApp, поэтому в IM кладём копию
 * с SKIP_CONNECTOR=Y — иначе коннектор отправит клиенту второй раз.
 */
function waCcQuoteSendEchoToIm($chatId, $text, $replyId, $idMessage)
{
	$userId = waCcQuoteSendCurrentUserId();
	if ($userId <= 0 || (int)$chatId <= 0 || trim((string)$text) === '') {
		return 0;
	}
	if (!class_exists('CIMMessenger')) {
		return 0;
	}
	$params = [];
	if ((int)$replyId > 0) {
		$params['REPLY_ID'] = (string)(int)$replyId;
		$params['IMOL_QUOTE_MSG'] = 'Y';
	}
	if ((string)$idMessage !== '') {
		$params['CONNECTOR_MID'] = (string)$idMessage;
	}
	try {
		$add = [
			'TO_CHAT_ID' => (int)$chatId,
			'FROM_USER_ID' => $userId,
			'MESSAGE' => (string)$text,
			'SKIP_CONNECTOR' => 'Y',
			'SKIP_COMMAND' => 'Y',
		];
		if ($params) {
			$add['PARAMS'] = $params;
		}
		$msgId = (int)\CIMMessenger::Add($add);
	} catch (\Throwable $e) {
		return 0;
	}
	if ($msgId > 0 && $params && class_exists('CIMMessageParam')) {
		try {
			\CIMMessageParam::Set($msgId, $params);
			\CIMMessageParam::SendPull($msgId, array_keys($params));
		} catch (\Throwable $e) {
			/* ignore */
		}
	}
	return $msgId;
}

function waCcQuoteLinkHandle()
{
	if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
		waCcQuoteSendJson(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED'], 405);
	}
	if (!waCcQuoteSendAuthed()) {
		waCcQuoteSendJson(['ok' => false, 'error' => 'AUTH_REQUIRED'], 401);
	}

	$chatId = (int)($_POST['chatId'] ?? 0);
	$fileId = (int)($_POST['fileId'] ?? 0);
	$replyId = (int)($_POST['replyId'] ?? 0);
	if ($chatId <= 0 || $fileId <= 0 || $replyId <= 0) {
		waCcQuoteSendJson(['ok' => false, 'error' => 'BAD_REQUEST'], 400);
	}

	$conn = \Bitrix\Main\Application::getConnection();
	$reply = $conn->query("SELECT ID, CHAT_ID FROM b_im_message WHERE ID=" . $replyId)->fetch();
	if (!$reply || (int)$reply['CHAT_ID'] !== $chatId) {
		waCcQuoteSendJson(['ok' => false, 'error' => 'REPLY_NOT_IN_CHAT'], 400);
	}

	$userId = waCcQuoteSendCurrentUserId();
	$rows = $conn->query("SELECT ID FROM b_im_message WHERE CHAT_ID=" . $chatId .
		" AND AUTHOR_ID=" . $userId . " ORDER BY ID DESC LIMIT 30");
	$messageId = 0;
	while ($row = $rows->fetch()) {
		$candidateId = (int)$row['ID'];
		$params = \CIMMessageParam::Get($candidateId);
		$candidateFileIds = waCcQuoteSendFileIds($params['FILE_ID'] ?? ($params['FILE'] ?? []));
		if (in_array($fileId, $candidateFileIds, true)) {
			$messageId = $candidateId;
			break;
		}
	}
	if ($messageId <= 0) {
		waCcQuoteSendJson(['ok' => false, 'error' => 'MESSAGE_NOT_FOUND'], 404);
	}

	\CIMMessageParam::Set($messageId, [
		'REPLY_ID' => $replyId,
		'IMOL_QUOTE_MSG' => 'Y',
	]);
	try {
		\CIMMessageParam::SendPull($messageId, ['REPLY_ID', 'IMOL_QUOTE_MSG']);
	} catch (\Throwable $e) {
		/* periodic sync will still pick the parameter up */
	}
	waCcQuoteSendJson(['ok' => true, 'messageId' => $messageId, 'replyId' => $replyId]);
}

function waCcQuoteSendMetaHandle()
{
	if (!waCcQuoteSendAuthed()) {
		waCcQuoteSendJson(['ok' => false, 'error' => 'auth'], 401);
	}
	\Bitrix\Main\Loader::includeModule('im');
	$raw = (string)($_GET['ids'] ?? '');
	$ids = array_values(array_unique(array_filter(array_map('intval', preg_split('/[^\d]+/', $raw)))));
	$ids = array_slice($ids, 0, 80);
	if (!$ids) {
		waCcQuoteSendJson(['ok' => true, 'items' => []]);
	}
	$conn = \Bitrix\Main\Application::getConnection();
	$idList = implode(',', $ids);
	$replyBy = [];
	try {
		$p = $conn->query("SELECT MESSAGE_ID, PARAM_VALUE FROM b_im_message_param
			WHERE PARAM_NAME='REPLY_ID' AND MESSAGE_ID IN (" . $idList . ")");
		while ($row = $p->fetch()) {
			$rid = (int)$row['PARAM_VALUE'];
			if ($rid > 0) {
				$replyBy[(int)$row['MESSAGE_ID']] = $rid;
			}
		}
	} catch (\Throwable $e) {
		/* ignore */
	}
	$loadIds = $ids;
	foreach ($replyBy as $rid) {
		$loadIds[] = $rid;
	}
	$loadIds = array_values(array_unique(array_filter($loadIds)));
	$filesByMessage = [];
	$fileNames = [];
	if ($loadIds) {
		try {
			$fileRows = $conn->query("SELECT MESSAGE_ID, PARAM_VALUE FROM b_im_message_param
				WHERE PARAM_NAME IN ('FILE_ID','FILE') AND MESSAGE_ID IN (" . implode(',', $loadIds) . ")");
			$allFileIds = [];
			while ($row = $fileRows->fetch()) {
				$messageId = (int)$row['MESSAGE_ID'];
				$fileIds = waCcQuoteSendFileIds($row['PARAM_VALUE'] ?? '');
				foreach ($fileIds as $fileId) {
					$filesByMessage[$messageId][$fileId] = $fileId;
					$allFileIds[$fileId] = $fileId;
				}
			}
			if ($allFileIds) {
				$objectRows = $conn->query("SELECT ID, NAME FROM b_disk_object WHERE ID IN (" .
					implode(',', array_map('intval', array_values($allFileIds))) . ")");
				while ($row = $objectRows->fetch()) {
					$fileNames[(int)$row['ID']] = (string)($row['NAME'] ?? '');
				}
			}
		} catch (\Throwable $e) {
			/* quote remains text-only */
		}
	}
	$out = [];
	if ($loadIds) {
		$res = $conn->query("SELECT ID, AUTHOR_ID, MESSAGE FROM b_im_message WHERE ID IN (" . implode(',', $loadIds) . ")");
		while ($row = $res->fetch()) {
			$id = (int)$row['ID'];
			$fileIds = isset($filesByMessage[$id]) ? array_values($filesByMessage[$id]) : [];
			$mediaKind = $fileIds ? waCcQuoteSendMediaKind($fileNames[$fileIds[0]] ?? '') : '';
			$text = waCcQuoteSendPreviewFromIm($row);
			if ($text === '' && $fileIds) {
				$text = $mediaKind === 'image' ? 'Фото' : ($mediaKind === 'video' ? 'Видео' :
					($mediaKind === 'audio' ? 'Голосовое сообщение' : 'Файл'));
			}
			$out[$id] = [
				'id' => $id,
				'authorId' => (int)$row['AUTHOR_ID'],
				'text' => $text,
				'replyId' => (int)($replyBy[$id] ?? 0),
				'fileIds' => $fileIds,
				'mediaKind' => $mediaKind,
			];
		}
	}
	foreach ($replyBy as $mid => $rid) {
		if (isset($out[$mid])) {
			$out[$mid]['replyId'] = $rid;
		}
	}
	waCcQuoteSendJson(['ok' => true, 'items' => $out]);
}

function waCcQuoteSendCollectFiles()
{
	$out = [];
	if (empty($_FILES['files']) || !is_array($_FILES['files'])) {
		return $out;
	}
	$f = $_FILES['files'];
	if (!isset($f['name'])) {
		return $out;
	}
	if (!is_array($f['name'])) {
		if ((int)($f['error'] ?? 0) === UPLOAD_ERR_OK && !empty($f['tmp_name']) && is_uploaded_file($f['tmp_name'])) {
			$out[] = [
				'tmp' => $f['tmp_name'],
				'name' => (string)$f['name'],
				'type' => (string)($f['type'] ?? ''),
			];
		}
		return $out;
	}
	foreach ($f['name'] as $i => $name) {
		if ((int)($f['error'][$i] ?? 1) !== UPLOAD_ERR_OK) {
			continue;
		}
		$tmp = (string)($f['tmp_name'][$i] ?? '');
		if ($tmp === '' || !is_uploaded_file($tmp)) {
			continue;
		}
		$out[] = [
			'tmp' => $tmp,
			'name' => (string)$name,
			'type' => (string)($f['type'][$i] ?? ''),
		];
	}
	return $out;
}

function waCcQuoteSendHandle()
{
	if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
		waCcQuoteSendJson(['ok' => false, 'error' => 'POST only'], 405);
	}
	if (!waCcQuoteSendAuthed()) {
		waCcQuoteSendJson(['ok' => false, 'error' => 'auth'], 401);
	}

	\Bitrix\Main\Loader::includeModule('im');

	$chatId = (int)($_POST['chatId'] ?? 0);
	$dialogId = (string)($_POST['dialogId'] ?? '');
	if ($chatId <= 0 && preg_match('/chat(\d+)/i', $dialogId, $m)) {
		$chatId = (int)$m[1];
	}
	$replyId = (int)($_POST['replyId'] ?? 0);
	$text = trim((string)($_POST['message'] ?? ''));
	$ptt = !empty($_POST['ptt']);
	$hint = (string)($_POST['quotedHint'] ?? '');
	$files = waCcQuoteSendCollectFiles();

	if ($chatId <= 0 || $replyId <= 0) {
		waCcQuoteSendJson(['ok' => false, 'fallback' => 'im', 'error' => 'bad_ids']);
	}
	if ($text === '' && !$files) {
		waCcQuoteSendJson(['ok' => false, 'fallback' => 'im', 'error' => 'empty']);
	}

	$chat = \Bitrix\Im\Model\ChatTable::getById($chatId)->fetch();
	if (!$chat) {
		waCcQuoteSendJson(['ok' => false, 'fallback' => 'im', 'error' => 'no_chat']);
	}
	[$lineId, $greenChat] = waCcQuoteSendParseEntity((string)($chat['ENTITY_ID'] ?? ''));
	if ($lineId <= 0 || $greenChat === '') {
		waCcQuoteSendJson(['ok' => false, 'fallback' => 'im', 'error' => 'not_wa']);
	}

	$imMsg = \Bitrix\Im\Model\MessageTable::getById($replyId)->fetch();
	if (!$imMsg || (int)$imMsg['CHAT_ID'] !== $chatId) {
		waCcQuoteSendJson(['ok' => false, 'fallback' => 'im', 'error' => 'reply_mismatch']);
	}

	$cred = waCcQuoteSendCred($lineId);
	if (!$cred) {
		waCcQuoteSendJson(['ok' => false, 'fallback' => 'im', 'error' => 'no_cred']);
	}

	$candidates = waCcQuoteSendResolveMids($replyId, $hint);
	$quotedId = '';
	foreach ($candidates as $cand) {
		if (waCcQuoteSendValidateMid($cred, $greenChat, $cand) !== false) {
			$quotedId = $cand;
			break;
		}
	}
	if ($quotedId === '' && $candidates) {
		$quotedId = $candidates[0];
	}
	if ($quotedId === '') {
		$quotedId = waCcQuoteSendGuessMid($cred, $greenChat, $imMsg);
	}
	if ($quotedId === '') {
		waCcQuoteSendJson(['ok' => false, 'fallback' => 'im', 'error' => 'no_quoted_id']);
	}

	$sent = [];
	if ($files) {
		foreach ($files as $i => $file) {
			$caption = ($i === 0) ? $text : '';
			$fields = [
				'chatId' => $greenChat,
				'fileName' => $file['name'] !== '' ? $file['name'] : ('file_' . ($i + 1)),
				'quotedMessageId' => $quotedId,
			];
			if ($caption !== '') {
				$fields['caption'] = $caption;
			}
			$method = ($ptt && $i === 0) ? 'sendPTTByUpload' : 'sendFileByUpload';
			[$code, $data] = waCcQuoteSendHttpFile(
				waCcQuoteSendEndpoint($cred, $method, true),
				$fields,
				$file['tmp'],
				$fields['fileName'],
				$file['type']
			);
			if ($method === 'sendPTTByUpload' && ($code < 200 || $code >= 300 || !is_array($data) || empty($data['idMessage']))) {
				[$code, $data] = waCcQuoteSendHttpFile(
					waCcQuoteSendEndpoint($cred, 'sendFileByUpload', true),
					$fields,
					$file['tmp'],
					$fields['fileName'],
					$file['type']
				);
			}
			if ($code < 200 || $code >= 300 || !is_array($data) || empty($data['idMessage'])) {
				if (!$sent) {
					waCcQuoteSendJson([
						'ok' => false,
						'fallback' => 'im',
						'error' => 'green_file',
						'http' => $code,
					]);
				}
				break;
			}
			$sent[] = (string)$data['idMessage'];
		}
	} else {
		[$code, $data] = waCcQuoteSendHttpJson(
			waCcQuoteSendEndpoint($cred, 'sendMessage'),
			[
				'chatId' => $greenChat,
				'message' => $text,
				'quotedMessageId' => $quotedId,
			]
		);
		if ($code < 200 || $code >= 300 || !is_array($data) || empty($data['idMessage'])) {
			waCcQuoteSendJson([
				'ok' => false,
				'fallback' => 'im',
				'error' => 'green_text',
				'http' => $code,
			]);
		}
		$sent[] = (string)$data['idMessage'];
	}

	/* Файлы дублирует в IM сам клиент (disk commit), тут только текст. */
	$imMessageId = 0;
	if (!$files) {
		$imMessageId = waCcQuoteSendEchoToIm($chatId, $text, $replyId, $sent[0] ?? '');
	}

	waCcQuoteSendJson([
		'ok' => true,
		'via' => 'green',
		'quotedMessageId' => $quotedId,
		'idMessage' => $sent[0] ?? '',
		'idMessages' => $sent,
		'imMessageId' => $imMessageId,
		'needsFileEcho' => $files ? 1 : 0,
		'reply' => [
			'id' => $replyId,
			'text' => waCcQuoteSendPreviewFromIm($imMsg),
		],
	]);
}
