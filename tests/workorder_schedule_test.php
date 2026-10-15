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

$isoTimes = [
    'Start_Date' => '2026-10-08',
    'End_Date' => '2026-10-09',
    'Start_Time' => '2026-10-08T16:00:00.1234567',
    'End_Time' => '2026-10-09T08:15:00.0000000Z',
];
same(
    ['start_time' => '16:00', 'end_time' => '18:00', 'start_minutes' => 16 * 60, 'end_minutes' => 18 * 60, 'all_day' => false],
    workorder_multiday_calendar_slot($isoTimes, '2026-10-08'),
    'ISO-starttijd met fracties blijft 16:00 en valt niet terug op 07:00'
);
same(
    ['start_time' => '07:00', 'end_time' => '08:15', 'start_minutes' => 7 * 60, 'end_minutes' => (8 * 60) + 15, 'all_day' => false],
    workorder_multiday_calendar_slot($isoTimes, '2026-10-09', format_workorder_time_value($isoTimes['Start_Time']), format_workorder_time_value($isoTimes['End_Time'])),
    'dezelfde tijdparser als de weekkalender bepaalt de eindtijd'
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

$dayFilters = workorder_odata_overlap_filters('2026-10-15', '2026-10-15');
same(
    [
        'Start_Date ge 2026-10-15 and Start_Date le 2026-10-15',
        'Start_Date le 2026-10-15 and End_Date ge 2026-10-15',
    ],
    $dayFilters,
    'dagfilter zoekt overlap in twee queries zonder or over verschillende velden'
);
foreach ($dayFilters as $dayFilter) {
    check(!odata_filter_has_cross_field_or($dayFilter), 'gegenereerd dagfilter zonder cross-field or: ' . $dayFilter);
    check(
        !odata_filter_has_cross_field_or("Resource_No eq '1001' and " . $dayFilter),
        'filter voor één monteur zonder cross-field or: ' . $dayFilter
    );
}
$weekFilters = workorder_odata_overlap_filters('2026-10-15', '2026-10-21');
same(
    [
        'Start_Date ge 2026-10-15 and Start_Date le 2026-10-21',
        'Start_Date le 2026-10-21 and End_Date ge 2026-10-15',
    ],
    $weekFilters,
    'weekfilter begrenst start en eind zonder or'
);
foreach ($weekFilters as $weekFilter) {
    check(!odata_filter_has_cross_field_or($weekFilter), 'gegenereerd weekfilter zonder cross-field or: ' . $weekFilter);
}
check(
    odata_filter_has_cross_field_or('Start_Date le 2026-10-15 and (Start_Date ge 2026-10-15 or End_Date ge 2026-10-15)'),
    'het oude overlapfilter met or tussen Start_Date en End_Date wordt herkend'
);
check(
    !odata_filter_has_cross_field_or("(No eq 'WO1' or No eq 'WO2' or No eq 'WO3')"),
    'or op hetzelfde veld blijft toegestaan'
);
check(
    !odata_filter_has_cross_field_or("(Assigned_User_ID eq 'KVT\\monteur' or Assigned_User_ID eq 'MONTEUR')"),
    'or op Assigned_User_ID blijft toegestaan'
);
check(
    !odata_filter_has_cross_field_or('Starting_Date le 2026-10-21 and Ending_Date ge 2026-10-15'),
    'and over verschillende velden blijft toegestaan'
);
check(!odata_filter_has_cross_field_or(''), 'leeg filter is geen cross-field or');

$catalog = [
    ['No' => 'SPAN', 'Start_Date' => '2026-10-01', 'Start_Time' => '08:00:00', 'End_Date' => '2026-12-31', 'End_Time' => '17:00:00'],
    ['No' => 'DONE', 'Start_Date' => '2026-10-01', 'Start_Time' => '08:00:00', 'End_Date' => '2026-10-02', 'End_Time' => '17:00:00'],
    ['No' => 'BOTH', 'Start_Date' => '2026-10-15', 'Start_Time' => '08:00:00', 'End_Date' => '2026-10-16', 'End_Time' => '12:00:00'],
    ['No' => 'IN', 'Start_Date' => '2026-10-15', 'Start_Time' => '09:00:00', 'End_Date' => '2026-10-15', 'End_Time' => '11:00:00'],
    ['No' => 'BLANK', 'Start_Date' => '2026-10-15', 'Start_Time' => '10:00:00'],
    ['No' => 'EARLY', 'Start_Date' => '2026-10-08', 'End_Date' => '2026-10-07', 'Start_Time' => '09:00:00'],
];
$fetchCalls = 0;
$sentFilters = [];
$onDay = workorder_fetch_overlapping('2026-10-15', '2026-10-15', static function (string $filter) use (&$fetchCalls, &$sentFilters, $catalog): array {
    $fetchCalls++;
    $sentFilters[] = $filter;
    check(!odata_filter_has_cross_field_or($filter), 'fetch stuurt geen cross-field or: ' . $filter);

    return $catalog;
});
same(2, $fetchCalls, 'overlap gebruikt twee queries en voegt die samen');
same($dayFilters, $sentFilters, 'fetch vraagt precies de twee overlapfilters');
same(['SPAN', 'BOTH', 'IN', 'BLANK'], array_column($onDay, 'No'), 'PHP houdt meerdaagse orders en laat afgelopen of ongeldige einddatums weg');
$lastDayCalls = 0;
$onLastDay = workorder_fetch_overlapping('2026-12-31', '2026-12-31', static function (string $filter) use (&$lastDayCalls, $catalog): array {
    $lastDayCalls++;
    check(!odata_filter_has_cross_field_or($filter), 'laatste-dagfilter zonder cross-field or');

    return $catalog;
});
same(2, $lastDayCalls, 'ook de laatste dag vraagt twee filters');
same(['SPAN'], array_column($onLastDay, 'No'), 'order van 1 okt t/m 31 dec staat ook op de laatste dag');

$index = (string) file_get_contents(__DIR__ . '/../web/index.php');
$mail = (string) file_get_contents(__DIR__ . '/../web/api_send_email_notifications.php');
check(strpos($index, '/^ASS\d{5,10}$/') !== false, 'ASS-zoekfilter blijft intact');
check(strpos($index, '/^WO\d{5,10}$/') !== false, 'WO-zoekfilter blijft intact');
check(strpos($index, '/^40\d{5}$/') !== false, '40-nummerzoekfilter blijft intact');
check(strpos($index, "'\$select' => 'Start_Date,Start_Time'") !== false, 'laatste-werkorderquery haalt alleen startvelden op');
check(substr_count($index, 'End_Date') === 3, 'End_Date staat in de lijst-, lookup- en detailquery van index.php');
check(substr_count($mail, 'End_Date') >= 3, 'End_Date staat in de mailquery\'s');
check(strpos($index, ' or End_Date') === false, 'index.php bouwt geen or op End_Date');
check(strpos($mail, ' or End_Date') === false, 'de mail bouwt geen or op End_Date');
$briefingSource = (string) file_get_contents(__DIR__ . '/../web/lib/daily_briefing.php');
check(strpos($briefingSource, ' or End_Date') === false, 'de briefing bouwt geen or op End_Date');

$mimirApi = 'mimir_test_key';
$baseUrl = 'https://bc.example:7148/';
$environment = 'Production';
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'];
require __DIR__ . '/../web/odata.php';

foreach ($dayFilters as $dayFilter) {
    $technicianFilter = "Resource_No eq '1001' and " . $dayFilter;
    $mimirUrl = odata_company_url('Production', 'Koninklijke van Twist', 'AppWerkorders', [
        '$select' => 'No,Start_Date,End_Date',
        '$filter' => $technicianFilter,
        '$orderby' => 'Start_Date asc,Start_Time asc,No asc',
    ]);
    check(strpos($mimirUrl, 'https://mimir.invalid/') === 0, 'met $mimirApi gaat de URL eerst naar Mímir');
    $parsed = odata_mimir_parse_entity_url($mimirUrl);
    check(is_array($parsed), 'Mímir kan de OData-URL naar company/table vertalen');
    same($technicianFilter, (string) ($parsed['query']['$filter'] ?? ''), 'Mímir krijgt hetzelfde filter');
    check(!odata_filter_has_cross_field_or((string) ($parsed['query']['$filter'] ?? '')), 'Mímir-filter zonder cross-field or');

    $directUrl = odata_bc_url_from_odata_url($mimirUrl);
    check(strpos($directUrl, 'https://bc.example:7148/Production/ODataV4/') === 0, 'fallback herschrijft naar directe BC-OData');
    $directQuery = [];
    parse_str((string) parse_url($directUrl, PHP_URL_QUERY), $directQuery);
    same($technicianFilter, (string) ($directQuery['$filter'] ?? ''), 'directe OData krijgt hetzelfde filter');
    check(!odata_filter_has_cross_field_or((string) ($directQuery['$filter'] ?? '')), 'direct OData-filter zonder cross-field or');
}

fwrite(STDOUT, "OK {$assertions} assertions\n");
