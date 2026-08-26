<?php
/**
 * История изменений ключевых UF сделки → b_crm_event (как смена суммы).
 *
 * В init.php ПОСЛЕ остальных local/crm includes:
 *   $waDealUfHist = $_SERVER['DOCUMENT_ROOT'] . '/local/crm/include_deal_uf_history.php';
 *   if (is_file($waDealUfHist)) { require_once $waDealUfHist; }
 */

if (!function_exists('waDealUfHistory_fields')) {
	function waDealUfHistory_fields(): array
	{
		return [
			'UF_CRM_1784636341021', // Счет выставлен
			'UF_CRM_1764332847245', // Предоплата получена
			'UF_CRM_1764577842986', // Полная оплата за поставку получена
			'UF_CRM_1784802244742', // Запрос на выдачу товара без полной оплаты
			'UF_CRM_1783486791226', // Закуплено полностью или с изменениями?
			'UF_CRM_1784524115744', // Заказ выдан
		];
	}
}

if (!function_exists('waDealUfHistory_fieldType')) {
	function waDealUfHistory_fieldType(string $field): string
	{
		$meta = waDealUfHistory_loadMeta();
		return (string)($meta[$field]['type'] ?? '');
	}
}

if (!function_exists('waDealUfHistory_norm')) {
	/**
	 * Каноническое значение для сравнения.
	 * boolean: пусто / 0 / N / false → '0'; 1 / Y → '1'
	 * enumeration: пусто → ''; иначе id
	 */
	function waDealUfHistory_norm($v, string $type = ''): string
	{
		if (is_array($v)) {
			if (isset($v['VALUE'])) {
				return waDealUfHistory_norm($v['VALUE'], $type);
			}
			if (isset($v['ID'])) {
				return waDealUfHistory_norm($v['ID'], $type);
			}
			if (isset($v[0])) {
				return waDealUfHistory_norm($v[0], $type);
			}
			$v = '';
		}
		if (is_bool($v)) {
			$v = $v ? '1' : '0';
		}
		$s = trim((string)$v);
		$u = strtoupper($s);

		if ($type === 'boolean') {
			if ($s === '' || $s === '0' || $u === 'N' || $u === 'FALSE') {
				return '0';
			}
			if ($s === '1' || $u === 'Y' || $u === 'TRUE') {
				return '1';
			}
			return '0';
		}

		if ($s === '' || $u === 'N') {
			return '';
		}
		if ($u === 'Y') {
			return '1';
		}
		return $s;
	}
}

if (!function_exists('waDealUfHistory_same')) {
	function waDealUfHistory_same(string $field, $a, $b): bool
	{
		$type = waDealUfHistory_fieldType($field);
		return waDealUfHistory_norm($a, $type) === waDealUfHistory_norm($b, $type);
	}
}

if (!function_exists('waDealUfHistory_loadMeta')) {
	function waDealUfHistory_loadMeta(): array
	{
		static $meta = null;
		if (is_array($meta)) {
			return $meta;
		}
		$meta = [];
		global $USER_FIELD_MANAGER;
		if (!$USER_FIELD_MANAGER) {
			return $meta;
		}
		$all = $USER_FIELD_MANAGER->GetUserFields('CRM_DEAL', 0, LANGUAGE_ID);
		foreach (waDealUfHistory_fields() as $name) {
			if (!isset($all[$name])) {
				continue;
			}
			$uf = $all[$name];
			$label = '';
			foreach (['EDIT_FORM_LABEL', 'LIST_COLUMN_LABEL', 'LIST_FILTER_LABEL'] as $k) {
				if (!empty($uf[$k]) && is_array($uf[$k])) {
					$label = (string)($uf[$k]['ru'] ?? reset($uf[$k]) ?: '');
					if ($label !== '') {
						break;
					}
				} elseif (!empty($uf[$k]) && is_string($uf[$k])) {
					$label = $uf[$k];
					break;
				}
			}
			$enums = [];
			if (($uf['USER_TYPE_ID'] ?? '') === 'enumeration' && class_exists('CUserFieldEnum')) {
				$rs = CUserFieldEnum::GetList([], ['USER_FIELD_ID' => (int)$uf['ID']]);
				while ($e = $rs->Fetch()) {
					$enums[(string)$e['ID']] = (string)$e['VALUE'];
				}
			}
			$meta[$name] = [
				'label' => $label !== '' ? $label : $name,
				'type' => (string)($uf['USER_TYPE_ID'] ?? ''),
				'enums' => $enums,
			];
		}
		return $meta;
	}
}

if (!function_exists('waDealUfHistory_caption')) {
	function waDealUfHistory_caption(string $field, $value): string
	{
		$meta = waDealUfHistory_loadMeta();
		$info = $meta[$field] ?? ['type' => '', 'enums' => []];
		$type = (string)($info['type'] ?? '');
		$n = waDealUfHistory_norm($value, $type);
		if ($type === 'boolean') {
			return ($n === '1') ? 'Да' : 'Нет';
		}
		if ($n === '') {
			return '—';
		}
		if ($type === 'enumeration') {
			$enums = $info['enums'] ?? [];
			if (isset($enums[$n])) {
				return $enums[$n];
			}
		}
		return $n;
	}
}

