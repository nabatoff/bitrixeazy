<?php

namespace Artflowers\Salesplan\Internal;

use Artflowers\Salesplan\Config\BranchConfig;
use Bitrix\Main\Loader;

class BranchResolver
{
	public static function getUserDepartmentIds(int $userId): array
	{
		if ($userId <= 0) {
			return [];
		}
		$ids = [];
		if (Loader::includeModule('intranet') && class_exists('\CIntranetUtils')) {
			$depts = \CIntranetUtils::GetUserDepartments($userId);
			if (is_array($depts)) {
				foreach ($depts as $id) {
					$id = (int)$id;
					if ($id > 0) {
						$ids[] = $id;
					}
				}
			}
		}
		return array_values(array_unique($ids));
	}

	public static function getSubDepartmentIds(int $rootDepartmentId): array
	{
		$result = [$rootDepartmentId];
		if ($rootDepartmentId <= 0) {
			return [];
		}
		if (Loader::includeModule('crm')) {
			try {
				$queries = new \Bitrix\Crm\Integration\HumanResources\DepartmentQueries();
				$subs = $queries->getSubDepartments($rootDepartmentId);
				if (is_array($subs)) {
					foreach ($subs as $id) {
						$id = (int)$id;
						if ($id > 0) {
							$result[] = $id;
						}
					}
				}
			} catch (\Throwable $e) {
			}
		}
		if (Loader::includeModule('iblock') && Loader::includeModule('intranet')) {
			$iblockId = (int)\COption::GetOptionInt('intranet', 'iblock_structure', 0);
			if ($iblockId > 0) {
				$rs = \CIBlockSection::GetList(
					['LEFT_MARGIN' => 'ASC'],
					['IBLOCK_ID' => $iblockId, 'ACTIVE' => 'Y', 'SECTION_ID' => $rootDepartmentId],
					false,
					['ID']
				);
				while ($row = $rs->Fetch()) {
					$childId = (int)$row['ID'];
					$result = array_merge($result, self::getSubDepartmentIds($childId));
				}
			}
		}
		return array_values(array_unique(array_map('intval', $result)));
	}

	public static function isUserInDepartmentTree(int $userId, int $rootDepartmentId): bool
	{
		$userDepts = self::getUserDepartmentIds($userId);
		if ($userDepts === []) {
			return false;
		}
		$tree = self::getSubDepartmentIds($rootDepartmentId);
		foreach ($userDepts as $deptId) {
			if (in_array($deptId, $tree, true)) {
				return true;
			}
		}
		return false;
	}

	public static function isDepartmentHead(int $userId, int $departmentId): bool
	{
		if ($userId <= 0 || $departmentId <= 0 || !Loader::includeModule('iblock')) {
			return false;
		}
		$iblockId = (int)\COption::GetOptionInt('intranet', 'iblock_structure', 0);
		if ($iblockId <= 0) {
			return false;
		}
		$rs = \CIBlockSection::GetList([], ['IBLOCK_ID' => $iblockId, 'ID' => $departmentId], false, ['UF_HEAD']);
		if ($row = $rs->Fetch()) {
			return (int)($row['UF_HEAD'] ?? 0) === $userId;
		}
		return false;
	}

	public static function resolveBranchForUser(int $userId): ?array
	{
		foreach (BranchConfig::getAll() as $branch) {
			$deptId = (int)($branch['department_id'] ?? 0);
			if ($deptId > 0 && self::isUserInDepartmentTree($userId, $deptId)) {
				return $branch;
			}
		}
		return null;
	}

	public static function getBranchUsers(int $rootDepartmentId): array
	{
		$users = [];
		if ($rootDepartmentId <= 0) {
			return $users;
		}

		$userIds = [];
		if (Loader::includeModule('crm')) {
			try {
				$queries = new \Bitrix\Crm\Integration\HumanResources\DepartmentQueries();
				$userIds = $queries->getUsersByDepartmentId($rootDepartmentId, true);
			} catch (\Throwable $e) {
			}
		}

		if (!is_array($userIds) || $userIds === []) {
			if (Loader::includeModule('intranet') && class_exists('\CIntranetUtils')) {
				$deptIds = self::getSubDepartmentIds($rootDepartmentId);
				foreach ($deptIds as $deptId) {
					$employees = \CIntranetUtils::GetDepartmentEmployees($deptId, true, 'Y', 'Y');
					if (!is_array($employees)) {
						continue;
					}
					foreach ($employees as $user) {
						$userIds[] = (int)($user['ID'] ?? $user['USER_ID'] ?? 0);
					}
				}
			}
		}

		$userIds = array_values(array_unique(array_filter(array_map('intval', (array)$userIds))));
		if ($userIds === []) {
			$deptIds = self::getSubDepartmentIds($rootDepartmentId);
			foreach ($deptIds as $deptId) {
				$rs = \CUser::GetList(
					'id',
					'asc',
					['ACTIVE' => 'Y', 'UF_DEPARTMENT' => $deptId],
					['FIELDS' => ['ID']]
				);
				while ($row = $rs->Fetch()) {
					$userIds[] = (int)$row['ID'];
				}
			}
			$userIds = array_values(array_unique(array_filter($userIds)));
		}
		foreach ($userIds as $userId) {
			if ($userId <= 0) {
				continue;
			}
			$rs = \CUser::GetByID($userId);
			if ($row = $rs->Fetch()) {
				$users[$userId] = [
					'ID' => $userId,
					'NAME' => trim((string)($row['NAME'] ?? '') . ' ' . (string)($row['LAST_NAME'] ?? '')),
					'ACTIVE' => (string)($row['ACTIVE'] ?? 'Y'),
				];
			}
		}
		return array_values($users);
	}
}
