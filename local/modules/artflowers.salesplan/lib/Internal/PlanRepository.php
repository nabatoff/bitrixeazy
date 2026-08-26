<?php

namespace Artflowers\Salesplan\Internal;

use Artflowers\Salesplan\Config\BranchConfig;
use Artflowers\Salesplan\Model\BranchPlanTable;
use Artflowers\Salesplan\Model\UserPlanTable;
use Bitrix\Main\Type\DateTime;

class PlanRepository
{
	public static function getBranchPlan(string $branchId, int $year, int $month): float
	{
		$row = BranchPlanTable::getList([
			'filter' => [
				'=BRANCH_ID' => $branchId,
				'=PERIOD_YEAR' => $year,
				'=PERIOD_MONTH' => $month,
			],
			'select' => ['AMOUNT'],
			'limit' => 1,
		])->fetch();
		return $row ? (float)$row['AMOUNT'] : 0.0;
	}

	public static function saveBranchPlan(string $branchId, int $year, int $month, float $amount, int $userId): void
	{
		$existing = BranchPlanTable::getList([
			'filter' => [
				'=BRANCH_ID' => $branchId,
				'=PERIOD_YEAR' => $year,
				'=PERIOD_MONTH' => $month,
			],
			'limit' => 1,
		])->fetch();

		$fields = [
			'BRANCH_ID' => $branchId,
			'PERIOD_YEAR' => $year,
			'PERIOD_MONTH' => $month,
			'AMOUNT' => round($amount, 2),
			'CURRENCY' => 'KZT',
			'DATE_MODIFY' => new DateTime(),
			'MODIFIED_BY' => $userId,
		];

		if ($existing) {
			$old = (float)$existing['AMOUNT'];
			BranchPlanTable::update((int)$existing['ID'], $fields);
			if ($old !== (float)$fields['AMOUNT']) {
				AuditService::log('branch_plan', (int)$existing['ID'], $branchId, null, $year, $month, 'AMOUNT', $old, $fields['AMOUNT'], $userId);
			}
			return;
		}

		$fields['DATE_CREATE'] = new DateTime();
		$fields['CREATED_BY'] = $userId;
		$res = BranchPlanTable::add($fields);
		if ($res->isSuccess()) {
			AuditService::log('branch_plan', (int)$res->getId(), $branchId, null, $year, $month, 'AMOUNT', 0, $fields['AMOUNT'], $userId);
		}
	}

	public static function getUserPlansMap(string $branchId, int $year, int $month): array
	{
		$map = [];
		$rows = UserPlanTable::getList([
			'filter' => [
				'=BRANCH_ID' => $branchId,
				'=PERIOD_YEAR' => $year,
				'=PERIOD_MONTH' => $month,
			],
			'select' => ['USER_ID', 'AMOUNT', 'ID'],
		]);
		while ($row = $rows->fetch()) {
			$map[(int)$row['USER_ID']] = [
				'id' => (int)$row['ID'],
				'amount' => (float)$row['AMOUNT'],
			];
		}
		return $map;
	}

	public static function saveUserPlan(string $branchId, int $targetUserId, int $year, int $month, float $amount, int $changedBy): void
	{
		$existing = UserPlanTable::getList([
			'filter' => [
				'=BRANCH_ID' => $branchId,
				'=USER_ID' => $targetUserId,
				'=PERIOD_YEAR' => $year,
				'=PERIOD_MONTH' => $month,
			],
			'limit' => 1,
		])->fetch();

		$fields = [
			'BRANCH_ID' => $branchId,
			'USER_ID' => $targetUserId,
			'PERIOD_YEAR' => $year,
			'PERIOD_MONTH' => $month,
			'AMOUNT' => round($amount, 2),
			'CURRENCY' => 'KZT',
			'DATE_MODIFY' => new DateTime(),
			'MODIFIED_BY' => $changedBy,
		];

		if ($existing) {
			$old = (float)$existing['AMOUNT'];
			UserPlanTable::update((int)$existing['ID'], $fields);
			if ($old !== (float)$fields['AMOUNT']) {
				AuditService::log('user_plan', (int)$existing['ID'], $branchId, $targetUserId, $year, $month, 'AMOUNT', $old, $fields['AMOUNT'], $changedBy);
			}
			return;
		}

		$fields['DATE_CREATE'] = new DateTime();
		$fields['CREATED_BY'] = $changedBy;
		$res = UserPlanTable::add($fields);
		if ($res->isSuccess()) {
			AuditService::log('user_plan', (int)$res->getId(), $branchId, $targetUserId, $year, $month, 'AMOUNT', 0, $fields['AMOUNT'], $changedBy);
		}
	}

