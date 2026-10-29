<?php
/**
 * Vorige werkorders: component eerst, aanvullen met locatie, sortering en uitsluitingen.
 *
 * Run: php tests/previous_workorders_test.php
 */

require __DIR__ . '/../web/functions.php';

$assertions = 0;

function check(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
}

$wo = static fn(string $no, string $date, string $status = 'Gefactureerd'): array => ['No' => $no, 'Start_Date' => $date, 'Status' => $status];

$component = [$wo('CUR', '2026-10-29', 'Open'), $wo('4086655', '2019-03-02'), $wo('WO2610392', '2026-09-16')];
$location = [
    $wo('WO2610392', '2026-09-16'),
    $wo('L1', '2025-01-10'),
    $wo('L2', '2026-02-01'),
    $wo('L3', '2026-05-01', 'Geannuleerd'),
    $wo('L4', '2024-01-01'),
    $wo('L5', '2023-01-01'),
];
$result = merge_previous_workorders($component, $location, 'CUR');
check(array_column($result, 'No') === ['WO2610392', '4086655', 'L2', 'L1', 'L4'], 'volgorde/aanvulling: ' . implode(',', array_column($result, 'No')));
check(array_column($result, '_match') === ['component', 'component', 'locatie', 'locatie', 'locatie'], 'match-type');
check(merge_previous_workorders([], [], 'CUR') === [], 'leeg');
check(count(merge_previous_workorders(array_fill(0, 3, $wo('X', '2026-01-01')), [], 'CUR')) === 1, 'geen dubbelen');

$sevenComponent = [];
for ($i = 1; $i <= 7; $i++) {
    $sevenComponent[] = $wo('C' . $i, '2026-0' . $i . '-01');
}
$result = merge_previous_workorders($sevenComponent, $location, 'CUR');
check(array_column($result, 'No') === ['C7', 'C6', 'C5', 'C4', 'C3'], 'max 5, alleen component');

echo "OK ($assertions assertions)\n";
