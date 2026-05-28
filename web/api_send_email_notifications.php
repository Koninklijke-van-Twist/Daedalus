<?php
/**
 * Includes en requires
 */
require __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/odata.php';

/**
 * Constants
 */
const APP_BASE_URL = 'https://sleutels.kvt.nl/daedalus/index.php';
const USER_CACHE_DIR = __DIR__ . '/cache/users';

/**
 * Variabelen
 */
$minute = 60;
$hour = $minute * 60;
$odataTtl = [
    'resource_by_email' => $hour,
    'usersetup_by_email' => $hour,
    'resource_by_userid' => $hour,
    'workorders_list' => $hour,
    'planning_lines' => $minute * 15,
    'item_task_flags' => $hour,
];

$statusLabels = [
    'O' => 'Onbekend',
    'N' => 'Niet nodig',
    'X' => 'Niet op tijd',
    'T' => 'Te laat',
    'I' => 'Inkooporder aanwezig',
    'V' => 'Voorraad',
    'G' => 'Gepicked',
    'B' => 'Uitgegeven',
    'A' => 'Aangenomen',
    'C' => 'Gecontroleerd',
];

$summary = [
    'timestamp_utc' => gmdate('c'),
    'processed' => 0,
    'skipped' => 0,
    'emails_sent_total' => 0,
    'orders_sent_total' => 0,
    'new_workorder_emails_sent' => 0,
    'new_workorder_orders_sent' => 0,
    'daily_overview_emails_sent' => 0,
    'daily_overview_orders_sent' => 0,
    'emails_sent' => 0,
    'orders_sent' => 0,
    'errors' => [],
];

/**
 * Functies
 */
function normalize_email(string $email): string
{
    return trim(strtolower($email));
}

function odata_ttl(string $key, int $fallback = 120): int
{
    global $odataTtl;
    $value = (int) ($odataTtl[$key] ?? $fallback);
    return $value > 0 ? $value : $fallback;
}

function read_json_assoc_file(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function write_json_assoc_file(string $path, array $data): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }

    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if (is_string($json)) {
        @file_put_contents($path, $json, LOCK_EX);
    }
}

function default_notification_settings(): array
{
    return [
        'enabled' => false,
        'daily_overview_enabled' => false,
        'daily_overview_last_sent_date' => '',
        'last_checked_at' => '',
        'resource_no' => '',
        'resource_name' => '',
        'company' => '',
    ];
}

function normalize_notification_settings(array $input): array
{
    $defaults = default_notification_settings();

    return [
        'enabled' => is_true_value($input['enabled'] ?? $defaults['enabled']),
        'daily_overview_enabled' => is_true_value($input['daily_overview_enabled'] ?? $defaults['daily_overview_enabled']),
        'daily_overview_last_sent_date' => trim((string) ($input['daily_overview_last_sent_date'] ?? $defaults['daily_overview_last_sent_date'])),
        'last_checked_at' => trim((string) ($input['last_checked_at'] ?? $defaults['last_checked_at'])),
        'resource_no' => trim((string) ($input['resource_no'] ?? $defaults['resource_no'])),
        'resource_name' => trim((string) ($input['resource_name'] ?? $defaults['resource_name'])),
        'company' => trim((string) ($input['company'] ?? $defaults['company'])),
    ];
}

function normalize_status_filter_map(array $input): array
{
    $result = [];
    foreach ($input as $status => $enabled) {
        $statusText = trim((string) $status);
        if ($statusText === '') {
            continue;
        }
        $result[$statusText] = (bool) $enabled;
    }

    return $result;
}

function normalize_webfleet_filter_map(array $input): array
{
    return normalize_status_filter_map($input);
}

function read_user_payload(string $path): array
{
    $raw = read_json_assoc_file($path);
    $filtersRaw = is_array($raw['filters'] ?? null) ? $raw['filters'] : $raw;
    $webfleetRaw = is_array($raw['webfleet_filters'] ?? null) ? $raw['webfleet_filters'] : [];
    $notificationRaw = is_array($raw['notification_settings'] ?? null) ? $raw['notification_settings'] : [];
    $meta = is_array($raw['meta'] ?? null) ? $raw['meta'] : [];

    return [
        'filters' => normalize_status_filter_map($filtersRaw),
        'webfleet_filters' => normalize_webfleet_filter_map($webfleetRaw),
        'meta' => $meta,
        'notification_settings' => normalize_notification_settings($notificationRaw),
    ];
}

function write_user_payload(string $path, array $payload): void
{
    write_json_assoc_file($path, [
        'filters' => normalize_status_filter_map(is_array($payload['filters'] ?? null) ? $payload['filters'] : []),
        'webfleet_filters' => normalize_webfleet_filter_map(is_array($payload['webfleet_filters'] ?? null) ? $payload['webfleet_filters'] : []),
        'meta' => is_array($payload['meta'] ?? null) ? $payload['meta'] : [],
        'notification_settings' => normalize_notification_settings(is_array($payload['notification_settings'] ?? null) ? $payload['notification_settings'] : []),
    ]);
}

function list_user_cache_files(): array
{
    $paths = glob(USER_CACHE_DIR . '/*.json');
    if (!is_array($paths)) {
        return [];
    }

    sort($paths, SORT_NATURAL | SORT_FLAG_CASE);
    return $paths;
}

function email_from_user_cache_path(string $path): string
{
    $filename = basename($path, '.json');
    return normalize_email($filename);
}

