<?php
/**
 * Functies
 */
function odata_company_url(string $environment, string $company, string $entity, array $params = []): string
{
    global $baseUrl;
    $encCompany = rawurlencode($company);

    // When Mímir is on, build a synthetic OData URL that odata_mimir_parse_entity_url understands.
    // After a Mímir failure in this process the circuit is open and the pre-Mímir BC URL is used.
    $mimirUrl = function_exists('odata_mimir_enabled') && odata_mimir_enabled()
        && !(function_exists('odata_mimir_circuit_open') && odata_mimir_circuit_open());
    if ($mimirUrl) {
        $env = trim($environment) !== '' ? $environment : 'mimir';
        $base = "https://mimir.invalid/" . $env . "/ODataV4/Company('" . $encCompany . "')/";
    } else {
        $prefix = (isset($baseUrl) && is_string($baseUrl)) ? $baseUrl : '';
        $base = $prefix . $environment . "/ODataV4/Company('" . $encCompany . "')/";
    }

    $query = '';
    if (!empty($params)) {
        $query = '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }
    return $base . $entity . $query;
}

function odata_quote_string(string $value): string
{
    return str_replace("'", "''", trim($value));
}

function nl_date(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return '-';
    }

    try {
        return (new DateTime($value))->format('d-m-Y');
    } catch (Exception $exception) {
        return (string) $value;
    }
}

function safe_text(?string $value, string $fallback = '-'): string
{
    $trimmed = trim((string) $value);
    return $trimmed === '' ? $fallback : $trimmed;
}

function bc_text_html(?string $value, string $fallback = '-'): string
{
    $text = safe_text($value, $fallback);
    $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

    return str_ireplace(
        ['&lt;br/&gt;', '&lt;br /&gt;', '&lt;br&gt;'],
        '<br/>',
        $escaped
    );
}

function is_true_value($value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_numeric($value)) {
        return ((int) $value) === 1;
    }

    $normalized = strtolower(trim((string) $value));
    return in_array($normalized, ['1', 'true', 'yes', 'ja'], true);
}

function workorder_ymd(string $value): string
{
    $raw = trim($value);
    if ($raw === '' || preg_match('/^(\d{4}-\d{2}-\d{2})/', $raw, $matches) !== 1) {
        return '';
    }

    $ymd = (string) ($matches[1] ?? '');
    if ($ymd === '' || $ymd === '0001-01-01') {
        return '';
    }

    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd, new DateTimeZone('UTC'));
    if (!($parsed instanceof DateTimeImmutable) || $parsed->format('Y-m-d') !== $ymd) {
        return '';
    }

    return $ymd;
}

function workorder_schedule_end_ymd(array $row): string
{
    $startYmd = workorder_ymd((string) ($row['Start_Date'] ?? ''));
    $endYmd = workorder_ymd((string) ($row['End_Date'] ?? ''));
    if ($startYmd === '' || $endYmd === '' || $endYmd < $startYmd) {
        return $startYmd;
    }

    return $endYmd;
}

function workorder_spans_days(array $row): bool
{
    $startYmd = workorder_ymd((string) ($row['Start_Date'] ?? ''));
    $endYmd = workorder_ymd((string) ($row['End_Date'] ?? ''));

    return $startYmd !== '' && $endYmd !== '' && $endYmd > $startYmd;
}

function workorder_period_label(array $row, string $startTime, string $endTime): string
{
    if (!workorder_spans_days($row)) {
        return '';
    }

    $startLabel = nl_date(workorder_ymd((string) ($row['Start_Date'] ?? '')));
    $startTime = trim($startTime);
    if ($startTime !== '') {
        $startLabel .= ' ' . $startTime;
    }

    $endLabel = nl_date(workorder_ymd((string) ($row['End_Date'] ?? '')));
    $endTime = trim($endTime);
    if ($endTime !== '') {
        $endLabel .= ' ' . $endTime;
    }

    return $startLabel . ' t/m ' . $endLabel;
}

/**
 * Elke kalenderdag van start t/m eind die binnen de gekozen reeks valt.
 * Ontbrekende of eerdere einddatum: alleen de startdatum.
 *
 * @return list<string>
 */
