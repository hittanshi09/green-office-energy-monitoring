<?php
declare(strict_types=1);

function go_defaults(): array
{
    return [
        'office_name' => 'Green Office', 'tariff' => 8, 'monthly_target' => 900,
        'monthly_budget' => 7200, 'emission_factor' => 0.71,
        'emission_source' => 'Demo assumption; replace with sourced factor',
        'floor_area' => 120, 'working_days' => 22, 'data_mode' => 'demo',
    ];
}

function go_date(string $value): ?DateTimeImmutable
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts)
        || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
        return null;
    }
    return DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC')) ?: null;
}

function go_reading_problem(array $reading): ?string
{
    if (!is_string($reading['reading_date'] ?? null) || !go_date($reading['reading_date'])) {
        return 'Enter a valid reading date as YYYY-MM-DD.';
    }
    $value = $reading['meter_reading'] ?? null;
    if ((!is_string($value) && !is_int($value) && !is_float($value))
        || !is_numeric($value) || !is_finite((float) $value)
        || (float) $value < 0 || (float) $value > 999999999) {
        return 'Meter reading must be between 0 and 999999999 kWh.';
    }
    if (!preg_match('/^\d+(?:\.\d{1,3})?$/D', (string) $value)) {
        return 'Meter reading must be a decimal with at most 3 decimal places.';
    }
    if (!in_array($reading['meter_reset'] ?? 0, [0, 1, '0', '1', false, true], true)) {
        return 'Meter reset must be 0 or 1.';
    }
    return null;
}

function go_ordered_readings(array $readings): array
{
    $rows = [];
    foreach ($readings as $index => $reading) {
        $rows[] = [
            'key' => isset($reading['reading_id']) ? 'id:' . $reading['reading_id'] : 'row:' . $index,
            'reading' => $reading,
        ];
    }
    usort($rows, static function (array $a, array $b): int {
        $a = $a['reading'];
        $b = $b['reading'];
        $dateA = is_string($a['reading_date'] ?? null) ? $a['reading_date'] : '';
        $dateB = is_string($b['reading_date'] ?? null) ? $b['reading_date'] : '';
        return strcmp($dateA, $dateB) ?: (($a['reading_id'] ?? 0) <=> ($b['reading_id'] ?? 0));
    });
    return $rows;
}

function go_reading_errors(array $readings): array
{
    $rows = go_ordered_readings($readings);
    $dates = [];
    foreach ($rows as $row) {
        $date = $row['reading']['reading_date'] ?? null;
        if (is_string($date) && go_date($date)) {
            $dates[$date] = ($dates[$date] ?? 0) + 1;
        }
    }
    $errors = [];
    $baseline = null;
    foreach ($rows as $row) {
        $reading = $row['reading'];
        $date = $reading['reading_date'] ?? '';
        $problem = go_reading_problem($reading);
        if (is_string($date) && ($dates[$date] ?? 0) > 1) {
            $errors[$row['key']] = 'Duplicate reading date; keep one verified reading for this date.';
            $baseline = null;
            continue;
        }
        if ($problem !== null) {
            $errors[$row['key']] = $problem;
            continue;
        }
        if (!empty($reading['meter_reset'])) {
            $baseline = $reading;
        } elseif ($baseline !== null && (float) $reading['meter_reading'] < (float) $baseline['meter_reading']) {
            $errors[$row['key']] = 'Meter reading decreased without a marked reset; verify this reading.';
        } else {
            $baseline = $reading;
        }
    }
    return $errors;
}

function go_validate_readings(array $readings): void
{
    $errors = go_reading_errors($readings);
    if ($errors !== []) {
        throw new InvalidArgumentException(reset($errors));
    }
}

