<?php
/**
 * Локальные credentials Green API по линиям ОЛ (CONFIG_ID / LINE).
 * Скопируй в green_api_instances.local.php и заполни из кабинета Green API / настроек коннектора.
 *
 * return [
 *     'default' => [
 *         'idInstance' => '1234567890',
 *         'apiTokenInstance' => 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
 *         'apiUrl' => 'https://api.green-api.com',
 *     ],
 *     'lines' => [
 *         31 => ['idInstance' => '...', 'apiTokenInstance' => '...'],
 *         35 => ['idInstance' => '...', 'apiTokenInstance' => '...'],
 *     ],
 * ];
 */

return [
	'default' => null,
	'lines' => [],
];