function workorder_days_in_range(array $row, DateTimeImmutable $rangeStart, DateTimeImmutable $rangeEnd): array
{
    $startYmd = workorder_ymd((string) ($row['Start_Date'] ?? ''));
    if ($startYmd === '') {
        return [];
    }

    $endYmd = workorder_schedule_end_ymd($row);
    if ($endYmd === '') {
        $endYmd = $startYmd;
    }

    $fromYmd = max($startYmd, $rangeStart->format('Y-m-d'));
    $toYmd = min($endYmd, $rangeEnd->format('Y-m-d'));
    if ($toYmd < $fromYmd) {
        return [];
    }

    $cursor = DateTimeImmutable::createFromFormat('!Y-m-d', $fromYmd, new DateTimeZone('UTC'));
    $last = DateTimeImmutable::createFromFormat('!Y-m-d', $toYmd, new DateTimeZone('UTC'));
    if (!($cursor instanceof DateTimeImmutable) || !($last instanceof DateTimeImmutable)) {
        return [];
    }

    $days = [];
    while ($cursor <= $last) {
        $days[] = $cursor->format('Y-m-d');
        $cursor = $cursor->modify('+1 day');
    }

    return $days;
}

function workorder_rows_on_day(array $rows, string $day): array
{
    $ymd = workorder_ymd($day);
    if ($ymd === '') {
        return [];
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd, new DateTimeZone('UTC'));
    if (!($date instanceof DateTimeImmutable)) {
        return [];
    }

    $result = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        if (workorder_days_in_range($row, $date, $date) !== []) {
            $result[] = $row;
        }
    }

    return $result;
}

function workorder_odata_overlap_filter(string $rangeStart, string $rangeEnd): string
{
    return 'Start_Date le ' . $rangeEnd . ' and (Start_Date ge ' . $rangeStart . ' or End_Date ge ' . $rangeStart . ')';
}

function workorder_clock_minutes(string $time): ?int
{
    if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', trim($time), $matches) !== 1) {
        return null;
    }

    $hours = (int) $matches[1];
    $minutes = (int) $matches[2];
    if ($hours < 0 || $hours > 23 || $minutes < 0 || $minutes > 59) {
        return null;
    }

    return ($hours * 60) + $minutes;
}

function workorder_minutes_clock(int $minutes): string
{
    if ($minutes >= 24 * 60) {
        return '24:00';
    }

    $clamped = max(0, $minutes);

    return sprintf('%02d:%02d', intdiv($clamped, 60), $clamped % 60);
}

/**
 * Tijdvak op één dag als de werkorder meerdere dagen beslaat.
 * Deel van de dag telt mee: startdag vanaf de starttijd, einddag tot de eindtijd,
 * tussenliggende dagen de hele werkdag (07:00–18:00).
 *
 * @return array{start_time: string, end_time: string, start_minutes: int, end_minutes: int, all_day: bool}|null
 */
function workorder_multiday_calendar_slot(array $row, string $occurrenceYmd): ?array
{
    $startYmd = workorder_ymd((string) ($row['Start_Date'] ?? ''));
    $endYmd = workorder_ymd((string) ($row['End_Date'] ?? ''));
    $occurrenceYmd = workorder_ymd($occurrenceYmd);
    if ($startYmd === '' || $endYmd === '' || $endYmd <= $startYmd || $occurrenceYmd === '') {
        return null;
    }
    if ($occurrenceYmd < $startYmd || $occurrenceYmd > $endYmd) {
        return null;
    }

    $workdayStart = 7 * 60;
    $workdayEnd = 18 * 60;
    $startTime = workorder_clock_minutes((string) ($row['Start_Time'] ?? ''));
    $endTime = workorder_clock_minutes((string) ($row['End_Time'] ?? ''));

    if ($occurrenceYmd === $startYmd) {
        $slotStart = $startTime ?? $workdayStart;
        $slotEnd = $workdayEnd;
        if ($slotEnd <= $slotStart) {
            $slotEnd = min(24 * 60, $slotStart + 60);
        }
    } elseif ($occurrenceYmd === $endYmd) {
        $slotEnd = $endTime ?? $workdayEnd;
        $slotStart = $workdayStart;
        if ($slotEnd <= $slotStart) {
            $slotStart = max(0, $slotEnd - 60);
        }
    } else {
        $slotStart = $workdayStart;
        $slotEnd = $workdayEnd;
    }

    return [
        'start_time' => workorder_minutes_clock($slotStart),
        'end_time' => workorder_minutes_clock($slotEnd),
        'start_minutes' => $slotStart,
        'end_minutes' => $slotEnd,
        'all_day' => false,
    ];
}