if (!function_exists('waDealUfHistory_loadValues')) {
	function waDealUfHistory_loadValues(int $id): array
	{
		if ($id <= 0 || !class_exists('CCrmDeal')) {
			return [];
		}
		$select = array_merge(['ID'], waDealUfHistory_fields());
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

if (!function_exists('waDealUfHistory_onBeforeUpdate')) {
	function waDealUfHistory_onBeforeUpdate(&$arFields)
	{
		if (!is_array($arFields)) {
			return true;
		}
		$id = (int)($arFields['ID'] ?? 0);
		if ($id <= 0) {
			return true;
		}
		if (!isset($GLOBALS['WA_DEAL_UF_HISTORY_OLD']) || !is_array($GLOBALS['WA_DEAL_UF_HISTORY_OLD'])) {
			$GLOBALS['WA_DEAL_UF_HISTORY_OLD'] = [];
		}
		$GLOBALS['WA_DEAL_UF_HISTORY_OLD'][$id] = waDealUfHistory_loadValues($id);
		return true;
	}
}

if (!function_exists('waDealUfHistory_registerChange')) {
	function waDealUfHistory_registerChange(int $dealId, string $field, $oldVal, $newVal): void
	{
		if ($dealId <= 0 || !class_exists('CCrmEvent')) {
			return;
		}
		/* антидубль на один save (legacy+ORM / двойной after) */
		$type = waDealUfHistory_fieldType($field);
		$key = $dealId . '|' . $field . '|' . waDealUfHistory_norm($oldVal, $type) . '>' . waDealUfHistory_norm($newVal, $type);
		$now = time();
		if (!isset($GLOBALS['WA_DEAL_UF_HISTORY_DEDUP']) || !is_array($GLOBALS['WA_DEAL_UF_HISTORY_DEDUP'])) {
			$GLOBALS['WA_DEAL_UF_HISTORY_DEDUP'] = [];
		}
		$prev = (int)($GLOBALS['WA_DEAL_UF_HISTORY_DEDUP'][$key] ?? 0);
		if ($prev > 0 && ($now - $prev) < 5) {
			return;
		}
		$GLOBALS['WA_DEAL_UF_HISTORY_DEDUP'][$key] = $now;

		$meta = waDealUfHistory_loadMeta();
		$label = $meta[$field]['label'] ?? $field;
		$event = new CCrmEvent();
		$event->Add([
			'ENTITY_TYPE' => 'DEAL',
			'ENTITY_ID' => $dealId,
			'ENTITY_FIELD' => $field,
			'EVENT_TYPE' => CCrmEvent::TYPE_CHANGE,
			'EVENT_NAME' => 'Значение поля "' . $label . '" было изменено',
			'EVENT_TEXT_1' => waDealUfHistory_caption($field, $oldVal),
			'EVENT_TEXT_2' => waDealUfHistory_caption($field, $newVal),
		], false);
	}
}

if (!function_exists('waDealUfHistory_onAfterUpdate')) {
	function waDealUfHistory_onAfterUpdate(&$arFields)
	{
		if (!is_array($arFields)) {
			return true;
		}
		$id = (int)($arFields['ID'] ?? 0);
		if ($id <= 0) {
			return true;
		}
		if (empty($GLOBALS['WA_DEAL_UF_HISTORY_OLD'][$id]) || !is_array($GLOBALS['WA_DEAL_UF_HISTORY_OLD'][$id])) {
			return true;
		}
		$old = $GLOBALS['WA_DEAL_UF_HISTORY_OLD'][$id];
		unset($GLOBALS['WA_DEAL_UF_HISTORY_OLD'][$id]);

		/* сравниваем факт в БД до/после, а не сырой $arFields (форма шлёт пустые boolean) */
		$new = waDealUfHistory_loadValues($id);
		if (!$new) {
			return true;
		}

		foreach (waDealUfHistory_fields() as $name) {
			$before = $old[$name] ?? null;
			$after = $new[$name] ?? null;
			if (waDealUfHistory_same($name, $before, $after)) {
				continue;
			}
			waDealUfHistory_registerChange($id, $name, $before, $after);
		}
		return true;
	}
}

if (!function_exists('waDealUfHistory_onAfterAdd')) {
	function waDealUfHistory_onAfterAdd(&$arFields)
	{
		if (!is_array($arFields)) {
			return true;
		}
		$id = (int)($arFields['ID'] ?? 0);
		if ($id <= 0) {
			return true;
		}
		$new = waDealUfHistory_loadValues($id);
		if (!$new) {
			return true;
		}
		foreach (waDealUfHistory_fields() as $name) {
			$after = $new[$name] ?? null;
			if (waDealUfHistory_same($name, null, $after)) {
				continue;
			}
			waDealUfHistory_registerChange($id, $name, null, $after);
		}
		return true;
	}
}

$em = \Bitrix\Main\EventManager::getInstance();
$em->addEventHandler('crm', 'OnBeforeCrmDealUpdate', 'waDealUfHistory_onBeforeUpdate');
$em->addEventHandler('crm', 'OnAfterCrmDealUpdate', 'waDealUfHistory_onAfterUpdate');
$em->addEventHandler('crm', 'OnAfterCrmDealAdd', 'waDealUfHistory_onAfterAdd');
