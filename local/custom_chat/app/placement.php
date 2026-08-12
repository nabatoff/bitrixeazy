<?php
/**
 * Placement: сделка/лид → оболочка КЦ.
 * Auth ДО любого вывода.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/shell.php';

try {
	$placement = isset($_REQUEST['PLACEMENT']) ? (string)$_REQUEST['PLACEMENT'] : '';
	$optionsRaw = isset($_REQUEST['PLACEMENT_OPTIONS']) ? (string)$_REQUEST['PLACEMENT_OPTIONS'] : '{}';
	$options = json_decode($optionsRaw, true);
	if (!is_array($options)) {
		$options = [];
	}
	$entityId = (int)($options['ID'] ?? $options['id'] ?? 0);

	$query = [];
	$placementUp = strtoupper($placement);
	if ($entityId > 0) {
		if (strpos($placementUp, 'LEAD') !== false) {
			$query['leadId'] = $entityId;
		} elseif (strpos($placementUp, 'DEAL') !== false) {
			$query['dealId'] = $entityId;
		}
	}

	$auth = waCcAppAuthorizeFromRequest();
	if (!$auth['ok']) {
		waCcAppRenderError('Нет сессии: ' . ($auth['error'] ?: 'auth_failed')
			. (!empty($auth['debug']) ? (' [' . $auth['debug'] . ']') : ''));
	}

	$mobile = waCcAppIsMobileClient();
	$note = ($placement !== '' ? $placement : 'DEFAULT') . ' / ' . ($mobile ? 'mobile' : 'desktop');
	waCcAppRenderShell($query, (int)$auth['userId'], $note, $mobile);
} catch (\Throwable $e) {
	waCcAppRenderError('Fatal: ' . $e->getMessage());
}
