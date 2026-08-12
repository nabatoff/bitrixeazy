<?php
/**
 * Диагностика мобильного WebView: auth + UA + mobile flag (без КЦ).
 * Открой как handler приложения или напрямую под сессией портала.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/shell.php';

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');

$auth = waCcAppAuthorizeFromRequest();
$mobile = waCcAppIsMobileClient();
$ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');

echo '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8">'
	. '<meta name="viewport" content="width=device-width, initial-scale=1">'
	. '<title>WA ping</title></head><body style="margin:16px;font:14px/1.45 system-ui;background:#e8f5e9">'
	. '<h1 style="font-size:18px">WhatsApp app ping</h1>'
	. '<pre style="background:#fff;padding:12px;border-radius:8px;white-space:pre-wrap">'
	. htmlspecialchars(json_encode([
		'auth_ok' => !empty($auth['ok']),
		'userId' => (int)($auth['userId'] ?? 0),
		'auth_error' => (string)($auth['error'] ?? ''),
		'auth_debug' => (string)($auth['debug'] ?? ''),
		'mobile' => $mobile,
		'ua' => $ua,
		'REQUEST_keys' => array_keys($_REQUEST),
		'has_AUTH_ID' => !empty($_REQUEST['AUTH_ID']) || !empty($_REQUEST['auth']),
		'shell' => is_file(__DIR__ . '/shell.php'),
		'chat_index' => is_file($_SERVER['DOCUMENT_ROOT'] . '/local/custom_chat/index.php'),
	], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
	. '</pre>'
	. '<p>Если видишь зелёный фон — WebView жив. Дальше открой меню «Ватсап чат».</p>'
	. '</body></html>';