function go_intervals(array $readings): array
{
    $errors = go_reading_errors($readings);
    $rows = [];
    $baseline = null;
    foreach (go_ordered_readings($readings) as $entry) {
        $reading = $entry['reading'];
        $date = is_string($reading['reading_date'] ?? null) ? $reading['reading_date'] : '';
        $row = array_merge($reading, [
            'start' => $baseline['reading_date'] ?? null, 'end' => $date,
            'days' => null, 'kwh' => null, 'daily' => null, 'issue' => $errors[$entry['key']] ?? null,
        ]);
        if ($baseline !== null && go_date($date)) {
            $row['days'] = (int) go_date($baseline['reading_date'])->diff(go_date($date))->format('%a');
        }
        if ($row['issue'] !== null) {
            if (str_starts_with($row['issue'], 'Duplicate reading date')) {
                $baseline = null;
            }
        } elseif (!empty($reading['meter_reset'])) {
            $row['issue'] = 'Meter reset: consumption since preceding reading is unknown.';
            $baseline = $reading;
        } else {
            if ($baseline !== null && $row['days'] > 0) {
                $row['kwh'] = round((float) $reading['meter_reading'] - (float) $baseline['meter_reading'], 3);
                $row['daily'] = $row['kwh'] / $row['days'];
            }
            $baseline = $reading;
        }
        $rows[] = $row;
    }
    return $rows;
}

function go_monthly_allowance(string $start, string $end, float $monthly): float
{
    $cursor = go_date($start);
    $finish = go_date($end);
    if (!$cursor || !$finish || $finish <= $cursor) {
        return 0.0;
    }
    $total = 0.0;
    while ($cursor < $finish) {
        $next = $cursor->modify('first day of next month');
        $boundary = $next < $finish ? $next : $finish;
        $days = (int) $cursor->diff($boundary)->format('%a');
        $total += $monthly * $days / (int) $cursor->format('t');
        $cursor = $boundary;
    }
    return $total;
}

function go_daily_change(?float $before, ?float $after): ?float
{
    return $before !== null && $before > 0 && $after !== null ? ($after - $before) / $before * 100 : null;
}

function go_summary(array $intervals, array $settings, string $from = '', string $to = ''): array
{
    if (($from !== '' && !go_date($from)) || ($to !== '' && !go_date($to))
        || ($from !== '' && $to !== '' && $from > $to)) {
        throw new InvalidArgumentException('Choose valid dates with the start on or before the end.');
    }
    $settings += go_defaults();
    $valid = [];
    $kwh = $target = $budget = 0.0;
    $days = $issues = 0;
    foreach ($intervals as $interval) {
        if ($interval['issue'] !== null && ($from === '' || $interval['end'] >= $from)
            && ($to === '' || $interval['end'] <= $to)) {
            $issues++;
        }
        // Cumulative readings cannot locate consumption within a partly selected interval.
        if ($interval['kwh'] === null || $interval['days'] <= 0
            || ($from !== '' && $interval['start'] < $from)
            || ($to !== '' && $interval['end'] > $to)) {
            continue;
        }
        $valid[] = $interval;
        $kwh += $interval['kwh'];
        $days += $interval['days'];
        $target += go_monthly_allowance($interval['start'], $interval['end'], (float) $settings['monthly_target']);
        $budget += go_monthly_allowance($interval['start'], $interval['end'], (float) $settings['monthly_budget']);
    }
    $count = count($valid);
    $latest = $count ? $valid[$count - 1] : null;
    return [
        'intervals' => $valid, 'kwh' => $kwh, 'cost' => $kwh * (float) $settings['tariff'],
        'co2' => $kwh * (float) $settings['emission_factor'], 'days' => $days,
        'daily' => $days ? $kwh / $days : null, 'latest' => $latest,
        'change' => $count >= 2 ? go_daily_change($valid[$count - 2]['daily'], $latest['daily']) : null,
        'target_kwh' => $target, 'budget' => $budget,
        'area_intensity' => (float) $settings['floor_area'] > 0 ? $kwh / (float) $settings['floor_area'] : null,
        'issues' => $issues,
    ];
}

function go_nonnegative_number(mixed $value): float
{
    if ((!is_int($value) && !is_float($value) && !is_string($value))
        || !is_numeric($value) || !is_finite((float) $value) || (float) $value < 0) {
        throw new InvalidArgumentException('Energy calculation inputs must be finite, non-negative numbers.');
    }
    return (float) $value;
}

function go_equipment_kwh(array $item): float
{
    return go_nonnegative_number($item['watts'] ?? null) * go_nonnegative_number($item['quantity'] ?? null)
        * go_nonnegative_number($item['hours_per_day'] ?? null) * go_nonnegative_number($item['days_per_month'] ?? null) / 1000;
}

