<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/energy.php';
require_once __DIR__ . '/lib/store.php';

// Exercises production calculations without opening a database or changing office data.
$failures = [];
$checks = 0;
function expect(string $label, mixed $actual, mixed $expected): void
{
    global $failures, $checks;
    $checks++;
    $equal = is_float($expected) ? is_numeric($actual) && abs((float) $actual - $expected) < 0.000001 : $actual === $expected;
    if (!$equal) {
        $failures[] = "$label: expected " . json_encode($expected) . ', got ' . json_encode($actual);
    }
}
function expectInvalid(string $label, callable $run): void
{
    try {
        $run();
        expect($label, false, true);
    } catch (InvalidArgumentException) {
        expect($label, true, true);
    }
}
function reading(int $id, string $date, mixed $value, int $reset = 0): array
{
    return ['reading_id' => $id, 'reading_date' => $date, 'meter_reading' => $value, 'meter_reset' => $reset];
}

$settings = go_defaults();
$normal = go_intervals([
    reading(9, '2025-06-01', '13790.375'), reading(1, '2025-04-01', '12000.125'),
    reading(5, '2025-05-01', '12850.375'),
]);
$summary = go_summary($normal, $settings);
expect('sort dates, preserve sparse IDs', array_column($normal, 'reading_id'), [1, 5, 9]);
expect('decimal meter usage', $normal[1]['kwh'], 850.25);
expect('first baseline has no measured usage', $normal[0]['kwh'], null);
expect('actual interval days', array_column($normal, 'days'), [null, 30, 31]);
expect('total measured use', $summary['kwh'], 1790.25);
expect('cost estimate uses configured rate', $summary['cost'], 14322.0);
expect('emissions estimate', $summary['co2'], 1271.0775);
expect('only covered days counted', $summary['days'], 61);
expect('daily comparison, not unequal month totals', $summary['change'], ((940 / 31) / (850.25 / 30) - 1) * 100);
expect('two full calendar months target', $summary['target_kwh'], 1800.0);
expect('two full calendar months budget', $summary['budget'], 14400.0);
expect('period area intensity', $summary['area_intensity'], 1790.25 / 120);

$leap = go_intervals([reading(1, '2024-01-16', 10), reading(2, '2024-03-01', 460)]);
$leapSummary = go_summary($leap, $settings);
expect('leap-year coverage days', $leapSummary['days'], 45);
expect('leap-year prorated target', $leapSummary['target_kwh'], 900 * (16 / 31 + 1));
expect('leap-year full February allowance', go_monthly_allowance('2024-02-01', '2024-03-01', 900), 900.0);
expect('year boundary allowance', go_monthly_allowance('2025-12-31', '2026-01-02', 900), 1800 / 31);
expect('zero-length allowance', go_monthly_allowance('2025-01-01', '2025-01-01', 900), 0.0);
expect('partial selected interval excluded', go_summary($leap, $settings, '2024-02-01', '2024-03-01')['kwh'], 0.0);
expect('full selected interval included', go_summary($leap, $settings, '2024-01-16', '2024-03-01')['kwh'], 450.0);
expect('filter excludes interval extending beyond end', go_summary($normal, $settings, '', '2025-05-15')['kwh'], 850.25);
expectInvalid('invalid filter rejected', fn() => go_summary($normal, $settings, '2025-02-30'));
expectInvalid('reversed filter rejected', fn() => go_summary($normal, $settings, '2025-06-01', '2025-05-01'));

$typo = go_intervals([
    reading(1, '2025-01-01', 1000), reading(2, '2025-02-01', 100), reading(3, '2025-03-01', 1300),
]);
expect('unexplained decrease excluded', $typo[1]['kwh'], null);
expect('decrease does not inflate next usage', $typo[2]['kwh'], 300.0);
expect('recovery measures from last verified baseline', $typo[2]['start'], '2025-01-01');
expect('recovered gap retains all calendar days', $typo[2]['days'], 59);
expect('gap cannot be partially assigned to filter', go_summary($typo, $settings, '2025-02-01', '2025-03-01')['kwh'], 0.0);
expect('typo flagged', go_summary($typo, $settings)['issues'], 1);
$unresolved = go_intervals([reading(1, '2025-01-01', 1000), reading(2, '2025-02-01', 100), reading(3, '2025-03-01', 300)]);
expect('unresolved lower sequence contributes no energy', go_summary($unresolved, $settings)['kwh'], 0.0);

$reset = [reading(1, '2025-01-01', 1000), reading(2, '2025-02-01', 100, 1), reading(3, '2025-03-01', 400)];
go_validate_readings($reset);
$resetIntervals = go_intervals($reset);
expect('marked reset excluded', $resetIntervals[1]['kwh'], null);
expect('reset establishes new baseline', $resetIntervals[2]['kwh'], 300.0);
expect('reset gap excluded from covered days', go_summary($resetIntervals, $settings)['days'], 28);
expect('reset warning is not validation error', go_reading_errors($reset), []);

