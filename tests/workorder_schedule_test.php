<?php
/**
 * Werkorder Start_Date/End_Date: elke dag van de reeks, terugval zonder geldige einddatum.
 *
 * Run: php tests/workorder_schedule_test.php
 */

require __DIR__ . '/../web/functions.php';

$assertions = 0;

function fail(string $message): void
{
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

function check(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fail($message);
    }
}

function same($expected, $actual, string $message): void
{
    check($expected === $actual, $message . ' (verwacht ' . var_export($expected, true) . ', kreeg ' . var_export($actual, true) . ')');
}

function ymd(string $value): DateTimeImmutable
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
    if (!($date instanceof DateTimeImmutable)) {
        fail('ongeldige testdatum ' . $value);
    }

    return $date;
}

$partial = [
    'No' => 'WO2601071',
    'Start_Date' => '2026-10-08',
    'Start_Time' => '16:00:00',
    'End_Date' => '2026-10-09',
    'End_Time' => '08:15:00',
];
same(
    ['2026-10-08', '2026-10-09'],
    workorder_days_in_range($partial, ymd('2026-10-01'), ymd('2026-10-31')),
    'WO2601071 staat op beide dagen'
);
same('08-10-2026 16:00 t/m 09-10-2026 08:15', workorder_period_label($partial, '16:00', '08:15'), 'detail/lijst toont start en einde');
same(
    ['start_time' => '16:00', 'end_time' => '18:00', 'start_minutes' => 16 * 60, 'end_minutes' => 18 * 60, 'all_day' => false],
    workorder_multiday_calendar_slot($partial, '2026-10-08'),
    'startdag loopt vanaf de starttijd tot het einde van de werkdag'
);
same(
    ['start_time' => '07:00', 'end_time' => '08:15', 'start_minutes' => 7 * 60, 'end_minutes' => (8 * 60) + 15, 'all_day' => false],
    workorder_multiday_calendar_slot($partial, '2026-10-09'),
    'einddag loopt vanaf het begin van de werkdag tot de eindtijd'
);

$long = [
    'No' => 'WO2605377',
    'Start_Date' => '2026-10-01',
    'Start_Time' => '08:00:00',
    'End_Date' => '2026-12-31',
    'End_Time' => '17:00:00',
];
$started = microtime(true);
$longDays = workorder_days_in_range($long, ymd('2026-10-15'), ymd('2026-10-17'));
check(microtime(true) - $started < 0.05, 'doorsnede van een lange reeks blijft goedkoop');
same(['2026-10-15', '2026-10-16', '2026-10-17'], $longDays, 'lange order alleen op de dagen binnen de gekozen reeks');
$fullSpan = workorder_days_in_range($long, ymd('2026-10-01'), ymd('2026-12-31'));
same(92, count($fullSpan), '1 okt t/m 31 dec is elke dag, inclusief de grenzen');
same('2026-10-01', $fullSpan[0], 'reeks begint op de startdatum');
same('2026-12-31', $fullSpan[count($fullSpan) - 1], 'reeks eindigt op de einddatum');
same(
    ['start_time' => '07:00', 'end_time' => '18:00', 'start_minutes' => 7 * 60, 'end_minutes' => 18 * 60, 'all_day' => false],
    workorder_multiday_calendar_slot($long, '2026-10-16'),
    'tussendag is een hele werkdag'
);
same(
    ['start_time' => '08:00', 'end_time' => '18:00', 'start_minutes' => 8 * 60, 'end_minutes' => 18 * 60, 'all_day' => false],
    workorder_multiday_calendar_slot($long, '2026-10-01'),
    'eerste dag van een lange order begint op de starttijd'
);
same(
    ['start_time' => '07:00', 'end_time' => '17:00', 'start_minutes' => 7 * 60, 'end_minutes' => 17 * 60, 'all_day' => false],
    workorder_multiday_calendar_slot($long, '2026-12-31'),
    'laatste dag eindigt op de eindtijd'
);

$sameDay = ['Start_Date' => '2026-10-08', 'End_Date' => '2026-10-08', 'Start_Time' => '09:00:00', 'End_Time' => '11:00:00'];
same(['2026-10-08'], workorder_days_in_range($sameDay, ymd('2026-10-01'), ymd('2026-10-31')), 'zelfde dag blijft één dag');
same('', workorder_period_label($sameDay, '09:00', '11:00'), 'gelijke datums krijgen geen dubbele periode');
same(null, workorder_multiday_calendar_slot($sameDay, '2026-10-08'), 'eendaagse order houdt de bestaande tijdslogica');

$missingEnd = ['Start_Date' => '2026-10-08', 'Start_Time' => '09:00:00', 'End_Time' => '17:00:00'];
same(['2026-10-08'], workorder_days_in_range($missingEnd, ymd('2026-10-01'), ymd('2026-10-31')), 'zonder einddatum alleen de startdatum');
same([], workorder_days_in_range($missingEnd, ymd('2026-10-09'), ymd('2026-10-10')), 'zonder einddatum niet op latere dagen');

$blankEnd = ['Start_Date' => '2026-10-08T00:00:00Z', 'End_Date' => '0001-01-01'];
same(['2026-10-08'], workorder_days_in_range($blankEnd, ymd('2026-10-01'), ymd('2026-10-31')), 'BC-lege einddatum 0001-01-01 telt als ontbrekend');

$endBeforeStart = ['Start_Date' => '2026-10-08', 'End_Date' => '2026-10-07', 'Start_Time' => '09:00:00', 'End_Time' => '08:00:00'];
same(['2026-10-08'], workorder_days_in_range($endBeforeStart, ymd('2026-10-01'), ymd('2026-10-31')), 'einddatum vóór start valt terug op de startdatum');
same('', workorder_period_label($endBeforeStart, '09:00', '08:00'), 'ongeldige einddatum wordt niet als periode getoond');
same([], workorder_rows_on_day([$endBeforeStart], '2026-10-07'), 'ongeldige einddatum verschijnt niet op de eerdere dag');

$example = ['Start_Date' => '2026-10-15', 'Start_Time' => '09:00:00', 'End_Date' => '2026-10-17', 'End_Time' => '17:00:00'];
same(['2026-10-15', '2026-10-16', '2026-10-17'], workorder_days_in_range($example, ymd('2026-10-01'), ymd('2026-10-31')), '15 t/m 17 inclusief de gedeeltelijke dagen');

same(
    'Start_Date le 2026-10-15 and (Start_Date ge 2026-10-15 or End_Date ge 2026-10-15)',
    workorder_odata_overlap_filter('2026-10-15', '2026-10-15'),
    'dagfilter zoekt overlap in plaats van alleen Start_Date'
);

$index = (string) file_get_contents(__DIR__ . '/../web/index.php');
$mail = (string) file_get_contents(__DIR__ . '/../web/api_send_email_notifications.php');
check(strpos($index, '/^ASS\d{5,10}$/') !== false, 'ASS-zoekfilter blijft intact');
check(strpos($index, '/^WO\d{5,10}$/') !== false, 'WO-zoekfilter blijft intact');
check(strpos($index, '/^40\d{5}$/') !== false, '40-nummerzoekfilter blijft intact');
check(strpos($index, "'\$select' => 'Start_Date,Start_Time'") !== false, 'laatste-werkorderquery haalt alleen startvelden op');
check(substr_count($index, 'End_Date') === 3, 'End_Date staat in de lijst-, lookup- en detailquery van index.php');
check(substr_count($mail, 'End_Date') >= 3, 'End_Date staat in de mailquery\'s');

fwrite(STDOUT, "OK {$assertions} assertions\n");
