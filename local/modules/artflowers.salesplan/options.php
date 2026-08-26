<?php
defined('B_PROLOG_INCLUDED') || die;

use Artflowers\Salesplan\Config\BranchConfig;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;

$moduleId = 'artflowers.salesplan';

if ($APPLICATION->GetGroupRight($moduleId) < 'R') {
	return;
}

Loader::includeModule($moduleId);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid()) {
	$raw = (string)($_POST['branches_json'] ?? '');
	$data = json_decode($raw, true);
	if (is_array($data) && $data !== []) {
		BranchConfig::saveAll($data);
		CAdminMessage::ShowMessage(['MESSAGE' => 'Сохранено', 'TYPE' => 'OK']);
	}
}

$branches = BranchConfig::getAll();
$json = json_encode($branches, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>
<form method="post">
	<?= bitrix_sessid_post() ?>
	<h2>Филиалы плана продаж</h2>
	<p>JSON: id, name, department_id, category_ids, category_labels. Руководитель и состав — из оргструктуры.</p>
	<textarea name="branches_json" rows="22" cols="100" style="width:100%;font-family:monospace;"><?= htmlspecialchars($json) ?></textarea>
	<br><br>
	<input type="submit" value="Сохранить" class="adm-btn-save">
</form>
