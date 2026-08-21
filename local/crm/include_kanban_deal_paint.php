<?php
/**
 * Подсветка карточек канбана сделок (воронки 15–20) по UF.
 * Подключать из bitrix/php_interface/init.php:
 *
 *   $waKanbanPaint = $_SERVER['DOCUMENT_ROOT'] . '/local/crm/include_kanban_deal_paint.php';
 *   if (is_file($waKanbanPaint)) {
 *       require_once $waKanbanPaint;
 *   }
 */

use Bitrix\Main\Page\Asset;

if (!function_exists('waKanbanDealPaint_isDealKanbanPage')) {
	function waKanbanDealPaint_isDealKanbanPage(): bool
	{
		$candidates = [];
		if (!empty($GLOBALS['APPLICATION']) && is_object($GLOBALS['APPLICATION'])) {
			try {
				$candidates[] = (string)$GLOBALS['APPLICATION']->GetCurPage(false);
				$candidates[] = (string)$GLOBALS['APPLICATION']->GetCurPage(true);
			} catch (\Throwable $e) {
				// ignore
			}
		}
		$candidates[] = (string)($_SERVER['REQUEST_URI'] ?? '');
		$candidates[] = (string)($_SERVER['SCRIPT_NAME'] ?? '');

		foreach ($candidates as $raw) {
			$path = parse_url($raw, PHP_URL_PATH);
			$path = is_string($path) ? $path : $raw;
			if ($path === '') {
				continue;
			}
			if (
				preg_match('#/crm/deal/kanban#i', $path)
				|| preg_match('#/crm/deal/category/\d+/kanban#i', $path)
			) {
				return true;
			}
		}
		return false;
	}
}

if (!function_exists('waKanbanDealPaint_injectAssets')) {
	function waKanbanDealPaint_injectAssets(string $phase = 'prolog'): void
	{
		static $jsDone = false;
		static $stringDone = false;

		if (!waKanbanDealPaint_isDealKanbanPage()) {
			return;
		}

		$docRoot = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
		$js = '/local/crm/kanban_deal_paint.js';
		$css = '/local/crm/kanban_deal_paint.css';
		if (!is_file($docRoot . $js)) {
			return;
		}

		$jsVer = (string)@filemtime($docRoot . $js);
		$cssVer = is_file($docRoot . $css) ? (string)@filemtime($docRoot . $css) : '';
		$jsUrl = $js . ($jsVer !== '' ? '?v=' . $jsVer : '');
		$cssUrl = $css . ($cssVer !== '' ? '?v=' . $cssVer : '');

		if (!class_exists(Asset::class, true)) {
			return;
		}
		$asset = Asset::getInstance();

		if (!$jsDone && ($phase === 'prolog' || $phase === 'both')) {
			$asset->addJs($jsUrl);
			if ($cssVer !== '') {
				$asset->addCss($cssUrl);
			}
			$jsDone = true;
		}

		if (!$stringDone && ($phase === 'epilog' || $phase === 'both')) {
			$html = '';
			if ($cssVer !== '') {
				$html .= '<link rel="stylesheet" href="' . htmlspecialcharsbx($cssUrl) . '">';
			}
			$html .= '<script src="' . htmlspecialcharsbx($jsUrl) . '"></script>';
			$asset->addString($html);
			$stringDone = true;
		}
	}
}

$em = \Bitrix\Main\EventManager::getInstance();
$em->addEventHandler('main', 'OnProlog', static function () {
	waKanbanDealPaint_injectAssets('prolog');
});
$em->addEventHandler('main', 'OnEpilog', static function () {
	waKanbanDealPaint_injectAssets('epilog');
});
