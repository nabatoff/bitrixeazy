<?php

namespace Artflowers\Salesplan\Internal;

use Artflowers\Salesplan\Config\BranchConfig;
use Bitrix\Crm\PhaseSemantics;
use Bitrix\Crm\Statistics\Entity\DealSumStatisticsTable;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\Entity\Base;
use Bitrix\Main\Entity\ExpressionField;
use Bitrix\Main\Entity\Query;
use Bitrix\Main\Entity\ReferenceField;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\Date;

class ActualsService
{
	public const CACHE_TTL = 60;
	public const CACHE_DIR = '/artflowers/salesplan/actuals/';

	public static function getPeriodDateBounds(int $year, int $month): array
	{
		$month = max(1, min(12, $month));
		$start = Date::createFromTimestamp(mktime(0, 0, 0, $month, 1, $year));
		$endDay = (int)date('t', mktime(0, 0, 0, $month, 1, $year));
		$end = Date::createFromTimestamp(mktime(0, 0, 0, $month, $endDay, $year));
		return [$start, $end];
	}

	public static function fetchAggregated(string $branchId, int $year, int $month, ?string $categoryFilter = null, ?int $userId = null): array
	{
		$branch = BranchConfig::getById($branchId);
		if (!$branch) {
			return self::emptyAggregate();
		}
		$categoryIds = BranchConfig::resolveCategoryIds($branch, $categoryFilter);
		$cacheKey = md5(json_encode([$branchId, $year, $month, $categoryIds, $userId]));
		$cache = Cache::createInstance();
		if ($cache->initCache(self::CACHE_TTL, $cacheKey, self::CACHE_DIR)) {
			$vars = $cache->getVars();
			if (is_array($vars)) {
				return $vars;
			}
		}

		$result = self::queryStatistics($categoryIds, $year, $month, $userId);
		if ($cache->startDataCache()) {
			$cache->endDataCache($result);
		}
		return $result;
	}

	protected static function emptyAggregate(): array
	{
		return [
			'sum' => 0.0,
			'count' => 0,
			'avg' => 0.0,
		];
	}

	protected static function queryStatistics(array $categoryIds, int $year, int $month, ?int $userId): array
	{
		if (!Loader::includeModule('crm') || $categoryIds === []) {
			return self::emptyAggregate();
		}

		if (class_exists(DealSumStatisticsTable::class)) {
			return self::queryFromStatisticsTable($categoryIds, $year, $month, $userId);
		}

		return self::queryFromDeals($categoryIds, $year, $month, $userId);
	}

	protected static function queryFromStatisticsTable(array $categoryIds, int $year, int $month, ?int $userId): array
	{
		[$startDate, $endDate] = self::getPeriodDateBounds($year, $month);

		$subQuery = new Query(DealSumStatisticsTable::getEntity());
		$subQuery->setTableAliasPostfix('_s1');
		$subQuery->addSelect('OWNER_ID');
		$subQuery->addFilter('>=END_DATE', $startDate);
		$subQuery->addFilter('<=END_DATE', $endDate);
		$subQuery->addFilter('=STAGE_SEMANTIC_ID', PhaseSemantics::SUCCESS);
		$subQuery->addFilter('@CATEGORY_ID', $categoryIds);
		if ($userId !== null && $userId > 0) {
			$subQuery->addFilter('=RESPONSIBLE_ID', $userId);
		}
		$subQuery->addGroup('OWNER_ID');
		$subQuery->registerRuntimeField('', new ExpressionField('MAX_CREATED_DATE', 'MAX(%s)', 'CREATED_DATE'));
		$subQuery->addSelect('MAX_CREATED_DATE');

		$query = new Query(DealSumStatisticsTable::getEntity());
		$query->setTableAliasPostfix('_s2');
		$query->registerRuntimeField('', new ExpressionField('SUM_TOTAL_R', 'SUM(%s)', 'SUM_TOTAL'));
		$query->registerRuntimeField('', new ExpressionField('CNT', 'COUNT(DISTINCT %s)', 'OWNER_ID'));
		$query->addSelect('SUM_TOTAL_R');
		$query->addSelect('CNT');
		$query->registerRuntimeField(
			'',
			new ReferenceField(
				'M',
				Base::getInstanceByQuery($subQuery),
				['=this.OWNER_ID' => 'ref.OWNER_ID', '=this.CREATED_DATE' => 'ref.MAX_CREATED_DATE'],
				['join_type' => 'INNER']
			)
		);

		$row = $query->exec()->fetch();
		$sum = (float)($row['SUM_TOTAL_R'] ?? 0);
		$count = (int)($row['CNT'] ?? 0);

		return [
			'sum' => round($sum, 2),
			'count' => $count,
			'avg' => $count > 0 ? round($sum / $count, 2) : 0.0,
		];
	}

