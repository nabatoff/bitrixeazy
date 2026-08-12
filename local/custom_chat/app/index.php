<?php
/**
 * Главный URL локального приложения (меню Bitrix).
 * Auth ДО любого вывода (иначе session headers already sent).
 *
 * Диагностика: ?wa_ping=1  или  /app/ping.php
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/shell.php';

// Мгновенный маркер: если в WebView белый экран ДО этого — запрос не доходит до PHP
if (!headers_sent()) {
	header('Content-Type: text/html; charset=UTF-8');
	header('Cache-Control: no-store, no-cache, must-revalidate');
	header_remove('X-Frame-Options');
}

try {
	$wantPing = !empty($_REQUEST['wa_ping'])
		|| (isset($_SERVER['REQUEST_URI']) && stripos((string)$_SERVER['REQUEST_URI'], 'ping.php') !== false);

	$auth = waCcAppAuthorizeFromRequest();

	if ($wantPing || (!empty($_GET['ping']))) {
		$mobile = waCcAppIsMobileClient();
		$ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
		$payload = [
			'auth_ok' => !empty($auth['ok']),
			'userId' => (int)($auth['userId'] ?? 0),
			'auth_error' => (string)($auth['error'] ?? ''),
			'auth_debug' => (string)($auth['debug'] ?? ''),
			'mobile' => $mobile,
			'ua' => $ua,
			'REQUEST_keys' => array_keys($_REQUEST),
			'has_AUTH_ID' => !empty($_REQUEST['AUTH_ID']) || !empty($_REQUEST['auth']),
			'PLACEMENT' => $_REQUEST['PLACEMENT'] ?? null,
			'shell' => is_file(__DIR__ . '/shell.php'),
			'chat_index' => is_file($_SERVER['DOCUMENT_ROOT'] . '/local/custom_chat/index.php'),
		];
		echo '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<title>WA ping</title></head>'
			. '<body style="margin:0;padding:16px;font:14px/1.45 system-ui;background:#00a884;color:#fff">'
			. '<h1 style="font-size:20px;margin:0 0 12px">WA PING OK</h1>'
			. '<pre style="background:#fff;color:#111;padding:12px;border-radius:10px;white-space:pre-wrap;overflow:auto">'
			. htmlspecialchars(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
			. '</pre></body></html>';
		die();
	}

	if (!$auth['ok']) {
		waCcAppRenderError('Нет сессии: ' . ($auth['error'] ?: 'auth_failed')
			. (!empty($auth['debug']) ? (' [' . $auth['debug'] . ']') : ''));
	}

	// Видимый first-paint ДО include КЦ (если дальше fatal — останется эта полоса)
	$mobile = waCcAppIsMobileClient();
	$uid = (int)$auth['userId'];
	echo '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8">'
		. '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">'
		. '<title>WhatsApp чат</title>'
		. '<style>html,body{margin:0;background:#00a884}#wa-boot-bar{padding:10px 14px;color:#fff;font:13px/1.3 system-ui}'
		. '#wa-boot-bar b{display:block;font-size:15px}</style></head><body>'
		. '<div id="wa-boot-bar"><b>WhatsApp загружается…</b>user #'
		. $uid . ' · ' . ($mobile ? 'mobile' : 'desktop') . '</div>';
	if (function_exists('flush')) {
		@ob_flush();
		@flush();
	}

	// Mobile (и Bitrix WebView): без iframe
	// Desktop: shell с iframe
	waCcAppRenderShell([], $uid, $mobile ? 'mobile' : 'desktop', $mobile);
} catch (\Throwable $e) {
	waCcAppRenderError('Fatal: ' . $e->getMessage());
}
