<?php
/**
 * Пункт «План продаж» в левом меню портала (только для SALETARGET).
 *
 * init.php:
 *   $afSpMenu = $_SERVER['DOCUMENT_ROOT'] . '/local/sales_plan/include_menu.php';
 *   if (is_file($afSpMenu)) { require_once $afSpMenu; }
 */
if (!defined('B_PROLOG_INCLUDED') && !defined('BX_ROOT')) {
	return;
}

if (!defined('AF_SP_MENU_REG')) {
	define('AF_SP_MENU_REG', true);
	try {
		$em = \Bitrix\Main\EventManager::getInstance();
		$em->addEventHandler('main', 'OnEpilog', 'afSpOnEpilogMenu');
	} catch (\Throwable $e) {
	}
}

function afSpMenuShouldSkip(): bool
{
	global $USER;
	if (!$USER || !is_object($USER) || !$USER->IsAuthorized()) {
		return true;
	}
	if (defined('ADMIN_SECTION') && ADMIN_SECTION) {
		return true;
	}
	if (!\Bitrix\Main\Loader::includeModule('artflowers.salesplan') || !\Bitrix\Main\Loader::includeModule('crm')) {
		return true;
	}
	try {
		$access = \Artflowers\Salesplan\Internal\AccessService::forCurrentUser();
		if (!$access->canReadSaleTarget() || $access->getVisibleBranches() === []) {
			return true;
		}
	} catch (\Throwable $e) {
		return true;
	}
	return false;
}

function afSpOnEpilogMenu(): void
{
	try {
		if (afSpMenuShouldSkip()) {
			return;
		}
		$url = '/local/sales_plan/';
		$js = "(function(){try{var u='{$url}';var done=false;function add(){if(done)return;var m=document.querySelector('.menu-items-body')||document.querySelector('#menu-items-block');if(!m)return;if(m.querySelector('[data-af-sp-menu]')){done=true;return;}var a=document.createElement('a');a.href=u;a.setAttribute('data-af-sp-menu','1');a.className='menu-item-block';a.innerHTML='<span class=\"menu-item-icon-box\"><span class=\"menu-item-icon\"></span></span><span class=\"menu-item-link-text\">План продаж</span>';m.prepend(a);done=true;}document.addEventListener('DOMContentLoaded',add);if(window.BX&&BX.ready)BX.ready(add);setTimeout(add,1200);}catch(e){}})();";
		if (class_exists('\Bitrix\Main\Page\Asset', true)) {
			\Bitrix\Main\Page\Asset::getInstance()->addString('<script>' . $js . '</script>', true);
		}
	} catch (\Throwable $e) {
	}
}
