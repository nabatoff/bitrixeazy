<?php
/**
 * Placement handler — без prolog (BitrixMobile).
 * Также ловит открытие REST_APP-activity из таймлайна.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/shell.php';
require_once __DIR__ . '/placement_query.php';

if (!headers_sent()) {
	header('Content-Type: text/html; charset=UTF-8');
	header('Cache-Control: no-store');
}

try {
	$placement = isset($_REQUEST['PLACEMENT']) ? (string)$_REQUEST['PLACEMENT'] : '';
	$optionsRaw = isset($_REQUEST['PLACEMENT_OPTIONS']) ? (string)$_REQUEST['PLACEMENT_OPTIONS'] : '{}';
	$options = json_decode($optionsRaw, true);
	if (!is_array($options)) {
		$options = [];
	}

	$query = waCcAppBuildQueryFromPlacement($options, $placement);

	$auth = waCcAppResolveUserIdForApp();
	if (!$auth['ok']) {
		waCcAppRenderError('Нет AUTH_ID/user: ' . ($auth['error'] ?: 'auth_failed')
			. (!empty($auth['debug']) ? (' [' . $auth['debug'] . ']') : ''));
	}

	$mobile = waCcAppIsMobileClient();
	$note = ($placement !== '' ? $placement : 'DEFAULT') . ' / ' . ($mobile ? 'mobile-redirect' : 'desktop-iframe');
	waCcAppRenderShell($query, (int)$auth['userId'], $note, $mobile);
} catch (\Throwable $e) {
	waCcAppRenderError('Fatal: ' . $e->getMessage());
}