function build_or_filter(string $field, array $values): string
{
    $parts = [];
    foreach ($values as $value) {
        $parts[] = $field . " eq '" . odata_quote_string((string) $value) . "'";
    }

    if (empty($parts)) {
        return '';
    }

    if (count($parts) === 1) {
        return $parts[0];
    }

    return '(' . implode(' or ', $parts) . ')';
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

function parse_iso_datetime_or_null(string $value): ?DateTimeImmutable
{
    $raw = trim($value);
    if ($raw === '') {
        return null;
    }

    try {
        return new DateTimeImmutable($raw);
    } catch (Throwable $throwable) {
        return null;
    }
}

function workorder_datetime_from_row(array $workOrder): ?DateTimeImmutable
{
    $startDateRaw = trim((string) ($workOrder['Start_Date'] ?? ''));
    if ($startDateRaw === '') {
        return null;
    }

    $datePart = $startDateRaw;
    if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $startDateRaw, $matches) === 1) {
        $datePart = (string) ($matches[1] ?? $startDateRaw);
    }

    $startTimeRaw = trim((string) ($workOrder['Start_Time'] ?? ''));
    $timePart = '00:00:00';
    if ($startTimeRaw !== '') {
        if (preg_match('/^(\d{2}:\d{2}:\d{2})/', $startTimeRaw, $matches) === 1) {
            $timePart = (string) ($matches[1] ?? '00:00:00');
        } elseif (preg_match('/^(\d{2}:\d{2})/', $startTimeRaw, $matches) === 1) {
            $timePart = (string) ($matches[1] ?? '00:00') . ':00';
        }
    }

    try {
        return new DateTimeImmutable($datePart . ' ' . $timePart, new DateTimeZone('UTC'));
    } catch (Throwable $throwable) {
        return null;
    }
}

function latest_workorder_datetime_iso(array $workOrders): string
{
    $latest = null;

    foreach ($workOrders as $workOrder) {
        if (!is_array($workOrder)) {
            continue;
        }

        $candidate = workorder_datetime_from_row($workOrder);
        if ($candidate === null) {
            continue;
        }

        if ($latest === null || $candidate > $latest) {
            $latest = $candidate;
        }
    }

    return $latest instanceof DateTimeImmutable ? $latest->format('c') : '';
}

function notification_settings_with_forward_last_checked(array $settings, string $candidateIso): array
{
    $candidate = parse_iso_datetime_or_null($candidateIso);
    if ($candidate === null) {
        return $settings;
    }

    $current = parse_iso_datetime_or_null((string) ($settings['last_checked_at'] ?? ''));
    if ($current !== null && $candidate <= $current) {
        return $settings;
    }

    $settings['last_checked_at'] = $candidate->format('c');
    return $settings;
}

function status_css_class(string $status): string
{
    $normalized = strtolower(trim($status));
    if ($normalized === '') {
        return '';
    }

    $slug = preg_replace('/[^a-z0-9]+/', '-', $normalized);
    $slug = is_string($slug) ? trim($slug, '-') : '';

    return $slug !== '' ? ('status-' . $slug) : '';
}

function webfleet_default_status_label(): string
{
    return 'Niet gestart';
}

function webfleet_status_label_from_flags(bool $hasStarted, bool $hasCompleted): string
{
    if ($hasCompleted) {
        return 'Werk voltooid';
    }

    if ($hasStarted) {
        return 'Werk gestart';
    }

    return webfleet_default_status_label();
}

function webfleet_status_badge_class(string $status): string
{
    $normalized = strtolower(trim($status));
    if ($normalized === 'werk gestart') {
        return 'status-onderhanden';
    }

    if ($normalized === 'werk voltooid') {
        return 'status-uitgevoerd';
    }

    return 'status-gepland';
}

function material_status_code(string $status): string
{
    global $statusLabels;

    $raw = trim($status);
    if ($raw === '') {
        return '';
    }

    $upperRaw = strtoupper($raw);
    if (isset($statusLabels[$upperRaw])) {
        return $upperRaw;
    }

    foreach ($statusLabels as $code => $label) {
        if (strcasecmp(trim((string) $label), $raw) === 0) {
            return strtoupper((string) $code);
        }
    }

    if (preg_match('/^\s*([A-Za-z])\s*(?:$|[-:]).*$/', $raw, $matches) === 1) {
        return strtoupper((string) ($matches[1] ?? ''));
    }

    return '';
}

function material_status_label(string $status): string
{
    global $statusLabels;

    $raw = trim($status);
    if ($raw === '') {
        return 'Onbekend';
    }

    $code = strtoupper($raw);
    if (isset($statusLabels[$code])) {
        return (string) $statusLabels[$code];
    }

    return $raw;
}

function workorder_material_badge_class(string $status): string
{
    $code = material_status_code($status);

    if (in_array($code, ['V', 'G', 'B'], true)) {
        return 'ok';
    }

    if (in_array($code, ['X', 'T', 'I'], true)) {
        return 'warn';
    }

    if (in_array($code, ['A', 'C', 'N'], true)) {
        return 'neutral';
    }

    if ($code === 'O' || $code === '') {
        return 'unknown';
    }

    return 'neutral';
}

function workorder_task_text(array $workOrder): string
{
    $taskDescription = trim((string) ($workOrder['Task_Description'] ?? ''));
    if ($taskDescription !== '') {
        return $taskDescription;
    }

    $taskCode = trim((string) ($workOrder['Task_Code'] ?? ''));
    if ($taskCode !== '') {
        return $taskCode;
    }

    return '-';
}

function material_needed_text(int $realArticleCount): string
{
    if ($realArticleCount <= 0) {
        return 'Nee';
    }

    return 'Ja (' . $realArticleCount . ')';
}

function fetch_app_resources_by_field(
    string $environment,
    string $company,
    string $field,
    string $value,
    array $auth,
    string $ttlKey
): array {
    $normalizedValue = trim($value);
    if ($normalizedValue === '') {
        return [];
    }

    $url = odata_company_url($environment, $company, 'AppResource', [
        '$select' => 'No,Name,E_Mail,KVT_User_ID',
        '$filter' => $field . " eq '" . odata_quote_string($normalizedValue) . "'",
    ]);

    return odata_get_all($url, $auth, odata_ttl($ttlKey));
}

function fetch_app_resources_by_email(string $environment, string $company, string $email, array $auth): array
{
    return fetch_app_resources_by_field($environment, $company, 'E_Mail', $email, $auth, 'resource_by_email');
}

function fetch_user_setup_by_email(string $environment, string $company, string $email, array $auth): array
{
    if ($email === '') {
        return [];
    }

    $url = odata_company_url($environment, $company, 'AppUserSetup', [
        '$select' => 'User_ID,Email',
        '$filter' => "Email eq '" . odata_quote_string($email) . "'",
    ]);

    return odata_get_all($url, $auth, odata_ttl('usersetup_by_email'));
}

