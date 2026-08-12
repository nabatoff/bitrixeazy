<?php
/**
 * Handler локального приложения (меню Bitrix / BitrixMobile / REST_APP из таймлайна).
 * БЕЗ prolog_before — иначе белый экран в мобильном WebView.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/shell.php';
require_once __DIR__ . '/placement_query.php';

if (!headers_sent()) {
	header('Content-Type: text/html; charset=UTF-8');
	header('Cache-Control: no-store, no-cache, must-revalidate');
}

try {
	$wantPing = !empty($_REQUEST['wa_ping']) || !empty($_REQUEST['ping']);

	$auth = waCcAppResolveUserIdForApp();
	$mobile = waCcAppIsMobileClient();

	$placement = isset($_REQUEST['PLACEMENT']) ? (string)$_REQUEST['PLACEMENT'] : '';
	$optionsRaw = isset($_REQUEST['PLACEMENT_OPTIONS']) ? (string)$_REQUEST['PLACEMENT_OPTIONS'] : '{}';
	$options = json_decode($optionsRaw, true);
	if (!is_array($options)) {
		$options = [];
	}
	$query = waCcAppBuildQueryFromPlacement($options, $placement);

	if ($wantPing) {
		$payload = [
			'auth_ok' => !empty($auth['ok']),
			'userId' => (int)($auth['userId'] ?? 0),
			'auth_error' => (string)($auth['error'] ?? ''),
			'auth_debug' => (string)($auth['debug'] ?? ''),
			'mobile' => $mobile,
			'query' => $query,
			'placement' => $placement,
			'client_id' => waCcAppGetClientId(),
			'ua' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
			'REQUEST_keys' => array_keys($_REQUEST),
			'has_AUTH_ID' => !empty($_REQUEST['AUTH_ID']) || !empty($_REQUEST['auth']),
			'mode' => 'resolve_for_app',
		];
		echo '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<title>WA ping</title></head>'
			. '<body style="margin:0;padding:16px;font:14px/1.45 system-ui;background:#00a884;color:#fff">'
			. '<h1 style="font-size:20px;margin:0 0 12px">WA PING OK (no prolog)</h1>'
			. '<pre style="background:#fff;color:#111;padding:12px;border-radius:10px;white-space:pre-wrap">'
			. htmlspecialchars(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
			. '</pre></body></html>';
		die();
	}

	if (!$auth['ok']) {
		waCcAppRenderError('Нет AUTH_ID/user: ' . ($auth['error'] ?: 'auth_failed')
			. (!empty($auth['debug']) ? (' [' . $auth['debug'] . ']') : ''));
	}

	$note = ($placement !== '' ? $placement : 'menu') . ' / ' . ($mobile ? 'mobile-redirect' : 'desktop-iframe');
	waCcAppRenderShell($query, (int)$auth['userId'], $note, $mobile);
} catch (\Throwable $e) {
	waCcAppRenderError('Fatal: ' . $e->getMessage());
}
