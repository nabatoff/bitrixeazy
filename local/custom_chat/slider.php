<?php
/**
 * Обёртка для Bitrix SidePanel.
 * SidePanel вешает IFRAME=Y → КЦ ломается.
 * Грузим КЦ во внутреннем iframe БЕЗ IFRAME=Y + sandbox без allow-top-navigation,
 * иначе шаблон Битрикс делает frame-bust (top.location = чат) и «перекидывает» из сделки.
 */
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

global $USER;
if (!$USER || !$USER->IsAuthorized()) {
	LocalRedirect('/auth/?backurl=' . rawurlencode($_SERVER['REQUEST_URI'] ?? '/'));
	die();
}

$dealId = isset($_GET['dealId']) ? preg_replace('/\D+/', '', (string)$_GET['dealId']) : '';
$leadId = isset($_GET['leadId']) ? preg_replace('/\D+/', '', (string)$_GET['leadId']) : '';
$chatId = isset($_GET['chatId']) ? preg_replace('/\D+/', '', (string)$_GET['chatId']) : '';
$dialogId = isset($_GET['dialogId']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$_GET['dialogId']) : '';

$q = [];
if ($dealId !== '') {
	$q['dealId'] = $dealId;
}
if ($leadId !== '') {
	$q['leadId'] = $leadId;
}
if ($chatId !== '') {
	$q['chatId'] = $chatId;
}
if ($dialogId !== '') {
	$q['dialogId'] = $dialogId;
}
// флаг для КЦ: мы во вложенном iframe слайдера
$q['wa_embed'] = '1';

$ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
$isMobileApp = ($ua !== '' && preg_match('/BitrixMobile|BXMobileApp|Bitrix24\.Mobile/i', $ua));
$inner = '/local/custom_chat/';
if ($isMobileApp) {
	$q['wa_mobile'] = '1';
	$q['wa_noprolog'] = '1';
	$tokFile = $_SERVER['DOCUMENT_ROOT'] . '/local/custom_chat/app/shell.php';
	if (is_file($tokFile)) {
		require_once $tokFile;
		$q['wa_tok'] = waCcAppIssueToken((int)$USER->GetID());
	}
	$inner = '/local/custom_chat/mobile.php';
} else {
	$q['wa_desktop'] = '1';
}
if ($q) {
	$inner .= '?' . http_build_query($q);
}

header('Content-Type: text/html; charset=UTF-8');
header('X-Frame-Options: SAMEORIGIN');
?><!DOCTYPE html>
<html lang="ru">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>WhatsApp чат</title>
	<style>
		html, body { margin: 0; padding: 0; height: 100%; overflow: hidden; background: #f0f2f5; }
		iframe { display: block; width: 100%; height: 100%; border: 0; }
	</style>
</head>
<body>
	<iframe
		src="<?= htmlspecialchars($inner, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
		title="WhatsApp чат"
		allow="microphone; clipboard-read; clipboard-write"
		sandbox="allow-scripts allow-same-origin allow-forms allow-popups allow-popups-to-escape-sandbox allow-modals allow-downloads allow-presentation"
	></iframe>
</body>
</html>
<?php
die();
