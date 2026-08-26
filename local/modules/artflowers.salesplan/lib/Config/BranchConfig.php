<?php

namespace Artflowers\Salesplan\Config;

use Bitrix\Main\Config\Option;

class BranchConfig
{
	public const MODULE_ID = 'artflowers.salesplan';

	public static function defaultBranches(): array
	{
		return [
			'almaty' => [
				'id' => 'almaty',
				'name' => 'Алматы',
				'department_id' => 237,
				'category_ids' => [15, 16],
				'category_labels' => [
					15 => 'Срезанные',
					16 => 'Комнатные',
				],
			],
			'astana' => [
				'id' => 'astana',
				'name' => 'Астана',
				'department_id' => 236,
				'category_ids' => [17, 18],
				'category_labels' => [
					17 => 'Срезанные',
					18 => 'Комнатные',
				],
			],
			'uralsk' => [
				'id' => 'uralsk',
				'name' => 'Уральск',
				'department_id' => 238,
				'category_ids' => [19, 20],
				'category_labels' => [
					19 => 'Срезанные',
					20 => 'Комнатные',
				],
			],
		];
	}

	public static function getAll(): array
	{
		$raw = Option::get(self::MODULE_ID, 'branches_json', '');
		if ($raw === '') {
			return self::defaultBranches();
		}
		$data = json_decode($raw, true);
		if (!is_array($data) || $data === []) {
			return self::defaultBranches();
		}
		return $data;
	}

	public static function saveAll(array $branches): void
	{
		Option::set(self::MODULE_ID, 'branches_json', json_encode($branches, JSON_UNESCAPED_UNICODE));
	}

	public static function getById(string $branchId): ?array
	{
		$branchId = trim($branchId);
		if ($branchId === '') {
			return null;
		}
		$all = self::getAll();
		return $all[$branchId] ?? null;
	}

	public static function getByDepartmentId(int $departmentId): ?array
	{
		if ($departmentId <= 0) {
			return null;
		}
		foreach (self::getAll() as $branch) {
			if ((int)($branch['department_id'] ?? 0) === $departmentId) {
				return $branch;
			}
		}
		return null;
	}

	public static function getByCategoryId(int $categoryId): ?array
	{
		foreach (self::getAll() as $branch) {
			$ids = array_map('intval', (array)($branch['category_ids'] ?? []));
			if (in_array($categoryId, $ids, true)) {
				return $branch;
			}
		}
		return null;
	}

	public static function categoryFilterOptions(array $branch): array
	{
		$labels = (array)($branch['category_labels'] ?? []);
		$options = [['id' => 'all', 'name' => 'Все']];
		foreach ((array)($branch['category_ids'] ?? []) as $catId) {
			$catId = (int)$catId;
			$options[] = [
				'id' => (string)$catId,
				'name' => (string)($labels[$catId] ?? $labels[(string)$catId] ?? ('Воронка ' . $catId)),
			];
		}
		return $options;
	}

	public static function resolveCategoryIds(array $branch, ?string $filter): array
	{
		$all = array_map('intval', (array)($branch['category_ids'] ?? []));
		if ($filter === null || $filter === '' || $filter === 'all') {
			return $all;
		}
		$catId = (int)$filter;
		if ($catId > 0 && in_array($catId, $all, true)) {
			return [$catId];
		}
		return $all;
	}
}
