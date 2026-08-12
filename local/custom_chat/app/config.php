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
	} elseif (!empty($_SERVER['SERVER_NAME'])) {
		$host = (string)$_SERVER['SERVER_NAME'];
	}
	// BitrixMobile WebView часто белеет на https://host:443/...
	$host = preg_replace('/:(443|80)$/', '', $host);

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

/**
 * CLIENT_ID локального приложения (для REST_APP activity).
 * Пишется при install.php; иначе ищем по URL handler.
 */
function waCcAppClientIdPath()
{
	return __DIR__ . '/client_id.local.php';
}

function waCcAppSaveClientId($clientId)
{
	$clientId = trim((string)$clientId);
	if ($clientId === '') {
		return false;
	}
	$file = waCcAppClientIdPath();
	$code = "<?php\nreturn " . var_export($clientId, true) . ";\n";
	return (bool)@file_put_contents($file, $code);
}

function waCcAppGetClientId()
{
	$file = waCcAppClientIdPath();
	if (is_file($file)) {
		$v = include $file;
		if (is_string($v) && $v !== '') {
			return $v;
		}
	}

	try {
		if (class_exists('\Bitrix\Main\Loader') && \Bitrix\Main\Loader::includeModule('rest')
			&& class_exists('\Bitrix\Rest\AppTable')
		) {
			$needle = 'custom_chat/app';
			$res = \Bitrix\Rest\AppTable::getList([
				'filter' => ['=ACTIVE' => 'Y'],
				'select' => ['ID', 'CLIENT_ID', 'URL', 'URL_INSTALL', 'CODE'],
			]);
			while ($row = $res->fetch()) {
				$blob = strtolower(implode(' ', [
					(string)($row['URL'] ?? ''),
					(string)($row['URL_INSTALL'] ?? ''),
					(string)($row['CODE'] ?? ''),
				]));
				if (strpos($blob, $needle) !== false || strpos($blob, 'custom_chat') !== false) {
					$cid = (string)($row['CLIENT_ID'] ?? '');
					if ($cid !== '') {
						waCcAppSaveClientId($cid);
						return $cid;
					}
				}
			}
		}
	} catch (\Throwable $e) {
		/* ignore */
	}

	return '';
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
