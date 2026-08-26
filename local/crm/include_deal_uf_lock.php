<?php
/**
 * Блокировка UF сделки для всех, кроме админов портала.
 * BP/роботы могут писать (серверный хук пропускает automation-контекст).
 *
 * В bitrix/php_interface/init.php:
 *   $waDealUfLock = $_SERVER['DOCUMENT_ROOT'] . '/local/crm/include_deal_uf_lock.php';
 *   if (is_file($waDealUfLock)) { require_once $waDealUfLock; }
 */

use Bitrix\Main\Page\Asset;

if (!defined('B_PROLOG_INCLUDED') && php_sapi_name() !== 'cli') {
	/* init.php обычно уже после prolog — ок */
}

if (!function_exists('waDealUfLock_fields')) {
	function waDealUfLock_fields(): array
	{
		return [
			'UF_CRM_1782797106378', // Тип продажи — только админы
		];
	}
}
if (!function_exists('waDealUfLock_isPortalAdmin')) {
	function waDealUfLock_isPortalAdmin(): bool
	{
		global $USER;
		if (!$USER || !is_object($USER) || !$USER->IsAuthorized()) {
			return false;
		}
		if ($USER->IsAdmin()) {
			return true;
		}
		/* Bitrix24: группа админов портала */
		try {
			if (method_exists($USER, 'CanDoOperation') && $USER->CanDoOperation('edit_other_settings')) {
				return true;
			}
		} catch (\Throwable $e) {
			// ignore
		}
		return false;
	}
}

if (!function_exists('waDealUfLock_isAutomationContext')) {
	function waDealUfLock_isAutomationContext(): bool
	{
		$bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 25);
		foreach ($bt as $frame) {
			$class = (string)($frame['class'] ?? '');
			$func = (string)($frame['function'] ?? '');
			$hay = $class . '::' . $func;
			if (
				stripos($hay, 'Automation') !== false
				|| stripos($hay, 'Bizproc') !== false
				|| stripos($hay, 'CBP') !== false
				|| stripos($hay, 'Robot') !== false
				|| stripos($hay, 'Timeline\\Comment') !== false
			) {
				return true;
			}
		}
		if (defined('BX_CRONTAB') || defined('BX_CHECK_AGENT_START')) {
			return true;
		}
		return false;
	}
}

if (!function_exists('waDealUfLock_stripFields')) {
	/**
	 * Убрать заблокированные UF из апдейта (оставить старые значения).
	 * @param array $fields
	 * @return array
	 */
	function waDealUfLock_stripFields(array &$fields): bool
	{
		$changed = false;
		$allow = [];
		if (!empty($GLOBALS['WA_DEAL_AUTO_TAKE_ALLOW']) && is_array($GLOBALS['WA_DEAL_AUTO_TAKE_ALLOW'])) {
			foreach ($GLOBALS['WA_DEAL_AUTO_TAKE_ALLOW'] as $n) {
				$allow[(string)$n] = 1;
			}
		}
		foreach (waDealUfLock_fields() as $name) {
			if (isset($allow[$name])) {
				continue;
			}
			if (array_key_exists($name, $fields)) {
				unset($fields[$name]);
				$changed = true;
			}
		}
		return $changed;
	}
}
if (!function_exists('waDealUfLock_onBeforeUpdate')) {
	function waDealUfLock_onBeforeUpdate(&$arFields)
	{
		if (!is_array($arFields)) {
			return true;
		}
		if (waDealUfLock_isPortalAdmin()) {
			return true;
		}
		if (waDealUfLock_isAutomationContext()) {
			return true;
		}
		waDealUfLock_stripFields($arFields);
		return true;
	}
}

if (!function_exists('waDealUfLock_onBeforeUpdateOrm')) {
	function waDealUfLock_onBeforeUpdateOrm(\Bitrix\Main\Event $event)
	{
		$result = new \Bitrix\Main\ORM\EventResult();
		if (waDealUfLock_isPortalAdmin() || waDealUfLock_isAutomationContext()) {
			return $result;
		}
		$parameters = $event->getParameters();
		if (!isset($parameters['fields']) || !is_array($parameters['fields'])) {
			return $result;
		}
		$fields = $parameters['fields'];
		$before = $fields;
		if (!waDealUfLock_stripFields($fields)) {
			return $result;
		}
		$unset = [];
		foreach ($before as $name => $_) {
			if (!array_key_exists($name, $fields)) {
				$unset[] = $name;
			}
		}
		if ($unset) {
			$result->unsetFields($unset);
		}
		return $result;
	}
}

if (!function_exists('waDealUfLock_injectAssets')) {
	function waDealUfLock_injectAssets(): void
	{
		static $done = false;
		if ($done) {
			return;
		}
		if (waDealUfLock_isPortalAdmin()) {
			return;
		}

		$uri = (string)($_SERVER['REQUEST_URI'] ?? '');
		$path = parse_url($uri, PHP_URL_PATH);
		$path = is_string($path) ? $path : $uri;
		$page = '';
		if (!empty($GLOBALS['APPLICATION']) && is_object($GLOBALS['APPLICATION'])) {
			try {
				$page = (string)$GLOBALS['APPLICATION']->GetCurPage(false);
			} catch (\Throwable $e) {
				$page = '';
			}
		}
		$hay = $path . ' ' . $page;
		if (!preg_match('#/crm/deal/(details|kanban)#i', $hay)) {
			return;
		}

		$docRoot = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
		$js = '/local/crm/deal_uf_lock.js';
		if (!is_file($docRoot . $js)) {
			return;
		}
		if (!class_exists(Asset::class, true)) {
			return;
		}

		$ver = (string)@filemtime($docRoot . $js);
		$url = $js . ($ver !== '' ? '?v=' . $ver : '');
		$fieldsJson = \Bitrix\Main\Web\Json::encode(waDealUfLock_fields());
		$asset = Asset::getInstance();
		$asset->addJs($url);
		$asset->addString(
			'<script>window.__WA_DEAL_UF_LOCK=' . $fieldsJson . ';</script>'
			. '<script src="' . htmlspecialcharsbx($url) . '"></script>'
		);
		$done = true;
	}
}

$em = \Bitrix\Main\EventManager::getInstance();
$em->addEventHandler('crm', 'OnBeforeCrmDealUpdate', 'waDealUfLock_onBeforeUpdate');
$em->addEventHandler('crm', 'OnBeforeCrmDealAdd', 'waDealUfLock_onBeforeUpdate');
try {
	$em->addEventHandler('crm', '\Bitrix\Crm\DealTable::OnBeforeUpdate', 'waDealUfLock_onBeforeUpdateOrm');
	$em->addEventHandler('crm', '\Bitrix\Crm\DealTable::OnBeforeAdd', 'waDealUfLock_onBeforeUpdateOrm');
} catch (\Throwable $e) {
	// ignore
}
$em->addEventHandler('main', 'OnProlog', static function () {
	waDealUfLock_injectAssets();
});
$em->addEventHandler('main', 'OnEpilog', static function () {
	waDealUfLock_injectAssets();
});
