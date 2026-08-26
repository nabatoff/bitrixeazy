<?php
/**
 * Мобильный вход в КЦ БЕЗ prolog_before.
 * В WebView локального приложения BitrixMobile страница с prolog = белый экран.
 * Здесь только HMAC-токен / AUTH_ID → HTML КЦ → REST через wa_aid.
 *
 * Важно: для wa_media / wa_ticks / прочих API НЕ слать Content-Type: text/html —
 * иначе <audio> получает поток с неправильным MIME и в WebView «мёртвый».
 */
if (!defined('WA_CC_MOBILE_NOPROLOG')) {
	define('WA_CC_MOBILE_NOPROLOG', true);
}

$waIsApi = array_key_exists('wa_media', $_GET)
	|| array_key_exists('wa_ticks', $_GET)
	|| array_key_exists('wa_lines', $_GET)
	|| array_key_exists('wa_attach', $_GET)
	|| array_key_exists('wa_start', $_GET)
	|| array_key_exists('wa_bulk_zip', $_GET)
	|| array_key_exists('wa_quote_send', $_GET)
	|| array_key_exists('wa_quote_link', $_GET)
	|| array_key_exists('wa_msg_meta', $_GET)
	|| array_key_exists('wa_resolve_ol', $_GET)
	|| array_key_exists('wa_resolve_uc', $_GET)
	|| array_key_exists('wa_group_title', $_GET)
	|| array_key_exists('wa_ffmpeg', $_GET);

if (!$waIsApi && !headers_sent()) {
	header('Content-Type: text/html; charset=UTF-8');
	header('Cache-Control: no-store, no-cache, must-revalidate');
}

require_once __DIR__ . '/app/config.php';
require_once __DIR__ . '/app/shell.php';

$tok = (string)($_GET['wa_tok'] ?? $_REQUEST['wa_tok'] ?? '');
$aid = (string)($_GET['wa_aid'] ?? $_REQUEST['wa_aid'] ?? $_REQUEST['AUTH_ID'] ?? '');
$userId = $tok !== '' ? waCcAppConsumeToken($tok) : 0;

if ($userId <= 0 && $aid !== '') {
	$_REQUEST['AUTH_ID'] = $aid;
	$_REQUEST['auth'] = $aid;
	if (empty($_REQUEST['DOMAIN']) && !empty($_GET['DOMAIN'])) {
		$_REQUEST['DOMAIN'] = $_GET['DOMAIN'];
	}
	$resolved = waCcAppResolveUserIdNoProlog();
	if (!empty($resolved['ok'])) {
		$userId = (int)$resolved['userId'];
	}
}

if ($userId <= 0) {
	if ($waIsApi) {
		http_response_code(401);
		if (!headers_sent()) {
			header('Content-Type: text/plain; charset=utf-8');
		}
		echo 'Auth required';
		die();
	}
	http_response_code(200);
	echo '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8">'
		. '<meta name="viewport" content="width=device-width, initial-scale=1">'
		. '<title>WhatsApp</title></head>'
		. '<body style="margin:0;font:15px system-ui;padding:20px;background:#fff3cd">'
		. '<b>Auth required</b><br>Нет wa_tok / user. Открой снова из меню «Ватсап чат».'
		. '</body></html>';
	die();
}

$GLOBALS['WA_CC_FORCED_USER_ID'] = $userId;
$GLOBALS['WA_CC_AID'] = $aid;
$GLOBALS['WA_CC_MEDIA_AUTHED'] = true;

if (!empty($_GET['leadId']) || !empty($_GET['LEAD_ID'])) {
	$GLOBALS['WA_CC_CRM_LEAD_ID'] = (int)($_GET['leadId'] ?? $_GET['LEAD_ID'] ?? 0);
}
if (!empty($_GET['dealId']) || !empty($_GET['DEAL_ID'])) {
	$GLOBALS['WA_CC_CRM_DEAL_ID'] = (int)($_GET['dealId'] ?? $_GET['DEAL_ID'] ?? 0);
}

$_GET['wa_embed'] = '1';
$_GET['wa_mobile'] = '1';
$_GET['wa_noprolog'] = '1';
$_REQUEST['wa_embed'] = '1';
$_REQUEST['wa_mobile'] = '1';
$_REQUEST['wa_noprolog'] = '1';
if ($tok !== '') {
	$_GET['wa_tok'] = $tok;
	$_REQUEST['wa_tok'] = $tok;
}
if ($aid !== '') {
	$_GET['wa_aid'] = $aid;
	$_REQUEST['wa_aid'] = $aid;
}

if (!empty($_GET['wa_ffmpeg'])) {
	require __DIR__ . '/ajax_ffmpeg.php';
	exit;
}
if (array_key_exists('wa_ticks', $_GET)) {
	require __DIR__ . '/ajax_ticks.php';
	exit;
}
if (array_key_exists('wa_lines', $_GET)) {
	require __DIR__ . '/ajax_wa_lines.php';
	exit;
}
if (array_key_exists('wa_attach', $_GET)) {
	require __DIR__ . '/ajax_wa_attach.php';
	exit;
}
if (array_key_exists('wa_start', $_GET)) {
	require __DIR__ . '/ajax_wa_start.php';
	exit;
}

require __DIR__ . '/index.php';
