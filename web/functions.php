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

/**
 * BC on-prem OData geeft 501 BadRequest_MethodNotImplemented als `or` twee
 * verschillende velden combineert (`De operator 'OF' wordt niet ondersteund
 * op afzonderlijke velden`). `or` op hetzelfde veld mag wel.
 *
 * Lege filter of een filter dat niet geparsed kan worden telt als onveilig,
 * zodat een onbekende vorm niet per ongeluk doorgaat.
 */
function odata_filter_has_cross_field_or(string $filter): bool
{
    $filter = trim($filter);
    if ($filter === '') {
        return false;
    }

    $tokens = odata_filter_tokenize($filter);
    if ($tokens === null) {
        return true;
    }

    $index = 0;
    $expr = odata_filter_parse_or($tokens, $index);
    if ($expr === null || $index !== count($tokens)) {
        return true;
    }

    return odata_filter_expr_cross_field_or($expr);
}

/**
 * @return list<string>|null
 */
function odata_filter_tokenize(string $filter): ?array
{
    $tokens = [];
    $length = strlen($filter);
    $index = 0;
    while ($index < $length) {
        $char = $filter[$index];
        if ($char === ' ' || $char === "\t" || $char === "\n" || $char === "\r") {
            $index++;
            continue;
        }
        if ($char === '(' || $char === ')') {
            $tokens[] = $char;
            $index++;
            continue;
        }
        if ($char === "'") {
            $index++;
            $closed = false;
            while ($index < $length) {
                if ($filter[$index] === "'") {
                    if ($index + 1 < $length && $filter[$index + 1] === "'") {
                        $index += 2;
                        continue;
                    }
                    $index++;
                    $closed = true;
                    break;
                }
                $index++;
            }
            if (!$closed) {
                return null;
            }
            $tokens[] = 'value';
            continue;
        }

        if (preg_match('/\G([A-Za-z_][A-Za-z0-9_]*|\d{4}-\d{2}-\d{2}(?:T[^)\s]*)?|-?\d+(?:\.\d+)?)/', $filter, $matches, 0, $index) !== 1) {
            return null;
        }

        $word = (string) ($matches[1] ?? '');
        $index += strlen($word);
        $lower = strtolower($word);
        if (in_array($lower, ['and', 'or', 'eq', 'ne', 'gt', 'ge', 'lt', 'le'], true)) {
            $tokens[] = $lower;
            continue;
        }
        $tokens[] = 'word:' . $word;
    }

    return $tokens;
}

/**
 * @param list<string> $tokens
 * @return array<string, mixed>|null
 */
function odata_filter_parse_or(array $tokens, int &$index): ?array
{
    $left = odata_filter_parse_and($tokens, $index);
    if ($left === null) {
        return null;
    }
    if (($tokens[$index] ?? null) !== 'or') {
        return $left;
    }

    $items = [$left];
    while (($tokens[$index] ?? null) === 'or') {
        $index++;
        $right = odata_filter_parse_and($tokens, $index);
        if ($right === null) {
            return null;
        }
        $items[] = $right;
    }

    return ['type' => 'or', 'items' => $items];
}

/**
 * @param list<string> $tokens
 * @return array<string, mixed>|null
 */
function odata_filter_parse_and(array $tokens, int &$index): ?array
{
    $left = odata_filter_parse_primary($tokens, $index);
    if ($left === null) {
        return null;
    }
    if (($tokens[$index] ?? null) !== 'and') {
        return $left;
    }

    $items = [$left];
    while (($tokens[$index] ?? null) === 'and') {
        $index++;
        $right = odata_filter_parse_primary($tokens, $index);
        if ($right === null) {
            return null;
        }
        $items[] = $right;
    }

    return ['type' => 'and', 'items' => $items];
}

/**
 * @param list<string> $tokens
 * @return array<string, mixed>|null
 */