function fetch_app_resources_by_user_id(string $environment, string $company, string $userId, array $auth): array
{
    return fetch_app_resources_by_field($environment, $company, 'KVT_User_ID', $userId, $auth, 'resource_by_userid');
}

function fetch_service_resource_row_by_no(string $environment, string $company, string $resourceNo, array $auth): array
{
    $value = trim($resourceNo);
    if ($value === '') {
        return [];
    }

    $url = odata_company_url($environment, $company, 'AppResource', [
        '$select' => 'No,Name,E_Mail,Type,Blocked',
        '$filter' => "No eq '" . odata_quote_string($value) . "'",
    ]);

    $rows = odata_get_all($url, $auth, odata_ttl('resource_by_email'));
    $row = $rows[0] ?? null;
    if (!is_array($row)) {
        return [];
    }

    $type = strtolower(trim((string) ($row['Type'] ?? '')));
    if ($type !== '') {
        $normalizedType = str_replace([' ', '-', '_'], '', $type);
        if (!in_array($normalizedType, ['person', 'persoon'], true)) {
            return [];
        }
    }

    if (is_true_value($row['Blocked'] ?? false)) {
        return [];
    }

    return $row;
}

function fetch_service_resources(string $environment, string $company, array $auth): array
{
    $url = odata_company_url($environment, $company, 'AppResource', [
        '$select' => 'No,Name,E_Mail,Type,Blocked',
        '$filter' => "Blocked eq false",
        '$orderby' => 'Name asc',
    ]);

    $rows = odata_get_all($url, $auth, odata_ttl('resource_by_email'));
    $result = [];
    foreach ($rows as $row) {
        $no = trim((string) ($row['No'] ?? ''));
        $name = trim((string) ($row['Name'] ?? ''));
        $type = strtolower(trim((string) ($row['Type'] ?? '')));

        if ($no === '' || $name === '') {
            continue;
        }

        if ($type !== '') {
            $normalizedType = str_replace([' ', '-', '_'], '', $type);
            if (!in_array($normalizedType, ['person', 'persoon'], true)) {
                continue;
            }
        }

        $result[] = [
            'No' => $no,
            'Name' => $name,
            'E_Mail' => trim((string) ($row['E_Mail'] ?? '')),
        ];
    }

    return $result;
}

function is_task_article_line(array $line): bool
{
    $taskArticleFields = [
        'Taakartikel',
        'Taak_Artikel',
        'Task_Article',
        'KVT_Task_Article',
        'KVT_Taakartikel',
    ];

    foreach ($taskArticleFields as $field) {
        if (array_key_exists($field, $line) && is_true_value($line[$field])) {
            return true;
        }
    }

    return false;
}

function fetch_item_task_flags_map(string $environment, string $company, array $itemNos, array $auth): array
{
    $normalizedNos = [];
    foreach ($itemNos as $itemNo) {
        $value = trim((string) $itemNo);
        if ($value !== '') {
            $normalizedNos[$value] = true;
        }
    }

    $uniqueNos = array_keys($normalizedNos);
    if (empty($uniqueNos)) {
        return [];
    }

    $filter = build_or_filter('No', $uniqueNos);
    if ($filter === '') {
        return [];
    }

    $url = odata_company_url($environment, $company, 'AppItemCard', [
        '$select' => 'No,LVS_Maintenance_Task_Item',
        '$filter' => $filter,
    ]);

    $rows = odata_get_all($url, $auth, odata_ttl('item_task_flags'));
    $result = [];
    foreach ($rows as $row) {
        $itemNo = trim((string) ($row['No'] ?? ''));
        if ($itemNo === '') {
            continue;
        }

        $result[$itemNo] = is_true_value($row['LVS_Maintenance_Task_Item'] ?? false);
    }

    return $result;
}

function fetch_workorder_material_summary_for_workorders(
    string $environment,
    string $company,
    array $workOrderNos,
    array $auth
): array {
    global $statusLabels;

    $normalizedNos = [];
    foreach ($workOrderNos as $workOrderNo) {
        $value = trim((string) $workOrderNo);
        if ($value !== '') {
            $normalizedNos[$value] = true;
        }
    }

    $uniqueNos = array_keys($normalizedNos);
    $counts = [];
    $labels = [];
    $ranks = [];
    $fallbackLabels = [];
    $statusRankByCode = [];
    $statusCodes = array_keys($statusLabels);
    foreach ($statusCodes as $index => $code) {
        $statusRankByCode[strtoupper(trim((string) $code))] = $index;
    }

    foreach ($uniqueNos as $workOrderNo) {
        $counts[$workOrderNo] = 0;
        $labels[$workOrderNo] = 'Onbekend';
        $ranks[$workOrderNo] = PHP_INT_MAX;
        $fallbackLabels[$workOrderNo] = '';
    }

    if (empty($uniqueNos)) {
        return ['counts' => $counts, 'labels' => $labels];
    }

    $allLines = [];
    $itemNos = [];
    $chunks = array_chunk($uniqueNos, 20);
    foreach ($chunks as $chunkNos) {
        $filter = build_or_filter('LVS_Work_Order_No', $chunkNos);
        if ($filter === '') {
            continue;
        }

        $url = odata_company_url($environment, $company, 'LVS_JobPlanningLinesSub', [
            '$select' => 'LVS_Work_Order_No,Type,No,KVT_Status_Material',
            '$filter' => $filter,
        ]);

        $rows = odata_get_all($url, $auth, odata_ttl('planning_lines'));
        foreach ($rows as $row) {
            $type = strtolower(trim((string) ($row['Type'] ?? '')));
            if ($type !== 'item' && $type !== 'artikel') {
                continue;
            }

            $allLines[] = $row;

            $itemNo = trim((string) ($row['No'] ?? ''));
            if ($itemNo !== '') {
                $itemNos[$itemNo] = true;
            }
        }
    }

    $itemTaskFlagsMap = fetch_item_task_flags_map($environment, $company, array_keys($itemNos), $auth);

    foreach ($allLines as $line) {
        $workOrderNo = trim((string) ($line['LVS_Work_Order_No'] ?? ''));
        if ($workOrderNo === '' || !array_key_exists($workOrderNo, $counts)) {
            continue;
        }

        $itemNo = trim((string) ($line['No'] ?? ''));
        $isTaskItemByCard = $itemNo !== '' && !empty($itemTaskFlagsMap[$itemNo]);
        $isTaskItem = $isTaskItemByCard || is_task_article_line($line);
        if ($isTaskItem) {
            continue;
        }

        $counts[$workOrderNo]++;

        $statusRaw = trim((string) ($line['KVT_Status_Material'] ?? ''));
        $statusCode = material_status_code($statusRaw);
        $statusLabel = material_status_label($statusRaw);

        if ($fallbackLabels[$workOrderNo] === '' && $statusLabel !== '') {
            $fallbackLabels[$workOrderNo] = $statusLabel;
        }

        if ($statusCode === '') {
            continue;
        }

        $rank = $statusRankByCode[$statusCode] ?? PHP_INT_MAX;
        if ($rank < $ranks[$workOrderNo]) {
            $ranks[$workOrderNo] = $rank;
            $labels[$workOrderNo] = material_status_label($statusCode);
        }
    }

    foreach ($counts as $workOrderNo => $count) {
        if ($count <= 0) {
            continue;
        }

        if (($labels[$workOrderNo] ?? 'Onbekend') === 'Onbekend' && ($fallbackLabels[$workOrderNo] ?? '') !== '') {
            $labels[$workOrderNo] = $fallbackLabels[$workOrderNo];
        }
    }

    return ['counts' => $counts, 'labels' => $labels];
}

