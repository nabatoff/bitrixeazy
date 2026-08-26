<?php

use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

class artflowers_salesplan extends CModule
{
	public $MODULE_ID = 'artflowers.salesplan';
	public $MODULE_VERSION;
	public $MODULE_VERSION_DATE;
	public $MODULE_NAME;
	public $MODULE_DESCRIPTION;
	public $PARTNER_NAME = 'Art Flowers';
	public $PARTNER_URI = 'https://crm.artflowers.kz';

	public function __construct()
	{
		$arModuleVersion = [];
		include __DIR__ . '/version.php';
		$this->MODULE_VERSION = $arModuleVersion['VERSION'];
		$this->MODULE_VERSION_DATE = $arModuleVersion['VERSION_DATE'];
		$this->MODULE_NAME = 'Филиальный план продаж';
		$this->MODULE_DESCRIPTION = 'План продаж по филиалам с изоляцией доступа';
	}

	public function DoInstall()
	{
		global $APPLICATION;
		if (!\Bitrix\Main\Loader::includeModule('crm')) {
			$APPLICATION->ThrowException('Требуется модуль CRM');
			return false;
		}
		$this->InstallDB();
		$this->InstallEvents();
		$this->InstallFiles();
		\Bitrix\Main\ModuleManager::registerModule($this->MODULE_ID);
		return true;
	}

	public function DoUninstall()
	{
		$this->UnInstallEvents();
		$this->UnInstallFiles();
		$this->UnInstallDB();
		\Bitrix\Main\ModuleManager::unRegisterModule($this->MODULE_ID);
		return true;
	}

	public function InstallDB()
	{
		global $DB;
		$errors = $DB->RunSQLBatch(__DIR__ . '/db/mysql/install.sql');
		if ($errors !== false) {
			throw new \RuntimeException(implode("\n", $errors));
		}
		$this->seedDefaultOptions();
		return true;
	}

	public function UnInstallDB()
	{
		global $DB;
		$DB->RunSQLBatch(__DIR__ . '/db/mysql/uninstall.sql');
		\Bitrix\Main\Config\Option::delete($this->MODULE_ID);
		return true;
	}

	public function InstallEvents()
	{
		return true;
	}

	public function UnInstallEvents()
	{
		return true;
	}

	public function InstallFiles()
	{
		return true;
	}

	public function UnInstallFiles()
	{
		return true;
	}

	protected function seedDefaultOptions(): void
	{
		if (\Bitrix\Main\Config\Option::get($this->MODULE_ID, 'branches_json', '') !== '') {
			return;
		}
		$branches = [
			'almaty' => ['id' => 'almaty', 'name' => 'Алматы', 'department_id' => 237, 'category_ids' => [15, 16], 'category_labels' => ['15' => 'Срезанные', '16' => 'Комнатные']],
			'astana' => ['id' => 'astana', 'name' => 'Астана', 'department_id' => 236, 'category_ids' => [17, 18], 'category_labels' => ['17' => 'Срезанные', '18' => 'Комнатные']],
			'uralsk' => ['id' => 'uralsk', 'name' => 'Уральск', 'department_id' => 238, 'category_ids' => [19, 20], 'category_labels' => ['19' => 'Срезанные', '20' => 'Комнатные']],
		];
		\Bitrix\Main\Config\Option::set(
			$this->MODULE_ID,
			'branches_json',
			json_encode($branches, JSON_UNESCAPED_UNICODE)
		);
	}
}