	public static function importFromSaleTarget(string $branchId, int $year, int $month, int $changedBy): int
	{
		if (!class_exists('\Bitrix\Crm\Widget\SaleTarget\SaleTargetTable')) {
			return 0;
		}
		$branch = BranchConfig::getById($branchId);
		if (!$branch) {
			return 0;
		}
		$imported = 0;
		$users = BranchResolver::getBranchUsers((int)$branch['department_id']);
		foreach ($users as $user) {
			$userId = (int)$user['ID'];
			$row = \Bitrix\Crm\Widget\SaleTarget\SaleTargetTable::getList([
				'filter' => [
					'=USER_ID' => $userId,
					'=PERIOD_YEAR' => $year,
					'=PERIOD_MONTH' => $month,
				],
				'limit' => 1,
			])->fetch();
			if (!$row) {
				continue;
			}
			$amount = (float)($row['TARGET_AMOUNT'] ?? $row['AMOUNT'] ?? 0);
			if ($amount <= 0) {
				continue;
			}
			self::saveUserPlan($branchId, $userId, $year, $month, $amount, $changedBy);
			$imported++;
		}
		return $imported;
	}

	public static function buildDashboard(
		AccessService $access,
		string $branchId,
		int $year,
		int $month,
		?string $categoryFilter = null
	): array {
		$access->assertCanViewBranch($branchId);
		$branch = BranchConfig::getById($branchId);
		if (!$branch) {
			throw new \Bitrix\Main\ArgumentException('Unknown branch');
		}

		$branchPlan = self::getBranchPlan($branchId, $year, $month);
		$branchActual = ActualsService::fetchAggregated($branchId, $year, $month, $categoryFilter, null);
		$branchForecast = ForecastService::build($branchActual['sum'], $branchPlan, $year, $month);

		$allUsers = BranchResolver::getBranchUsers((int)$branch['department_id']);
		$userPlans = self::getUserPlansMap($branchId, $year, $month);

		$visibleUsers = [];
		foreach ($allUsers as $user) {
			$userId = (int)$user['ID'];
			if (!$access->canViewUser($branchId, $userId)) {
				continue;
			}
			$visibleUsers[] = $user;
		}

		$userIds = array_map(static fn($u) => (int)$u['ID'], $visibleUsers);
		$actualsMap = ActualsService::fetchManagersActuals($branchId, $year, $month, $categoryFilter, $userIds);

		$managers = [];
		$personalSum = 0.0;
		foreach ($visibleUsers as $user) {
			$userId = (int)$user['ID'];
			$plan = (float)($userPlans[$userId]['amount'] ?? 0);
			$personalSum += $plan;
			$actual = $actualsMap[$userId] ?? ['sum' => 0, 'count' => 0, 'avg' => 0];
			$forecast = ForecastService::build($actual['sum'], $plan, $year, $month);
			$managers[] = [
				'user_id' => $userId,
				'name' => $user['NAME'] !== '' ? $user['NAME'] : ('ID ' . $userId),
				'active' => $user['ACTIVE'] === 'Y',
				'plan' => $plan,
				'actual' => $actual['sum'],
				'deals_count' => $actual['count'],
				'avg_check' => $actual['avg'],
				'percent' => $forecast['percent'],
				'remaining' => $forecast['remaining'],
				'forecast' => $forecast['forecast'],
				'at_risk' => $forecast['at_risk'],
				'can_edit' => $access->canEditUserPlan($branchId, $userId),
			];
		}

		usort($managers, static fn($a, $b) => $b['actual'] <=> $a['actual']);

		$allocationDiff = round($branchPlan - $personalSum, 2);
		$canEdit = $access->canEditBranchPlans($branchId);
		$isPast = self::isPastPeriod($year, $month);

		return [
			'branch' => [
				'id' => $branchId,
				'name' => (string)$branch['name'],
				'plan' => $branchPlan,
				'actual' => $branchActual['sum'],
				'deals_count' => $branchActual['count'],
				'avg_check' => $branchActual['avg'],
				'percent' => $branchForecast['percent'],
				'remaining' => $branchForecast['remaining'],
				'forecast' => $branchForecast['forecast'],
				'at_risk' => $branchForecast['at_risk'],
				'personal_plans_sum' => round($personalSum, 2),
				'allocation_diff' => $allocationDiff,
				'allocation_warning' => abs($allocationDiff) >= 1,
			],
			'managers' => $managers,
			'permissions' => [
				'can_edit' => $canEdit && !$isPast,
				'is_admin' => $access->isAdmin(),
				'is_branch_head' => $access->isBranchHead($branchId),
				'is_past_period' => $isPast,
			],
			'category_options' => BranchConfig::categoryFilterOptions($branch),
		];
	}

	public static function isPastPeriod(int $year, int $month): bool
	{
		$nowY = (int)date('Y');
		$nowM = (int)date('n');
		return $year < $nowY || ($year === $nowY && $month < $nowM);
	}
}