function fetch_webfleet_status_labels_for_workorders(
    string $environment,
    string $company,
    array $workOrderNos,
    array $auth
): array {
    $normalizedNos = [];
    foreach ($workOrderNos as $workOrderNo) {
        $value = trim((string) $workOrderNo);
        if ($value !== '') {
            $normalizedNos[$value] = true;
        }
    }

    $uniqueNos = array_keys($normalizedNos);
    $labels = [];
    foreach ($uniqueNos as $workOrderNo) {
        $labels[$workOrderNo] = webfleet_default_status_label();
    }

    if (empty($uniqueNos)) {
        return $labels;
    }

    $statesByOrder = [];
    foreach ($uniqueNos as $workOrderNo) {
        $statesByOrder[$workOrderNo] = [
            'has_203' => false,
            'has_401' => false,
        ];
    }

    $chunks = array_chunk($uniqueNos, 20);
    foreach ($chunks as $chunkNos) {
        $filter = build_or_filter('Orderno', $chunkNos);
        if ($filter === '') {
            continue;
        }

        $url = odata_company_url($environment, $company, 'WebfleetImportLines', [
            '$select' => 'Orderno,Order_state',
            '$filter' => $filter,
        ]);

        $rows = odata_get_all($url, $auth, odata_ttl('workorders_list'));
        foreach ($rows as $row) {
            $orderNo = trim((string) ($row['Orderno'] ?? ''));
            if ($orderNo === '' || !isset($statesByOrder[$orderNo])) {
                continue;
            }

            $state = (int) ($row['Order_state'] ?? 0);
            if ($state === 203) {
                $statesByOrder[$orderNo]['has_203'] = true;
            } elseif ($state === 401) {
                $statesByOrder[$orderNo]['has_401'] = true;
            }
        }
    }

    foreach ($statesByOrder as $workOrderNo => $flags) {
        $labels[$workOrderNo] = webfleet_status_label_from_flags(
            !empty($flags['has_203']),
            !empty($flags['has_401'])
        );
    }

    return $labels;
}

function fetch_new_workorders_for_resource(
    string $environment,
    string $company,
    string $resourceNo,
    string $lastCheckedAt,
    array $auth
): array {
    $dateThreshold = '1970-01-01';
    if (trim($lastCheckedAt) !== '') {
        try {
            $dateThreshold = (new DateTimeImmutable($lastCheckedAt))->format('Y-m-d');
        } catch (Throwable $throwable) {
            $dateThreshold = '1970-01-01';
        }
    }

    return fetch_workorders_for_resource_by_date(
        $environment,
        $company,
        $resourceNo,
        $dateThreshold,
        false,
        $auth
    );
}

function fetch_workorders_for_resource_by_date(
    string $environment,
    string $company,
    string $resourceNo,
    string $dateThreshold,
    bool $inclusive,
    array $auth
): array {
    $normalizedResourceNo = trim($resourceNo);
    if ($normalizedResourceNo === '') {
        return [];
    }

    $normalizedDate = trim($dateThreshold);
    if ($normalizedDate === '') {
        $normalizedDate = '1970-01-01';
    }

    $operator = $inclusive ? 'ge' : 'gt';
    $filter = "Resource_No eq '" . odata_quote_string($normalizedResourceNo) . "' and Start_Date " . $operator . ' ' . $normalizedDate;
    $url = odata_company_url($environment, $company, 'AppWerkorders', [
        '$select' => 'No,Task_Code,Task_Description,Status,Resource_No,Resource_Name,Main_Entity_Description,Sub_Entity_Description,Component_Description,Serial_No,Start_Date,Start_Time,End_Date,End_Time,External_Document_No,KVT_Status_Purchase_Order,Job_No,Job_Task_No',
        '$filter' => $filter,
        '$orderby' => 'Start_Date asc,Start_Time asc,No asc',
    ]);

    return odata_get_all($url, $auth, odata_ttl('workorders_list'));
}

