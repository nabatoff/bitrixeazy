<?php
/**
 * Авто «взято в работу» + employee при триггерных полях.
 * Не перезаписывает, если уже заполнено. Пишет от имени текущего USER.
 * BP/automation — пропускаем (пусть отрабатывает свой BP).
 *
 * Подключать ПОСЛЕ include_deal_uf_lock.php:
 *   $waDealAutoTake = $_SERVER['DOCUMENT_ROOT'] . '/local/crm/include_deal_auto_take.php';
 *   if (is_file($waDealAutoTake)) { require_once $waDealAutoTake; }
 */

if (!function_exists('waDealAutoTake_rules')) {
	function waDealAutoTake_rules(): array
	{
		return [
			/* Бухгалтер */
			[
				'name' => 'accountant',
				'triggers' => [
					/* Счет выставлен = Да */
					['field' => 'UF_CRM_1784636341021', 'values' => ['914', 914, '1', 1]],
					/* Предоплата получена */
					['field' => 'UF_CRM_1764332847245', 'values' => ['1', 1, 'Y', 'y', true]],
				],
				'taken' => 'UF_CRM_1785326361467',
				'takenYes' => '937',
				'employee' => 'UF_CRM_1785324070',
			],
			/* Закупщик */
			[
				'name' => 'purchaser',
				'triggers' => [
					/* Закуплено полностью / с изменениями */
					['field' => 'UF_CRM_1783486791226', 'values' => ['910', 910, '911', 911]],
				],
				'taken' => 'UF_CRM_1783485774093',
				'takenYes' => '908',
				'employee' => 'UF_CRM_1785325552',
			],
			/* Кладовщик */
			[
				'name' => 'warehouse',
				'triggers' => [
					/* Заказ выдан полностью / частично */
					['field' => 'UF_CRM_1784524115744', 'values' => ['912', 912, '913', 913]],
				],
				'taken' => 'UF_CRM_1787123174',
				'takenYes' => '954',
				'employee' => 'UF_CRM_1787123117',
			],
		];
	}
}

if (!function_exists('waDealAutoTake_norm')) {
	function waDealAutoTake_norm($v)
	{
		if (is_array($v)) {
			if (isset($v['VALUE'])) {
				return waDealAutoTake_norm($v['VALUE']);
			}
			if (isset($v['ID'])) {
				return waDealAutoTake_norm($v['ID']);
			}
			if (isset($v[0])) {
				return waDealAutoTake_norm($v[0]);
			}
			return '';
		}
		if (is_bool($v)) {
			return $v ? '1' : '0';
		}
		return trim((string)$v);
	}
}

if (!function_exists('waDealAutoTake_inValues')) {
	function waDealAutoTake_inValues($value, array $values): bool
	{
		$n = waDealAutoTake_norm($value);
		if ($n === '' || $n === '0' || strtoupper($n) === 'N') {
			return false;
		}
		foreach ($values as $want) {
			if ($n === waDealAutoTake_norm($want)) {
				return true;
			}
		}
		return false;
	}
}

if (!function_exists('waDealAutoTake_isFilledEmployee')) {
	function waDealAutoTake_isFilledEmployee($v): bool
	{
		$n = waDealAutoTake_norm($v);
		return $n !== '' && $n !== '0';
	}
}

if (!function_exists('waDealAutoTake_isTakenYes')) {
	function waDealAutoTake_isTakenYes($v, string $yesId): bool
	{
		return waDealAutoTake_inValues($v, [$yesId, (int)$yesId, '1', 1, 'Y']);
	}
}

if (!function_exists('waDealAutoTake_loadDeal')) {
	function waDealAutoTake_loadDeal(int $id): array
	{
		if ($id <= 0 || !class_exists('CCrmDeal')) {
			return [];
		}
		$select = ['ID'];
		foreach (waDealAutoTake_rules() as $rule) {
			$select[] = $rule['taken'];
			$select[] = $rule['employee'];
			foreach ($rule['triggers'] as $t) {
				$select[] = $t['field'];
			}
		}
		$select = array_values(array_unique($select));
		$row = CCrmDeal::GetListEx(
			[],
			['=ID' => $id, 'CHECK_PERMISSIONS' => 'N'],
			false,
			false,
			$select
		)->Fetch();
		return is_array($row) ? $row : [];
	}
}

if (!function_exists('waDealAutoTake_mergedValue')) {
	function waDealAutoTake_mergedValue(array $fields, array $old, string $name)
	{
		if (array_key_exists($name, $fields)) {
			return $fields[$name];
		}
		return $old[$name] ?? null;
	}
}