function go_scenario(mixed $watts, mixed $newWatts, mixed $quantity, mixed $hours, mixed $newHours, mixed $days, mixed $tariff, mixed $factor): array
{
    $before = go_equipment_kwh(['watts' => $watts, 'quantity' => $quantity, 'hours_per_day' => $hours, 'days_per_month' => $days]);
    $after = go_equipment_kwh(['watts' => $newWatts, 'quantity' => $quantity, 'hours_per_day' => $newHours, 'days_per_month' => $days]);
    $saved = $before - $after;
    return [
        'before_kwh' => $before, 'after_kwh' => $after, 'saved_kwh' => $saved,
        'saved_cost' => $saved * go_nonnegative_number($tariff),
        'saved_co2' => $saved * go_nonnegative_number($factor),
    ];
}

function go_alerts(array $intervals, array $settings, string $today): array
{
    $date = go_date($today);
    if (!$date) {
        throw new InvalidArgumentException('Today must be a valid YYYY-MM-DD date.');
    }
    $settings += go_defaults();
    $summary = go_summary($intervals, $settings);
    $alerts = [];
    $lastDate = null;
    foreach ($intervals as $interval) {
        if (go_reading_problem($interval) === null && ($lastDate === null || $interval['end'] > $lastDate)) {
            $lastDate = $interval['end'];
        }
    }
    if ($lastDate === null) {
        $alerts[] = ['level' => 'info', 'title' => 'Add a starting reading', 'detail' => 'Two verified meter readings on different dates establish measured energy use.'];
    } else {
        $elapsed = (int) go_date($lastDate)->diff($date)->format('%r%a');
        if ($elapsed > 40) {
            $alerts[] = ['level' => 'warning', 'title' => 'Meter reading overdue', 'detail' => "Last recorded reading: $lastDate ($elapsed days ago). Add a new reading to refresh comparisons."];
        }
    }
    if ($summary['issues'] > 0) {
        $alerts[] = ['level' => 'warning', 'title' => 'Review gaps in meter data', 'detail' => $summary['issues'] . ' reading(s) have an issue or reset with unknown consumption. Review explanations in the meter log; these rows add no consumption to totals.'];
    }
    $latest = $summary['latest'];
    if ($latest === null && $lastDate !== null) {
        $alerts[] = ['level' => 'info', 'title' => 'More verified readings needed', 'detail' => 'No complete, usable interval yet. Add another reading after the current baseline or correct marked data.'];
    }
    if ($latest !== null) {
        $allowance = go_monthly_allowance($latest['start'], $latest['end'], (float) $settings['monthly_target']);
        if ($latest['kwh'] > $allowance) {
            $alerts[] = ['level' => 'warning', 'title' => 'Latest interval exceeds target', 'detail' => number_format($latest['daily'], 2) . ' kWh/day versus ' . number_format($allowance / $latest['days'], 2) . ' kWh/day allowed for ' . $latest['start'] . ' to ' . $latest['end'] . '. Target is prorated by covered calendar days.'];
        }
        if ($summary['change'] !== null && $summary['change'] > 20) {
            $alerts[] = ['level' => 'info', 'title' => 'Review increased daily usage', 'detail' => 'Daily average increased ' . number_format($summary['change'], 1) . '% versus the previous valid interval. Check occupancy, operating hours and equipment; this is a review signal, not proof of waste.'];
        }
    }
    return $alerts;
}

function go_action_comparison(array $intervals, string $completedDate): array
{
    $completed = go_date($completedDate);
    if (!$completed) {
        return ['before' => null, 'after' => null, 'change' => null];
    }
    $start = $completed->modify('-90 days')->format('Y-m-d');
    $end = $completed->modify('+90 days')->format('Y-m-d');
    $beforeKwh = $afterKwh = 0.0;
    $beforeDays = $afterDays = 0;
    foreach ($intervals as $interval) {
        if ($interval['kwh'] === null || $interval['days'] <= 0) {
            continue;
        }
        if ($interval['start'] >= $start && $interval['end'] <= $completedDate) {
            $beforeKwh += $interval['kwh'];
            $beforeDays += $interval['days'];
        } elseif ($interval['start'] >= $completedDate && $interval['end'] <= $end) {
            $afterKwh += $interval['kwh'];
            $afterDays += $interval['days'];
        }
    }
    $before = $beforeDays ? $beforeKwh / $beforeDays : null;
    $after = $afterDays ? $afterKwh / $afterDays : null;
    return ['before' => $before, 'after' => $after, 'change' => go_daily_change($before, $after)];
}