function fetch_workorders_for_resource_on_day(
    string $environment,
    string $company,
    string $resourceNo,
    string $day,
    array $auth
): array {
    $normalizedResourceNo = trim($resourceNo);
    if ($normalizedResourceNo === '') {
        return [];
    }

    $normalizedDay = trim($day);
    if ($normalizedDay === '') {
        return [];
    }

    $filter = "Resource_No eq '" . odata_quote_string($normalizedResourceNo) . "' and Start_Date ge " . $normalizedDay . ' and Start_Date le ' . $normalizedDay;
    $url = odata_company_url($environment, $company, 'AppWerkorders', [
        '$select' => 'No,Task_Code,Task_Description,Status,Resource_No,Resource_Name,Main_Entity_Description,Sub_Entity_Description,Component_Description,Serial_No,Start_Date,Start_Time,End_Date,End_Time,External_Document_No,KVT_Status_Purchase_Order,Job_No,Job_Task_No',
        '$filter' => $filter,
        '$orderby' => 'Start_Date asc,Start_Time asc,No asc',
    ]);

    return odata_get_all($url, $auth, odata_ttl('workorders_list'));
}

function workorder_link(string $company, string $resourceNo, string $workOrderNo): string
{
    return APP_BASE_URL . '?' . http_build_query([
        'company' => $company,
        'person' => $resourceNo,
        'workorder' => $workOrderNo,
    ], '', '&', PHP_QUERY_RFC3986);
}

function nl_date_safe(?string $value): string
{
    return nl_date($value);
}

function workorder_day_key(string $value): string
{
    $raw = trim($value);
    if ($raw === '') {
        return '';
    }

    return substr($raw, 0, 10);
}

function workorder_day_separator_label(string $value): string
{
    $dayKey = workorder_day_key($value);
    if ($dayKey === '') {
        return 'Onbekende datum';
    }

    $date = DateTimeImmutable::createFromFormat('Y-m-d', $dayKey);
    if (!($date instanceof DateTimeImmutable) || $date->format('Y-m-d') !== $dayKey) {
        return nl_date($dayKey);
    }

    $weekdays = [
        1 => 'maandag',
        2 => 'dinsdag',
        3 => 'woensdag',
        4 => 'donderdag',
        5 => 'vrijdag',
        6 => 'zaterdag',
        7 => 'zondag',
    ];

    $months = [
        1 => 'januari',
        2 => 'februari',
        3 => 'maart',
        4 => 'april',
        5 => 'mei',
        6 => 'juni',
        7 => 'juli',
        8 => 'augustus',
        9 => 'september',
        10 => 'oktober',
        11 => 'november',
        12 => 'december',
    ];

    $weekdayIndex = (int) $date->format('N');
    $weekday = (string) ($weekdays[$weekdayIndex] ?? '');
    $dayOfMonth = (int) $date->format('j');
    $monthIndex = (int) $date->format('n');
    $month = (string) ($months[$monthIndex] ?? '');
    $year = $date->format('Y');
    $formattedDate = $month !== ''
        ? ($dayOfMonth . ' ' . $month . ' ' . $year)
        : nl_date($dayKey);
    if ($weekday === '') {
        return $formattedDate;
    }

    return trim($weekday . ' ' . $formattedDate);
}

function safe_text_html(string $value, string $fallback = '-'): string
{
    $trimmed = trim($value);
    if ($trimmed === '') {
        $trimmed = $fallback;
    }

    return htmlspecialchars($trimmed, ENT_QUOTES, 'UTF-8');
}

function subject_count_text(int $count): string
{
    $normalizedCount = max(0, $count);
    $words = [
        1 => 'een',
        2 => 'twee',
        3 => 'drie',
        4 => 'vier',
        5 => 'vijf',
        6 => 'zes',
        7 => 'zeven',
        8 => 'acht',
        9 => 'negen',
        10 => 'tien',
        11 => 'elf',
        12 => 'twaalf',
    ];

    if (isset($words[$normalizedCount])) {
        return $words[$normalizedCount];
    }

    return (string) $normalizedCount;
}

function notification_subject(string $subjectPrefix, int $newWorkOrderCount): string
{
    return trim($subjectPrefix) . ' - ' . subject_count_text($newWorkOrderCount) . ' nieuwe werkorders ontvangen';
}

function daily_overview_subject(string $subjectPrefix, string $day): string
{
    return trim($subjectPrefix) . ' - dagelijks overzicht ' . trim($day);
}

