<?php

namespace Artflowers\Salesplan\Model;

use Bitrix\Main\Entity\DataManager;
use Bitrix\Main\Entity\DatetimeField;
use Bitrix\Main\Entity\FloatField;
use Bitrix\Main\Entity\IntegerField;
use Bitrix\Main\Entity\StringField;
use Bitrix\Main\Type\DateTime;

class BranchPlanTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'af_salesplan_branch_plan';
	}

	public static function getMap(): array
	{
		return [
			new IntegerField('ID', ['primary' => true, 'autocomplete' => true]),
			new StringField('BRANCH_ID', ['required' => true]),
			new IntegerField('PERIOD_YEAR', ['required' => true]),
			new IntegerField('PERIOD_MONTH', ['required' => true]),
			new FloatField('AMOUNT', ['required' => true, 'default_value' => 0]),
			new StringField('CURRENCY', ['default_value' => 'KZT']),
			new DatetimeField('DATE_CREATE', ['default_value' => static fn() => new DateTime()]),
			new DatetimeField('DATE_MODIFY'),
			new IntegerField('CREATED_BY'),
			new IntegerField('MODIFIED_BY'),
		];
	}
}
