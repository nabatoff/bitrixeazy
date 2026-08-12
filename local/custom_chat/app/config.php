<?php
/**
 * Конфиг локального приложения WhatsApp-чата.
 */
if (!defined('WA_CC_APP_LOADED')) {
	define('WA_CC_APP_LOADED', true);
}

function waCcAppBasePath()
{
	return '/local/custom_chat';
}

function waCcAppPublicBaseUrl()
{
	$host = '';
	if (!empty($_SERVER['HTTP_HOST'])) {
		$host = (string)$_SERVER['HTTP_HOST'];
	}
	$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
		|| (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
		|| (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
	$scheme = $https ? 'https' : 'http';
	if ($host === '') {
		return waCcAppBasePath();
	}
	return $scheme . '://' . $host . waCcAppBasePath();
}

function waCcAppChatEmbedUrl(array $query = [])
{
	$query['wa_embed'] = '1';
	if (!isset($query['wa_mobile']) && !isset($query['wa_desktop'])) {
		// по умолчанию не форсим mobile — решает caller
	}
	return waCcAppBasePath() . '/?' . http_build_query($query);
}

function waCcAppTitle()
{
	return 'WhatsApp чат';
}

/** @return array<int, array{placement:string,title:string}> */
function waCcAppPlacements()
{
	// ACTIVITY = зона таймлайна/дел в карточке (ближе к mobile, чем DETAIL_TAB)
	return [
		['placement' => 'CRM_DEAL_DETAIL_TAB', 'title' => 'WhatsApp'],
		['placement' => 'CRM_LEAD_DETAIL_TAB', 'title' => 'WhatsApp'],
		['placement' => 'CRM_DEAL_DETAIL_ACTIVITY', 'title' => 'WhatsApp чат'],
		['placement' => 'CRM_LEAD_DETAIL_ACTIVITY', 'title' => 'WhatsApp чат'],
		['placement' => 'CRM_DEAL_DETAIL_TOOLBAR', 'title' => 'WhatsApp чат'],
		['placement' => 'CRM_LEAD_DETAIL_TOOLBAR', 'title' => 'WhatsApp чат'],
		['placement' => 'CRM_DEAL_LIST_MENU', 'title' => 'WhatsApp чат'],
		['placement' => 'CRM_LEAD_LIST_MENU', 'title' => 'WhatsApp чат'],
	];
}