function build_email_html(string $company, string $resourceNo, string $resourceName, array $workOrders, array $materialCounts, array $materialLabels, array $webfleetLabels): string
{
    $cardsHtml = '';
    $previousWorkOrderDayKey = '';

    $badgeBaseStyle = 'display:inline-block;border-radius:999px;padding:3px 9px;font-size:12px;font-weight:600;white-space:nowrap;border:1px solid transparent;line-height:1.25;';
    $badgeStyles = [
        'ok' => $badgeBaseStyle . 'color:#1d8a4c;border-color:#bce4cb;background:#eef9f2;',
        'warn' => $badgeBaseStyle . 'color:#ad6f1a;border-color:#ead8b7;background:#fbf5ea;',
        'neutral' => $badgeBaseStyle . 'color:#637588;border-color:#d7e0e8;background:#f7f9fb;',
        'unknown' => $badgeBaseStyle . 'color:#8e2a2a;border-color:#f2cdcd;background:#fff3f3;',
        'status-open' => $badgeBaseStyle . 'color:#152233;border-color:#cfd8e2;background:#d1d1d1;',
        'status-getekend' => $badgeBaseStyle . 'color:#152233;border-color:#cfd8e2;background:#f6f9e9;',
        'status-uitgevoerd' => $badgeBaseStyle . 'color:#152233;border-color:#cfd8e2;background:#efd4ff;',
        'status-gecontroleerd' => $badgeBaseStyle . 'color:#152233;border-color:#cfd8e2;background:#fff8cf;',
        'status-geannuleerd' => $badgeBaseStyle . 'color:#152233;border-color:#cfd8e2;background:#ffa7a7;',
        'status-afgesloten' => $badgeBaseStyle . 'color:#152233;border-color:#cfd8e2;background:#948d8d;',
        'status-gepland' => $badgeBaseStyle . 'color:#152233;border-color:#cfd8e2;background:#d4ffda;',
        'status-onderhanden' => $badgeBaseStyle . 'color:#152233;border-color:#cfd8e2;background:#ffeedd;',
        'status-gefactureerd' => $badgeBaseStyle . 'color:#152233;border-color:#cfd8e2;background:#8ec9ba;',
    ];

    foreach ($workOrders as $workOrder) {
        $workOrderNo = trim((string) ($workOrder['No'] ?? ''));
        if ($workOrderNo === '') {
            continue;
        }

        $workOrderStartDateRaw = (string) ($workOrder['Start_Date'] ?? '');
        $workOrderDayKey = workorder_day_key($workOrderStartDateRaw);
        if ($workOrderDayKey === '') {
            $workOrderDayKey = '__onbekend__';
        }
        $showDaySeparator = $workOrderDayKey !== $previousWorkOrderDayKey;
        if ($showDaySeparator) {
            $previousWorkOrderDayKey = $workOrderDayKey;
            $separatorText = safe_text_html(workorder_day_separator_label($workOrderStartDateRaw), 'Onbekende datum');
            $cardsHtml .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:collapse;margin:2px 0;">';
            $cardsHtml .= '<tr>';
            $cardsHtml .= '<td style="border-top:1px solid #dbe4ee;font-size:1px;line-height:1px;">&nbsp;</td>';
            $cardsHtml .= '<td style="white-space:nowrap;padding:0 10px;color:#5f7287;font-size:13px;font-weight:600;line-height:1.3;">' . $separatorText . '</td>';
            $cardsHtml .= '<td style="border-top:1px solid #dbe4ee;font-size:1px;line-height:1px;">&nbsp;</td>';
            $cardsHtml .= '</tr>';
            $cardsHtml .= '</table>';
        }

        $href = workorder_link($company, $resourceNo, $workOrderNo);
        $workOrderStatusText = trim((string) ($workOrder['Status'] ?? ''));
        $workOrderStatusClass = status_css_class($workOrderStatusText);
        $workOrderBadgeStyle = $badgeStyles[$workOrderStatusClass] ?? $badgeStyles['neutral'];

        $webfleetStatusLabel = trim((string) ($webfleetLabels[$workOrderNo] ?? webfleet_default_status_label()));
        if ($webfleetStatusLabel === '') {
            $webfleetStatusLabel = webfleet_default_status_label();
        }
        $webfleetClass = webfleet_status_badge_class($webfleetStatusLabel);
        $webfleetBadgeStyle = $badgeStyles[$webfleetClass] ?? $badgeStyles['status-gepland'];

        $realArticleCount = (int) ($materialCounts[$workOrderNo] ?? 0);
        $materialStatusLabel = trim((string) ($materialLabels[$workOrderNo] ?? 'Onbekend'));
        if ($materialStatusLabel === '') {
            $materialStatusLabel = 'Onbekend';
        }
        $materialClass = workorder_material_badge_class($materialStatusLabel);
        $materialBadgeStyle = $badgeStyles[$materialClass] ?? $badgeStyles['unknown'];

        $startTime = format_workorder_time_value((string) ($workOrder['Start_Time'] ?? ''));
        $endTime = format_workorder_time_value((string) ($workOrder['End_Time'] ?? ''));
        $timeRangeText = '';
        if ($startTime !== '' && $endTime !== '') {
            $timeRangeText = $startTime . ' t/m ' . $endTime;
        } elseif ($startTime !== '') {
            $timeRangeText = $startTime;
        } elseif ($endTime !== '') {
            $timeRangeText = $endTime;
        }

        $cardsHtml .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:separate;border-spacing:0;background:#ffffff;border:1px solid #dbe4ee;border-radius:12px;margin:0 0 10px 0;">';
        $cardsHtml .= '<tr>';
        $cardsHtml .= '<td style="padding:12px;">';

        $cardsHtml .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:collapse;">';
        $cardsHtml .= '<tr>';
        $cardsHtml .= '<td style="vertical-align:top;padding:0 8px 0 0;">';
        $cardsHtml .= '<p style="margin:0 0 2px 0;font-size:15px;font-weight:700;color:#152233;line-height:1.3;">' . safe_text_html($workOrderNo) . '</p>';
        $cardsHtml .= '<p style="margin:0;font-size:16px;line-height:1.35;color:#152233;">' . safe_text_html(workorder_task_text($workOrder)) . '</p>';
        $cardsHtml .= '</td>';
        $cardsHtml .= '<td style="vertical-align:top;text-align:right;white-space:nowrap;">';
        $cardsHtml .= '<div style="margin:0 0 6px 0;"><span style="' . htmlspecialchars($webfleetBadgeStyle, ENT_QUOTES, 'UTF-8') . '">' . safe_text_html($webfleetStatusLabel) . '</span></div>';
        $cardsHtml .= '<div><span style="' . htmlspecialchars($workOrderBadgeStyle, ENT_QUOTES, 'UTF-8') . '">' . safe_text_html($workOrderStatusText) . '</span></div>';
        $cardsHtml .= '</td>';
        $cardsHtml .= '</tr>';
        $cardsHtml .= '</table>';

        $cardsHtml .= '<div style="margin-top:6px;font-size:14px;color:#5f7287;line-height:1.35;">';
        $cardsHtml .= '<b>Uitvoerdatum</b>: ' . safe_text_html(nl_date_safe((string) ($workOrder['Start_Date'] ?? '')));
        if ($timeRangeText !== '') {
            $cardsHtml .= ' · ' . safe_text_html($timeRangeText);
        }
        $cardsHtml .= '<br />';
        $cardsHtml .= '<b>Object</b>: ' . safe_text_html((string) ($workOrder['Main_Entity_Description'] ?? '-')) . '<br />';
        $cardsHtml .= '<b>Component</b>: ' . safe_text_html((string) ($workOrder['Component_Description'] ?? '-')) . '<br />';
        $cardsHtml .= '<b>Materiaal nodig</b>: ' . safe_text_html(material_needed_text($realArticleCount));
        if ($realArticleCount > 0) {
            $cardsHtml .= '<br /><b>Materiaalstatus</b>: <span style="' . htmlspecialchars($materialBadgeStyle, ENT_QUOTES, 'UTF-8') . '">' . safe_text_html($materialStatusLabel) . '</span>';
        }
        $cardsHtml .= '</div>';

        $cardsHtml .= '<div style="margin-top:10px;"><a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;color:#0f5bb7;text-decoration:none;font-size:14px;font-weight:600;">Open werkorder</a></div>';
        $cardsHtml .= '</td>';
        $cardsHtml .= '</tr>';
        $cardsHtml .= '</table>';
    }

    return '<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>'
        . '<body style="margin:0;padding:0;background:#f2f5f9;color:#152233;font-family:Segoe UI,Roboto,Arial,sans-serif;">'
        . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:collapse;background:#f2f5f9;">'
        . '<tr><td align="center" style="padding:12px;">'
        . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:760px;border-collapse:collapse;">'
        . '<tr><td style="padding:0 0 4px 0;font-size:24px;font-weight:700;line-height:1.3;color:#152233;">Nieuwe werkorders</td></tr>'
        . '<tr><td style="padding:0 0 12px 0;font-size:14px;line-height:1.35;color:#5f7287;">Bedrijf: ' . safe_text_html($company) . ' · Monteur: ' . safe_text_html($resourceName !== '' ? $resourceName : $resourceNo) . '</td></tr>'
        . '<tr><td>' . $cardsHtml . '</td></tr>'
        . '</table>'
        . '</td></tr>'
        . '</table>'
        . '</body></html>';
}

