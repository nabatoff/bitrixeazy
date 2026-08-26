<?php

Bitrix\Main\Loader::registerAutoLoadClasses('artflowers.salesplan', [
	'Artflowers\\Salesplan\\Config\\BranchConfig' => 'lib/Config/BranchConfig.php',
	'Artflowers\\Salesplan\\Model\\BranchPlanTable' => 'lib/Model/BranchPlanTable.php',
	'Artflowers\\Salesplan\\Model\\UserPlanTable' => 'lib/Model/UserPlanTable.php',
	'Artflowers\\Salesplan\\Model\\AuditTable' => 'lib/Model/AuditTable.php',
	'Artflowers\\Salesplan\\Internal\\AccessService' => 'lib/Internal/AccessService.php',
	'Artflowers\\Salesplan\\Internal\\BranchResolver' => 'lib/Internal/BranchResolver.php',
	'Artflowers\\Salesplan\\Internal\\ActualsService' => 'lib/Internal/ActualsService.php',
	'Artflowers\\Salesplan\\Internal\\PlanRepository' => 'lib/Internal/PlanRepository.php',
	'Artflowers\\Salesplan\\Internal\\AuditService' => 'lib/Internal/AuditService.php',
	'Artflowers\\Salesplan\\Internal\\ForecastService' => 'lib/Internal/ForecastService.php',
	'Artflowers\\Salesplan\\Controller\\Api' => 'lib/Controller/Api.php',
]);