function odata_filter_parse_primary(array $tokens, int &$index): ?array
{
    $token = $tokens[$index] ?? null;
    if ($token === '(') {
        $index++;
        $expr = odata_filter_parse_or($tokens, $index);
        if ($expr === null || ($tokens[$index] ?? null) !== ')') {
            return null;
        }
        $index++;

        return $expr;
    }

    if (!is_string($token) || strpos($token, 'word:') !== 0) {
        return null;
    }

    $field = substr($token, 5);
    $index++;
    $operator = $tokens[$index] ?? null;
    if (!in_array($operator, ['eq', 'ne', 'gt', 'ge', 'lt', 'le'], true)) {
        return null;
    }
    $index++;
    $value = $tokens[$index] ?? null;
    $valueOk = $value === 'value' || (is_string($value) && strpos($value, 'word:') === 0);
    if (!$valueOk) {
        return null;
    }
    $index++;

    return ['type' => 'pred', 'field' => $field];
}

/**
 * @param array<string, mixed> $expr
 */
function odata_filter_expr_cross_field_or(array $expr): bool
{
    $type = (string) ($expr['type'] ?? '');
    if ($type === 'pred') {
        return false;
    }

    $items = $expr['items'] ?? null;
    if (($type !== 'and' && $type !== 'or') || !is_array($items) || $items === []) {
        return true;
    }

    if ($type === 'and') {
        foreach ($items as $item) {
            if (!is_array($item) || odata_filter_expr_cross_field_or($item)) {
                return true;
            }
        }

        return false;
    }

    $fields = [];
    foreach ($items as $item) {
        if (!is_array($item) || odata_filter_expr_cross_field_or($item)) {
            return true;
        }
        foreach (odata_filter_expr_fields($item) as $field) {
            $fields[$field] = true;
        }
    }

    return count($fields) > 1;
}

/**
 * @param array<string, mixed> $expr
 * @return list<string>
 */
function odata_filter_expr_fields(array $expr): array
{
    if (($expr['type'] ?? '') === 'pred') {
        $field = trim((string) ($expr['field'] ?? ''));

        return $field === '' ? [] : [$field];
    }

    $fields = [];
    $items = $expr['items'] ?? [];
    if (!is_array($items)) {
        return [];
    }
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        foreach (odata_filter_expr_fields($item) as $field) {
            $fields[$field] = $field;
        }
    }

    return array_values($fields);
}

/**
 * Twee AND-filters die samen de overlap met [rangeStart, rangeEnd] benaderen.
 * BC on-prem ondersteunt geen `or` tussen Start_Date en End_Date. De aanroeper
 * haalt beide op, ontdubbelt op ordernummer en laat de einddatum in PHP beslissen.
 *
 * @return list<string>
 */
function workorder_odata_overlap_filters(string $rangeStart, string $rangeEnd): array
{
    $start = workorder_ymd($rangeStart);
    $end = workorder_ymd($rangeEnd);
    if ($start === '' || $end === '') {
        return [];
    }
    if ($end < $start) {
        $swap = $start;
        $start = $end;
        $end = $swap;
    }

    return [
        'Start_Date ge ' . $start . ' and Start_Date le ' . $end,
        'Start_Date le ' . $end . ' and End_Date ge ' . $start,
    ];
}

/**
 * @param list<list<array<string, mixed>>> $batches
 * @return list<array<string, mixed>>
 */
function workorder_merge_rows(array $batches): array
{
    $merged = [];
    $seen = [];
    foreach ($batches as $batch) {
        if (!is_array($batch)) {
            continue;
        }
        foreach ($batch as $row) {
            if (!is_array($row)) {
                continue;
            }
            $no = trim((string) ($row['No'] ?? ''));
            if ($no !== '') {
                if (isset($seen[$no])) {
                    continue;
                }
                $seen[$no] = true;
            }
            $merged[] = $row;
        }
    }

    return $merged;
}

/**
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function workorder_rows_overlapping(array $rows, string $rangeStart, string $rangeEnd): array
{
    $startYmd = workorder_ymd($rangeStart);
    $endYmd = workorder_ymd($rangeEnd);
    if ($startYmd === '' || $endYmd === '') {
        return [];
    }
    if ($endYmd < $startYmd) {
        $swap = $startYmd;
        $startYmd = $endYmd;
        $endYmd = $swap;
    }

    $start = DateTimeImmutable::createFromFormat('!Y-m-d', $startYmd, new DateTimeZone('UTC'));
    $end = DateTimeImmutable::createFromFormat('!Y-m-d', $endYmd, new DateTimeZone('UTC'));
    if (!($start instanceof DateTimeImmutable) || !($end instanceof DateTimeImmutable)) {
        return [];
    }

    $result = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        if (workorder_days_in_range($row, $start, $end) !== []) {
            $result[] = $row;
        }
    }

    return $result;
}

/**
 * Haalt kandidaten op zonder `or` over verschillende velden en houdt daarna
 * alleen rijen waarvan de order (start t/m eind) de reeks snijdt.
 *
 * @param callable(string): list<array<string, mixed>> $fetchFilter
 * @return list<array<string, mixed>>
 */
