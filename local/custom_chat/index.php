<?php
if (!empty($_GET['wa_ffmpeg'])) {
	$waFfmpegAssets = [
		'worker' => [
			'url' => 'https://cdn.jsdelivr.net/npm/@ffmpeg/ffmpeg@0.12.10/dist/umd/814.ffmpeg.js',
			'type' => 'text/javascript; charset=utf-8',
		],
		'core-js' => [
			'url' => 'https://cdn.jsdelivr.net/npm/@ffmpeg/core@0.12.10/dist/umd/ffmpeg-core.js',
			'type' => 'text/javascript; charset=utf-8',
		],
		'core-wasm' => [
			'url' => 'https://cdn.jsdelivr.net/npm/@ffmpeg/core@0.12.10/dist/umd/ffmpeg-core.wasm',
			'type' => 'application/wasm',
		],
	];
	$key = preg_replace('/[^a-z0-9_-]/', '', (string)$_GET['wa_ffmpeg']);
	if (!isset($waFfmpegAssets[$key])) {
		http_response_code(404);
		header('Content-Type: text/plain; charset=utf-8');
		echo 'Not found';
		exit;
	}
	$asset = $waFfmpegAssets[$key];
	$ctx = stream_context_create([
		'http' => ['timeout' => 120, 'follow_location' => 1],
		'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
	]);
	$data = @file_get_contents($asset['url'], false, $ctx);
	if ($data === false) {
		http_response_code(502);
		header('Content-Type: text/plain; charset=utf-8');
		echo 'Upstream fetch failed';
		exit;
	}
	header('Content-Type: ' . $asset['type']);
	header('Cache-Control: public, max-age=604800');
	header('Access-Control-Allow-Origin: *');
	echo $data;
	exit;
}

/**
 * WhatsApp PTT часто лежит как ogg/opus, а в b_file CONTENT_TYPE = image/* или octet-stream.
 */
function waCcMediaKindFromNameMime($mime, $fileName)
{
	$mime = strtolower((string)$mime);
	$name = strtolower((string)$fileName);
	$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
	if (preg_match('/^(mp3|ogg|oga|opus|wav|m4a|aac)$/', $ext) || strpos($mime, 'audio/') === 0 || preg_match('/voice|ptt|audio_message|голос/', $name)) {
		if (strpos($mime, 'audio/') !== 0) {
			$map = ['mp3' => 'audio/mpeg', 'ogg' => 'audio/ogg', 'oga' => 'audio/ogg', 'opus' => 'audio/ogg', 'wav' => 'audio/wav', 'm4a' => 'audio/mp4', 'aac' => 'audio/aac'];
			$mime = $map[$ext] ?? 'audio/ogg';
		}
		return ['kind' => 'audio', 'mime' => $mime];
	}
	if (preg_match('/^(mp4|mov|avi|mkv)$/', $ext) || strpos($mime, 'video/') === 0) {
		return ['kind' => 'video', 'mime' => $mime ?: 'video/mp4'];
	}
	if (preg_match('/^(jpe?g|png|gif|webp|bmp|heic|heif)$/', $ext) || strpos($mime, 'image/') === 0) {
		return ['kind' => 'image', 'mime' => $mime ?: 'image/jpeg'];
	}
	if ($ext === 'webm') {
		$kind = (strpos($mime, 'audio/') === 0 || preg_match('/voice|ptt|audio/', $name)) ? 'audio' : 'video';
		return ['kind' => $kind, 'mime' => $kind === 'audio' ? 'audio/webm' : 'video/webm'];
	}
	return ['kind' => 'file', 'mime' => $mime ?: 'application/octet-stream'];
}

function waCcSniffMediaKind($absPath, $declaredMime = '', $fileName = '')
{
	$fallback = waCcMediaKindFromNameMime($declaredMime, $fileName);
	if (!$absPath || !is_file($absPath)) {
		return $fallback;
	}
	$head = @file_get_contents($absPath, false, null, 0, 64);
	if ($head === false || $head === '') {
		return $fallback;
	}
	if (strncmp($head, 'OggS', 4) === 0) {
		return ['kind' => 'audio', 'mime' => 'audio/ogg'];
	}
	if (strncmp($head, 'ID3', 3) === 0) {
		return ['kind' => 'audio', 'mime' => 'audio/mpeg'];
	}
	if (strlen($head) >= 2 && ord($head[0]) === 0xFF && (ord($head[1]) & 0xE0) === 0xE0) {
		return ['kind' => 'audio', 'mime' => 'audio/mpeg'];
	}
	if (strncmp($head, 'RIFF', 4) === 0 && strlen($head) >= 12) {
		$sub = substr($head, 8, 4);
		if ($sub === 'WAVE') {
			return ['kind' => 'audio', 'mime' => 'audio/wav'];
		}
		if ($sub === 'WEBP') {
			return ['kind' => 'image', 'mime' => 'image/webp'];
		}
		if ($sub === 'AVI ') {
			return ['kind' => 'video', 'mime' => 'video/x-msvideo'];
		}
	}
	if (strlen($head) >= 12 && substr($head, 4, 4) === 'ftyp') {
		$brand = substr($head, 8, 4);
		if (preg_match('/^(M4A |M4B |mp4a)/', $brand)) {
			return ['kind' => 'audio', 'mime' => 'audio/mp4'];
		}
		return ['kind' => 'video', 'mime' => 'video/mp4'];
	}
	if (strncmp($head, "\x1A\x45\xDF\xA3", 4) === 0) {
		$kind = ($fallback['kind'] === 'audio') ? 'audio' : 'video';
		return ['kind' => $kind, 'mime' => $kind === 'audio' ? 'audio/webm' : 'video/webm'];
	}
	if (strlen($head) >= 3 && ord($head[0]) === 0xFF && ord($head[1]) === 0xD8 && ord($head[2]) === 0xFF) {
		return ['kind' => 'image', 'mime' => 'image/jpeg'];
	}
	if (strncmp($head, "\x89PNG", 4) === 0) {
		return ['kind' => 'image', 'mime' => 'image/png'];
	}
	if (strncmp($head, 'GIF8', 4) === 0) {
		return ['kind' => 'image', 'mime' => 'image/gif'];
	}
	return $fallback;
}

function waCcOggDurationSec($absPath)
{
	if (!$absPath || !is_file($absPath)) {
		return 0.0;
	}
	$size = filesize($absPath);
	if ($size < 27) {
		return 0.0;
	}
	$read = (int)min(65536, $size);
	$fh = @fopen($absPath, 'rb');
	if (!$fh) {
		return 0.0;
	}
	fseek($fh, $size - $read);
	$buf = fread($fh, $read);
	fclose($fh);
	if ($buf === false || $buf === '') {
		return 0.0;
	}
	$pos = strrpos($buf, 'OggS');
	if ($pos === false || ($pos + 14) > strlen($buf)) {
		return 0.0;
	}
	$lo = unpack('V', substr($buf, $pos + 6, 4));
	$hi = unpack('V', substr($buf, $pos + 10, 4));
	$granule = (float)($lo[1] ?? 0) + (float)($hi[1] ?? 0) * 4294967296.0;
	if ($granule <= 0) {
		return 0.0;
	}
	$sec = $granule / 48000.0;
	if ($sec > 3600) {
		$sec = $granule / 16000.0;
	}
	if ($sec <= 0 || $sec > 3600) {
		return 0.0;
	}
	return round($sec, 2);
}

function waCcResolveChatFile($fileId, $chatId = 0)
{
	$fileId = (int)$fileId;
	$chatId = (int)$chatId;
	if ($fileId <= 0) {
		return null;
	}

	$fileArray = null;
	$diskFileObj = null;

	if (\Bitrix\Main\Loader::includeModule('disk')) {
		try {
			$diskFile = \Bitrix\Disk\File::loadById($fileId);
			if ($diskFile) {
				$diskFileObj = $diskFile;
				$fileArray = $diskFile->getFile();
			}
		} catch (\Throwable $e) {
			$fileArray = null;
		}
	}

	if (!$fileArray && \Bitrix\Main\Loader::includeModule('im')) {
		try {
			$row = null;
			if (class_exists('\\Bitrix\\Im\\Model\\FileTable')) {
				$filter = ['=ID' => $fileId];
				if ($chatId > 0) {
					$filter['=CHAT_ID'] = $chatId;
				}
				$row = \Bitrix\Im\Model\FileTable::getList([
					'filter' => $filter,
					'limit' => 1,
				])->fetch();
				if (!$row && $chatId > 0) {
					$row = \Bitrix\Im\Model\FileTable::getList([
						'filter' => ['=ID' => $fileId],
						'limit' => 1,
					])->fetch();
				}
			}

			$diskId = 0;
			if ($row) {
				$diskId = (int)($row['DISK_FILE_ID'] ?? $row['DISK_ID'] ?? 0);
			}
			if ($diskId <= 0 && class_exists('\\CIMDisk') && method_exists('CIMDisk', 'GetFile')) {
				$imFile = \CIMDisk::GetFile($fileId);
				if (is_array($imFile)) {
					$diskId = (int)($imFile['DISK_FILE_ID'] ?? $imFile['disk_file_id'] ?? 0);
					if (!$fileArray && !empty($imFile['FILE'])) {
						$fileArray = $imFile['FILE'];
					}
				}
			}
			if ($diskId > 0 && \Bitrix\Main\Loader::includeModule('disk')) {
				$diskFile = \Bitrix\Disk\File::loadById($diskId);
				if ($diskFile) {
					$diskFileObj = $diskFile;
					$fileArray = $diskFile->getFile();
				}
			}
		} catch (\Throwable $e) {
			/* continue */
		}
	}

	if (!$fileArray) {
		try {
			$candidate = \CFile::GetFileArray($fileId);
			if (is_array($candidate) && !empty($candidate['SRC'])) {
				$fileArray = $candidate;
			}
		} catch (\Throwable $e) {
			$fileArray = null;
		}
	}

	if (!is_array($fileArray) || empty($fileArray['ID'])) {
		return null;
	}

	$filePath = \CFile::GetPath($fileArray['ID']);
	$absPath = $filePath ? ($_SERVER['DOCUMENT_ROOT'] . $filePath) : '';
	if ((!$absPath || !is_file($absPath)) && !empty($fileArray['SRC'])) {
		$absPath = $_SERVER['DOCUMENT_ROOT'] . $fileArray['SRC'];
	}

	$fileName = $fileArray['ORIGINAL_NAME'] ?? $fileArray['FILE_NAME'] ?? 'file';
	if ($diskFileObj) {
		try {
			$diskName = (string)$diskFileObj->getName();
			if ($diskName !== '') {
				$fileName = $diskName;
			}
		} catch (\Throwable $e) {
			/* keep b_file name */
		}
	}

	return [
		'file' => $fileArray,
		'diskFile' => $diskFileObj,
		'absPath' => $absPath,
		'name' => $fileName,
		'mime' => $fileArray['CONTENT_TYPE'] ?? 'application/octet-stream',
	];
}

function waCcStreamMediaFile($absPath, $mime, $fileName, $kind, $duration = 0)
{
	$size = filesize($absPath);
	$start = 0;
	$end = $size - 1;
	$code = 200;
	$range = isset($_SERVER['HTTP_RANGE']) ? (string)$_SERVER['HTTP_RANGE'] : '';
	if ($range !== '' && preg_match('/bytes=(\d*)-(\d*)/', $range, $m)) {
		if ($m[1] === '' && $m[2] !== '') {
			$start = max(0, $size - (int)$m[2]);
			$end = $size - 1;
		} else {
			if ($m[1] !== '') {
				$start = (int)$m[1];
			}
			if ($m[2] !== '') {
				$end = (int)$m[2];
			}
		}
		if ($end >= $size) {
			$end = $size - 1;
		}
		if ($start > $end || $start < 0) {
			http_response_code(416);
			header('Content-Range: bytes */' . $size);
			exit;
		}
		$code = 206;
	}
	$length = $end - $start + 1;
	http_response_code($code);
	if (!headers_sent()) {
		header_remove('Content-Type');
	}
	header('Content-Type: ' . $mime);
	header('X-WA-Media-Kind: ' . $kind);
	if ($duration > 0) {
		header('X-WA-Duration: ' . $duration);
	}
	header('Accept-Ranges: bytes');
	header('Content-Length: ' . $length);
	if ($code === 206) {
		header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
	}
	header('Cache-Control: private, max-age=3600');
	header('Content-Disposition: inline; filename="' . rawurlencode($fileName) . '"');
	if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
		exit;
	}
	$fp = fopen($absPath, 'rb');
	if ($fp === false) {
		http_response_code(500);
		exit;
	}
	if ($start > 0) {
		fseek($fp, $start);
	}
	$left = $length;
	while ($left > 0 && !feof($fp)) {
		$chunk = fread($fp, min(8192, $left));
		if ($chunk === false || $chunk === '') {
			break;
		}
		echo $chunk;
		$left -= strlen($chunk);
	}
	fclose($fp);
	exit;
}

function waCcFfmpegPath()
{
	$local = __DIR__ . '/bin/ffmpeg';
	if (is_file($local) && is_executable($local)) {
		return $local;
	}
	$which = trim((string)@shell_exec('command -v ffmpeg 2>/dev/null'));
	return $which !== '' ? $which : '';
}

function waCcAudioCacheDir()
{
	$dir = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\') . '/upload/wa_cc_audio_cache';
	if ($dir !== '/upload/wa_cc_audio_cache' && !is_dir($dir)) {
		@mkdir($dir, 0775, true);
	}
	return $dir;
}

function waCcWantMobileAudioMp3()
{
	$fmt = strtolower((string)($_GET['fmt'] ?? ''));
	if ($fmt === 'mp3' || $fmt === 'mpeg') {
		return true;
	}
	if ($fmt === 'orig' || $fmt === 'ogg') {
		return false;
	}
	$ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
	return (bool)preg_match('/BitrixMobile|BXMobileApp|Bitrix24\.Mobile|Android|iPhone|iPad/i', $ua);
}

function waCcNeedsMp3Transcode($absPath, $mime, $fileName)
{
	$mime = strtolower((string)$mime);
	$ext = strtolower(pathinfo((string)$fileName, PATHINFO_EXTENSION));
	if (preg_match('/^(mp3|m4a|aac|wav)$/', $ext)) {
		return false;
	}
	if (strpos($mime, 'mpeg') !== false || strpos($mime, 'mp4') !== false || strpos($mime, 'aac') !== false || strpos($mime, 'wav') !== false) {
		return false;
	}
	return (bool)(
		preg_match('/^(ogg|oga|opus|webm)$/', $ext)
		|| strpos($mime, 'ogg') !== false
		|| strpos($mime, 'opus') !== false
		|| strpos($mime, 'webm') !== false
	);
}

/**
 * BitrixMobile WebView не играет WhatsApp ogg/opus → кэш mp3.
 * @return string|null abs path to mp3
 */
function waCcTranscodeAudioToMp3($absPath, $fileId)
{
	$ffmpeg = waCcFfmpegPath();
	if ($ffmpeg === '' || !is_file($absPath)) {
		return null;
	}
	$fileId = (int)$fileId;
	$mtime = (int)@filemtime($absPath);
	$dir = waCcAudioCacheDir();
	if (!is_dir($dir)) {
		return null;
	}
	$cache = $dir . '/f' . $fileId . '_' . $mtime . '.mp3';
	if (is_file($cache) && filesize($cache) > 64) {
		return $cache;
	}
	$tmp = $cache . '.part.mp3';
	$cmd = escapeshellarg($ffmpeg)
		. ' -hide_banner -loglevel error -y -i ' . escapeshellarg($absPath)
		. ' -vn -acodec libmp3lame -aq 4 '
		. escapeshellarg($tmp);
	@exec($cmd, $out, $code);
	if ((int)$code !== 0 || !is_file($tmp) || filesize($tmp) <= 64) {
		@unlink($tmp);
		return null;
	}
	@rename($tmp, $cache);
	@unlink($tmp);
	return is_file($cache) ? $cache : null;
}

if (isset($_GET['wa_ticks'])) {
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
}

if (isset($_GET['wa_bulk_zip'])) {
	define('NO_KEEP_STATISTIC', true);
	define('NOT_CHECK_PERMISSIONS', true);
	define('BX_SECURITY_SHOW_MESSAGE', false);
	require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

	global $USER;
	if ((!$USER || !$USER->IsAuthorized()) && empty($GLOBALS['WA_CC_MEDIA_AUTHED']) && empty($GLOBALS['WA_CC_MEDIA_AUTHED'])) {
		http_response_code(401);
		header('Content-Type: text/plain; charset=utf-8');
		echo 'Auth required';
		exit;
	}

	if (!class_exists('ZipArchive')) {
		http_response_code(500);
		header('Content-Type: text/plain; charset=utf-8');
		echo 'ZipArchive not available';
		exit;
	}

	$chatId = (int)($_GET['chat'] ?? 0);
	$rawIds = (string)($_GET['ids'] ?? '');
	$fileIds = preg_split('/[^\d]+/', $rawIds);
	$fileIds = array_values(array_unique(array_filter(array_map('intval', $fileIds))));
	if (!$fileIds) {
		http_response_code(400);
		header('Content-Type: text/plain; charset=utf-8');
		echo 'No files';
		exit;
	}

	$tmpZip = tempnam(sys_get_temp_dir(), 'wa_cc_zip_');
	if (!$tmpZip) {
		http_response_code(500);
		header('Content-Type: text/plain; charset=utf-8');
		echo 'Temp file error';
		exit;
	}
	@unlink($tmpZip);
	$tmpZip .= '.zip';

	$zip = new \ZipArchive();
	if ($zip->open($tmpZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
		http_response_code(500);
		header('Content-Type: text/plain; charset=utf-8');
		echo 'Zip open failed';
		exit;
	}

	$usedNames = [];
	foreach ($fileIds as $fileId) {
		$resolved = waCcResolveChatFile($fileId, $chatId);
		if (!$resolved || empty($resolved['absPath']) || !is_file($resolved['absPath'])) {
			continue;
		}
		$baseName = trim((string)$resolved['name']);
		if ($baseName === '') {
			$baseName = 'file_' . $fileId;
		}
		$name = $baseName;
		$dot = strrpos($baseName, '.');
		$stem = $dot !== false ? substr($baseName, 0, $dot) : $baseName;
		$ext = $dot !== false ? substr($baseName, $dot) : '';
		$i = 2;
		while (isset($usedNames[mb_strtolower($name)])) {
			$name = $stem . ' (' . $i . ')' . $ext;
			$i++;
		}
		$usedNames[mb_strtolower($name)] = true;
		$zip->addFile($resolved['absPath'], $name);
	}
	$zip->close();

	if (!is_file($tmpZip) || filesize($tmpZip) <= 0) {
		@unlink($tmpZip);
		http_response_code(404);
		header('Content-Type: text/plain; charset=utf-8');
		echo 'Files not found';
		exit;
	}

	$downloadName = 'wa_files_' . date('Ymd_His') . '.zip';
	header('Content-Type: application/zip');
	header('Content-Disposition: attachment; filename="' . rawurlencode($downloadName) . '"');
	header('Content-Length: ' . filesize($tmpZip));
	header('Cache-Control: no-store');
	readfile($tmpZip);
	@unlink($tmpZip);
	exit;
}

/**
 * Нативная WA-цитата: POST ?wa_quote_send=1 → Green API sendMessage/sendFileByUpload.
 * Без im.message.add (иначе дубль через коннектор).
 */
if (isset($_GET['wa_quote_send'])) {
	define('NO_KEEP_STATISTIC', true);
	define('NOT_CHECK_PERMISSIONS', true);
	define('BX_SECURITY_SHOW_MESSAGE', false);
	require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
	require_once __DIR__ . '/include_wa_quote_send.php';
	waCcQuoteSendHandle();
	exit;
}

if (isset($_GET['wa_msg_meta'])) {
	define('NO_KEEP_STATISTIC', true);
	define('NOT_CHECK_PERMISSIONS', true);
	define('BX_SECURITY_SHOW_MESSAGE', false);
	require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
	require_once __DIR__ . '/include_wa_quote_send.php';
	waCcQuoteSendMetaHandle();
	exit;
}

if (isset($_GET['wa_quote_link'])) {
	define('NO_KEEP_STATISTIC', true);
	define('NOT_CHECK_PERMISSIONS', true);
	define('BX_SECURITY_SHOW_MESSAGE', false);
	require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
	require_once __DIR__ . '/include_wa_quote_send.php';
	waCcQuoteLinkHandle();
	exit;
}

/**
 * Прокси медиафайлов чата через сессию портала.
 * REST im.v2.File.download / disk.file.get часто 400/401 — обходим.
 */
if (!empty($_GET['wa_media'])) {
	define('NO_KEEP_STATISTIC', true);
	define('NOT_CHECK_PERMISSIONS', true);
	define('BX_SECURITY_SHOW_MESSAGE', false);
	require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

	global $USER;
	$origin = isset($_SERVER['HTTP_ORIGIN']) ? (string)$_SERVER['HTTP_ORIGIN'] : '';
	$allowOrigins = [
		'https://bitrixeazy.vercel.app',
		'https://crm.artflowers.kz',
	];
	if ($origin && (in_array($origin, $allowOrigins, true) || preg_match('#^https://[\w-]+\.vercel\.app$#', $origin))) {
		header('Access-Control-Allow-Origin: ' . $origin);
		header('Access-Control-Allow-Credentials: true');
		header('Vary: Origin');
	}
	if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
		header('Access-Control-Allow-Methods: GET, OPTIONS');
		header('Access-Control-Allow-Headers: Content-Type');
		http_response_code(204);
		exit;
	}

	if (!$USER || !$USER->IsAuthorized()) {
		$mediaAuthed = !empty($GLOBALS['WA_CC_MEDIA_AUTHED']);
		if (!$mediaAuthed) {
			http_response_code(401);
			header('Content-Type: text/plain; charset=utf-8');
			echo 'Auth required';
			exit;
		}
	}

	$fileId = (int)$_GET['wa_media'];
	$chatId = (int)($_GET['chat'] ?? 0);
	if ($fileId <= 0) {
		http_response_code(400);
		header('Content-Type: text/plain; charset=utf-8');
		echo 'Bad file id';
		exit;
	}
	$resolved = waCcResolveChatFile($fileId, $chatId);
	if (!$resolved || !is_array($resolved['file'] ?? null) || empty($resolved['file']['ID'])) {
		http_response_code(404);
		header('Content-Type: text/plain; charset=utf-8');
		echo 'File not found';
		exit;
	}
	$fileArray = $resolved['file'];
	$diskFileObj = $resolved['diskFile'];

	// Прямой стрим надёжнее ViewByUser для <img>/<audio> (нет лишних редиректов)
	$absPath = $resolved['absPath'];
	$fileName = $resolved['name'];
	$declaredMime = $resolved['mime'];
	$sniff = waCcSniffMediaKind($absPath, $declaredMime, $fileName);
	$sniff['name'] = $fileName;
	$sniff['ext'] = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
	$duration = 0.0;
	if ($sniff['kind'] === 'audio' && $absPath && is_file($absPath)) {
		$duration = waCcOggDurationSec($absPath);
	}
	$sniff['duration'] = $duration;

	if (!empty($_GET['kind'])) {
		header('Content-Type: application/json; charset=utf-8');
		header('Cache-Control: private, max-age=3600');
		echo json_encode($sniff);
		exit;
	}

	if ($absPath && is_file($absPath)) {
		$forceDownload = !empty($_GET['download']) || !empty($_GET['dl']);
		if (
			$sniff['kind'] === 'audio'
			&& waCcWantMobileAudioMp3()
			&& waCcNeedsMp3Transcode($absPath, $sniff['mime'], $fileName)
		) {
			$mp3 = waCcTranscodeAudioToMp3($absPath, $fileId);
			if ($mp3) {
				$mp3Name = preg_replace('/\.[^.]+$/', '', $fileName) . '.mp3';
				if ($forceDownload) {
					header('Content-Type: audio/mpeg');
					header('Content-Disposition: attachment; filename="' . rawurlencode($mp3Name) . '"');
					header('Content-Length: ' . filesize($mp3));
					header('Cache-Control: private, max-age=3600');
					readfile($mp3);
					exit;
				}
				waCcStreamMediaFile($mp3, 'audio/mpeg', $mp3Name, 'audio', $duration);
			}
		}
		if ($forceDownload) {
			header('Content-Type: ' . $sniff['mime']);
			header('Content-Disposition: attachment; filename="' . rawurlencode($fileName) . '"');
			header('Content-Length: ' . filesize($absPath));
			header('Cache-Control: private, max-age=3600');
			readfile($absPath);
			exit;
		}
		waCcStreamMediaFile($absPath, $sniff['mime'], $fileName, $sniff['kind'], $duration);
	}

	header('Cache-Control: private, max-age=3600');
	\CFile::ViewByUser($fileArray, [
		'force_download' => false,
		'cache_time' => 3600,
		'attachment_name' => $fileArray['ORIGINAL_NAME'] ?? $fileArray['FILE_NAME'] ?? 'file',
	]);
	exit;
}

/**
 * Сессия ОЛ ↔ CHAT_ID (ASSOCIATED_ENTITY_ID в CRM часто = SESSION_ID).
 * REST imopenlines.dialog.get(SESSION_ID) на коробке может отдавать 400.
 */
if (isset($_GET['wa_resolve_ol'])) {
	define('NO_KEEP_STATISTIC', true);
	define('NOT_CHECK_PERMISSIONS', true);
	require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

	global $USER;
	header('Content-Type: application/json; charset=utf-8');
	if (!$USER || !$USER->IsAuthorized()) {
		http_response_code(401);
		echo json_encode(['error' => 'auth']);
		exit;
	}

	$rawId = (int)$_GET['wa_resolve_ol'];
	$out = [
		'rawId' => $rawId,
		'chatId' => 0,
		'sessionId' => 0,
		'userCode' => '',
		'title' => '',
		'configId' => 0,
		'closed' => false,
	];
	if ($rawId <= 0) {
		echo json_encode($out);
		exit;
	}

	try {
		\Bitrix\Main\Loader::includeModule('im');
		\Bitrix\Main\Loader::includeModule('imopenlines');
	} catch (\Throwable $e) {
		echo json_encode($out);
		exit;
	}

	$session = null;
	$chat = null;
	try {
		if (class_exists('\Bitrix\ImOpenLines\Model\SessionTable')) {
			$session = \Bitrix\ImOpenLines\Model\SessionTable::getById($rawId)->fetch();
			if (!$session) {
				$session = \Bitrix\ImOpenLines\Model\SessionTable::getList([
					'filter' => ['=CHAT_ID' => $rawId],
					'order' => ['ID' => 'DESC'],
					'limit' => 1,
				])->fetch();
			}
		}
	} catch (\Throwable $e) {
		$session = null;
	}

	if (is_array($session)) {
		$out['sessionId'] = (int)($session['ID'] ?? 0);
		$out['chatId'] = (int)($session['CHAT_ID'] ?? 0);
		$out['userCode'] = (string)($session['USER_CODE'] ?? '');
		$out['configId'] = (int)($session['CONFIG_ID'] ?? 0);
		$status = (int)($session['STATUS'] ?? 0);
		$out['closed'] = !empty($session['CLOSED']) && $session['CLOSED'] !== 'N'
			|| in_array($status, [50, 60, 70, 80], true);
	}

	$chatId = (int)$out['chatId'] ?: $rawId;
	try {
		if (class_exists('\Bitrix\Im\Model\ChatTable')) {
			$chat = \Bitrix\Im\Model\ChatTable::getById($chatId)->fetch();
			if (!$chat && $rawId !== $chatId) {
				$chat = \Bitrix\Im\Model\ChatTable::getById($rawId)->fetch();
			}
		}
	} catch (\Throwable $e) {
		$chat = null;
	}

	if (is_array($chat)) {
		$out['chatId'] = (int)($chat['ID'] ?? $chatId);
		$out['title'] = (string)($chat['TITLE'] ?? '');
		if ($out['userCode'] === '' && !empty($chat['ENTITY_ID'])) {
			$out['userCode'] = (string)$chat['ENTITY_ID'];
		}
	} elseif ($out['chatId'] <= 0) {
		$out['chatId'] = $rawId;
	}

	echo json_encode($out, JSON_UNESCAPED_UNICODE);
	exit;
}

if (isset($_GET['wa_resolve_uc'])) {
	define('NO_KEEP_STATISTIC', true);
	define('NOT_CHECK_PERMISSIONS', true);
	require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

	global $USER;
	header('Content-Type: application/json; charset=utf-8');
	if (!$USER || !$USER->IsAuthorized()) {
		http_response_code(401);
		echo json_encode(['error' => 'auth']);
		exit;
	}

	$userCode = trim((string)$_GET['wa_resolve_uc']);
	if ($userCode === '') {
		echo json_encode(['chatId' => 0, 'title' => '', 'entity_id' => '']);
		exit;
	}

	try {
		\Bitrix\Main\Loader::includeModule('imopenlines');
	} catch (\Throwable $e) {
		echo json_encode(['chatId' => 0, 'title' => '', 'entity_id' => '']);
		exit;
	}

	try {
		$data = \CRest::call('imopenlines.dialog.get', ['USER_CODE' => $userCode]);
		$dialog = is_array($data) ? (($data['result'] ?? null) ?: $data) : [];
		echo json_encode([
			'chatId' => (int)($dialog['id'] ?? 0),
			'title' => (string)($dialog['name'] ?? $dialog['title'] ?? ''),
			'entity_id' => (string)($dialog['entity_id'] ?? ''),
		], JSON_UNESCAPED_UNICODE);
		exit;
	} catch (\Throwable $e) {
		echo json_encode(['chatId' => 0, 'title' => '', 'entity_id' => '']);
		exit;
	}
}

function waCcGroupTitlePickCandidate($value)
{
	$s = trim((string)$value);
	if ($s === '') return '';
	if (preg_match('/@g\.us|@c\.us|green-?api|whatsapp|documentmessage|imagemessage|videomessage|audiomessage/i', $s)) return '';
	if (preg_match('/^\+?\d[\d\s\-()]{7,}$/', $s)) return '';
	if (mb_strlen($s) < 3) return '';
	return $s;
}

function waCcGroupTitleCollectFromSettings($node, array &$out)
{
	if (is_array($node)) {
		foreach ($node as $k => $v) {
			$key = strtolower((string)$k);
			if (is_scalar($v) && preg_match('/(?:^|_)(groupname|group_name|chatname|chat_name|title|subject|name)$/i', $key)) {
				$candidate = waCcGroupTitlePickCandidate($v);
				if ($candidate !== '') $out[] = $candidate;
			}
			waCcGroupTitleCollectFromSettings($v, $out);
		}
	}
}

function waCcGroupTitleFromActivities($chatId)
{
	$chatId = (int)$chatId;
	if ($chatId <= 0) return '';
	try {
		\Bitrix\Main\Loader::includeModule('crm');
	} catch (\Throwable $e) {
		return '';
	}

	$candidates = [];
	try {
		$res = \CCrmActivity::GetList(
			['ID' => 'DESC'],
			[
				'ASSOCIATED_ENTITY_ID' => $chatId,
				'CHECK_PERMISSIONS' => 'N',
			],
			false,
			['nTopCount' => 40],
			['ID', 'SUBJECT', 'SETTINGS']
		);
		while ($act = $res->Fetch()) {
			$subject = waCcGroupTitlePickCandidate($act['SUBJECT'] ?? '');
			if ($subject !== '') $candidates[] = $subject;
			$settings = $act['SETTINGS'] ?? null;
			if (is_string($settings) && $settings !== '') {
				$decoded = json_decode($settings, true);
				if (!is_array($decoded)) {
					$decoded = @unserialize($settings);
				}
				if (is_array($decoded)) {
					$settings = $decoded;
				}
			}
			if (is_array($settings)) {
				waCcGroupTitleCollectFromSettings($settings, $candidates);
			}
		}
	} catch (\Throwable $e) {
		return '';
	}

	foreach ($candidates as $candidate) {
		if (preg_match('/[-–—]/u', $candidate)) {
			$parts = preg_split('/\s*[-–—]\s*/u', $candidate);
			foreach ($parts as $part) {
				$part = waCcGroupTitlePickCandidate($part);
				if ($part !== '') return $part;
			}
		}
		$clean = waCcGroupTitlePickCandidate($candidate);
		if ($clean !== '') return $clean;
	}
	return '';
}

if (isset($_GET['wa_group_title'])) {
	define('NO_KEEP_STATISTIC', true);
	define('NOT_CHECK_PERMISSIONS', true);
	require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
	global $USER;
	header('Content-Type: application/json; charset=utf-8');
	if (!$USER || !$USER->IsAuthorized()) {
		http_response_code(401);
		echo json_encode(['error' => 'auth']);
		exit;
	}
	require_once __DIR__ . '/app/wa_group_titles.php';
	$chatId = (int)($_GET['chat'] ?? 0);
	$groupId = trim((string)($_GET['group'] ?? $_GET['groupId'] ?? ''));
	$title = '';
	$resolvedGroupId = '';
	if ($groupId !== '') {
		$resolvedGroupId = waCcGroupTitlesNormalizeId($groupId);
		$title = waCcGroupTitlesGet($resolvedGroupId);
		if ($title === '' && $resolvedGroupId !== '') {
			$title = waCcGroupTitlesFetchFromGreenApi($resolvedGroupId);
			if ($title !== '') {
				waCcGroupTitlesSet($resolvedGroupId, $title, ['source' => 'getGroupData']);
			}
		}
	} elseif ($chatId > 0) {
		$fromChat = waCcGroupTitlesFromChatId($chatId);
		$resolvedGroupId = (string)($fromChat['groupId'] ?? '');
		$title = (string)($fromChat['title'] ?? '');
		if ($title === '') {
			$title = waCcGroupTitleFromActivities($chatId);
		}
	}
	echo json_encode([
		'title' => $title,
		'groupId' => $resolvedGroupId,
	], JSON_UNESCAPED_UNICODE);
	exit;
}

$waEmbed = isset($_GET['wa_embed']) && (string)$_GET['wa_embed'] === '1';
$waMobile = isset($_GET['wa_mobile']) && (string)$_GET['wa_mobile'] === '1';
$waTok = isset($_GET['wa_tok']) ? (string)$_GET['wa_tok'] : '';
// deeplink из карточки сделки/лида на телефоне — сразу экран чата
$waDealLeadDeep = (!empty($_GET['dealId']) || !empty($_GET['leadId']));
$waCrmLeadId = (int)($_GET['leadId'] ?? $_GET['LEAD_ID'] ?? 0);
$waCrmDealId = (int)($_GET['dealId'] ?? $_GET['DEAL_ID'] ?? 0);
if ($waCrmLeadId <= 0 && !empty($GLOBALS['WA_CC_CRM_LEAD_ID'])) {
	$waCrmLeadId = (int)$GLOBALS['WA_CC_CRM_LEAD_ID'];
}
if ($waCrmDealId <= 0 && !empty($GLOBALS['WA_CC_CRM_DEAL_ID'])) {
	$waCrmDealId = (int)$GLOBALS['WA_CC_CRM_DEAL_ID'];
}

if ($waEmbed) {
	// Вложенный iframe / локальное app: без шаблона Bitrix24 (иначе SearchTitle / frame-bust)
	$waNoProlog = defined('WA_CC_MOBILE_NOPROLOG')
		|| (isset($_GET['wa_noprolog']) && (string)$_GET['wa_noprolog'] === '1')
		|| (isset($_REQUEST['wa_noprolog']) && (string)$_REQUEST['wa_noprolog'] === '1');
	$waAid = (string)($_GET['wa_aid'] ?? $_REQUEST['wa_aid'] ?? '');
	$currentUserId = 0;
	$currentUserName = '';

	if ($waNoProlog) {
		// BitrixMobile WebView: prolog = белый экран. Юзера берём из tok / GLOBALS.
		$tokFile = $_SERVER['DOCUMENT_ROOT'] . '/local/custom_chat/app/shell.php';
		$authFile = $_SERVER['DOCUMENT_ROOT'] . '/local/custom_chat/app/auth.php';
		if ($waTok !== '' && is_file($tokFile)) {
			require_once $tokFile;
			$currentUserId = (int)waCcAppConsumeToken($waTok);
		}
		if ($currentUserId <= 0 && !empty($GLOBALS['WA_CC_FORCED_USER_ID'])) {
			$currentUserId = (int)$GLOBALS['WA_CC_FORCED_USER_ID'];
		}
		if ($waAid === '' && !empty($GLOBALS['WA_CC_AID'])) {
			$waAid = (string)$GLOBALS['WA_CC_AID'];
		}
		if ($currentUserId <= 0 && $waAid !== '' && is_file($authFile)) {
			require_once $authFile;
			$_REQUEST['AUTH_ID'] = $waAid;
			$_REQUEST['auth'] = $waAid;
			$resolved = waCcAppResolveUserIdNoProlog();
			if (!empty($resolved['ok'])) {
				$currentUserId = (int)$resolved['userId'];
			}
		}
		if ($currentUserId <= 0) {
			http_response_code(200);
			header('Content-Type: text/html; charset=utf-8');
			echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
				. '<title>WhatsApp</title></head><body style="font:15px system-ui;padding:20px;background:#fff3cd">'
				. '<b>Auth required (noprolog)</b><br>Нет userId из wa_tok.'
				. '</body></html>';
			die();
		}
		$currentUserName = '';
		if ($waAid !== '' && is_file($authFile)) {
			require_once $authFile;
			$domain = (string)($_GET['DOMAIN'] ?? $_REQUEST['DOMAIN'] ?? $_SERVER['HTTP_HOST'] ?? '');
			$domain = preg_replace('#^https?://#i', '', $domain);
			$domain = preg_replace('/:(443|80)$/', '', $domain);
			$res = waCcAppRestCallAuth('https', $domain, $waAid, 'user.current');
			if (!empty($res['ok']) && is_array($res['result'])) {
				$u = $res['result'];
				if ($currentUserId <= 0) {
					$currentUserId = (int)($u['ID'] ?? $u['id'] ?? 0);
				}
				$currentUserName = trim(implode(' ', array_filter([
					(string)($u['NAME'] ?? ''),
					(string)($u['LAST_NAME'] ?? ''),
				])));
				if ($currentUserName === '') {
					$currentUserName = trim((string)($u['LOGIN'] ?? $u['EMAIL'] ?? ''));
				}
			}
		}
		if ($currentUserName === '') {
			$currentUserName = 'User #' . $currentUserId;
		}
		$APPLICATION = null;
		$USER = null;
	} else {
		if (!defined('B_PROLOG_INCLUDED')) {
			if (!defined('NO_KEEP_STATISTIC')) {
				define('NO_KEEP_STATISTIC', true);
			}
			if (!defined('NO_AGENT_CHECK')) {
				define('NO_AGENT_CHECK', true);
			}
			if (!defined('NOT_CHECK_PERMISSIONS')) {
				define('NOT_CHECK_PERMISSIONS', false);
			}
			require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
		}

		global $USER, $APPLICATION;

		// one-time token из app/shell (+ запасной AUTH_ID)
		if (!$USER || !$USER->IsAuthorized()) {
			$tokFile = $_SERVER['DOCUMENT_ROOT'] . '/local/custom_chat/app/shell.php';
			$authFile = $_SERVER['DOCUMENT_ROOT'] . '/local/custom_chat/app/auth.php';
			if ($waTok !== '' && is_file($tokFile)) {
				require_once $tokFile;
				$tokUserId = waCcAppConsumeToken($waTok);
				if ($tokUserId > 0 && is_object($USER) && method_exists($USER, 'Authorize')) {
					try {
						$USER->Authorize($tokUserId);
					} catch (\Throwable $e) {
						/* ignore */
					}
				}
			}
			if ((!$USER || !$USER->IsAuthorized()) && is_file($authFile)) {
				require_once $authFile;
				$aid = (string)($_GET['wa_aid'] ?? $_REQUEST['wa_aid'] ?? $_REQUEST['AUTH_ID'] ?? '');
				if ($aid !== '' && function_exists('waCcAppRestCallAuth')) {
					$domain = (string)($_GET['DOMAIN'] ?? $_REQUEST['DOMAIN'] ?? $_SERVER['HTTP_HOST'] ?? '');
					$domain = preg_replace('#^https?://#i', '', $domain);
					$domain = preg_replace('/:(443|80)$/', '', $domain);
					$scheme = 'https';
					$res = waCcAppRestCallAuth($scheme, $domain, $aid, 'user.current');
					$uid = 0;
					if (!empty($res['ok']) && is_array($res['result'])) {
						$uid = (int)($res['result']['ID'] ?? $res['result']['id'] ?? 0);
					}
					if ($uid > 0 && is_object($USER) && method_exists($USER, 'Authorize')) {
						try {
							$USER->Authorize($uid);
						} catch (\Throwable $e) {
							/* ignore */
						}
					}
				}
			}
		}

		if (!$USER || !$USER->IsAuthorized()) {
			http_response_code(200);
			header('Content-Type: text/html; charset=utf-8');
			echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
				. '<title>WhatsApp</title></head><body style="font:15px system-ui;padding:20px;background:#fff3cd">'
				. '<b>Auth required</b><br>Нет сессии портала / битый wa_tok. Открой WhatsApp чат из меню приложения Bitrix24.'
				. '<pre style="margin-top:12px;font-size:11px;white-space:pre-wrap">'
				. htmlspecialchars(json_encode([
					'has_tok' => $waTok !== '',
					'has_aid' => !empty($_GET['wa_aid']) || !empty($_REQUEST['AUTH_ID']),
					'ua' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 160),
				], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
				. '</pre></body></html>';
			die();
		}

		if (class_exists('\\Bitrix\\Main\\Context')) {
			try {
				$resp = \Bitrix\Main\Context::getCurrent()->getResponse();
				$headers = $resp->getHeaders();
				$headers->delete('X-Frame-Options');
				$headers->set(
					'Content-Security-Policy',
					"frame-ancestors 'self' https://crm.artflowers.kz https://bitrixeazy.vercel.app https://*.vercel.app"
				);
			} catch (\Throwable $e) {
				/* ignore */
			}
		}

		$currentUserId = (int)$USER->GetID();
		if (method_exists($USER, 'GetFormattedName')) {
			$currentUserName = trim((string)$USER->GetFormattedName());
		}
		if ($currentUserName === '') {
			$currentUserName = trim((string)$USER->GetFullName());
		}
		if ($currentUserName === '') {
			$currentUserName = trim((string)$USER->GetLogin());
		}
	}

	if (!headers_sent()) {
		header('Content-Type: text/html; charset=UTF-8');
	}

	// На mobile/app НЕ вызываем ShowHead/Extension — frame-bust → белый экран в WebView
	$waLiteHead = $waMobile || $waTok !== '' || $waNoProlog || defined('WA_CC_APP_BOOT');
	if (!$waLiteHead) {
		if (class_exists('\\Bitrix\\Main\\UI\\Extension')) {
			try {
				\Bitrix\Main\UI\Extension::load(['main.core', 'rest.client', 'pull.client', 'ui.notification']);
			} catch (\Throwable $e) {
				/* ignore */
			}
		}
		CJSCore::Init(['ajax', 'rest', 'pull', 'popup']);
	}
	$bodyClass = 'wa-cc-embed';
	$waDesktop = isset($_GET['wa_desktop']) && (string)$_GET['wa_desktop'] === '1';
	if ($waMobile) {
		$bodyClass .= ' wa-cc-mobile';
	} elseif ($waDesktop || (!$waMobile && defined('WA_CC_APP_BOOT'))) {
		$bodyClass .= ' wa-cc-desktop';
	} elseif (!$waMobile && $waTok === '') {
		/* обычный embed без флага — desktop split */
		$bodyClass .= ' wa-cc-desktop';
	}
	if ($waMobile && $waDealLeadDeep) {
		$bodyClass .= ' wa-chat-open';
	}
	$sessId = function_exists('bitrix_sessid') ? bitrix_sessid() : '';
	// App include (/local/custom_chat/app/) не меняет location.search — прокидываем флаги в JS
	$waBootQuery = [
		'wa_embed' => '1',
		'wa_mobile' => $waMobile ? '1' : '',
		'wa_desktop' => (!empty($_GET['wa_desktop']) && (string)$_GET['wa_desktop'] === '1') ? '1' : '',
	];
	foreach (['dealId', 'leadId', 'chatId', 'dialogId', 'DEAL_ID', 'LEAD_ID'] as $waBootKey) {
		if (isset($_GET[$waBootKey]) && (string)$_GET[$waBootKey] !== '') {
			$waBootQuery[$waBootKey] = (string)$_GET[$waBootKey];
		}
	}
	?><!DOCTYPE html>
<html lang="ru">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
	<meta name="mobile-web-app-capable" content="yes">
	<meta name="apple-mobile-web-app-capable" content="yes">
	<title>WhatsApp чат</title>
	<script>
		window.__WA_CC_QUERY = <?= json_encode($waBootQuery, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
		window.__WA_CC_BOOT = <?= json_encode([
			'mobile' => (bool)$waMobile,
			'embed' => true,
			'appBoot' => defined('WA_CC_APP_BOOT'),
			'userId' => (int)$currentUserId,
			'noprolog' => (bool)$waNoProlog,
			'crmLeadId' => (int)$waCrmLeadId,
			'crmDealId' => (int)$waCrmDealId,
		], JSON_UNESCAPED_UNICODE) ?>;
		window.__WA_AID = <?= json_encode((string)$waAid, JSON_UNESCAPED_UNICODE) ?>;
		window.__WA_NOPROLOG = <?= !empty($waNoProlog) ? 'true' : 'false' ?>;
		window.waCcParams = function () {
			var sp = new URLSearchParams(window.location.search || '');
			var boot = window.__WA_CC_QUERY || {};
			try {
				Object.keys(boot).forEach(function (k) {
					if (boot[k] === null || boot[k] === undefined || boot[k] === '') return;
					if (!sp.has(k)) sp.set(k, String(boot[k]));
				});
			} catch (e) {}
			return sp;
		};
	</script>
	<?php if ($waLiteHead): ?>
		<script>window.BX=window.BX||{};BX.message=BX.message||function(){};</script>
		<script src="/bitrix/js/main/core/core.min.js?v=wa1"></script>
		<script src="/bitrix/js/main/core/core_ajax.min.js?v=wa1"></script>
		<script src="/bitrix/js/rest/client/rest.client.min.js?v=wa1"></script>
		<?php if ($waMobile): ?>
		<script src="/bitrix/js/mobileapp/mobile.js?v=wa1"></script>
		<?php endif; ?>
		<script>
			(function(){
				try {
					if (window.BX && BX.message) {
						BX.message({'bitrix_sessid': <?= json_encode($sessId) ?>});
					}
					if (window.BX && typeof BX.bitrix_sessid !== 'function') {
						BX.bitrix_sessid = function () { return <?= json_encode($sessId) ?>; };
					}
				} catch (e) {}
			})();
		</script>
	<?php else: ?>
		<?php $APPLICATION->ShowHead(); ?>
	<?php endif; ?>
	<style>
		html, body.wa-cc-embed {
			margin: 0; padding: 0; height: 100%; height: 100dvh;
			overflow: hidden; background: #f0f2f5;
		}
		body.wa-cc-embed .wa-app {
			height: 100vh; height: 100dvh; max-height: 100%;
			min-height: 0; border-radius: 0; max-width: none;
			padding-bottom: env(safe-area-inset-bottom, 0);
			box-sizing: border-box;
		}
	</style>
</head>
<body class="<?= htmlspecialchars($bodyClass, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
<?php
} else {
	require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php';
	$APPLICATION->SetTitle('Контакт-центр (WhatsApp Web UI)');

	// Разрешить встраивание КЦ в iframe виджета
	if (class_exists('\\Bitrix\\Main\\Context')) {
		try {
			$resp = \Bitrix\Main\Context::getCurrent()->getResponse();
			$headers = $resp->getHeaders();
			$headers->delete('X-Frame-Options');
			$headers->set(
				'Content-Security-Policy',
				"frame-ancestors 'self' https://crm.artflowers.kz https://bitrixeazy.vercel.app https://*.vercel.app"
			);
		} catch (\Throwable $e) {
			/* ignore */
		}
	}

	if (class_exists('\\Bitrix\\Main\\UI\\Extension')) {
		try {
			\Bitrix\Main\UI\Extension::load(['main.core', 'rest.client', 'pull.client', 'ui.notification']);
		} catch (\Throwable $e) {
			/* ignore */
		}
	}
	CJSCore::Init(['ajax', 'rest', 'pull', 'popup']);

	global $USER;
	$currentUserId = (int)$USER->GetID();
	$currentUserName = '';
	if ($USER) {
		if (method_exists($USER, 'GetFormattedName')) {
			$currentUserName = trim((string)$USER->GetFormattedName());
		}
		if ($currentUserName === '') {
			$currentUserName = trim((string)$USER->GetFullName());
		}
		if ($currentUserName === '') {
			$currentUserName = trim((string)$USER->GetLogin());
		}
	}
}
?>

<style>
@import url('https://fonts.googleapis.com/css2?family=Segoe+UI:wght@400;500;600;700&display=swap');

:root {
	--wa-teal: #00a884;
	--wa-teal-dark: #008069;
	--wa-panel: #f0f2f5;
	--wa-panel-deep: #008069;
	--wa-chat-bg: #efeae2;
	--wa-out: #d9fdd3;
	--wa-in: #ffffff;
	--wa-text: #111b21;
	--wa-muted: #667781;
	--wa-border: #d1d7db;
	--wa-hover: #f5f6f6;
	--wa-active: #f0f2f5;
	--wa-system: #ffeeba;
}

.wa-app {
	display: flex;
	height: calc(100vh - 120px);
	min-height: 560px;
	width: 100%;
	max-width: 1600px;
	margin: 0 auto;
	background: #fff;
	font-family: 'Segoe UI', Helvetica, Arial, sans-serif;
	color: var(--wa-text);
	box-shadow: 0 1px 3px rgba(11,20,26,.08);
	border-radius: 0;
	overflow: hidden;
}

/* —— SIDEBAR —— */
.wa-sidebar {
	width: 32%;
	min-width: 340px;
	max-width: 420px;
	background: #fff;
	border-right: 1px solid var(--wa-border);
	display: flex;
	flex-direction: column;
}
.wa-side-top {
	background: var(--wa-panel);
	padding: 10px 16px;
	display: flex;
	align-items: center;
	justify-content: space-between;
	min-height: 59px;
	box-sizing: border-box;
}
.wa-side-top h1 {
	margin: 0;
	font-size: 16px;
	font-weight: 600;
	color: var(--wa-text);
}
.wa-search-wrap {
	padding: 8px 12px;
	background: #fff;
	border-bottom: 1px solid #f0f2f5;
}
.wa-search {
	display: flex;
	align-items: center;
	gap: 10px;
	background: var(--wa-panel);
	border-radius: 8px;
	padding: 8px 12px;
}
.wa-search svg { flex-shrink: 0; color: var(--wa-muted); }
.wa-search input {
	flex: 1;
	border: none;
	background: transparent;
	outline: none;
	font-size: 14px;
	color: var(--wa-text);
	font-family: inherit;
}
.wa-search input::placeholder { color: var(--wa-muted); }
.wa-new-chat {
	margin: 12px;
	padding: 18px 14px;
	text-align: center;
	background: #f0f9f6;
	border: 1px solid #cce8df;
	border-radius: 10px;
	color: var(--wa-text);
}
.wa-new-chat-title { font-weight: 600; margin-bottom: 5px; }
.wa-new-chat-phone { color: var(--wa-muted); font-size: 13px; margin-bottom: 12px; }
.wa-new-chat-btn {
	border: 0;
	border-radius: 7px;
	padding: 9px 14px;
	background: var(--wa-teal);
	color: #fff;
	font-family: inherit;
	font-size: 13px;
	font-weight: 600;
	cursor: pointer;
}
.wa-new-chat-btn:disabled { opacity: .55; cursor: wait; }

.wa-tabs {
	display: flex;
	gap: 8px;
	padding: 0 12px 10px;
	background: #fff;
	border-bottom: 1px solid #f0f2f5;
	overflow-x: auto;
	scrollbar-width: none;
}
.wa-tabs::-webkit-scrollbar { display: none; }
.wa-tab {
	flex-shrink: 0;
	border: none;
	background: var(--wa-panel);
	color: var(--wa-muted);
	font-size: 13px;
	font-weight: 500;
	padding: 7px 14px;
	border-radius: 999px;
	cursor: pointer;
	font-family: inherit;
	line-height: 1.2;
	transition: background .15s, color .15s;
}
.wa-tab:hover { background: #e9edef; }
.wa-tab.active {
	background: #d9fdd3;
	color: var(--wa-teal-dark);
}
.wa-tab-count {
	display: inline-block;
	min-width: 18px;
	margin-left: 4px;
	padding: 0 5px;
	border-radius: 999px;
	background: rgba(0, 128, 105, .12);
	font-size: 11px;
	font-weight: 600;
	text-align: center;
}
.wa-tab:not(.active) .wa-tab-count {
	background: rgba(102, 119, 129, .14);
	color: var(--wa-muted);
}

.wa-chat-list { flex: 1; overflow-y: auto; }
.wa-chat-list.wa-list-searching::after {
	content: 'Поиск...';
	display: block;
	text-align: center;
	padding: 6px 14px;
	color: var(--wa-muted);
	font-size: 13px;
	position: sticky;
	bottom: 0;
	background: rgba(255, 255, 255, 0.92);
}
.wa-chat-item {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 10px 14px;
	cursor: pointer;
	border-bottom: 1px solid #f2f2f2;
}
.wa-chat-item:hover { background: var(--wa-hover); }
.wa-chat-item.active { background: var(--wa-active); }
.wa-chat-item.active.from-crm {
	box-shadow: inset 3px 0 0 #25d366;
}
.wa-avatar {
	width: 49px;
	height: 49px;
	border-radius: 50%;
	flex-shrink: 0;
	object-fit: cover;
	background: #dfe5e7;
	display: flex;
	align-items: center;
	justify-content: center;
	font-weight: 600;
	font-size: 18px;
	color: #fff;
	overflow: hidden;
}
.wa-avatar img { width: 100%; height: 100%; object-fit: cover; display: block; }
.wa-chat-meta { flex: 1; min-width: 0; }
.wa-chat-row {
	display: flex;
	justify-content: space-between;
	align-items: baseline;
	gap: 8px;
	margin-bottom: 3px;
}
.wa-chat-title {
	font-weight: 500;
	font-size: 16px;
	color: var(--wa-text);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}
.wa-chat-time {
	font-size: 12px;
	color: var(--wa-muted);
	flex-shrink: 0;
}
.wa-chat-time.unread { color: var(--wa-teal); font-weight: 600; }
.wa-chat-closed {
	flex-shrink: 0;
	font-size: 11px;
	font-weight: 600;
	color: #8696a0;
	background: #eef0f2;
	border-radius: 4px;
	padding: 1px 6px;
	margin-right: 6px;
	text-transform: lowercase;
}
.wa-chat-item.is-closed .wa-chat-title { color: #667781; }
.wa-chat-item.is-closed .wa-chat-preview { color: #8696a0; }
.wa-chat-row2 {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 8px;
}
.wa-chat-phone {
	font-size: 12.5px;
	color: var(--wa-teal-dark);
	margin-bottom: 2px;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}
.wa-chat-preview {
	font-size: 13.5px;
	color: var(--wa-muted);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
	flex: 1;
	min-width: 0;
}
.wa-badge {
	min-width: 20px;
	height: 20px;
	padding: 0 6px;
	border-radius: 10px;
	background: var(--wa-teal);
	color: #fff;
	font-size: 12px;
	font-weight: 600;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	flex-shrink: 0;
}

/* —— MAIN —— */
.wa-main {
	flex: 1;
	display: flex;
	flex-direction: column;
	min-width: 0;
	min-height: 0;
	overflow: hidden;
	background: var(--wa-chat-bg);
}
.wa-main-header {
	height: 59px;
	background: var(--wa-panel);
	padding: 8px 16px;
	border-bottom: 1px solid var(--wa-border);
	display: flex;
	align-items: center;
	gap: 12px;
	flex-shrink: 0;
	box-sizing: border-box;
}
.wa-main-header .wa-avatar { width: 40px; height: 40px; font-size: 15px; }
.wa-main-header-info { flex: 1; min-width: 0; }
.wa-main-header-info .title {
	font-size: 16px;
	font-weight: 500;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}
.wa-main-header-info .sub {
	font-size: 13px;
	color: var(--wa-muted);
}

.wa-header-actions {
	display: none;
	gap: 8px;
	margin-left: auto;
	flex-shrink: 0;
}
.wa-header-actions.visible { display: flex; }
.wa-chat-search-toggle {
	display: none;
	width: 38px;
	height: 38px;
	flex: 0 0 38px;
	align-items: center;
	justify-content: center;
	border: 0;
	border-radius: 50%;
	background: transparent;
	color: #54656f;
	cursor: pointer;
}
body.wa-chat-open .wa-chat-search-toggle { display: inline-flex; }
.wa-chat-search-toggle:hover,
.wa-chat-search-toggle.active { background: #e9edef; color: var(--wa-teal-dark); }
.wa-chat-search-toggle svg { width: 21px; height: 21px; }
.wa-chat-search-panel {
	display: none;
	align-items: center;
	gap: 8px;
	padding: 7px 12px;
	background: #fff;
	border-bottom: 1px solid var(--wa-border);
	flex-shrink: 0;
}
.wa-chat-search-panel.visible { display: flex; }
.wa-chat-search-field {
	flex: 1;
	min-width: 0;
	height: 36px;
	padding: 0 12px;
	border: 1px solid #d7dcdf;
	border-radius: 8px;
	outline: none;
	font-family: inherit;
	font-size: 14px;
	color: var(--wa-text);
	background: #f7f8fa;
}
.wa-chat-search-field:focus { border-color: var(--wa-teal); background: #fff; }
.wa-chat-search-status {
	min-width: 88px;
	color: var(--wa-muted);
	font-size: 12px;
	text-align: right;
	white-space: nowrap;
}
.wa-chat-search-nav,
.wa-chat-search-close {
	width: 34px;
	height: 34px;
	flex: 0 0 34px;
	border: 0;
	border-radius: 50%;
	background: transparent;
	color: #54656f;
	font-size: 20px;
	line-height: 1;
	cursor: pointer;
}
.wa-chat-search-nav:hover,
.wa-chat-search-close:hover { background: #e9edef; }
.wa-chat-search-nav:disabled { opacity: .35; cursor: default; background: transparent; }
.wa-msg.wa-chat-search-hit {
	outline: 3px solid rgba(0, 168, 132, .48);
	box-shadow: 0 0 0 6px rgba(0, 168, 132, .12);
}
.wa-ol-btn {
	border: none;
	border-radius: 8px;
	padding: 8px 14px;
	font-size: 13px;
	font-weight: 600;
	cursor: pointer;
	font-family: inherit;
	white-space: nowrap;
}
.wa-ol-btn-answer { background: var(--wa-teal); color: #fff; }
.wa-ol-btn-answer:hover { background: var(--wa-teal-dark); }
.wa-ol-btn-finish {
	background: #fff;
	color: #54656f;
	border: 1px solid var(--wa-border);
}
.wa-ol-btn-finish:hover { background: #f5f6f6; }
.wa-ol-btn-crm {
	background: #fff;
	color: #2067b0;
	border: 1px solid #a8c8e8;
}
.wa-ol-btn-crm:hover { background: #edf4fb; }
.wa-ol-btn-crm { text-decoration: none; display: inline-block; box-sizing: border-box; }
.wa-ol-btn:disabled { opacity: .5; cursor: default; }
.wa-msg-actions {
	position: absolute;
	top: 2px;
	right: 2px;
	display: flex;
	gap: 2px;
	z-index: 2;
	opacity: 0;
	pointer-events: none;
}
.wa-msg:hover .wa-msg-actions,
.wa-msg:focus-within .wa-msg-actions,
body.wa-cc-mobile .wa-msg-actions {
	opacity: 1;
	pointer-events: none;
}
.wa-msg:hover .wa-msg-actions > *,
.wa-msg:focus-within .wa-msg-actions > *,
body.wa-cc-mobile .wa-msg-actions > * {
	pointer-events: auto;
}
.wa-msg.system .wa-msg-actions { display: none; }
.wa-msg-reply-btn,
.wa-msg-fwd-btn,
.wa-msg-select-btn {
	border: none;
	background: rgba(255,255,255,.92);
	color: #54656f;
	border-radius: 999px;
	width: 28px;
	height: 28px;
	font-size: 14px;
	line-height: 1;
	cursor: pointer;
	box-shadow: 0 1px 3px rgba(11,20,26,.12);
	padding: 0;
}
.wa-msg-fwd-btn { font-size: 15px; }
.wa-msg-select-btn { font-size: 16px; }
.wa-msg.selected {
	outline: 2px solid rgba(0, 168, 132, .45);
	box-shadow: 0 0 0 3px rgba(0, 168, 132, .12);
}
.wa-msg.selected::after {
	content: '✓';
	position: absolute;
	left: -10px;
	top: 50%;
	transform: translateY(-50%);
	width: 22px;
	height: 22px;
	border-radius: 50%;
	background: var(--wa-teal);
	color: #fff;
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 12px;
	font-weight: 700;
	box-shadow: 0 2px 6px rgba(11,20,26,.16);
}
.wa-msg .wa-msg-from { display: block; font-size: 12.5px; font-weight: 600; color: #06cf9c; margin-bottom: 3px; padding-right: 62px; }
.wa-msg.out .wa-msg-from { color: #1a7f4c; }
body.wa-cc-mobile .wa-msg.out .wa-msg-actions {
	top: 2px;
	bottom: auto;
	right: 2px;
}
body.wa-cc-mobile .wa-msg.has-audio {
	margin-top: 4px;
}
body.wa-cc-mobile .wa-chat-search-panel { padding: 7px 8px; }
body.wa-cc-mobile .wa-chat-search-status { min-width: 58px; }

.wa-messages-container {
	flex: 1;
	min-height: 0;
	overflow-y: auto;
	overflow-anchor: none;
	padding: 20px 8%;
	display: flex;
	flex-direction: column;
	background-color: #e5ddd5;
	background-image: url("data:image/svg+xml,%3Csvg width='60' height='60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='%23d4cdc4' fill-opacity='0.35'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/svg%3E");
}
.wa-msg-unread-sep {
	align-self: stretch;
	display: flex;
	align-items: center;
	gap: 10px;
	margin: 12px 0 8px;
	color: #00a884;
	font-size: 12px;
	font-weight: 600;
	letter-spacing: .02em;
}
.wa-msg-unread-sep::before,
.wa-msg-unread-sep::after {
	content: '';
	flex: 1;
	height: 1px;
	background: #00a884;
	opacity: .4;
}
.wa-msg {
	max-width: 65%;
	margin-bottom: 3px;
	padding: 6px 9px 8px;
	border-radius: 7.5px;
	background: var(--wa-in);
	font-size: 14.2px;
	line-height: 1.4;
	box-shadow: 0 1px 0.5px rgba(11,20,26,.13);
	word-wrap: break-word;
	position: relative;
}
.wa-msg.out { align-self: flex-end; background: var(--wa-out); }
.wa-msg.in { align-self: flex-start; }
.wa-msg.system {
	align-self: center;
	background: var(--wa-system);
	color: #54656f;
	font-size: 12.5px;
	max-width: 85%;
	text-align: center;
	box-shadow: none;
}
.wa-msg-date-divider {
	align-self: center;
	background: rgba(255,255,255,.92);
	color: #54656f;
	font-size: 12.5px;
	padding: 5px 12px 6px;
	border-radius: 8px;
	margin: 10px 0 6px;
	box-shadow: 0 1px 0.5px rgba(11,20,26,.13);
}
.wa-history-loader {
	align-self: center;
	color: #667781;
	font-size: 13px;
	padding: 8px 12px;
}
.wa-msg .wa-msg-time {
	display: inline-flex;
	align-items: flex-end;
	gap: 3px;
	text-align: right;
	font-size: 11px;
	color: var(--wa-muted);
	margin-top: 2px;
	margin-left: 12px;
	float: right;
	position: relative;
	top: 4px;
}
.wa-ticks {
	display: inline-flex;
	width: 16px;
	height: 11px;
	flex-shrink: 0;
	position: relative;
	top: -1px;
	color: #667781;
}
.wa-ticks.read { color: #53bdeb; }
.wa-ticks svg { display: block; width: 16px; height: 11px; }
.wa-msg .wa-media { margin: 4px 0; clear: both; }
.wa-msg .wa-media img { max-width: 100%; max-height: 320px; border-radius: 6px; display: block; cursor: pointer; }
.wa-msg .wa-media video, .wa-msg .wa-media audio { max-width: 280px; display: block; min-width: 220px; }
.wa-msg .wa-media audio.wa-voice { min-width: 240px; height: 36px; }
.wa-msg .wa-media-audio { display: flex; align-items: center; gap: 8px; position: relative; z-index: 5; }
.wa-msg .wa-audio-dur { font-size: 12px; color: var(--wa-muted); white-space: nowrap; min-width: 2.5em; }
body.wa-cc-mobile .wa-msg .wa-media-audio,
body.wa-cc-mobile .wa-msg audio.wa-voice {
	position: relative;
	z-index: 6;
	pointer-events: auto !important;
	-webkit-transform: translateZ(0);
	transform: translateZ(0);
}
body.wa-cc-mobile .wa-msg.has-audio .wa-msg-actions,
body.wa-cc-mobile .wa-msg.out.has-audio .wa-msg-actions {
	display: none !important;
}
body.wa-cc-mobile .wa-msg.has-audio .wa-msg-time {
	float: none;
	display: block;
	clear: both;
	margin-left: 0;
	text-align: right;
}
body.wa-cc-mobile .wa-msg audio.wa-voice {
	min-width: 260px;
	width: 100%;
	max-width: 280px;
	height: 40px;
}
.wa-msg .wa-media .wa-media-loading { font-size: 13px; color: var(--wa-muted); padding: 6px 0; }
.wa-msg .wa-file-link { display: inline-flex; align-items: center; gap: 6px; color: #027eb5; text-decoration: none; word-break: break-all; }
.wa-msg-quote {
	display: flex;
	align-items: center;
	gap: 8px;
	border-left: 3px solid #06cf9c;
	background: rgba(0,0,0,.04);
	border-radius: 6px;
	padding: 6px 10px;
	margin-bottom: 6px;
	font-size: 12.5px;
	line-height: 1.35;
	color: var(--wa-muted);
}
.wa-msg-quote-body { min-width: 0; flex: 1; }
.wa-msg-quote-thumb {
	width: 46px;
	height: 46px;
	flex: 0 0 46px;
	border-radius: 5px;
	object-fit: cover;
	background: #dfe5e7;
	cursor: pointer;
}
.wa-msg.out .wa-msg-quote { border-left-color: #1a7f4c; }
.wa-msg-quote-author { display: block; font-weight: 600; color: #06cf9c; margin-bottom: 2px; }
.wa-msg.out .wa-msg-quote-author { color: #1a7f4c; }
.wa-msg-edited { font-size: 10px; color: var(--wa-muted); margin-right: 2px; font-style: italic; }
.wa-reply-bar {
	display: none;
	align-items: center;
	gap: 10px;
	padding: 8px 12px;
	background: #f0f2f5;
	border-top: 1px solid var(--wa-border);
	border-left: 3px solid var(--wa-teal);
}
.wa-reply-bar.visible { display: flex; }
.wa-reply-preview { flex: 1; min-width: 0; }
.wa-reply-thumb {
	display: none;
	width: 42px;
	height: 42px;
	flex: 0 0 42px;
	border-radius: 5px;
	object-fit: cover;
	background: #dfe5e7;
}
.wa-reply-thumb.visible { display: block; }
.wa-reply-author { display: block; font-size: 12px; font-weight: 600; color: var(--wa-teal-dark); }
.wa-reply-text { display: block; font-size: 13px; color: var(--wa-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.wa-reply-cancel {
	border: none;
	background: transparent;
	font-size: 22px;
	line-height: 1;
	color: var(--wa-muted);
	padding: 4px 8px;
	cursor: pointer;
}
.wa-empty {
	color: var(--wa-muted);
	text-align: center;
	margin: auto;
	background: rgba(255,255,255,.85);
	padding: 10px 18px;
	border-radius: 8px;
	font-size: 14px;
}

/* —— INPUT —— */
.wa-footer {
	background: var(--wa-panel);
	border-top: 1px solid var(--wa-border);
	flex-shrink: 0;
}
.wa-upload-hint {
	font-size: 12px;
	color: var(--wa-muted);
	padding: 6px 16px 0;
	display: none;
}
.wa-attach-preview {
	display: none;
	padding: 8px 12px 0;
	gap: 8px;
	flex-wrap: wrap;
	align-items: flex-start;
}
.wa-attach-preview.visible { display: flex; }
.wa-attach-card {
	position: relative;
	width: 86px;
	border: 1px solid var(--wa-border);
	border-radius: 10px;
	background: #fff;
	padding: 6px;
	box-sizing: border-box;
}
.wa-attach-thumb {
	width: 100%;
	height: 62px;
	border-radius: 8px;
	background: #f3f5f6 center/cover no-repeat;
	display: flex;
	align-items: center;
	justify-content: center;
	color: var(--wa-muted);
	font-size: 11px;
	text-align: center;
	overflow: hidden;
}
.wa-attach-thumb img {
	width: 100%;
	height: 100%;
	object-fit: cover;
	display: block;
}
.wa-attach-name {
	margin-top: 6px;
	font-size: 11px;
	line-height: 1.25;
	color: var(--wa-text);
	word-break: break-word;
	max-height: 28px;
	overflow: hidden;
}
.wa-attach-remove {
	position: absolute;
	top: -6px;
	right: -6px;
	width: 22px;
	height: 22px;
	border: none;
	border-radius: 50%;
	background: #ea0038;
	color: #fff;
	font-size: 15px;
	line-height: 1;
	cursor: pointer;
}
.wa-input-bar {
	display: none;
	gap: 8px;
	align-items: flex-end;
	padding: 8px 12px 10px;
}
.wa-input-bar.visible { display: flex; }
.wa-input-bar textarea {
	flex: 1;
	resize: none;
	border: none;
	border-radius: 8px;
	padding: 10px 14px;
	font-size: 15px;
	font-family: inherit;
	max-height: 120px;
	outline: none;
	background: #fff;
	line-height: 1.4;
}
.wa-icon-btn, .wa-send-btn {
	width: 42px;
	height: 42px;
	border: none;
	border-radius: 50%;
	cursor: pointer;
	flex-shrink: 0;
	display: flex;
	align-items: center;
	justify-content: center;
	background: transparent;
	color: #54656f;
	padding: 0;
}
.wa-icon-btn:hover { color: var(--wa-teal); }
.wa-icon-btn svg, .wa-send-btn svg { width: 24px; height: 24px; }
.wa-send-btn {
	background: var(--wa-teal);
	color: #fff;
}
.wa-send-btn:hover { background: var(--wa-teal-dark); }
.wa-send-btn:disabled, .wa-icon-btn:disabled { opacity: .45; cursor: default; }
.wa-send-btn.mic { background: transparent; color: #54656f; }
.wa-send-btn.mic:hover { color: var(--wa-teal); background: transparent; }
.wa-send-btn.recording {
	background: #ea0038;
	color: #fff;
	animation: wa-pulse 1.2s ease-in-out infinite;
}
@keyframes wa-pulse {
	0%, 100% { box-shadow: 0 0 0 0 rgba(234,0,56,.45); }
	50% { box-shadow: 0 0 0 10px rgba(234,0,56,0); }
}

.wa-rec-bar {
	display: none;
	align-items: center;
	gap: 14px;
	padding: 10px 16px;
	background: var(--wa-panel);
}
.wa-rec-bar.active { display: flex; }
.wa-rec-dot {
	width: 10px;
	height: 10px;
	border-radius: 50%;
	background: #ea0038;
	animation: wa-blink 1s step-start infinite;
}
@keyframes wa-blink { 50% { opacity: 0; } }
.wa-rec-timer {
	font-size: 15px;
	font-variant-numeric: tabular-nums;
	color: var(--wa-text);
	min-width: 48px;
}
.wa-rec-wave {
	flex: 1;
	height: 28px;
	border-radius: 4px;
	background: linear-gradient(90deg, #cfd6d9 0%, #00a884 50%, #cfd6d9 100%);
	background-size: 200% 100%;
	animation: wa-wave 1.4s linear infinite;
	opacity: .55;
}
@keyframes wa-wave {
	0% { background-position: 100% 0; }
	100% { background-position: -100% 0; }
}
.wa-rec-cancel {
	border: none;
	background: transparent;
	color: #ea0038;
	font-size: 14px;
	font-weight: 600;
	cursor: pointer;
	padding: 8px;
}

/* lightbox */
.wa-lightbox {
	position: fixed; inset: 0; z-index: 100000;
	background: rgba(0,0,0,.88);
	display: none; align-items: center; justify-content: center;
	padding: 48px 24px; box-sizing: border-box;
}
.wa-lightbox.open { display: flex; }
.wa-lightbox img {
	max-width: 100%; max-height: 100%; object-fit: contain;
	border-radius: 4px; box-shadow: 0 8px 32px rgba(0,0,0,.45); user-select: none;
}
.wa-lightbox-close, .wa-lightbox-dl {
	position: absolute; top: 14px; height: 44px; border: none;
	background: rgba(255,255,255,.12); color: #fff; cursor: pointer;
}
.wa-lightbox-close {
	right: 18px; width: 44px; border-radius: 50%;
	font-size: 28px; display: flex; align-items: center; justify-content: center;
}
.wa-lightbox-dl {
	right: 70px; padding: 0 14px; border-radius: 22px; font-size: 13px;
}
.wa-lightbox-close:hover, .wa-lightbox-dl:hover { background: rgba(255,255,255,.22); }

.wa-fwd {
	position: fixed; inset: 0; z-index: 100010;
	background: rgba(11,20,26,.45);
	display: none; align-items: flex-end; justify-content: center;
}
.wa-fwd.open { display: flex; }
.wa-fwd-panel {
	width: min(440px, 100%);
	max-height: 88vh;
	background: #fff;
	border-radius: 16px 16px 0 0;
	display: flex;
	flex-direction: column;
	min-height: 0;
	box-shadow: 0 -8px 32px rgba(11,20,26,.18);
}
.wa-fwd-head {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 10px 12px;
	border-bottom: 1px solid #f0f0f0;
}
.wa-fwd-head .wa-fwd-title { flex: 1; font-size: 16px; font-weight: 600; }
.wa-fwd-close, .wa-fwd-go {
	border: none; background: transparent; cursor: pointer;
	height: 36px; border-radius: 8px; font-size: 14px; font-weight: 600;
}
.wa-fwd-close { width: 36px; font-size: 22px; color: #54656f; }
.wa-fwd-go { color: #fff; background: var(--wa-teal); padding: 0 14px; }
.wa-fwd-go:disabled { opacity: .4; cursor: default; }
.wa-fwd-preview {
	margin: 8px 12px 0;
	padding: 8px 10px;
	background: #f0f2f5;
	border-radius: 8px;
	font-size: 13px;
	color: #54656f;
	max-height: 72px;
	overflow: hidden;
}
.wa-fwd-search { padding: 8px 12px; }
.wa-fwd-search input {
	width: 100%; box-sizing: border-box;
	border: 1px solid #e9edef; border-radius: 8px;
	padding: 9px 12px; font: inherit; font-size: 14px; outline: none;
}
.wa-fwd-list {
	flex: 1 1 auto;
	min-height: 0;
	overflow-y: auto;
	overflow-x: hidden;
	-webkit-overflow-scrolling: touch;
	overscroll-behavior: contain;
	touch-action: pan-y;
}
.wa-fwd-list.wa-list-searching::after {
	content: 'Идёт поиск...';
	display: block;
	text-align: center;
	padding: 8px 14px 12px;
	color: var(--wa-muted);
	font-size: 13px;
	position: sticky;
	bottom: 0;
	background: rgba(255, 255, 255, 0.94);
}
.wa-fwd-item {
	display: flex; align-items: center; gap: 10px;
	padding: 8px 14px; cursor: pointer; border-bottom: 1px solid #f6f6f6;
}
.wa-fwd-item:hover { background: #f5f6f6; }
.wa-fwd-item.on { background: #e7f8f2; }
.wa-fwd-item .wa-fwd-meta { flex: 1; min-width: 0; }
.wa-fwd-item .wa-fwd-name { font-size: 14px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.wa-fwd-item .wa-fwd-sub { font-size: 12px; color: #667781; }
.wa-fwd-check {
	width: 22px; height: 22px; border-radius: 50%;
	border: 2px solid #c5c9cc; flex-shrink: 0;
	display: flex; align-items: center; justify-content: center;
	font-size: 12px; color: #fff;
}
.wa-fwd-item.on .wa-fwd-check { background: var(--wa-teal); border-color: var(--wa-teal); }
body.wa-cc-mobile .wa-fwd-panel { width: 100%; max-height: 92dvh; }

.wa-bulkbar {
	display: none;
	align-items: center;
	gap: 8px;
	padding: 10px 14px;
	background: #f7fffc;
	border-top: 1px solid #dbeee8;
	border-bottom: 1px solid #dbeee8;
}
.wa-bulkbar.visible { display: flex; }
.wa-bulkbar-title {
	flex: 1;
	min-width: 0;
	font-size: 14px;
	font-weight: 600;
	color: #0b4f43;
}
.wa-bulkbar-btn {
	border: none;
	border-radius: 10px;
	height: 36px;
	padding: 0 12px;
	font: inherit;
	font-size: 13px;
	font-weight: 600;
	cursor: pointer;
}
.wa-bulkbar-btn.ghost {
	background: #fff;
	color: #54656f;
	border: 1px solid #d7dbde;
}
.wa-bulkbar-btn.primary {
	background: var(--wa-teal);
	color: #fff;
}
.wa-bulkbar-btn:disabled {
	opacity: .45;
	cursor: default;
}
body.wa-cc-mobile .wa-bulkbar {
	flex-wrap: wrap;
}

@media (max-width: 900px) {
	.wa-sidebar { min-width: 280px; width: 38%; }
	.wa-messages-container { padding: 12px 4%; }
}

/* —— Mobile / local app embed —— */
.wa-back-btn {
	display: none;
	align-items: center;
	justify-content: center;
	width: 40px;
	height: 40px;
	margin: 0 4px 0 -6px;
	padding: 0;
	border: 0;
	border-radius: 50%;
	background: transparent;
	color: #54656f;
	cursor: pointer;
	flex-shrink: 0;
	-webkit-tap-highlight-color: transparent;
}
.wa-back-btn:active { background: rgba(11, 20, 26, .08); }
.wa-back-btn svg { display: block; width: 24px; height: 24px; }

body.wa-cc-mobile .wa-app,
body.wa-cc-embed.wa-cc-mobile .wa-app {
	height: 100%;
	height: 100dvh;
	min-height: 0;
	max-width: none;
}
body.wa-cc-mobile .wa-sidebar {
	width: 100%;
	min-width: 0;
	max-width: none;
	border-right: 0;
}
body.wa-cc-mobile .wa-main {
	display: none;
	width: 100%;
	min-width: 0;
}
body.wa-cc-mobile.wa-chat-open .wa-sidebar { display: none; }
body.wa-cc-mobile.wa-chat-open .wa-main { display: flex; flex-direction: column; min-height: 0; }
body.wa-cc-mobile .wa-back-btn { display: inline-flex; }
body.wa-cc-mobile .wa-ol-btn,
body.wa-cc-mobile .wa-icon-btn,
body.wa-cc-mobile .wa-send-btn,
body.wa-cc-mobile .wa-tab,
body.wa-cc-mobile .wa-chat-item {
	min-height: 44px;
	-webkit-tap-highlight-color: transparent;
}
body.wa-cc-mobile .wa-chat-item { padding: 12px 14px; }
body.wa-cc-mobile .wa-input-bar textarea {
	font-size: 16px; /* iOS: без автозума */
	max-height: 120px;
}
body.wa-cc-mobile .wa-footer {
	padding-bottom: max(8px, env(safe-area-inset-bottom, 0px));
}
body.wa-cc-mobile .wa-main-header {
	padding-top: max(8px, env(safe-area-inset-top, 0px));
}
body.wa-cc-mobile .wa-side-top {
	padding-top: max(10px, env(safe-area-inset-top, 0px));
}
body.wa-cc-mobile .wa-header-actions {
	flex-wrap: wrap;
	gap: 6px;
	justify-content: flex-end;
}
body.wa-cc-mobile .wa-ol-btn {
	font-size: 12px;
	padding: 8px 10px;
}

@media (max-width: 720px) {
	/* только обычная страница / телефон; desktop-embed не схлопываем */
	body:not(.wa-cc-mobile):not(.wa-cc-desktop) .wa-app {
		height: 100vh;
		height: 100dvh;
		min-height: 0;
		max-width: none;
	}
	body:not(.wa-cc-mobile):not(.wa-cc-desktop) .wa-sidebar {
		width: 100%;
		min-width: 0;
		max-width: none;
		border-right: 0;
	}
	body:not(.wa-cc-mobile):not(.wa-cc-desktop) .wa-main { display: none; width: 100%; }
	body:not(.wa-cc-mobile):not(.wa-cc-desktop).wa-chat-open .wa-sidebar { display: none; }
	body:not(.wa-cc-mobile):not(.wa-cc-desktop).wa-chat-open .wa-main { display: flex; flex-direction: column; }
	body:not(.wa-cc-mobile):not(.wa-cc-desktop) .wa-back-btn { display: inline-flex; }
	body:not(.wa-cc-mobile):not(.wa-cc-desktop) .wa-input-bar textarea { font-size: 16px; }
}

/* Desktop embed (локальное приложение на ПК): всегда список + чат */
body.wa-cc-desktop.wa-cc-embed .wa-app {
	display: flex;
	height: 100%;
	height: 100dvh;
	min-height: 0;
	max-width: none;
}
body.wa-cc-desktop .wa-sidebar {
	display: flex !important;
	width: 34%;
	min-width: 280px;
	max-width: 420px;
	border-right: 1px solid var(--wa-border);
}
body.wa-cc-desktop .wa-main {
	display: flex !important;
	flex: 1;
	min-width: 0;
	min-height: 0;
	flex-direction: column;
}
body.wa-cc-desktop .wa-back-btn { display: none !important; }
body.wa-cc-desktop.wa-chat-open .wa-sidebar { display: flex !important; }
</style>

<div class="wa-app">
	<div class="wa-sidebar">
		<div class="wa-side-top">
			<h1>Чаты</h1>
		</div>
		<div class="wa-search-wrap">
			<div class="wa-search">
				<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M15.5 14h-.79l-.28-.27A6.47 6.47 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>
				<input type="search" id="wa-search" placeholder="Поиск по имени или телефону" autocomplete="off">
			</div>
		</div>
		<div class="wa-tabs" id="wa-tabs">
			<button type="button" class="wa-tab active" data-filter="all">Чаты</button>
			<button type="button" class="wa-tab" data-filter="unread">Непрочитанные</button>
			<button type="button" class="wa-tab" data-filter="groups">Группы</button>
		</div>
		<div class="wa-chat-list" id="wa-chat-list">Загрузка...</div>
	</div>

	<div class="wa-main">
		<div class="wa-main-header" id="wa-active-header">
			<button type="button" class="wa-back-btn" id="wa-btn-back" title="Назад к списку" aria-label="Назад">
				<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M15.41 7.41 14 6l-6 6 6 6 1.41-1.41L10.83 12z"/></svg>
			</button>
			<div class="wa-avatar" id="wa-active-avatar" style="background:#dfe5e7;display:none;"></div>
			<div class="wa-main-header-info">
				<div class="title" id="wa-active-title">Выберите чат</div>
				<div class="sub" id="wa-active-sub">Открытые линии</div>
			</div>
			<div class="wa-header-actions" id="wa-header-actions">
				<a href="#" role="button" class="wa-ol-btn wa-ol-btn-crm" id="wa-btn-lead" style="display:none;">Лид</a>
				<a href="#" role="button" class="wa-ol-btn wa-ol-btn-crm" id="wa-btn-deal" style="display:none;">Сделка</a>
				<button type="button" class="wa-ol-btn wa-ol-btn-answer" id="wa-btn-answer" style="display:none;">Принять</button>
				<button type="button" class="wa-ol-btn wa-ol-btn-finish" id="wa-btn-finish" style="display:none;">Завершить</button>
			</div>
			<button type="button" class="wa-chat-search-toggle" id="wa-chat-search-toggle" title="Поиск по сообщениям" aria-label="Поиск по сообщениям">
				<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M15.5 14h-.79l-.28-.27A6.47 6.47 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>
			</button>
		</div>
		<div class="wa-chat-search-panel" id="wa-chat-search-panel">
			<input type="search" class="wa-chat-search-field" id="wa-chat-search-field" placeholder="Поиск в переписке" autocomplete="off">
			<span class="wa-chat-search-status" id="wa-chat-search-status"></span>
			<button type="button" class="wa-chat-search-nav" id="wa-chat-search-prev" title="Предыдущее совпадение" aria-label="Предыдущее совпадение" disabled>↑</button>
			<button type="button" class="wa-chat-search-nav" id="wa-chat-search-next" title="Следующее совпадение" aria-label="Следующее совпадение" disabled>↓</button>
			<button type="button" class="wa-chat-search-close" id="wa-chat-search-close" title="Закрыть поиск" aria-label="Закрыть поиск">×</button>
		</div>

		<div class="wa-messages-container" id="wa-messages-container">
			<div class="wa-empty">Выберите диалог слева</div>
		</div>
		<div class="wa-bulkbar" id="wa-bulkbar">
			<div class="wa-bulkbar-title" id="wa-bulkbar-title">Выбрано: 0</div>
			<button type="button" class="wa-bulkbar-btn ghost" id="wa-bulkbar-cancel">Снять</button>
			<button type="button" class="wa-bulkbar-btn ghost" id="wa-bulkbar-download" disabled>Скачать</button>
			<button type="button" class="wa-bulkbar-btn primary" id="wa-bulkbar-forward" disabled>Переслать</button>
		</div>

		<div class="wa-footer">
			<div class="wa-upload-hint" id="wa-upload-hint"></div>
			<div class="wa-attach-preview" id="wa-attach-preview"></div>
			<div class="wa-rec-bar" id="wa-rec-bar">
				<span class="wa-rec-dot"></span>
				<span class="wa-rec-timer" id="wa-rec-timer">0:00</span>
				<div class="wa-rec-wave"></div>
				<button type="button" class="wa-rec-cancel" id="wa-rec-cancel">Отмена</button>
				<button type="button" class="wa-send-btn" id="wa-rec-send" title="Отправить голосовое">
					<svg viewBox="0 0 24 24" fill="currentColor"><path d="M1.101 21.757 23.8 12.028 1.101 2.3l.011 7.912 13.623 1.816-13.623 1.817-.011 7.912z"/></svg>
				</button>
			</div>
			<div class="wa-reply-bar" id="wa-reply-bar">
				<img class="wa-reply-thumb" id="wa-reply-thumb" alt="">
				<div class="wa-reply-preview">
					<span class="wa-reply-author" id="wa-reply-author"></span>
					<span class="wa-reply-text" id="wa-reply-text"></span>
				</div>
				<button type="button" class="wa-reply-cancel" id="wa-reply-cancel" title="Отменить ответ" aria-label="Отменить">×</button>
			</div>
			<div class="wa-input-bar" id="wa-input-bar">
				<button type="button" class="wa-icon-btn" id="wa-attach" title="Прикрепить файл">
					<svg viewBox="0 0 24 24" fill="currentColor"><path d="M16.5 6v11.5c0 2.21-1.79 4-4 4s-4-1.79-4-4V5a2.5 2.5 0 0 1 5 0v10.5c0 .55-.45 1-1 1s-1-.45-1-1V6H10v9.5a2.5 2.5 0 0 0 5 0V5c0-2.21-1.79-4-4-4S7 2.79 7 5v12.5c0 3.04 2.46 5.5 5.5 5.5s5.5-2.46 5.5-5.5V6h-1.5z"/></svg>
				</button>
				<input type="file" id="wa-file" style="display:none" multiple
					accept="image/*,video/*,audio/*,.pdf,.doc,.docx,.xls,.xlsx,.zip,.rar,.txt">
				<textarea id="wa-input" rows="1" placeholder="Введите сообщение"></textarea>
				<button type="button" class="wa-send-btn mic" id="wa-send" title="Голосовое сообщение">
					<svg class="ico-mic" viewBox="0 0 24 24" fill="currentColor"><path d="M12 14c1.66 0 3-1.34 3-3V5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3zm5.91-3c-.49 0-.9.36-.98.85C16.52 14.2 14.47 16 12 16s-4.52-1.8-4.93-4.15a.998.998 0 0 0-.98-.85c-.61 0-1.09.54-1 1.14.49 3 2.89 5.35 5.91 5.78V20c0 .55.45 1 1 1s1-.45 1-1v-2.08c3.02-.43 5.42-2.78 5.91-5.78.1-.6-.39-1.14-1-1.14z"/></svg>
					<svg class="ico-send" viewBox="0 0 24 24" fill="currentColor" style="display:none"><path d="M1.101 21.757 23.8 12.028 1.101 2.3l.011 7.912 13.623 1.816-13.623 1.817-.011 7.912z"/></svg>
				</button>
			</div>
		</div>
	</div>
</div>

<div class="wa-lightbox" id="wa-lightbox" role="dialog" aria-modal="true" aria-label="Просмотр изображения">
	<button type="button" class="wa-lightbox-dl" id="wa-lightbox-dl" title="Скачать">Скачать</button>
	<button type="button" class="wa-lightbox-close" id="wa-lightbox-close" title="Закрыть" aria-label="Закрыть">×</button>
	<img id="wa-lightbox-img" alt="">
</div>

<div class="wa-fwd" id="wa-fwd" role="dialog" aria-modal="true" aria-label="Переслать сообщение">
	<div class="wa-fwd-panel">
		<div class="wa-fwd-head">
			<button type="button" class="wa-fwd-close" id="wa-fwd-close" title="Закрыть" aria-label="Закрыть">×</button>
			<div class="wa-fwd-title" id="wa-fwd-title">Переслать</div>
			<button type="button" class="wa-fwd-go" id="wa-fwd-go" disabled>Отправить</button>
		</div>
		<div class="wa-fwd-preview" id="wa-fwd-preview"></div>
		<div class="wa-fwd-search">
			<input type="search" id="wa-fwd-search" placeholder="Поиск чата или номера" autocomplete="off">
		</div>
		<div class="wa-fwd-list" id="wa-fwd-list"></div>
	</div>
</div>

<script>
(function waCcBoot(startFn) {
	if (window.__WA_NOPROLOG) {
		function go() {
			try {
				startFn();
			} catch (e) {
				console.error('WA CC boot', e);
				var el = document.getElementById('wa-chat-list');
				if (el) {
					el.innerHTML = '<div style="padding:16px;color:#c62828;font:14px system-ui">'
						+ 'Ошибка загрузки чата: ' + (e && e.message ? e.message : e) + '</div>';
				}
			}
		}
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', go);
		} else {
			go();
		}
		return;
	}
	var tries = 0;
	function run() {
		if (typeof window.BX !== 'undefined' && typeof BX.ready === 'function') {
			BX.ready(startFn);
			return;
		}
		tries++;
		if (tries === 1) {
			// ядро не успело / SidePanel iframe без core — догружаем
			var urls = [
				'/bitrix/js/main/core/core.min.js',
				'/bitrix/js/main/core/core.js'
			];
			for (var i = 0; i < urls.length; i++) {
				var s = document.createElement('script');
				s.src = urls[i];
				s.async = false;
				document.head.appendChild(s);
			}
		}
		if (tries > 100) {
			console.error('WA CC: BX не загрузился (SidePanel/iframe). Открой КЦ в обычной вкладке.');
			return;
		}
		setTimeout(run, 50);
	}
	run();
})(function () {
	const CURRENT_USER_ID = <?= (int)$currentUserId ?>;
	let CURRENT_USER_NAME = <?= json_encode((string)($currentUserName ?? ''), JSON_UNESCAPED_UNICODE) ?>;
	const CLOSED_LINE_STATUSES = [50, 60, 70, 80];
	const MESSAGES_PAGE = 80;
	let currentDialogId = null;
	let crmFocusDialogId = null;
	let currentChatId = null;
	let currentChatIsOpenLine = false;
	let currentChatData = null;
	let sessionState = { needsAnswer: false, canFinish: false, isClosed: false };
	let crmBindings = { leadId: 0, dealId: 0 };
	/** Лид/сделка из placement (?leadId= / ?dealId=) — приоритет над entity_data_2 чата */
	let crmContextLeadId = <?= (int)$waCrmLeadId ?>;
	let crmContextDealId = <?= (int)$waCrmDealId ?>;
	/** Жёсткий контекст из URL/placement — не перебивается sessionStorage чата */
	let crmPlacementLeadId = <?= (int)$waCrmLeadId ?>;
	let crmPlacementDealId = <?= (int)$waCrmDealId ?>;
	(function initCrmContextFromQuery() {
		try {
			const boot = window.__WA_CC_BOOT || {};
			if (parseInt(boot.crmLeadId, 10) > 0) {
				crmContextLeadId = parseInt(boot.crmLeadId, 10);
				crmPlacementLeadId = crmContextLeadId;
			}
			if (parseInt(boot.crmDealId, 10) > 0) {
				crmContextDealId = parseInt(boot.crmDealId, 10);
				crmPlacementDealId = crmContextDealId;
			}
			const sp = (typeof window.waCcParams === 'function')
				? window.waCcParams()
				: new URLSearchParams(window.location.search);
			const parseId = function (v) {
				if (v == null || v === '') return 0;
				const m = String(v).trim().match(/(\d{1,12})/);
				return m ? (parseInt(m[1], 10) || 0) : 0;
			};
			const qLead = parseId(sp.get('leadId') || sp.get('LEAD_ID'));
			const qDeal = parseId(sp.get('dealId') || sp.get('DEAL_ID'));
			if (qLead > 0) {
				crmContextLeadId = qLead;
				crmPlacementLeadId = qLead;
			}
			if (qDeal > 0) {
				crmContextDealId = qDeal;
				crmPlacementDealId = qDeal;
			}
			// Не восстанавливаем lead/deal из sessionStorage — при запуске приложения
			// из меню подтягивался старый лид (332043) вместо актуального для чата.
			if (crmContextLeadId || crmContextDealId) {
				sessionStorage.setItem('wa_cc_crm_ctx', JSON.stringify({
					leadId: crmContextLeadId,
					dealId: crmContextDealId,
					ts: Date.now()
				}));
			}
		} catch (e) { /* ignore */ }
	})();

	function persistCrmContextForChat(chatId) {
		if (!chatId || (!crmContextLeadId && !crmContextDealId)) return;
		try {
			sessionStorage.setItem('wa_cc_ctx_chat_' + chatId, JSON.stringify({
				leadId: crmContextLeadId,
				dealId: crmContextDealId,
				ts: Date.now()
			}));
		} catch (e) { /* ignore */ }
	}

	function restoreCrmContextForChat(chatId) {
		if (!chatId || crmPlacementLeadId || crmPlacementDealId || crmContextLeadId || crmContextDealId) return;
		try {
			const raw = sessionStorage.getItem('wa_cc_ctx_chat_' + chatId);
			if (!raw) return;
			const saved = JSON.parse(raw);
			if (!saved || (Date.now() - (saved.ts || 0)) > 86400000) return;
			if (parseInt(saved.leadId, 10) > 0) crmContextLeadId = parseInt(saved.leadId, 10);
			if (parseInt(saved.dealId, 10) > 0) crmContextDealId = parseInt(saved.dealId, 10);
		} catch (e) { /* ignore */ }
	}

	function getEffectiveCrmId(type) {
		if (type === 'lead') {
			if (crmPlacementLeadId > 0) return crmPlacementLeadId;
			if (crmContextLeadId > 0) return crmContextLeadId;
			return crmBindings.leadId || 0;
		}
		if (type === 'deal') {
			if (crmPlacementDealId > 0) return crmPlacementDealId;
			if (crmContextDealId > 0) return crmContextDealId;
			return crmBindings.dealId || 0;
		}
		return 0;
	}

	function waCcEachHostWindow(fn) {
		const seen = new Set();
		const list = [];
		try { list.push(window); } catch (e) { /* ignore */ }
		try { if (window.parent) list.push(window.parent); } catch (e) { /* ignore */ }
		try { if (window.top) list.push(window.top); } catch (e) { /* ignore */ }
		try { if (window.opener) list.push(window.opener); } catch (e) { /* ignore */ }
		for (let i = 0; i < list.length; i++) {
			try {
				const w = list[i];
				if (!w || seen.has(w)) continue;
				seen.add(w);
				const hit = fn(w);
				if (hit) return hit;
			} catch (e) { /* cross-origin */ }
		}
		return null;
	}
	let lastMessageId = 0;
	let firstMessageId = 0;
	let hasMoreHistory = true;
	let historyLoading = false;
	let openingScrollLock = false;
	let openScrollMode = 'bottom';
	let openUnreadAnchorId = 0;
	let sending = false;
	let filesMap = {};
	let usersMap = {};
	let chatsCache = [];
	let searchQuery = '';
	let listFilter = 'all';
	let searchDebounceId = null;
	let searchRemoteLoading = false;
	let lastRemoteSearchKey = '';
	let completedPhoneSearchKey = '';
	let searchRecentLoaded = false;
	let crmNewChatOffer = null;
	let waLinesPromise = null;
	let waAllowedLineIds = null;
	const failedPhoneLookups = new Set();
	const failedUserCodeLookups = new Set();
	const failedCrmEntityChatLookups = new Set();
	const olIdResolveCache = new Map();
	const crmNameCache = new Map();
	const crmLeadResolveCache = new Map();
	const groupTitleCache = new Map();
	const messagesById = {};
	const replyPreviewCache = {};
	const localReadAt = {};
	let replyTo = null;
	let forwardMessages = [];
	let forwardSelected = new Set();
	let forwardSearchQuery = '';
	let forwardSearchTimer = null;
	let forwardRemoteChats = [];
	let forwardSearchLoading = false;
	let opponentReadMessageId = 0;
	let waChatTickStatus = '';
	let waChatTickTs = 0;
	let waChatReadTs = 0;
	let selectedMessageIds = new Set();
	let currentMessageCache = new Map();
	let chatSearchHistoryComplete = false;
	let chatSearchResults = [];
	let chatSearchIndex = -1;
	let chatSearchToken = 0;
	let chatSearchTimer = null;

	const listEl = document.getElementById('wa-chat-list');
	const tabsEl = document.getElementById('wa-tabs');
	const messagesEl = document.getElementById('wa-messages-container');
	const titleEl = document.getElementById('wa-active-title');
	const subEl = document.getElementById('wa-active-sub');
	const headerActions = document.getElementById('wa-header-actions');
	const btnAnswer = document.getElementById('wa-btn-answer');
	const btnFinish = document.getElementById('wa-btn-finish');
	const btnLead = document.getElementById('wa-btn-lead');
	const btnDeal = document.getElementById('wa-btn-deal');
	const btnBack = document.getElementById('wa-btn-back');
	const activeAvatar = document.getElementById('wa-active-avatar');
	const inputBar = document.getElementById('wa-input-bar');
	const inputEl = document.getElementById('wa-input');
	const sendBtn = document.getElementById('wa-send');
	const attachBtn = document.getElementById('wa-attach');
	const fileInput = document.getElementById('wa-file');
	const uploadHint = document.getElementById('wa-upload-hint');
	const attachPreviewEl = document.getElementById('wa-attach-preview');
	const searchEl = document.getElementById('wa-search');
	const chatSearchToggle = document.getElementById('wa-chat-search-toggle');
	const chatSearchPanel = document.getElementById('wa-chat-search-panel');
	const chatSearchField = document.getElementById('wa-chat-search-field');
	const chatSearchStatus = document.getElementById('wa-chat-search-status');
	const chatSearchPrev = document.getElementById('wa-chat-search-prev');
	const chatSearchNext = document.getElementById('wa-chat-search-next');
	const chatSearchClose = document.getElementById('wa-chat-search-close');
	const icoMic = sendBtn.querySelector('.ico-mic');
	const icoSend = sendBtn.querySelector('.ico-send');

	const recBar = document.getElementById('wa-rec-bar');
	const recTimerEl = document.getElementById('wa-rec-timer');
	const recCancel = document.getElementById('wa-rec-cancel');
	const recSend = document.getElementById('wa-rec-send');

	const replyBar = document.getElementById('wa-reply-bar');
	const replyThumbEl = document.getElementById('wa-reply-thumb');
	const replyAuthorEl = document.getElementById('wa-reply-author');
	const replyTextEl = document.getElementById('wa-reply-text');
	const replyCancel = document.getElementById('wa-reply-cancel');

	const lightbox = document.getElementById('wa-lightbox');
	const lightboxImg = document.getElementById('wa-lightbox-img');
	const lightboxClose = document.getElementById('wa-lightbox-close');
	const lightboxDl = document.getElementById('wa-lightbox-dl');
	let lightboxDownloadUrl = '';

	const fwdEl = document.getElementById('wa-fwd');
	const fwdListEl = document.getElementById('wa-fwd-list');
	const fwdSearchEl = document.getElementById('wa-fwd-search');
	const fwdPreviewEl = document.getElementById('wa-fwd-preview');
	const fwdTitleEl = document.getElementById('wa-fwd-title');
	const fwdGoBtn = document.getElementById('wa-fwd-go');
	const fwdCloseBtn = document.getElementById('wa-fwd-close');
	const bulkBarEl = document.getElementById('wa-bulkbar');
	const bulkBarTitleEl = document.getElementById('wa-bulkbar-title');
	const bulkBarCancelBtn = document.getElementById('wa-bulkbar-cancel');
	const bulkBarDownloadBtn = document.getElementById('wa-bulkbar-download');
	const bulkBarForwardBtn = document.getElementById('wa-bulkbar-forward');

	/* —— voice —— */
	let mediaRecorder = null;
	let mediaStream = null;
	let audioChunks = [];
	let recStartedAt = 0;
	let recTimerId = null;
	let recording = false;
	let pendingUploadFiles = [];

	function openLightbox(src, downloadUrl) {
		if (!src) return;
		lightboxDownloadUrl = downloadUrl || src;
		lightboxImg.src = src;
		lightbox.classList.add('open');
		document.body.style.overflow = 'hidden';
	}
	function closeLightbox() {
		lightbox.classList.remove('open');
		lightboxImg.removeAttribute('src');
		lightboxDownloadUrl = '';
		document.body.style.overflow = '';
	}
	lightboxClose.addEventListener('click', closeLightbox);
	lightboxDl.addEventListener('click', () => { if (lightboxDownloadUrl) window.open(lightboxDownloadUrl, '_blank'); });
	lightbox.addEventListener('click', e => { if (e.target === lightbox) closeLightbox(); });
	document.addEventListener('keydown', e => {
		if (e.key === 'Escape' && lightbox.classList.contains('open')) closeLightbox();
	});

	function waRestOauth(method, params) {
		params = params || {};
		const body = new URLSearchParams();
		body.set('auth', String(window.__WA_AID || ''));
		function flatten(obj, prefix) {
			Object.keys(obj || {}).forEach(function (k) {
				const v = obj[k];
				const key = prefix ? (prefix + '[' + k + ']') : k;
				if (v === null || v === undefined) return;
				if (Array.isArray(v)) {
					v.forEach(function (item, idx) {
						if (item !== null && typeof item === 'object') flatten(item, key + '[' + idx + ']');
						else body.append(key + '[' + idx + ']', String(item));
					});
				} else if (typeof v === 'object') {
					flatten(v, key);
				} else {
					body.set(key, String(v));
				}
			});
		}
		flatten(params);
		return fetch('/rest/' + method + '.json', {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString(),
			credentials: 'omit'
		}).then(function (r) { return r.json(); }).then(function (data) {
			if (!data || data.error) {
				const err = (data && (data.error_description || data.error)) || 'rest_error';
				throw new Error(String(err));
			}
			return data.result;
		});
	}

	function rest(method, params) {
		// OAuth AUTH_ID только в mobile noprolog (нет PHP-сессии).
		// На desktop после wa_tok → Authorize используем BX.rest + sessid (полные права юзера).
		if (window.__WA_AID && window.__WA_NOPROLOG) {
			return waRestOauth(method, params || {});
		}
		return new Promise((resolve, reject) => {
			if (!window.BX || !BX.rest || typeof BX.rest.callMethod !== 'function') {
				if (window.__WA_AID) {
					return waRestOauth(method, params || {}).then(resolve, reject);
				}
				reject(new Error('BX.rest unavailable'));
				return;
			}
			BX.rest.callMethod(method, params || {}, function (result) {
				if (result.error()) reject(result.error());
				else resolve(result.data());
			});
		});
	}

	function resolveDialogId(chat) {
		if (chat.id != null && chat.id !== '') return String(chat.id);
		if (chat.dialog_id) return String(chat.dialog_id);
		if (chat.chat_id) return 'chat' + chat.chat_id;
		if (chat.chat && chat.chat.id) return 'chat' + chat.chat.id;
		return null;
	}

	function getChatLineStatus(chat) {
		if (!chat) return NaN;
		return parseInt((chat.lines && chat.lines.status) ||
			(chat.chat && chat.chat.lines && chat.chat.lines.status), 10);
	}

	function isChatClosed(chat) {
		if (!chat) return false;
		if (chat._waClosed) return true;
		return CLOSED_LINE_STATUSES.indexOf(getChatLineStatus(chat)) !== -1;
	}

	function chatStorageId(chat) {
		return normalizeDialogId(resolveDialogId(chat)) ||
			(chat && chat.chat_id ? 'chat' + chat.chat_id : '') ||
			(chat && chat.chat && chat.chat.id ? 'chat' + chat.chat.id : '');
	}

	function getChatSortTime(chat) {
		const raw = (chat.message && chat.message.date) ||
			chat.date_update || chat.date_last_activity || chat._archivedAt || 0;
		if (typeof raw === 'string') {
			const ts = Date.parse(raw);
			return isNaN(ts) ? 0 : ts;
		}
		const n = parseInt(raw, 10) || 0;
		return n < 1e12 ? n * 1000 : n;
	}

	function sortChatsDesc(list) {
		return list.sort((a, b) => getChatSortTime(b) - getChatSortTime(a));
	}

	function mergeChatRecords(existing, incoming) {
		const merged = Object.assign({}, existing || {}, incoming || {});
		const existTime = getChatSortTime(existing);
		const incTime = getChatSortTime(incoming);
		if (existTime > incTime) {
			merged.message = (existing.message && existing.message.text)
				? existing.message : (incoming.message || existing.message);
			merged.date_update = existing.date_update || incoming.date_update;
		}
		const exActive = existing && !isChatClosed(existing);
		const incClosed = incoming && isChatClosed(incoming);
		if (exActive && incClosed && incoming._fromCrm) {
			merged.lines = Object.assign({}, existing.lines || {});
			merged._waClosed = false;
		} else if (isChatClosed(existing) || existing._waClosed) {
			if (!merged.lines) merged.lines = Object.assign({}, existing.lines || {});
			const closedStatus = getChatLineStatus(existing);
			if (CLOSED_LINE_STATUSES.indexOf(closedStatus) !== -1) {
				merged.lines.status = closedStatus;
			}
			merged._waClosed = true;
		}
		delete merged._fromCrm;
		merged._displayName = (incoming && incoming._displayName) || (existing && existing._displayName) || merged._displayName;
		return merged;
	}

	function markChatClosed(chat) {
		if (!chat) return chat;
		if (!chat.lines) chat.lines = {};
		if (CLOSED_LINE_STATUSES.indexOf(getChatLineStatus(chat)) === -1) {
			chat.lines.status = 50;
		}
		chat._waClosed = true;
		return chat;
	}

	function mergeChatLists() {
		const byId = {};
		for (let i = 0; i < arguments.length; i++) {
			(arguments[i] || []).forEach(chat => {
				const id = chatStorageId(chat);
				if (!id) return;
				byId[id] = byId[id] ? mergeChatRecords(byId[id], chat) : chat;
			});
		}
		return sortChatsDesc(Object.values(byId).filter(isOpenLine));
	}

	function dialogToChatItem(dialog, activity) {
		const chatId = parseInt(dialog.id, 10);
		if (!chatId) return null;
		const dialogId = dialog.dialog_id || ('chat' + chatId);
		const completed = activity && (activity.COMPLETED === 'Y' || activity.COMPLETED === '1');
		const item = {
			id: dialogId,
			dialog_id: dialogId,
			chat_id: chatId,
			title: dialog.name || dialog.title || (activity && activity.SUBJECT) || ('Чат #' + chatId),
			type: dialog.type || 'lines',
			entity_type: dialog.entity_type || 'LINES',
			entity_id: dialog.entity_id,
			entity_data_1: dialog.entity_data_1,
			entity_data_2: dialog.entity_data_2,
			entity_data_3: dialog.entity_data_3,
			counter: parseInt(dialog.counter || 0, 10),
			date_update: (activity && activity.LAST_UPDATED) || dialog.date_create,
			_fromCrm: true,
			chat: {
				id: chatId,
				type: dialog.type || 'lines',
				entity_type: dialog.entity_type || 'LINES',
				entity_id: dialog.entity_id,
				entity_data_1: dialog.entity_data_1,
				entity_data_2: dialog.entity_data_2,
				entity_data_3: dialog.entity_data_3,
				name: dialog.name || dialog.title
			},
			message: {
				text: (activity && activity.SUBJECT) || '',
				date: (activity && activity.LAST_UPDATED) || dialog.date_create
			}
		};
		if (completed) {
			item.lines = { status: 50 };
			item._waClosed = true;
		} else {
			const lineStatus = parseInt(dialog.lines && dialog.lines.status, 10);
			if (dialog.lines) item.lines = Object.assign({}, dialog.lines);
			if (CLOSED_LINE_STATUSES.indexOf(lineStatus) !== -1) {
				item._waClosed = true;
			}
		}
		return item;
	}

	const CRM_OWNER_TYPES = { 1: 'LEAD', 2: 'DEAL', 3: 'CONTACT', 4: 'COMPANY' };

	async function resolveChatIdByUserCode(userCode) {
		if (!userCode || failedUserCodeLookups.has(userCode)) return 0;
		try {
			const r = await fetch('/local/custom_chat/?wa_resolve_uc=' + encodeURIComponent(userCode), { credentials: 'same-origin' });
			if (!r.ok) throw new Error('resolve_uc_http_' + r.status);
			const data = await r.json();
			const cid = parseInt(data && data.chatId, 10);
			if (cid) return cid;
		} catch (e) {
			failedUserCodeLookups.add(userCode);
		}
		return 0;
	}

	async function fetchRecentOlChats() {
		let list = [];
		let offset = 0;
		const pageSize = 200;
		const maxOffset = 200;
		let hasMore = true;

		while (hasMore && offset < maxOffset) {
			let data;
			try {
				data = await rest('im.recent.list', {
					SKIP_OPENLINES: 'N',
					ONLY_OPENLINES: 'Y',
					LIMIT: pageSize,
					OFFSET: offset
				});
			} catch (e) {
				if (offset === 0) {
					data = await rest('im.recent.get', {
						SKIP_OPENLINES: 'N',
						ONLY_OPENLINES: 'Y'
					});
					const items = Array.isArray(data) ? data : (data && data.items) || [];
					return items.filter(isOpenLine);
				}
				break;
			}

			const items = (data && data.items) ? data.items : (Array.isArray(data) ? data : []);
			list = list.concat(items);
			hasMore = !!(data && data.hasMore) && items.length > 0;
			offset += items.length;
			if (items.length < pageSize) break;
		}

		return list.filter(isOpenLine);
	}

	function isOpenLine(chat) {
		if (!chat) return false;
		const type = (chat.chat && chat.chat.type) || chat.type || '';
		const entityType = (chat.chat && chat.chat.entity_type) || chat.entity_type || '';
		const id = resolveDialogId(chat) || '';
		return type === 'lines' || type === 'openlines' || entityType === 'LINES' ||
			id.indexOf('imol|') === 0 || !!(chat.lines || (chat.chat && chat.chat.lines));
	}

	function getOlConnectorHaystack(chat) {
		const parts = [
			chat.chat && chat.chat.entity_id,
			chat.entity_id,
			chat.chat && chat.chat.entity_data_1,
			chat.chat && chat.chat.entity_data_2,
			chat.chat && chat.chat.entity_data_3,
			resolveDialogId(chat),
			chat.user && chat.user.id,
			chat.user && chat.user.external_auth_id
		];
		return parts.filter(Boolean).join('|').toLowerCase();
	}

	function getWhatsAppGroupKey(chat) {
		if (!chat) return '';
		const parts = [
			chat.chat && chat.chat.entity_id,
			chat.entity_id,
			chat.chat && chat.chat.entity_data_1,
			chat.chat && chat.chat.entity_data_2,
			chat.chat && chat.chat.entity_data_3,
			resolveDialogId(chat),
		].filter(Boolean);
		for (let i = 0; i < parts.length; i++) {
			const raw = String(parts[i]);
			const segs = raw.split('|').filter(Boolean);
			if (segs.length >= 3 && /@g\.us$/i.test(segs[2])) {
				return segs[2].toLowerCase();
			}
			const m = raw.toLowerCase().match(/(\d{5,20}(?:-\d{5,20})?@g\.us|\d{10,20}@g\.us)/);
			if (m) return m[1];
		}
		return '';
	}

	function isWhatsAppGroupChat(chat) {
		if (!isOpenLine(chat)) return false;
		return /@g\.us\b/i.test(getOlConnectorHaystack(chat));
	}

	function getChatOpenLineId(chat) {
		const entityId = String(
			(chat && chat.chat && chat.chat.entity_id) ||
			(chat && chat.entity_id) ||
			''
		);
		const parts = entityId.split('|');
		return parts.length >= 2 ? (parseInt(parts[1], 10) || 0) : 0;
	}

	function isChatAllowedForPhoneSearch(chat) {
		if (!waAllowedLineIds || !waAllowedLineIds.size) return true;
		const lineId = getChatOpenLineId(chat);
		return lineId > 0 && waAllowedLineIds.has(lineId);
	}

	function isChatUnread(chat) {
		return parseInt(chat.counter || 0, 10) > 0 || !!chat.unread;
	}

	function applyLocalReadState(chat) {
		if (!chat) return chat;
		const id = normalizeDialogId(resolveDialogId(chat));
		if (!id || !localReadAt[id]) return chat;
		const msgTs = Math.floor(getChatSortTime(chat) / 1000);
		if (msgTs <= (localReadAt[id] + 3) || id === normalizeDialogId(currentDialogId)) {
			chat.counter = 0;
			chat.unread = false;
		}
		return chat;
	}

	function markCurrentChatReadLocally() {
		const id = normalizeDialogId(currentDialogId);
		if (!id) return;
		localReadAt[id] = Math.floor(Date.now() / 1000);
		const zero = function (c) {
			if (!c) return;
			c.counter = 0;
			c.unread = false;
		};
		zero(currentChatData);
		chatsCache.forEach(function (c) {
			if (normalizeDialogId(resolveDialogId(c)) === id) zero(c);
		});
	}

	function isPinnedCrmChat(chat) {
		const did = resolveDialogId(chat);
		return !!(did && (did === crmFocusDialogId || did === currentDialogId));
	}

	function applyListFilter(items) {
		items = items.filter(isOpenLine);
		if (listFilter === 'unread') items = items.filter(isChatUnread);
		else if (listFilter === 'groups') items = items.filter(c => isWhatsAppGroupChat(c) || isPinnedCrmChat(c));
		else items = items.filter(c => !isWhatsAppGroupChat(c) || isPinnedCrmChat(c));

		if (!searchQuery.trim()) {
			items = items.filter(c => !isChatClosed(c) || isPinnedCrmChat(c));
		}
		return items;
	}

	function dedupeChatsByPhone(items) {
		const byPhone = new Map();
		const byGroup = new Map();
		const restItems = [];
		items.forEach(function (chat) {
			if (isWhatsAppGroupChat(chat)) {
				const groupKey = getWhatsAppGroupKey(chat);
				if (!groupKey) {
					restItems.push(chat);
					return;
				}
				const prevGroup = byGroup.get(groupKey);
				if (!prevGroup || getChatSortTime(chat) > getChatSortTime(prevGroup)) {
					byGroup.set(groupKey, chat);
				}
				return;
			}
			const phone = getPrimaryPhone(chat);
			if (!phone || phone.length < 10) {
				restItems.push(chat);
				return;
			}
			const prev = byPhone.get(phone);
			const prefer =
				!prev ||
				(isChatClosed(prev) && !isChatClosed(chat)) ||
				(isChatClosed(prev) === isChatClosed(chat) && getChatSortTime(chat) > getChatSortTime(prev));
			if (prefer) {
				byPhone.set(phone, chat);
			}
		});
		return sortChatsDesc(restItems.concat(Array.from(byGroup.values()), Array.from(byPhone.values())));
	}

	function getVisibleChatBase() {
		let base = chatsCache.slice().filter(isOpenLine);
		if (searchQuery.trim()) {
			base = base.filter(function (c) { return matchesChatSearch(c, searchQuery); });
		}
		if (!searchQuery.trim()) {
			base = base.filter(function (c) { return !isChatClosed(c) || isPinnedCrmChat(c); });
		}
		return base;
	}

	function getTabCounts() {
		const base = getVisibleChatBase();
		return {
			all: base.filter(c => !isWhatsAppGroupChat(c)).length,
			unread: base.filter(isChatUnread).length,
			groups: base.filter(isWhatsAppGroupChat).length
		};
	}

	function updateTabUi() {
		const counts = getTabCounts();
		tabsEl.querySelectorAll('.wa-tab').forEach(btn => {
			const key = btn.dataset.filter;
			btn.classList.toggle('active', key === listFilter);
			const n = counts[key] || 0;
			const labels = { all: 'Чаты', unread: 'Непрочитанные', groups: 'Группы' };
			btn.innerHTML = labels[key] + (n > 0 ? '<span class="wa-tab-count">' + n + '</span>' : '');
		});
		broadcastUnreadToPortal();
	}

	function emptyListLabel() {
		const digits = normalizePhoneDigits(searchQuery);
		if (digits.length >= 10 && completedPhoneSearchKey !== digits) return 'Ищем чаты…';
		if (searchQuery.trim()) return 'Ничего не найдено';
		if (listFilter === 'unread') return 'Нет непрочитанных';
		if (listFilter === 'groups') return 'Нет групповых чатов';
		return 'Нет чатов';
	}

	function normalizeDialogId(id) {
		if (id == null || id === '') return '';
		const s = String(id);
		if (/^chat\d+$/i.test(s)) return s.toLowerCase();
		if (/^\d+$/.test(s)) return 'chat' + s;
		return s;
	}

	function pullMatchesCurrentChat(params) {
		if (!currentDialogId && !currentChatId) return false;
		const msg = params.message || {};
		const chatId = params.chatId || params.chat_id || msg.chat_id || msg.chatId;
		const dialogId = params.dialogId || params.dialog_id || msg.dialog_id || msg.dialogId;
		const curDialog = normalizeDialogId(currentDialogId);
		if (dialogId && normalizeDialogId(dialogId) === curDialog) return true;
		if (chatId && currentChatId && String(chatId) === String(currentChatId)) return true;
		if (currentChatId && curDialog === 'chat' + currentChatId) {
			if (dialogId && normalizeDialogId(dialogId) === curDialog) return true;
			if (chatId && String(chatId) === String(currentChatId)) return true;
		}
		return false;
	}

	let loadChatListTimer = null;
	function scheduleLoadChatList() {
		if (loadChatListTimer) return;
		loadChatListTimer = setTimeout(function () {
			loadChatListTimer = null;
			if (document.hidden) return;
			loadChatList();
		}, 2000);
	}

	function handlePullMessage(params, command) {
		params = params || {};
		if (!pullMatchesCurrentChat(params)) {
			scheduleLoadChatList();
			return;
		}
		const msg = params.message;
		const files = params.files || params.file;
		if (params.users) mergeUsers(params.users);
		if (files) mergeFiles(Array.isArray(files) ? files : Object.values(files));
		if (msg && msg.id) {
			if (String(command || '').toLowerCase().indexOf('update') !== -1) {
				msg._edited = true;
			}
			hydrateFilesFromMessages([msg]);
			prefetchFileUrls(getFileIds(msg));
			ensureUsersLoaded(collectMessageAuthorIds([msg])).then(function () {
				appendMessages([msg], false, { markEdited: !!msg._edited });
			});
		} else refreshTail().catch(() => {});
		markCurrentChatReadLocally();
		if (currentDialogId) rest('im.dialog.read', { DIALOG_ID: currentDialogId }).catch(() => {});
		scheduleLoadChatList();
	}

	function parseDateValue(raw) {
		if (raw == null || raw === '') return null;
		if (raw instanceof Date) return isNaN(raw.getTime()) ? null : raw;
		if (typeof raw === 'number') return new Date(raw < 1e12 ? raw * 1000 : raw);
		const s = String(raw).trim();
		if (!s) return null;
		if (/^\d{10}$/.test(s)) return new Date(parseInt(s, 10) * 1000);
		if (/^\d{13}$/.test(s)) return new Date(parseInt(s, 10));
		const d = new Date(s);
		return isNaN(d.getTime()) ? null : d;
	}

	function parseMessageDate(msg) {
		if (!msg) return null;
		return parseDateValue(
			msg.date || msg.DATE || msg.date_create || msg.DATE_CREATE ||
			msg.timestamp || msg.TIMESTAMP
		);
	}

	function formatTime(dateInput) {
		const d = dateInput instanceof Date ? dateInput : parseDateValue(dateInput);
		if (!d) return '';
		return d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
	}

	function formatMessageDayLabel(d) {
		const now = new Date();
		if (d.toDateString() === now.toDateString()) return 'Сегодня';
		const yesterday = new Date(now);
		yesterday.setDate(now.getDate() - 1);
		if (d.toDateString() === yesterday.toDateString()) return 'Вчера';
		return d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', year: 'numeric' });
	}

	function formatListTime(dateStr) {
		const d = parseDateValue(dateStr);
		if (!d) return '';
		const now = new Date();
		if (d.toDateString() === now.toDateString()) return formatTime(d);
		const yesterday = new Date(now);
		yesterday.setDate(now.getDate() - 1);
		if (d.toDateString() === yesterday.toDateString()) return 'Вчера';
		return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' });
	}

	function isGenericOlTitle(title) {
		const t = String(title || '').trim();
		if (!t) return true;
		if (/^(?:chat|чат)\s*#\d+$/i.test(t)) return true;
		if (/^(whatsapp(\s+green[\s-]?api(\.com)?)?|green[\s-]?api(\.com)?|wazzup|chatapp|гость|guest|visitor|клиент\s*#?\d*)$/i.test(t)) return true;
		const digits = t.replace(/\D/g, '');
		if (digits.length >= 10 && digits.length >= t.replace(/\s/g, '').length * 0.7) return true;
		return false;
	}

	function getChatRawTitle(chat) {
		if (!chat) return '';
		return String(
			chat.title ||
			(chat.chat && (chat.chat.name || chat.chat.title)) ||
			''
		).trim();
	}

	function normalizeGroupDisplayTitle(title) {
		let t = String(title || '').trim();
		if (!t) return '';

		const dash = t.match(/^(.+?)\s*[-–—]\s*(.+)$/u);
		if (dash) {
			const head = dash[1].trim();
			const tail = dash[2].trim();
			const olTail = /^(?:опт|уральск|marketing|whatsapp|green)/iu.test(tail)
				|| /\b\d{3,5}\s*$/.test(tail)
				|| /\+?\d{10,}/.test(tail)
				|| /\s+[А-Яа-яёA-Za-z]{2,}(?:\s+\d{3,5})?\s*$/.test(tail);
			if (olTail && head.length >= 2) {
				return head;
			}
		}

		t = t.replace(/\s[-–—]\s*(?:Опт|Уральск)\s+.+/iu, '').trim();
		t = t.replace(/\s+(?:Опт|Уральск)\s+[А-Яа-яёA-Za-z]+(?:\s+\d{3,5})?\s*$/iu, '').trim();
		t = t.replace(/\s+\+?\d[\d\s\-]{8,}\d\s*$/, '').trim();
		return t || String(title || '').trim();
	}

	function getWhatsAppGroupDisplayName(chat) {
		const key = getWhatsAppGroupKey(chat);
		const cached = key ? String(groupTitleCache.get(key) || '').trim() : '';
		if (cached && !isGenericOlTitle(cached)) return cached;
		const raw = getChatRawTitle(chat);
		const parsed = normalizeGroupDisplayTitle(raw);
		if (parsed && !isGenericOlTitle(parsed)) return parsed;
		return parsed || 'Группа';
	}

	async function fetchGroupTitleFromOl(chat) {
		const key = getWhatsAppGroupKey(chat);
		if (!key) return '';
		if (groupTitleCache.has(key)) return String(groupTitleCache.get(key) || '');
		const chatId = parseInt(chat && (chat.chat_id || (chat.chat && chat.chat.id)), 10) || 0;
		try {
			const url = new URL('/local/custom_chat/', window.location.origin);
			url.searchParams.set('wa_group_title', '1');
			if (chatId) url.searchParams.set('chat', String(chatId));
			else url.searchParams.set('group', key);
			const resp = await fetch(url.toString(), { credentials: 'same-origin' });
			if (!resp.ok) throw new Error('wa_group_title_http_' + resp.status);
			const data = await resp.json();
			const title = String((data && data.title) || '').trim();
			groupTitleCache.set(key, title);
			return title;
		} catch (e) {
			groupTitleCache.set(key, '');
			return '';
		}
	}

	function isOperatorUser(user) {
		if (!user) return true;
		const id = parseInt(user.id || user.ID, 10);
		if (id > 0 && id === CURRENT_USER_ID) return true;
		if (user.bot === true || user.isBot === true) return true;
		const type = String(user.type || '').toLowerCase();
		if (type === 'bot') return true;
		if (user.extranet === true || user.extranet === 'Y') return false;
		const ext = String(user.external_auth_id || user.externalAuthId || '').toLowerCase();
		if (ext.indexOf('imconnector') !== -1 || ext.indexOf('connector') !== -1 || ext.indexOf('whatsapp') !== -1) {
			return false;
		}
		if (type === 'extranet' || type === 'guest' || type === 'lines') return false;
		if (type === 'employee' || type === 'user') {
			if (user.work_position || user.workPosition || user.department) return true;
			if (user.extranet === false || user.extranet === 'N') return true;
		}
		return false;
	}

	function getChatClientUser(chat) {
		if (!chat) return null;
		const candidates = [];
		if (Array.isArray(chat.users)) candidates.push.apply(candidates, chat.users);
		if (chat.chat && Array.isArray(chat.chat.users)) candidates.push.apply(candidates, chat.chat.users);
		if (chat.opponent) candidates.push(chat.opponent);
		if (chat.user) candidates.push(chat.user);

		for (let i = 0; i < candidates.length; i++) {
			if (candidates[i] && !isOperatorUser(candidates[i])) return candidates[i];
		}
		return null;
	}

	function getUserPersonName(user) {
		if (!user || isOperatorUser(user)) return '';
		const first = String(user.first_name || user.firstName || user.NAME || '').trim();
		const last = String(user.last_name || user.lastName || user.LAST_NAME || '').trim();
		if (first || last) return (first + ' ' + last).trim();
		const full = String(user.name || user.full_name || '').trim();
		if (full && !isGenericOlTitle(full)) return full;
		return '';
	}

	function getCrmDisplayNameFromChat(chat) {
		const bindings = getCrmEntityIdsFromChat(chat);
		let name = '';
		if (bindings.contactId) name = crmNameCache.get('CONTACT:' + bindings.contactId) || '';
		if (!name && bindings.leadId) name = crmNameCache.get('LEAD:' + bindings.leadId) || '';
		return name;
	}

	function getChatDisplayName(chat) {
		if (!chat) return 'Чат';

		if (isWhatsAppGroupChat(chat)) {
			if (chat._displayName && !isGenericOlTitle(chat._displayName)) return chat._displayName;
			return getWhatsAppGroupDisplayName(chat);
		}

		if (chat._displayName) return chat._displayName;

		const clientName = getUserPersonName(getChatClientUser(chat));
		if (clientName) return clientName;

		if (chat._clientUserName && !isGenericOlTitle(chat._clientUserName)) {
			return chat._clientUserName;
		}

		const crmName = getCrmDisplayNameFromChat(chat);
		if (crmName) return crmName;

		const phone = getPrimaryPhone(chat);
		if (phone) return formatPhoneDisplay(phone);

		const raw = getChatRawTitle(chat);
		if (raw && !isGenericOlTitle(raw)) return raw;
		return raw || 'Чат';
	}

	function getCrmEntityIdsFromChat(chat) {
		const bindings = parseCrmBindings(getEntityData2(chat, null));
		const ownerTypeId = parseInt(chat && chat._crmOwnerTypeId, 10);
		const ownerId = parseInt(chat && chat._crmOwnerId, 10);
		const ownerType = CRM_OWNER_TYPES[ownerTypeId];
		if (ownerType === 'CONTACT' && ownerId && !bindings.contactId) bindings.contactId = ownerId;
		if (ownerType === 'LEAD' && ownerId && !bindings.leadId) bindings.leadId = ownerId;
		return bindings;
	}

	async function fetchCrmNamesBatch(type, ids) {
		const method = type === 'CONTACT' ? 'crm.contact.list' : (type === 'LEAD' ? 'crm.lead.list' : '');
		if (!method || !ids.length) return;

		const missing = ids.filter(function (id) { return !crmNameCache.has(type + ':' + id); });
		if (!missing.length) return;

		for (let i = 0; i < missing.length; i += 50) {
			const chunk = missing.slice(i, i + 50);
			try {
				const data = await rest(method, {
					filter: { ID: chunk },
					select: ['ID', 'NAME', 'LAST_NAME', 'TITLE']
				});
				const items = Array.isArray(data) ? data : (data && data.result) || [];
				const seen = new Set();
				items.forEach(function (entity) {
					const id = parseInt(entity.ID, 10);
					if (!id) return;
					seen.add(id);
					let name = [entity.NAME, entity.LAST_NAME].filter(Boolean).join(' ').trim();
					if (!name && entity.TITLE) name = String(entity.TITLE).trim();
					crmNameCache.set(type + ':' + id, name);
				});
				chunk.forEach(function (id) {
					if (!seen.has(id) && !crmNameCache.has(type + ':' + id)) {
						crmNameCache.set(type + ':' + id, '');
					}
				});
			} catch (e) {
				chunk.forEach(function (id) {
					if (!crmNameCache.has(type + ':' + id)) crmNameCache.set(type + ':' + id, '');
				});
			}
		}
	}

	async function enrichChatDisplayNames(chats) {
		if (!Array.isArray(chats) || !chats.length) return;

		const contactIds = new Set();
		const leadIds = new Set();
		const groupChats = [];

		chats.forEach(function (chat) {
			if (isWhatsAppGroupChat(chat)) {
				groupChats.push(chat);
				return;
			}
			const bindings = getCrmEntityIdsFromChat(chat);
			if (bindings.contactId) contactIds.add(bindings.contactId);
			if (bindings.leadId) leadIds.add(bindings.leadId);
		});

		await fetchCrmNamesBatch('CONTACT', Array.from(contactIds));
		await fetchCrmNamesBatch('LEAD', Array.from(leadIds));
		await Promise.all(groupChats.map(async function (chat) {
			const title = await fetchGroupTitleFromOl(chat);
			if (title && !isGenericOlTitle(title)) {
				chat._displayName = title;
			}
		}));

		chats.forEach(function (chat) {
			if (isWhatsAppGroupChat(chat)) {
				chat._displayName = getWhatsAppGroupDisplayName(chat);
				return;
			}

			let name = getUserPersonName(getChatClientUser(chat));
			if (!name && chat._clientUserName && !isGenericOlTitle(chat._clientUserName)) {
				name = chat._clientUserName;
			}
			if (!name) name = getCrmDisplayNameFromChat(chat);
			if (!name) {
				const phone = getPrimaryPhone(chat);
				if (phone) name = formatPhoneDisplay(phone);
			}
			if (!name) {
				const raw = getChatRawTitle(chat);
				if (raw && !isGenericOlTitle(raw)) name = raw;
			}
			if (name) chat._displayName = name;
		});
	}

	function initials(title) {
		const parts = String(title || '?').trim().split(/\s+/).filter(Boolean);
		if (!parts.length) return '?';
		if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
		return (parts[0][0] + parts[1][0]).toUpperCase();
	}

	function getAvatarData(chat) {
		const title = getChatDisplayName(chat);
		const clientUser = getChatClientUser(chat);
		const url =
			(chat.avatar && chat.avatar.url) ||
			(chat.chat && chat.chat.avatar) ||
			(clientUser && clientUser.avatar) ||
			(clientUser && clientUser.avatar_hr) ||
			'';
		const color =
			(chat.avatar && chat.avatar.color) ||
			(chat.chat && chat.chat.color) ||
			(clientUser && clientUser.color) ||
			'#00a884';
		const bad = !url || url.indexOf('blank.gif') !== -1 || url === '/bitrix/js/im/images/blank.gif';
		return { title, url: bad ? '' : url, color, initials: initials(title) };
	}

	function avatarHtml(av, sizeClass) {
		if (av.url) {
			return '<div class="wa-avatar' + (sizeClass || '') + '"><img src="' + BX.util.htmlspecialchars(av.url) + '" alt=""></div>';
		}
		return '<div class="wa-avatar' + (sizeClass || '') + '" style="background:' + BX.util.htmlspecialchars(av.color) + '">' +
			BX.util.htmlspecialchars(av.initials) + '</div>';
	}

	function setHeaderAvatar(av) {
		activeAvatar.style.display = 'flex';
		if (av.url) {
			activeAvatar.style.background = '#dfe5e7';
			activeAvatar.innerHTML = '<img src="' + BX.util.htmlspecialchars(av.url) + '" alt="">';
		} else {
			activeAvatar.style.background = av.color;
			activeAvatar.textContent = av.initials;
		}
	}

	function parseFileId(v) {
		if (v == null || v === '') return 0;
		if (Array.isArray(v)) {
			for (let i = 0; i < v.length; i++) {
				const id = parseFileId(v[i]);
				if (id) return id;
			}
			return 0;
		}
		if (typeof v === 'object') {
			return parseFileId(v.id || v.ID || v.fileId || v.FILE_ID || v.diskFileId || v.objectId || v.objectid);
		}
		const s = String(v).trim();
		const m = s.match(/^(?:n)?(\d+)$/i);
		if (m) return parseInt(m[1], 10) || 0;
		const n = parseInt(s, 10);
		return isNaN(n) ? 0 : n;
	}

	function pickFirstUrl() {
		for (let i = 0; i < arguments.length; i++) {
			const v = arguments[i];
			if (typeof v === 'string' && v.trim()) return v.trim();
		}
		return '';
	}

	function mediaPreviewUrl(media) {
		if (!media || typeof media !== 'object') return '';
		const preview = media.preview || media.Preview || media.sd || media.SD || '';
		if (typeof preview === 'string') return preview;
		if (preview && typeof preview === 'object') {
			return preview['250'] || preview['500'] || preview['1000'] || pickFirstUrl.apply(null, Object.values(preview));
		}
		return pickFirstUrl(media.hd, media.HD, media.previewUrl);
	}

	function normalizeMediaKind(type, ext, name) {
		const e = String(ext || '').toLowerCase().replace(/^\./, '');
		const n = String(name || '').toLowerCase();
		if (/^(mp3|ogg|oga|wav|m4a|opus|aac)$/i.test(e) || /voice|audio_message|голос|\bptt\b/i.test(n)) {
			return 'audio';
		}
		if (e === 'webm' && /voice|audio|ptt/i.test(n)) return 'audio';
		if (/^(mp4|mov|avi|mkv)$/i.test(e)) return 'video';
		if (/^(jpe?g|png|gif|webp|bmp|heic|heif)$/i.test(e)) return 'image';

		let t = String(type || '').toLowerCase().trim();
		if (t.indexOf('/') !== -1) t = t.split('/')[0];
		if (!t || t === 'file' || t === 'document') {
			if (e === 'webm') t = 'audio';
		}
		return t;
	}

	function absolutizePortalUrl(url) {
		const s = pickFirstUrl(url);
		if (!s) return '';
		if (/^(https?:|blob:|data:)/i.test(s)) return s;
		if (s.indexOf('//') === 0) return window.location.protocol + s;
		if (s.charAt(0) === '/') return window.location.origin + s;
		return s;
	}

	function normalizeFileRecord(raw, key) {
		if (!raw) return null;
		const viewer = raw.viewerAttrs || raw.viewerattrs || {};
		const id = parseFileId(
			raw.id || raw.ID || raw.fileId || raw.diskFileId ||
			viewer.objectId || viewer.objectid || key
		);
		if (!id) return null;
		const name = raw.name || raw.NAME || raw.originalName || raw.title ||
			viewer.title || ('file.' + (raw.extension || raw.EXTENSION || 'bin'));
		const ext = String(raw.extension || raw.EXTENSION || (String(name).split('.').pop() || '')).toLowerCase();
		const type = normalizeMediaKind(raw.type || raw.TYPE || raw.mediaType || viewer.viewertype || viewer.viewerType, ext, name);
		const media = raw.mediaUrl || raw.mediaurl || {};
		// Bitrix REST часто отдаёт urlpreview/urlshow/urldownload в lowercase!
		const urlPreview = absolutizePortalUrl(pickFirstUrl(
			raw.urlPreview, raw.urlpreview, raw.previewUrl, raw.previewImage,
			raw.urlPreviewDownload, mediaPreviewUrl(media), viewer.viewerResized, viewer.viewerresized
		));
		const urlShow = absolutizePortalUrl(pickFirstUrl(
			raw.urlShow, raw.urlshow, raw.showUrl, raw.viewUrl, raw.url,
			viewer.src, media.hd, media.HD, mediaPreviewUrl(media)
		));
		const urlDownload = absolutizePortalUrl(pickFirstUrl(
			raw.urlDownload, raw.urldownload, raw.downloadUrl, raw.src,
			viewer.src, media.hd, media.HD, urlShow, urlPreview
		));
		return {
			id: id,
			name: name,
			extension: ext,
			type: type,
			urlPreview: urlPreview,
			urlShow: urlShow,
			urlDownload: urlDownload,
			isVoiceNote: !!(raw.isVoiceNote || raw.isvoicenote)
		};
	}

	function mergeFiles(files) {
		if (!files) return;
		if (Array.isArray(files)) {
			files.forEach(function (raw) {
				const f = normalizeFileRecord(raw);
				if (f) filesMap[f.id] = Object.assign(filesMap[f.id] || {}, f);
			});
			return;
		}
		if (typeof files === 'object') {
			Object.keys(files).forEach(function (key) {
				const f = normalizeFileRecord(files[key], key);
				if (f) filesMap[f.id] = Object.assign(filesMap[f.id] || {}, f);
			});
		}
	}

	function msgParams(msg) {
		return (msg && (msg.params || msg.PARAMS)) || {};
	}

	function rememberMessages(messages) {
		(messages || []).forEach(function (msg) {
			const id = parseInt(msg && msg.id, 10);
			if (id > 0) messagesById[id] = msg;
		});
	}

	function firstParamScalar(v) {
		if (v == null || v === '') return '';
		if (Array.isArray(v)) return firstParamScalar(v[0]);
		if (typeof v === 'object') {
			return firstParamScalar(v.id || v.ID || v.REPLY_ID || v.value || '');
		}
		return v;
	}

	function getReplyId(msg) {
		const p = msgParams(msg);
		return parseInt(firstParamScalar(p.REPLY_ID || p.replyId || p.reply_id || msg.replyId || msg.reply_id) || 0, 10) || 0;
	}

	function cacheReplyPreview(id, author, text, fileIds, mediaKind) {
		id = parseInt(id, 10) || 0;
		if (!id) return;
		const prev = replyPreviewCache[id] || {};
		replyPreviewCache[id] = {
			id: id,
			author: author || prev.author || '',
			text: text || prev.text || '',
			fileIds: Array.isArray(fileIds) && fileIds.length ? fileIds.map(parseFileId).filter(Boolean) : (prev.fileIds || []),
			mediaKind: mediaKind || prev.mediaKind || ''
		};
	}

	function getReplyPreviewText(msg) {
		if (!msg) return '';
		let t = stripConnectorPrefix(messageRawText(msg));
		t = t.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
		if (!t) {
			const ids = getFileIds(msg);
			const first = ids.length ? filesMap[ids[0]] : null;
			const kind = first ? normalizeMediaKind(first.type, first.extension, first.name) : '';
			if (kind === 'image') t = 'Фото';
			else if (kind === 'video') t = 'Видео';
			else if (kind === 'audio') t = 'Голосовое сообщение';
			else t = ids.length ? 'Файл' : 'Медиа';
		}
		if (t.length > 140) t = t.slice(0, 137) + '...';
		return t;
	}

	function getReplyAuthorName(msg, out) {
		if (!msg) return '';
		if (out) return getOutgoingSenderName(msg) || CURRENT_USER_NAME || 'Вы';
		return getIncomingSenderName(msg) || 'Клиент';
	}

	function renderReplyQuoteHtml(replyId) {
		if (!replyId) return '';
		const orig = messagesById[replyId];
		const cached = replyPreviewCache[replyId] || {};
		const fileIds = orig ? getFileIds(orig) : (cached.fileIds || []);
		const firstFileId = fileIds.length ? parseFileId(fileIds[0]) : 0;
		const file = firstFileId ? (filesMap[firstFileId] || {}) : {};
		const kind = normalizeMediaKind(file.type || cached.mediaKind, file.extension, file.name);
		const out = orig ? isOutgoingMessage(orig) : false;
		const author = BX.util.htmlspecialchars(
			(orig ? getReplyAuthorName(orig, out) : '') || cached.author || 'Сообщение'
		);
		let previewText = (orig ? getReplyPreviewText(orig) : '') || cached.text || '';
		if (!previewText) previewText = kind === 'image' ? 'Фото' : (firstFileId ? 'Файл' : 'Ответ');
		const text = BX.util.htmlspecialchars(previewText);
		let thumb = '';
		if (firstFileId && kind === 'image') {
			const src = waMediaProxyUrl(firstFileId);
			thumb = '<img class="wa-msg-quote-thumb" src="' + BX.util.htmlspecialchars(src) +
				'" data-full="' + BX.util.htmlspecialchars(src) + '" alt="Фото">';
		}
		return '<div class="wa-msg-quote" data-reply-id="' + replyId + '">' +
			'<div class="wa-msg-quote-body"><span class="wa-msg-quote-author">' + author +
			'</span><span class="wa-msg-quote-text">' + text + '</span></div>' + thumb + '</div>';
	}

	function extractIncomingQuote(rawText) {
		const t = String(rawText || '').replace(/^\uFEFF/, '');
		if (t.indexOf('>>') !== 0) return null;
		const nl = t.search(/\r?\n/);
		if (nl < 3) return null;
		const quote = t.slice(2, nl).trim();
		const rest = t.slice(nl).replace(/^\r?\n/, '').trim();
		if (!quote || !rest) return null;
		return { quote: quote, text: rest };
	}

	function waMsgMetaUrl(ids) {
		const entry = window.__WA_NOPROLOG ? 'mobile.php' : 'index.php';
		const url = new URL(window.location.pathname.replace(/[^/]+$/, '') + entry, window.location.origin);
		url.searchParams.set('wa_msg_meta', '1');
		url.searchParams.set('ids', ids.join(','));
		if (window.__WA_AID && window.__WA_NOPROLOG) {
			url.searchParams.set('wa_aid', window.__WA_AID);
		}
		return url.toString();
	}

	async function hydrateReplyMeta(messages) {
		const ids = [];
		(messages || []).forEach(function (m) {
			const id = parseInt(m && m.id, 10);
			if (id) ids.push(id);
			const rid = getReplyId(m);
			if (rid && !messagesById[rid] && !replyPreviewCache[rid]) ids.push(rid);
		});
		const uniq = Array.from(new Set(ids)).filter(Boolean);
		if (!uniq.length) return;
		let items = {};
		try {
			const resp = await fetch(waMsgMetaUrl(uniq), { credentials: 'same-origin' });
			const data = await resp.json();
			items = (data && data.items) || {};
		} catch (e) {
			return;
		}
		Object.keys(items).forEach(function (key) {
			const row = items[key];
			if (!row) return;
			const id = parseInt(row.id || key, 10);
			if (!id) return;
			cacheReplyPreview(id, '', row.text || '', row.fileIds || [], row.mediaKind || '');
			if (Array.isArray(row.fileIds) && row.fileIds.length) {
				prefetchFileUrls(row.fileIds.map(parseFileId).filter(Boolean));
			}
			const msg = messagesById[id];
			if (msg && row.replyId) {
				msg.params = msg.params || msg.PARAMS || {};
				if (!getReplyId(msg)) msg.params.REPLY_ID = row.replyId;
				const quoted = items[String(row.replyId)] || items[row.replyId] || replyPreviewCache[row.replyId];
				if (quoted) {
					cacheReplyPreview(
						row.replyId,
						'',
						quoted.text || '',
						quoted.fileIds || [],
						quoted.mediaKind || ''
					);
				}
			}
		});
	}

	function applyPendingQuote(preview, imMessageId) {
		if (!preview || !preview.id) return;
		cacheReplyPreview(
			preview.id,
			preview.author || '',
			preview.text || '',
			preview.fileIds || [],
			preview.mediaKind || ''
		);
		let target = imMessageId ? messagesById[parseInt(imMessageId, 10)] : null;
		if (!target) {
			const lastId = getLastOutgoingMsgId();
			target = lastId ? messagesById[lastId] : null;
		}
		if (!target) return;
		target.params = target.params || target.PARAMS || {};
		if (!getReplyId(target)) target.params.REPLY_ID = preview.id;
		const node = messagesEl.querySelector('.wa-msg[data-id="' + target.id + '"]');
		if (node && !node.querySelector('.wa-msg-quote')) {
			node.insertAdjacentHTML('afterbegin', renderReplyQuoteHtml(preview.id));
		}
	}

	function updateReplyBar() {
		if (!replyBar) return;
		if (!replyTo || !replyTo.id) {
			replyBar.classList.remove('visible');
			if (replyAuthorEl) replyAuthorEl.textContent = '';
			if (replyTextEl) replyTextEl.textContent = '';
			if (replyThumbEl) {
				replyThumbEl.classList.remove('visible');
				replyThumbEl.removeAttribute('src');
			}
			return;
		}
		replyBar.classList.add('visible');
		if (replyAuthorEl) replyAuthorEl.textContent = replyTo.author || 'Сообщение';
		if (replyTextEl) replyTextEl.textContent = replyTo.text || '';
		if (replyThumbEl) {
			if (replyTo.thumbnail) {
				replyThumbEl.src = replyTo.thumbnail;
				replyThumbEl.classList.add('visible');
			} else {
				replyThumbEl.classList.remove('visible');
				replyThumbEl.removeAttribute('src');
			}
		}
	}

	function setReplyTo(msg) {
		if (!msg || isSystemMessage(msg)) return;
		const id = parseInt(msg.id, 10);
		if (!id) return;
		const out = isOutgoingMessage(msg);
		const fileIds = getFileIds(msg);
		const firstFileId = fileIds.length ? parseFileId(fileIds[0]) : 0;
		const firstFile = firstFileId ? (filesMap[firstFileId] || {}) : {};
		const mediaKind = normalizeMediaKind(firstFile.type, firstFile.extension, firstFile.name);
		replyTo = {
			id: id,
			author: getReplyAuthorName(msg, out),
			text: getReplyPreviewText(msg),
			connectorMid: extractConnectorMid(msg),
			fileIds: fileIds,
			mediaKind: mediaKind,
			thumbnail: firstFileId && mediaKind === 'image' ? waMediaProxyUrl(firstFileId) : ''
		};
		cacheReplyPreview(id, replyTo.author, replyTo.text, fileIds, mediaKind);
		updateReplyBar();
		inputEl.focus();
	}

	function clearReplyTo() {
		replyTo = null;
		updateReplyBar();
	}

	function extractConnectorMid(msg) {
		const p = msgParams(msg);
		let v = p.CONNECTOR_MID || p.connectorMid || p.CONNECTOR_MID || p.connector_mid || '';
		if (Array.isArray(v)) v = v[0] || '';
		if (v && typeof v === 'object') v = v.id || v.idMessage || v.CONNECTOR_MID || '';
		return String(v || '').trim();
	}

	function quoteSendEndpoint() {
		const entry = window.__WA_NOPROLOG ? 'mobile.php' : 'index.php';
		const url = new URL(window.location.pathname.replace(/[^/]+$/, '') + entry, window.location.origin);
		url.searchParams.set('wa_quote_send', '1');
		if (window.__WA_AID && window.__WA_NOPROLOG) {
			url.searchParams.set('wa_aid', window.__WA_AID);
		}
		return url.toString();
	}

	function quoteLinkEndpoint() {
		const entry = window.__WA_NOPROLOG ? 'mobile.php' : 'index.php';
		const url = new URL(window.location.pathname.replace(/[^/]+$/, '') + entry, window.location.origin);
		url.searchParams.set('wa_quote_link', '1');
		if (window.__WA_AID && window.__WA_NOPROLOG) {
			url.searchParams.set('wa_aid', window.__WA_AID);
		}
		return url.toString();
	}

	async function linkCommittedFileReply(fileId, replyId) {
		fileId = parseFileId(fileId);
		replyId = parseInt(replyId, 10) || 0;
		if (!fileId || !replyId || !currentChatId) return;
		const fd = new FormData();
		fd.append('sessid', BX.bitrix_sessid());
		fd.append('chatId', String(currentChatId));
		fd.append('fileId', String(fileId));
		fd.append('replyId', String(replyId));
		const resp = await fetch(quoteLinkEndpoint(), {
			method: 'POST',
			body: fd,
			credentials: 'same-origin'
		});
		const data = await resp.json().catch(function () { return null; });
		if (!resp.ok || !data || !data.ok) {
			throw new Error((data && data.error) || ('http_' + resp.status));
		}
	}

	async function sendQuotedViaGreen(opts) {
		opts = opts || {};
		if (!replyTo || !replyTo.id) return { fallback: 'im' };
		const fd = new FormData();
		fd.append('chatId', String(currentChatId || ''));
		fd.append('dialogId', String(currentDialogId || ''));
		fd.append('replyId', String(replyTo.id));
		if (replyTo.connectorMid) fd.append('quotedHint', replyTo.connectorMid);
		if (opts.text) fd.append('message', String(opts.text));
		if (opts.ptt) fd.append('ptt', '1');
		(opts.files || []).forEach(function (f) {
			if (f) fd.append('files[]', f, f.name || 'file');
		});
		const resp = await fetch(quoteSendEndpoint(), {
			method: 'POST',
			body: fd,
			credentials: 'same-origin'
		});
		let data = null;
		try { data = await resp.json(); } catch (e) { data = null; }
		if (data && data.ok) return data;
		if (data && data.fallback === 'im') return data;
		return {
			fallback: 'im',
			error: (data && (data.error || data.message)) || ('http_' + resp.status)
		};
	}

	function bindMessageReplyButton(div, msg) {
		if (!div || !msg || isSystemMessage(msg)) return;
		const wrap = document.createElement('div');
		wrap.className = 'wa-msg-actions';

		const replyBtn = document.createElement('button');
		replyBtn.type = 'button';
		replyBtn.className = 'wa-msg-reply-btn';
		replyBtn.title = 'Ответить';
		replyBtn.setAttribute('aria-label', 'Ответить');
		replyBtn.textContent = '↩';
		replyBtn.addEventListener('click', function (e) {
			e.preventDefault();
			e.stopPropagation();
			setReplyTo(msg);
		});

		const selectBtn = document.createElement('button');
		selectBtn.type = 'button';
		selectBtn.className = 'wa-msg-select-btn';
		selectBtn.title = 'Выбрать';
		selectBtn.setAttribute('aria-label', 'Выбрать');
		selectBtn.textContent = '✓';
		selectBtn.addEventListener('click', function (e) {
			e.preventDefault();
			e.stopPropagation();
			toggleMessageSelection(msg);
		});

		const fwdBtn = document.createElement('button');
		fwdBtn.type = 'button';
		fwdBtn.className = 'wa-msg-fwd-btn';
		fwdBtn.title = 'Переслать';
		fwdBtn.setAttribute('aria-label', 'Переслать');
		fwdBtn.textContent = '↗';
		fwdBtn.addEventListener('click', function (e) {
			e.preventDefault();
			e.stopPropagation();
			openForwardPicker(msg);
		});

		wrap.appendChild(replyBtn);
		wrap.appendChild(selectBtn);
		wrap.appendChild(fwdBtn);
		div.appendChild(wrap);
	}

	function forwardPlainText(msg) {
		let t = stripConnectorPrefix(messageRawText(msg));
		t = t.replace(/\[(?:DISK\s+)?FILE\s+ID=(?:n)?\d+\]/gi, '');
		t = t.replace(/\[br\]/gi, '\n').replace(/<br\s*\/?>/gi, '\n');
		t = t.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
		return t;
	}

	function getForwardCandidates(query) {
		const q = (query || '').trim();
		let items = mergeChatLists(chatsCache || [], forwardRemoteChats || []);
		if (!q) {
			items = items.filter(function (c) {
				return !isChatClosed(c) || isPinnedCrmChat(c);
			});
		} else {
			items = items.filter(function (c) { return matchesChatSearch(c, q); });
		}
		const cur = currentDialogId;
		items = items.filter(function (c) { return resolveDialogId(c) !== cur; });
		items = dedupeChatsByPhone(items);
		return sortChatsDesc(items).slice(0, 80);
	}

	function setForwardSearchLoading(flag) {
		forwardSearchLoading = !!flag;
		if (fwdListEl) {
			fwdListEl.classList.toggle('wa-list-searching', forwardSearchLoading);
		}
	}

	function updateFwdSendState() {
		if (!fwdGoBtn) return;
		fwdGoBtn.disabled = !forwardMessages.length || forwardSelected.size === 0;
		const n = forwardSelected.size;
		const msgCount = forwardMessages.length;
		if (fwdTitleEl) {
			const base = msgCount > 1 ? ('Переслать ' + msgCount + ' сообщений') : 'Переслать';
			fwdTitleEl.textContent = n ? (base + ' · ' + n) : base;
		}
	}

	function renderForwardList() {
		if (!fwdListEl) return;
		const items = getForwardCandidates(forwardSearchQuery);
		fwdListEl.innerHTML = '';
		if (!items.length) {
			const msg = forwardSearchLoading ? 'Ищем чаты…' : 'Ничего не найдено';
			fwdListEl.innerHTML = '<div class="wa-fwd-item" style="justify-content:center;color:#667781;">' + msg + '</div>';
			return;
		}
		items.forEach(function (chat) {
			const did = resolveDialogId(chat);
			const av = getAvatarData(chat);
			const phone = getPrimaryPhone(chat);
			const sub = phone ? formatPhoneDisplay(phone) : (isChatClosed(chat) ? 'завершён' : '');
			const row = document.createElement('div');
			row.className = 'wa-fwd-item' + (forwardSelected.has(did) ? ' on' : '');
			row.innerHTML =
				avatarHtml(av) +
				'<div class="wa-fwd-meta">' +
					'<div class="wa-fwd-name">' + BX.util.htmlspecialchars(av.title) + '</div>' +
					(sub ? '<div class="wa-fwd-sub">' + BX.util.htmlspecialchars(sub) + '</div>' : '') +
				'</div>' +
				'<div class="wa-fwd-check">' + (forwardSelected.has(did) ? '✓' : '') + '</div>';
			row.addEventListener('click', function () {
				if (forwardSelected.has(did)) forwardSelected.delete(did);
				else forwardSelected.add(did);
				renderForwardList();
				updateFwdSendState();
			});
			fwdListEl.appendChild(row);
		});
	}

	function closeForwardPicker() {
		forwardMessages = [];
		forwardSelected = new Set();
		forwardSearchQuery = '';
		forwardRemoteChats = [];
		setForwardSearchLoading(false);
		if (fwdSearchEl) fwdSearchEl.value = '';
		if (fwdEl) fwdEl.classList.remove('open');
		updateFwdSendState();
	}

	function openForwardPicker(msgOrMessages) {
		const list = Array.isArray(msgOrMessages) ? msgOrMessages.slice() : [msgOrMessages];
		const cleaned = list.filter(function (msg) {
			return msg && !isSystemMessage(msg) && parseInt(msg.id, 10);
		}).sort(function (a, b) {
			return (parseInt(a.id, 10) || 0) - (parseInt(b.id, 10) || 0);
		});
		if (!cleaned.length) return;
		forwardMessages = cleaned;
		forwardSelected = new Set();
		forwardSearchQuery = '';
		forwardRemoteChats = [];
		setForwardSearchLoading(false);
		if (fwdSearchEl) fwdSearchEl.value = '';
		if (fwdPreviewEl) {
			if (cleaned.length === 1) {
				const preview = getReplyPreviewText(cleaned[0]);
				fwdPreviewEl.textContent = preview || '[медиа]';
			} else {
				const withFiles = cleaned.filter(function (msg) { return getFileIds(msg).length > 0; }).length;
				fwdPreviewEl.textContent =
					'Сообщений: ' + cleaned.length +
					(withFiles ? (' · с файлами: ' + withFiles) : '');
			}
		}
		renderForwardList();
		updateFwdSendState();
		if (fwdEl) fwdEl.classList.add('open');
		setTimeout(function () {
			if (fwdSearchEl) fwdSearchEl.focus();
		}, 50);
	}

	async function ensureChatCanReceive(chat) {
		const chatId = parseInt(chat.chat_id || (chat.chat && chat.chat.id), 10);
		if (!chatId) return;
		if (isChatClosed(chat)) {
			await rest('imopenlines.session.start', { CHAT_ID: chatId });
			try {
				await rest('imopenlines.operator.answer', { CHAT_ID: chatId });
			} catch (e) { /* already taken */ }
			if (!chat.lines) chat.lines = {};
			chat.lines.status = 20;
			chat._waClosed = false;
			return;
		}
		const st = getChatLineStatus(chat);
		if ([0, 5, 10].indexOf(st) !== -1 || isNaN(st)) {
			try {
				await rest('imopenlines.operator.answer', { CHAT_ID: chatId });
			} catch (e) { /* ignore */ }
		}
	}

	function guessFwdFileName(fileId, blob) {
		const f = filesMap[fileId] || {};
		let name = String(f.name || '').trim();
		if (name && !/^file\.\d+$/i.test(name) && !/^\d+$/.test(name) && name.indexOf('.') !== -1) {
			return name;
		}
		let ext = String(f.extension || '').replace(/^\./, '');
		if (!ext && blob && blob.type) {
			const mime = String(blob.type).toLowerCase();
			if (mime.indexOf('ogg') !== -1) ext = 'ogg';
			else if (mime.indexOf('jpeg') !== -1) ext = 'jpg';
			else if (mime.indexOf('png') !== -1) ext = 'png';
			else if (mime.indexOf('mp4') !== -1) ext = 'mp4';
			else if (mime.indexOf('webm') !== -1) ext = 'webm';
			else if (mime.indexOf('pdf') !== -1) ext = 'pdf';
		}
		if (!ext) {
			if (isAudioMediaFile(f)) ext = 'ogg';
			else if (isImageFileRecord(f)) ext = 'jpg';
			else ext = 'bin';
		}
		return 'fwd_' + fileId + '.' + ext;
	}

	async function blobFileFromChatMedia(fileId) {
		const url = waMediaProxyUrl(fileId);
		const resp = await fetch(url, { credentials: 'same-origin' });
		if (!resp.ok) throw new Error('Не скачался файл #' + fileId);
		const blob = await resp.blob();
		const name = guessFwdFileName(fileId, blob);
		const type = blob.type || 'application/octet-stream';
		return new File([blob], name, { type: type });
	}

	async function sendForwardToChat(chat, msg) {
		await ensureChatCanReceive(chat);
		const dialogId = resolveDialogId(chat);
		const text = forwardPlainText(msg);
		const fileIds = getFileIds(msg);
		if (fileIds.length) {
			for (let i = 0; i < fileIds.length; i++) {
				const file = await blobFileFromChatMedia(fileIds[i]);
				const caption = (i === 0) ? text : '';
				await uploadViaDiskCommit(file, caption, true, chat);
			}
			return;
		}
		if (!text) throw new Error('Пустое сообщение');
		await rest('im.message.add', { DIALOG_ID: dialogId, MESSAGE: text });
	}

	async function confirmForward() {
		if (!forwardMessages.length || !forwardSelected.size || sending) return;
		const targets = [];
		forwardSelected.forEach(function (did) {
			const chat = (chatsCache || []).find(function (c) { return resolveDialogId(c) === did; });
			if (chat) targets.push(chat);
		});
		if (!targets.length) return;

		sending = true;
		if (fwdGoBtn) fwdGoBtn.disabled = true;
		const errors = [];
		try {
			for (let i = 0; i < targets.length; i++) {
				if (fwdTitleEl) fwdTitleEl.textContent = 'Отправка ' + (i + 1) + '/' + targets.length + '…';
				try {
					for (let j = 0; j < forwardMessages.length; j++) {
						await sendForwardToChat(targets[i], forwardMessages[j]);
					}
				} catch (e) {
					const name = getAvatarData(targets[i]).title;
					errors.push(name + ': ' + (e && (e.ex && e.ex.error_description || e.error_description || e.message) || e));
				}
			}
			closeForwardPicker();
			clearSelectedMessages();
			loadChatList();
			if (errors.length) {
				alert('Переслано с ошибками:\n' + errors.join('\n'));
			}
		} finally {
			sending = false;
			updateFwdSendState();
		}
	}

	function hydrateFilesFromMessages(messages) {
		(messages || []).forEach(function (msg) {
			const p = msgParams(msg);
			const viewer = p.viewerAttrs || p.viewerattrs || {};
			const ids = getFileIds(msg);
			if (!ids.length) return;
			const src = pickFirstUrl(p.src, p.SRC, viewer.src, viewer.viewerSrc);
			const media = msg.mediaUrl || msg.mediaurl || {};
			const preview = mediaPreviewUrl(media);
			const name = p.title || viewer.title || viewer.viewerTitle || '';
			const extFromName = name ? String(name.split('.').pop() || '').toLowerCase() : '';
			let type = normalizeMediaKind(
				viewer.viewertype || viewer.viewerType || p.FILE_TYPE || p.fileType || '',
				extFromName,
				name
			);
			if (msg.isVoiceNote || msg.isvoicenote) type = 'audio';
			if (msg.isVideoNote || msg.isvideonote) type = 'video';
			ids.forEach(function (fid) {
				const prev = filesMap[fid] || { id: fid };
				const mergedType = (type === 'audio' || type === 'video') ? type : (prev.type || type);
				filesMap[fid] = Object.assign(prev, {
					id: fid,
					name: prev.name || name || prev.name,
					extension: prev.extension || extFromName,
					type: mergedType,
					urlPreview: prev.urlPreview || absolutizePortalUrl(preview || src),
					urlShow: prev.urlShow || absolutizePortalUrl(src || preview),
					urlDownload: prev.urlDownload || absolutizePortalUrl(src || preview),
					isVoiceNote: prev.isVoiceNote || !!(msg.isVoiceNote || msg.isvoicenote)
				});
			});
		});
	}

	function getFileIds(msg) {
		const ids = new Set();
		const p = msgParams(msg);
		['FILE_ID', 'FILE', 'fileId', 'FILE_IDS', 'objectId', 'objectid'].forEach(function (key) {
			let val = p[key];
			if (val == null || val === '') return;
			if (!Array.isArray(val)) val = [val];
			val.forEach(function (v) {
				const id = parseFileId(v);
				if (id) ids.add(id);
			});
		});
		const viewer = p.viewerAttrs || p.viewerattrs || {};
		const vid = parseFileId(viewer.objectId || viewer.objectid);
		if (vid) ids.add(vid);
		if (Array.isArray(msg.files)) {
			msg.files.forEach(function (f) {
				const id = parseFileId(f);
				if (id) ids.add(id);
			});
		}
		const text = msg.text || '';
		let match;
		const re = /\[?(?:DISK\s+)?FILE\s+ID=(?:n)?(\d+)\]?/gi;
		while ((match = re.exec(text)) !== null) {
			ids.add(parseInt(match[1], 10));
		}
		return Array.from(ids);
	}

	function bulkDownloadUrl(fileId) {
		return waMediaProxyUrl(fileId) + '&download=1';
	}

	function bulkZipUrl(fileIds) {
		const ids = (fileIds || []).map(function (id) { return parseInt(id, 10) || 0; }).filter(Boolean);
		let url;
		if (window.__WA_NOPROLOG) {
			const entry = 'mobile.php';
			const base = window.location.pathname.replace(/[^/]+$/, '') + entry;
			url = new URL(base, window.location.origin);
		} else {
			url = new URL(window.location.href);
			url.search = '';
			url.hash = '';
		}
		url.searchParams.set('wa_bulk_zip', '1');
		url.searchParams.set('chat', String(currentChatId || 0));
		url.searchParams.set('ids', ids.join(','));
		if (window.__WA_AID && window.__WA_NOPROLOG) {
			url.searchParams.set('wa_aid', window.__WA_AID);
		}
		return url.toString();
	}

	function isMessageSelected(msgId) {
		return selectedMessageIds.has(parseInt(msgId, 10) || 0);
	}

	function syncSelectedMessageDom(msgId) {
		const id = parseInt(msgId, 10) || 0;
		if (!id) return;
		const node = messagesEl.querySelector('.wa-msg[data-id="' + id + '"]');
		if (node) node.classList.toggle('selected', selectedMessageIds.has(id));
	}

	function getSelectedMessages() {
		return Array.from(selectedMessageIds)
			.map(function (id) { return messagesById[id] || null; })
			.filter(Boolean)
			.sort(function (a, b) { return (parseInt(a.id, 10) || 0) - (parseInt(b.id, 10) || 0); });
	}

	function collectSelectedFileIds() {
		const out = new Set();
		getSelectedMessages().forEach(function (msg) {
			getFileIds(msg).forEach(function (id) {
				if (id) out.add(id);
			});
		});
		return Array.from(out);
	}

	function updateBulkBar() {
		const count = selectedMessageIds.size;
		const fileCount = collectSelectedFileIds().length;
		if (bulkBarEl) bulkBarEl.classList.toggle('visible', count > 0);
		if (bulkBarTitleEl) {
			bulkBarTitleEl.textContent = count
				? ('Выбрано: ' + count + (fileCount ? (' · файлов: ' + fileCount) : ''))
				: 'Выбрано: 0';
		}
		if (bulkBarDownloadBtn) bulkBarDownloadBtn.disabled = count <= 0 || fileCount <= 0;
		if (bulkBarForwardBtn) bulkBarForwardBtn.disabled = count <= 0;
	}

	function clearSelectedMessages() {
		if (!selectedMessageIds.size) return;
		const ids = Array.from(selectedMessageIds);
		selectedMessageIds.clear();
		ids.forEach(syncSelectedMessageDom);
		updateBulkBar();
	}

	function toggleMessageSelection(msg) {
		if (!msg || isSystemMessage(msg)) return;
		const id = parseInt(msg.id, 10) || 0;
		if (!id) return;
		if (selectedMessageIds.has(id)) selectedMessageIds.delete(id);
		else selectedMessageIds.add(id);
		syncSelectedMessageDom(id);
		updateBulkBar();
	}

	async function downloadSelectedFiles() {
		const fileIds = collectSelectedFileIds();
		if (!fileIds.length) {
			alert('В выбранных сообщениях нет файлов');
			return;
		}
		if (fileIds.length <= 5) {
			fileIds.forEach(function (fileId, idx) {
				setTimeout(function () {
					const a = document.createElement('a');
					a.href = bulkDownloadUrl(fileId);
					a.target = '_blank';
					a.rel = 'noopener';
					document.body.appendChild(a);
					a.click();
					a.remove();
				}, idx * 180);
			});
			return;
		}
		window.open(bulkZipUrl(fileIds), '_blank');
	}

	function collectFileIdsFromMessages(messages) {
		const ids = new Set();
		(messages || []).forEach(function (msg) {
			getFileIds(msg).forEach(function (id) { ids.add(id); });
		});
		return Array.from(ids);
	}

	function isImageFileRecord(f) {
		if (!f || isAudioMediaFile(f)) return false;
		const ext = (f.extension || '').toLowerCase().replace(/^\./, '');
		const name = (f.name || '').toLowerCase();
		if (/^(jpe?g|png|gif|webp|bmp|heic|heif)$/i.test(ext) ||
			/\.(jpe?g|png|gif|webp|bmp|heic|heif)(\?|$)/i.test(name)) {
			return true;
		}
		const preview = String(f.urlPreview || '');
		return (f.type === 'image' || normalizeMediaKind(f.type, f.extension, f.name) === 'image') &&
			preview && preview.indexOf('wa_media=') === -1;
	}

	async function prefetchFileUrls(fileIds) {
		if (!fileIds.length) return;
		fileIds.forEach(function (id) {
			const f = filesMap[id];
			if (f && (f.urlPreview || f.urlShow || f.urlDownload)) return;
			const proxy = waMediaProxyUrl(id);
			if (!proxy) return;
			const prev = filesMap[id] || { id: id };
			filesMap[id] = Object.assign(prev, {
				id: id,
				urlDownload: prev.urlDownload || proxy,
				urlShow: prev.urlShow || proxy,
				urlPreview: prev.urlPreview || proxy
			});
		});
	}

	function matchConnectorOperatorPrefix(text) {
		// Green-API: [b]WhatsApp Green-Api.com[/b] или [b]Имя[/b]: текст
		return (text || '').match(/^\s*\[(?:b|B)\]([^\]]+)\[\/(?:b|B)\]\s*:?\s*/);
	}

	function messageAuthorId(msg) {
		if (!msg) return 0;
		return parseInt(
			msg.authorId || msg.AUTHOR_ID || msg.author_id ||
			msg.senderId || msg.SENDER_ID || msg.sender_id ||
			0,
			10
		) || 0;
	}

	function isConnectorOperatorLabel(label) {
		return /whatsapp|green-?api|chatapp|wazzup|telegram|open.?line|открыт(ая|ой)\s+лин/i.test(String(label || ''));
	}

	function isSystemSenderLabel(label) {
		return /контактная информация|сохранена в (лид|контакт|компани)|начат новый диалог|обращение направлен|завершил[аи]? работу|начал[аи]? работу|диалог закрыт|перевед[её]н/i.test(String(label || ''));
	}

	function isUsableSenderLabel(label) {
		const s = String(label || '').trim();
		if (!s) return false;
		if (isConnectorOperatorLabel(s)) return false;
		if (isSystemSenderLabel(s)) return false;
		return true;
	}

	function isConnectorOperatorText(text) {
		const from = parseConnectorFrom(text);
		return !!(from && isConnectorOperatorLabel(from));
	}

	function parseConnectorFrom(text) {
		const m = matchConnectorOperatorPrefix(text);
		return m ? m[1].trim() : null;
	}

	function messageRawText(msg) {
		if (!msg) return '';
		return String(
			msg.text || msg.MESSAGE || msg.message || msg.message_out || ''
		);
	}

	function parsePlainNamePrefix(text) {
		const t = String(text || '');
		let m = t.match(/^\s*\*([^*\n]{2,80})\*\s*:?\s*/);
		if (m) return { name: m[1].trim(), rest: t.slice(m[0].length) };
		m = t.match(/^\s*([^\n:]{2,80})\s*\(\+?\d[\d\s\-]{8,}\)\s*:\s*/);
		if (m) return { name: m[1].trim(), rest: t.slice(m[0].length) };
		// патч 1os / Green-API: «Салта Опт 77775215321\nтекст» или «Салта Опт 77775215321: текст»
		m = t.match(/^\s*([^\n\[\*]{2,60}?)\s+(\d{10,15})\s*(?:\n|:)\s*/);
		if (m) {
			const name = (m[1].trim() + ' ' + m[2]).trim();
			return { name: name, rest: t.slice(m[0].length) };
		}
		m = t.match(/^\s*(\d{10,15})\s*(?:\n|:)\s*/);
		if (m) return { name: m[1], rest: t.slice(m[0].length) };
		return null;
	}

	function parseHumanSenderFromText(text) {
		let t = String(text || '').replace(/^\uFEFF/, '');
		// HTML после конвертации BB: <b>Имя</b>
		while (true) {
			const m = t.match(/^\s*<b>([^<]{1,80})<\/b>\s*:?\s*(?:<br\s*\/?>)?\s*/i);
			if (!m) break;
			const label = m[1].replace(/&nbsp;/g, ' ').trim();
			t = t.slice(m[0].length);
			if (label && isUsableSenderLabel(label)) return label;
		}
		while (true) {
			const m = t.match(/^\s*\[(?:b|B)\]([^\]]+)\[\/(?:b|B)\]\s*:?\s*(?:\n|\[br\]|<br\s*\/?>)?\s*/i);
			if (!m) break;
			const label = m[1].trim();
			t = t.slice(m[0].length);
			if (label && isUsableSenderLabel(label)) return label;
		}
		const plain = parsePlainNamePrefix(t);
		if (plain && plain.name && isUsableSenderLabel(plain.name)) return plain.name;
		return '';
	}

	function stripConnectorPrefix(text) {
		let t = String(text || '');
		while (/^\s*<b>[^<]+<\/b>\s*:?\s*(?:<br\s*\/?>)?\s*/i.test(t)) {
			t = t.replace(/^\s*<b>[^<]+<\/b>\s*:?\s*(?:<br\s*\/?>)?\s*/i, '');
		}
		while (/^\s*\[(?:b|B)\][^\]]+\[\/(?:b|B)\]\s*:?\s*(?:\n|\[br\]|<br\s*\/?>)?\s*/i.test(t)) {
			t = t.replace(/^\s*\[(?:b|B)\][^\]]+\[\/(?:b|B)\]\s*:?\s*(?:\n|\[br\]|<br\s*\/?>)?\s*/i, '');
		}
		const plain = parsePlainNamePrefix(t);
		if (plain) return plain.rest;
		return t;
	}

	function isConnectorGuestUser(user) {
		if (!user) return false;
		if (user.bot === true || user.isBot === true) return false;
		const type = String(user.type || '').toLowerCase();
		if (type === 'bot') return false;
		const ext = String(user.external_auth_id || user.externalAuthId || user.EXTERNAL_AUTH_ID || '').toLowerCase();
		if (/imconnector|connector|whatsapp|bot|replica/i.test(ext)) return true;
		if (type === 'extranet' || type === 'guest' || type === 'lines') return true;
		if (user.extranet === true || user.extranet === 'Y') return true;
		return false;
	}

	function isStaffPortalUser(user) {
		if (!user) return false;
		if (user.bot === true || user.isBot === true) return false;
		const type = String(user.type || '').toLowerCase();
		if (type === 'bot') return false;
		if (isConnectorGuestUser(user)) return false;
		const id = parseInt(user.id || user.ID, 10);
		if (id > 0 && id === CURRENT_USER_ID) return true;
		if (type === 'employee' || type === 'user' || type === '') {
			if (user.extranet === false || user.extranet === 'N') return true;
			if (user.work_position || user.workPosition || user.department) return true;
			const ext = String(user.external_auth_id || user.externalAuthId || user.EXTERNAL_AUTH_ID || '').toLowerCase();
			if (!ext) return true;
		}
		return false;
	}

	function isConnectorOperatorMessage(msg) {
		return isConnectorOperatorText(msg.text || '');
	}

	function mergeUsers(users) {
		if (!users) return;
		if (Array.isArray(users)) {
			users.forEach(function (u) {
				const id = parseInt(u && (u.id || u.ID), 10);
				if (id) usersMap[id] = Object.assign(usersMap[id] || {}, u);
			});
			return;
		}
		if (typeof users === 'object') {
			Object.keys(users).forEach(function (k) {
				const u = users[k];
				if (!u || typeof u !== 'object') return;
				const id = parseInt(u.id || u.ID || k, 10);
				if (id) usersMap[id] = Object.assign(usersMap[id] || {}, u);
			});
		}
	}

	function collectMessageAuthorIds(messages) {
		const ids = [];
		(messages || []).forEach(function (msg) {
			const authorId = messageAuthorId(msg);
			if (authorId > 0) ids.push(authorId);
			const p = msgParams(msg);
			const opId = parseInt(p.OPERATOR_ID || p.operatorId || 0, 10);
			if (opId > 0 && opId !== CURRENT_USER_ID) ids.push(opId);
		});
		return ids;
	}

	async function ensureUsersLoaded(ids) {
		const missing = [];
		(ids || []).forEach(function (id) {
			id = parseInt(id, 10);
			if (id > 0 && !userDisplayName(usersMap[id])) missing.push(id);
		});
		if (!missing.length) return;
		const uniq = Array.from(new Set(missing));
		try {
			const data = await rest('im.user.list.get', { ID: uniq });
			mergeUsers(data);
		} catch (e) {
			try {
				const data = await rest('user.get', { FILTER: { ID: uniq.join('|') }, ADMIN_MODE: 'N' });
				mergeUsers(data);
			} catch (e2) {
				for (let i = 0; i < uniq.length; i++) {
					try {
						const one = await rest('user.get', { ID: uniq[i] });
						mergeUsers(Array.isArray(one) ? one : [one]);
					} catch (e3) {}
				}
			}
		}
	}

	function userDisplayName(user) {
		if (!user) return '';
		const n = String(user.name || user.NAME || '').trim();
		if (n) return n;
		const first = String(user.first_name || user.FIRST_NAME || user.firstName || '').trim();
		const last = String(user.last_name || user.LAST_NAME || user.lastName || '').trim();
		return [first, last].filter(Boolean).join(' ');
	}

	function senderColor(name) {
		const colors = ['#06cf9c', '#02a698', '#53bdeb', '#a855f7', '#ef4444', '#f59e0b', '#ec4899', '#3b82f6', '#14b8a6', '#e11d48'];
		let h = 0;
		const s = String(name || '');
		for (let i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) >>> 0;
		return colors[h % colors.length];
	}

	function getIncomingSenderName(msg) {
		const human = parseHumanSenderFromText(messageRawText(msg));
		if (human) return human;
		// в группе OL-гость один на весь чат (часто «Юлия») — это не автор сообщения
		if (currentChatData && isWhatsAppGroupChat(currentChatData)) return '';
		const p = msgParams(msg);
		const fromP = p.NAME || p.AUTHOR_NAME || p.USER_NAME || p.IMOL_USER_NAME
			|| p.FROM || p.senderName || p.SENDER_NAME || p.name;
		if (fromP && typeof fromP === 'string' && fromP.trim() && !isConnectorOperatorLabel(fromP)) {
			return fromP.trim();
		}
		const authorId = messageAuthorId(msg);
		if (authorId && authorId !== CURRENT_USER_ID) {
			const n = userDisplayName(usersMap[authorId]);
			if (n && !isConnectorOperatorLabel(n)) return n;
		}
		return '';
	}

	function isViewerStamp(name, authorId) {
		if (!CURRENT_USER_NAME) return false;
		if (parseInt(authorId || 0, 10) === CURRENT_USER_ID) return false;
		return String(name || '').trim() === CURRENT_USER_NAME;
	}

	function getOutgoingSenderName(msg) {
		const authorId = messageAuthorId(msg);
		if (authorId === CURRENT_USER_ID && CURRENT_USER_NAME) return CURRENT_USER_NAME;
		if (authorId > 0) {
			const n = userDisplayName(usersMap[authorId]);
			if (n && !isConnectorOperatorLabel(n) && !isViewerStamp(n, authorId)) return n;
		}
		const human = parseHumanSenderFromText(messageRawText(msg));
		if (human && !isViewerStamp(human, authorId)) return human;
		const p = msgParams(msg);
		const fromP = p.AUTHOR_NAME || p.OPERATOR_NAME || p.MANAGER_NAME;
		if (fromP && typeof fromP === 'string' && fromP.trim()
			&& !isConnectorOperatorLabel(fromP) && !isViewerStamp(fromP, authorId)) {
			return fromP.trim();
		}
		const opId = parseInt(p.OPERATOR_ID || p.operatorId || 0, 10);
		if (opId > 0 && opId !== CURRENT_USER_ID) {
			const n = userDisplayName(usersMap[opId]);
			if (n && !isConnectorOperatorLabel(n)) return n;
		}
		return '';
	}

	function seedCurrentUser() {
		if (CURRENT_USER_ID && CURRENT_USER_NAME) {
			usersMap[CURRENT_USER_ID] = Object.assign(usersMap[CURRENT_USER_ID] || {}, {
				id: CURRENT_USER_ID,
				name: CURRENT_USER_NAME
			});
		}
	}

	function isSystemMessage(msg) {
		const text = msg.text || '';
		if (/начал работу с диалогом|завершил работу|диалог закрыт|перевед[её]н|поставил оценку|пригласил|покинул|начат новый диалог|контактная информация сохранена|обращение направлено/i.test(text)) {
			return true;
		}
		if (/\[USER=\d+/i.test(text) && /начал|завершил|пригласил|покинул|направлен/i.test(text)) {
			return true;
		}
		const authorId = messageAuthorId(msg);
		if (authorId !== 0) return false;
		if (isConnectorOperatorMessage(msg)) return false;
		if (parseConnectorFrom(text) && !isConnectorOperatorLabel(parseConnectorFrom(text))) return false;
		if (parsePlainNamePrefix(text)) return false;
		if (getFileIds(msg).length) return false;
		const p = msgParams(msg);
		const code = Array.isArray(p.CODE) ? String(p.CODE[0] || '') : String(p.CODE || '');
		if (/^(SYSTEM|IMOL_|CHAT_|NOTIFY)/i.test(code)) return true;
		return false;
	}

	function isOutgoingMessage(msg) {
		const authorId = messageAuthorId(msg);
		if (authorId === CURRENT_USER_ID) return true;
		if (authorId > 0) {
			const u = usersMap[authorId];
			if (!u) return true;
			if (isConnectorGuestUser(u)) return false;
			if (isStaffPortalUser(u)) return true;
			return false;
		}
		return isConnectorOperatorText(msg.text || '');
	}

	function parseBbCode(raw) {
		let t = BX.util.htmlspecialchars(raw || '');
		t = t.replace(/\[br\]/gi, '<br>').replace(/\n/g, '<br>');
		t = t.replace(/\[b\]([\s\S]*?)\[\/b\]/gi, '<b>$1</b>');
		t = t.replace(/\[i\]([\s\S]*?)\[\/i\]/gi, '<i>$1</i>');
		t = t.replace(/\[u\]([\s\S]*?)\[\/u\]/gi, '<u>$1</u>');
		t = t.replace(/\[s\]([\s\S]*?)\[\/s\]/gi, '<s>$1</s>');
		t = t.replace(/\[url=([^\]]+)\]([\s\S]*?)\[\/url\]/gi, (_, url, label) =>
			'<a href="' + url.replace(/"/g, '&quot;') + '" target="_blank" rel="noopener noreferrer">' + label + '</a>');
		t = t.replace(/\[url\]([\s\S]*?)\[\/url\]/gi, (_, url) =>
			'<a href="' + url.replace(/"/g, '&quot;') + '" target="_blank" rel="noopener noreferrer">' + url + '</a>');
		t = t.replace(/\[USER=\d+(?:\s+[^\]]*)?\]([\s\S]*?)\[\/USER\]/gi, '<b>$1</b>');
		t = t.replace(/\[\/?(?:COLOR|SIZE|FONT|CODE|QUOTE|IMG)[^\]]*\]/gi, '');
		return t;
	}

	function previewText(chat) {
		const msg = chat.message || {};
		if (msg.file) return '📎 Файл';
		if (msg.sticker) return 'Стикер';
		let text = msg.text || '';
		text = stripConnectorPrefix(text).replace(/\[[^\]]+\]/g, '').trim();
		if (!text && msg.attach) return 'Вложение';
		return text || '';
	}

	function normalizePhoneDigits(value) {
		return String(value || '').replace(/\D/g, '');
	}

	function extractPhoneDigitsFromText(text) {
		if (!text) return [];
		const found = new Set();
		const raw = String(text);

		(raw.match(/\+?\d[\d\s\-().]{6,}\d/g) || []).forEach(part => {
			const digits = normalizePhoneDigits(part);
			if (digits.length >= 7 && digits.length <= 15) found.add(digits);
		});

		(raw.match(/\b\d{10,15}\b/g) || []).forEach(part => {
			const digits = normalizePhoneDigits(part);
			if (digits.length >= 7 && digits.length <= 15) found.add(digits);
		});

		return Array.from(found);
	}

	function getChatPhones(chat) {
		const phones = new Set();

		const userPhones = chat.user && chat.user.phones;
		if (userPhones && typeof userPhones === 'object') {
			Object.keys(userPhones).forEach(key => {
				extractPhoneDigitsFromText(userPhones[key]).forEach(p => phones.add(p));
			});
		}

		const entityId = (chat.chat && chat.chat.entity_id) || chat.entity_id || '';
		if (entityId) {
			entityId.split('|').forEach(part => {
				extractPhoneDigitsFromText(part.replace(/@.+$/i, '')).forEach(p => phones.add(p));
			});
		}

		const dialogId = resolveDialogId(chat) || '';
		if (dialogId.indexOf('imol|') === 0) {
			dialogId.split('|').forEach(part => {
				extractPhoneDigitsFromText(part.replace(/@.+$/i, '')).forEach(p => phones.add(p));
			});
		}

		['entity_data_1', 'entity_data_2', 'entity_data_3'].forEach(key => {
			const val = chat.chat && chat.chat[key];
			if (val) extractPhoneDigitsFromText(val).forEach(p => phones.add(p));
		});

		extractPhoneDigitsFromText(chat.title).forEach(p => phones.add(p));
		extractPhoneDigitsFromText(chat.chat && chat.chat.name).forEach(p => phones.add(p));

		return Array.from(phones);
	}

	function extractWaClientPhones(entityId) {
		const out = [];
		if (!entityId) return out;
		String(entityId).split('|').forEach(function (part) {
			part = String(part || '').trim();
			const m = part.match(/^(\d{10,15})@(?:c\.us|s\.whatsapp\.net)$/i);
			if (m) out.push(normalizePhoneDigits(m[1]));
		});
		return out;
	}

	function getClientPhone(chat) {
		if (!chat) return '';
		if (chat._clientPhone) return chat._clientPhone;

		const entityId = (chat.chat && chat.chat.entity_id) || chat.entity_id || '';
		const waPhones = extractWaClientPhones(entityId);
		if (waPhones.length) {
			chat._clientPhone = waPhones[0];
			return chat._clientPhone;
		}

		const waFromHelper = getChatWaPhones(chat);
		if (waFromHelper.length) {
			chat._clientPhone = buildWaPhoneDigits(waFromHelper[0]);
			if (chat._clientPhone.length >= 10) return chat._clientPhone;
		}

		const clientUser = getChatClientUser(chat);
		if (clientUser && clientUser.phones && typeof clientUser.phones === 'object') {
			const vals = Object.values(clientUser.phones);
			for (let i = 0; i < vals.length; i++) {
				const d = normalizePhoneDigits(vals[i]);
				if (d.length >= 10) {
					chat._clientPhone = d;
					return d;
				}
			}
		}

		const parts = String(entityId).split('|');
		if (parts.length >= 4) {
			for (let i = 2; i < parts.length; i++) {
				const d = normalizePhoneDigits(String(parts[i]).replace(/@.+$/i, ''));
				if (d.length >= 10) {
					chat._clientPhone = d;
					return d;
				}
			}
		}

		const all = getChatPhones(chat);
		const linePart = normalizePhoneDigits(String(parts[1] || ''));
		for (let j = 0; j < all.length; j++) {
			if (linePart && all[j].slice(-10) === linePart.slice(-10)) continue;
			if (all[j].length >= 10) {
				chat._clientPhone = all[j];
				return all[j];
			}
		}
		chat._clientPhone = all.length ? all[all.length - 1] : '';
		return chat._clientPhone;
	}

	function formatPhoneDisplay(digits) {
		if (!digits) return '';
		if (digits.length === 11 && digits[0] === '7') {
			return '+7 ' + digits.slice(1, 4) + ' ' + digits.slice(4, 7) + '-' + digits.slice(7, 9) + '-' + digits.slice(9);
		}
		if (digits.length === 11 && digits[0] === '8') {
			return '+7 ' + digits.slice(1, 4) + ' ' + digits.slice(4, 7) + '-' + digits.slice(7, 9) + '-' + digits.slice(9);
		}
		return '+' + digits;
	}

	function getChatSearchDigits(chat) {
		const parts = [
			chat._displayName,
			chat.title,
			chat.chat && chat.chat.name,
			chat.chat && chat.chat.entity_id,
			chat.chat && chat.chat.entity_data_1,
			chat.chat && chat.chat.entity_data_2,
			chat.chat && chat.chat.entity_data_3,
			resolveDialogId(chat),
			previewText(chat)
		];

		const userPhones = chat.user && chat.user.phones;
		if (userPhones && typeof userPhones === 'object') {
			Object.values(userPhones).forEach(v => parts.push(v));
		}

		(chat._phones || getChatPhones(chat)).forEach(p => parts.push(p));
		const clientPhone = getClientPhone(chat);
		if (clientPhone) parts.push(clientPhone);

		return normalizePhoneDigits(parts.filter(Boolean).join(' '));
	}

	function getPrimaryPhone(chat) {
		return getClientPhone(chat);
	}

	function matchesChatSearch(chat, query) {
		const q = (query || '').trim().toLowerCase();
		if (!q) return true;

		const title = (chat.title || (chat.chat && chat.chat.name) || '').toLowerCase();
		const preview = previewText(chat).toLowerCase();
		const displayName = (chat._displayName || getUserPersonName(getChatClientUser(chat)) || '').toLowerCase();
		if (displayName && displayName.indexOf(q) !== -1) return true;
		if (title.indexOf(q) !== -1 || preview.indexOf(q) !== -1) return true;

		const qDigits = normalizePhoneDigits(q);
		if (qDigits.length >= 2) {
			const allDigits = getChatSearchDigits(chat);
			if (allDigits && allDigits.indexOf(qDigits) !== -1) return true;

			const phones = chat._phones || getChatPhones(chat);
			if (phones.some(p => p.indexOf(qDigits) !== -1)) return true;
		}

		return false;
	}

	function buildPhoneLookupValues(digits) {
		const values = new Set();
		if (!digits) return [];
		values.add('+' + digits);
		values.add(digits);
		if (digits.length === 11 && digits[0] === '7') {
			values.add('+7' + digits.slice(1));
			values.add('8' + digits.slice(1));
		}
		if (digits.length === 11 && digits[0] === '8') {
			values.add('+7' + digits.slice(1));
			values.add('7' + digits.slice(1));
		}
		if (digits.length === 10) {
			values.add('+7' + digits);
			values.add('7' + digits);
			values.add('8' + digits);
		}
		return Array.from(values).slice(0, 12);
	}

	function getOlMetaFromCache() {
		for (let i = 0; i < chatsCache.length; i++) {
			const eid = (chatsCache[i].chat && chatsCache[i].chat.entity_id) || chatsCache[i].entity_id || '';
			const parts = eid.split('|');
			if (parts.length >= 2 && parts[0] && parts[1]) {
				return { connector: parts[0], lineId: parts[1] };
			}
		}
		return null;
	}

	async function resolveOlRawId(rawId) {
		const id = parseInt(rawId, 10);
		if (!id) return null;
		if (olIdResolveCache.has(id)) return olIdResolveCache.get(id);
		const pending = (async function () {
			if (window.__WA_NOPROLOG) {
				try {
					const data = await rest('imopenlines.dialog.get', { CHAT_ID: id });
					const d = data.result || data;
					const lineStatus = parseInt(d.lines && d.lines.status, 10);
					return {
						rawId: id,
						chatId: parseInt(d.id, 10) || id,
						userCode: String(d.entity_id || ''),
						title: String(d.name || d.title || ''),
						closed: CLOSED_LINE_STATUSES.indexOf(lineStatus) !== -1
					};
				} catch (e1) {
					try {
						const data = await rest('imopenlines.dialog.get', { SESSION_ID: id });
						const d = data.result || data;
						const lineStatus = parseInt(d.lines && d.lines.status, 10);
						return {
							rawId: id,
							chatId: parseInt(d.id, 10) || id,
							userCode: String(d.entity_id || ''),
							title: String(d.name || d.title || ''),
							closed: CLOSED_LINE_STATUSES.indexOf(lineStatus) !== -1
						};
					} catch (e2) { /* ignore */ }
				}
				return { rawId: id, chatId: id, userCode: '', title: '', closed: false };
			}
			try {
				const r = await fetch('/local/custom_chat/?wa_resolve_ol=' + id, { credentials: 'same-origin' });
				if (!r.ok) return null;
				return await r.json();
			} catch (e) {
				return null;
			}
		})();
		olIdResolveCache.set(id, pending);
		return pending;
	}

	function chatItemFromResolvedMeta(meta, extra) {
		const chatId = parseInt(meta && meta.chatId, 10);
		if (!chatId) return null;
		const title = (meta.title || (extra && extra.title) || ('Чат #' + chatId));
		const userCode = (meta.userCode || '');
		const closed = !!(meta.closed || (extra && extra.closed));
		const item = {
			id: 'chat' + chatId,
			dialog_id: 'chat' + chatId,
			chat_id: chatId,
			title: title,
			type: 'lines',
			entity_type: 'LINES',
			entity_id: userCode,
			counter: 0,
			_fromCrm: true,
			_waClosed: closed,
			lines: { status: closed ? 50 : 0 },
			chat: {
				id: chatId,
				type: 'lines',
				entity_type: 'LINES',
				entity_id: userCode,
				name: title
			},
			message: {
				text: (extra && extra.preview) || '',
				date: (extra && extra.date) || ''
			}
		};
		item._phones = getChatPhones(item);
		return item;
	}

	async function chatItemFromDialogChatId(rawId) {
		const id = parseInt(rawId, 10);
		if (!id) return null;

		let meta = null;
		try {
			meta = await resolveOlRawId(id);
		} catch (e) {
			meta = null;
		}
		const chatId = parseInt(meta && meta.chatId, 10) || 0;
		const userCode = String((meta && meta.userCode) || '');
		const resolvedId = chatId || id;

		// На коробке CHAT_ID/SESSION_ID → 400; USER_CODE обычно ок
		if (userCode) {
			try {
				const cid = await resolveChatIdByUserCode(userCode);
				if (cid) {
					const item = chatItemFromResolvedMeta(Object.assign({}, meta || {}, { chatId: cid }), { closed: !!(meta && meta.closed) });
					if (item) {
						item._phones = getChatPhones(item);
						return item;
					}
				}
			} catch (e) { /* synthetic below */ }
		}

		// Не дёргаем imopenlines.dialog.get(CHAT_ID) / im.dialog.get — 400/403 в консоли
		if (meta && (chatId || userCode || meta.title)) {
			return chatItemFromResolvedMeta(meta, { closed: !!(meta && meta.closed) });
		}

		try {
			const data = await rest('im.dialog.get', { DIALOG_ID: 'chat' + resolvedId });
			const dialog = (data && data.result) || data || {};
			const chat = dialog.chat || dialog;
			const fake = {
				id: parseInt(chat.id || resolvedId, 10),
				dialog_id: dialog.dialog_id || ('chat' + resolvedId),
				name: chat.name || chat.title || '',
				type: chat.type || 'lines',
				entity_type: chat.entity_type || 'LINES',
				entity_id: chat.entity_id || '',
				entity_data_1: chat.entity_data_1,
				entity_data_2: chat.entity_data_2,
				entity_data_3: chat.entity_data_3,
				lines: chat.lines
			};
			const item = dialogToChatItem(fake, null);
			if (item) {
				item._phones = getChatPhones(item);
				return item;
			}
		} catch (e) { /* ignore */ }

		return null;
	}

	async function collectChatIdsForCrmEntity(entityType, entityId, chatIds) {
		const typeLower = entityType.toLowerCase();
		const cacheKey = typeLower + ':' + entityId;
		if (failedCrmEntityChatLookups.has(cacheKey)) return;

		const before = chatIds.size;
		try {
			const chats = await rest('imopenlines.crm.chat.get', {
				CRM_ENTITY_TYPE: typeLower,
				CRM_ENTITY: entityId,
				ACTIVE_ONLY: 'N'
			});
			const list = chats.result || chats || [];
			if (Array.isArray(list)) {
				if (!list.length) {
					failedCrmEntityChatLookups.add(cacheKey);
					return;
				}
				list.forEach(function (c) {
					const cid = parseInt(c.CHAT_ID || c.chatId, 10);
					if (cid) chatIds.add(cid);
				});
				return;
			}
		} catch (e) {}

		if (chatIds.size !== before) return;

		try {
			const last = await rest('imopenlines.crm.chat.getLastId', {
				CRM_ENTITY_TYPE: typeLower,
				CRM_ENTITY: entityId
			});
			const cid = parseInt(last.result !== undefined ? last.result : last, 10);
			if (cid) chatIds.add(cid);
			else failedCrmEntityChatLookups.add(cacheKey);
		} catch (e) {
			failedCrmEntityChatLookups.add(cacheKey);
		}
	}

	function buildWaPhoneDigits(qDigits) {
		let num = normalizePhoneDigits(qDigits);
		if (num.length === 11 && num[0] === '8') num = '7' + num.slice(1);
		if (num.length === 10) num = '7' + num;
		return num;
	}

	function buildUserCodeFromOlMeta(meta, qDigits) {
		const num = buildWaPhoneDigits(qDigits);
		if (num.length < 10) return '';

		const prefix = meta.connector + '|' + meta.lineId + '|';
		let part2 = num + '@c.us';
		let part3 = part2;

		for (let i = 0; i < chatsCache.length; i++) {
			const eid = (chatsCache[i].chat && chatsCache[i].chat.entity_id) || chatsCache[i].entity_id || '';
			if (eid.indexOf(prefix) !== 0) continue;
			const parts = eid.split('|');
			if (parts.length < 4) continue;
			if (/@c\.us/i.test(parts[2]) || /@s\.whatsapp\.net/i.test(parts[2])) {
				part2 = num + '@c.us';
				part3 = /@/.test(parts[3]) ? part2 : num;
			} else {
				part2 = num;
				part3 = num;
			}
			break;
		}

		return prefix + part2 + '|' + part3;
	}

	async function findChatByPhoneUserCode(qDigits, chatIds) {
		if (failedPhoneLookups.has(qDigits)) return;
		const meta = getOlMetaFromCache();
		if (!meta) return;

		const userCode = buildUserCodeFromOlMeta(meta, qDigits);
		if (!userCode) {
			failedPhoneLookups.add(qDigits);
			return;
		}

		try {
			const cid = await resolveChatIdByUserCode(userCode);
			if (cid) chatIds.add(cid);
		} catch (e) {
			failedPhoneLookups.add(qDigits);
		}
	}

	async function ensureSearchRecentCache() {
		if (searchRecentLoaded) return;
		try {
			for (let offset = 0; offset < 250; offset += 50) {
				const data = await rest('im.recent.list', {
					LIMIT: 50,
					OFFSET: offset,
					ONLY_OPENLINES: 'Y'
				});
				const items = data.items || (data.result && data.result.items) || data.result || [];
				if (!Array.isArray(items) || !items.length) break;
				chatsCache = mergeChatLists(chatsCache, items).map(function (chat) {
					if (!chat._phones) chat._phones = getChatPhones(chat);
					if (isChatClosed(chat)) markChatClosed(chat);
					return chat;
				});
				if (items.length < 50) break;
			}
		} catch (e) {
			console.warn('search recent cache', e);
		}
		searchRecentLoaded = true;
	}

	async function searchChatsByPhoneRemote(query) {
		const qDigits = normalizePhoneDigits(query);
		if (qDigits.length < 10) return [];

		await ensureSearchRecentCache();

		const chatIds = new Set();
		await findChatsByPhonesAnyLine([qDigits], chatIds, { skipRestrictedCrmMethods: true });

		(chatsCache || []).forEach(function (chat) {
			if (!matchesChatSearch(chat, query)) return;
			const cid = parseInt(chat.chat_id || (chat.chat && chat.chat.id), 10);
			if (cid) chatIds.add(cid);
		});

		const found = [];
		const ids = Array.from(chatIds);
		for (let i = 0; i < ids.length; i++) {
			try {
				const item = await chatItemFromDialogChatId(ids[i]);
				if (!item) continue;
				item._phones = getChatPhones(item);
				if (
					isChatAllowedForPhoneSearch(item) &&
					(matchesChatSearch(item, query) || chatItemMatchesPhones(item, [qDigits]))
				) {
					item._fromSearch = true;
					found.push(item);
				}
			} catch (e) {
				console.warn('dialog.get CHAT_ID=' + ids[i], e);
			}
		}

		return found;
	}

	async function fetchCrmEntitiesByName(method, filter) {
		try {
			const data = await rest(method, {
				filter: filter,
				select: ['ID', 'NAME', 'LAST_NAME', 'TITLE'],
				start: 0
			});
			return Array.isArray(data) ? data : (data && data.result) || [];
		} catch (e) {
			return [];
		}
	}

	async function searchChatsByNameRemote(query) {
		const q = query.trim();
		if (q.length < 2) return [];

		const entityKeys = new Set();
		const entities = [];
		const searches = [
			['crm.contact.list', 'CONTACT', { '%NAME': q }],
			['crm.contact.list', 'CONTACT', { '%LAST_NAME': q }],
			['crm.lead.list', 'LEAD', { '%NAME': q }],
			['crm.lead.list', 'LEAD', { '%LAST_NAME': q }],
			['crm.lead.list', 'LEAD', { '%TITLE': q }]
		];

		for (let i = 0; i < searches.length; i++) {
			const items = await fetchCrmEntitiesByName(searches[i][0], searches[i][2]);
			for (let j = 0; j < items.length && j < 8; j++) {
				const id = parseInt(items[j].ID, 10);
				if (!id) continue;
				const key = searches[i][1] + ':' + id;
				if (entityKeys.has(key)) continue;
				entityKeys.add(key);
				entities.push({ type: searches[i][1], id: id, entity: items[j] });
			}
		}

		const chatIds = new Set();
		for (let i = 0; i < entities.length; i++) {
			await collectChatIdsForCrmEntity(entities[i].type, entities[i].id, chatIds);
		}

		const found = [];
		const ids = Array.from(chatIds);
		for (let i = 0; i < ids.length; i++) {
			try {
				const item = await chatItemFromDialogChatId(ids[i]);
				if (!item) continue;
				await enrichChatDisplayNames([item]);
				if (matchesChatSearch(item, query)) found.push(item);
			} catch (e) {
				console.warn('dialog.get CHAT_ID=' + ids[i], e);
			}
		}

		return found;
	}

	function getLocalSearchMatches() {
		let items = applyListFilter(chatsCache.slice());
		if (searchQuery.trim()) {
			items = items.filter(function (c) { return matchesChatSearch(c, searchQuery); });
		}
		return items;
	}

	async function runRemoteSearchIfNeeded() {
		const q = (searchQuery || '').trim();
		if (q.length < 2) return;

		const qDigits = normalizePhoneDigits(q);
		const isPhoneSearch = qDigits.length >= 10;
		if (!isPhoneSearch && getLocalSearchMatches().length > 0) return;

		const searchKey = isPhoneSearch ? qDigits : q.toLowerCase();
		if (searchRemoteLoading && lastRemoteSearchKey === searchKey) return;

		lastRemoteSearchKey = searchKey;
		searchRemoteLoading = true;
		listEl.classList.add('wa-list-searching');
		let searchSucceeded = false;

		try {
			if (isPhoneSearch) {
				const lines = await loadWaLines();
				waAllowedLineIds = new Set(lines.map(function (line) {
					return parseInt(line.id, 10) || 0;
				}).filter(Boolean));
			}
			const remote = isPhoneSearch
				? await searchChatsByPhoneRemote(q)
				: await searchChatsByNameRemote(q);
			if (remote.length) {
				remote.forEach(function (item) { item._fromSearch = true; });
				chatsCache = mergeChatLists(chatsCache, remote).map(function (chat) {
					if (!chat._phones) chat._phones = getChatPhones(chat);
					delete chat._clientPhone;
					if (isChatClosed(chat)) markChatClosed(chat);
					return chat;
				});
				await enrichChatDisplayNames(remote);
			}
			searchSucceeded = true;
		} catch (e) {
			console.error(e);
		} finally {
			searchRemoteLoading = false;
			if (
				searchSucceeded &&
				isPhoneSearch &&
				normalizePhoneDigits(searchQuery) === qDigits
			) {
				completedPhoneSearchKey = qDigits;
			}
			listEl.classList.remove('wa-list-searching');
			renderChatList();
		}
	}

	function isAudioMediaFile(f) {
		if (!f) return false;
		const type = normalizeMediaKind(f.type, f.extension, f.name);
		const ext = (f.extension || '').toLowerCase().replace(/^\./, '');
		const name = (f.name || '').toLowerCase();

		if (f.isVoiceNote || f._kind === 'audio') return true;
		if (type === 'audio') return true;
		if (/^(mp3|ogg|oga|wav|m4a|opus|aac)$/i.test(ext)) return true;
		if (/voice|audio_message|голос|\bptt\b/i.test(name)) return true;
		if (ext === 'webm' && type !== 'video') return true;

		return false;
	}

	function isVideoMediaFile(f) {
		if (!f || isAudioMediaFile(f)) return false;
		if (f._kind === 'video') return true;
		const type = normalizeMediaKind(f.type, f.extension, f.name);
		const ext = (f.extension || '').toLowerCase().replace(/^\./, '');
		return type === 'video' || /^(mp4|mov|avi|mkv)$/i.test(ext);
	}

	function waMediaProxyUrl(fileId) {
		const id = parseFileId(fileId);
		if (!id) return '';
		/* Как раньше: тот же URL страницы (cookies/сессия). mobile.php — только для noprolog + wa_aid. */
		let url;
		if (window.__WA_NOPROLOG) {
			const entry = 'mobile.php';
			const base = window.location.pathname.replace(/[^/]+$/, '') + entry;
			url = new URL(base, window.location.origin);
		} else {
			url = new URL(window.location.href);
			url.search = '';
			url.hash = '';
		}
		url.searchParams.set('wa_media', String(id));
		const chatId = currentChatId || String(currentDialogId || '').replace(/^chat/i, '');
		if (chatId && /^\d+$/.test(String(chatId))) {
			url.searchParams.set('chat', String(chatId));
		}
		if (window.__WA_AID && window.__WA_NOPROLOG) {
			url.searchParams.set('wa_aid', window.__WA_AID);
		}
		return url.toString();
	}

	function waMediaKindUrl(fileId) {
		const url = waMediaProxyUrl(fileId);
		if (!url) return '';
		const u = new URL(url);
		u.searchParams.set('kind', '1');
		return u.toString();
	}

	function formatAudioDuration(sec) {
		sec = Math.round(Number(sec) || 0);
		if (sec < 0) sec = 0;
		const m = Math.floor(sec / 60);
		const s = sec % 60;
		return m + ':' + String(s).padStart(2, '0');
	}

	function setAudioDurationLabel(audio, sec) {
		if (!audio || !(sec > 0) || !isFinite(sec)) return;
		const wrap = audio.closest('.wa-media');
		const label = wrap && wrap.querySelector('.wa-audio-dur');
		if (label) {
			label.textContent = formatAudioDuration(sec);
			label.hidden = false;
		}
	}

	function bindAudioDuration(audio) {
		if (!audio || audio.dataset.durBound === '1') return;
		audio.dataset.durBound = '1';
		const id = parseFileId(audio.dataset.fileId);

		/* если прямой Bitrix URL не открылся — fallback на наш proxy (+mp3 на mobile) */
		audio.addEventListener('error', function onAudioErr() {
			if (audio.dataset.proxyTried === '1') return;
			const proxy = audio.dataset.proxy || waMediaProxyUrl(id);
			if (!proxy || audio.src === proxy) return;
			audio.dataset.proxyTried = '1';
			let next = proxy;
			if (waCcIsMobileClient()) {
				try {
					const u = new URL(proxy);
					u.searchParams.set('fmt', 'mp3');
					next = u.toString();
				} catch (e) { /* keep proxy */ }
			}
			audio.src = next;
			try { audio.load(); } catch (e) { /* ignore */ }
		});

		function applyKnown() {
			const f = filesMap[id] || {};
			if (f.duration > 0) {
				setAudioDurationLabel(audio, f.duration);
				return true;
			}
			return false;
		}

		function fromElement() {
			const d = audio.duration;
			if (isFinite(d) && d > 0) {
				const f = filesMap[id] || { id: id };
				f.duration = d;
				filesMap[id] = f;
				setAudioDurationLabel(audio, d);
				return true;
			}
			return false;
		}

		if (applyKnown() || fromElement()) return;

		audio.addEventListener('loadedmetadata', function () {
			if (fromElement()) return;
			if (audio.duration === Infinity || isNaN(audio.duration)) {
				try { audio.currentTime = 1e101; } catch (e) {}
			}
		});
		audio.addEventListener('durationchange', fromElement);
		audio.addEventListener('timeupdate', function onTu() {
			if (!fromElement()) return;
			audio.removeEventListener('timeupdate', onTu);
			try { if (audio.currentTime > 1) audio.currentTime = 0; } catch (e) {}
		});

		if (id && !(filesMap[id] && filesMap[id].duration > 0)) {
			fetch(waMediaKindUrl(id), { credentials: 'same-origin' }).then(function (resp) {
				return resp.json();
			}).then(function (data) {
				const dur = data && Number(data.duration);
				if (dur > 0) {
					const f = filesMap[id] || { id: id };
					f.duration = dur;
					filesMap[id] = f;
					applyKnown();
				}
			}).catch(function () {});
		}
	}

	function mediaElementHtml(fileId, tag, extraClass) {
		const f = filesMap[fileId] || {};
		/* Как вчера: сначала прямые Bitrix urlShow/urlDownload, proxy только fallback */
		const direct = f.urlShow || f.urlDownload || f.urlPreview || waMediaProxyUrl(fileId);
		if (tag === 'audio') {
			const known = filesMap[fileId] && filesMap[fileId].duration > 0
				? formatAudioDuration(filesMap[fileId].duration)
				: '';
			return '<div class="wa-media wa-media-audio">' +
				'<audio controls preload="metadata" playsinline webkit-playsinline class="' + (extraClass || '') + '" ' +
				'src="' + BX.util.htmlspecialchars(direct) + '" ' +
				'data-file-id="' + fileId + '" ' +
				'data-proxy="' + BX.util.htmlspecialchars(waMediaProxyUrl(fileId)) + '"></audio>' +
				'<span class="wa-audio-dur"' + (known ? '' : ' hidden') + '>' + known + '</span>' +
				'</div>';
		}
		return '<div class="wa-media">' +
			'<' + tag + ' controls preload="metadata" playsinline class="' + (extraClass || '') + '" ' +
			'src="' + BX.util.htmlspecialchars(direct) + '" ' +
			'data-file-id="' + fileId + '" ' +
			'data-proxy="' + BX.util.htmlspecialchars(waMediaProxyUrl(fileId)) + '"></' + tag + '>' +
			'</div>';
	}

	function renderImageHtml(id, f) {
		const name = BX.util.htmlspecialchars((f && f.name) || ('фото #' + id));
		const proxy = waMediaProxyUrl(id);
		const preview = (f && (f.urlPreview || f.urlShow || f.urlDownload)) || proxy;
		const full = (f && (f.urlShow || f.urlDownload || f.urlPreview)) || proxy;
		return '<div class="wa-media"><img class="wa-lightbox-trigger" src="' + BX.util.htmlspecialchars(preview) +
			'" data-full="' + BX.util.htmlspecialchars(full) +
			'" data-download="' + BX.util.htmlspecialchars(full) +
			'" data-proxy="' + BX.util.htmlspecialchars(proxy) +
			'" data-file-id="' + id + '" alt="' + name + '" loading="lazy"></div>';
	}

	function renderUnknownMediaHtml(id) {
		return '<div class="wa-media wa-media-unknown" data-file-id="' + id + '">' +
			'<div class="wa-media-loading">медиа...</div></div>';
	}

	function renderFileLinkHtml(id, f) {
		const name = BX.util.htmlspecialchars((f && f.name) || ('файл #' + id));
		const href = waMediaProxyUrl(id);
		return '<div class="wa-media"><a class="wa-file-link" href="' + BX.util.htmlspecialchars(href) +
			'" target="_blank" rel="noopener" data-file-id="' + id + '">📎 ' + name + '</a></div>';
	}

	function isDocumentFile(f) {
		const ext = String((f && f.extension) || '').toLowerCase().replace(/^\./, '');
		const name = String((f && f.name) || '').toLowerCase();
		return /^(pdf|docx?|xlsx?|pptx?|zip|rar|7z|txt|csv|rtf)$/i.test(ext) ||
			/\.(pdf|docx?|xlsx?|pptx?|zip|rar|7z|txt|csv|rtf)(\?|$)/i.test(name);
	}

	function applyProbedKind(id, kind, extra) {
		const prev = filesMap[id] || { id: id };
		const name = (extra && extra.name) || prev.name;
		const ext = (extra && extra.ext) || prev.extension;
		filesMap[id] = Object.assign(prev, {
			id: id,
			type: kind || prev.type,
			_kind: kind,
			name: name,
			extension: ext,
			duration: (extra && extra.duration > 0) ? extra.duration : prev.duration
		});
		if (kind === 'file' && isAudioMediaFile(filesMap[id])) {
			filesMap[id].type = 'audio';
			filesMap[id]._kind = 'audio';
			return 'audio';
		}
		return kind;
	}

	async function probeMediaKind(fileId) {
		const id = parseFileId(fileId);
		if (!id) return 'file';
		const f = filesMap[id] || {};
		if (f._kind === 'audio' || f._kind === 'video' || f._kind === 'image') return f._kind;
		if (isAudioMediaFile(f)) { applyProbedKind(id, 'audio'); return 'audio'; }
		if (isVideoMediaFile(f)) { applyProbedKind(id, 'video'); return 'video'; }
		if (isImageFileRecord(f)) { applyProbedKind(id, 'image'); return 'image'; }
		if (isDocumentFile(f)) { applyProbedKind(id, 'file'); return 'file'; }

		try {
			const resp = await fetch(waMediaKindUrl(id), { credentials: 'same-origin' });
			const ct = (resp.headers.get('content-type') || '').toLowerCase();
			if (ct.indexOf('json') === -1) {
				applyProbedKind(id, 'audio');
				return 'audio';
			}
			const data = await resp.json();
			let kind = (data && data.kind) || 'file';
			kind = applyProbedKind(id, kind, data) || kind;
			if (kind === 'file' && !isDocumentFile(filesMap[id])) {
				applyProbedKind(id, 'audio', data);
				return 'audio';
			}
			return kind;
		} catch (e) {
			applyProbedKind(id, 'audio');
			return 'audio';
		}
	}

	function bindImageLightbox(img) {
		if (!img || img.dataset.lbBound === '1') return;
		img.dataset.lbBound = '1';
		img.addEventListener('click', function (e) {
			e.preventDefault();
			openLightbox(img.dataset.full || img.src, img.dataset.download || img.dataset.full || img.src);
		});
	}

	function fillMediaContainer(container, id, kind) {
		if (!container) return;
		const f = filesMap[id] || { id: id };
		let html = '';
		if (kind === 'audio') html = mediaElementHtml(id, 'audio', 'wa-voice');
		else if (kind === 'video') html = mediaElementHtml(id, 'video');
		else if (kind === 'image') html = renderImageHtml(id, f);
		else html = renderFileLinkHtml(id, f);
		const tmp = document.createElement('div');
		tmp.innerHTML = html;
		const next = tmp.firstElementChild;
		if (next) {
			container.replaceWith(next);
			next.querySelectorAll('img.wa-lightbox-trigger').forEach(bindImageLightbox);
			next.querySelectorAll('audio').forEach(bindAudioDuration);
			if (kind === 'audio') {
				const msgEl = next.closest('.wa-msg');
				if (msgEl) msgEl.classList.add('has-audio');
			}
		} else {
			container.innerHTML = html;
		}
		keepOpenScrollAfterMedia();
	}

	function renderFilesHtml(msg) {
		const ids = getFileIds(msg);
		if (!ids.length) return '';
		const voiceMsg = !!(msg && (msg.isVoiceNote || msg.isvoicenote));
		return ids.map(function (id) {
			const f = filesMap[id] || { id: id };
			if (voiceMsg) f.isVoiceNote = true;
			if (isAudioMediaFile(f)) return mediaElementHtml(id, 'audio', 'wa-voice');
			if (isVideoMediaFile(f)) return mediaElementHtml(id, 'video');
			if (isImageFileRecord(f)) return renderImageHtml(id, f);
			return renderUnknownMediaHtml(id);
		}).join('');
	}

	async function resolveMediaUrl(fileId, directUrl) {
		if (directUrl) return directUrl;
		const id = parseFileId(fileId);
		const f = filesMap[id] || {};
		return f.urlShow || f.urlDownload || f.urlPreview || waMediaProxyUrl(id) || '';
	}

	async function bindLazyMedia(root) {
		const scope = root || messagesEl;
		const unknowns = scope.querySelectorAll('.wa-media-unknown[data-file-id]');
		unknowns.forEach(function (el) {
			if (el.dataset.probing === '1') return;
			el.dataset.probing = '1';
			const id = parseFileId(el.dataset.fileId);
			probeMediaKind(id).then(function (kind) {
				fillMediaContainer(el, id, kind);
			}).catch(function () {
				fillMediaContainer(el, id, 'audio');
			});
		});
		scope.querySelectorAll('img.wa-lightbox-trigger[data-proxy]').forEach(function (img) {
			if (img.dataset.boundErr === '1') return;
			img.dataset.boundErr = '1';
			img.addEventListener('error', function () {
				const proxy = img.dataset.proxy || '';
				if (proxy && img.dataset.proxyTried !== '1' && img.src !== proxy) {
					img.dataset.proxyTried = '1';
					img.src = proxy;
					return;
				}
				if (img.dataset.kindTried === '1') return;
				img.dataset.kindTried = '1';
				const id = parseFileId(img.dataset.fileId);
				const wrap = img.closest('.wa-media') || img.parentNode;
				probeMediaKind(id).then(function (kind) {
					if (kind === 'image') return;
					fillMediaContainer(wrap, id, kind);
				});
			});
		});
		scope.querySelectorAll('audio[data-file-id]').forEach(bindAudioDuration);
	}

	function ticksSvgHtml(double) {
		if (double) {
			return '<svg viewBox="0 0 16 11" aria-hidden="true"><path fill="currentColor" d="M11.07 1.05 4.6 7.52 1.93 4.85.8 5.98l3.8 3.8 7.6-7.6z"/><path fill="currentColor" d="M14.53 1.05 8.06 7.52 7.2 6.66 6.07 7.79l1.99 1.99 7.6-7.6z"/></svg>';
		}
		return '<svg viewBox="0 0 16 11" aria-hidden="true"><path fill="currentColor" d="M12.2 1.05 5.73 7.52 3.06 4.85 1.93 5.98l3.8 3.8 7.6-7.6z"/></svg>';
	}

	function messageUnixTs(msgOrDiv) {
		if (!msgOrDiv) return 0;
		if (msgOrDiv.nodeType === 1) {
			const raw = msgOrDiv.getAttribute('data-ts');
			const n = parseInt(raw, 10);
			if (n > 0) return n;
			return 0;
		}
		const d = parseMessageDate(msgOrDiv);
		if (!d) return 0;
		return Math.floor(d.getTime() / 1000);
	}

	function getLastOutgoingMsgId() {
		if (!messagesEl) return 0;
		const nodes = messagesEl.querySelectorAll('.wa-msg.out');
		if (!nodes.length) return 0;
		return parseInt(nodes[nodes.length - 1].dataset.id, 10) || 0;
	}

	function getOutgoingReadStatus(msg) {
		if (!msg || isSystemMessage(msg) || !isOutgoingMessage(msg)) return '';
		const msgTs = messageUnixTs(msg);
		const id = parseInt(msg.id, 10) || 0;
		const lastOutId = getLastOutgoingMsgId();
		const isLatest = lastOutId > 0 && id === lastOutId;

		/* WhatsApp: всё до readTs — синие; после — только latest отражает свежий status */
		if (waChatReadTs > 0 && msgTs > 0 && msgTs <= waChatReadTs) {
			return 'read';
		}
		if (isLatest) {
			if (waChatTickStatus === 'read') return 'read';
			if (waChatTickStatus === 'delivered' || waChatTickStatus === 'sent') return 'sent';
			if (waChatTickStatus) return 'sent';
			return id ? 'sent' : 'pending';
		}
		/* старые после readTs / без read: серые двойные (доставлено), не синие sticky */
		if (waChatTickStatus || waChatReadTs) return 'sent';
		if (id) return 'sent';
		return 'pending';
	}

	function getCurrentChatTickKeys() {
		const chat = currentChatData;
		if (!chat) return [];
		const keys = [];
		const groupKey = getWhatsAppGroupKey(chat);
		if (groupKey) keys.push(groupKey);
		const hay = getOlConnectorHaystack(chat);
		const found = String(hay || '').match(/(\d{10,20}@c\.us|\d{5,20}(?:-\d{5,20})?@g\.us)/gi) || [];
		found.forEach(function (v) { keys.push(v); });
		const phone = getPrimaryPhone(chat);
		if (phone) keys.push(phone);
		(chat._phones || getChatPhones(chat) || []).forEach(function (p) {
			if (p) keys.push(p);
		});
		return keys;
	}

	function getCurrentChatLineId() {
		const chat = currentChatData;
		if (!chat) return '';
		const eid = String((chat.chat && chat.chat.entity_id) || chat.entity_id || '');
		const parts = eid.split('|').filter(Boolean);
		if (parts.length >= 2 && /^\d+$/.test(parts[1])) return parts[1];
		return '';
	}

	function messageTicksHtml(msg) {
		const status = getOutgoingReadStatus(msg);
		if (!status) return '';
		const read = status === 'read';
		const label = read ? 'прочитано' : (status === 'pending' ? 'отправляется' : 'доставлено');
		return '<span class="wa-ticks' + (read ? ' read' : '') + '" title="' + label + '" aria-label="' + label + '">' +
			ticksSvgHtml(status !== 'pending') + '</span>';
	}

	function applyReadedList(list) {
		if (!list) return;
		if (!Array.isArray(list) && typeof list === 'object') {
			list = Object.keys(list).map(function (k) {
				const v = list[k] && typeof list[k] === 'object' ? list[k] : {};
				return Object.assign({ user_id: k }, v);
			});
		}
		if (!Array.isArray(list)) return;
		let maxId = opponentReadMessageId || 0;
		list.forEach(function (r) {
			if (!r) return;
			const uid = parseInt(r.user_id || r.userId, 10);
			if (uid && uid === CURRENT_USER_ID) return;
			const u = uid ? usersMap[uid] : null;
			if (u && isStaffPortalUser(u)) return;
			const mid = parseInt(r.message_id || r.messageId || r.last_id || r.lastId, 10) || 0;
			if (mid > maxId) maxId = mid;
		});
		if (maxId > (opponentReadMessageId || 0)) opponentReadMessageId = maxId;
	}

	function updateOutgoingTicks() {
		if (!messagesEl) return;
		const lastOutId = getLastOutgoingMsgId();
		messagesEl.querySelectorAll('.wa-msg.out').forEach(function (div) {
			const id = parseInt(div.dataset.id, 10) || 0;
			const msgTs = parseInt(div.getAttribute('data-ts'), 10) || 0;
			let el = div.querySelector('.wa-ticks');
			const time = div.querySelector('.wa-msg-time');
			if (!el && time) {
				el = document.createElement('span');
				el.className = 'wa-ticks';
				el.innerHTML = ticksSvgHtml(true);
				time.appendChild(el);
			}
			if (!el) return;

			const isLatest = lastOutId > 0 && id === lastOutId;
			let isRead = false;
			if (waChatReadTs > 0 && msgTs > 0 && msgTs <= waChatReadTs) {
				isRead = true;
			} else if (isLatest && waChatTickStatus === 'read') {
				isRead = true;
			}

			el.classList.toggle('read', !!isRead);
			el.innerHTML = ticksSvgHtml(true);
			el.title = isRead ? 'прочитано' : 'доставлено';
			el.setAttribute('aria-label', el.title);
		});
	}

	function waTicksUrl() {
		if (window.__WA_NOPROLOG) {
			const entry = 'mobile.php';
			return new URL(window.location.pathname.replace(/[^/]+$/, '') + entry, window.location.origin);
		}
		return new URL('/local/custom_chat/ajax_ticks.php', window.location.origin);
	}

	async function refreshReadReceipts(opts) {
		opts = opts || {};
		const keys = getCurrentChatTickKeys();
		if (keys.length) {
			try {
				const url = waTicksUrl();
				url.searchParams.set('wa_ticks', '1');
				url.searchParams.set('keys', keys.join(','));
				const lineId = getCurrentChatLineId();
				if (lineId) url.searchParams.set('line', lineId);
				if (opts.force || opts.fresh) url.searchParams.set('force', '1');
				if (window.__WA_AID && window.__WA_NOPROLOG) {
					url.searchParams.set('wa_aid', window.__WA_AID);
				}
				const resp = await fetch(url.toString(), { credentials: 'same-origin' });
				const data = await resp.json();
				const st = String((data && data.status) || '').toLowerCase();
				if (st === 'read' || st === 'delivered' || st === 'sent') {
					waChatTickStatus = st;
				}
				waChatTickTs = parseInt((data && data.ts) || 0, 10) || 0;
				waChatReadTs = parseInt((data && data.readTs) || 0, 10) || 0;
				if (waChatReadTs <= 0 && st === 'read' && waChatTickTs > 0) {
					waChatReadTs = waChatTickTs;
				}
			} catch (e) {}
		}
		updateOutgoingTicks();
	}

	function markLocalOutgoingPending() {
		/* новое исходящее ещё не прочитано — не держим sticky read на последнем bubble */
		waChatTickStatus = 'sent';
		waChatTickTs = Math.floor(Date.now() / 1000);
		updateOutgoingTicks();
		startTicksBurst();
	}

	let ticksBurstTimer = null;
	let ticksBurstUntil = 0;
	function startTicksBurst() {
		ticksBurstUntil = Date.now() + 12000;
		if (ticksBurstTimer) return;
		ticksBurstTimer = setInterval(function () {
			if (!currentDialogId || Date.now() > ticksBurstUntil) {
				clearInterval(ticksBurstTimer);
				ticksBurstTimer = null;
				return;
			}
			refreshReadReceipts({ force: true }).catch(function () {});
		}, 4000);
		refreshReadReceipts({ force: true }).catch(function () {});
	}

	function handlePullRead(params) {
		params = params || {};
		if (currentDialogId && !pullMatchesCurrentChat(params)) return;
		const uid = parseInt(params.userId || params.user_id || 0, 10);
		if (uid && uid === CURRENT_USER_ID) return;
		const u = uid ? usersMap[uid] : null;
		if (u && isStaffPortalUser(u)) return;
		const lastId = parseInt(
			params.lastId || params.last_id || params.messageId || params.message_id || 0,
			10
		);
		if (lastId > (opponentReadMessageId || 0)) {
			opponentReadMessageId = lastId;
			updateOutgoingTicks();
		}
	}

	function renderMessage(msg) {
		const div = document.createElement('div');
		const system = isSystemMessage(msg);
		const out = !system && isOutgoingMessage(msg);
		div.className = 'wa-msg ' + (system ? 'system' : (out ? 'out' : 'in'));
		div.dataset.id = msg.id;
		const msgDate = parseMessageDate(msg);
		if (msgDate) {
			div.setAttribute('data-ts', String(Math.floor(msgDate.getTime() / 1000)));
		}

		let body = '';
		const replyId = getReplyId(msg);
		if (replyId) body += renderReplyQuoteHtml(replyId);
		let fromName = '';
		if (!system) {
			fromName = out ? getOutgoingSenderName(msg) : getIncomingSenderName(msg);
		}
		let rawText = stripConnectorPrefix(messageRawText(msg));
		if (!replyId) {
			const extracted = extractIncomingQuote(rawText);
			if (extracted) {
				body += '<div class="wa-msg-quote"><div class="wa-msg-quote-body"><span class="wa-msg-quote-author">'
					+ BX.util.htmlspecialchars(fromName || 'Цитата') + '</span><span class="wa-msg-quote-text">'
					+ BX.util.htmlspecialchars(extracted.quote) + '</span></div></div>';
				rawText = extracted.text;
			}
		}
		if (fromName) {
			const color = out ? '#1a7f4c' : senderColor(fromName);
			body += '<span class="wa-msg-from" style="color:' + color + '">'
				+ BX.util.htmlspecialchars(fromName) + '</span>';
		}
		body += renderFilesHtml(msg);
		if (rawText.trim()) body += '<span class="wa-msg-text">' + parseBbCode(rawText) + '</span>';
		if (!body) body = '<span class="wa-msg-text" style="color:#667781;font-style:italic">[медиа]</span>';
		if (msgDate) {
			body += '<span class="wa-msg-time">' +
				(msg._edited ? '<span class="wa-msg-edited">изменено</span>' : '') +
				formatTime(msgDate) + messageTicksHtml(msg) + '</span>';
		}
		div.innerHTML = body;

		div.querySelectorAll('img.wa-lightbox-trigger').forEach(img => {
			img.addEventListener('click', e => {
				e.preventDefault();
				openLightbox(img.dataset.full || img.src, img.dataset.download || img.dataset.full || img.src);
			});
		});
		div.querySelectorAll('img.wa-msg-quote-thumb').forEach(img => {
			img.addEventListener('click', e => {
				e.preventDefault();
				e.stopPropagation();
				openLightbox(img.dataset.full || img.src, img.dataset.full || img.src);
			});
		});
		div.querySelectorAll('a[data-file-id]').forEach(a => {
			a.addEventListener('click', async e => {
				e.preventDefault();
				const fid = parseInt(a.dataset.fileId, 10);
				const f = filesMap[fid] || {};
				const direct = f.urlDownload || f.urlShow || f.urlPreview || '';
				const url = await resolveMediaUrl(fid, direct);
				if (url) window.open(bulkDownloadUrl(fid), '_blank');
				else alert('Не удалось скачать файл');
			});
		});
		bindMessageReplyButton(div, msg);
		div.classList.toggle('selected', isMessageSelected(msg.id));
		div.addEventListener('click', function (e) {
			if (!selectedMessageIds.size) return;
			if (e.target.closest('a, button, audio, video, img, input, textarea')) return;
			toggleMessageSelection(msg);
		});
		if (div.querySelector('.wa-media-audio') || div.querySelector('audio')) {
			div.classList.add('has-audio');
		}
		return div;
	}

	function trackMessageBounds(messages) {
		(messages || []).forEach(function (msg) {
			const id = parseInt(msg.id, 10) || 0;
			if (!id) return;
			lastMessageId = Math.max(lastMessageId, id);
			if (!firstMessageId || id < firstMessageId) firstMessageId = id;
		});
	}

	function cacheCurrentMessages(messages) {
		(messages || []).forEach(function (msg) {
			const id = parseInt(msg && msg.id, 10) || 0;
			if (id) currentMessageCache.set(id, msg);
		});
	}

	function shouldStickToBottom() {
		return messagesEl.scrollHeight - messagesEl.scrollTop - messagesEl.clientHeight < 120;
	}

	function scrollMessagesToBottom() {
		if (!messagesEl) return;
		messagesEl.scrollTop = messagesEl.scrollHeight;
	}

	function findFirstUnreadMessage(messages, unreadCount) {
		const n = parseInt(unreadCount, 10) || 0;
		if (n <= 0 || !messages || !messages.length) return null;
		const sorted = messages.slice().sort(function (a, b) {
			return (parseInt(a.id, 10) || 0) - (parseInt(b.id, 10) || 0);
		});
		const incoming = sorted.filter(function (msg) {
			return !isSystemMessage(msg) && !isOutgoingMessage(msg);
		});
		if (incoming.length) {
			const idx = Math.max(0, incoming.length - n);
			return incoming[idx];
		}
		const idx = Math.max(0, sorted.length - n);
		return sorted[idx] || null;
	}

	function insertUnreadSeparator(anchorId) {
		if (!anchorId || !messagesEl) return;
		if (messagesEl.querySelector('.wa-msg-unread-sep')) return;
		const el = messagesEl.querySelector('.wa-msg[data-id="' + anchorId + '"]');
		if (!el) return;
		const sep = document.createElement('div');
		sep.className = 'wa-msg-unread-sep';
		sep.textContent = 'Непрочитанные';
		el.parentNode.insertBefore(sep, el);
	}

	function applyOpenScroll() {
		if (!messagesEl) return;
		if (openScrollMode === 'unread' && openUnreadAnchorId) {
			const target = messagesEl.querySelector('.wa-msg-unread-sep')
				|| messagesEl.querySelector('.wa-msg[data-id="' + openUnreadAnchorId + '"]');
			if (target) {
				try {
					target.scrollIntoView({ block: 'start', behavior: 'auto' });
				} catch (e) {
					const top = target.offsetTop - 8;
					messagesEl.scrollTop = top > 0 ? top : 0;
				}
				return;
			}
		}
		scrollMessagesToBottom();
	}

	function scheduleOpenScrollKeep() {
		openingScrollLock = true;
		applyOpenScroll();
		requestAnimationFrame(function () {
			applyOpenScroll();
			requestAnimationFrame(applyOpenScroll);
		});
		if (messagesEl) {
			messagesEl.querySelectorAll('img').forEach(function (img) {
				if (img.complete) return;
				img.addEventListener('load', applyOpenScroll);
			});
		}
		[80, 250, 600, 1200].forEach(function (ms) {
			setTimeout(applyOpenScroll, ms);
		});
		setTimeout(function () {
			openingScrollLock = false;
			applyOpenScroll();
		}, 1400);
	}

	function keepOpenScrollAfterMedia() {
		if (openScrollMode === 'bottom') {
			if (openingScrollLock || shouldStickToBottom()) scrollMessagesToBottom();
			return;
		}
		if (openingScrollLock) applyOpenScroll();
	}

	function broadcastUnreadToPortal() {
		try {
			const n = getTabCounts().unread || 0;
			const payload = { source: 'wa-cc', type: 'unread', count: n };
			window.postMessage(payload, '*');
			if (window.parent && window.parent !== window) window.parent.postMessage(payload, '*');
			if (window.top && window.top !== window) window.top.postMessage(payload, '*');
		} catch (e) { /* ignore */ }
	}

	function publishOpenChatToPortal() {
		try {
			window.__waCcOpenDialogId = currentDialogId || '';
			window.__waCcOpenChatId = currentChatId || 0;
			if (window.top) {
				window.top.__waCcOpenDialogId = currentDialogId || '';
				window.top.__waCcOpenChatId = currentChatId || 0;
			}
		} catch (e) { /* ignore */ }
	}

	function setHistoryLoader(visible) {
		let el = messagesEl.querySelector('.wa-history-loader');
		if (!visible) {
			if (el) el.remove();
			return;
		}
		if (!el) {
			el = document.createElement('div');
			el.className = 'wa-history-loader';
			el.textContent = 'Загрузка...';
			messagesEl.insertBefore(el, messagesEl.firstChild);
		}
	}

	async function prependMessages(messages) {
		cacheCurrentMessages(messages);
		rememberMessages(messages);
		await hydrateReplyMeta(messages);
		if (!messages || !messages.length) return;
		rememberMessages(messages);

		const prevScrollHeight = messagesEl.scrollHeight;
		const prevScrollTop = messagesEl.scrollTop;
		const sorted = messages.slice().sort(function (a, b) { return a.id - b.id; });
		const firstDivider = messagesEl.querySelector('.wa-msg-date-divider');
		const firstDayInDom = firstDivider ? firstDivider.dataset.day : '';
		const frag = document.createDocumentFragment();
		let lastDayKey = '';

		sorted.forEach(function (msg) {
			if (messagesEl.querySelector('[data-id="' + msg.id + '"]')) return;
			const msgDate = parseMessageDate(msg);
			const dayKey = msgDate ? msgDate.toDateString() : '';
			if (dayKey && dayKey !== lastDayKey && dayKey !== firstDayInDom &&
				!messagesEl.querySelector('.wa-msg-date-divider[data-day="' + dayKey + '"]')) {
				const div = document.createElement('div');
				div.className = 'wa-msg-date-divider';
				div.dataset.day = dayKey;
				div.textContent = formatMessageDayLabel(msgDate);
				frag.appendChild(div);
				lastDayKey = dayKey;
			}
			frag.appendChild(renderMessage(msg));
		});

		if (!frag.childNodes.length) return;

		const loader = messagesEl.querySelector('.wa-history-loader');
		if (loader) messagesEl.insertBefore(frag, loader.nextSibling);
		else messagesEl.insertBefore(frag, messagesEl.firstChild);

		trackMessageBounds(sorted);
		messagesEl.scrollTop = messagesEl.scrollHeight - prevScrollHeight + prevScrollTop;
		bindLazyMedia(messagesEl);
	}

	function appendDateDivider(dayKey, d) {
		if (messagesEl.querySelector('.wa-msg-date-divider[data-day="' + dayKey + '"]')) return;
		const div = document.createElement('div');
		div.className = 'wa-msg-date-divider';
		div.dataset.day = dayKey;
		div.textContent = formatMessageDayLabel(d);
		messagesEl.appendChild(div);
	}

	function messageContentFingerprint(msg) {
		if (!msg) return '';
		return JSON.stringify([
			stripConnectorPrefix(messageRawText(msg)),
			getReplyId(msg),
			getFileIds(msg).map(parseFileId).filter(Boolean),
			parseInt(msg.author_id || msg.authorId || 0, 10) || 0
		]);
	}

	async function appendMessages(messages, replace, opts) {
		opts = opts || {};
		const previousById = {};
		(messages || []).forEach(function (msg) {
			const id = parseInt(msg && msg.id, 10) || 0;
			if (id && messagesById[id]) previousById[id] = messagesById[id];
		});
		if (replace) {
			messagesEl.innerHTML = '';
			selectedMessageIds.clear();
			updateBulkBar();
			openUnreadAnchorId = 0;
			openScrollMode = 'bottom';
		}
		cacheCurrentMessages(messages);
		rememberMessages(messages);
		await hydrateReplyMeta(messages);
		if (!messages || !messages.length) {
			if (replace) {
				firstMessageId = 0;
				lastMessageId = 0;
				messagesEl.innerHTML = '<div class="wa-empty">Нет сообщений</div>';
			}
			return;
		}
		const empty = messagesEl.querySelector('.wa-empty');
		if (empty) empty.remove();

		const unreadCount = parseInt(opts.unreadCount, 10) || 0;
		const firstUnread = replace ? findFirstUnreadMessage(messages, unreadCount) : null;
		if (firstUnread && firstUnread.id) {
			openScrollMode = 'unread';
			openUnreadAnchorId = parseInt(firstUnread.id, 10) || 0;
		} else if (replace) {
			openScrollMode = 'bottom';
			openUnreadAnchorId = 0;
		}

		const stickBottom = replace ? (openScrollMode === 'bottom') : shouldStickToBottom();
		let lastDayKey = '';
		if (!replace) {
			const dividers = messagesEl.querySelectorAll('.wa-msg-date-divider');
			if (dividers.length) lastDayKey = dividers[dividers.length - 1].dataset.day || '';
		}

		messages.slice().sort((a, b) => a.id - b.id).forEach(msg => {
			const existing = messagesEl.querySelector('[data-id="' + msg.id + '"]');
			if (existing) {
				const previous = previousById[parseInt(msg.id, 10) || 0];
				const changed = !!previous && messageContentFingerprint(previous) !== messageContentFingerprint(msg);
				if (!changed && !opts.markEdited) return;
				msg._edited = !!(msg._edited || opts.markEdited || changed || existing.querySelector('.wa-msg-edited'));
				existing.replaceWith(renderMessage(msg));
				return;
			}
			const msgDate = parseMessageDate(msg);
			const dayKey = msgDate ? msgDate.toDateString() : '';
			if (dayKey && dayKey !== lastDayKey) {
				appendDateDivider(dayKey, msgDate);
				lastDayKey = dayKey;
			}
			messagesEl.appendChild(renderMessage(msg));
		});
		if (replace && openUnreadAnchorId) insertUnreadSeparator(openUnreadAnchorId);
		trackMessageBounds(messages);
		if (replace) {
			scheduleOpenScrollKeep();
		} else if (stickBottom) {
			scrollMessagesToBottom();
		}
		bindLazyMedia(messagesEl);
	}

	function currentNewChatOffer() {
		if (crmNewChatOffer && crmNewChatOffer.phone) return crmNewChatOffer;
		const q = (searchQuery || '').trim();
		const phone = buildWaPhoneDigits(q);
		if (phone.length < 10) return null;
		if (completedPhoneSearchKey !== normalizePhoneDigits(q)) return null;
		return { phone: phone, source: 'search', entityType: '', entityId: 0 };
	}

	function loadWaLines() {
		if (waLinesPromise) return waLinesPromise;
		let url;
		if (window.__WA_NOPROLOG) {
			url = new URL(window.location.pathname, window.location.origin);
			url.search = '';
			url.searchParams.set('wa_lines', '1');
			if (window.__WA_AID) url.searchParams.set('wa_aid', window.__WA_AID);
		} else {
			url = new URL('/local/custom_chat/ajax_wa_lines.php', window.location.origin);
		}
		waLinesPromise = fetch(url.toString(), { credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (data) {
				if (!data || !data.ok) throw new Error('Не удалось получить WhatsApp-линии');
				return Array.isArray(data.lines) ? data.lines : [];
			})
			.catch(function (e) {
				waLinesPromise = null;
				throw e;
			});
		return waLinesPromise;
	}

	function chooseWaLine(lines) {
		if (!lines.length) return Promise.resolve(null);
		if (lines.length === 1) return Promise.resolve(lines[0]);
		const text = lines.map(function (line, i) {
			const number = line.number ? (' · +' + line.number) : '';
			return (i + 1) + '. ' + line.name + number;
		}).join('\n');
		const raw = window.prompt('Выберите WhatsApp-линию:\n\n' + text, '1');
		if (raw === null) return Promise.resolve(null);
		const idx = parseInt(raw, 10) - 1;
		return Promise.resolve(lines[idx] || null);
	}

	async function attachNewChatToCrm(chatId, offer) {
		if (!offer || !offer.entityType || !offer.entityId) return;
		let url;
		if (window.__WA_NOPROLOG) {
			url = new URL(window.location.pathname, window.location.origin);
			url.search = '';
			url.searchParams.set('wa_attach', '1');
			if (window.__WA_AID) url.searchParams.set('wa_aid', window.__WA_AID);
		} else {
			url = new URL('/local/custom_chat/ajax_wa_attach.php', window.location.origin);
		}
		const body = new URLSearchParams();
		body.set('chatId', String(chatId));
		body.set('entityType', offer.entityType);
		body.set('entityId', String(offer.entityId));
		const response = await fetch(url.toString(), {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
			body: body.toString()
		});
		const data = await response.json();
		if (!data || !data.ok) console.warn('WA CC: CRM attach', data);
	}

	async function createNewWaSession(phone, line) {
		let url;
		if (window.__WA_NOPROLOG) {
			url = new URL(window.location.pathname, window.location.origin);
			url.search = '';
			url.searchParams.set('wa_start', '1');
			if (window.__WA_AID) url.searchParams.set('wa_aid', window.__WA_AID);
		} else {
			url = new URL('/local/custom_chat/ajax_wa_start.php', window.location.origin);
		}
		const body = new URLSearchParams();
		body.set('phone', phone);
		body.set('lineId', String(line.id));
		const response = await fetch(url.toString(), {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
			body: body.toString()
		});
		const data = await response.json();
		if (!data || !data.ok) {
			throw new Error((data && (data.message || data.error)) || 'Битрикс не создал чат');
		}
		return parseInt(data.chatId, 10) || 0;
	}

	async function startNewWaDialog(offer, button) {
		if (!offer || !offer.phone) return;
		if (button) button.disabled = true;
		try {
			const lines = await loadWaLines();
			if (!lines.length) throw new Error('У вас нет подключённой WhatsApp-линии');
			const line = await chooseWaLine(lines);
			if (!line) return;

			const phone = buildWaPhoneDigits(offer.phone);
			const chatId = await createNewWaSession(phone, line);
			if (!chatId) throw new Error('Битрикс не создал чат');

			let target = null;
			for (let i = 0; i < 8 && !target; i++) {
				try { target = await chatItemFromDialogChatId(chatId); } catch (e) {}
				if (!target) await new Promise(function (resolve) { setTimeout(resolve, 350); });
			}
			if (!target) throw new Error('Чат создан, но ещё не готов к открытию');
			try {
				await attachNewChatToCrm(chatId, offer);
			} catch (e) {
				console.warn('WA CC: не удалось привязать новый чат к CRM', e);
			}

			target._phones = [phone];
			chatsCache = mergeChatLists(chatsCache, [target]);
			crmNewChatOffer = null;
			searchQuery = '';
			searchEl.value = '';
			renderChatList();
			await openDialog(target, { keepPlacement: true });
		} catch (e) {
			const msg = e && (e.error_description || e.message || (e.ex && e.ex.error_description))
				|| 'Не удалось начать диалог';
			alert('Не удалось начать WhatsApp-диалог: ' + msg);
		} finally {
			if (button) button.disabled = false;
		}
	}

	function renderNewChatOffer(offer) {
		const phone = buildWaPhoneDigits(offer && offer.phone);
		if (phone.length < 10) return;
		const box = document.createElement('div');
		box.className = 'wa-new-chat';
		box.innerHTML =
			'<div class="wa-new-chat-title">Чат с этим номером не найден</div>' +
			'<div class="wa-new-chat-phone">' + BX.util.htmlspecialchars(formatPhoneDisplay(phone)) + '</div>' +
			'<button type="button" class="wa-new-chat-btn">Начать новый диалог</button>';
		const button = box.querySelector('.wa-new-chat-btn');
		button.addEventListener('click', function () {
			startNewWaDialog(offer, button);
		});
		listEl.appendChild(box);
	}

	function renderChatList() {
		let items = applyListFilter(chatsCache.slice());
		if (searchQuery.trim()) {
			items = items.filter(c => matchesChatSearch(c, searchQuery));
			const searchPhone = normalizePhoneDigits(searchQuery);
			if (searchPhone.length >= 10) {
				items = items.filter(isChatAllowedForPhoneSearch);
				if (completedPhoneSearchKey !== searchPhone) items = [];
			}
		}
		items = dedupeChatsByPhone(items);
		updateTabUi();

		listEl.innerHTML = '';
		if (!items.length) {
			const offer = currentNewChatOffer();
			if (offer && !searchRemoteLoading) {
				renderNewChatOffer(offer);
			} else {
				listEl.innerHTML = '<div class="wa-chat-item" style="justify-content:center;color:#667781;padding:24px 14px;">' +
					BX.util.htmlspecialchars(emptyListLabel()) + '</div>';
			}
			return;
		}

		items.forEach(chat => {
			const dialogId = resolveDialogId(chat);
			const av = getAvatarData(chat);
			const title = av.title;
			const preview = previewText(chat);
			const isGroup = isWhatsAppGroupChat(chat);
			const phone = isGroup ? '' : getPrimaryPhone(chat);
			const phoneLabel = phone ? formatPhoneDisplay(phone) : '';
			const titleHasPhone = phone && normalizePhoneDigits(title).indexOf(phone) !== -1;
			const counter = parseInt(chat.counter || 0, 10);
			const time = formatListTime(
				(chat.message && chat.message.date) || chat.date_update || chat.date_last_activity
			);
			const unread = counter > 0 || chat.unread;
			const closed = isChatClosed(chat);

			const item = document.createElement('div');
			item.className = 'wa-chat-item' +
				(dialogId === currentDialogId ? ' active' : '') +
				(dialogId === crmFocusDialogId ? ' from-crm' : '') +
				(closed ? ' is-closed' : '');
			item.dataset.dialogId = dialogId;
			if (phone) item.dataset.phone = phone;
			item.innerHTML =
				avatarHtml(av) +
				'<div class="wa-chat-meta">' +
					'<div class="wa-chat-row">' +
						'<div class="wa-chat-title">' + BX.util.htmlspecialchars(title) + '</div>' +
						'<div class="wa-chat-time' + (unread ? ' unread' : '') + '">' + BX.util.htmlspecialchars(time) + '</div>' +
					'</div>' +
					(phoneLabel && !titleHasPhone
						? '<div class="wa-chat-phone">' + BX.util.htmlspecialchars(phoneLabel) + '</div>'
						: '') +
					'<div class="wa-chat-row2">' +
						(closed ? '<span class="wa-chat-closed">завершён</span>' : '') +
						'<div class="wa-chat-preview">' + BX.util.htmlspecialchars(preview) + '</div>' +
						(counter > 0 ? '<span class="wa-badge">' + counter + '</span>' : '') +
					'</div>' +
				'</div>';
			item.addEventListener('click', () => openDialog(chat));
			listEl.appendChild(item);
		});

		const activeEl = listEl.querySelector('.wa-chat-item.active');
		if (activeEl && typeof activeEl.scrollIntoView === 'function') {
			try {
				activeEl.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
			} catch (e) {
				activeEl.scrollIntoView(false);
			}
		}
	}

	async function loadChatList() {
		try {
			let list = await fetchRecentOlChats().catch(function () { return []; });
			if (!list.length) {
				const all = await rest('im.recent.get', { SKIP_OPENLINES: 'N' });
				list = (all || []).filter(isOpenLine);
			}

			const keep = [];
			if (currentChatData) keep.push(currentChatData);
			if (crmFocusDialogId) {
				const pin = chatsCache.find(function (c) { return resolveDialogId(c) === crmFocusDialogId; });
				if (pin) keep.push(pin);
			}
			if (searchQuery.trim()) {
				const q = searchQuery;
				chatsCache.forEach(function (c) {
					if (c._fromSearch || matchesChatSearch(c, q)) keep.push(c);
				});
			}
			chatsCache = mergeChatLists(keep, list).map(chat => {
				chat._phones = getChatPhones(chat);
				if (isChatClosed(chat) && !isPinnedCrmChat(chat)) markChatClosed(chat);
				return applyLocalReadState(chat);
			});
			markCurrentChatReadLocally();
			await enrichChatDisplayNames(chatsCache);
			renderChatList();
			if (currentDialogId) {
				const found = chatsCache.find(c => resolveDialogId(c) === currentDialogId);
				if (found) {
					currentChatData = found;
					refreshSessionState();
				}
			}
		} catch (e) {
			console.error(e);
			listEl.innerHTML = '<div class="wa-chat-item">Ошибка загрузки списка</div>';
		}
	}

	function chatSearchMessageText(msg) {
		return stripConnectorPrefix(messageRawText(msg))
			.replace(/\[(?:\/)?[a-z][^\]]*\]/gi, ' ')
			.replace(/<[^>]+>/g, ' ')
			.replace(/&nbsp;|&#160;/gi, ' ')
			.replace(/\s+/g, ' ')
			.trim()
			.toLocaleLowerCase('ru-RU');
	}

	function clearChatSearchHighlight() {
		messagesEl.querySelectorAll('.wa-chat-search-hit').forEach(function (node) {
			node.classList.remove('wa-chat-search-hit');
		});
	}

	function updateChatSearchUi(scanning) {
		const total = chatSearchResults.length;
		if (scanning) {
			chatSearchStatus.textContent = total ? ('Ищем… ' + total) : 'Ищем…';
		} else if (total && chatSearchIndex >= 0) {
			chatSearchStatus.textContent = (chatSearchIndex + 1) + ' из ' + total;
		} else {
			chatSearchStatus.textContent = chatSearchField.value.trim().length >= 2 ? 'Не найдено' : '';
		}
		chatSearchPrev.disabled = total < 2;
		chatSearchNext.disabled = total < 2;
	}

	function rebuildChatSearchResults(query, keepMessageId) {
		const needle = String(query || '').trim().toLocaleLowerCase('ru-RU');
		chatSearchResults = Array.from(currentMessageCache.values()).filter(function (msg) {
			return needle.length >= 2 && chatSearchMessageText(msg).indexOf(needle) !== -1;
		}).sort(function (a, b) {
			return (parseInt(b.id, 10) || 0) - (parseInt(a.id, 10) || 0);
		});
		chatSearchIndex = keepMessageId
			? chatSearchResults.findIndex(function (msg) {
				return (parseInt(msg.id, 10) || 0) === keepMessageId;
			})
			: (chatSearchResults.length ? 0 : -1);
		if (chatSearchIndex < 0 && chatSearchResults.length) chatSearchIndex = 0;
	}

	async function focusChatSearchResult(index) {
		if (!chatSearchResults.length) return;
		chatSearchIndex = (index + chatSearchResults.length) % chatSearchResults.length;
		const id = parseInt(chatSearchResults[chatSearchIndex].id, 10) || 0;
		if (!id) return;
		clearChatSearchHighlight();

		let node = messagesEl.querySelector('.wa-msg[data-id="' + id + '"]');
		if (!node && currentMessageCache.has(id)) {
			const cachedRange = Array.from(currentMessageCache.values()).filter(function (msg) {
				const messageId = parseInt(msg && msg.id, 10) || 0;
				return messageId && messageId < firstMessageId && messageId >= id;
			});
			if (cachedRange.length) {
				await prependMessages(cachedRange);
				node = messagesEl.querySelector('.wa-msg[data-id="' + id + '"]');
			}
		}
		while (!node && hasMoreHistory && currentDialogId) {
			const before = firstMessageId;
			await loadOlderMessages();
			node = messagesEl.querySelector('.wa-msg[data-id="' + id + '"]');
			if (firstMessageId === before) break;
		}
		if (node) {
			node.classList.add('wa-chat-search-hit');
			node.scrollIntoView({ block: 'center', behavior: 'smooth' });
		}
		updateChatSearchUi(false);
	}

	async function runChatMessageSearch() {
		clearTimeout(chatSearchTimer);
		const query = chatSearchField.value.trim();
		const token = ++chatSearchToken;
		const dialogId = currentDialogId;
		clearChatSearchHighlight();
		if (!dialogId || query.length < 2) {
			chatSearchResults = [];
			chatSearchIndex = -1;
			updateChatSearchUi(false);
			return;
		}

		rebuildChatSearchResults(query, 0);
		updateChatSearchUi(!chatSearchHistoryComplete);
		if (chatSearchHistoryComplete) {
			updateChatSearchUi(false);
			if (chatSearchResults.length) focusChatSearchResult(0);
			return;
		}

		let cursor = 0;
		currentMessageCache.forEach(function (msg) {
			const id = parseInt(msg && msg.id, 10) || 0;
			if (id && (!cursor || id < cursor)) cursor = id;
		});
		cursor = cursor || firstMessageId;

		try {
			while (cursor && token === chatSearchToken && dialogId === currentDialogId) {
				const data = await rest('im.dialog.messages.get', {
					DIALOG_ID: dialogId,
					LAST_ID: cursor,
					LIMIT: MESSAGES_PAGE
				});
				if (token !== chatSearchToken || dialogId !== currentDialogId) return;
				const messages = data.messages || [];
				mergeUsers(data.users);
				mergeFiles(data.files);
				cacheCurrentMessages(messages);
				const selectedId = chatSearchResults[chatSearchIndex]
					? (parseInt(chatSearchResults[chatSearchIndex].id, 10) || 0)
					: 0;
				rebuildChatSearchResults(query, selectedId);
				updateChatSearchUi(true);

				let nextCursor = cursor;
				messages.forEach(function (msg) {
					const id = parseInt(msg && msg.id, 10) || 0;
					if (id && id < nextCursor) nextCursor = id;
				});
				if (!messages.length || messages.length < MESSAGES_PAGE || nextCursor >= cursor) {
					chatSearchHistoryComplete = true;
					break;
				}
				cursor = nextCursor;
				await new Promise(function (resolve) { setTimeout(resolve, 120); });
			}
			if (token !== chatSearchToken || dialogId !== currentDialogId) return;
			chatSearchHistoryComplete = true;
			const selectedId = chatSearchResults[chatSearchIndex]
				? (parseInt(chatSearchResults[chatSearchIndex].id, 10) || 0)
				: 0;
			rebuildChatSearchResults(query, selectedId);
			updateChatSearchUi(false);
			if (chatSearchResults.length && chatSearchIndex >= 0) {
				focusChatSearchResult(chatSearchIndex);
			}
		} catch (e) {
			console.error('WA CC: поиск по сообщениям', e);
			if (token === chatSearchToken) {
				chatSearchStatus.textContent = 'Ошибка поиска';
			}
		}
	}

	function closeChatMessageSearch() {
		chatSearchToken++;
		clearTimeout(chatSearchTimer);
		clearChatSearchHighlight();
		chatSearchPanel.classList.remove('visible');
		chatSearchToggle.classList.remove('active');
		chatSearchField.value = '';
		chatSearchResults = [];
		chatSearchIndex = -1;
		chatSearchStatus.textContent = '';
	}

	async function loadMessages(dialogId, opts) {
		opts = opts || {};
		currentMessageCache = new Map();
		chatSearchHistoryComplete = false;
		messagesEl.innerHTML = '<div class="wa-empty">Загрузка...</div>';
		lastMessageId = 0;
		firstMessageId = 0;
		hasMoreHistory = true;
		historyLoading = false;
		openingScrollLock = true;
		openScrollMode = 'bottom';
		openUnreadAnchorId = 0;
		filesMap = {};
		usersMap = {};
		seedCurrentUser();
		try {
			const data = await rest('im.dialog.messages.get', {
				DIALOG_ID: dialogId,
				LIMIT: MESSAGES_PAGE
			});
			const messages = data.messages || [];
			if (data.chat_id) currentChatId = data.chat_id;
			mergeUsers(data.users);
			mergeFiles(data.files);
			hydrateFilesFromMessages(messages);
			if (/\bwa_debug_senders=1\b/.test(String(location.search || ''))) {
				const rows = (messages || []).slice(0, 20).map(function (m) {
					const raw = messageRawText(m);
					return {
						id: m.id,
						author: messageAuthorId(m),
						out: isOutgoingMessage(m),
						parsed: parseHumanSenderFromText(raw),
						text: raw.slice(0, 160)
					};
				});
				console.log('WA CC senders debug', rows);
				try { console.table(rows); } catch (e) {}
			}
			await ensureUsersLoaded(collectMessageAuthorIds(messages));
			await prefetchFileUrls(collectFileIdsFromMessages(messages));
			await appendMessages(messages, true, { unreadCount: opts.unreadCount || 0 });
			hasMoreHistory = messages.length >= MESSAGES_PAGE;
			chatSearchHistoryComplete = !hasMoreHistory;
			markCurrentChatReadLocally();
			renderChatList();
			rest('im.dialog.read', { DIALOG_ID: dialogId }).catch(() => {});
		} catch (e) {
			console.error(e);
			openingScrollLock = false;
			messagesEl.innerHTML = '<div class="wa-empty">Не удалось загрузить сообщения</div>';
		}
	}

	async function loadOlderMessages() {
		if (!currentDialogId || historyLoading || !hasMoreHistory || !firstMessageId) return;
		if (openingScrollLock) return;
		historyLoading = true;
		setHistoryLoader(true);
		try {
			const data = await rest('im.dialog.messages.get', {
				DIALOG_ID: currentDialogId,
				LAST_ID: firstMessageId,
				LIMIT: MESSAGES_PAGE
			});
			const messages = data.messages || [];
			mergeUsers(data.users);
			mergeFiles(data.files);
			hydrateFilesFromMessages(messages);
			if (!messages.length) {
				hasMoreHistory = false;
				chatSearchHistoryComplete = true;
				return;
			}
			await ensureUsersLoaded(collectMessageAuthorIds(messages));
			await prefetchFileUrls(collectFileIdsFromMessages(messages));
			const beforeFirst = firstMessageId;
			await prependMessages(messages);
			if (firstMessageId >= beforeFirst || messages.length < MESSAGES_PAGE) {
				hasMoreHistory = false;
				chatSearchHistoryComplete = true;
			}
		} catch (e) {
			console.error(e);
		} finally {
			historyLoading = false;
			setHistoryLoader(false);
		}
	}

	window.openDialog = async function (chatData, opts) {
		opts = opts || {};
		const dialogId = resolveDialogId(chatData);
		if (!dialogId) return;

		closeChatMessageSearch();
		if (recording) await cancelRecording();
		clearReplyTo();

		// Клик по списку чатов — не тащим leadId из другой карточки / sessionStorage
		if (!opts.keepPlacement) {
			crmPlacementLeadId = 0;
			crmPlacementDealId = 0;
			crmContextLeadId = 0;
			crmContextDealId = 0;
		}

		currentDialogId = dialogId;
		currentChatId = chatData.chat_id || (chatData.chat && chatData.chat.id) || null;
		if (opts.keepPlacement) {
			persistCrmContextForChat(currentChatId);
		}
		currentChatIsOpenLine = true;
		currentChatData = chatData;
		opponentReadMessageId = 0;
		waChatTickStatus = '';
		waChatTickTs = 0;
		waChatReadTs = 0;
		const unreadCount = parseInt(chatData.counter || 0, 10) || (chatData.unread ? 1 : 0);
		markCurrentChatReadLocally();
		publishOpenChatToPortal();
		await applyCrmBindings(chatData, null);

		const av = getAvatarData(chatData);
		titleEl.textContent = av.title;
		setHeaderAvatar(av);

		inputBar.classList.add('visible');
		uploadHint.style.display = 'none';
		inputEl.value = '';
		updateSendButton();
		try {
			document.body.classList.add('wa-chat-open');
		} catch (e) { /* ignore */ }
		inputEl.focus();
		renderChatList();
		await loadMessages(dialogId, { unreadCount: unreadCount });
		publishOpenChatToPortal();
		await refreshSessionState();
		refreshReadReceipts({ force: true }).catch(function () {});
		startChatPolling();
		startTicksBurst();
	};

	function closeMobileChatView() {
		try {
			document.body.classList.remove('wa-chat-open');
		} catch (e) { /* ignore */ }
	}

	if (btnBack) {
		btnBack.addEventListener('click', function (e) {
			try { if (e && e.preventDefault) e.preventDefault(); } catch (err) { /* ignore */ }
			closeMobileChatView();
		});
	}

	(function detectMobileEmbed() {
		try {
			const sp = (typeof window.waCcParams === 'function')
				? window.waCcParams()
				: new URLSearchParams(window.location.search);
			const boot = window.__WA_CC_BOOT || {};
			const ua = navigator.userAgent || '';
			const bitrixApp = /BitrixMobile|BXMobileApp|Bitrix24\.Mobile/i.test(ua);
			const wide = !!(window.innerWidth >= 860 || (window.matchMedia && window.matchMedia('(min-width: 860px)').matches));

			if (bitrixApp) {
				document.body.classList.add('wa-cc-mobile');
				document.body.classList.remove('wa-cc-desktop');
				return;
			}
			if (wide || sp.get('wa_desktop') === '1') {
				document.body.classList.add('wa-cc-desktop');
				document.body.classList.remove('wa-cc-mobile');
				return;
			}
			if (document.body.classList.contains('wa-cc-mobile') || boot.mobile || sp.get('wa_mobile') === '1') {
				document.body.classList.add('wa-cc-mobile');
				document.body.classList.remove('wa-cc-desktop');
				return;
			}
			if (sp.get('wa_embed') === '1') {
				document.body.classList.add('wa-cc-desktop');
				return;
			}
			if (window.matchMedia && window.matchMedia('(max-width: 720px)').matches) {
				document.body.classList.add('wa-cc-mobile');
			}
		} catch (e) { /* ignore */ }
	})();

	function getEntityData2(chat, dialog) {
		if (dialog && dialog.entity_data_2) return dialog.entity_data_2;
		if (!chat) return '';
		if (chat.entity_data_2) return chat.entity_data_2;
		if (chat.chat && chat.chat.entity_data_2) return chat.chat.entity_data_2;
		return '';
	}

	function parseCrmBindings(entityData2) {
		const result = { leadId: 0, dealId: 0, contactId: 0, companyId: 0 };
		if (!entityData2 || typeof entityData2 !== 'string') return result;
		const parts = entityData2.split('|');
		for (let i = 0; i + 1 < parts.length; i += 2) {
			const type = (parts[i] || '').toUpperCase();
			const id = parseInt(parts[i + 1], 10) || 0;
			if (id <= 0) continue;
			if (type === 'LEAD') result.leadId = id;
			if (type === 'DEAL') result.dealId = id;
			if (type === 'CONTACT') result.contactId = id;
			if (type === 'COMPANY') result.companyId = id;
		}
		return result;
	}

	function waCcIsMobileClient() {
		return !!(window.__WA_NOPROLOG
			|| (window.__WA_CC_BOOT && window.__WA_CC_BOOT.mobile)
			|| (document.body && document.body.classList.contains('wa-cc-mobile'))
			|| /BitrixMobile|BXMobileApp|Bitrix24\.Mobile/i.test(navigator.userAgent || ''));
	}

	function getMobileCrmEntityPaths(type, id) {
		const entityTypeId = type === 'lead' ? 1 : 2;
		const typeName = type === 'lead' ? 'lead' : 'deal';
		const idKey = type === 'lead' ? 'lead_id' : 'deal_id';
		return [
			'/mobile/crm/' + typeName + '/?page=view&' + idKey + '=' + id,
			'/mobile/crm/type/' + entityTypeId + '/details/' + id + '/',
			'/crm/type/' + entityTypeId + '/details/' + id + '/',
			(type === 'lead' ? '/crm/lead/details/' : '/crm/deal/details/') + id + '/'
		];
	}

	function waCcHostWindows(skipSelf) {
		const seen = new Set();
		const list = [];
		if (!skipSelf) {
			try { list.push(window); } catch (e) { /* ignore */ }
		}
		try { if (window.parent) list.push(window.parent); } catch (e) { /* ignore */ }
		try { if (window.top) list.push(window.top); } catch (e) { /* ignore */ }
		try { if (window.opener) list.push(window.opener); } catch (e) { /* ignore */ }
		const out = [];
		for (let i = 0; i < list.length; i++) {
			try {
				const w = list[i];
				if (!w || seen.has(w)) continue;
				if (skipSelf && w === window) continue;
				seen.add(w);
				out.push(w);
			} catch (e) { /* ignore */ }
		}
		return out;
	}

	function openCrmViaPageManager(hosts, paths, title, origin) {
		for (let h = 0; h < hosts.length; h++) {
			try {
				const pm = hosts[h].BXMobileApp && hosts[h].BXMobileApp.PageManager;
				if (!pm) continue;
				for (let p = 0; p < paths.length; p++) {
					const opts = {
						url: origin + paths[p],
						title: title,
						cache: false,
						bx24ModernStyle: true
					};
					if (typeof pm.loadPageBlank === 'function') {
						pm.loadPageBlank(opts);
						return true;
					}
					if (typeof pm.loadPageStart === 'function') {
						pm.loadPageStart(opts);
						return true;
					}
					if (typeof pm.loadPageModal === 'function') {
						pm.loadPageModal(opts);
						return true;
					}
				}
			} catch (e) { /* ignore */ }
		}
		return false;
	}

	function openCrmViaNativeEntity(hosts, type, id) {
		const isLead = type === 'lead';
		const entityTypeId = isLead ? 1 : 2;
		for (let i = 0; i < hosts.length; i++) {
			const w = hosts[i];
			try {
				if (w.Application && typeof w.Application.openCrmEntity === 'function') {
					try {
						w.Application.openCrmEntity({ entityTypeId: entityTypeId, entityId: id });
					} catch (e1) {
						w.Application.openCrmEntity({ id: id, type: isLead ? 'lead' : 'deal' });
					}
					return true;
				}
			} catch (e) { /* ignore */ }
			try {
				if (w.BX && w.BX.MobileTools && typeof w.BX.MobileTools.openEntity === 'function') {
					w.BX.MobileTools.openEntity({ typeName: isLead ? 'lead' : 'deal', id: id });
					return true;
				}
			} catch (e) { /* ignore */ }
		}
		return false;
	}

	function openCrmViaApplicationUrl(hosts, url) {
		for (let i = 0; i < hosts.length; i++) {
			try {
				const app = hosts[i].Application;
				if (app && typeof app.openUrl === 'function') {
					app.openUrl(url);
					return true;
				}
			} catch (e) { /* ignore */ }
		}
		return false;
	}

	function openCrmViaMobileBridge(type, id) {
		const isLead = type === 'lead';
		const entityTypeId = isLead ? 1 : 2;
		const title = (isLead ? 'Лид #' : 'Сделка #') + id;
		const origin = location.origin || '';
		let paths = getMobileCrmEntityPaths(type, id);

		try {
			const router = waCcEachHostWindow(function (w) {
				if (w.BX && w.BX.Crm && w.BX.Crm.Router && w.BX.Crm.Router.Instance) {
					return w.BX.Crm.Router.Instance;
				}
				return null;
			});
			if (router && typeof router.getItemDetailUrl === 'function') {
				const uri = router.getItemDetailUrl(entityTypeId, id);
				const routed = uri && (uri.toString ? uri.toString() : String(uri));
				if (routed && routed.indexOf('/') === 0) {
					paths = [routed].concat(paths);
				}
			}
		} catch (e) { /* ignore */ }

		const allHosts = waCcHostWindows(false);
		const primaryUrl = origin + paths[0];

		// 1) Нативная карточка CRM в BitrixMobile (лучший путь)
		if (openCrmViaNativeEntity(allHosts, type, id)) {
			return true;
		}

		// 2) PageManager — как кнопка WhatsApp в карточке CRM
		if (openCrmViaPageManager(allHosts, paths, title, origin)) {
			return true;
		}

		// 3) Application.openUrl
		if (openCrmViaApplicationUrl(allHosts, primaryUrl)) {
			return true;
		}

		return false;
	}

	function getCrmEntityUrl(type, id) {
		id = parseInt(id, 10) || 0;
		if (!id) return '#';
		const entityTypeId = type === 'lead' ? 1 : 2;
		if (waCcIsMobileClient()) {
			const origin = location.origin || '';
			const typeName = type === 'lead' ? 'lead' : 'deal';
			const idKey = type === 'lead' ? 'lead_id' : 'deal_id';
			return origin + '/mobile/crm/' + typeName + '/?page=view&' + idKey + '=' + id;
		}
		return type === 'lead'
			? '/crm/lead/details/' + id + '/'
			: '/crm/deal/details/' + id + '/';
	}

	function openCrmEntity(type, id) {
		id = parseInt(id, 10) || 0;
		if (!id) return;

		const isLead = type === 'lead';
		const desktopPath = isLead
			? '/crm/lead/details/' + id + '/'
			: '/crm/deal/details/' + id + '/';

		const onMobile = waCcIsMobileClient();

		function sidePanelOpen(path) {
			const candidates = [];
			try { if (window.top && window.top.BX && window.top.BX.SidePanel) candidates.push(window.top.BX.SidePanel.Instance); } catch (e) {}
			try { if (window.parent && window.parent !== window && window.parent.BX && window.parent.BX.SidePanel) candidates.push(window.parent.BX.SidePanel.Instance); } catch (e) {}
			try { if (window.BX && BX.SidePanel) candidates.push(BX.SidePanel.Instance); } catch (e) {}
			for (let i = 0; i < candidates.length; i++) {
				if (candidates[i] && typeof candidates[i].open === 'function') {
					candidates[i].open(path, { cacheable: false, width: 920 });
					return true;
				}
			}
			return false;
		}

		if (!onMobile) {
			try {
				if (window.BX24 && typeof BX24.openPath === 'function') {
					BX24.openPath(desktopPath);
					return;
				}
			} catch (e) { /* ignore */ }
			if (sidePanelOpen(desktopPath)) return;
			try {
				if (window.top && window.top !== window) {
					window.top.location.href = desktopPath;
					return;
				}
			} catch (e) { /* ignore */ }
			const aDesk = document.createElement('a');
			aDesk.href = desktopPath;
			aDesk.target = '_top';
			document.body.appendChild(aDesk);
			aDesk.click();
			aDesk.remove();
			return;
		}

		const paths = getMobileCrmEntityPaths(type, id);
		const absUrl = (location.origin || '') + paths[0];
		const title = (isLead ? 'Лид #' : 'Сделка #') + id;

		if (openCrmViaMobileBridge(type, id)) {
			return;
		}

		try {
			if (window.top && window.top !== window) {
				if (openCrmViaPageManager([window.top], paths, title, location.origin || '')) {
					return;
				}
			}
		} catch (e) { /* ignore */ }

		const aMob = document.createElement('a');
		aMob.href = absUrl;
		aMob.target = '_top';
		aMob.rel = 'noopener noreferrer';
		document.body.appendChild(aMob);
		aMob.click();
		aMob.remove();
	}

	function updateCrmButtons() {
		const hasChat = currentChatIsOpenLine && currentChatId;
		const effLead = getEffectiveCrmId('lead');
		const effDeal = getEffectiveCrmId('deal');
		btnLead.style.display = hasChat && effLead ? 'inline-block' : 'none';
		btnDeal.style.display = hasChat && effDeal ? 'inline-block' : 'none';
		btnLead.href = effLead ? getCrmEntityUrl('lead', effLead) : '#';
		btnDeal.href = effDeal ? getCrmEntityUrl('deal', effDeal) : '#';
		if (effLead) {
			btnLead.textContent = 'Лид #' + effLead;
		} else {
			btnLead.textContent = 'Лид';
		}
		if (effDeal) {
			btnDeal.textContent = 'Сделка #' + effDeal;
		} else {
			btnDeal.textContent = 'Сделка';
		}
	}

	/**
	 * Без placement (?leadId=): entity_data_2 часто старый лид (332043).
	 * Ищем лид по телефону клиента + imopenlines.crm.chat.get (новее = приоритет).
	 */
	async function resolveCrmLeadForChat(chatId, dialog, chat) {
		chatId = parseInt(chatId, 10);
		if (!chatId) return 0;
		if (crmPlacementLeadId > 0) return crmPlacementLeadId;

		const cacheKey = String(chatId);
		if (crmLeadResolveCache.has(cacheKey)) {
			return crmLeadResolveCache.get(cacheKey);
		}

		const item = chat || currentChatData;
		const phone = getClientPhone(item);
		let leadIds = [];

		if (phone && phone.length >= 10) {
			try {
				const dup = await rest('crm.duplicate.findbycomm', {
					type: 'PHONE',
					values: buildPhoneLookupValues(phone)
				});
				const dupData = dup.result || dup || {};
				leadIds = (dupData.LEAD || []).map(function (id) {
					return parseInt(id, 10);
				}).filter(Boolean);
				leadIds.sort(function (a, b) { return b - a; });
			} catch (e) {
				console.warn('resolveCrmLead duplicate', e);
			}
		}

		for (let i = 0; i < leadIds.length && i < 20; i++) {
			const leadId = leadIds[i];
			try {
				const chats = await rest('imopenlines.crm.chat.get', {
					CRM_ENTITY_TYPE: 'lead',
					CRM_ENTITY: leadId,
					ACTIVE_ONLY: 'N'
				});
				const list = Array.isArray(chats) ? chats : (chats && chats.result) || [];
				if (!Array.isArray(list)) continue;
				const owns = list.some(function (c) {
					return parseInt(c.CHAT_ID || c.chatId || c.id, 10) === chatId;
				});
				if (owns) {
					crmLeadResolveCache.set(cacheKey, leadId);
					return leadId;
				}
			} catch (e) { /* ignore */ }
		}

		for (let j = 0; j < leadIds.length && j < 10; j++) {
			const actIds = new Set();
			await fetchChatIdsFromCrmActivities('lead', leadIds[j], actIds);
			if (actIds.has(chatId)) {
				crmLeadResolveCache.set(cacheKey, leadIds[j]);
				return leadIds[j];
			}
		}

		const bindings = parseCrmBindings(getEntityData2(item, dialog));
		if (bindings.leadId > 0) {
			crmLeadResolveCache.set(cacheKey, bindings.leadId);
			return bindings.leadId;
		}

		const fallback = leadIds[0] || 0;
		crmLeadResolveCache.set(cacheKey, fallback);
		return fallback;
	}

	async function applyCrmBindings(chat, dialog) {
		crmBindings = parseCrmBindings(getEntityData2(chat, dialog));
		if (crmPlacementDealId > 0) {
			crmBindings.dealId = crmPlacementDealId;
		} else if (crmContextDealId > 0) {
			crmBindings.dealId = crmContextDealId;
		}
		if (crmPlacementLeadId > 0) {
			crmBindings.leadId = crmPlacementLeadId;
		} else {
			const chatId = parseInt(
				currentChatId || (chat && (chat.chat_id || (chat.chat && chat.chat.id))),
				10
			);
			const resolved = await resolveCrmLeadForChat(chatId, dialog, chat);
			if (resolved > 0) crmBindings.leadId = resolved;
		}
		updateCrmButtons();
	}

	function parseSessionState(dialog, chatData) {
		const managers = (dialog.manager_list || []).map(id => parseInt(id, 10)).filter(Boolean);
		const owner = parseInt(dialog.owner, 10) || 0;
		const lineStatus = parseInt(chatData && chatData.lines && chatData.lines.status, 10);
		const closedStatuses = CLOSED_LINE_STATUSES;
		const isClosed = closedStatuses.indexOf(lineStatus) !== -1;

		const acceptedByMe = managers.indexOf(CURRENT_USER_ID) !== -1 || owner === CURRENT_USER_ID;

		// 0/5/10 — в очереди, ждёт оператора
		const waitingStatuses = [0, 5, 10];
		const isWaiting = waitingStatuses.indexOf(lineStatus) !== -1 || (isNaN(lineStatus) && !acceptedByMe);

		return {
			acceptedByMe: acceptedByMe,
			isClosed: isClosed,
			needsAnswer: !isClosed && !acceptedByMe && isWaiting,
			canFinish: !isClosed && acceptedByMe
		};
	}

	function updateSessionButtons() {
		if (!currentChatIsOpenLine || !currentChatId) {
			headerActions.classList.remove('visible');
			btnAnswer.style.display = 'none';
			btnFinish.style.display = 'none';
			crmBindings = { leadId: 0, dealId: 0 };
			updateCrmButtons();
			subEl.textContent = 'Выберите чат';
			return;
		}

		headerActions.classList.add('visible');
		btnAnswer.style.display = sessionState.needsAnswer ? 'inline-block' : 'none';
		btnFinish.style.display = sessionState.canFinish ? 'inline-block' : 'none';
		updateCrmButtons();

		if (sessionState.isClosed) {
			subEl.textContent = 'диалог завершён';
		} else if (sessionState.acceptedByMe) {
			subEl.textContent = 'в работе';
		} else if (sessionState.needsAnswer) {
			subEl.textContent = 'ожидает ответа';
		} else {
			subEl.textContent = 'открытая линия';
		}
	}

	async function refreshSessionState() {
		if (!currentChatIsOpenLine || !currentChatId) {
			sessionState = { needsAnswer: false, canFinish: false, isClosed: false };
			updateSessionButtons();
			return;
		}

		try {
			const data = await rest('imopenlines.dialog.get', { CHAT_ID: parseInt(currentChatId, 10) });
			const dialog = data.result || data;
			if (dialog.entity_data_2 && currentChatData) {
				currentChatData.entity_data_2 = dialog.entity_data_2;
				if (currentChatData.chat) currentChatData.chat.entity_data_2 = dialog.entity_data_2;
			}
			applyReadedList(dialog.readed_list || dialog.readedList);
			if (Array.isArray(dialog.readed_list) && !isWhatsAppGroupChat(currentChatData)) {
				const guest = dialog.readed_list.find(function (r) {
					const uid = parseInt(r.user_id, 10);
					if (!uid || uid === CURRENT_USER_ID) return false;
					const u = usersMap[uid];
					if (u && isStaffPortalUser(u)) return false;
					if (u && isConnectorGuestUser(u)) return true;
					return false;
				});
				if (guest && guest.user_name && currentChatData) {
					const guestName = String(guest.user_name).trim();
					if (guestName && !isGenericOlTitle(guestName) && !isConnectorOperatorLabel(guestName)) {
						currentChatData._clientUserName = guestName;
					}
				}
			}
			sessionState = parseSessionState(dialog, currentChatData || {});
			await applyCrmBindings(currentChatData, dialog);
			await enrichChatDisplayNames([currentChatData]);
			if (currentChatData && currentChatData._displayName) {
				const key = chatStorageId(currentChatData);
				chatsCache.forEach(function (c) {
					if (chatStorageId(c) === key) {
						c._displayName = currentChatData._displayName;
						c._clientUserName = currentChatData._clientUserName;
						if (currentChatData.entity_data_2) c.entity_data_2 = currentChatData.entity_data_2;
					}
				});
			}
			const av = getAvatarData(currentChatData);
			titleEl.textContent = av.title;
			setHeaderAvatar(av);
			updateOutgoingTicks();
			renderChatList();
		} catch (e) {
			console.warn('session state', e);
			const lineStatus = parseInt(currentChatData && currentChatData.lines && currentChatData.lines.status, 10);
			const isClosed = CLOSED_LINE_STATUSES.indexOf(lineStatus) !== -1;
			const needsAnswer = !isClosed && [0, 5, 10].indexOf(lineStatus) !== -1;
			sessionState = {
				needsAnswer: needsAnswer,
				canFinish: !isClosed && !needsAnswer,
				isClosed: isClosed,
				acceptedByMe: !needsAnswer && !isClosed
			};
			await applyCrmBindings(currentChatData, null);
		}

		updateSessionButtons();
	}

	async function ensureCanSend() {
		if (!currentChatId) return;
		if (sessionState.needsAnswer) {
			await rest('imopenlines.operator.answer', { CHAT_ID: parseInt(currentChatId, 10) });
			await refreshSessionState();
			return;
		}
		if (!sessionState.isClosed) return;
		await rest('imopenlines.session.start', { CHAT_ID: parseInt(currentChatId, 10) });
		try {
			await rest('imopenlines.operator.answer', { CHAT_ID: parseInt(currentChatId, 10) });
		} catch (e) {
			console.warn('operator.answer after session.start', e);
		}
		if (currentChatData) {
			if (!currentChatData.lines) currentChatData.lines = {};
			currentChatData.lines.status = 20;
			currentChatData._waClosed = false;
		}
		await refreshSessionState();
	}

	async function acceptChat() {
		if (!currentChatId || sending) return;
		sending = true;
		btnAnswer.disabled = true;
		try {
			await rest('imopenlines.operator.answer', { CHAT_ID: parseInt(currentChatId, 10) });
			await refreshSessionState();
			await loadMessages(currentDialogId);
			loadChatList();
		} catch (e) {
			console.error(e);
			alert('Не удалось принять чат: ' + (e.ex ? e.ex.error_description : (e.error_description || e)));
		} finally {
			sending = false;
			btnAnswer.disabled = false;
		}
	}

	async function finishChat() {
		if (!currentChatId || sending) return;
		if (!confirm('Завершить диалог?')) return;

		sending = true;
		btnFinish.disabled = true;
		try {
			await rest('imopenlines.operator.finish', { CHAT_ID: parseInt(currentChatId, 10) });
			sessionState = { needsAnswer: false, canFinish: false, isClosed: true };
			if (currentChatData) {
				markChatClosed(currentChatData);
				const idx = chatsCache.findIndex(c => resolveDialogId(c) === currentDialogId);
				if (idx !== -1) {
					chatsCache[idx] = mergeChatRecords(chatsCache[idx], currentChatData);
				} else {
					chatsCache.unshift(currentChatData);
				}
				chatsCache = sortChatsDesc(chatsCache);
				renderChatList();
			}
			updateSessionButtons();
			await loadMessages(currentDialogId);
			loadChatList();
		} catch (e) {
			console.error(e);
			alert('Не удалось завершить чат: ' + (e.ex ? e.ex.error_description : (e.error_description || e)));
		} finally {
			sending = false;
			btnFinish.disabled = false;
		}
	}

	btnAnswer.addEventListener('click', acceptChat);
	btnFinish.addEventListener('click', finishChat);
	function bindCrmEntityButton(el, type) {
		el.addEventListener('click', function (e) {
			if (e && e.preventDefault) e.preventDefault();
			if (e && e.stopPropagation) e.stopPropagation();
			const id = getEffectiveCrmId(type);
			if (!id) return;
			openCrmEntity(type, id);
		});
	}
	bindCrmEntityButton(btnLead, 'lead');
	bindCrmEntityButton(btnDeal, 'deal');
	if (replyCancel) replyCancel.addEventListener('click', clearReplyTo);
	if (fwdCloseBtn) fwdCloseBtn.addEventListener('click', closeForwardPicker);
	if (fwdGoBtn) fwdGoBtn.addEventListener('click', confirmForward);
	if (bulkBarCancelBtn) bulkBarCancelBtn.addEventListener('click', clearSelectedMessages);
	if (bulkBarDownloadBtn) {
		bulkBarDownloadBtn.addEventListener('click', function () {
			downloadSelectedFiles().catch(function (e) {
				alert('Не удалось скачать файлы: ' + (e && (e.ex && e.ex.error_description || e.error_description || e.message) || e));
			});
		});
	}
	if (bulkBarForwardBtn) {
		bulkBarForwardBtn.addEventListener('click', function () {
			const msgs = getSelectedMessages();
			if (!msgs.length) return;
			openForwardPicker(msgs);
		});
	}
	if (fwdEl) {
		fwdEl.addEventListener('click', function (e) {
			if (e.target === fwdEl) closeForwardPicker();
		});
	}
	if (fwdSearchEl) {
		fwdSearchEl.addEventListener('input', function () {
			forwardSearchQuery = fwdSearchEl.value || '';
			if (!forwardSearchQuery.trim()) {
				forwardRemoteChats = [];
				setForwardSearchLoading(false);
			}
			renderForwardList();
			clearTimeout(forwardSearchTimer);
			const q = forwardSearchQuery.trim();
			if (q.length < 2) {
				setForwardSearchLoading(false);
				renderForwardList();
				return;
			}
			forwardSearchTimer = setTimeout(function () {
				setForwardSearchLoading(true);
				renderForwardList();
				const qDigits = normalizePhoneDigits(q);
				const job = qDigits.length >= 10
					? searchChatsByPhoneRemote(q)
					: searchChatsByNameRemote(q);
				job.then(async function (remote) {
					if (!fwdEl || !fwdEl.classList.contains('open')) {
						setForwardSearchLoading(false);
						return;
					}
					if (fwdSearchEl.value.trim() !== q) {
						setForwardSearchLoading(false);
						return;
					}
					if (remote && remote.length) {
						(remote || []).forEach(function (item) { item._fromSearch = true; });
						try { await enrichChatDisplayNames(remote); } catch (e) {}
						forwardRemoteChats = mergeChatLists(forwardRemoteChats || [], remote || []);
						chatsCache = mergeChatLists(chatsCache, remote);
					}
					setForwardSearchLoading(false);
					renderForwardList();
				}).catch(function () {
					setForwardSearchLoading(false);
					renderForwardList();
				});
			}, 180);
		});
	}
	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape' && fwdEl && fwdEl.classList.contains('open')) {
			e.preventDefault();
			closeForwardPicker();
		}
	});

	async function refreshTail() {
		if (!currentDialogId) return;
		const data = await rest('im.dialog.messages.get', {
			DIALOG_ID: currentDialogId,
			FIRST_ID: lastMessageId || 0,
			LIMIT: 20
		});
		const messages = data.messages || [];
		mergeUsers(data.users);
		mergeFiles(data.files);
		hydrateFilesFromMessages(messages);
		await ensureUsersLoaded(collectMessageAuthorIds(messages));
		await prefetchFileUrls(collectFileIdsFromMessages(messages));
		await appendMessages(messages, false);
	}

	let recentEditSyncBusy = false;
	async function refreshRecentMessagesForEdits() {
		if (!currentDialogId || recentEditSyncBusy) return;
		recentEditSyncBusy = true;
		const dialogId = currentDialogId;
		try {
			const data = await rest('im.dialog.messages.get', {
				DIALOG_ID: dialogId,
				LIMIT: 20
			});
			if (dialogId !== currentDialogId) return;
			const messages = data.messages || [];
			mergeUsers(data.users);
			mergeFiles(data.files);
			hydrateFilesFromMessages(messages);
			await ensureUsersLoaded(collectMessageAuthorIds(messages));
			await prefetchFileUrls(collectFileIdsFromMessages(messages));
			await appendMessages(messages, false, { checkUpdates: true });
		} finally {
			recentEditSyncBusy = false;
		}
	}

	function updateSendButton() {
		const hasText = !!(inputEl.value || '').trim();
		const hasPendingFiles = pendingUploadFiles.length > 0;
		if (hasText || hasPendingFiles) {
			sendBtn.classList.remove('mic');
			sendBtn.title = hasPendingFiles ? 'Отправить вложения' : 'Отправить';
			icoMic.style.display = 'none';
			icoSend.style.display = 'block';
		} else {
			sendBtn.classList.add('mic');
			sendBtn.title = 'Голосовое сообщение';
			icoMic.style.display = 'block';
			icoSend.style.display = 'none';
		}
	}

	async function sendMessage() {
		const text = (inputEl.value || '').trim();
		if (!text || !currentDialogId || sending) return;
		sending = true;
		sendBtn.disabled = true;
		try {
			await ensureCanSend();
			let quotedOk = false;
			let quotedRes = null;
			const heldReply = replyTo && replyTo.id ? {
				id: replyTo.id,
				author: replyTo.author || '',
				text: replyTo.text || '',
				fileIds: replyTo.fileIds || [],
				mediaKind: replyTo.mediaKind || ''
			} : null;
			if (heldReply) {
				try {
					quotedRes = await sendQuotedViaGreen({ text: text });
					quotedOk = !!(quotedRes && quotedRes.ok);
					if (quotedOk && quotedRes.reply && quotedRes.reply.text) {
						heldReply.text = quotedRes.reply.text;
					}
				} catch (e) {
					console.warn('wa_quote_send failed, fallback IM', e);
				}
			}
			if (!quotedOk) {
				const payload = { DIALOG_ID: currentDialogId, MESSAGE: text };
				if (heldReply) payload.REPLY_ID = heldReply.id;
				await rest('im.message.add', payload);
			}
			inputEl.value = '';
			clearReplyTo();
			inputEl.style.height = 'auto';
			updateSendButton();
			markLocalOutgoingPending();
			await refreshTail();
			if (quotedOk && heldReply) {
				applyPendingQuote(heldReply, quotedRes && quotedRes.imMessageId);
			}
			if (quotedOk) {
				setTimeout(function () {
					refreshTail().then(function () {
						if (heldReply) applyPendingQuote(heldReply, quotedRes && quotedRes.imMessageId);
					}).catch(function () {});
				}, 900);
			}
			loadChatList();
			refreshReadReceipts().catch(function () {});
		} catch (e) {
			console.error(e);
			alert('Ошибка отправки: ' + (e.ex ? e.ex.error_description : e));
		} finally {
			sending = false;
			sendBtn.disabled = false;
			inputEl.focus();
		}
	}

	function fileToBase64(file) {
		return new Promise((resolve, reject) => {
			const reader = new FileReader();
			reader.onload = () => {
				const res = String(reader.result || '');
				resolve(res.includes(',') ? res.split(',')[1] : res);
			};
			reader.onerror = reject;
			reader.readAsDataURL(file);
		});
	}

	function buildUniqueFileName(name) {
		name = String(name || '').trim() || 'file';
		const dot = name.lastIndexOf('.');
		const base = dot > 0 ? name.slice(0, dot) : name;
		const ext = dot > 0 ? name.slice(dot) : '';
		const stamp = new Date().toISOString().replace(/[^\d]/g, '').slice(0, 14);
		const rnd = Math.random().toString(36).slice(2, 7);
		return base + '_' + stamp + '_' + rnd + ext;
	}

	function ensureUploadFileName(file) {
		if (!file) return file;
		const original = String(file.name || '');
		if (!original) return file;
		try {
			return new File([file], buildUniqueFileName(original), {
				type: file.type || 'application/octet-stream',
				lastModified: file.lastModified || Date.now()
			});
		} catch (e) {
			return file;
		}
	}

	function getUploadDialogId() {
		if (currentDialogId && /^chat\d+/i.test(currentDialogId)) return currentDialogId;
		if (currentChatId) return 'chat' + currentChatId;
		return currentDialogId;
	}

	async function uploadViaDiskCommit(file, caption, notifyClient, targetChat, silentConnector) {
		const chatId = parseInt(
			(targetChat && (targetChat.chat_id || (targetChat.chat && targetChat.chat.id))) || currentChatId,
			10
		);
		if (!chatId) throw new Error('CHAT_ID не определён');

		let folderId = null;
		try {
			const folder = await rest('im.disk.folder.get', { CHAT_ID: chatId });
			folderId = folder.ID || folder.id;
		} catch (e) {
			const dialogId = targetChat ? resolveDialogId(targetChat) : getUploadDialogId();
			const folder = await rest('im.disk.folder.get', { DIALOG_ID: dialogId });
			folderId = folder.ID || folder.id;
		}
		if (!folderId) throw new Error('Не удалось получить папку чата');

		const content = await fileToBase64(file);
		const uploaded = await rest('disk.folder.uploadfile', {
			id: folderId,
			data: { NAME: file.name },
			fileContent: [file.name, content]
		});

		const fileId = uploaded.ID || uploaded.id || (uploaded.result && (uploaded.result.ID || uploaded.result.id));
		if (!fileId) throw new Error('Файл не загружен на диск');

		const commitParams = {
			CHAT_ID: chatId,
			FILE_ID: [fileId],
			MESSAGE: caption || ''
		};
		// Внутри Bitrix: SILENT_CONNECTOR=Y → НЕ слать в коннектор (WhatsApp).
		// REST SILENT_MODE=Y мапится туда же — поэтому для доставки клиенту параметр НЕ передаём.
		if (notifyClient === false) {
			commitParams.SILENT_MODE = 'N';
		}
		// Файл уже ушёл в WhatsApp через Green API — в чат кладём копию молча.
		if (silentConnector) {
			commitParams.SILENT_MODE = 'Y';
		}

		return {
			fileId: parseFileId(fileId),
			result: await rest('im.disk.file.commit', commitParams)
		};
	}

	async function echoFilesLocally(files, caption, replyId) {
		const arr = Array.from(files || []);
		for (let i = 0; i < arr.length; i++) {
			const file = arr[i];
			if (!file) continue;
			try {
				const committed = await uploadViaDiskCommit(
					ensureUploadFileName(file),
					(i === 0 && caption) ? caption : '',
					true,
					null,
					true
				);
				if (replyId && committed && committed.fileId) {
					await linkCommittedFileReply(committed.fileId, replyId);
				}
			} catch (e) {
				console.warn('local file echo failed', e);
			}
		}
	}

	async function uploadFileToChat(file, caption) {
		return await uploadViaDiskCommit(ensureUploadFileName(file), caption || '', true);
	}

	async function uploadVoiceViaV2(file) {
		const dialogId = getUploadDialogId();
		if (!dialogId) throw new Error('DIALOG_ID не определён');
		file = ensureUploadFileName(file);
		const content = await fileToBase64(file);
		return await rest('im.v2.File.upload', {
			dialogId: dialogId,
			fields: { name: file.name, content: content, message: '' }
		});
	}

	async function uploadVoiceToClient(file) {
		try {
			return await uploadVoiceViaV2(file);
		} catch (e1) {
			console.warn('im.v2.File.upload failed, fallback im.disk.file.commit', e1);
			return await uploadViaDiskCommit(file, '', true);
		}
	}

	async function uploadVoice(file) {
		if (!currentDialogId || !file || sending) return;
		if (file.size < 800) {
			alert('Слишком короткая запись');
			return;
		}

		sending = true;
		sendBtn.disabled = true;
		attachBtn.disabled = true;
		uploadHint.style.display = 'block';
		uploadHint.textContent = 'Подготовка голосового...';

		try {
			await ensureCanSend();
			const waFile = await prepareVoiceFileForWhatsApp(file, file.type, function (msg) {
				uploadHint.textContent = msg;
			});
			uploadHint.textContent = 'Отправка голосового (' + waFile.name + ')...';
			let quotedOk = false;
			if (replyTo && replyTo.id) {
				try {
					const q = await sendQuotedViaGreen({ files: [waFile], ptt: true });
					quotedOk = !!(q && q.ok);
				} catch (e) {
					console.warn('wa_quote_send voice failed, fallback IM', e);
				}
			}
			if (!quotedOk) {
				await uploadVoiceToClient(waFile);
			} else {
				await echoFilesLocally([waFile], '', replyTo && replyTo.id);
				clearReplyTo();
			}
			markLocalOutgoingPending();
			await loadMessages(currentDialogId);
			if (quotedOk) {
				setTimeout(function () { refreshTail().catch(function () {}); }, 900);
				setTimeout(function () { refreshTail().catch(function () {}); }, 2200);
			}
			loadChatList();
			refreshReadReceipts().catch(function () {});
		} catch (e) {
			console.error(e);
			alert('Ошибка отправки голосового: ' + (e.ex ? e.ex.error_description : (e.error_description || e.message || e)));
		} finally {
			sending = false;
			sendBtn.disabled = false;
			attachBtn.disabled = false;
			uploadHint.style.display = 'none';
			inputEl.focus();
		}
	}

	async function uploadFiles(fileList) {
		if (!currentDialogId || !fileList || !fileList.length || sending) return;
		sending = true;
		sendBtn.disabled = true;
		attachBtn.disabled = true;
		uploadHint.style.display = 'block';
		const caption = (inputEl.value || '').trim();
		try {
			await ensureCanSend();
			const fileArr = Array.from(fileList);
			let quotedOk = false;
			if (replyTo && replyTo.id) {
				try {
					const q = await sendQuotedViaGreen({ text: caption, files: fileArr });
					quotedOk = !!(q && q.ok);
				} catch (e) {
					console.warn('wa_quote_send files failed, fallback IM', e);
				}
			}
			if (!quotedOk) {
				for (let i = 0; i < fileArr.length; i++) {
					const file = fileArr[i];
					uploadHint.textContent = 'Загрузка: ' + file.name + ' (' + (i + 1) + '/' + fileArr.length + ')...';
					await uploadFileToChat(file, (i === 0 && caption) ? caption : '');
				}
			} else {
				await echoFilesLocally(fileArr, caption, replyTo && replyTo.id);
				clearReplyTo();
			}
			inputEl.value = '';
			inputEl.style.height = 'auto';
			updateSendButton();
			markLocalOutgoingPending();
			await loadMessages(currentDialogId);
			if (quotedOk) {
				setTimeout(function () { refreshTail().catch(function () {}); }, 900);
				setTimeout(function () { refreshTail().catch(function () {}); }, 2200);
			}
			loadChatList();
			refreshReadReceipts().catch(function () {});
		} catch (e) {
			console.error(e);
			alert('Ошибка загрузки файла: ' + (e.ex ? e.ex.error_description : (e.error_description || e.message || e)));
		} finally {
			sending = false;
			sendBtn.disabled = false;
			attachBtn.disabled = false;
			uploadHint.style.display = 'none';
			fileInput.value = '';
			inputEl.focus();
		}
	}

	function revokePendingUploadUrls() {
		pendingUploadFiles.forEach(function (item) {
			if (item && item.previewUrl) {
				try { URL.revokeObjectURL(item.previewUrl); } catch (e) { /* ignore */ }
			}
		});
	}

	function clearPendingUploads() {
		revokePendingUploadUrls();
		pendingUploadFiles = [];
		if (attachPreviewEl) {
			attachPreviewEl.innerHTML = '';
			attachPreviewEl.classList.remove('visible');
		}
		if (fileInput) {
			fileInput.value = '';
		}
		updateSendButton();
	}

	function renderPendingUploads() {
		if (!attachPreviewEl) return;
		attachPreviewEl.innerHTML = '';
		if (!pendingUploadFiles.length) {
			attachPreviewEl.classList.remove('visible');
			updateSendButton();
			return;
		}

		pendingUploadFiles.forEach(function (item, index) {
			const card = document.createElement('div');
			card.className = 'wa-attach-card';

			const removeBtn = document.createElement('button');
			removeBtn.type = 'button';
			removeBtn.className = 'wa-attach-remove';
			removeBtn.textContent = '×';
			removeBtn.addEventListener('click', function () {
				if (item.previewUrl) {
					try { URL.revokeObjectURL(item.previewUrl); } catch (e) { /* ignore */ }
				}
				pendingUploadFiles.splice(index, 1);
				renderPendingUploads();
			});

			const thumb = document.createElement('div');
			thumb.className = 'wa-attach-thumb';
			if (item.file && /^image\//i.test(item.file.type) && item.previewUrl) {
				const img = document.createElement('img');
				img.src = item.previewUrl;
				img.alt = item.file.name || 'image';
				thumb.appendChild(img);
			} else {
				thumb.textContent = item.file && item.file.type ? item.file.type.split('/')[0].toUpperCase() : 'FILE';
			}

			const name = document.createElement('div');
			name.className = 'wa-attach-name';
			name.textContent = item.file && item.file.name ? item.file.name : 'Файл';

			card.appendChild(removeBtn);
			card.appendChild(thumb);
			card.appendChild(name);
			attachPreviewEl.appendChild(card);
		});

		attachPreviewEl.classList.add('visible');
		updateSendButton();
	}

	function stageFilesForUpload(fileList) {
		if (!currentDialogId || !fileList || !fileList.length || sending) return;
		Array.from(fileList).forEach(function (file) {
			if (!file || file.size <= 0) return;
			pendingUploadFiles.push({
				file: file,
				previewUrl: /^image\//i.test(file.type || '') ? URL.createObjectURL(file) : ''
			});
		});
		renderPendingUploads();
	}

	async function sendPendingUploads() {
		if (!pendingUploadFiles.length || sending) return;
		const files = pendingUploadFiles.map(function (item) { return item.file; });
		clearPendingUploads();
		await uploadFiles(files);
	}

	function formatRecTime(ms) {
		const s = Math.floor(ms / 1000);
		const m = Math.floor(s / 60);
		const sec = s % 60;
		return m + ':' + String(sec).padStart(2, '0');
	}

	function pickMime() {
		const types = [
			'audio/webm;codecs=opus',
			'audio/webm',
			'audio/ogg;codecs=opus',
			'audio/ogg',
			'audio/mp4'
		];
		for (const t of types) {
			if (window.MediaRecorder && MediaRecorder.isTypeSupported(t)) return t;
		}
		return '';
	}

	function loadScriptOnce(src, key) {
		if (window[key]) return window[key];
		window[key] = new Promise((resolve, reject) => {
			const s = document.createElement('script');
			s.src = src;
			s.onload = resolve;
			s.onerror = () => reject(new Error('Script load failed: ' + src));
			document.head.appendChild(s);
		});
		return window[key];
	}

	function waFfmpegBase() {
		const path = window.location.pathname.replace(/[^/]+$/, '');
		return window.location.origin + path + 'wa-ffmpeg/';
	}

	function waFfmpegAsset(name) {
		return window.location.origin + '/local/custom_chat/ajax_ffmpeg.php?wa_ffmpeg=' + encodeURIComponent(name);
	}

	let ffmpegInstance = null;
	async function ensureFfmpeg() {
		if (ffmpegInstance) return ffmpegInstance;
		const localBase = waFfmpegBase();
		await loadScriptOnce(localBase + 'ffmpeg.js', '__waFfmpegLib');
		await loadScriptOnce('https://cdn.jsdelivr.net/npm/@ffmpeg/util@0.12.1/dist/umd/index.js', '__waFfmpegUtil');
		const { FFmpeg } = FFmpegWASM;
		const ffmpeg = new FFmpeg();
		await ffmpeg.load({
			coreURL: waFfmpegAsset('core-js'),
			wasmURL: waFfmpegAsset('core-wasm')
		});
		ffmpegInstance = ffmpeg;
		return ffmpeg;
	}

	function isOggOpusVoice(blob, mime) {
		const type = (mime || (blob && blob.type) || '').toLowerCase();
		return type.indexOf('ogg') !== -1 || type.indexOf('opus') !== -1;
	}

	function guessAudioExt(blob, mime) {
		const type = (mime || blob.type || '').toLowerCase();
		if (type.indexOf('ogg') !== -1) return 'ogg';
		if (type.indexOf('mp4') !== -1 || type.indexOf('m4a') !== -1) return 'm4a';
		if (type.indexOf('mpeg') !== -1 || type.indexOf('mp3') !== -1) return 'mp3';
		if (type.indexOf('wav') !== -1) return 'wav';
		return 'webm';
	}

	async function convertToWhatsAppOgg(blob, mime, onProgress) {
		if (onProgress) onProgress('Загрузка конвертера...');
		const ffmpeg = await ensureFfmpeg();
		const { fetchFile } = FFmpegUtil;
		const inName = 'in.' + guessAudioExt(blob, mime);
		const outName = 'out.ogg';

		if (onProgress) onProgress('Конвертация OGG/Opus 16kHz...');
		await ffmpeg.writeFile(inName, await fetchFile(blob));
		const code = await ffmpeg.exec([
			'-i', inName,
			'-vn',
			'-c:a', 'libopus',
			'-ar', '16000',
			'-ac', '1',
			'-b:a', '16k',
			'-application', 'voip',
			'-map_metadata', '-1',
			'-y', outName
		]);
		if (code !== 0) {
			await ffmpeg.deleteFile(inName).catch(function () {});
			throw new Error('FFmpeg exit code ' + code);
		}

		const data = await ffmpeg.readFile(outName);
		await ffmpeg.deleteFile(inName).catch(function () {});
		await ffmpeg.deleteFile(outName).catch(function () {});

		if (!data || !data.length || data.length < 200) {
			throw new Error('Пустой OGG после конвертации');
		}

		const uid = Date.now() + '_' + Math.random().toString(36).slice(2, 8);
		return new File([new Uint8Array(data)], 'voice_' + uid + '.ogg', { type: 'audio/ogg' });
	}

	async function prepareVoiceFileForWhatsApp(blob, mime, onProgress) {
		if (isOggOpusVoice(blob, mime)) {
			const uid = Date.now() + '_' + Math.random().toString(36).slice(2, 8);
			return new File([blob], 'voice_' + uid + '.ogg', { type: 'audio/ogg' });
		}
		try {
			return await convertToWhatsAppOgg(blob, mime, onProgress);
		} catch (e) {
			console.warn('FFmpeg conversion failed, sending original audio', e);
			if (onProgress) onProgress('Конвертер недоступен, отправка как есть...');
			const ext = guessAudioExt(blob, mime);
			const uid = Date.now() + '_' + Math.random().toString(36).slice(2, 8);
			const type = mime || blob.type || 'audio/webm';
			return new File([blob], 'voice_' + uid + '.' + ext, { type: type });
		}
	}

	async function createAudioRecorder(stream) {
		const mime = pickMime();
		return mime
			? new MediaRecorder(stream, { mimeType: mime })
			: new MediaRecorder(stream);
	}

	async function startRecording() {
		if (!currentDialogId || recording || sending) return;
		if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
			alert('Браузер не поддерживает запись микрофона (нужен HTTPS)');
			return;
		}
		try {
			mediaStream = await navigator.mediaDevices.getUserMedia({ audio: true });
			audioChunks = [];
			mediaRecorder = await createAudioRecorder(mediaStream);

			mediaRecorder.ondataavailable = e => {
				if (e.data && e.data.size > 0) audioChunks.push(e.data);
			};
			mediaRecorder.start(250);
			recording = true;
			recStartedAt = Date.now();
			recTimerEl.textContent = '0:00';
			recTimerId = setInterval(() => {
				recTimerEl.textContent = formatRecTime(Date.now() - recStartedAt);
			}, 250);

			inputBar.style.display = 'none';
			inputBar.classList.remove('visible');
			recBar.classList.add('active');
		} catch (e) {
			console.error(e);
			alert('Нет доступа к микрофону');
			stopTracks();
		}
	}

	function stopTracks() {
		if (mediaStream) {
			mediaStream.getTracks().forEach(t => t.stop());
			mediaStream = null;
		}
	}

	function hideRecUi() {
		recBar.classList.remove('active');
		if (currentDialogId) {
			inputBar.classList.add('visible');
			inputBar.style.display = '';
		}
		if (recTimerId) {
			clearInterval(recTimerId);
			recTimerId = null;
		}
		recording = false;
		mediaRecorder = null;
		audioChunks = [];
		stopTracks();
	}

	function cancelRecording() {
		return new Promise(resolve => {
			if (!mediaRecorder || mediaRecorder.state === 'inactive') {
				hideRecUi();
				resolve();
				return;
			}
			mediaRecorder.onstop = () => {
				hideRecUi();
				resolve();
			};
			try { mediaRecorder.stop(); } catch (e) { hideRecUi(); resolve(); }
		});
	}

	function finishRecording(send) {
		return new Promise(resolve => {
			if (!mediaRecorder) {
				hideRecUi();
				resolve(null);
				return;
			}
			const mime = mediaRecorder.mimeType || 'audio/webm';
			mediaRecorder.onstop = async () => {
				const blob = new Blob(audioChunks, { type: mime });
				stopTracks();
				if (recTimerId) clearInterval(recTimerId);
				recording = false;
				mediaRecorder = null;
				audioChunks = [];
				recBar.classList.remove('active');
				if (currentDialogId) {
					inputBar.classList.add('visible');
					inputBar.style.display = '';
				}

				if (send && blob.size > 0 && currentDialogId) {
					const file = new File([blob], 'voice_rec.webm', { type: mime });
					await uploadVoice(file);
				}
				resolve(blob);
			};
			try { mediaRecorder.stop(); } catch (e) { hideRecUi(); resolve(null); }
		});
	}

	sendBtn.addEventListener('click', () => {
		if (pendingUploadFiles.length) {
			sendPendingUploads().catch(function (e) {
				console.error(e);
			});
		} else if ((inputEl.value || '').trim()) {
			sendMessage();
		} else {
			startRecording();
		}
	});
	recCancel.addEventListener('click', () => cancelRecording());
	recSend.addEventListener('click', () => finishRecording(true));

	attachBtn.addEventListener('click', () => fileInput.click());
	fileInput.addEventListener('change', () => {
		if (fileInput.files && fileInput.files.length) stageFilesForUpload(Array.from(fileInput.files));
	});

	inputEl.addEventListener('keydown', e => {
		if (e.key === 'Enter' && !e.shiftKey) {
			e.preventDefault();
			sendMessage();
		}
	});
	inputEl.addEventListener('input', function () {
		this.style.height = 'auto';
		this.style.height = Math.min(this.scrollHeight, 120) + 'px';
		updateSendButton();
	});

	searchEl.addEventListener('input', function () {
		searchQuery = searchEl.value || '';
		crmNewChatOffer = null;
		completedPhoneSearchKey = '';
		waAllowedLineIds = null;
		if (searchQuery.trim()) {
			chatsCache.forEach(function (chat) {
				chat._phones = getChatPhones(chat);
				delete chat._clientPhone;
			});
		} else {
			searchRecentLoaded = false;
		}
		renderChatList();

		clearTimeout(searchDebounceId);
		searchDebounceId = setTimeout(function () {
			runRemoteSearchIfNeeded();
		}, 450);
	});

	chatSearchToggle.addEventListener('click', function () {
		if (!currentDialogId) return;
		const opening = !chatSearchPanel.classList.contains('visible');
		chatSearchPanel.classList.toggle('visible', opening);
		chatSearchToggle.classList.toggle('active', opening);
		if (opening) {
			chatSearchField.focus();
			chatSearchField.select();
		} else {
			closeChatMessageSearch();
		}
	});
	chatSearchClose.addEventListener('click', closeChatMessageSearch);
	chatSearchField.addEventListener('input', function () {
		clearTimeout(chatSearchTimer);
		chatSearchTimer = setTimeout(runChatMessageSearch, 400);
	});
	chatSearchField.addEventListener('keydown', function (e) {
		if (e.key === 'Enter') {
			e.preventDefault();
			runChatMessageSearch();
		} else if (e.key === 'Escape') {
			closeChatMessageSearch();
		}
	});
	chatSearchPrev.addEventListener('click', function () {
		focusChatSearchResult(chatSearchIndex - 1);
	});
	chatSearchNext.addEventListener('click', function () {
		focusChatSearchResult(chatSearchIndex + 1);
	});

	tabsEl.addEventListener('click', e => {
		const btn = e.target.closest('.wa-tab');
		if (!btn || !btn.dataset.filter) return;
		listFilter = btn.dataset.filter;
		renderChatList();
	});

	messagesEl.addEventListener('scroll', function () {
		if (openingScrollLock || historyLoading) return;
		if (messagesEl.scrollTop < 100) loadOlderMessages();
	});
	if (typeof ResizeObserver !== 'undefined') {
		try {
			const ro = new ResizeObserver(function () {
				if (openingScrollLock) applyOpenScroll();
				else if (openScrollMode === 'bottom' && shouldStickToBottom()) scrollMessagesToBottom();
			});
			ro.observe(messagesEl);
		} catch (e) { /* ignore */ }
	}

	messagesEl.addEventListener('dragover', e => e.preventDefault());
	messagesEl.addEventListener('drop', e => {
		e.preventDefault();
		if (!currentDialogId) return;
		const files = Array.from(e.dataTransfer.files || []);
		if (files.length) stageFilesForUpload(files);
	});

	function getClipboardFiles(evt) {
		const dt = evt && evt.clipboardData;
		if (!dt) return [];
		const files = Array.from(dt.files || []);
		if (files.length) {
			return files.filter(function (file) {
				return file && file.size > 0;
			});
		}

		const out = [];
		Array.from(dt.items || []).forEach(function (item) {
			if (!item || item.kind !== 'file') return;
			const file = item.getAsFile ? item.getAsFile() : null;
			if (file && file.size > 0) out.push(file);
		});
		return out;
	}

	function handlePasteFiles(e) {
		if (!currentDialogId || sending) return;
		const files = getClipboardFiles(e);
		if (!files.length) return;
		e.preventDefault();
		stageFilesForUpload(files);
	}

	inputEl.addEventListener('paste', handlePasteFiles);
	messagesEl.addEventListener('paste', handlePasteFiles);

	if (BX.PULL) {
		const pullType = (BX.PullClient && BX.PullClient.SubscriptionType)
			? BX.PullClient.SubscriptionType.Server : null;
		const pullCommands = ['messageChat', 'message', 'messageAdd', 'messageUpdate', 'messageChatAdd'];
		const pullReadCommands = ['readMessage', 'readMessageChat', 'readMessageOpponent', 'readMessageOpponentChat'];

		pullCommands.forEach(function (command) {
			const sub = {
				moduleId: 'im',
				command: command,
				callback: function (params) { handlePullMessage(params, command); }
			};
			if (pullType) sub.type = pullType;
			BX.PULL.subscribe(sub);
		});

		pullReadCommands.forEach(function (command) {
			const sub = { moduleId: 'im', command: command, callback: handlePullRead };
			if (pullType) sub.type = pullType;
			BX.PULL.subscribe(sub);
		});

		const subAll = {
			moduleId: 'im',
			callback: function (data) {
				const cmd = data.command;
				const params = data.params || {};
				if (pullCommands.indexOf(cmd) !== -1) handlePullMessage(params);
				if (pullReadCommands.indexOf(cmd) !== -1) handlePullRead(params);
				if (cmd === 'recentChange' || cmd === 'chatUpdate') {
					scheduleLoadChatList();
					if (currentChatId) refreshSessionState();
				}
			}
		};
		if (pullType) subAll.type = pullType;
		BX.PULL.subscribe(subAll);

		// события открытых линий иногда идут отдельным модулем
		BX.PULL.subscribe({
			moduleId: 'imopenlines',
			callback: function (data) {
				const cmd = data.command || '';
				if (cmd.indexOf('message') !== -1 || cmd === 'sessionStart' || cmd === 'sessionFinish') {
					if (currentDialogId) refreshTail().catch(function () {});
					scheduleLoadChatList();
					if (currentChatId) refreshSessionState();
				}
			}
		});
	}

	let chatPollTimer = null;
	let ticksPollTimer = null;
	let editPollTick = 0;
	function startChatPolling() {
		if (chatPollTimer) clearInterval(chatPollTimer);
		if (ticksPollTimer) clearInterval(ticksPollTimer);
		chatPollTimer = setInterval(function () {
			if (document.hidden) return;
			if (currentDialogId && !sending && !recording) {
				refreshTail().catch(function () {});
				editPollTick++;
				if (editPollTick >= 6) {
					editPollTick = 0;
					refreshRecentMessagesForEdits().catch(function () {});
				}
			}
		}, 10000);
		ticksPollTimer = setInterval(function () {
			if (document.hidden) return;
			if (currentDialogId && !sending && !recording) {
				refreshReadReceipts().catch(function () {});
			}
		}, 8000);
	}

	updateSendButton();

	(async function ensureCurrentUserName() {
		try {
			if (CURRENT_USER_NAME && !/^User #\d+$/i.test(String(CURRENT_USER_NAME).trim())) {
				return;
			}
			const u = await rest('user.current', {});
			if (!u || typeof u !== 'object') return;
			const name = [u.NAME, u.LAST_NAME].filter(Boolean).join(' ').trim()
				|| String(u.LOGIN || u.EMAIL || '').trim();
			if (name) CURRENT_USER_NAME = name;
		} catch (e) { /* ignore */ }
	})().finally(function () {
		loadChatList();
		setInterval(function () {
			if (!document.hidden) loadChatList();
		}, 60000);
	});

	async function fetchCrmPhonesForEntity(entityType, entityId) {
		const type = String(entityType || '').toLowerCase();
		const id = parseInt(entityId, 10);
		const phones = new Set();
		if (!type || !id) return { phones: [], contactId: 0, dealIds: [] };

		const pushPhone = function (v) {
			if (v == null || v === '') return;
			if (Array.isArray(v)) {
				v.forEach(pushPhone);
				return;
			}
			if (typeof v === 'object') {
				pushPhone(v.VALUE || v.value);
				return;
			}
			const d = buildWaPhoneDigits(v);
			if (d.length >= 10) phones.add(d);
		};
		const pushFromFm = function (fm) {
			if (!fm || typeof fm !== 'object') return;
			Object.keys(fm).forEach(function (key) {
				pushPhone(fm[key]);
			});
		};
		const pushFromEntity = function (ent) {
			if (!ent || typeof ent !== 'object') return;
			pushPhone(ent.PHONE);
			pushPhone(ent.PHONE_MOBILE);
			pushPhone(ent.PHONE_WORK);
			pushFromFm(ent.FM);
		};

		let contactId = 0;
		const dealIds = [];

		if (type === 'deal') {
			dealIds.push(id);
			const contactIds = new Set();
			try {
				const deal = await rest('crm.deal.get', { id: id });
				const d = (deal && deal.result) || deal || {};
				contactId = parseInt(d.CONTACT_ID, 10) || 0;
				if (contactId) contactIds.add(contactId);
				pushFromEntity(d);
				const companyId = parseInt(d.COMPANY_ID, 10) || 0;
				if (companyId) {
					try {
						const comp = await rest('crm.company.get', { id: companyId });
						pushFromEntity((comp && comp.result) || comp || {});
					} catch (e) {}
				}
			} catch (e) {}
			try {
				const items = await rest('crm.deal.contact.items.get', { id: id });
				const list = Array.isArray(items) ? items : (items && items.result) || [];
				(list || []).forEach(function (row) {
					const cid = parseInt(row.CONTACT_ID || row.contactId || row.ID, 10) || 0;
					if (cid) {
						contactIds.add(cid);
						if (!contactId) contactId = cid;
					}
				});
			} catch (e) {}
			const cids = Array.from(contactIds);
			for (let ci = 0; ci < cids.length && ci < 8; ci++) {
				try {
					const cGet = await rest('crm.contact.get', { id: cids[ci] });
					pushFromEntity((cGet && cGet.result) || cGet || {});
				} catch (e) {}
			}
		} else if (type === 'lead') {
			try {
				const leadGet = await rest('crm.lead.get', { id: id });
				const l = (leadGet && leadGet.result) || leadGet || {};
				pushFromEntity(l);
				contactId = parseInt(l.CONTACT_ID, 10) || 0;
			} catch (e) {}
		} else if (type === 'contact') {
			contactId = id;
		}

		if (contactId) {
			try {
				const cGet = await rest('crm.contact.get', { id: contactId });
				pushFromEntity((cGet && cGet.result) || cGet || {});
			} catch (e) {}
			// другие сделки того же контакта — чат часто висит на старой
			if (type === 'deal') {
				try {
					const deals = await rest('crm.deal.list', {
						filter: { CONTACT_ID: contactId },
						select: ['ID'],
						order: { ID: 'DESC' },
						start: 0
					});
					const rows = Array.isArray(deals) ? deals : (deals && deals.result) || [];
					(rows || []).forEach(function (row) {
						const did = parseInt(row.ID || row.id, 10);
						if (did) dealIds.push(did);
					});
				} catch (e) {}
			}
		}

		return {
			phones: Array.from(phones),
			contactId: contactId,
			dealIds: Array.from(new Set(dealIds)).slice(0, 15)
		};
	}

	function getAllOlMetasFromCache() {
		const out = [];
		const seen = new Set();
		(chatsCache || []).forEach(function (chat) {
			const eid = (chat.chat && chat.chat.entity_id) || chat.entity_id || '';
			const parts = String(eid).split('|');
			if (parts.length < 2 || !parts[0] || !parts[1]) return;
			const key = parts[0] + '|' + parts[1];
			if (seen.has(key)) return;
			seen.add(key);
			out.push({ connector: parts[0], lineId: parts[1] });
		});
		return out;
	}

	async function findChatsByPhonesAnyLine(phoneList, chatIds, options) {
		const phones = (phoneList || []).map(buildWaPhoneDigits).filter(function (p) {
			return p.length >= 10;
		});
		if (!phones.length) return;
		options = options || {};

		// 1) CRM duplicate → чаты контакта/лида/компании (в т.ч. другие сделки)
		if (!options.skipCrm) {
			for (let i = 0; i < phones.length && i < 4; i++) {
				try {
					const dup = await rest('crm.duplicate.findbycomm', {
						type: 'PHONE',
						values: buildPhoneLookupValues(phones[i])
					});
					const dupData = dup.result || dup || {};
					const types = ['CONTACT', 'LEAD', 'COMPANY', 'DEAL'];
					for (let t = 0; t < types.length; t++) {
						const ids = dupData[types[t]] || [];
						for (let j = 0; j < ids.length && j < 8; j++) {
							if (!options.skipRestrictedCrmMethods) {
								await collectChatIdsForCrmEntity(types[t], ids[j], chatIds);
							}
							if (String(types[t]).toLowerCase() === 'deal' || types[t] === 'DEAL') {
								await fetchChatIdsFromCrmActivities('deal', ids[j], chatIds);
							}
							if (types[t] === 'LEAD' || types[t] === 'lead') {
								await fetchChatIdsFromCrmActivities('lead', ids[j], chatIds);
							}
						}
					}
				} catch (e) {
					console.warn('deal deeplink duplicate', e);
				}
			}
		}

		// 2) USER_CODE по всем линиям из кеша
		let metas = getAllOlMetasFromCache();
		if (!metas.length) {
			const one = getOlMetaFromCache();
			if (one) metas = [one];
		}
		for (let m = 0; m < metas.length; m++) {
			for (let p = 0; p < phones.length && p < 3; p++) {
				const userCode = buildUserCodeFromOlMeta(metas[m], phones[p]);
				if (!userCode || failedUserCodeLookups.has(userCode)) continue;
				const cid = await resolveChatIdByUserCode(userCode);
				if (cid) chatIds.add(cid);
			}
		}

		// 3) уже загруженный список слева — только entity_id телефон
		(chatsCache || []).forEach(function (chat) {
			const cps = getChatWaPhones(chat);
			const hit = phones.some(function (ph) {
				const tail = buildWaPhoneDigits(ph).slice(-10);
				return tail.length >= 10 && cps.indexOf(tail) !== -1;
			});
			if (!hit) return;
			const cid = parseInt(chat.chat_id || (chat.chat && chat.chat.id), 10);
			if (cid) chatIds.add(cid);
		});
	}

	function getChatWaPhones(item) {
		const out = new Set();
		const add = function (v) {
			const d = buildWaPhoneDigits(v);
			if (d.length >= 10) out.add(d.slice(-10));
		};
		const eid = String((item && item.chat && item.chat.entity_id) || (item && item.entity_id) || '');
		if (eid) {
			eid.split('|').forEach(function (part) {
				part = String(part || '').trim();
				if (!part) return;
				const wa = part.match(/(\d{10,15})@c\.us/i);
				if (wa) add(wa[1]);
				add(part.replace(/@.+$/i, ''));
			});
		}
		['entity_data_1', 'entity_data_2', 'entity_data_3'].forEach(function (key) {
			const val = item && item.chat && item.chat[key];
			if (!val) return;
			extractPhoneDigitsFromText(val).forEach(add);
		});
		return Array.from(out);
	}

	function phoneTails(phones) {
		return (phones || []).map(buildWaPhoneDigits).filter(function (p) {
			return p.length >= 10;
		}).map(function (p) {
			return p.slice(-10);
		});
	}

	function chatItemMatchesPhones(item, phones) {
		const want = phoneTails(phones);
		if (!want.length) return true;
		const have = getChatWaPhones(item);
		if (!have.length) return false;
		return want.some(function (w) {
			return have.indexOf(w) !== -1;
		});
	}

	function scoreDialogForCrm(dialog, phoneDigits, fromPrimary, ctxEntity) {
		if (!dialog) return -1e9;
		const hay = [
			dialog.entity_id,
			dialog.entity_data_1,
			dialog.entity_data_2,
			dialog.entity_data_3,
			dialog.dialog_id
		].filter(Boolean).join('|').toLowerCase();
		if (/@g\.us\b/i.test(hay)) return -1e9;

		let score = 0;
		if (fromPrimary) score += 40;

		if (ctxEntity && ctxEntity.id > 0) {
			const bindings = parseCrmBindings([
				dialog.entity_data_2,
				dialog.entity_data_1,
				dialog.entity_data_3
			].filter(Boolean).join('|'));
			if (ctxEntity.type === 'lead') {
				if (bindings.leadId === ctxEntity.id) score += 500;
				else if (bindings.leadId > 0) score -= 1000;
			}
			if (ctxEntity.type === 'deal') {
				if (bindings.dealId === ctxEntity.id) score += 500;
				else if (bindings.dealId > 0) score -= 1000;
			}
		}

		const hayDigits = normalizePhoneDigits(hay);
		if (phoneDigits && phoneDigits.length >= 10) {
			const variants = [phoneDigits];
			if (phoneDigits.length === 11 && (phoneDigits[0] === '7' || phoneDigits[0] === '8')) {
				variants.push(phoneDigits.slice(1));
			}
			if (variants.some(function (v) { return v.length >= 10 && hayDigits.indexOf(v) !== -1; })) {
				score += 120;
			}
		}

		const lineStatus = parseInt(
			(dialog.lines && (dialog.lines.status || dialog.lines.STATUS)) || dialog.status,
			10
		);
		if (CLOSED_LINE_STATUSES.indexOf(lineStatus) === -1) score += 60;
		else score -= 30;

		if (/@c\.us|@s\.whatsapp\.net|whatsapp|green|wazzup/i.test(hay)) score += 25;
		if (!dialog.entity_id && !dialog.entity_data_1) score -= 40;
		return score;
	}

	function parseCrmEntityId(v) {
		if (v == null || v === '') return 0;
		const s = String(v).trim();
		// Bitrix UI часто копируют как ID331574 / LEAD_331574
		const m = s.match(/(\d{1,12})/);
		return m ? (parseInt(m[1], 10) || 0) : 0;
	}

	async function fetchChatIdsFromCrmActivities(entityType, entityId, chatIds) {
		const type = String(entityType || '').toLowerCase();
		const id = parseInt(entityId, 10);
		if (!id) return;
		const ownerTypeId = type === 'deal' ? 2 : (type === 'lead' ? 1 : 0);
		if (!ownerTypeId) return;
		try {
			const rows = await rest('crm.activity.list', {
				filter: {
					OWNER_TYPE_ID: ownerTypeId,
					OWNER_ID: id
				},
				select: ['ID', 'PROVIDER_ID', 'PROVIDER_TYPE_ID', 'ASSOCIATED_ENTITY_ID', 'SUBJECT', 'SETTINGS'],
				order: { ID: 'DESC' },
				start: 0
			});
			const list = Array.isArray(rows) ? rows : (rows && rows.result) || [];
			for (let i = 0; i < list.length; i++) {
				const a = list[i];
				const prov = String(a.PROVIDER_ID || a.provider_id || '').toUpperCase();
				const subj = String(a.SUBJECT || a.subject || '');
				if (prov.indexOf('IMOPENLINE') === -1 && !/чат открытой линии|whatsapp|green-api|open.?line/i.test(subj)) {
					continue;
				}
				let cid = parseInt(a.ASSOCIATED_ENTITY_ID || a.associated_entity_id, 10) || 0;
				if (!cid && a.SETTINGS) {
					try {
						const st = typeof a.SETTINGS === 'string' ? JSON.parse(a.SETTINGS) : a.SETTINGS;
						cid = parseInt(st && (st.CHAT_ID || st.chatId || st.DIALOG_ID), 10) || 0;
					} catch (e) {}
				}
				const fromSubj = subj.match(/chat(\d+)/i) || subj.match(/#(\d{3,})/);
				if (!cid && fromSubj) cid = parseInt(fromSubj[1], 10) || 0;
				if (!cid) continue;
				try {
					const resolved = await resolveOlRawId(cid);
					if (resolved && parseInt(resolved.chatId, 10)) {
						cid = parseInt(resolved.chatId, 10);
					}
				} catch (e) {}
				chatIds.add(cid);
			}
		} catch (e) {
			console.warn('crm.activity.list chats', e);
		}
	}

	/**
	 * Резолв OL-чата для сделки/лида (как виджет findPersonalChatForDeal).
	 * entityType: 'deal' | 'lead'
	 */
	async function resolveChatItemForCrmEntity(entityType, entityId) {
		const type = String(entityType || '').toLowerCase();
		const id = parseCrmEntityId(entityId);
		if (!id || (type !== 'deal' && type !== 'lead')) return null;

		const meta = await fetchCrmPhonesForEntity(type, id);
		const phones = meta.phones || [];
		const phoneDigits = phones[0] || '';
		const contactId = meta.contactId || 0;
		const relatedDealIds = meta.dealIds || (type === 'deal' ? [id] : []);
		const requirePhone = phones.length > 0;
		if (requirePhone) {
			console.info('WA CC: CRM deeplink phones', phones);
		}

		const ctxEntity = { type: type, id: id };
		const seen = new Set();
		const scored = [];

		const pushChatId = async function (cid, fromPrimary, entityBound) {
			cid = parseInt(cid, 10);
			if (!cid) return;
			try {
				const resolved = await resolveOlRawId(cid);
				const real = parseInt(resolved && resolved.chatId, 10);
				if (real > 0) cid = real;
			} catch (e) {}
			if (seen.has(cid)) return;
			seen.add(cid);
			try {
				const item = await chatItemFromDialogChatId(cid);
				if (!item) return;
				if (requirePhone && !chatItemMatchesPhones(item, phones)) return;

				const dialog = item.chat || item;
				let bestPhoneScore = scoreDialogForCrm(dialog, phoneDigits, fromPrimary, ctxEntity);
				if (entityBound) bestPhoneScore += 800;
				for (let pi = 1; pi < phones.length; pi++) {
					let s = scoreDialogForCrm(dialog, phones[pi], fromPrimary, ctxEntity);
					if (entityBound) s += 800;
					if (s > bestPhoneScore) bestPhoneScore = s;
				}
				scored.push({
					score: bestPhoneScore,
					item: item,
					fromPrimary: fromPrimary,
					entityBound: !!entityBound
				});
			} catch (e) {
				console.warn('crm deeplink dialog', cid, e);
			}
		};

		const finalizeBest = async function () {
			const best = pickBest();
			if (best) {
				try {
					chatsCache = mergeChatLists(chatsCache, scored.map(function (s) { return s.item; }));
					await enrichChatDisplayNames(scored.map(function (s) { return s.item; }));
					renderChatList();
				} catch (e) {}
			}
			return best;
		};

		const pickBest = function () {
			if (!scored.length) return null;
			scored.sort(function (a, b) { return b.score - a.score; });
			let pool = scored;
			if (requirePhone) {
				pool = scored.filter(function (s) {
					return chatItemMatchesPhones(s.item, phones);
				});
				if (!pool.length) return null;
			}
			const entityHits = pool.filter(function (s) { return s.entityBound || s.score >= 500; });
			if (entityHits.length) return entityHits[0].item;
			return pool[0].item;
		};

		// 1) Чаты, привязанные к этому лиду/сделке (не путать с другими лидами того же клиента)
		const primaryIds = new Set();
		await collectChatIdsForCrmEntity(type, id, primaryIds);
		await fetchChatIdsFromCrmActivities(type, id, primaryIds);
		try {
			const active = await rest('imopenlines.crm.chat.get', {
				CRM_ENTITY_TYPE: type,
				CRM_ENTITY: id,
				ACTIVE_ONLY: 'Y'
			});
			const list = Array.isArray(active) ? active : (active && active.result) || [];
			if (Array.isArray(list)) {
				list.forEach(function (c) {
					const cid = parseInt(c.CHAT_ID || c.chatId || c.id, 10);
					if (cid) primaryIds.add(cid);
				});
			}
		} catch (e) {
			console.warn('imopenlines.crm.chat.get primary', e);
		}
		const pids = Array.from(primaryIds);
		for (let i = 0; i < pids.length; i++) {
			await pushChatId(pids[i], true, true);
		}
		const primaryHit = pickBest();
		if (primaryHit) {
			try {
				chatsCache = mergeChatLists(chatsCache, scored.map(function (s) { return s.item; }));
				await enrichChatDisplayNames(scored.map(function (s) { return s.item; }));
				renderChatList();
			} catch (e) {}
			return primaryHit;
		}

		// 2) телефон → fallback (один клиент — несколько лидов/чатов)
		if (phones.length) {
			const phoneSet = new Set();
			await findChatsByPhonesAnyLine(phones, phoneSet);
			const phoneChatIds = Array.from(phoneSet);
			for (let i = 0; i < phoneChatIds.length; i++) {
				await pushChatId(phoneChatIds[i], false, false);
			}
			const phoneHit = pickBest();
			if (phoneHit) {
				return await finalizeBest();
			}
		}

		const batches = [
			{ entityType: type, entityId: id, activeOnly: true, fromPrimary: true },
			{ entityType: 'contact', entityId: contactId, activeOnly: true, fromPrimary: false },
			{ entityType: type, entityId: id, activeOnly: false, fromPrimary: true },
			{ entityType: 'contact', entityId: contactId, activeOnly: false, fromPrimary: false }
		];
		relatedDealIds.forEach(function (did) {
			did = parseInt(did, 10);
			if (!did || (type === 'deal' && did === id)) return;
			batches.push(
				{ entityType: 'deal', entityId: did, activeOnly: true, fromPrimary: false },
				{ entityType: 'deal', entityId: did, activeOnly: false, fromPrimary: false }
			);
		});

		for (let b = 0; b < batches.length; b++) {
			const batch = batches[b];
			if (!batch.entityId) continue;
			const chatIds = new Set();
			if (batch.activeOnly) {
				try {
					const chats = await rest('imopenlines.crm.chat.get', {
						CRM_ENTITY_TYPE: batch.entityType,
						CRM_ENTITY: batch.entityId,
						ACTIVE_ONLY: 'Y'
					});
					const list = Array.isArray(chats) ? chats : (chats && chats.result) || [];
					if (Array.isArray(list)) {
						list.forEach(function (c) {
							const cid = parseInt(c.CHAT_ID || c.chatId || c.id, 10);
							if (cid) chatIds.add(cid);
						});
					}
				} catch (e) {
					console.warn('imopenlines.crm.chat.get', batch, e);
				}
			} else {
				await collectChatIdsForCrmEntity(batch.entityType, batch.entityId, chatIds);
			}

			const ids = Array.from(chatIds);
			for (let i = 0; i < ids.length; i++) {
				await pushChatId(ids[i], batch.fromPrimary, batch.fromPrimary && batch.entityType === type && batch.entityId === id);
			}

			if (batch.activeOnly && batch.fromPrimary) {
				const good = scored.filter(function (s) {
					return s.score >= 120 || (!requirePhone && s.score >= 40);
				});
				if (good.length) break;
			}
		}

		// Таймлайн «Чат с клиентом» на этой и связанных сделках
		if (!scored.length) {
			const actIds = new Set();
			await fetchChatIdsFromCrmActivities(type, id, actIds);
			if (type === 'deal') {
				for (let i = 0; i < relatedDealIds.length && i < 10; i++) {
					if (relatedDealIds[i] === id) continue;
					await fetchChatIdsFromCrmActivities('deal', relatedDealIds[i], actIds);
				}
			}
			const aids = Array.from(actIds);
			for (let i = 0; i < aids.length; i++) {
				await pushChatId(aids[i], true, true);
			}
		}

		return await finalizeBest();
	}

	/* Deep-link: ?chatId= / ?dialogId= / ?dealId= / ?leadId= (приоритет chat → dialog → deal/lead) */
	(async function openFromQuery() {
		const params = (typeof window.waCcParams === 'function')
			? window.waCcParams()
			: new URLSearchParams(window.location.search);
		const chatIdParam = params.get('chatId');
		const dialogIdParam = params.get('dialogId');
		const dealIdRaw = params.get('dealId') || params.get('DEAL_ID');
		const leadIdRaw = params.get('leadId') || params.get('LEAD_ID');
		const dealIdParam = dealIdRaw ? String(parseCrmEntityId(dealIdRaw) || '') : '';
		const leadIdParam = leadIdRaw ? String(parseCrmEntityId(leadIdRaw) || '') : '';
		if (!chatIdParam && !dialogIdParam && !dealIdRaw && !leadIdRaw) return;
		if (leadIdParam) {
			crmContextLeadId = parseInt(leadIdParam, 10) || crmContextLeadId;
			crmPlacementLeadId = crmContextLeadId;
		}
		if (dealIdParam) {
			crmContextDealId = parseInt(dealIdParam, 10) || crmContextDealId;
			crmPlacementDealId = crmContextDealId;
		}
		if ((dealIdRaw || leadIdRaw) && !dealIdParam && !leadIdParam) {
			console.warn('WA CC: некорректный dealId/leadId', dealIdRaw || leadIdRaw);
			return;
		}

		const adoptTarget = async function (target, fromCrm) {
			if (!target) return false;
			if (fromCrm && (dealIdParam || leadIdParam)) {
				const meta = await fetchCrmPhonesForEntity(
					dealIdParam ? 'deal' : 'lead',
					dealIdParam || leadIdParam
				);
				if (meta.phones && meta.phones.length) {
					if (!chatItemMatchesPhones(target, meta.phones)) {
						console.warn('WA CC: телефон не совпал — чат не открываем', {
							want: meta.phones,
							have: getChatWaPhones(target),
							chatId: target.chat_id || (target.chat && target.chat.id)
						});
						return false;
					}
				}
			}
			chatsCache = mergeChatLists(chatsCache, [target]);
			await enrichChatDisplayNames([target]);
			if (fromCrm) {
				crmFocusDialogId = resolveDialogId(target);
				if (isWhatsAppGroupChat(target)) listFilter = 'groups';
				if (leadIdParam) {
					crmContextLeadId = parseInt(leadIdParam, 10) || crmContextLeadId;
					crmPlacementLeadId = crmContextLeadId;
				}
				if (dealIdParam) {
					crmContextDealId = parseInt(dealIdParam, 10) || crmContextDealId;
					crmPlacementDealId = crmContextDealId;
				}
			}
			renderChatList();
			await openDialog(target, { keepPlacement: true });
			return true;
		};

		const tryOpen = async function () {
			let target = null;

			if (chatIdParam) {
				const cid = parseCrmEntityId(chatIdParam) || parseInt(chatIdParam, 10);
				target = (chatsCache || []).find(function (c) {
					const id = c.chat_id || (c.chat && c.chat.id);
					return parseInt(id, 10) === cid;
				});
				if (!target && cid) {
					try {
						target = await chatItemFromDialogChatId(cid);
					} catch (e) {
						console.warn('deeplink dialog.get', e);
					}
				}
				if (await adoptTarget(target, !!(dealIdParam || leadIdParam))) return true;
			}

			if (dialogIdParam) {
				const want = String(dialogIdParam).toLowerCase();
				target = (chatsCache || []).find(function (c) {
					const id = resolveDialogId(c);
					return id && String(id).toLowerCase() === want;
				});
				if (!target) {
					const m = want.match(/^chat(\d+)$/i) || want.match(/(\d+)/);
					if (m) {
						try {
							target = await chatItemFromDialogChatId(parseInt(m[1], 10));
						} catch (e) {}
					}
				}
				if (await adoptTarget(target, !!(dealIdParam || leadIdParam))) return true;
			}

			if (dealIdParam || leadIdParam) {
				try {
					if (dealIdParam) {
						target = await resolveChatItemForCrmEntity('deal', dealIdParam);
					} else {
						target = await resolveChatItemForCrmEntity('lead', leadIdParam);
					}
				} catch (e) {
					console.warn('deeplink crm entity', e);
				}
				if (await adoptTarget(target, true)) return true;
			}

			return false;
		};

		for (let i = 0; i < 10; i++) {
			if (await tryOpen()) return;
			await new Promise(function (r) { setTimeout(r, 400); });
		}

		if (dealIdParam || leadIdParam) {
			const label = dealIdParam ? ('сделке #' + dealIdParam) : ('лиду #' + leadIdParam);
			console.warn('WA CC: чат не найден по ' + label);
			try {
				const entityType = dealIdParam ? 'deal' : 'lead';
				const entityId = parseInt(dealIdParam || leadIdParam, 10) || 0;
				const meta = await fetchCrmPhonesForEntity(entityType, entityId);
				const phone = meta && meta.phones && meta.phones[0];
				if (phone) {
					crmNewChatOffer = {
						phone: phone,
						source: 'crm',
						entityType: entityType,
						entityId: entityId
					};
					searchQuery = phone;
					searchEl.value = formatPhoneDisplay(phone);
					renderChatList();
					return;
				}
			} catch (e) {
				console.warn('WA CC: телефон CRM для нового чата', e);
			}
			if (typeof BX !== 'undefined' && BX.UI && BX.UI.Notification) {
				BX.UI.Notification.Center.notify({
					content: 'В ' + label + ' нет телефона для WhatsApp',
					autoHideDelay: 5000
				});
			}
		}
	})();
});
</script>

<?php
if (!empty($waEmbed)) {
	if (is_object($APPLICATION) && method_exists($APPLICATION, 'ShowBodyScripts')) {
		$APPLICATION->ShowBodyScripts();
	}
	echo '</body></html>';
	// epilog без prolog = fatal → белый экран в BitrixMobile
	if (empty($waNoProlog) && defined('B_PROLOG_INCLUDED')) {
		require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_after.php';
	}
	die();
}

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php';
?>
