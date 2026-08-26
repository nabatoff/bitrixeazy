<?php
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php';

use Artflowers\Salesplan\Config\BranchConfig;
use Artflowers\Salesplan\Internal\AccessService;
use Artflowers\Salesplan\Internal\ActualsService;
use Bitrix\Main\Loader;

$APPLICATION->SetTitle('Сделки плана продаж');

if (!Loader::includeModule('artflowers.salesplan') || !Loader::includeModule('crm')) {
	ShowError('Модуль недоступен');
	require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php';
	return;
}

$access = AccessService::forCurrentUser();
$branchId = trim((string)($_REQUEST['branch_id'] ?? ''));
$year = (int)($_REQUEST['year'] ?? date('Y'));
$month = (int)($_REQUEST['month'] ?? date('n'));
$category = (string)($_REQUEST['category'] ?? 'all');
$userId = isset($_REQUEST['user_id']) ? (int)$_REQUEST['user_id'] : null;

if ($branchId === '') {
	$branchId = (string)$access->resolveDefaultBranchId();
}

try {
	$access->assertCanViewBranch($branchId);
	if ($userId !== null && $userId > 0) {
		$access->assertCanViewUser($branchId, $userId);
	}
} catch (\Throwable $e) {
	ShowError('Доступ запрещён');
	require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php';
	return;
}

$dealIds = ActualsService::getDealIds($branchId, $year, $month, $category, $userId, 200);
$branch = BranchConfig::getById($branchId);
?>
<div class="af-sp-deals">
	<p><a href="/local/sales_plan/">← План продаж</a></p>
	<h2><?= htmlspecialchars((string)($branch['name'] ?? $branchId)) ?> — успешные сделки <?= (int)$month ?>.<?= (int)$year ?></h2>
	<?php if ($dealIds === []): ?>
		<p>Сделок не найдено.</p>
	<?php else: ?>
		<ul class="af-sp-deals-list">
			<?php foreach ($dealIds as $dealId): ?>
				<li><a href="/crm/deal/details/<?= (int)$dealId ?>/" target="_blank">Сделка #<?= (int)$dealId ?></a></li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>
<?php
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php';