$duplicates = go_intervals([
    reading(1, '2025-01-01', 100), reading(3, '2025-02-01', 200), reading(2, '2025-02-01', 180),
    reading(4, '2025-03-01', 300), reading(5, '2025-04-01', 400),
]);
expect('duplicate dates sort by ID', array_column($duplicates, 'reading_id'), [1, 2, 3, 4, 5]);
expect('intervals touching duplicates excluded', array_column($duplicates, 'kwh'), [null, null, null, null, 100.0]);
expect('both duplicate rows flagged', go_summary($duplicates, $settings)['issues'], 2);
expect('allowance excludes unknown duplicate gap', go_summary($duplicates, $settings)['target_kwh'], 900.0);
expect('duplicate invalid values also clear baseline', go_summary(go_intervals([
    reading(1, '2025-01-01', 100), reading(2, '2025-02-01', -1), reading(3, '2025-02-01', -2), reading(4, '2025-03-01', 300),
]), $settings)['kwh'], 0.0);

foreach ([['2025-02-29', 2], ['0000-01-01', 2], ['2025-1-01', 2], ['2025-01-01', -1],
    ['2025-01-01', INF], ['2025-01-01', NAN], ['2025-01-01', '1e3'], ['2025-01-01', '1.0001'],
    ['2025-01-01', '999999999.001'], ['2025-01-01', ''], ['2025-01-01', null]] as $index => [$date, $value]) {
    expectInvalid('invalid reading rejected ' . $index, fn() => go_validate_readings([reading(1, $date, $value)]));
}
expectInvalid('duplicate input rejected', fn() => go_validate_readings([reading(1, '2025-01-01', 100), reading(2, '2025-01-01', 100)]));
expectInvalid('decreasing input rejected', fn() => go_validate_readings([reading(1, '2025-01-01', 100), reading(2, '2025-02-01', 99)]));
expectInvalid('invalid reset flag rejected', fn() => go_validate_readings([['reading_date' => '2025-01-01', 'meter_reading' => 1, 'meter_reset' => 'yes']]));
go_validate_readings([reading(1, '2024-02-29', '999999999.000')]);
expect('valid maximum and leap day', true, true);
expect('repair errors preserve identity', array_keys(go_reading_errors([reading(42, '2025-02-30', 10)])), ['id:42']);
expect('float decimals accepted', go_reading_problem(reading(1, '2025-01-01', 0.001)), null);

$empty = go_summary([], $settings);
expect('empty total', $empty['kwh'], 0.0);
expect('empty daily unknown', $empty['daily'], null);
expect('empty latest unknown', $empty['latest'], null);
expect('empty change unknown', $empty['change'], null);
$invalid = go_summary(go_intervals([reading(1, 'invalid', 100), reading(2, '2025-01-01', -10)]), $settings);
expect('all-invalid readings have no measured use', $invalid['kwh'], 0.0);
expect('all-invalid readings flagged', $invalid['issues'], 2);
expect('zero area protected', go_summary($normal, array_replace($settings, ['floor_area' => 0]))['area_intensity'], null);
$zero = go_summary(go_intervals([reading(1, '2025-01-01', 0), reading(2, '2025-02-01', 0), reading(3, '2025-03-01', 100)]), $settings);
expect('zero previous daily protected', $zero['change'], null);

$scenario = go_scenario(60, 9, 20, 8, 8, 22, 8, 0.71);
expect('lighting proposal monthly savings', $scenario['saved_kwh'], 179.52);
expect('lighting proposal cost savings', $scenario['saved_cost'], 1436.16);
expect('lighting proposal emissions savings', $scenario['saved_co2'], 127.4592);
expect('zero equipment estimate', go_equipment_kwh(['watts' => 0, 'quantity' => 20, 'hours_per_day' => 8, 'days_per_month' => 22]), 0.0);
expect('proposal may increase consumption', go_scenario(9, 60, 20, 8, 8, 22, 8, 0.71)['saved_kwh'], -179.52);
expectInvalid('negative simulator input rejected', fn() => go_scenario(60, -1, 20, 8, 8, 22, 8, 0.71));

