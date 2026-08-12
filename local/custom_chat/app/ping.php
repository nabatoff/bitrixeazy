<?php
/**
 * Ping без prolog — можно временно поставить как handler приложения.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/shell.php';

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');

$auth = waCcAppResolveUserIdNoProlog();
$mobile = waCcAppIsMobileClient();
$ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');

echo '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8">'
	. '<meta name="viewport" content="width=device-width, initial-scale=1">'
	. '<title>WA ping</title></head><body style="margin:16px;font:14px/1.45 system-ui;background:#00a884;color:#fff">'
	. '<h1 style="font-size:18px;margin:0 0 12px">WhatsApp app ping (no prolog)</h1>'
	. '<pre style="background:#fff;color:#111;padding:12px;border-radius:8px;white-space:pre-wrap">'
	. htmlspecialchars(json_encode([
		'auth_ok' => !empty($auth['ok']),
		'userId' => (int)($auth['userId'] ?? 0),
		'auth_error' => (string)($auth['error'] ?? ''),
		'auth_debug' => (string)($auth['debug'] ?? ''),
		'mobile' => $mobile,
		'ua' => $ua,
		'REQUEST_keys' => array_keys($_REQUEST),
		'has_AUTH_ID' => !empty($_REQUEST['AUTH_ID']) || !empty($_REQUEST['auth']),
		'mode' => 'no_prolog',
	], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
	. '</pre>'
	. '<p>Зелёный фон = WebView жив, prolog не трогали.</p>'
	. '</body></html>';