function smtp_read_response($socket): string
{
    $response = '';

    while (!feof($socket)) {
        $line = fgets($socket, 512);
        if (!is_string($line)) {
            break;
        }

        $response .= $line;
        if (strlen($line) < 4) {
            break;
        }

        if (preg_match('/^\d{3}\s/', $line) === 1) {
            break;
        }
    }

    return $response;
}

function smtp_expect_response($socket, array $expectedCodes): string
{
    $response = smtp_read_response($socket);
    $code = (int) substr(trim($response), 0, 3);

    if (!in_array($code, $expectedCodes, true)) {
        throw new RuntimeException('SMTP fout: ' . trim($response));
    }

    return $response;
}

function smtp_send_command($socket, string $command, array $expectedCodes): string
{
    $bytes = fwrite($socket, $command . "\r\n");
    if ($bytes === false) {
        throw new RuntimeException('SMTP command kon niet worden verzonden.');
    }

    return smtp_expect_response($socket, $expectedCodes);
}

function smtp_send_html_mail(array $mailConfig, string $toEmail, string $toName, string $subject, string $html): void
{
    $smtp = is_array($mailConfig['smtp'] ?? null) ? $mailConfig['smtp'] : [];
    $host = trim((string) ($smtp['host'] ?? ''));
    $port = (int) ($smtp['port'] ?? 587);
    $username = trim((string) ($smtp['username'] ?? ''));
    $password = (string) ($smtp['password'] ?? '');
    $encryption = strtolower(trim((string) ($smtp['encryption'] ?? 'tls')));
    $timeout = (int) ($smtp['timeout'] ?? 20);

    $fromEmail = trim((string) ($mailConfig['from_email'] ?? $username));
    $fromName = trim((string) ($mailConfig['from_name'] ?? 'Daedalus'));

    if ($host === '' || $fromEmail === '' || $toEmail === '') {
        throw new RuntimeException('Ontbrekende SMTP of e-mailgegevens.');
    }

    $socket = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, $timeout);
    if (!is_resource($socket)) {
        throw new RuntimeException('SMTP connectie mislukt: ' . $errstr . ' (' . $errno . ')');
    }

    stream_set_timeout($socket, $timeout);

    try {
        smtp_expect_response($socket, [220]);
        smtp_send_command($socket, 'EHLO sleutels.kvt.nl', [250]);

        if ($encryption === 'tls') {
            smtp_send_command($socket, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('STARTTLS kon niet worden gestart.');
            }
            smtp_send_command($socket, 'EHLO sleutels.kvt.nl', [250]);
        }

        if ($username !== '') {
            smtp_send_command($socket, 'AUTH LOGIN', [334]);
            smtp_send_command($socket, base64_encode($username), [334]);
            smtp_send_command($socket, base64_encode($password), [235]);
        }

        smtp_send_command($socket, 'MAIL FROM:<' . $fromEmail . '>', [250]);
        smtp_send_command($socket, 'RCPT TO:<' . $toEmail . '>', [250, 251]);
        smtp_send_command($socket, 'DATA', [354]);

        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $encodedFromName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
        $encodedToName = trim($toName) !== ''
            ? ('=?UTF-8?B?' . base64_encode($toName) . '?=')
            : '';

        $headers = [
            'Date: ' . gmdate('D, d M Y H:i:s O'),
            'From: ' . $encodedFromName . ' <' . $fromEmail . '>',
            'To: ' . ($encodedToName !== '' ? ($encodedToName . ' ') : '') . '<' . $toEmail . '>',
            'Subject: ' . $encodedSubject,
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];

        $data = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($html), 76, "\r\n") . "\r\n.\r\n";
        $bytes = fwrite($socket, $data);
        if ($bytes === false) {
            throw new RuntimeException('SMTP DATA kon niet worden verzonden.');
        }

        smtp_expect_response($socket, [250]);
        smtp_send_command($socket, 'QUIT', [221]);
    } finally {
        fclose($socket);
    }
}

/**
 * Page load
 */
