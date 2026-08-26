<?php
/**
 * Левое меню (бейдж + иконка) и всплывающие уведомления WA-чата.
 * Подключается из include_crm_button.php.
 */
if (!defined('B_PROLOG_INCLUDED') && !defined('BX_ROOT')) {
	return;
}

if (!defined('WA_CC_PORTAL_WIDGET_REG')) {
	define('WA_CC_PORTAL_WIDGET_REG', true);
	try {
		$em = \Bitrix\Main\EventManager::getInstance();
		$em->addEventHandler('main', 'OnProlog', 'waCcOnPrologPortalWidget');
		$em->addEventHandler('main', 'OnEpilog', 'waCcOnEpilogPortalWidget');
		$em->addEventHandler('main', 'OnEndBufferContent', 'waCcOnEndBufferPortalWidget');
	} catch (\Throwable $e) {
		// ignore
	}
}

function waCcPortalWidgetShouldSkip()
{
	global $USER;
	if (!$USER || !is_object($USER) || !$USER->IsAuthorized()) {
		return true;
	}
	if (defined('ADMIN_SECTION') && ADMIN_SECTION) {
		return true;
	}

	$page = '';
	if (!empty($GLOBALS['APPLICATION']) && is_object($GLOBALS['APPLICATION'])) {
		try {
			$page = (string)$GLOBALS['APPLICATION']->GetCurPage(false);
		} catch (\Throwable $e) {
			$page = '';
		}
	}
	if ($page === '' && !empty($_SERVER['REQUEST_URI'])) {
		$page = (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
	}

	if (strpos($page, '/local/custom_chat/') === 0) {
		return true;
	}
	if (preg_match('#^/bitrix/(admin|tools|services/main/ajax|spread)#i', $page)) {
		return true;
	}
	$script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
	if (strpos($script, '/bitrix/services/main/ajax.php') !== false) {
		return true;
	}
	return false;
}

function waCcPortalWidgetUrls()
{
	$base = '/local/custom_chat';
	$doc = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
	$jsFile = $doc . $base . '/portal_widget.js';
	$cssFile = $doc . $base . '/portal_widget.css';
	$jsV = is_file($jsFile) ? (int)filemtime($jsFile) : time();
	$cssV = is_file($cssFile) ? (int)filemtime($cssFile) : time();
	$userId = 0;
	global $USER;
	if ($USER && is_object($USER) && method_exists($USER, 'GetID')) {
		$userId = (int)$USER->GetID();
	}
	return [
		'css' => $base . '/portal_widget.css?v=' . $cssV,
		'js' => $base . '/portal_widget.js?v=' . $jsV,
		'cfg' => [
			'unreadUrl' => $base . '/portal_unread.php',
			'slider' => $base . '/slider.php',
			'icon' => $base . '/img/wa-menu.svg',
			'userId' => $userId,
			'appId' => 64,
			'menuId' => '1897508225',
		],
	];
}

function waCcPortalWidgetHtml()
{
	$u = waCcPortalWidgetUrls();
	$cfgJson = json_encode($u['cfg'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	$css = htmlspecialchars($u['css'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	$js = htmlspecialchars($u['js'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	return '<link rel="stylesheet" href="' . $css . '">'
		. '<script>window.__WA_CC_PORTAL=' . $cfgJson . ';</script>'
		. '<script src="' . $js . '"></script>';
}

function waCcEnsureMenuCounterId()
{
	static $done = false;
	if ($done) {
		return;
	}
	$done = true;
	try {
		$sites = [];
		if (defined('SITE_ID') && SITE_ID) {
			$sites[] = SITE_ID;
		}
		$sites[] = 's1';
		$sites = array_values(array_unique($sites));
		foreach ($sites as $siteId) {
			$opt = 'left_menu_items_marketplace_' . $siteId;
			$raw = \COption::GetOptionString('intranet', $opt, '');
			if ($raw === '') {
				continue;
			}
			$arr = @unserialize($raw, ['allowed_classes' => false]);
			if (!is_array($arr)) {
				continue;
			}
			$changed = false;
			foreach ($arr as &$it) {
				if (!is_array($it)) {
					continue;
				}
				$link = (string)($it['LINK'] ?? '');
				$text = (string)($it['TEXT'] ?? '');
				$id = (string)($it['ID'] ?? '');
				$hit = ($link === '/marketplace/app/64/'
					|| $id === '1897508225'
					|| $text === 'Ватсап чат'
					|| strpos($link, '/marketplace/app/64') !== false);
				if (!$hit) {
					continue;
				}
				if ((string)($it['COUNTER_ID'] ?? '') !== 'wa_cc_unread') {
					$it['COUNTER_ID'] = 'wa_cc_unread';
					$changed = true;
				}
			}
			unset($it);
			if ($changed) {
				\COption::SetOptionString('intranet', $opt, serialize($arr));
			}
		}
	} catch (\Throwable $e) {
		// ignore
	}
}

function waCcOnPrologPortalWidget()
{
	try {
		if (waCcPortalWidgetShouldSkip()) {
			return;
		}
		waCcEnsureMenuCounterId();
		if (class_exists('\Bitrix\Main\UI\Extension', true)) {
			try {
				\Bitrix\Main\UI\Extension::load(['pull.client']);
			} catch (\Throwable $e) {
				// ignore
			}
		}
		if (function_exists('CJSCore')) {
			try {
				\CJSCore::Init(['pull']);
			} catch (\Throwable $e) {
				// ignore
			}
		}
		$u = waCcPortalWidgetUrls();
		if (class_exists('\Bitrix\Main\Page\Asset', true)) {
			$asset = \Bitrix\Main\Page\Asset::getInstance();
			$asset->addCss($u['css']);
			$asset->addJs($u['js']);
			$cfgJson = json_encode($u['cfg'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			$asset->addString('<script>window.__WA_CC_PORTAL=' . $cfgJson . ';</script>', true);
		}
	} catch (\Throwable $e) {
		// ignore
	}
}

function waCcOnEpilogPortalWidget()
{
	try {
		if (waCcPortalWidgetShouldSkip()) {
			return;
		}
		$html = waCcPortalWidgetHtml();
		if (class_exists('\Bitrix\Main\Page\Asset', true)) {
			\Bitrix\Main\Page\Asset::getInstance()->addString($html, true);
		} elseif (!empty($GLOBALS['APPLICATION']) && is_object($GLOBALS['APPLICATION']) && method_exists($GLOBALS['APPLICATION'], 'AddHeadString')) {
			$GLOBALS['APPLICATION']->AddHeadString($html, true);
		}
	} catch (\Throwable $e) {
		// ignore
	}
}

function waCcOnEndBufferPortalWidget(&$content)
{
	try {
		if (!is_string($content) || strlen($content) < 80) {
			return;
		}
		if (stripos($content, '</body>') === false) {
			return;
		}
		if (strpos($content, 'portal_widget.js') !== false) {
			return;
		}
		if (waCcPortalWidgetShouldSkip()) {
			return;
		}
		$html = waCcPortalWidgetHtml();
		$content = preg_replace('#</body>#i', $html . '</body>', $content, 1);
	} catch (\Throwable $e) {
		// ignore
	}
}
