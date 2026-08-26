<?php

namespace Artflowers\Salesplan\Model;

use Bitrix\Main\Entity\DataManager;
use Bitrix\Main\Entity\DatetimeField;
use Bitrix\Main\Entity\IntegerField;
use Bitrix\Main\Entity\StringField;
use Bitrix\Main\Type\DateTime;

class AuditTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'af_salesplan_audit';
	}

	public static function getMap(): array
	{
		return [
			new IntegerField('ID', ['primary' => true, 'autocomplete' => true]),
			new StringField('ENTITY_TYPE', ['required' => true]),
			new IntegerField('ENTITY_ID'),
			new StringField('BRANCH_ID', ['required' => true]),
			new IntegerField('USER_ID'),
			new IntegerField('PERIOD_YEAR', ['required' => true]),
			new IntegerField('PERIOD_MONTH', ['required' => true]),
			new StringField('FIELD_NAME', ['required' => true]),
			new StringField('OLD_VALUE'),
			new StringField('NEW_VALUE'),
			new IntegerField('CHANGED_BY', ['required' => true]),
			new DatetimeField('CHANGED_AT', ['required' => true, 'default_value' => static fn() => new DateTime()]),
		];
	}
}
