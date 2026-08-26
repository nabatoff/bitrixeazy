<?php
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php';

use Artflowers\Salesplan\Config\BranchConfig;
use Artflowers\Salesplan\Internal\AccessService;
use Bitrix\Main\Loader;
use Bitrix\Main\Page\Asset;

$APPLICATION->SetTitle('План продаж');

if (!Loader::includeModule('artflowers.salesplan') || !Loader::includeModule('crm')) {
	ShowError('Модуль плана продаж недоступен');
	require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php';
	return;
}

$access = AccessService::forCurrentUser();
if (!$access->canReadSaleTarget()) {
	ShowError('Недостаточно прав для просмотра плана продаж');
	require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php';
	return;
}

$branches = $access->getVisibleBranches();
if ($branches === []) {
	ShowError('Нет доступных филиалов');
	require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php';
	return;
}

$defaultBranch = $access->resolveDefaultBranchId();
$year = (int)date('Y');
$month = (int)date('n');

$base = '/local/sales_plan';
$cssFile = $_SERVER['DOCUMENT_ROOT'] . $base . '/assets/sales_plan.css';
$jsFile = $_SERVER['DOCUMENT_ROOT'] . $base . '/assets/sales_plan.js';
$cssV = is_file($cssFile) ? filemtime($cssFile) : time();
$jsV = is_file($jsFile) ? filemtime($jsFile) : time();

Asset::getInstance()->addCss($base . '/assets/sales_plan.css?v=' . $cssV);
Asset::getInstance()->addJs($base . '/assets/sales_plan.js?v=' . $jsV);

$cfg = [
	'ajaxUrl' => $base . '/ajax.php',
	'dealsUrl' => $base . '/deals.php',
	'sessid' => bitrix_sessid(),
	'isAdmin' => $access->isAdmin(),
	'branches' => array_values(array_map(static function ($b) {
		return [
			'id' => (string)$b['id'],
			'name' => (string)$b['name'],
		];
	}, $branches)),
	'defaultBranch' => $defaultBranch,
	'year' => $year,
	'month' => $month,
];
?>
<div id="af-sales-plan-app" class="af-sp-app">
	<div class="af-sp-toolbar ui-ctl-toolbar">
		<div class="af-sp-toolbar__group">
			<label class="af-sp-label">Месяц</label>
			<input type="month" id="af-sp-period" class="ui-ctl-element" value="<?= htmlspecialchars(sprintf('%04d-%02d', $year, $month)) ?>">
		</div>
		<?php if ($access->isAdmin()): ?>
		<div class="af-sp-toolbar__group">
			<label class="af-sp-label">Филиал</label>
			<select id="af-sp-branch" class="ui-ctl-element">
				<?php foreach ($branches as $branch): ?>
					<option value="<?= htmlspecialchars((string)$branch['id']) ?>"<?= $defaultBranch === (string)$branch['id'] ? ' selected' : '' ?>>
						<?= htmlspecialchars((string)$branch['name']) ?>
					</option>
				<?php endforeach; ?>
			</select>
		</div>
		<?php else: ?>
			<input type="hidden" id="af-sp-branch" value="<?= htmlspecialchars((string)$defaultBranch) ?>">
		<?php endif; ?>
		<div class="af-sp-toolbar__group">
			<label class="af-sp-label">Воронка</label>
			<select id="af-sp-category" class="ui-ctl-element"></select>
		</div>
		<button type="button" class="ui-btn ui-btn-light-border" id="af-sp-refresh">Обновить</button>
		<button type="button" class="ui-btn ui-btn-success" id="af-sp-save" style="display:none;">Сохранить</button>
		<?php if ($access->isAdmin()): ?>
		<button type="button" class="ui-btn ui-btn-light" id="af-sp-import">Импорт SaleTarget</button>
		<?php endif; ?>
	</div>

	<div id="af-sp-alert" class="af-sp-alert" style="display:none;"></div>

	<div class="af-sp-summary" id="af-sp-summary"></div>

	<div class="af-sp-table-wrap">
		<table class="af-sp-table" id="af-sp-table">
			<thead>
				<tr>
					<th>Менеджер</th>
					<th>План</th>
					<th>Факт</th>
					<th>Сделок</th>
					<th>Ср. чек</th>
					<th>%</th>
					<th>Остаток</th>
					<th>Прогноз</th>
				</tr>
			</thead>
			<tbody></tbody>
		</table>
	</div>

	<div id="af-sp-audit" class="af-sp-audit" style="display:none;"></div>
</div>
<script>window.__AF_SALES_PLAN = <?= \Bitrix\Main\Web\Json::encode($cfg, JSON_UNESCAPED_UNICODE) ?>;</script>
<?php
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php';
