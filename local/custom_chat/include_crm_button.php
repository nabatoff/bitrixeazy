<?php
/**
 * Кнопка «WhatsApp чат» в сделке/лиде → SidePanel.
 * URL: /local/custom_chat/slider.php?dealId|leadId= — обёртка с внутренним iframe
 * на полный КЦ (без IFRAME=Y), поэтому работает как прямая ссылка.
 *
 * В bitrix/php_interface/init.php в КОНЕЦ:
 *   $waCc = $_SERVER['DOCUMENT_ROOT'] . '/local/custom_chat/include_crm_button.php';
 *   if (is_file($waCc)) { require_once $waCc; }
 */
if (!defined('B_PROLOG_INCLUDED') && !defined('BX_ROOT') && empty($_SERVER['DOCUMENT_ROOT'])) {
	return;
}

try {
	if (!class_exists('\Bitrix\Main\EventManager', true)) {
		return;
	}

	$em = \Bitrix\Main\EventManager::getInstance();
	$em->addEventHandler('crm', 'onEntityDetailsToolbarInit', 'waCcOnEntityDetailsToolbarInit');
	$em->addEventHandler('main', 'OnEpilog', 'waCcOnEpilogInjectButton');
} catch (\Throwable $e) {
	if (defined('BX_EXCEPTION_HANDLING') || isset($_GET['wa_cc_debug'])) {
		AddMessage2Log('waCc include: ' . $e->getMessage(), 'wa_cc');
	}
}

/** SidePanel на десктопе; на mobile WebView — PageManager / location. */
function waCcBuildOpenJs($url)
{
	$urlJs = class_exists('CUtil', false) ? \CUtil::JSEscape($url) : addslashes($url);
	return ""
		. "(function(e){"
		. "try{if(e&&e.preventDefault)e.preventDefault();if(e&&e.stopPropagation)e.stopPropagation();}catch(err){}"
		. "var u='{$urlJs}';"
		. "try{"
		. "if(window.BXMobileApp&&BXMobileApp.PageManager&&BXMobileApp.PageManager.loadPageBlank){"
		. "BXMobileApp.PageManager.loadPageBlank({url:u,title:'WhatsApp чат',cache:false});return false;}"
		. "if(window.Application&&Application.openUrl){Application.openUrl(u);return false;}"
		. "}catch(err2){}"
		. "var sp=(window.top&&window.top.BX&&window.top.BX.SidePanel&&window.top.BX.SidePanel.Instance)"
		. "||(window.BX&&BX.SidePanel&&BX.SidePanel.Instance);"
		. "if(sp){sp.open(u,{width:1100,cacheable:false,allowChangeHistory:false,printable:false});return false;}"
		. "window.location.href=u;return false;"
		. "})(typeof event!=='undefined'?event:null);";
}

function waCcSliderUrl($query)
{
	return '/local/custom_chat/slider.php?' . $query;
}

/**
 * @param \Bitrix\Main\Event $event
 */
function waCcOnEntityDetailsToolbarInit($event)
{
	try {
		if (!is_object($event) || !method_exists($event, 'getParameter')) {
			return;
		}

		$entityTypeId = (int)$event->getParameter('entityTypeID');
		if ($entityTypeId <= 0) {
			$entityTypeId = (int)$event->getParameter('entityTypeId');
		}
		$entityId = (int)$event->getParameter('entityID');
		if ($entityId <= 0) {
			$entityId = (int)$event->getParameter('entityId');
		}
		if ($entityId <= 0) {
			return;
		}

		$dealType = 2;
		$leadType = 1;
		if (class_exists('CCrmOwnerType', false)) {
			$dealType = (int)\CCrmOwnerType::Deal;
			$leadType = (int)\CCrmOwnerType::Lead;
		}
		if ($entityTypeId !== $dealType && $entityTypeId !== $leadType) {
			return;
		}

		$toolbar = $event->getParameter('toolbar');
		if (!is_object($toolbar) || !method_exists($toolbar, 'addButton')) {
			return;
		}

		$query = ($entityTypeId === $dealType)
			? ('dealId=' . $entityId)
			: ('leadId=' . $entityId);
		$url = waCcSliderUrl($query);
		$js = waCcBuildOpenJs($url);

		$btn = array(
			'text' => 'WhatsApp чат',
			'title' => 'Открыть чат клиента в контакт-центре',
		);

		if (class_exists('\Bitrix\UI\Buttons\JsCode', true)) {
			$btn['onclick'] = new \Bitrix\UI\Buttons\JsCode($js);
		} else {
			$btn['onclick'] = $js;
		}
		if (class_exists('\Bitrix\UI\Buttons\Color', true)) {
			$btn['color'] = \Bitrix\UI\Buttons\Color::SUCCESS;
		}

		$toolbar->addButton($btn);
		$GLOBALS['WA_CC_TOOLBAR_BTN'] = true;
	} catch (\Throwable $e) {
		// fallback OnEpilog
	}
}

function waCcOnEpilogInjectButton()
{
	try {
		if (!empty($GLOBALS['WA_CC_TOOLBAR_BTN'])) {
			return;
		}
		if (empty($GLOBALS['APPLICATION']) || !is_object($GLOBALS['APPLICATION'])) {
			return;
		}

		$page = (string)$GLOBALS['APPLICATION']->GetCurPage(false);
		if (!preg_match('#/crm/(deal|lead)/details/(\d+)/?#i', $page, $m)) {
			return;
		}

		$param = (strtolower($m[1]) === 'deal') ? 'dealId' : 'leadId';
		$id = (int)$m[2];
		if ($id <= 0) {
			return;
		}

		$url = waCcSliderUrl($param . '=' . $id);
		$openJs = waCcBuildOpenJs($url);

		$js = '(function(){'
			. 'if(window.__waCcCrmBtn)return;window.__waCcCrmBtn=true;'
			. 'function openCc(){' . $openJs . '}'
			. 'function mount(){if(document.getElementById("wa-cc-crm-btn"))return true;'
			. 'var bar=document.querySelector(".ui-toolbar-after-title-buttons,.ui-toolbar-right-buttons,.pagetitle-container,.crm-entity-actions-container");'
			. 'if(!bar)return false;var b=document.createElement("button");'
			. 'b.id="wa-cc-crm-btn";b.type="button";b.textContent="WhatsApp чат";'
			. 'b.className="ui-btn ui-btn-success ui-btn-sm";b.style.marginLeft="8px";'
			. 'b.addEventListener("click",openCc);bar.appendChild(b);return true;}'
			. 'function boot(){if(mount())return;var n=0;var t=setInterval(function(){n++;if(mount()||n>40)clearInterval(t);},250);}'
			. 'if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",boot);else boot();'
			. '})();';

		if (class_exists('\Bitrix\Main\Page\Asset', true)) {
			\Bitrix\Main\Page\Asset::getInstance()->addString('<script>' . $js . '</script>');
		}
	} catch (\Throwable $e) {
		// ignore
	}
}
