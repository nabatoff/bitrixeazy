<?php

namespace Artflowers\Salesplan\Controller;

use Artflowers\Salesplan\Config\BranchConfig;
use Artflowers\Salesplan\Internal\AccessService;
use Artflowers\Salesplan\Internal\ActualsService;
use Artflowers\Salesplan\Internal\AuditService;
use Artflowers\Salesplan\Internal\PlanRepository;
use Bitrix\Main\Context;
use Bitrix\Main\Loader;
use Bitrix\Main\Web\Json;

class Api
{
	public static function handle(): void
	{
		global $USER;
		header('Content-Type: application/json; charset=utf-8');

		if (!$USER || !is_object($USER) || !$USER->IsAuthorized()) {
			self::error('Unauthorized', 401);
		}
		if (!Loader::includeModule('artflowers.salesplan') || !Loader::includeModule('crm')) {
			self::error('Module not available', 500);
		}

		$request = Context::getCurrent()->getRequest();
		if (!check_bitrix_sessid()) {
			self::error('Invalid sessid', 403);
		}

		$action = (string)$request->getPost('action');
		$access = AccessService::forCurrentUser();
		if (!$access->canReadSaleTarget()) {
			self::error('Access denied', 403);
		}

		try {
			switch ($action) {
				case 'getDashboard':
					self::ok(self::getDashboard($access, $request));
					break;
				case 'savePlans':
					self::ok(self::savePlans($access, $request));
					break;
				case 'getAudit':
					self::ok(self::getAudit($access, $request));
					break;
				case 'importSaleTarget':
					self::ok(self::importSaleTarget($access, $request));
					break;
				case 'saveBranchConfig':
					self::ok(self::saveBranchConfig($access, $request));
					break;
				default:
					self::error('Unknown action', 400);
			}
		} catch (\Throwable $e) {
			self::error($e->getMessage(), (int)$e->getCode() ?: 500);
		}
	}

	protected static function getDashboard(AccessService $access, $request): array
	{
		[$branchId, $year, $month, $category] = self::parsePeriodRequest($access, $request, true);
		return PlanRepository::buildDashboard($access, $branchId, $year, $month, $category);
	}

	protected static function savePlans(AccessService $access, $request): array
	{
		[$branchId, $year, $month] = self::parsePeriodRequest($access, $request, false);
		$access->assertCanEditBranch($branchId);
		if (PlanRepository::isPastPeriod($year, $month) && !$access->isAdmin()) {
			throw new \Bitrix\Main\SystemException('Past period is read-only', 403);
		}

		$branchPlan = $request->getPost('branch_plan');
		if ($branchPlan !== null && $branchPlan !== '') {
			PlanRepository::saveBranchPlan($branchId, $year, $month, (float)$branchPlan, $access->getUserId());
		}

		$userPlans = $request->getPost('user_plans');
		if (is_string($userPlans) && $userPlans !== '') {
			$userPlans = Json::decode($userPlans);
		}
		if (is_array($userPlans)) {
			foreach ($userPlans as $userId => $amount) {
				$userId = (int)$userId;
				if ($userId <= 0) {
					continue;
				}
				$access->assertCanViewUser($branchId, $userId);
				PlanRepository::saveUserPlan($branchId, $userId, $year, $month, (float)$amount, $access->getUserId());
			}
		}

		ActualsService::clearCache();
		$category = (string)$request->getPost('category') ?: 'all';
		return PlanRepository::buildDashboard($access, $branchId, $year, $month, $category);
	}

	protected static function getAudit(AccessService $access, $request): array
	{
		[$branchId, $year, $month] = self::parsePeriodRequest($access, $request, true);
		if (!$access->isAdmin()) {
			throw new \Bitrix\Main\SystemException('Access denied', 403);
		}
		return ['items' => AuditService::getForPeriod($branchId, $year, $month)];
	}

	protected static function importSaleTarget(AccessService $access, $request): array
	{
		[$branchId, $year, $month] = self::parsePeriodRequest($access, $request, false);
		$access->assertCanEditBranch($branchId);
		$count = PlanRepository::importFromSaleTarget($branchId, $year, $month, $access->getUserId());
		ActualsService::clearCache();
		return ['imported' => $count];
	}

	protected static function saveBranchConfig(AccessService $access, $request): array
	{
		if (!$access->isAdmin()) {
			throw new \Bitrix\Main\SystemException('Access denied', 403);
		}
		$raw = (string)$request->getPost('branches_json');
		$data = Json::decode($raw);
		if (!is_array($data) || $data === []) {
			throw new \Bitrix\Main\ArgumentException('Invalid branches_json');
		}
		BranchConfig::saveAll($data);
		ActualsService::clearCache();
		return ['ok' => true, 'branches' => BranchConfig::getAll()];
	}

	protected static function parsePeriodRequest(AccessService $access, $request, bool $allowManagerSingleBranch): array
	{
		$year = (int)$request->getPost('year');
		$month = (int)$request->getPost('month');
		if ($year < 2000 || $month < 1 || $month > 12) {
			$year = (int)date('Y');
			$month = (int)date('n');
		}
		$branchId = trim((string)$request->getPost('branch_id'));
		if ($branchId === '') {
			$branchId = (string)$access->resolveDefaultBranchId();
		}
		if ($branchId === '') {
			throw new \Bitrix\Main\ArgumentException('Branch is required');
		}
		$access->assertCanViewBranch($branchId);
		if (!$access->isAdmin() && !$allowManagerSingleBranch) {
			// branch head only own branch — already enforced by assertCanViewBranch
		}
		$category = (string)$request->getPost('category');
		if ($category === '') {
			$category = 'all';
		}
		return [$branchId, $year, $month, $category];
	}

	protected static function ok(array $data): void
	{
		echo Json::encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
		exit;
	}

	protected static function error(string $message, int $code = 400): void
	{
		http_response_code($code >= 400 ? $code : 400);
		echo Json::encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
		exit;
	}
}
