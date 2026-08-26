<?php

namespace Artflowers\Salesplan\Internal;

use Artflowers\Salesplan\Config\BranchConfig;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Loader;

class AccessService
{
	protected int $userId;

	public function __construct(int $userId)
	{
		$this->userId = $userId;
	}

	public static function forCurrentUser(): self
	{
		global $USER;
		$userId = ($USER && is_object($USER) && method_exists($USER, 'GetID')) ? (int)$USER->GetID() : 0;
		return new self($userId);
	}

	public function getUserId(): int
	{
		return $this->userId;
	}

	public function isAdmin(): bool
	{
		if ($this->userId <= 0) {
			return false;
		}
		global $USER;
		if ($USER && is_object($USER) && (int)$USER->GetID() === $this->userId && method_exists($USER, 'IsAdmin') && $USER->IsAdmin()) {
			return true;
		}
		$groups = \CUser::GetUserGroup($this->userId);
		if (is_array($groups) && in_array(1, array_map('intval', $groups), true)) {
			return true;
		}
		if (Loader::includeModule('crm')) {
			if (class_exists('\CCrmPerms') && \CCrmPerms::IsAdmin($this->userId)) {
				return true;
			}
			try {
				$permissions = Container::getInstance()->getUserPermissions($this->userId);
				if (method_exists($permissions, 'isAdmin') && $permissions->isAdmin()) {
					return true;
				}
			} catch (\Throwable $e) {
			}
		}
		return false;
	}

	public function canReadSaleTarget(): bool
	{
		if ($this->userId <= 0) {
			return false;
		}
		if ($this->isAdmin()) {
			return true;
		}
		if (!Loader::includeModule('crm')) {
			return false;
		}
		try {
			$saleTarget = Container::getInstance()->getUserPermissions($this->userId)->saleTarget();
			if (method_exists($saleTarget, 'canRead')) {
				return (bool)$saleTarget->canRead();
			}
			if (method_exists($saleTarget, 'canView')) {
				return (bool)$saleTarget->canView();
			}
		} catch (\Throwable $e) {
		}
		$perms = \CCrmPerms::GetUserPermissions($this->userId);
		if ($perms && method_exists($perms, 'HavePerm')) {
			return (bool)$perms->HavePerm('SALETARGET', \CCrmPerms::PERM_READ);
		}
		return false;
	}

	public function canEditSaleTarget(): bool
	{
		if ($this->userId <= 0) {
			return false;
		}
		if ($this->isAdmin()) {
			return true;
		}
		if (!Loader::includeModule('crm')) {
			return false;
		}
		try {
			$saleTarget = Container::getInstance()->getUserPermissions($this->userId)->saleTarget();
			if (method_exists($saleTarget, 'canEdit')) {
				return (bool)$saleTarget->canEdit();
			}
			if (method_exists($saleTarget, 'canWrite')) {
				return (bool)$saleTarget->canWrite();
			}
		} catch (\Throwable $e) {
		}
		$perms = \CCrmPerms::GetUserPermissions($this->userId);
		if ($perms && method_exists($perms, 'HavePerm')) {
			return (bool)$perms->HavePerm('SALETARGET', \CCrmPerms::PERM_WRITE);
		}
		return false;
	}

	public function isBranchHead(string $branchId): bool
	{
		$branch = BranchConfig::getById($branchId);
		if (!$branch) {
			return false;
		}
		$deptId = (int)($branch['department_id'] ?? 0);
		return $deptId > 0 && BranchResolver::isDepartmentHead($this->userId, $deptId);
	}

	public function canViewBranch(string $branchId): bool
	{
		if (!$this->canReadSaleTarget()) {
			return false;
		}
		if ($this->isAdmin()) {
			return BranchConfig::getById($branchId) !== null;
		}
		if ($this->isBranchHead($branchId)) {
			return true;
		}
		$branch = BranchConfig::getById($branchId);
		if (!$branch) {
			return false;
		}
		$deptId = (int)($branch['department_id'] ?? 0);
		return $deptId > 0 && BranchResolver::isUserInDepartmentTree($this->userId, $deptId);
	}

	public function canEditBranchPlans(string $branchId): bool
	{
		if (!$this->canEditSaleTarget()) {
			return false;
		}
		return $this->isAdmin() || $this->isBranchHead($branchId);
	}

	public function canViewUser(string $branchId, int $targetUserId): bool
	{
		if ($targetUserId <= 0 || !$this->canViewBranch($branchId)) {
			return false;
		}
		if ($this->isAdmin() || $this->isBranchHead($branchId)) {
			return true;
		}
		return $this->userId === $targetUserId;
	}

	public function canEditUserPlan(string $branchId, int $targetUserId): bool
	{
		return $this->canEditBranchPlans($branchId);
	}

	public function getVisibleBranches(): array
	{
		$result = [];
		foreach (BranchConfig::getAll() as $branch) {
			if ($this->canViewBranch((string)$branch['id'])) {
				$result[] = $branch;
			}
		}
		return $result;
	}

	public function resolveDefaultBranchId(): ?string
	{
		if ($this->isAdmin()) {
			$all = BranchConfig::getAll();
			$first = reset($all);
			return $first ? (string)$first['id'] : null;
		}
		$branch = BranchResolver::resolveBranchForUser($this->userId);
		return $branch ? (string)$branch['id'] : null;
	}

	public function assertCanViewBranch(string $branchId): void
	{
		if (!$this->canViewBranch($branchId)) {
			throw new \Bitrix\Main\SystemException('Access denied', 403);
		}
	}

	public function assertCanEditBranch(string $branchId): void
	{
		if (!$this->canEditBranchPlans($branchId)) {
			throw new \Bitrix\Main\SystemException('Access denied', 403);
		}
	}

	public function assertCanViewUser(string $branchId, int $targetUserId): void
	{
		if (!$this->canViewUser($branchId, $targetUserId)) {
			throw new \Bitrix\Main\SystemException('Access denied', 403);
		}
	}
}
