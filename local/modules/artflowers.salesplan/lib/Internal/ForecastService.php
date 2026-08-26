<?php

namespace Artflowers\Salesplan\Internal;

class ForecastService
{
	public static function build(float $actual, float $plan, int $year, int $month): array
	{
		$month = max(1, min(12, $month));
		$now = time();
		$daysInMonth = (int)date('t', mktime(0, 0, 0, $month, 1, $year));
		$currentDay = ((int)date('Y') === $year && (int)date('n') === $month)
			? (int)date('j')
			: $daysInMonth;
		$remaining = max(0, $plan - $actual);
		$percent = $plan > 0 ? round(($actual / $plan) * 100, 1) : 0.0;
		$forecast = 0.0;
		$atRisk = false;
		if ($currentDay > 0 && $daysInMonth > 0) {
			$forecast = round(($actual / $currentDay) * $daysInMonth, 2);
			if ($plan > 0 && $forecast < $plan * 0.9 && $currentDay >= 5) {
				$atRisk = true;
			}
		}
		return [
			'percent' => $percent,
			'remaining' => round($remaining, 2),
			'forecast' => $forecast,
			'at_risk' => $atRisk,
			'day' => $currentDay,
			'days_in_month' => $daysInMonth,
		];
	}
}