if (!function_exists('waDealAutoTake_triggerActive')) {
	function waDealAutoTake_triggerActive(array $fields, array $old, array $rule): bool
	{
		foreach ($rule['triggers'] as $t) {
			$val = waDealAutoTake_mergedValue($fields, $old, $t['field']);
			if (waDealAutoTake_inValues($val, $t['values'])) {
				return true;
			}
		}
		return false;
	}
}

if (!function_exists('waDealAutoTake_currentUserId')) {
	function waDealAutoTake_currentUserId(): int
	{
		global $USER;
		if ($USER && is_object($USER) && $USER->IsAuthorized()) {
			return (int)$USER->GetID();
		}
		return 0;
	}
}

if (!function_exists('waDealAutoTake_applyToFields')) {
	/**
	 * @return string[] имена полей, которые выставили мы (для UF-lock allow)
	 */
	function waDealAutoTake_applyToFields(array &$fields): array
	{
		static $busy = false;
		if ($busy) {
			return [];
		}
		if (!is_array($fields)) {
			return [];
		}
		/* не вмешиваемся в BP/роботов */
		if (function_exists('waDealUfLock_isAutomationContext') && waDealUfLock_isAutomationContext()) {
			return [];
		}
		$userId = waDealAutoTake_currentUserId();
		if ($userId <= 0) {
			return [];
		}

		$id = (int)($fields['ID'] ?? 0);
		if ($id <= 0) {
			return [];
		}

		$busy = true;
		$allowed = [];
		try {
			$old = waDealAutoTake_loadDeal($id);
			if (!$old) {
				return [];
			}

			foreach (waDealAutoTake_rules() as $rule) {
				if (!waDealAutoTake_triggerActive($fields, $old, $rule)) {
					continue;
				}

				$takenField = $rule['taken'];
				$empField = $rule['employee'];
				$takenYes = (string)$rule['takenYes'];

				$takenNow = waDealAutoTake_mergedValue($fields, $old, $takenField);
				$empNow = waDealAutoTake_mergedValue($fields, $old, $empField);

				if (!waDealAutoTake_isTakenYes($takenNow, $takenYes)) {
					$fields[$takenField] = $takenYes;
					$allowed[] = $takenField;
				}

				if (!waDealAutoTake_isFilledEmployee($empNow)) {
					$fields[$empField] = $userId;
					$allowed[] = $empField;
				}
			}
		} finally {
			$busy = false;
		}

		if ($allowed) {
			$prev = $GLOBALS['WA_DEAL_AUTO_TAKE_ALLOW'] ?? [];
			if (!is_array($prev)) {
				$prev = [];
			}
			$GLOBALS['WA_DEAL_AUTO_TAKE_ALLOW'] = array_values(array_unique(array_merge($prev, $allowed)));
		}

		return $allowed;
	}
}

if (!function_exists('waDealAutoTake_onBeforeUpdate')) {
	function waDealAutoTake_onBeforeUpdate(&$arFields)
	{
		waDealAutoTake_applyToFields($arFields);
		return true;
	}
}

if (!function_exists('waDealAutoTake_onBeforeUpdateOrm')) {
	function waDealAutoTake_onBeforeUpdateOrm(\Bitrix\Main\Event $event)
	{
		$parameters = $event->getParameters();
		if (!isset($parameters['fields']) || !is_array($parameters['fields'])) {
			return;
		}
		/* ORM часто без ID в fields — взять из primary */
		$fields = $parameters['fields'];
		if (empty($fields['ID'])) {
			$id = 0;
			if (isset($parameters['id'])) {
				$id = is_array($parameters['id']) ? (int)($parameters['id']['ID'] ?? reset($parameters['id'])) : (int)$parameters['id'];
			}
			if ($id > 0) {
				$fields['ID'] = $id;
			}
		}
		$allowed = waDealAutoTake_applyToFields($fields);
		if (!$allowed) {
			return;
		}
		$parameters['fields'] = $fields;
		try {
			$ref = new \ReflectionClass($event);
			if ($ref->hasProperty('parameters')) {
				$prop = $ref->getProperty('parameters');
				$prop->setAccessible(true);
				$prop->setValue($event, $parameters);
			}
		} catch (\Throwable $e) {
			// ignore
		}
	}
}

/* UF-lock: не срезать поля, которые выставил auto-take */
if (!function_exists('waDealUfLock_stripFields_original_wrap')) {
	/* patch strip via redefinition impossible — hook allow in lock file instead */
}

$em = \Bitrix\Main\EventManager::getInstance();
$em->addEventHandler('crm', 'OnBeforeCrmDealUpdate', 'waDealAutoTake_onBeforeUpdate');
try {
	$em->addEventHandler('crm', '\Bitrix\Crm\DealTable::OnBeforeUpdate', 'waDealAutoTake_onBeforeUpdateOrm');
} catch (\Throwable $e) {
	// ignore
}
