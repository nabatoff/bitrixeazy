<?php

namespace Artflowers\Salesplan\Internal;

use Artflowers\Salesplan\Model\AuditTable;
use Bitrix\Main\Type\DateTime;

class AuditService
{
	public static function log(
		string $entityType,
		?int $entityId,
		string $branchId,
		?int $userId,
		int $year,
		int $month,
		string $fieldName,
		$oldValue,
		$newValue,
		int $changedBy
	): void {
		AuditTable::add([
			'ENTITY_TYPE' => $entityType,
			'ENTITY_ID' => $entityId,
			'BRANCH_ID' => $branchId,
			'USER_ID' => $userId,
			'PERIOD_YEAR' => $year,
			'PERIOD_MONTH' => $month,
			'FIELD_NAME' => $fieldName,
			'OLD_VALUE' => (string)$oldValue,
			'NEW_VALUE' => (string)$newValue,
			'CHANGED_BY' => $changedBy,
			'CHANGED_AT' => new DateTime(),
		]);
	}

	public static function getForPeriod(string $branchId, int $year, int $month, int $limit = 100): array
	{
		$rows = AuditTable::getList([
			'filter' => [
				'=BRANCH_ID' => $branchId,
				'=PERIOD_YEAR' => $year,
				'=PERIOD_MONTH' => $month,
			],
			'order' => ['CHANGED_AT' => 'DESC'],
			'limit' => $limit,
		]);
		$result = [];
		while ($row = $rows->fetch()) {
			$result[] = $row;
		}
		return $result;
	}
}
