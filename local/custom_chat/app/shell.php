<?php
/**
 * Оболочка локального приложения.
 * Mobile: include КЦ в том же запросе (iframe в WebView = белый экран).
 * Desktop: iframe + wa_tok.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

function waCcAppIsMobileClient()
{
	$ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');

	if (!empty($_REQUEST['mobile']) || !empty($_REQUEST['MOBILE']) || (string)($_REQUEST['wa_mobile'] ?? '') === '1') {
		return true;
	}
	if ((string)($_REQUEST['wa_desktop'] ?? '') === '1') {
		return false;
	}

	// явный desktop browser
	if ($ua !== '' && preg_match('/Windows NT|Macintosh|X11;|CrOS/i', $ua)
		&& !preg_match('/Mobile|Android.+Mobile|iPhone|iPod|BitrixMobile|BXMobileApp/i', $ua)
	) {
		return false;
	}

	// телефон / Bitrix mobile / неизвестный WebView → mobile (без iframe)
	if ($ua !== '' && preg_match(
		'/BitrixMobile|BXMobileApp|Bitrix24\.Mobile|Mobile|Android|iPhone|iPod|iPad|webOS|Opera Mini/i',
		$ua
	)) {
		return true;
	}

	// нет UA / странный WebView приложения — безопаснее mobile include
	return true;
}

function waCcAppIssueToken($userId)
{
	$userId = (int)$userId;
	$exp = time() + 180;
	$payload = $userId . '.' . $exp;
	$sig = hash_hmac('sha256', $payload, waCcAppTokenSecret());
	return rtrim(strtr(base64_encode($payload . '.' . $sig), '+/', '-_'), '=');
}

function waCcAppTokenSecret()
{
	$doc = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
	$seed = $doc . '|wa_cc_app_v1';
	if (defined('BX_PERSONAL_ROOT')) {
		$seed .= '|' . BX_PERSONAL_ROOT;
	}
	return hash('sha256', $seed);
}

/**
 * @return int
 */
function waCcAppConsumeToken($token)
{
	$token = (string)$token;
	if ($token === '') {
		return 0;
	}
	$raw = base64_decode(strtr($token, '-_', '+/'), true);
	if ($raw === false || $raw === '') {
		return 0;
	}
	$parts = explode('.', $raw);
	if (count($parts) !== 3) {
		return 0;
	}
	$userId = (int)$parts[0];
	$exp = (int)$parts[1];
	$sig = (string)$parts[2];
	if ($userId <= 0 || $exp < time()) {
		return 0;
	}
	$payload = $userId . '.' . $exp;
	$expect = hash_hmac('sha256', $payload, waCcAppTokenSecret());
	if (!hash_equals($expect, $sig)) {
		return 0;
	}
	return $userId;
}

/**
 * @param array<string, scalar> $query
 * @param bool $mobile
 */
function waCcAppRenderShell(array $query, $userId, $note = '', $mobile = false)
{
	$userId = (int)$userId;
	$mobile = (bool)$mobile;

	if ($mobile) {
		waCcAppBootChatMobile($query);
		return;
	}

	$tok = waCcAppIssueToken($userId);
	$query['wa_embed'] = '1';
	$query['wa_tok'] = $tok;
	$query['wa_desktop'] = '1';
	unset($query['wa_mobile']);
	$src = waCcAppBasePath() . '/?' . http_build_query($query);
	$title = waCcAppTitle();

	if (!headers_sent()) {
		header('Content-Type: text/html; charset=UTF-8');
		header('Cache-Control: no-store');
		header_remove('X-Frame-Options');
	}

	$srcEsc = htmlspecialchars($src, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	$titleEsc = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

	echo '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8">'
		. '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">'
		. '<title>' . $titleEsc . '</title>'
		. '<style>'
		. 'html,body{margin:0;padding:0;height:100%;height:100dvh;background:#f0f2f5;overflow:hidden}'
		. 'iframe{display:block;width:100%;height:100%;height:100dvh;border:0;background:#fff}'
		. '</style></head><body>'
		. '<iframe src="' . $srcEsc . '" title="' . $titleEsc . '" '
		. 'allow="microphone; clipboard-read; clipboard-write"></iframe>'
		. '</body></html>';
	die();
}

/**
 * Mobile: без iframe — сразу КЦ в этом запросе.
 *
 * @param array<string, scalar> $query
 */
function waCcAppBootChatMobile(array $query = [])
{
	foreach ($query as $k => $v) {
		$_GET[$k] = (string)$v;
		$_REQUEST[$k] = (string)$v;
	}
	$_GET['wa_embed'] = '1';
	$_GET['wa_mobile'] = '1';
	$_REQUEST['wa_embed'] = '1';
	$_REQUEST['wa_mobile'] = '1';
	unset($_GET['wa_desktop'], $_REQUEST['wa_desktop']);

	if (!defined('WA_CC_APP_BOOT')) {
		define('WA_CC_APP_BOOT', true);
	}

	$chat = $_SERVER['DOCUMENT_ROOT'] . '/local/custom_chat/index.php';
	if (!is_file($chat)) {
		waCcAppRenderError('Не найден /local/custom_chat/index.php');
	}

	register_shutdown_function(static function () {
		$err = error_get_last();
		if (!$err || !is_array($err)) {
			return;
		}
		$type = (int)($err['type'] ?? 0);
		if (!in_array($type, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
			return;
		}
		if (!headers_sent()) {
			header('Content-Type: text/html; charset=UTF-8');
		}
		$msg = htmlspecialchars((string)($err['message'] ?? 'fatal'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$file = htmlspecialchars((string)($err['file'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$line = (int)($err['line'] ?? 0);
		echo '<pre style="margin:16px;padding:14px;background:#fff3cd;border:2px solid #c62828;'
			. 'border-radius:10px;white-space:pre-wrap;font:13px/1.4 system-ui">'
			. "WhatsApp chat FATAL\n{$msg}\n{$file}:{$line}</pre>";
	});

	try {
		require $chat;
	} catch (\Throwable $e) {
		waCcAppRenderError('Boot chat: ' . $e->getMessage());
	}
	die();
}