	protected static function queryFromDeals(array $categoryIds, int $year, int $month, ?int $userId): array
	{
		[$startDate, $endDate] = self::getPeriodDateBounds($year, $month);
		$filter = [
			'@CATEGORY_ID' => $categoryIds,
			'=STAGE_SEMANTIC_ID' => 'S',
			'>=CLOSEDATE' => $startDate->format('Y-m-d') . ' 00:00:00',
			'<=CLOSEDATE' => $endDate->format('Y-m-d') . ' 23:59:59',
			'CHECK_PERMISSIONS' => 'N',
		];
		if ($userId !== null && $userId > 0) {
			$filter['=ASSIGNED_BY_ID'] = $userId;
		}
		$sum = 0.0;
		$count = 0;
		$rs = \CCrmDeal::GetListEx([], $filter, false, false, ['ID', 'OPPORTUNITY_ACCOUNT', 'OPPORTUNITY']);
		while ($row = $rs->Fetch()) {
			$sum += (float)($row['OPPORTUNITY_ACCOUNT'] ?? $row['OPPORTUNITY'] ?? 0);
			$count++;
		}
		return [
			'sum' => round($sum, 2),
			'count' => $count,
			'avg' => $count > 0 ? round($sum / $count, 2) : 0.0,
		];
	}

	public static function fetchManagersActuals(string $branchId, int $year, int $month, ?string $categoryFilter, array $userIds): array
	{
		$result = [];
		foreach ($userIds as $userId) {
			$userId = (int)$userId;
			if ($userId <= 0) {
				continue;
			}
			$result[$userId] = self::fetchAggregated($branchId, $year, $month, $categoryFilter, $userId);
		}
		return $result;
	}

	public static function clearCache(): void
	{
		$cache = Cache::createInstance();
		if (method_exists($cache, 'cleanDir')) {
			$cache->cleanDir(self::CACHE_DIR);
		}
	}

	public static function getDealIds(string $branchId, int $year, int $month, ?string $categoryFilter, ?int $userId, int $limit = 200): array
	{
		$branch = BranchConfig::getById($branchId);
		if (!$branch || !Loader::includeModule('crm')) {
			return [];
		}
		$categoryIds = BranchConfig::resolveCategoryIds($branch, $categoryFilter);
		[$startDate, $endDate] = self::getPeriodDateBounds($year, $month);
		$ids = [];

		if (class_exists(DealSumStatisticsTable::class)) {
			$subQuery = new Query(DealSumStatisticsTable::getEntity());
			$subQuery->setTableAliasPostfix('_d1');
			$subQuery->addSelect('OWNER_ID');
			$subQuery->addFilter('>=END_DATE', $startDate);
			$subQuery->addFilter('<=END_DATE', $endDate);
			$subQuery->addFilter('=STAGE_SEMANTIC_ID', PhaseSemantics::SUCCESS);
			$subQuery->addFilter('@CATEGORY_ID', $categoryIds);
			if ($userId !== null && $userId > 0) {
				$subQuery->addFilter('=RESPONSIBLE_ID', $userId);
			}
			$subQuery->addGroup('OWNER_ID');
			$subQuery->registerRuntimeField('', new ExpressionField('MAX_CREATED_DATE', 'MAX(%s)', 'CREATED_DATE'));
			$subQuery->addSelect('MAX_CREATED_DATE');

			$query = new Query(DealSumStatisticsTable::getEntity());
			$query->setTableAliasPostfix('_d2');
			$query->addSelect('OWNER_ID');
			$query->addOrder('END_DATE', 'DESC');
			$query->setLimit($limit);
			$query->registerRuntimeField(
				'',
				new ReferenceField(
					'M',
					Base::getInstanceByQuery($subQuery),
					['=this.OWNER_ID' => 'ref.OWNER_ID', '=this.CREATED_DATE' => 'ref.MAX_CREATED_DATE'],
					['join_type' => 'INNER']
				)
			);
			$rows = $query->exec();
			while ($row = $rows->fetch()) {
				$id = (int)($row['OWNER_ID'] ?? 0);
				if ($id > 0) {
					$ids[$id] = $id;
				}
			}
		} else {
			$filter = [
				'@CATEGORY_ID' => $categoryIds,
				'=STAGE_SEMANTIC_ID' => 'S',
				'>=CLOSEDATE' => $startDate->format('Y-m-d') . ' 00:00:00',
				'<=CLOSEDATE' => $endDate->format('Y-m-d') . ' 23:59:59',
				'CHECK_PERMISSIONS' => 'N',
			];
			if ($userId !== null && $userId > 0) {
				$filter['=ASSIGNED_BY_ID'] = $userId;
			}
			$rs = \CCrmDeal::GetListEx(['CLOSEDATE' => 'DESC'], $filter, false, ['nTopCount' => $limit], ['ID']);
			while ($row = $rs->Fetch()) {
				$ids[(int)$row['ID']] = (int)$row['ID'];
			}
		}
		return array_values($ids);
	}
}