if (!defined('DAEDALUS_EMAIL_NOTIFICATIONS_LIB_ONLY')) {
    header('Content-Type: application/json; charset=utf-8');

    try {
        $userFiles = list_user_cache_files();

        foreach ($userFiles as $userFile) {
            $summary['processed']++;
            $email = email_from_user_cache_path($userFile);
            if ($email === '' || $email === 'onbekend') {
                $summary['skipped']++;
                continue;
            }

            $payload = read_user_payload($userFile);
            $notificationSettings = normalize_notification_settings(is_array($payload['notification_settings'] ?? null) ? $payload['notification_settings'] : []);

            if (empty($notificationSettings['enabled'])) {
                $summary['skipped']++;
                continue;
            }

            $company = trim((string) ($notificationSettings['company'] ?? ''));
            $resourceNo = trim((string) ($notificationSettings['resource_no'] ?? ''));
            if ($company === '' || $resourceNo === '') {
                $summary['skipped']++;
                continue;
            }

            try {
                $serviceResource = fetch_service_resource_row_by_no($environment, $company, $resourceNo, $auth);
                if (empty($serviceResource)) {
                    $summary['skipped']++;
                    continue;
                }

                $resourcesForEmail = fetch_app_resources_by_email($environment, $company, $email, $auth);
                if (count($resourcesForEmail) === 0) {
                    $userSetupRows = fetch_user_setup_by_email($environment, $company, $email, $auth);
                    foreach ($userSetupRows as $userSetupRow) {
                        $userId = trim((string) ($userSetupRow['User_ID'] ?? ''));
                        if ($userId === '') {
                            continue;
                        }

                        $resourcesForEmail = array_merge(
                            $resourcesForEmail,
                            fetch_app_resources_by_user_id($environment, $company, $userId, $auth)
                        );
                    }
                }

                $emailResourceNos = [];
                foreach ($resourcesForEmail as $resourceRow) {
                    $candidate = trim((string) ($resourceRow['No'] ?? ''));
                    if ($candidate !== '') {
                        $emailResourceNos[$candidate] = true;
                    }
                }

                if (!isset($emailResourceNos[$resourceNo])) {
                    $summary['skipped']++;
                    continue;
                }

                $resourceName = trim((string) ($serviceResource['Name'] ?? $notificationSettings['resource_name'] ?? ''));
                $subjectPrefix = trim((string) ($reportMail['subject_prefix'] ?? 'Daedalus'));
                $todayUtc = gmdate('Y-m-d');
                $dailyOverviewEnabled = !empty($notificationSettings['daily_overview_enabled']);
                $dailyOverviewLastSentDate = trim((string) ($notificationSettings['daily_overview_last_sent_date'] ?? ''));

                if ($dailyOverviewEnabled && $dailyOverviewLastSentDate !== $todayUtc) {
                    $dailyWorkOrders = fetch_workorders_for_resource_on_day(
                        $environment,
                        $company,
                        $resourceNo,
                        $todayUtc,
                        $auth
                    );

                    if (!empty($dailyWorkOrders)) {
                        $dailyWorkOrderNos = array_map(static fn(array $workOrder): string => trim((string) ($workOrder['No'] ?? '')), $dailyWorkOrders);
                        $dailyMaterialSummary = fetch_workorder_material_summary_for_workorders($environment, $company, $dailyWorkOrderNos, $auth);
                        $dailyWebfleetLabels = fetch_webfleet_status_labels_for_workorders($environment, $company, $dailyWorkOrderNos, $auth);

                        $dailyEmailHtml = build_email_html(
                            $company,
                            $resourceNo,
                            $resourceName,
                            $dailyWorkOrders,
                            is_array($dailyMaterialSummary['counts'] ?? null) ? $dailyMaterialSummary['counts'] : [],
                            is_array($dailyMaterialSummary['labels'] ?? null) ? $dailyMaterialSummary['labels'] : [],
                            $dailyWebfleetLabels
                        );

                        $dailySubject = daily_overview_subject($subjectPrefix, $todayUtc);

                        smtp_send_html_mail(
                            $reportMail,
                            $email,
                            $resourceName,
                            $dailySubject,
                            $dailyEmailHtml
                        );

                        $summary['daily_overview_emails_sent']++;
                        $summary['daily_overview_orders_sent'] += count($dailyWorkOrders);
                        $summary['emails_sent_total']++;
                        $summary['orders_sent_total'] += count($dailyWorkOrders);
                        $summary['emails_sent']++;
                        $summary['orders_sent'] += count($dailyWorkOrders);
                    }

                    $notificationSettings['daily_overview_last_sent_date'] = $todayUtc;
                }

                $newWorkOrders = fetch_new_workorders_for_resource(
                    $environment,
                    $company,
                    $resourceNo,
                    (string) ($notificationSettings['last_checked_at'] ?? ''),
                    $auth
                );

                if (!empty($newWorkOrders)) {
                    $workOrderNos = array_map(static fn(array $workOrder): string => trim((string) ($workOrder['No'] ?? '')), $newWorkOrders);
                    $materialSummary = fetch_workorder_material_summary_for_workorders($environment, $company, $workOrderNos, $auth);
                    $webfleetLabels = fetch_webfleet_status_labels_for_workorders($environment, $company, $workOrderNos, $auth);

                    $emailHtml = build_email_html(
                        $company,
                        $resourceNo,
                        $resourceName,
                        $newWorkOrders,
                        is_array($materialSummary['counts'] ?? null) ? $materialSummary['counts'] : [],
                        is_array($materialSummary['labels'] ?? null) ? $materialSummary['labels'] : [],
                        $webfleetLabels
                    );

                    $subject = notification_subject($subjectPrefix, count($newWorkOrders));

                    smtp_send_html_mail(
                        $reportMail,
                        $email,
                        $resourceName,
                        $subject,
                        $emailHtml
                    );

                    $summary['new_workorder_emails_sent']++;
                    $summary['new_workorder_orders_sent'] += count($newWorkOrders);
                    $summary['emails_sent_total']++;
                    $summary['orders_sent_total'] += count($newWorkOrders);
                    $summary['emails_sent']++;
                    $summary['orders_sent'] += count($newWorkOrders);

                    $latestNewWorkOrderDatetime = latest_workorder_datetime_iso($newWorkOrders);
                    $notificationSettings = notification_settings_with_forward_last_checked($notificationSettings, $latestNewWorkOrderDatetime);
                }

                $payload['notification_settings'] = $notificationSettings;
                write_user_payload($userFile, $payload);
            } catch (Throwable $throwable) {
                $summary['errors'][] = [
                    'email' => $email,
                    'company' => $company,
                    'resource_no' => $resourceNo,
                    'message' => $throwable->getMessage(),
                ];
            }
        }

        echo json_encode([
            'ok' => true,
            'summary' => $summary,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $throwable) {
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'error' => $throwable->getMessage(),
            'summary' => $summary,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