function workorder_fetch_overlapping(string $rangeStart, string $rangeEnd, callable $fetchFilter): array
{
    $filters = workorder_odata_overlap_filters($rangeStart, $rangeEnd);
    if (function_exists('odata_prefetch')) {
        // Beide overlap-queries tegelijk ophalen (via Mímir); de lus hieronder leest ze uit het verzoekgeheugen.
        odata_prefetch(static function () use ($filters, $fetchFilter): void {
            foreach ($filters as $filter) {
                $fetchFilter($filter);
            }
        });
    }

    $batches = [];
    foreach ($filters as $filter) {
        $batch = $fetchFilter($filter);
        $batches[] = is_array($batch) ? $batch : [];
    }

    $overlapping = workorder_rows_overlapping(workorder_merge_rows($batches), $rangeStart, $rangeEnd);
    usort($overlapping, static function (array $left, array $right): int {
        return [
            workorder_ymd((string) ($left['Start_Date'] ?? '')),
            format_workorder_time_value((string) ($left['Start_Time'] ?? '')),
            trim((string) ($left['No'] ?? '')),
        ] <=> [
            workorder_ymd((string) ($right['Start_Date'] ?? '')),
            format_workorder_time_value((string) ($right['Start_Time'] ?? '')),
            trim((string) ($right['No'] ?? '')),
        ];
    });

    return $overlapping;
}

function format_workorder_time_value(string $value): string
{
    $raw = trim($value);
    if ($raw === '') {
        return '';
    }

    try {
        $dateTime = new DateTimeImmutable($raw);
        return $dateTime->format('H:i');
    } catch (Throwable $throwable) {
    }

    if (preg_match('/T(\d{2}:\d{2})/', $raw, $matches) === 1) {
        return (string) ($matches[1] ?? '');
    }

    if (preg_match('/^(\d{2}:\d{2})/', $raw, $matches) === 1) {
        return (string) ($matches[1] ?? '');
    }

    return '';
}

function workorder_clock_minutes(string $time): ?int
{
    $formatted = format_workorder_time_value($time);
    if ($formatted === '' || preg_match('/^(\d{2}):(\d{2})$/', $formatted, $matches) !== 1) {
        return null;
    }

    $hours = (int) $matches[1];
    $minutes = (int) $matches[2];
    if ($hours > 23 || $minutes > 59) {
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
function workorder_multiday_calendar_slot(array $row, string $occurrenceYmd, ?string $startTime = null, ?string $endTime = null): ?array
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
    $startTime = workorder_clock_minutes($startTime ?? (string) ($row['Start_Time'] ?? ''));
    $endTime = workorder_clock_minutes($endTime ?? (string) ($row['End_Time'] ?? ''));

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

/**
 * Vorige werkorders: eerst op component, aanvullen met servicelocatie; zonder dubbelen, Start_Date aflopend.
 */
function merge_previous_workorders(array $componentRows, array $locationRows, string $currentNo, int $limit = 5): array
{
    $sortByStartDateDesc = static function (array $a, array $b): int {
        return strcmp((string) ($b['Start_Date'] ?? ''), (string) ($a['Start_Date'] ?? ''));
    };
    usort($componentRows, $sortByStartDateDesc);
    usort($locationRows, $sortByStartDateDesc);

    $result = [];
    foreach (['component' => $componentRows, 'locatie' => $locationRows] as $matchType => $rows) {
        foreach ($rows as $row) {
            $no = trim((string) ($row['No'] ?? ''));
            if (count($result) >= $limit) {
                break 2;
            }
            if ($no === '' || $no === $currentNo || isset($result[$no]) || trim((string) ($row['Status'] ?? '')) === 'Geannuleerd') {
                continue;
            }
            $result[$no] = $row + ['_match' => $matchType];
        }
    }

    return array_values($result);
}