$alertReadings = go_intervals([reading(1, '2025-01-01', 0), reading(2, '2025-02-01', 310), reading(3, '2025-03-01', 1710)]);
$alertTitles = array_column(go_alerts($alertReadings, $settings, '2025-04-11'), 'title');
expect('stale reading alert', in_array('Meter reading overdue', $alertTitles, true), true);
expect('calendar-normalized target alert', in_array('Latest interval exceeds target', $alertTitles, true), true);
expect('daily increase review alert', in_array('Review increased daily usage', $alertTitles, true), true);
expect('exactly 40 days not overdue', in_array('Meter reading overdue', array_column(go_alerts($alertReadings, $settings, '2025-04-10'), 'title'), true), false);
expect('empty readings have onboarding alert', go_alerts([], $settings, '2025-01-01')[0]['title'], 'Add a starting reading');
expect('unknown intervals produce data alert', in_array('Review gaps in meter data', array_column(go_alerts($resetIntervals, $settings, '2025-03-01'), 'title'), true), true);

$actionReadings = go_intervals([
    reading(1, '2024-12-01', 0), reading(2, '2025-01-01', 310), reading(3, '2025-01-11', 410),
    reading(4, '2025-02-01', 830), reading(5, '2025-03-01', 1110), reading(6, '2025-04-01', 1265),
    reading(7, '2025-06-01', 8000),
]);
$comparison = go_action_comparison($actionReadings, '2025-02-01');
expect('before uses weighted daily average', $comparison['before'], 830 / 62);
expect('after uses weighted daily average within 90 days', $comparison['after'], 435 / 59);
expect('observed change calculation', $comparison['change'], ((435 / 59) / (830 / 62) - 1) * 100);
expect('straddling action intervals excluded', go_action_comparison($leap, '2024-02-01'), ['before' => null, 'after' => null, 'change' => null]);
expect('invalid completion date unknown', go_action_comparison($normal, 'invalid'), ['before' => null, 'after' => null, 'change' => null]);
expect('no action data unknown', go_action_comparison([], '2025-02-01'), ['before' => null, 'after' => null, 'change' => null]);

$legacy = [reading(1, '2025-01-01', 1000), reading(2, '2025-02-01', 100), reading(3, '2025-03-01', 200)];
$partialRepair = [reading(1, '2025-01-01', 1000), reading(2, '2025-02-01', 1100), reading(3, '2025-03-01', 200)];
go_accept_readings($legacy, $partialRepair, true);
expect('partial legacy repair accepted', true, true);
expectInvalid('partial repair requires correction operation', fn() => go_accept_readings($legacy, $partialRepair));
expectInvalid('unchanged legacy faults rejected', fn() => go_accept_readings($legacy, $legacy, true));
expectInvalid('repair introducing new issue rejected', fn() => go_accept_readings($legacy, [reading(1, '2025-01-01', -1), reading(2, '2025-02-01', 100), reading(3, '2025-03-01', 200)], true));
expectInvalid('deleting reset cannot corrupt later interval', fn() => go_accept_readings($reset, [$reset[0], $reset[2]], true));
$postBefore = $_POST;
$_POST = ['decimal' => '10.125', 'whole' => '22', 'trimmed' => ' 8.50 ', 'zero' => '0',
    'fractional' => '2.5', 'negative' => '-1', 'large' => '1001', 'extra_precision' => '1.0001',
    'exponent' => '1e2', 'array' => ['1'], 'blank' => ''];
expect('form decimal preserved', go_number('decimal', 0, 100), '10.125');
expect('form integer accepted', go_number('whole', 1, 31, 0), '22');
expect('form whitespace trimmed', go_number('trimmed', 0, 100, 2), '8.50');
expect('form zero accepted', go_number('zero', 0, 100), '0');
expectInvalid('form fraction rejected for integer', fn() => go_number('fractional', 0, 100, 0));
expectInvalid('form negative rejected', fn() => go_number('negative', 0, 100));
expectInvalid('form maximum enforced', fn() => go_number('large', 0, 1000));
expectInvalid('form precision enforced', fn() => go_number('extra_precision', 0, 100));
expectInvalid('form scientific notation rejected', fn() => go_number('exponent', 0, 1000));
expectInvalid('form arrays rejected', fn() => go_number('array', 0, 100));
expectInvalid('blank required number rejected', fn() => go_number('blank', 0, 100));
$_POST = $postBefore;
expect('CSV ordinary text unchanged', go_csv_cell('Meeting room'), 'Meeting room');
expect('CSV equals escaped', go_csv_cell('=1+1'), "'=1+1");
expect('CSV whitespace formula escaped', go_csv_cell("\t+1"), "'\t+1");
expect('CSV at escaped', go_csv_cell('@SUM(A1:A2)'), "'@SUM(A1:A2)");
expect('CSV minus escaped', go_csv_cell('-1'), "'-1");

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=UTF-8');
}
if ($failures) {
    http_response_code(500);
    echo "FAILED ($checks checks):\n - " . implode("\n - ", $failures) . "\n";
    exit(1);
}
echo "OK - $checks checks passed; no database data changed.\n";
