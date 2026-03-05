<?php
require __DIR__ . "/auth.php";
require_once __DIR__ . "/logincheck.php";
require_once __DIR__ . '/functions.php';
require_once __DIR__ . "/odata.php";

ob_start();
register_shutdown_function(function () {
    $error = error_get_last();
    if (!$error || ($error['type'] ?? 0) !== E_ERROR) {
        return;
    }

    $message = (string) ($error['message'] ?? '');
    $isTimeout = stripos($message, 'Maximum execution time') !== false
        && stripos($message, '120') !== false
        && stripos($message, 'second') !== false;

    if (!$isTimeout) {
        return;
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $refreshUrl = htmlspecialchars((string) ($_SERVER['REQUEST_URI'] ?? 'overzicht.php'), ENT_QUOTES, 'UTF-8');
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    header('Retry-After: 5');

    echo '<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<meta http-equiv="refresh" content="5;url=' . $refreshUrl . '">';
    echo '<title>Even geduld</title></head><body style="font-family:Verdana,Geneva,Tahoma,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0">';
    echo '<div style="text-align:center;padding:24px">Er is meer tijd nodig om gegevens te laden.<br>De pagina wordt automatisch vernieuwd...</div>';
    echo '<script>setTimeout(function(){location.reload();},5000);</script>';
    echo '</body></html>';
});

$statuses = [
    "O" => "Onbekend", //nog niet gecontroleerd
    "N" => "Niet nodig", //geen materiaal nodig voor order
    "X" => "Niet op tijd", //1 of meerdere materialen en/of gereedschap
    "T" => "Te laat", //inkooporder is (net) te laat
    "I" => "Inkooporder aanwezig", //Inkooporder is er en is op tijd
    "V" => "Voorraad", //Is gereserveerd in voorraad
    "G" => "Gepicked", //Zijn uitgezocht en in de juiste bak gelegd
    "B" => "Uitgegeven", //De blauwe bak is vanuit magazijn uitgegeven aan de service engineer
    "A" => "Aangenomen", //Aangenomen door Service Engineer en gecontroleerd
    "C" => "Gecontroleerd" //SE heeft controle uitgevoerd op verbruik materiaal en geboekt
];

$loadingTextOptions = [];
$loadingTextInitial = '';
$loadingTextsPath = __DIR__ . '/loadingTexts.php';
if (is_file($loadingTextsPath)) {
    require_once $loadingTextsPath;
    if (isset($texts) && is_array($texts)) {
        $loadingTextOptions = array_values(array_filter($texts, static function ($value): bool {
            return is_string($value) && trim($value) !== '';
        }));
    }
    if (function_exists('getRandomLoadingText')) {
        $loadingTextInitial = (string) getRandomLoadingText();
    }
}
if ($loadingTextInitial === '' && !empty($loadingTextOptions)) {
    $loadingTextInitial = (string) $loadingTextOptions[array_rand($loadingTextOptions)];
}

$companies = [
    'Koninklijke van Twist',
    'Hunter van Twist',
    'KVT Gas',
];

$minute = 60;
$hour = $minute * 60;
$day = $hour * 24;
$week = $day * 7;

$odataTtl = [
    'resource_by_email' => $week,
    'usersetup_by_email' => $day,
    'resource_by_userid' => $week,
    'service_resources' => $day,
    'workorders_counts' => $hour,
    'werkorders_material_flags' => $hour,
    'workorders_list' => $hour,
    'workorder_detail' => $minute * 15,
    'planning_lines' => $minute * 15,
    'item_task_flags' => $hour,
    'bin_lookup' => $minute * 15,
];

$sessionUserEmail = normalize_email((string) ($_SESSION['user']['email'] ?? ''));
$userEmail = $sessionUserEmail;
if ($userEmail === '') {
    $userEmail = 'ict@kvt.nl';
}
$statusFilterOwnerEmail = $sessionUserEmail;
$selectedWorkOrderNo = trim((string) ($_GET['workorder'] ?? ''));
$selectedPersonNoRequest = trim((string) ($_GET['person'] ?? ''));
$searchQuery = trim((string) ($_GET['q'] ?? ''));
$statusFiltersRequest = trim((string) ($_GET['status_filters'] ?? ''));
$webfleetStatusFiltersRequest = trim((string) ($_GET['webfleet_status_filters'] ?? ''));
$dateFromRequest = trim((string) ($_GET['date_from'] ?? ''));
$dateToRequest = trim((string) ($_GET['date_to'] ?? ''));
$ajaxAction = trim((string) ($_GET['ajax'] ?? ''));
$hasStatusFiltersRequest = array_key_exists('status_filters', $_GET);
$hasWebfleetStatusFiltersRequest = array_key_exists('webfleet_status_filters', $_GET);

function get_sharepoint_url($selectedWorkOrder)
{
    return "https://kvtnl.sharepoint.com/sites/KVTAlgemeen/gedeelde%20documenten/General/Equipments/{$selectedWorkOrder['Component_No']}%20-%20{$selectedWorkOrder['Component_Description']}";
}

function user_pref_path(): string
{
    return __DIR__ . '/cache/user-company-preferences.json';
}

function status_catalog_path(): string
{
    return __DIR__ . '/cache/statuses.json';
}

function normalize_email(string $email): string
{
    return trim(strtolower($email));
}

function user_status_filters_path(string $email): string
{
    $normalizedEmail = normalize_email($email);
    if ($normalizedEmail === '') {
        return __DIR__ . '/cache/users/onbekend.json';
    }

    $safeEmail = str_replace(["\\", '/', "\0"], '_', $normalizedEmail);
    return __DIR__ . '/cache/users/' . $safeEmail . '.json';
}

function request_client_ip(): string
{
    $candidates = [
        (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''),
        (string) ($_SERVER['HTTP_X_REAL_IP'] ?? ''),
        (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
    ];

    foreach ($candidates as $candidate) {
        $value = trim($candidate);
        if ($value === '') {
            continue;
        }

        if (strpos($value, ',') !== false) {
            $parts = array_map('trim', explode(',', $value));
            $value = trim((string) ($parts[0] ?? ''));
        }

        if ($value !== '') {
            return $value;
        }
    }

    return 'onbekend';
}

function request_location_hint(): string
{
    $city = trim((string) ($_SERVER['HTTP_X_CITY'] ?? $_SERVER['HTTP_CF_IPCITY'] ?? $_SERVER['HTTP_X_APPENGINE_CITY'] ?? ''));
    $region = trim((string) ($_SERVER['HTTP_X_REGION'] ?? $_SERVER['HTTP_CF_REGION'] ?? $_SERVER['HTTP_X_APPENGINE_REGION'] ?? ''));
    $country = trim((string) ($_SERVER['HTTP_X_COUNTRY_CODE'] ?? $_SERVER['HTTP_CF_IPCOUNTRY'] ?? $_SERVER['HTTP_X_APPENGINE_COUNTRY'] ?? ''));

    $parts = [];
    if ($city !== '') {
        $parts[] = $city;
    }
    if ($region !== '') {
        $parts[] = $region;
    }
    if ($country !== '') {
        $parts[] = strtoupper($country);
    }

    if (!empty($parts)) {
        return implode(', ', $parts);
    }

    return 'onbekend';
}

function parse_user_agent_device(string $userAgent): array
{
    $ua = trim($userAgent);
    if ($ua === '') {
        return ['brand' => 'onbekend', 'model' => 'onbekend', 'name' => 'onbekend'];
    }

    $lower = strtolower($ua);
    if (strpos($lower, 'iphone') !== false) {
        return ['brand' => 'Apple', 'model' => 'iPhone', 'name' => 'Apple iPhone'];
    }
    if (strpos($lower, 'ipad') !== false) {
        return ['brand' => 'Apple', 'model' => 'iPad', 'name' => 'Apple iPad'];
    }
    if (strpos($lower, 'macintosh') !== false || strpos($lower, 'mac os') !== false) {
        return ['brand' => 'Apple', 'model' => 'Mac', 'name' => 'Apple Mac'];
    }
    if (strpos($lower, 'windows') !== false) {
        return ['brand' => 'Microsoft', 'model' => 'Windows PC', 'name' => 'Windows PC'];
    }

    if (strpos($lower, 'android') !== false) {
        $model = 'Android';
        if (preg_match('/Android[^;]*;\s*([^;\)]+)/i', $ua, $matches) === 1) {
            $candidate = trim((string) ($matches[1] ?? ''));
            if ($candidate !== '' && stripos($candidate, 'Build/') === false) {
                $model = $candidate;
            }
        }

        $brand = 'Android';
        $brandMap = ['samsung', 'huawei', 'xiaomi', 'oneplus', 'oppo', 'vivo', 'sony', 'nokia', 'motorola', 'google'];
        foreach ($brandMap as $brandCandidate) {
            if (strpos($lower, $brandCandidate) !== false) {
                $brand = ucfirst($brandCandidate);
                break;
            }
        }

        return [
            'brand' => $brand,
            'model' => $model,
            'name' => trim($brand . ' ' . $model),
        ];
    }

    return ['brand' => 'onbekend', 'model' => 'onbekend', 'name' => 'onbekend'];
}

function current_status_filter_metadata(string $email): array
{
    $userAgent = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $device = parse_user_agent_device($userAgent);
    $normalizedEmail = normalize_email($email);

    return [
        'email' => $normalizedEmail !== '' ? $normalizedEmail : 'onbekend',
        'ip_address' => request_client_ip(),
        'location' => request_location_hint(),
        'user_agent' => $userAgent !== '' ? $userAgent : 'onbekend',
        'device_brand' => (string) ($device['brand'] ?? 'onbekend'),
        'device_model' => (string) ($device['model'] ?? 'onbekend'),
        'device_name' => (string) ($device['name'] ?? 'onbekend'),
        'updated_at' => gmdate('c'),
    ];
}

function read_user_status_filters_payload(string $path): array
{
    $raw = read_json_assoc_file($path);
    $filtersRaw = is_array($raw['filters'] ?? null) ? $raw['filters'] : $raw;
    $filters = normalize_status_filter_map($filtersRaw);
    $webfleetFiltersRaw = is_array($raw['webfleet_filters'] ?? null) ? $raw['webfleet_filters'] : [];
    $webfleetFilters = normalize_webfleet_filter_map($webfleetFiltersRaw);
    $meta = is_array($raw['meta'] ?? null) ? $raw['meta'] : [];

    return [
        'filters' => $filters,
        'webfleet_filters' => $webfleetFilters,
        'meta' => $meta,
    ];
}

function write_user_status_filters_payload(string $path, array $filters, array $meta, ?array $webfleetFilters = null): void
{
    $existingPayload = read_user_status_filters_payload($path);
    $persistedWebfleetFilters = is_array($existingPayload['webfleet_filters'] ?? null)
        ? $existingPayload['webfleet_filters']
        : [];

    if (is_array($webfleetFilters)) {
        $persistedWebfleetFilters = normalize_status_filter_map($webfleetFilters);
    }

    write_json_assoc_file($path, [
        'filters' => normalize_status_filter_map($filters),
        'webfleet_filters' => $persistedWebfleetFilters,
        'meta' => $meta,
    ]);
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

function read_user_company_preferences(): array
{
    return read_json_assoc_file(user_pref_path());
}

function write_user_company_preferences(array $data): void
{
    write_json_assoc_file(user_pref_path(), $data);
}

function get_user_company_preference(string $email): string
{
    if ($email === '') {
        return '';
    }

    $data = read_user_company_preferences();
    $value = $data[$email] ?? '';
    return is_string($value) ? trim($value) : '';
}

function set_user_company_preference(string $email, string $company): void
{
    if ($email === '' || $company === '') {
        return;
    }

    $data = read_user_company_preferences();
    $data[$email] = $company;
    write_user_company_preferences($data);
}

function in_companies(string $company, array $companies): bool
{
    return in_array($company, $companies, true);
}

function odata_ttl(string $key, int $fallback = 120): int
{
    global $odataTtl;
    $value = (int) ($odataTtl[$key] ?? $fallback);
    return $value > 0 ? $value : $fallback;
}

function parse_date_ymd(string $value): ?DateTimeImmutable
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $value);
    if (!($parsed instanceof DateTimeImmutable)) {
        return null;
    }

    if ($parsed->format('Y-m-d') !== $value) {
        return null;
    }

    return $parsed;
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

function country_calling_code(string $countryCode): string
{
    $code = strtoupper(trim($countryCode));
    if ($code === '') {
        return '';
    }

    $map = [
        'NL' => '+31',
        'BE' => '+32',
        'DE' => '+49',
        'FR' => '+33',
        'GB' => '+44',
        'UK' => '+44',
        'IE' => '+353',
        'ES' => '+34',
        'IT' => '+39',
        'PT' => '+351',
        'PL' => '+48',
        'DK' => '+45',
        'SE' => '+46',
        'NO' => '+47',
        'FI' => '+358',
        'US' => '+1',
        'CA' => '+1',
    ];

    return (string) ($map[$code] ?? '');
}

function phone_tel_href(string $phone, string $countryCode = ''): string
{
    $value = trim($phone);
    if ($value === '') {
        return '';
    }

    $normalized = preg_replace('/[^0-9+]/', '', $value);
    $normalized = is_string($normalized) ? trim($normalized) : '';
    if ($normalized === '') {
        return '';
    }

    if (strpos($normalized, '00') === 0) {
        $normalized = '+' . substr($normalized, 2);
    }

    if (strpos($normalized, '+') !== 0) {
        $callingCode = country_calling_code($countryCode);
        if ($callingCode !== '') {
            $nationalNumber = preg_replace('/^0+/', '', $normalized);
            $nationalNumber = is_string($nationalNumber) ? $nationalNumber : $normalized;
            $normalized = $callingCode . $nationalNumber;
        }
    }

    $normalized = preg_replace('/(?!^)\+/', '', $normalized);
    return is_string($normalized) ? trim($normalized) : '';
}

function country_code_flag_emoji(string $countryCode): string
{
    $code = strtoupper(trim($countryCode));
    if (strlen($code) !== 2 || preg_match('/^[A-Z]{2}$/', $code) !== 1) {
        return '';
    }

    $first = 127397 + ord($code[0]);
    $second = 127397 + ord($code[1]);
    return html_entity_decode('&#' . $first . ';&#' . $second . ';', ENT_QUOTES, 'UTF-8');
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

function fetch_service_resources(string $environment, string $company, array $auth): array
{
    $url = odata_company_url($environment, $company, 'AppResource', [
        '$select' => 'No,Name,E_Mail,Type,Blocked',
        '$filter' => "Blocked eq false",
        '$orderby' => 'Name asc',
    ]);

    $rows = odata_get_all($url, $auth, odata_ttl('service_resources'));
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

function map_resource_summary(array $resource): array
{
    return [
        'No' => trim((string) ($resource['No'] ?? '')),
        'Name' => trim((string) ($resource['Name'] ?? '')),
        'E_Mail' => trim((string) ($resource['E_Mail'] ?? '')),
    ];
}

function resolve_selected_resource_name(string $selectedResourceNo, array $serviceResourceMap, array $resourcesForUser): string
{
    $normalizedSelectedResourceNo = trim($selectedResourceNo);
    if ($normalizedSelectedResourceNo === '') {
        return '';
    }

    if (isset($serviceResourceMap[$normalizedSelectedResourceNo])) {
        return safe_text((string) ($serviceResourceMap[$normalizedSelectedResourceNo]['Name'] ?? ''));
    }

    foreach ($resourcesForUser as $resource) {
        if (trim((string) ($resource['No'] ?? '')) === $normalizedSelectedResourceNo) {
            return safe_text((string) ($resource['Name'] ?? ''));
        }
    }

    return '';
}

function merged_status_catalog(array $statusCatalog, array $availableStatuses): array
{
    $catalogMap = [];
    foreach ($statusCatalog as $statusValue) {
        $catalogMap[(string) $statusValue] = true;
    }

    foreach ($availableStatuses as $statusValue) {
        $normalizedStatus = trim((string) $statusValue);
        if ($normalizedStatus !== '') {
            $catalogMap[$normalizedStatus] = true;
        }
    }

    $merged = array_keys($catalogMap);
    sort($merged, SORT_NATURAL | SORT_FLAG_CASE);
    return $merged;
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

function workorder_sort_key(array $row): string
{
    $date = trim((string) ($row['Start_Date'] ?? ''));
    if ($date !== '') {
        return $date;
    }

    return '9999-12-31';
}

function material_status_label(string $status): string
{
    global $statuses;

    $raw = trim($status);
    if ($raw === '') {
        return 'Onbekend';
    }

    $code = strtoupper($raw);
    if (isset($statuses[$code])) {
        return (string) $statuses[$code];
    }

    return $raw;
}

function material_status_code(string $status): string
{
    global $statuses;

    $raw = trim($status);
    if ($raw === '') {
        return '';
    }

    $upperRaw = strtoupper($raw);
    if (isset($statuses[$upperRaw])) {
        return $upperRaw;
    }

    foreach ($statuses as $code => $label) {
        if (strcasecmp(trim((string) $label), $raw) === 0) {
            return strtoupper((string) $code);
        }
    }

    if (preg_match('/^\s*([A-Za-z])\s*(?:$|[-:]).*$/', $raw, $matches) === 1) {
        return strtoupper((string) ($matches[1] ?? ''));
    }

    return '';
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

function is_task_article_line(array $line): bool
{
    $taskArticleFields = [
        'Taakartikel',
        'Taak_Artikel',
        'Task_Article',
        'KVT_Task_Article',
        'KVT_Taakartikel',
        'KVT_Exclude_Calc_Workorder',
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

function split_task_article_lines(array $lines, array $itemTaskFlagsMap): array
{
    $taskLines = [];
    $articleLines = [];

    foreach ($lines as $line) {
        $itemNo = trim((string) ($line['No'] ?? ''));
        $isTaskItemByCard = $itemNo !== '' && !empty($itemTaskFlagsMap[$itemNo]);
        $isTaskItem = $isTaskItemByCard || is_task_article_line($line);

        if ($isTaskItem) {
            $taskLines[] = $line;
            continue;
        }

        $articleLines[] = $line;
    }

    return [
        'task' => $taskLines,
        'article' => $articleLines,
    ];
}

function fetch_bin_location_maps(string $environment, string $company, array $lines, array $auth): array
{
    $binCodes = [];
    $itemNos = [];

    foreach ($lines as $line) {
        $binCode = trim((string) ($line['Bin_Code'] ?? ''));
        if ($binCode === '') {
            continue;
        }

        $binCodes[$binCode] = true;

        $itemNo = trim((string) ($line['No'] ?? ''));
        if ($itemNo !== '') {
            $itemNos[$itemNo] = true;
        }
    }

    $uniqueBinCodes = array_keys($binCodes);
    if (empty($uniqueBinCodes)) {
        return ['by_pair' => [], 'by_bin' => []];
    }

    $byPair = [];
    $byBin = [];

    try {
        $binChunks = array_chunk($uniqueBinCodes, 25);
        foreach ($binChunks as $binChunk) {
            $filter = build_or_filter('Bin_Code', $binChunk);
            if ($filter === '') {
                continue;
            }

            $url = odata_company_url($environment, $company, 'BinContentsList', [
                '$select' => 'Location_Code,Bin_Code,Item_No',
                '$filter' => $filter,
            ]);
            $rows = odata_get_all($url, $auth, odata_ttl('bin_lookup'));

            foreach ($rows as $row) {
                $binCode = trim((string) ($row['Bin_Code'] ?? ''));
                $itemNo = trim((string) ($row['Item_No'] ?? ''));
                $locationCode = trim((string) ($row['Location_Code'] ?? ''));
                if ($binCode === '' || $locationCode === '') {
                    continue;
                }

                $pairKey = strtoupper($binCode . '|' . $itemNo);
                if (!isset($byPair[$pairKey])) {
                    $byPair[$pairKey] = $locationCode;
                }

                $binKey = strtoupper($binCode);
                if (!isset($byBin[$binKey])) {
                    $byBin[$binKey] = $locationCode;
                }
            }
        }
    } catch (Throwable $throwable) {
    }

    if (count($byBin) < count($uniqueBinCodes)) {
        try {
            $chunks = array_chunk($uniqueBinCodes, 25);
            foreach ($chunks as $chunk) {
                $filter = build_or_filter('Code', $chunk);
                if ($filter === '') {
                    continue;
                }

                $url = odata_company_url($environment, $company, 'BinList', [
                    '$select' => 'Code,Location_Code',
                    '$filter' => $filter,
                ]);
                $rows = odata_get_all($url, $auth, odata_ttl('bin_lookup'));

                foreach ($rows as $row) {
                    $binCode = trim((string) ($row['Code'] ?? ''));
                    $locationCode = trim((string) ($row['Location_Code'] ?? ''));
                    if ($binCode === '' || $locationCode === '') {
                        continue;
                    }

                    $binKey = strtoupper($binCode);
                    if (!isset($byBin[$binKey])) {
                        $byBin[$binKey] = $locationCode;
                    }
                }
            }
        } catch (Throwable $throwable) {
        }
    }

    return [
        'by_pair' => $byPair,
        'by_bin' => $byBin,
    ];
}

function apply_bin_locations_to_lines(array $lines, array $binLocationMaps): array
{
    $byPair = is_array($binLocationMaps['by_pair'] ?? null) ? $binLocationMaps['by_pair'] : [];
    $byBin = is_array($binLocationMaps['by_bin'] ?? null) ? $binLocationMaps['by_bin'] : [];

    foreach ($lines as $index => $line) {
        $binCode = trim((string) ($line['Bin_Code'] ?? ''));
        if ($binCode === '') {
            continue;
        }

        $itemNo = trim((string) ($line['No'] ?? ''));
        $pairKey = strtoupper($binCode . '|' . $itemNo);
        $binKey = strtoupper($binCode);
        $locationCode = '';

        if (isset($byPair[$pairKey])) {
            $locationCode = trim((string) $byPair[$pairKey]);
        } elseif (isset($byBin[$binKey])) {
            $locationCode = trim((string) $byBin[$binKey]);
        }

        if ($locationCode !== '') {
            $lines[$index]['KVT_Bin_Location_Code'] = $locationCode;
        }
    }

    return $lines;
}

function fetch_real_article_counts_for_workorders(
    string $environment,
    string $company,
    array $workOrderNos,
    array $auth
): array {
    $summary = fetch_workorder_material_summary_for_workorders($environment, $company, $workOrderNos, $auth);
    return $summary['counts'];
}

function fetch_workorder_material_summary_for_workorders(
    string $environment,
    string $company,
    array $workOrderNos,
    array $auth
): array {
    global $statuses;

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
    $statusCodes = array_keys($statuses);
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
            '$select' => 'LVS_Work_Order_No,Type,No,KVT_Status_Material,KVT_Exclude_Calc_Workorder',
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

function material_needed_text(int $realArticleCount): string
{
    if ($realArticleCount <= 0) {
        return 'Nee';
    }

    return 'Ja (' . $realArticleCount . ')';
}

function material_line_status(array $line): array
{
    $statusRaw = (string) ($line['KVT_Status_Material'] ?? '');
    $statusMaterial = material_status_label($statusRaw);
    $statusCode = material_status_code($statusRaw);
    $binCode = trim((string) ($line['Bin_Code'] ?? ''));
    $binLocationCode = trim((string) ($line['KVT_Bin_Location_Code'] ?? ''));
    $expectedReceiptDate = trim((string) ($line['KVT_Expected_Receipt_Date'] ?? ''));
    $badgeClass = workorder_material_badge_class($statusRaw);
    $isUnknownExpectedDate = $expectedReceiptDate !== '' && strpos($expectedReceiptDate, '0001-01-01') === 0;
    $hasExpectedDate = $expectedReceiptDate !== '' && !$isUnknownExpectedDate;
    $detail = '-';

    if (in_array($statusCode, ['V', 'G', 'B'], true)) {
        if ($binCode !== '' && $binLocationCode !== '') {
            $detail = 'Bin: ' . $binCode . ' · Locatie: ' . $binLocationCode;
        } else {
            $detail = 'Locatie onbekend';
        }
    } elseif (in_array($statusCode, ['A', 'C'], true)) {
        $detail = 'Uitgegeven aan monteur';
    } elseif ($statusCode === 'O') {
        $detail = '';
    } elseif (in_array($statusCode, ['N', 'X', 'T', 'I'], true)) {
        if ($hasExpectedDate) {
            $detail = 'Verwacht: ' . nl_date($expectedReceiptDate);
        } else {
            $detail = 'Levertijd niet bekend';
        }
    } else {
        $detail = 'Levertijd niet bekend';
    }

    return [
        'label' => $statusMaterial,
        'class' => $badgeClass,
        'material_status_label' => $statusMaterial,
        'detail' => $detail,
    ];
}

function is_open_workorder_status(string $status): bool
{
    $normalized = strtolower(trim($status));
    if ($normalized === '') {
        return true;
    }

    $closedTerms = [
        'closed',
        'afgesloten',
        'afgerond',
        'gereed',
        'voltooid',
        'cancelled',
        'geannuleerd',
        'finished',
        'gefactureerd',
        'uitgevoerd',
        'ondertekend',
        'gecontroleerd',
    ];
    foreach ($closedTerms as $term) {
        if (strpos($normalized, $term) !== false) {
            return false;
        }
    }

    return true;
}

function status_is_exact(string $status, string $expected): bool
{
    return trim(strtolower($status)) === trim(strtolower($expected));
}

function is_executed_workorder_status(string $status): bool
{
    return status_is_exact($status, 'uitgevoerd');
}

function is_closed_workorder_status(string $status): bool
{
    return status_is_exact($status, 'afgesloten');
}

function is_checked_workorder_status(string $status): bool
{
    return status_is_exact($status, 'gecontroleerd');
}

function is_signed_workorder_status(string $status): bool
{
    return status_is_exact($status, 'ondertekend');
}

function status_enabled_default(string $status): bool
{
    return !is_executed_workorder_status($status)
        && !is_closed_workorder_status($status)
        && !is_checked_workorder_status($status)
        && !is_signed_workorder_status($status);
}

function status_css_class(string $status): string
{
    $normalized = strtolower(trim($status));
    if ($normalized === '') {
        return '';
    }

    $normalized = strtr($normalized, [
        'á' => 'a',
        'à' => 'a',
        'ä' => 'a',
        'â' => 'a',
        'ã' => 'a',
        'é' => 'e',
        'è' => 'e',
        'ë' => 'e',
        'ê' => 'e',
        'í' => 'i',
        'ì' => 'i',
        'ï' => 'i',
        'î' => 'i',
        'ó' => 'o',
        'ò' => 'o',
        'ö' => 'o',
        'ô' => 'o',
        'ú' => 'u',
        'ù' => 'u',
        'ü' => 'u',
        'û' => 'u',
        'ñ' => 'n',
        'ç' => 'c',
    ]);

    $slug = preg_replace('/[^a-z0-9]+/', '-', $normalized);
    $slug = is_string($slug) ? trim($slug, '-') : '';

    return $slug !== '' ? ('status-' . $slug) : '';
}

function webfleet_default_status_label(): string
{
    return 'Niet gestart';
}

function webfleet_legacy_default_status_label(): string
{
    return 'Nog niet gestart';
}

function webfleet_status_catalog(): array
{
    return [
        webfleet_default_status_label(),
        'Werk gestart',
        'Werk voltooid',
    ];
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

function normalize_webfleet_filter_map(array $input): array
{
    $normalized = normalize_status_filter_map($input);
    $legacyKey = webfleet_legacy_default_status_label();
    $defaultKey = webfleet_default_status_label();

    if (array_key_exists($legacyKey, $normalized)) {
        $legacyValue = (bool) $normalized[$legacyKey];
        unset($normalized[$legacyKey]);

        if (!array_key_exists($defaultKey, $normalized)) {
            $normalized[$defaultKey] = $legacyValue;
        }
    }

    return $normalized;
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

function read_status_catalog(): array
{
    $raw = read_json_assoc_file(status_catalog_path());
    $statuses = [];

    foreach ($raw as $key => $value) {
        if (is_string($key) && trim($key) !== '' && (is_bool($value) || is_numeric($value))) {
            $statuses[trim($key)] = true;
            continue;
        }

        $status = trim((string) $value);
        if ($status !== '') {
            $statuses[$status] = true;
        }
    }

    $result = array_keys($statuses);
    sort($result, SORT_NATURAL | SORT_FLAG_CASE);
    return $result;
}

function write_status_catalog(array $statuses): void
{
    $normalized = [];
    foreach ($statuses as $status) {
        $value = trim((string) $status);
        if ($value !== '') {
            $normalized[$value] = true;
        }
    }

    $result = array_keys($normalized);
    sort($result, SORT_NATURAL | SORT_FLAG_CASE);
    write_json_assoc_file(status_catalog_path(), array_values($result));
}

function ensure_user_status_filters(string $email, array $catalogStatuses, array $metadata = []): array
{
    $path = user_status_filters_path($email);
    $payload = read_user_status_filters_payload($path);
    $existing = is_array($payload['filters'] ?? null) ? $payload['filters'] : [];
    $existingMeta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
    $changed = false;

    foreach ($catalogStatuses as $status) {
        if (!array_key_exists($status, $existing)) {
            $existing[$status] = status_enabled_default($status);
            $changed = true;
        }
    }

    if ($changed || !is_file($path) || $existingMeta !== $metadata) {
        write_user_status_filters_payload($path, $existing, $metadata);
    }

    return $existing;
}

function ensure_user_webfleet_status_filters(string $email, array $catalogStatuses, array $metadata = []): array
{
    $path = user_status_filters_path($email);
    $payload = read_user_status_filters_payload($path);
    $existingRaw = is_array($payload['webfleet_filters'] ?? null) ? $payload['webfleet_filters'] : [];
    $existing = normalize_webfleet_filter_map($existingRaw);
    $existingMeta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
    $changed = false;

    foreach ($catalogStatuses as $status) {
        if (!array_key_exists($status, $existing)) {
            $existing[$status] = true;
            $changed = true;
        }
    }

    if ($changed || !is_file($path) || $existingMeta !== $metadata) {
        $normalFilters = is_array($payload['filters'] ?? null) ? $payload['filters'] : [];
        write_user_status_filters_payload($path, $normalFilters, $metadata, $existing);
    }

    return $existing;
}

function decode_status_filters_request(string $payload): array
{
    if ($payload === '') {
        return [];
    }

    $decoded = json_decode($payload, true);
    return is_array($decoded) ? normalize_status_filter_map($decoded) : [];
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

function fetch_workorder_open_counts(string $environment, string $company, array $resourceNos, array $auth): array
{
    $result = [];
    foreach ($resourceNos as $resourceNo) {
        $resourceNo = trim((string) $resourceNo);
        if ($resourceNo !== '') {
            $result[$resourceNo] = 0;
        }
    }

    if (empty($result)) {
        return $result;
    }

    $filter = build_or_filter('Resource_No', array_keys($result));
    if ($filter === '') {
        return $result;
    }

    $url = odata_company_url($environment, $company, 'AppWerkorders', [
        '$select' => 'No,Resource_No,Status',
        '$filter' => $filter,
    ]);

    $rows = odata_get_all($url, $auth, odata_ttl('workorders_counts'));
    foreach ($rows as $row) {
        $resourceNo = trim((string) ($row['Resource_No'] ?? ''));
        if ($resourceNo === '' || !isset($result[$resourceNo])) {
            continue;
        }

        $status = (string) ($row['Status'] ?? '');
        if (!is_open_workorder_status($status)) {
            continue;
        }

        $result[$resourceNo]++;
    }

    return $result;
}

function fetch_werkorders_no_material_needed_map(string $environment, string $company, string $resourceNo, array $auth): array
{
    $resourceNo = trim($resourceNo);
    if ($resourceNo === '') {
        return [];
    }

    $url = odata_company_url($environment, $company, 'Werkorders', [
        '$select' => 'No,KVT_No_Material_Needed',
        '$filter' => "Resource_No eq '" . odata_quote_string($resourceNo) . "'",
    ]);

    $rows = odata_get_all($url, $auth, odata_ttl('werkorders_material_flags'));
    $result = [];
    foreach ($rows as $row) {
        $no = trim((string) ($row['No'] ?? ''));
        if ($no === '') {
            continue;
        }

        $result[$no] = is_true_value($row['KVT_No_Material_Needed'] ?? false);
    }

    return $result;
}

function odata_fetch_single_row(
    string $environment,
    string $company,
    string $entity,
    string $select,
    string $filter,
    array $auth,
    int $ttl
): array {
    if (trim($entity) === '' || trim($select) === '' || trim($filter) === '') {
        return [];
    }

    $url = odata_company_url($environment, $company, $entity, [
        '$select' => $select,
        '$filter' => $filter,
    ]);

    $rows = odata_get_all($url, $auth, $ttl);
    $firstRow = $rows[0] ?? null;
    return is_array($firstRow) ? $firstRow : [];
}

function fetch_single_werkorder_row_by_no(
    string $environment,
    string $company,
    string $workOrderNo,
    string $select,
    array $auth
): array {
    $normalizedWorkOrderNo = trim($workOrderNo);
    if ($normalizedWorkOrderNo === '' || trim($select) === '') {
        return [];
    }

    return odata_fetch_single_row(
        $environment,
        $company,
        'Werkorders',
        $select,
        "No eq '" . odata_quote_string($normalizedWorkOrderNo) . "'",
        $auth,
        odata_ttl('workorder_detail')
    );
}

function fetch_werkorder_no_material_needed_by_no(string $environment, string $company, string $workOrderNo, array $auth): ?bool
{
    $row = fetch_single_werkorder_row_by_no(
        $environment,
        $company,
        $workOrderNo,
        'No,KVT_No_Material_Needed',
        $auth
    );

    if (empty($row)) {
        return null;
    }

    return is_true_value($row['KVT_No_Material_Needed'] ?? false);
}

function fetch_werkorder_visit_contact_by_no(string $environment, string $company, string $workOrderNo, array $auth): array
{
    return fetch_single_werkorder_row_by_no(
        $environment,
        $company,
        $workOrderNo,
        'No,KVT_Primary_Contact_No,KVT_Primary_Contact_Phone_No,Visit_Address,Visit_Address_2,Visit_Post_Code,Visit_City,Visit_Country_Region_Code',
        $auth
    );
}

function fetch_contact_name_by_no(string $environment, string $company, string $contactNo, array $auth): string
{
    $contactNo = trim($contactNo);
    if ($contactNo === '') {
        return '';
    }

    $row = odata_fetch_single_row(
        $environment,
        $company,
        'Contacts',
        'No,Name,Name_2',
        "No eq '" . odata_quote_string($contactNo) . "'",
        $auth,
        odata_ttl('workorder_detail')
    );
    if (empty($row)) {
        return '';
    }

    $name = trim((string) ($row['Name'] ?? ''));
    $name2 = trim((string) ($row['Name_2'] ?? ''));
    return trim($name . ($name2 !== '' ? (' ' . $name2) : ''));
}

function fetch_component_parking_address_by_no(string $environment, string $company, string $componentNo, array $auth): string
{
    $componentNo = trim($componentNo);
    if ($componentNo === '') {
        return '';
    }

    $row = odata_fetch_single_row(
        $environment,
        $company,
        'AppComponentCard',
        'No,KVT_Parking_Address',
        "No eq '" . odata_quote_string($componentNo) . "'",
        $auth,
        odata_ttl('workorder_detail')
    );
    if (empty($row)) {
        return '';
    }

    return trim((string) ($row['KVT_Parking_Address'] ?? ''));
}

function build_week_chunks(DateTimeImmutable $startDate, DateTimeImmutable $endDate): array
{
    if ($endDate < $startDate) {
        return [];
    }

    $chunks = [];
    $chunkStart = $startDate;
    while ($chunkStart <= $endDate) {
        $chunkEnd = $chunkStart->modify('+6 days');
        if ($chunkEnd > $endDate) {
            $chunkEnd = $endDate;
        }

        $chunks[] = [
            'start' => $chunkStart,
            'end' => $chunkEnd,
        ];

        $chunkStart = $chunkStart->modify('+7 days');
    }

    return $chunks;
}

function fetch_app_workorders_chunked(
    string $environment,
    string $company,
    string $resourceNo,
    DateTimeImmutable $rangeStart,
    DateTimeImmutable $rangeEnd,
    array $auth
): array {
    $resourceNo = trim($resourceNo);
    if ($resourceNo === '') {
        return [];
    }

    $chunks = build_week_chunks($rangeStart, $rangeEnd);
    if (empty($chunks)) {
        return [];
    }

    $allRows = [];
    $seenWorkOrders = [];
    foreach ($chunks as $chunk) {
        $start = $chunk['start']->format('Y-m-d');
        $end = $chunk['end']->format('Y-m-d');

        $filter = "Resource_No eq '" . odata_quote_string($resourceNo) . "'"
            . " and Start_Date ge " . $start
            . " and Start_Date le " . $end;

        $url = odata_company_url($environment, $company, 'AppWerkorders', [
            '$select' => 'No,Task_Code,Task_Description,Status,Resource_No,Resource_Name,Main_Entity_Description,Sub_Entity_Description,Component_Description,Serial_No,Start_Date,End_Date,External_Document_No,KVT_Status_Purchase_Order,Job_No,Job_Task_No',
            '$filter' => $filter,
            '$orderby' => 'Start_Date asc,No asc',
        ]);

        $rows = odata_get_all($url, $auth, odata_ttl('workorders_list'));
        foreach ($rows as $row) {
            $workOrderNo = trim((string) ($row['No'] ?? ''));
            if ($workOrderNo !== '' && isset($seenWorkOrders[$workOrderNo])) {
                continue;
            }

            if ($workOrderNo !== '') {
                $seenWorkOrders[$workOrderNo] = true;
            }

            $allRows[] = $row;
        }
    }

    return $allRows;
}

function workorder_matches_query(array $workOrder, string $searchQuery): bool
{
    $needle = strtolower(trim($searchQuery));
    if ($needle === '') {
        return true;
    }

    $workOrderNo = strtolower((string) ($workOrder['No'] ?? ''));
    $taskDescription = strtolower((string) ($workOrder['Task_Description'] ?? ''));
    return strpos($workOrderNo, $needle) !== false || strpos($taskDescription, $needle) !== false;
}

$errorMessage = '';
$resourcesForUser = [];
$serviceResources = [];
$openWorkOrderCounts = [];
$workOrderRealArticleCounts = [];
$workOrderMaterialStatusLabels = [];
$workOrderWebfleetStatusLabels = [];
$workOrderWebfleetStatusCounts = [];
$workOrders = [];
$selectedWorkOrder = null;
$selectedWorkOrderRealArticleCount = 0;
$selectedWorkOrderMaterialStatusLabel = 'Onbekend';
$selectedWorkOrderWebfleetStatusLabel = webfleet_default_status_label();
$selectedWorkOrderStartTime = '';
$selectedWorkOrderEndTime = '';
$selectedWorkOrderPrimaryContactName = '';
$selectedWorkOrderVisitAddress = '';
$taskArticleLines = [];
$planningLines = [];
$availableWorkorderStatuses = [];
$workOrderStatusCounts = [];
$disabledFilterExtraRows = [];
$allWorkOrdersCount = 0;
$statusCatalog = read_status_catalog();
$webfleetStatusCatalog = webfleet_status_catalog();
$submittedStatusFilters = decode_status_filters_request($statusFiltersRequest);
$submittedWebfleetStatusFilters = normalize_webfleet_filter_map(decode_status_filters_request($webfleetStatusFiltersRequest));
$statusFilterMetadata = current_status_filter_metadata($statusFilterOwnerEmail);
$userStatusFilters = ensure_user_status_filters($statusFilterOwnerEmail, $statusCatalog, $statusFilterMetadata);
$webfleetStatusFilters = ensure_user_webfleet_status_filters($statusFilterOwnerEmail, $webfleetStatusCatalog, $statusFilterMetadata);

if ($hasWebfleetStatusFiltersRequest && $webfleetStatusFiltersRequest !== '') {
    foreach ($webfleetStatusCatalog as $webfleetStatusValue) {
        if (array_key_exists($webfleetStatusValue, $submittedWebfleetStatusFilters)) {
            $webfleetStatusFilters[$webfleetStatusValue] = (bool) $submittedWebfleetStatusFilters[$webfleetStatusValue];
        }
    }
}

if ($selectedWorkOrderNo === '' && $ajaxAction === '') {
    write_user_status_filters_payload(
        user_status_filters_path($statusFilterOwnerEmail),
        $userStatusFilters,
        $statusFilterMetadata,
        $webfleetStatusFilters
    );
}

$today = new DateTimeImmutable('today');
$defaultRangeStart = $today;
$defaultRangeEnd = $today->modify('+7 days');
$rangeStart = parse_date_ymd($dateFromRequest) ?? $defaultRangeStart;
$rangeEnd = parse_date_ymd($dateToRequest) ?? $defaultRangeEnd;

if ($rangeEnd < $rangeStart) {
    $tmp = $rangeStart;
    $rangeStart = $rangeEnd;
    $rangeEnd = $tmp;
}

$dateFromValue = $rangeStart->format('Y-m-d');
$dateToValue = $rangeEnd->format('Y-m-d');

$preferredCompany = get_user_company_preference($userEmail);
$requestedCompany = trim((string) ($_GET['company'] ?? ''));

if (in_companies($requestedCompany, $companies)) {
    $company = $requestedCompany;
} elseif (in_companies($preferredCompany, $companies)) {
    $company = $preferredCompany;
} else {
    $company = $companies[0];
}

if ($userEmail !== '') {
    set_user_company_preference($userEmail, $company);
}

$selectedResourceNo = '';
$selectedResourceName = '';
$ownResourceNo = '';

if ($ajaxAction === 'resource_counts') {
    $resourceNosInput = $_GET['resource_nos'] ?? [];
    if (!is_array($resourceNosInput)) {
        $resourceNosInput = [$resourceNosInput];
    }

    $resourceNos = [];
    foreach ($resourceNosInput as $resourceNoInput) {
        $resourceNo = trim((string) $resourceNoInput);
        if ($resourceNo !== '') {
            $resourceNos[$resourceNo] = true;
        }
    }

    $resourceNos = array_keys($resourceNos);
    if (count($resourceNos) > 300) {
        $resourceNos = array_slice($resourceNos, 0, 300);
    }

    header('Content-Type: application/json; charset=utf-8');
    try {
        $counts = fetch_workorder_open_counts($environment, $company, $resourceNos, $auth);
        echo json_encode([
            'ok' => true,
            'counts' => $counts,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $throwable) {
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'error' => 'Kon aantallen niet laden.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    exit;
}

try {
    $resourcesForUser = fetch_app_resources_by_email($environment, $company, $userEmail, $auth);

    if (count($resourcesForUser) === 0) {
        $userSetupRows = fetch_user_setup_by_email($environment, $company, $userEmail, $auth);
        foreach ($userSetupRows as $userSetupRow) {
            $userId = trim((string) ($userSetupRow['User_ID'] ?? ''));
            if ($userId === '') {
                continue;
            }

            $resourcesForUser = array_merge(
                $resourcesForUser,
                fetch_app_resources_by_user_id($environment, $company, $userId, $auth)
            );
        }
    }

    $serviceResources = fetch_service_resources($environment, $company, $auth);
    if (count($serviceResources) === 0) {
        foreach ($resourcesForUser as $resource) {
            $serviceResources[] = map_resource_summary($resource);
        }
    }

    $ownResourceNo = trim((string) ($resourcesForUser[0]['No'] ?? ''));
    $serviceResourceMap = [];
    foreach ($serviceResources as $serviceResource) {
        $no = trim((string) ($serviceResource['No'] ?? ''));
        if ($no !== '') {
            $serviceResourceMap[$no] = $serviceResource;
        }
    }

    if ($selectedPersonNoRequest !== '' && isset($serviceResourceMap[$selectedPersonNoRequest])) {
        $selectedResourceNo = $selectedPersonNoRequest;
    } elseif ($ownResourceNo !== '' && isset($serviceResourceMap[$ownResourceNo])) {
        $selectedResourceNo = $ownResourceNo;
    } elseif ($ownResourceNo !== '') {
        $selectedResourceNo = $ownResourceNo;
    } elseif (!empty($serviceResources)) {
        $selectedResourceNo = (string) ($serviceResources[0]['No'] ?? '');
    }

    $selectedResourceName = resolve_selected_resource_name($selectedResourceNo, $serviceResourceMap, $resourcesForUser);

    if ($selectedResourceNo !== '') {
        $workOrders = fetch_app_workorders_chunked(
            $environment,
            $company,
            $selectedResourceNo,
            $rangeStart,
            $rangeEnd,
            $auth
        );

        $workOrderNosForWebfleet = array_map(
            static fn(array $workOrder): string => trim((string) ($workOrder['No'] ?? '')),
            $workOrders
        );
        $workOrderWebfleetStatusLabels = fetch_webfleet_status_labels_for_workorders(
            $environment,
            $company,
            $workOrderNosForWebfleet,
            $auth
        );
        foreach ($workOrderWebfleetStatusLabels as $webfleetStatusLabel) {
            $webfleetStatusText = trim((string) $webfleetStatusLabel);
            if ($webfleetStatusText === '') {
                $webfleetStatusText = webfleet_default_status_label();
            }
            $workOrderWebfleetStatusCounts[$webfleetStatusText] = (int) ($workOrderWebfleetStatusCounts[$webfleetStatusText] ?? 0) + 1;
        }

        $statusMap = [];
        $allWorkOrdersCount = count($workOrders);
        foreach ($workOrders as $workOrder) {
            $status = trim((string) ($workOrder['Status'] ?? ''));
            if ($status !== '') {
                $statusMap[$status] = true;
                $workOrderStatusCounts[$status] = (int) ($workOrderStatusCounts[$status] ?? 0) + 1;
            }
        }
        $availableWorkorderStatuses = array_keys($statusMap);
        sort($availableWorkorderStatuses, SORT_NATURAL | SORT_FLAG_CASE);

        $mergedStatusCatalog = merged_status_catalog($statusCatalog, $availableWorkorderStatuses);
        if ($mergedStatusCatalog !== $statusCatalog) {
            $statusCatalog = $mergedStatusCatalog;
            write_status_catalog($statusCatalog);
        }

        $userStatusFilters = ensure_user_status_filters($statusFilterOwnerEmail, $statusCatalog, $statusFilterMetadata);

        if (($hasStatusFiltersRequest && $statusFiltersRequest !== '') || ($hasWebfleetStatusFiltersRequest && $webfleetStatusFiltersRequest !== '')) {
            foreach ($statusCatalog as $statusValue) {
                if (array_key_exists($statusValue, $submittedStatusFilters)) {
                    $userStatusFilters[$statusValue] = (bool) $submittedStatusFilters[$statusValue];
                }
            }

            foreach ($webfleetStatusCatalog as $webfleetStatusValue) {
                if (array_key_exists($webfleetStatusValue, $submittedWebfleetStatusFilters)) {
                    $webfleetStatusFilters[$webfleetStatusValue] = (bool) $submittedWebfleetStatusFilters[$webfleetStatusValue];
                }
            }

            write_user_status_filters_payload(
                user_status_filters_path($statusFilterOwnerEmail),
                $userStatusFilters,
                $statusFilterMetadata,
                $webfleetStatusFilters
            );
        }

        $workOrdersForExtraStatusCounts = $workOrders;
        if ($searchQuery !== '') {
            $workOrdersForExtraStatusCounts = array_values(array_filter(
                $workOrdersForExtraStatusCounts,
                static fn(array $workOrder): bool => workorder_matches_query($workOrder, $searchQuery)
            ));
        }

        foreach ($workOrdersForExtraStatusCounts as $workOrder) {
            $workOrderNo = trim((string) ($workOrder['No'] ?? ''));
            $status = trim((string) ($workOrder['Status'] ?? ''));
            $webfleetStatus = trim((string) ($workOrderWebfleetStatusLabels[$workOrderNo] ?? webfleet_default_status_label()));
            if ($webfleetStatus === '') {
                $webfleetStatus = webfleet_default_status_label();
            }

            $isStatusEnabled = true;
            if ($status !== '') {
                $isStatusEnabled = array_key_exists($status, $userStatusFilters)
                    ? (bool) $userStatusFilters[$status]
                    : status_enabled_default($status);
            }

            $isWebfleetEnabled = array_key_exists($webfleetStatus, $webfleetStatusFilters)
                ? (bool) $webfleetStatusFilters[$webfleetStatus]
                : true;

            if ($isStatusEnabled && $isWebfleetEnabled) {
                continue;
            }

            $reasons = [];
            if (!$isStatusEnabled && $status !== '') {
                $reasons[] = [
                    'label' => $status,
                    'class' => status_css_class($status),
                ];
            }
            if (!$isWebfleetEnabled) {
                $reasons[] = [
                    'label' => $webfleetStatus,
                    'class' => webfleet_status_badge_class($webfleetStatus),
                ];
            }

            if (empty($reasons)) {
                continue;
            }

            $reasonKeyParts = [];
            foreach ($reasons as $reason) {
                $reasonKeyParts[] = (string) ($reason['label'] ?? '') . '|' . (string) ($reason['class'] ?? '');
            }
            $reasonKey = implode('||', $reasonKeyParts);

            if (!isset($disabledFilterExtraRows[$reasonKey])) {
                $disabledFilterExtraRows[$reasonKey] = [
                    'count' => 0,
                    'reasons' => $reasons,
                ];
            }

            $disabledFilterExtraRows[$reasonKey]['count'] = (int) ($disabledFilterExtraRows[$reasonKey]['count'] ?? 0) + 1;
        }

        if (!empty($disabledFilterExtraRows)) {
            uasort($disabledFilterExtraRows, static function (array $left, array $right): int {
                $leftCount = (int) ($left['count'] ?? 0);
                $rightCount = (int) ($right['count'] ?? 0);
                if ($leftCount === $rightCount) {
                    $leftLabel = '';
                    $rightLabel = '';

                    $leftReasons = is_array($left['reasons'] ?? null) ? $left['reasons'] : [];
                    $rightReasons = is_array($right['reasons'] ?? null) ? $right['reasons'] : [];
                    if (!empty($leftReasons)) {
                        $leftLabel = strtolower(trim((string) ($leftReasons[0]['label'] ?? '')));
                    }
                    if (!empty($rightReasons)) {
                        $rightLabel = strtolower(trim((string) ($rightReasons[0]['label'] ?? '')));
                    }

                    return strcmp($leftLabel, $rightLabel);
                }

                return $rightCount <=> $leftCount;
            });
        }

        $workOrders = array_values(array_filter(
            $workOrders,
            static function (array $workOrder) use ($userStatusFilters, $webfleetStatusFilters, $workOrderWebfleetStatusLabels): bool {
                $status = trim((string) ($workOrder['Status'] ?? ''));
                $workOrderNo = trim((string) ($workOrder['No'] ?? ''));
                $webfleetStatus = trim((string) ($workOrderWebfleetStatusLabels[$workOrderNo] ?? webfleet_default_status_label()));

                $isStatusEnabled = true;
                if ($status === '') {
                    $isStatusEnabled = true;
                } elseif (array_key_exists($status, $userStatusFilters)) {
                    $isStatusEnabled = (bool) $userStatusFilters[$status];
                } else {
                    $isStatusEnabled = status_enabled_default($status);
                }

                $isWebfleetEnabled = array_key_exists($webfleetStatus, $webfleetStatusFilters)
                    ? (bool) $webfleetStatusFilters[$webfleetStatus]
                    : true;

                return $isStatusEnabled && $isWebfleetEnabled;
            }
        ));

        if ($searchQuery !== '') {
            $workOrders = array_values(array_filter(
                $workOrders,
                static fn(array $workOrder): bool => workorder_matches_query($workOrder, $searchQuery)
            ));
        }

        usort($workOrders, static function (array $left, array $right): int {
            return strcmp(workorder_sort_key($left), workorder_sort_key($right));
        });

        $workOrderNosForCounts = array_map(
            static fn(array $workOrder): string => trim((string) ($workOrder['No'] ?? '')),
            $workOrders
        );
        $workOrderMaterialSummary = fetch_workorder_material_summary_for_workorders(
            $environment,
            $company,
            $workOrderNosForCounts,
            $auth
        );
        $workOrderRealArticleCounts = is_array($workOrderMaterialSummary['counts'] ?? null)
            ? $workOrderMaterialSummary['counts']
            : [];
        $workOrderMaterialStatusLabels = is_array($workOrderMaterialSummary['labels'] ?? null)
            ? $workOrderMaterialSummary['labels']
            : [];
    }

    if ($selectedWorkOrderNo !== '') {
        $selectedUrl = odata_company_url($environment, $company, 'AppWerkorders', [
            '$select' => 'No,Task_Code,Task_Description,Status,Resource_No,Resource_Name,Main_Entity_Description,Sub_Entity_Description,Component_No,Component_Description,Serial_No,Start_Date,Start_Time,End_Date,End_Time,External_Document_No,KVT_Lowest_Present_Status_Mat,KVT_Status_Purchase_Order,Job_No,Job_Task_No,KVT_Memo_Service_Location,KVT_Memo_Component,KVT_Memo,KVT_Memo_Internal_Use_Only',
            '$filter' => "No eq '" . odata_quote_string($selectedWorkOrderNo) . "'",
        ]);
        $selectedRows = odata_get_all($selectedUrl, $auth, odata_ttl('workorder_detail'));
        $selectedWorkOrder = $selectedRows[0] ?? null;

        if ($selectedWorkOrder !== null) {
            if (isset($workOrderWebfleetStatusLabels[$selectedWorkOrderNo])) {
                $selectedWorkOrderWebfleetStatusLabel = safe_text((string) $workOrderWebfleetStatusLabels[$selectedWorkOrderNo]);
            } else {
                $selectedWebfleetMap = fetch_webfleet_status_labels_for_workorders(
                    $environment,
                    $company,
                    [$selectedWorkOrderNo],
                    $auth
                );
                $selectedWorkOrderWebfleetStatusLabel = safe_text((string) (
                    $selectedWebfleetMap[$selectedWorkOrderNo] ?? webfleet_default_status_label()
                ));
            }

            $selectedWorkOrderStartTime = format_workorder_time_value((string) ($selectedWorkOrder['Start_Time'] ?? ''));
            $selectedWorkOrderEndTime = format_workorder_time_value((string) ($selectedWorkOrder['End_Time'] ?? ''));

            $selectedWorkOrderMaterialSummary = fetch_workorder_material_summary_for_workorders(
                $environment,
                $company,
                [$selectedWorkOrderNo],
                $auth
            );
            $selectedWorkOrderMaterialStatusLabel = safe_text((string) (
                $selectedWorkOrderMaterialSummary['labels'][$selectedWorkOrderNo] ?? 'Onbekend'
            ));

            $visitContactRow = fetch_werkorder_visit_contact_by_no($environment, $company, $selectedWorkOrderNo, $auth);
            if (!empty($visitContactRow)) {
                $selectedWorkOrder = array_merge($selectedWorkOrder, $visitContactRow);
            }

            $primaryContactNo = trim((string) ($selectedWorkOrder['KVT_Primary_Contact_No'] ?? ''));
            if ($primaryContactNo !== '') {
                $selectedWorkOrderPrimaryContactName = fetch_contact_name_by_no(
                    $environment,
                    $company,
                    $primaryContactNo,
                    $auth
                );
            }

            $componentNo = trim((string) ($selectedWorkOrder['Component_No'] ?? ''));
            if ($componentNo !== '') {
                $selectedWorkOrderVisitAddress = fetch_component_parking_address_by_no(
                    $environment,
                    $company,
                    $componentNo,
                    $auth
                );
            }

            $linesUrl = odata_company_url($environment, $company, 'LVS_JobPlanningLinesSub', [
                '$select' => 'Line_No,Type,No,Description,KVT_Extended_Text,Quantity,Unit_of_Measure_Code,KVT_Status_Material,Bin_Code,KVT_Completely_Picked,KVT_Qty_Picked,KVT_Expected_Receipt_Date,LVS_Purchase_Order_No,LVS_Outstanding_Qty_Base,Planning_Date,LVS_Vendor_Name,LVS_Supply_from,KVT_Exclude_Calc_Workorder',
                '$filter' => "LVS_Work_Order_No eq '" . odata_quote_string($selectedWorkOrderNo) . "'",
                '$orderby' => 'Line_No asc',
            ]);
            $planningLinesAll = odata_get_all($linesUrl, $auth, odata_ttl('planning_lines'));
            $planningLines = array_values(array_filter($planningLinesAll, static function (array $line): bool {
                $type = strtolower(trim((string) ($line['Type'] ?? '')));
                return $type === 'item' || $type === 'artikel';
            }));

            $itemNos = [];
            foreach ($planningLines as $line) {
                $itemNo = trim((string) ($line['No'] ?? ''));
                if ($itemNo !== '') {
                    $itemNos[] = $itemNo;
                }
            }

            $itemTaskFlagsMap = fetch_item_task_flags_map($environment, $company, $itemNos, $auth);
            $splitLines = split_task_article_lines($planningLines, $itemTaskFlagsMap);
            $taskArticleLines = $splitLines['task'];
            $planningLines = $splitLines['article'];

            $binLocationMaps = fetch_bin_location_maps($environment, $company, $planningLines, $auth);
            $planningLines = apply_bin_locations_to_lines($planningLines, $binLocationMaps);

            $selectedWorkOrderRealArticleCount = count($planningLines);
        }
    }
} catch (Throwable $throwable) {
    $errorMessage = $throwable->getMessage();
}

$title = $selectedWorkOrderNo !== '' ? 'Werkorder details' : 'Mijn werkorders';
$listQuery = [
    'company' => $company,
    'person' => $selectedResourceNo,
    'date_from' => $dateFromValue,
    'date_to' => $dateToValue,
    'q' => $searchQuery,
];
$listQuery = array_filter($listQuery, static function ($value): bool {
    return trim((string) $value) !== '';
});
if ($hasWebfleetStatusFiltersRequest && $webfleetStatusFiltersRequest !== '') {
    $listQuery['webfleet_status_filters'] = $webfleetStatusFiltersRequest;
}
$listHref = 'index.php' . (!empty($listQuery) ? ('?' . http_build_query($listQuery, '', '&', PHP_QUERY_RFC3986)) : '');
$resourceCountsUrl = 'index.php?' . http_build_query([
    'ajax' => 'resource_counts',
    'company' => $company,
], '', '&', PHP_QUERY_RFC3986);

$statusFiltersForModal = [];
foreach ($statusCatalog as $statusValue) {
    $statusFiltersForModal[$statusValue] = array_key_exists($statusValue, $userStatusFilters)
        ? (bool) $userStatusFilters[$statusValue]
        : status_enabled_default($statusValue);
}

$statusFiltersPayloadValue = json_encode($statusFiltersForModal, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($statusFiltersPayloadValue)) {
    $statusFiltersPayloadValue = '{}';
}

$webfleetStatusFiltersForModal = [];
foreach ($webfleetStatusCatalog as $webfleetStatusValue) {
    $webfleetStatusFiltersForModal[$webfleetStatusValue] = array_key_exists($webfleetStatusValue, $webfleetStatusFilters)
        ? (bool) $webfleetStatusFilters[$webfleetStatusValue]
        : true;
}

$webfleetStatusFiltersPayloadValue = json_encode($webfleetStatusFiltersForModal, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($webfleetStatusFiltersPayloadValue)) {
    $webfleetStatusFiltersPayloadValue = '{}';
}

$activeStatusFilters = [];
foreach ($statusCatalog as $statusValue) {
    if (!empty($statusFiltersForModal[$statusValue])) {
        $activeStatusFilters[] = $statusValue;
    }
}

$activeWebfleetFilters = [];
foreach ($webfleetStatusCatalog as $webfleetStatusValue) {
    if (!empty($webfleetStatusFiltersForModal[$webfleetStatusValue])) {
        $activeWebfleetFilters[] = $webfleetStatusValue;
    }
}

?>

<!doctype html>
<html lang="nl">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
    <meta name="theme-color" content="#ffffff" />
    <meta name="apple-mobile-web-app-capable" content="yes" />
    <meta name="apple-mobile-web-app-status-bar-style" content="default" />
    <title><?= htmlspecialchars($title) ?></title>
    <link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png">
    <link rel="icon" href="favicon.ico">
    <link rel="manifest" href="site.webmanifest">
    <style>
        :root {
            color-scheme: light;
            --bg: #f2f5f9;
            --card: #ffffff;
            --text: #152233;
            --muted: #5f7287;
            --border: #dbe4ee;
            --primary: #0f5bb7;
            --ok: #1d8a4c;
            --warn: #ad6f1a;
            --neutral: #637588;
            --date-picker-width: 148px;
            --day-button-width: 35px;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
            background: var(--bg);
            color: var(--text);
            padding-bottom: 72px;
        }

        .page {
            width: min(100%, 760px);
            margin: 0 auto;
            padding: 12px;
        }

        .logo {
            display: block;
            width: 168px;
            max-width: 70%;
            margin: 0 auto 10px;
            height: auto;
        }

        .title {
            margin: 0 0 4px;
            font-size: 1.2rem;
            line-height: 1.3;
        }

        .subtitle {
            margin: 0 0 12px;
            color: var(--muted);
            font-size: .92rem;
        }

        .alert {
            border: 1px solid #f2cdcd;
            background: #fff3f3;
            color: #8e2a2a;
            border-radius: 10px;
            padding: 10px;
            margin-bottom: 10px;
            font-size: .9rem;
        }

        .status-open {
            background: #d1d1d1;
        }

        .status-getekend {
            background: #f6f9e9;
        }

        .status-uitgevoerd {
            background: #efd4ff;
        }

        .status-gecontroleerd {
            background: #fff8cf;
        }

        .status-geannuleerd {
            background: #ffa7a7;
        }

        .status-afgesloten {
            background: #948d8d;
        }

        .status-gepland {
            background: #d4ffda;
        }

        .status-onderhanden {
            background: #ffeedd;
        }

        .status-gefactureerd {
            background: #8ec9ba;
        }

        .badge.status-open,
        .badge.status-getekend,
        .badge.status-uitgevoerd,
        .badge.status-gecontroleerd,
        .badge.status-geannuleerd,
        .badge.status-afgesloten,
        .badge.status-gepland,
        .badge.status-onderhanden,
        .badge.status-gefactureerd,
        .active-filter-chip.status-open,
        .active-filter-chip.status-getekend,
        .active-filter-chip.status-uitgevoerd,
        .active-filter-chip.status-gecontroleerd,
        .active-filter-chip.status-geannuleerd,
        .active-filter-chip.status-afgesloten,
        .active-filter-chip.status-gepland,
        .active-filter-chip.status-onderhanden,
        .active-filter-chip.status-gefactureerd {
            color: var(--text);
            border-color: #cfd8e2;
        }

        .badge.status-open,
        .active-filter-chip.status-open {
            background: #d1d1d1;
        }

        .badge.status-getekend,
        .active-filter-chip.status-getekend {
            background: #f6f9e9;
        }

        .badge.status-uitgevoerd,
        .active-filter-chip.status-uitgevoerd {
            background: #efd4ff;
        }

        .badge.status-gecontroleerd,
        .active-filter-chip.status-gecontroleerd {
            background: #fff8cf;
        }

        .badge.status-geannuleerd,
        .active-filter-chip.status-geannuleerd {
            background: #ffa7a7;
        }

        .badge.status-afgesloten,
        .active-filter-chip.status-afgesloten {
            background: #948d8d;
        }

        .badge.status-gepland,
        .active-filter-chip.status-gepland {
            background: #d4ffda;
        }

        .badge.status-onderhanden,
        .active-filter-chip.status-onderhanden {
            background: #ffeedd;
        }

        .badge.status-gefactureerd,
        .active-filter-chip.status-gefactureerd {
            background: #8ec9ba;
        }

        .wo-list,
        .line-list {
            display: grid;
            gap: 10px;
        }

        .wo-day-separator {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            color: var(--muted);
            font-size: .82rem;
            font-weight: 600;
            text-transform: none;
            letter-spacing: .01em;
            margin: 2px 0;
        }

        .wo-day-separator::before,
        .wo-day-separator::after {
            content: '';
            height: 1px;
            background: var(--border);
            flex: 1;
        }

        .card {
            display: block;
            text-decoration: none;
            color: inherit;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 12px;
        }

        .card:active {
            transform: scale(.997);
        }

        .feedback-card {
            margin-top: 12px;
            width: 50%;
            margin-left: auto;
            margin-right: auto;
        }

        .status-extra-card {
            margin-top: 12px;
            width: 75%;
            margin-left: auto;
            margin-right: auto;
        }

        .status-extra-list {
            display: grid;
            gap: 8px;
        }

        .status-extra-row {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 6px;
        }

        .status-extra-reasons {
            display: inline-flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 6px;
        }

        .status-extra-sep {
            color: var(--muted);
        }

        .row {
            display: flex;
            justify-content: space-between;
            align-items: start;
            gap: 10px;
        }

        .badge-stack {
            display: inline-flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: 6px;
        }

        .wo-no {
            font-size: .95rem;
            font-weight: 700;
            color: var(--text);
            margin: 0 0 2px;
        }

        .wo-no-large {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text);
            margin: 0 0 2px;
        }

        .wo-task {
            margin: 0;
            font-size: 1rem;
            line-height: 1.35;
        }

        .meta {
            margin-top: 6px;
            font-size: .86rem;
            color: var(--muted);
            line-height: 1.35;
        }

        .badge {
            border-radius: 999px;
            padding: 3px 9px;
            font-size: .72rem;
            font-weight: 600;
            white-space: nowrap;
            border: 1px solid transparent;
        }

        .badge.ok {
            color: var(--ok);
            border-color: #bce4cb;
            background: #eef9f2;
        }

        .badge.warn {
            color: var(--warn);
            border-color: #ead8b7;
            background: #fbf5ea;
        }

        .badge.neutral {
            color: var(--neutral);
            border-color: #d7e0e8;
            background: #f7f9fb;
        }

        .badge.unknown {
            color: #8e2a2a;
            border-color: #f2cdcd;
            background: #fff3f3;
        }

        .detail-head {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 12px;
            margin-bottom: 10px;
        }

        .back {
            display: inline-block;
            margin-bottom: 8px;
            color: var(--primary);
            font-weight: 600;
            text-decoration: none;
            font-size: .9rem;
        }

        .line-name {
            margin: 0;
            font-size: .95rem;
            line-height: 1.35;
        }

        .line-subtitle {
            margin: 2px 0 0;
            color: var(--muted);
            font-size: .8rem;
            line-height: 1.25;
        }

        .line-desc {
            margin-top: 5px;
            color: var(--muted);
            font-size: .87rem;
            white-space: pre-wrap;
        }

        .status-detail {
            margin-top: 5px;
            color: var(--muted);
            font-size: .8rem;
        }

        .status-material-label {
            margin-top: 5px;
            color: var(--text);
            font-size: .82rem;
            font-weight: 600;
        }

        .empty {
            text-align: center;
            color: var(--muted);
            padding: 18px 10px;
            font-size: .92rem;
        }

        .toolbar {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 10px;
            margin-bottom: 10px;
            display: grid;
            gap: 8px;
        }

        .toolbar.is-hidden {
            display: none;
        }

        .field label {
            display: block;
            font-size: .78rem;
            color: var(--muted);
            margin-bottom: 4px;
        }

        .field select {
            width: 100%;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 10px;
            font-size: .95rem;
            background: #fff;
            color: var(--text);
        }

        .field input {
            width: 100%;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 10px;
            font-size: .95rem;
            background: #fff;
            color: var(--text);
        }

        .toolbar .actions {
            display: flex;
            justify-content: flex-end;
            align-items: flex-start;
            gap: 8px;
            flex-wrap: wrap;
        }

        .toolbar .actions .field {
            min-width: 150px;
            margin: 0;
        }

        .toolbar .actions .status-filter-trigger {
            margin-right: 0;
        }

        .toolbar .actions .active-filter-groups {
            display: grid;
            gap: 4px;
            flex: 1 1 auto;
            min-width: 120px;
        }

        .toolbar .actions .active-filter-chips {
            display: flex;
            flex-wrap: wrap;
            gap: 0;
        }

        .toolbar .actions .active-filter-row {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .toolbar .actions .active-filter-prefix {
            color: var(--muted);
            font-size: .72rem;
            font-weight: 600;
            line-height: 1;
            width: 22px;
            display: inline-flex;
            justify-content: flex-end;
            text-transform: uppercase;
        }

        .active-filter-chip {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #cfd8e2;
            border-radius: 999px;
            width: 14px;
            height: 14px;
            padding: 0;
            background: #f7f9fb;
            color: var(--text);
        }

        .range-row {
            display: grid;
            grid-template-columns: 1fr 1fr var(--day-button-width);
            gap: 8px;
        }

        .range-row input[type="date"] {
            max-width: var(--date-picker-width);
        }

        .day-picker-field {
            position: relative;
        }

        .day-picker-input {
            position: absolute;
            width: 1px;
            height: 1px;
            opacity: 0;
            pointer-events: none;
        }

        .toolbar .day-picker-button {
            width: auto;
            min-width: 0;
            border: 0;
            background: transparent;
            color: inherit;
            border-radius: 0;
            padding: 0;
            font-size: 1.15rem;
            font-weight: 400;
            line-height: 1;
            text-transform: none;
            cursor: pointer;
        }

        .toolbar button {
            border: 1px solid var(--primary);
            background: var(--primary);
            color: #fff;
            border-radius: 10px;
            padding: 9px 12px;
            font-size: .9rem;
            font-weight: 600;
        }

        .toolbar button.is-attention-bounce {
            animation: applyButtonBounce .95s cubic-bezier(.22, .96, .35, 1);
        }

        @keyframes applyButtonBounce {
            0% {
                transform: translateY(0) scale(1);
            }

            12% {
                transform: translateY(-6px) scale(1.03);
            }

            24% {
                transform: translateY(0) scale(1);
            }

            38% {
                transform: translateY(-5px) scale(1.025);
            }

            50% {
                transform: translateY(0) scale(1);
            }

            64% {
                transform: translateY(-4px) scale(1.02);
            }

            76% {
                transform: translateY(0) scale(1);
            }

            88% {
                transform: translateY(-2px) scale(1.01);
            }

            100% {
                transform: translateY(0) scale(1);
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .toolbar button.is-attention-bounce {
                animation: none;
            }
        }

        .button {
            border: 1px solid var(--primary);
            background: var(--primary);
            color: #fff;
            border-radius: 10px;
            padding: 9px 12px;
            font-size: .9rem;
            font-weight: 600;
        }

        .status-modal {
            position: fixed;
            inset: 0;
            z-index: 4200;
            display: grid;
            place-items: center;
            padding: 14px;
        }

        .status-modal[hidden] {
            display: none;
        }

        .status-modal-backdrop {
            position: absolute;
            inset: 0;
            background: rgba(21, 34, 51, 0.46);
        }

        .status-modal-card {
            position: relative;
            z-index: 1;
            width: min(100%, 620px);
            max-height: min(82vh, 700px);
            overflow: auto;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 12px;
        }

        .status-modal-columns {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 10px;
        }

        .status-modal-column-title {
            margin: 0 0 6px;
            font-size: .84rem;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: .02em;
        }

        .status-modal-title {
            margin: 0 0 4px;
            font-size: 1rem;
        }

        .status-modal-subtitle {
            margin: 0 0 10px;
            color: var(--muted);
            font-size: .85rem;
        }

        .status-modal-list {
            display: grid;
            gap: 8px;
            margin-bottom: 0;
        }

        .status-filter-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: .92rem;
            color: var(--text);
        }

        .status-filter-item input {
            width: auto;
            margin: 0;
        }

        .status-filter-label {
            display: inline-flex;
            align-items: center;
            border: 1px solid #cfd8e2;
            border-radius: 999px;
            padding: 3px 9px;
            color: var(--text);
        }

        .status-modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
        }

        .status-modal-actions .secondary {
            border-color: var(--border);
            background: #fff;
            color: var(--text);
        }

        .page-loader {
            position: fixed;
            inset: 0;
            background: rgba(242, 245, 249, 0.96);
            display: none;
            place-items: center;
            z-index: 4000;
        }

        .page-loader.visible {
            display: grid;
        }

        .page-loader-visual {
            position: relative;
            width: min(220px, 62vw);
            aspect-ratio: 1 / 1;
            display: grid;
            place-items: center;
        }

        .page-loader-visual::before {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: 50%;
            border: 15px solid #d7e4f5;
            border-top-color: var(--primary);
            border-right-color: #4a7fc6;
            animation: loaderSpin 1.05s linear infinite;
            z-index: 1;
        }

        .page-loader-visual img {
            width: min(160px, 58vw);
            height: auto;
            position: relative;
            z-index: 2;
            filter: drop-shadow(0 2px 6px rgba(21, 34, 51, 0.08));
        }

        .page-loader-text {
            margin-top: -66px;
            max-width: min(320px, 86vw);
            text-align: center;
            color: #2d3e53;
            font-size: .95rem;
            line-height: 1.35;
            font-weight: 600;
            opacity: 1;
            transition: opacity 420ms ease;
        }

        .page-loader-text.fade-out {
            opacity: 0;
        }

        .loader-progress {
            width: min(320px, 84vw);
            margin-top: 298px;
            margin-bottom: 0;
            position: relative;
            z-index: 3;
        }

        .loader-progress-shell {
            position: relative;
            height: 12px;
            border-radius: 999px 0 0 999px;
            border: 1px solid #aac0da;
            border-right: none;
            background: #e9eff7;
            overflow: visible;
            transform-origin: center;
        }

        .loader-progress-fill {
            height: 100%;
            width: 0%;
            border-radius: 999px 0 0 999px;
            background: #4c89d3;
            /*            box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.25);*/
            transform-origin: bottom;
        }

        .loader-progress-fill.draining {
            animation: loaderVerticalDrain 2200ms linear forwards;
        }

        .loader-progress-tip {
            position: absolute;
            right: -18px;
            top: 50%;
            width: 18px;
            height: 12px;
            transform: translateY(-50%);
            border-radius: 0 6px 6px 0;
            background: #e9eff7;
            border: 1px solid #aac0da;
            border-left: none;
            transform-origin: left center;
            transition: opacity 220ms ease;
            z-index: 3;
            overflow: hidden;
        }

        .loader-progress-tip::after {
            content: '';
            position: absolute;
            left: -1px;
            top: 0;
            height: 100%;
            width: 0%;
            border-radius: inherit;
            background: #4c89d3;
            transition: width 500ms linear;
        }

        .loader-progress-shell.tip-fill .loader-progress-tip::after {
            width: calc(100% + 1px);
        }

        .loader-progress-shell.tip-burst .loader-progress-tip {
            animation: loaderTipBurst 980ms ease-in forwards;
        }

        .loader-progress-spill {
            position: absolute;
            right: -16px;
            top: 6px;
            width: 88px;
            height: 220px;
            opacity: 0;
            pointer-events: none;
            overflow: visible;
        }

        .loader-progress-spill path {
            stroke: #6ea5e7;
            stroke-width: 2;
            fill: none;
            stroke-linecap: round;
            stroke-linejoin: round;
            filter: drop-shadow(0 1px 2px rgba(33, 86, 160, 0.22));
            stroke-dasharray: 320;
            stroke-dashoffset: 320;
        }

        .loader-progress-shell.spilling .loader-progress-spill {
            opacity: 1;
            transition: opacity 120ms linear;
        }

        .loader-progress-shell.spilling .loader-progress-spill path {
            animation: loaderSpillFlow 2200ms linear forwards, loaderSpillThickness 2200ms linear forwards;
        }

        @keyframes loaderVerticalDrain {
            0% {
                clip-path: inset(0 0 0 0);
            }

            100% {
                clip-path: inset(100% 0 0 0);
            }
        }

        @keyframes loaderTipBurst {
            0% {
                transform: translateY(-50%) translateX(0) rotate(0deg);
                opacity: 1;
            }

            15% {
                transform: translateY(-50%) translateX(8px) rotate(22deg);
                opacity: 1;
            }

            100% {
                transform: translateY(-50%) translateX(120vw) rotate(68deg);
                opacity: 0;
            }
        }

        @keyframes loaderSpillFlow {
            0% {
                stroke-dashoffset: 320;
                opacity: 0.55;
            }

            12% {
                opacity: 1;
            }

            100% {
                stroke-dashoffset: 0;
                opacity: 0.3;
            }
        }

        @keyframes loaderSpillThickness {
            0% {
                stroke-width: 2;
            }

            35% {
                stroke-width: 8;
            }

            100% {
                stroke-width: 0;
            }
        }

        @keyframes loaderSpin {
            from {
                transform: rotate(0deg);
            }

            to {
                transform: rotate(360deg);
            }
        }

        @media (min-width: 900px) {
            .page {
                padding-top: 20px;
            }
        }
    </style>
</head>

<body>
    <main class="page">
        <img src="logo-website.png" alt="KVT" class="logo" />

        <form class="toolbar<?= $selectedWorkOrder !== null ? ' is-hidden' : '' ?>" method="get" action="index.php"
            data-nav-form>
            <div class="field">
                <label for="company">Bedrijf</label>
                <select id="company" name="company" onchange="this.form.submit()">
                    <?php foreach ($companies as $companyOption): ?>
                        <option value="<?= htmlspecialchars($companyOption) ?>" <?= $companyOption === $company ? 'selected' : '' ?>>
                            <?= htmlspecialchars($companyOption) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="person">Servicemonteur</label>
                <select id="person" name="person" onchange="this.form.submit()"
                    data-counts-url="<?= htmlspecialchars($resourceCountsUrl) ?>"
                    data-own-resource="<?= htmlspecialchars($ownResourceNo) ?>">
                    <?php if (count($serviceResources) === 0): ?>
                        <option value="">Gegevens van servicemonteurs worden opgehaald...</option>
                    <?php else: ?>
                        <?php foreach ($serviceResources as $serviceResource): ?>
                            <?php $resourceNo = trim((string) ($serviceResource['No'] ?? '')); ?>
                            <?php $baseName = safe_text((string) ($serviceResource['Name'] ?? '')); ?>
                            <option value="<?= htmlspecialchars($resourceNo) ?>" data-name="<?= htmlspecialchars($baseName) ?>"
                                <?= $resourceNo === $selectedResourceNo ? 'selected' : '' ?>>
                                <?php
                                $openCount = (int) ($openWorkOrderCounts[$resourceNo] ?? 0);
                                $optionLabel = $baseName;
                                if (isset($openWorkOrderCounts[$resourceNo])) {
                                    $optionLabel .= ' (' . $openCount . ' werkorders)';
                                }
                                ?>
                                <?= htmlspecialchars($optionLabel) ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>
            <div class="range-row">
                <div class="field">
                    <label for="date_from">Van</label>
                    <input id="date_from" name="date_from" type="date"
                        value="<?= htmlspecialchars($dateFromValue) ?>" />
                </div>
                <div class="field">
                    <label for="date_to">Tot</label>
                    <input id="date_to" name="date_to" type="date" value="<?= htmlspecialchars($dateToValue) ?>" />
                </div>
                <div class="field day-picker-field">
                    <label for="date_day">Dag</label>
                    <input id="date_day" class="day-picker-input" type="date" />
                    <button id="pick-day" class="day-picker-button" type="button">📅</button>
                </div>
            </div>
            <div class="field">
                <label for="q">Zoeken op werkorder of omschrijving</label>
                <input id="q" name="q" type="search" value="<?= htmlspecialchars($searchQuery) ?>"
                    placeholder="Bijv. WO-123 of onderhoud" />
            </div>
            <div class="actions">
                <button id="open-status-filter" class="status-filter-trigger" type="button">Statusfilter</button>
                <div class="active-filter-groups">
                    <div class="active-filter-row" aria-label="Actieve werkorderstatusfilters">
                        <span class="active-filter-prefix">WO</span>
                        <div class="active-filter-chips">
                            <?php foreach ($activeStatusFilters as $activeStatus): ?>
                                <?php $activeStatusClass = status_css_class($activeStatus); ?>
                                <span class="active-filter-chip <?= htmlspecialchars($activeStatusClass) ?>"
                                    title="<?= htmlspecialchars($activeStatus) ?>"
                                    aria-label="<?= htmlspecialchars($activeStatus) ?>"></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="active-filter-row" aria-label="Actieve Webfleet-filters">
                        <span class="active-filter-prefix">WF</span>
                        <div class="active-filter-chips">
                            <?php foreach ($activeWebfleetFilters as $activeWebfleetStatus): ?>
                                <?php $activeWebfleetClass = webfleet_status_badge_class($activeWebfleetStatus); ?>
                                <span class="active-filter-chip <?= htmlspecialchars($activeWebfleetClass) ?>"
                                    title="<?= htmlspecialchars($activeWebfleetStatus) ?>"
                                    aria-label="<?= htmlspecialchars($activeWebfleetStatus) ?>"></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <input id="status_filters" name="status_filters" type="hidden"
                    value="<?= htmlspecialchars($statusFiltersPayloadValue) ?>" />
                <input id="webfleet_status_filters" name="webfleet_status_filters" type="hidden"
                    value="<?= htmlspecialchars($webfleetStatusFiltersPayloadValue) ?>" />
                <button id="apply-filters-button" type="submit">Toepassen</button>
            </div>
        </form>

        <div id="status-filter-modal" class="status-modal" hidden>
            <div class="status-modal-backdrop" data-status-close></div>
            <div class="status-modal-card" role="dialog" aria-modal="true" aria-labelledby="status-modal-title">
                <h2 id="status-modal-title" class="status-modal-title">Statusfilter</h2>
                <p class="status-modal-subtitle">Toon alleen werkorders die voldoen aan beide statusfilters.</p>
                <div class="status-modal-columns">
                    <div>
                        <h3 class="status-modal-column-title">Werkorderstatus</h3>
                        <div class="status-modal-list">
                            <?php if (count($statusCatalog) === 0): ?>
                                <div class="empty">Geen statussen beschikbaar.</div>
                            <?php else: ?>
                                <?php foreach ($statusCatalog as $statusOption): ?>
                                    <?php
                                    $statusCount = (int) ($workOrderStatusCounts[$statusOption] ?? 0);
                                    $statusEnabled = array_key_exists($statusOption, $statusFiltersForModal)
                                        ? (bool) $statusFiltersForModal[$statusOption]
                                        : status_enabled_default($statusOption);
                                    $statusOptionClass = status_css_class($statusOption);
                                    ?>
                                    <label class="status-filter-item">
                                        <input type="checkbox" class="status-filter-checkbox"
                                            data-status="<?= htmlspecialchars($statusOption) ?>" <?= $statusEnabled ? 'checked' : '' ?> />
                                        <span
                                            class="status-filter-label <?= htmlspecialchars($statusOptionClass) ?>"><?= htmlspecialchars($statusOption . ' (' . $statusCount . ')') ?></span>
                                    </label>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div>
                        <h3 class="status-modal-column-title">Webfleet</h3>
                        <div class="status-modal-list">
                            <?php foreach ($webfleetStatusCatalog as $webfleetStatusOption): ?>
                                <?php
                                $webfleetStatusCount = (int) ($workOrderWebfleetStatusCounts[$webfleetStatusOption] ?? 0);
                                $webfleetStatusEnabled = array_key_exists($webfleetStatusOption, $webfleetStatusFiltersForModal)
                                    ? (bool) $webfleetStatusFiltersForModal[$webfleetStatusOption]
                                    : true;
                                $webfleetStatusOptionClass = webfleet_status_badge_class($webfleetStatusOption);
                                ?>
                                <label class="status-filter-item">
                                    <input type="checkbox" class="webfleet-filter-checkbox"
                                        data-webfleet-status="<?= htmlspecialchars($webfleetStatusOption) ?>"
                                        <?= $webfleetStatusEnabled ? 'checked' : '' ?> />
                                    <span
                                        class="status-filter-label <?= htmlspecialchars($webfleetStatusOptionClass) ?>"><?= htmlspecialchars($webfleetStatusOption . ' (' . $webfleetStatusCount . ')') ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="status-modal-actions">
                    <button class="button secondary" type="button" data-status-close>Annuleren</button>
                    <button class="button" id="save-status-filter" type="button">Opslaan</button>
                </div>
            </div>
        </div>

        <?php if ($errorMessage !== ''): ?>
            <div class="alert">Fout bij laden van Business Central data: <?= htmlspecialchars($errorMessage) ?></div>
        <?php endif; ?>

        <?php if ($selectedWorkOrder !== null): ?>
            <section class="detail-head">
                <a href="<?= htmlspecialchars($listHref) ?>" class="back" data-nav-link>← Terug naar werkorders</a>
                <p class="wo-no-large"><?= bc_text_html((string) ($selectedWorkOrder['No'] ?? '')) ?></p>
                <h1 class="title">
                    <?= bc_text_html(workorder_task_text($selectedWorkOrder)) ?>
                </h1>
                <p class="subtitle">
                    Uitvoerdatum: <?= htmlspecialchars(nl_date((string) ($selectedWorkOrder['Start_Date'] ?? ''))) ?>
                    · Monteur: <?= bc_text_html((string) ($selectedWorkOrder['Resource_Name'] ?? '')) ?>
                    <?php if ($selectedWorkOrderStartTime !== '' || $selectedWorkOrderEndTime !== ''): ?>
                        <br />
                        <?php if ($selectedWorkOrderStartTime !== ''): ?>
                            Starttijd: <?= htmlspecialchars($selectedWorkOrderStartTime) ?>
                        <?php endif; ?>
                        <?php if ($selectedWorkOrderStartTime !== '' && $selectedWorkOrderEndTime !== ''): ?>
                            ·
                        <?php endif; ?>
                        <?php if ($selectedWorkOrderEndTime !== ''): ?>
                            Eindtijd: <?= htmlspecialchars($selectedWorkOrderEndTime) ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </p>
                <?php
                $selectedWorkOrderWebfleetBadgeClass = 'badge ' . webfleet_status_badge_class($selectedWorkOrderWebfleetStatusLabel);
                ?>
                <div class="meta">
                    Webfleet status:
                    <span
                        class="<?= htmlspecialchars($selectedWorkOrderWebfleetBadgeClass) ?>"><?= htmlspecialchars($selectedWorkOrderWebfleetStatusLabel) ?></span><br />
                    Object:
                    <?= bc_text_html((string) ($selectedWorkOrder['Main_Entity_Description'] ?? '')) ?><br />
                    Component: <a href="<?= get_sharepoint_url($selectedWorkOrder) ?>">
                        <?= bc_text_html((string) ($selectedWorkOrder['Component_Description'] ?? '')) ?></a><br /><br />
                    Materiaal nodig:
                    <?= htmlspecialchars(material_needed_text($selectedWorkOrderRealArticleCount)) ?><br />
                    <?php if ($selectedWorkOrderRealArticleCount > 0): ?>
                        Materiaalstatus:
                        <?= htmlspecialchars($selectedWorkOrderMaterialStatusLabel) ?><br />
                    <?php endif; ?><br />
                    <?php
                    $primaryContactName = trim((string) $selectedWorkOrderPrimaryContactName);
                    $primaryContactPhone = trim((string) ($selectedWorkOrder['KVT_Primary_Contact_Phone_No'] ?? ''));

                    $visitAddressText = trim((string) $selectedWorkOrderVisitAddress);
                    $visitCountryCode = trim((string) ($selectedWorkOrder['Visit_Country_Region_Code'] ?? ''));
                    $primaryContactPhoneHref = phone_tel_href($primaryContactPhone, $visitCountryCode);
                    $hasVisitInfo = $visitAddressText !== '';
                    ?>
                    <?php if ($primaryContactPhone !== '' || $hasVisitInfo): ?>
                        <?php if ($primaryContactName !== ''): ?>
                            <b>Contactpersoon</b>: <?= bc_text_html($primaryContactName) ?><br />
                        <?php endif; ?>
                        <?php if ($primaryContactPhone !== ''): ?>
                            <b>Telefoon</b>:
                            <?php if ($primaryContactPhoneHref !== ''): ?>
                                <a
                                    href="tel:<?= htmlspecialchars($primaryContactPhoneHref) ?>"><?= htmlspecialchars($primaryContactPhone) ?></a><br />
                            <?php else: ?>
                                <?= htmlspecialchars($primaryContactPhone) ?><br />
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php if ($hasVisitInfo): ?>
                            <b>Bezoekadres</b>:<br />
                            <?= bc_text_html($visitAddressText) ?><br />
                        <?php endif; ?>
                        <br />
                    <?php endif; ?>
                    <?php
                    $omschrijvingParts = [];
                    $memoText = trim((string) ($selectedWorkOrder['KVT_Memo'] ?? ''));
                    $memoInternalText = trim((string) ($selectedWorkOrder['KVT_Memo_Internal_Use_Only'] ?? ''));
                    if ($memoText !== '') {
                        $omschrijvingParts[] = bc_text_html($memoText, '');
                    }
                    if ($memoInternalText !== '') {
                        $omschrijvingParts[] = bc_text_html($memoInternalText, '');
                    }
                    ?>
                    <?php if (!empty($omschrijvingParts)): ?>
                        <b>Omschrijving:</b><br />
                        <?= implode('<br/><br/>', $omschrijvingParts) ?>
                    <?php endif; ?>
                </div>
            </section>

            <?php if (count($taskArticleLines) > 0): ?>
                <h2 class="title">Taakomschrijving:</h2>
                <section class="line-list">
                    <?php foreach ($taskArticleLines as $line): ?>
                        <?php $taskExtendedText = trim((string) ($line['KVT_Extended_Text'] ?? '')); ?>
                        <article class="card">
                            <p class="line-name"><?= bc_text_html((string) ($line['Description'] ?? '')) ?></p>
                            <?php if ($taskExtendedText !== ''): ?>
                                <div class="line-desc">
                                    <?= bc_text_html($taskExtendedText, '') ?>
                                </div>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </section>
            <?php endif; ?>

            <h2 class="title">Artikelen:</h2>

            <?php if (count($planningLines) === 0): ?>
                <div class="card empty">Geen artikelen gevonden voor deze werkorder.</div>
            <?php else: ?>
                <section class="line-list">
                    <?php foreach ($planningLines as $line): ?>
                        <?php $lineStatus = material_line_status($line); ?>
                        <?php $extendedText = trim((string) ($line['KVT_Extended_Text'] ?? '')); ?>
                        <?php $lineNo = trim((string) ($line['No'] ?? '')); ?>
                        <article class="card">
                            <div class="row">
                                <div>
                                    <p class="line-name"><?= bc_text_html((string) ($line['Description'] ?? '')) ?></p>
                                    <p class="line-subtitle"><?= bc_text_html($lineNo !== '' ? $lineNo : '-') ?></p>
                                </div>
                                <span
                                    class="badge <?= htmlspecialchars($lineStatus['class']) ?>"><?= htmlspecialchars(safe_text((string) ($lineStatus['material_status_label'] ?? 'Onbekend'))) ?></span>
                            </div>
                            <div class="meta">
                                Aantal: <?= htmlspecialchars((string) ($line['Quantity'] ?? '0')) ?>
                                <?= bc_text_html((string) ($line['Unit_of_Measure_Code'] ?? ''), '') ?>
                            </div>
                            <?php if ($extendedText !== ''): ?>
                                <div class="line-desc">
                                    <?= bc_text_html($extendedText, '') ?>
                                </div>
                            <?php endif; ?>
                            <div class="status-material-label">
                                Materiaalstatus:
                                <?= htmlspecialchars(safe_text((string) ($lineStatus['material_status_label'] ?? 'Onbekend'))) ?>
                            </div>
                            <?php if (trim((string) ($lineStatus['detail'] ?? '')) !== ''): ?>
                                <div class="status-detail"><?= htmlspecialchars((string) $lineStatus['detail']) ?></div>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </section>
            <?php endif; ?>

        <?php else: ?>
            <h1 class="title">Mijn werkorders</h1>

            <?php if ($selectedResourceNo === ''): ?>
                <div class="card empty">Geen servicemonteur geselecteerd.</div>
            <?php elseif (count($workOrders) === 0): ?>
                <div class="card empty">Geen werkorders gevonden.</div>
            <?php else: ?>
                <section class="wo-list">
                    <?php $previousWorkOrderDayKey = ''; ?>
                    <?php foreach ($workOrders as $workOrder): ?>
                        <?php
                        $workOrderHrefParams = $listQuery;
                        $workOrderHrefParams['workorder'] = (string) ($workOrder['No'] ?? '');
                        $workOrderHref = 'index.php?' . http_build_query($workOrderHrefParams, '', '&', PHP_QUERY_RFC3986);
                        $workOrderStartDateRaw = (string) ($workOrder['Start_Date'] ?? '');
                        $workOrderDayKey = workorder_day_key($workOrderStartDateRaw);
                        if ($workOrderDayKey === '') {
                            $workOrderDayKey = '__onbekend__';
                        }
                        $showDaySeparator = $workOrderDayKey !== $previousWorkOrderDayKey;
                        if ($showDaySeparator) {
                            $previousWorkOrderDayKey = $workOrderDayKey;
                        }
                        $workOrderNo = (string) ($workOrder['No'] ?? '');
                        $realArticleCount = (int) ($workOrderRealArticleCounts[$workOrderNo] ?? 0);
                        $workOrderStatusText = safe_text((string) ($workOrder['Status'] ?? ''));
                        $workOrderStatusClass = status_css_class((string) ($workOrder['Status'] ?? ''));
                        $workOrderBadgeClass = $workOrderStatusClass !== '' ? ('badge ' . $workOrderStatusClass) : 'badge neutral';
                        $workOrderWebfleetStatusLabel = safe_text((string) ($workOrderWebfleetStatusLabels[$workOrderNo] ?? webfleet_default_status_label()));
                        $workOrderWebfleetBadgeClass = 'badge ' . webfleet_status_badge_class($workOrderWebfleetStatusLabel);
                        $workOrderMaterialStatusLabel = safe_text((string) ($workOrderMaterialStatusLabels[$workOrderNo] ?? 'Onbekend'));
                        $workOrderMaterialBadgeClass = 'badge ' . workorder_material_badge_class($workOrderMaterialStatusLabel);
                        ?>
                        <?php if ($showDaySeparator): ?>
                            <div class="wo-day-separator"><?= htmlspecialchars(workorder_day_separator_label($workOrderStartDateRaw)) ?>
                            </div>
                        <?php endif; ?>
                        <a class="card" href="<?= htmlspecialchars($workOrderHref) ?>" data-nav-link>
                            <div class="row">
                                <div>
                                    <p class="wo-no"><?= bc_text_html((string) ($workOrder['No'] ?? '')) ?>
                                    </p>
                                    <h2 class="wo-task">
                                        <?= bc_text_html(workorder_task_text($workOrder)) ?>
                                    </h2>
                                </div>
                                <div class="badge-stack">
                                    <span
                                        class="<?= htmlspecialchars($workOrderWebfleetBadgeClass) ?>"><?= htmlspecialchars($workOrderWebfleetStatusLabel) ?></span>
                                    <span
                                        class="<?= htmlspecialchars($workOrderBadgeClass) ?>"><?= htmlspecialchars($workOrderStatusText) ?></span>
                                </div>
                            </div>
                            <div class="meta">
                                <b>Uitvoerdatum</b>:
                                <?= htmlspecialchars(nl_date((string) ($workOrder['Start_Date'] ?? ''))) ?><br />
                                <b>Object</b>:
                                <?= bc_text_html((string) ($workOrder['Main_Entity_Description'] ?? '')) ?><br />
                                <b>Component</b>:
                                <?= bc_text_html((string) ($workOrder['Component_Description'] ?? '')) ?><br />
                                <b>Materiaal nodig</b>: <?= htmlspecialchars(material_needed_text($realArticleCount)) ?><br />
                                <?php if ($realArticleCount > 0): ?>
                                    <b>Materiaalstatus</b>:
                                    <span
                                        class="<?= htmlspecialchars($workOrderMaterialBadgeClass) ?>"><?= htmlspecialchars($workOrderMaterialStatusLabel) ?></span>
                                <?php endif; ?>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </section>
            <?php endif; ?>

            <?php if (count($disabledFilterExtraRows) > 0): ?>
                <article class="card status-extra-card">
                    <p class="wo-no">Uitgeschakelde filters</p>
                    <div class="meta status-extra-list">
                        <?php foreach ($disabledFilterExtraRows as $disabledFilterExtraRow): ?>
                            <?php
                            $extraCount = (int) ($disabledFilterExtraRow['count'] ?? 0);
                            $extraReasons = is_array($disabledFilterExtraRow['reasons'] ?? null) ? $disabledFilterExtraRow['reasons'] : [];
                            ?>
                            <div class="status-extra-row">
                                <span><?= htmlspecialchars((string) $extraCount) ?> extra
                                    werkorder<?= $extraCount === 1 ? '' : 's' ?> voor</span>
                                <span class="status-extra-reasons">
                                    <?php foreach ($extraReasons as $reasonIndex => $extraReason): ?>
                                        <?php
                                        $reasonLabel = trim((string) ($extraReason['label'] ?? ''));
                                        $reasonClass = trim((string) ($extraReason['class'] ?? ''));
                                        ?>
                                        <span
                                            class="status-filter-label<?= $reasonClass !== '' ? (' ' . htmlspecialchars($reasonClass)) : '' ?>"><?= htmlspecialchars($reasonLabel) ?></span>
                                    <?php endforeach; ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </article>
            <?php endif; ?>

            <article class="card feedback-card">
                <p class="wo-no">Vragen of feedback?</p>
                <div class="meta">
                    Mail <a href="mailto:ict@kvt.nl">ict@kvt.nl</a>
                </div>
            </article>
        <?php endif; ?>

        <?= injectTimerHtml([
            'statusUrl' => 'odata.php?action=cache_status',
            'deleteUrl' => 'odata.php?action=cache_delete',
            'clearUrl' => 'odata.php?action=cache_clear',
            'label' => 'Cache',
            'title' => 'OData cache',
            'css' => '{{root}} .odata-cache-widget{position:fixed;right:10px;bottom:12px;top:auto;z-index:1200;background:#ffffffed;backdrop-filter:blur(4px);}{{root}} .odata-cache-popout{position:fixed;left:8px;right:8px;top:66px;max-height:calc(100vh - 76px);width:auto;}@media (max-width: 900px){ {{root}} .odata-cache-widget{display:none !important;} }',
        ]) ?>
    </main>

    <div class="page-loader" id="page-loader" aria-live="polite" aria-busy="true">
        <div class="page-loader-visual">
            <img src="kvtlogo.png" alt="Laden" />
        </div>
        <div class="loader-progress" id="loader-progress">
            <div class="loader-progress-shell" id="loader-progress-shell">
                <div class="loader-progress-fill" id="loader-progress-fill"></div>
                <div class="loader-progress-tip"></div>
                <svg class="loader-progress-spill" id="loader-progress-spill" viewBox="0 0 88 220" aria-hidden="true"
                    focusable="false" preserveAspectRatio="none">
                    <path d="M70 4 C 78 6, 84 14, 84 28 C 84 56, 84 96, 84 136 C 84 170, 84 196, 84 220" />
                </svg>
            </div>
        </div>
        <div class="page-loader-text" id="page-loader-text"><?= htmlspecialchars($loadingTextInitial) ?></div>
    </div>

    <script>
        (function ()
        {
            const loaderEl = document.getElementById('page-loader');
            const loaderTextEl = document.getElementById('page-loader-text');
            const loaderProgressShellEl = document.getElementById('loader-progress-shell');
            const loaderProgressFillEl = document.getElementById('loader-progress-fill');
            const loadingTexts = <?= json_encode($loadingTextOptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
            if (!loaderEl)
            {
                return;
            }

            const fillDurationMs = 8000;
            const tipFillDurationMs = 500;
            const shakeRampMs = 1000;
            const drainDurationMs = 2200;
            const loaderWatchdogMs = 20000;
            let progressRunning = false;
            let fillTimeoutId = null;
            let tipFillTimeoutId = null;
            let burstTimeoutId = null;
            let shakeFrameId = null;
            let shakeStartAt = 0;
            let loaderWatchdogTimeoutId = null;
            let isNavigatingAway = false;

            function clearLoaderWatchdog ()
            {
                if (loaderWatchdogTimeoutId !== null)
                {
                    window.clearTimeout(loaderWatchdogTimeoutId);
                    loaderWatchdogTimeoutId = null;
                }
            }

            function armLoaderWatchdog ()
            {
                clearLoaderWatchdog();
                loaderWatchdogTimeoutId = window.setTimeout(function ()
                {
                    if (!isNavigatingAway)
                    {
                        hideLoaderImmediately();
                    }
                }, loaderWatchdogMs);
            }

            function showLoader ()
            {
                loaderEl.classList.add('visible');
                startProgressSequence();
                armLoaderWatchdog();
            }

            function hideLoader ()
            {
                clearLoaderWatchdog();
                loaderEl.classList.remove('visible');
            }

            function hideLoaderImmediately ()
            {
                resetProgressSequence();
                hideLoader();
            }

            function resetProgressSequence ()
            {
                if (fillTimeoutId !== null)
                {
                    window.clearTimeout(fillTimeoutId);
                    fillTimeoutId = null;
                }
                if (tipFillTimeoutId !== null)
                {
                    window.clearTimeout(tipFillTimeoutId);
                    tipFillTimeoutId = null;
                }
                if (burstTimeoutId !== null)
                {
                    window.clearTimeout(burstTimeoutId);
                    burstTimeoutId = null;
                }
                clearLoaderWatchdog();
                if (shakeFrameId !== null)
                {
                    window.cancelAnimationFrame(shakeFrameId);
                    shakeFrameId = null;
                }

                if (loaderProgressShellEl)
                {
                    loaderProgressShellEl.classList.remove('tip-fill', 'tip-burst', 'spilling');
                    loaderProgressShellEl.style.transform = 'translate(0,0) rotate(0deg)';
                }

                if (loaderProgressFillEl)
                {
                    loaderProgressFillEl.style.transition = 'none';
                    loaderProgressFillEl.style.width = '0%';
                    loaderProgressFillEl.classList.remove('draining');
                    loaderProgressFillEl.style.clipPath = 'inset(0 0 0 0)';
                    void loaderProgressFillEl.offsetWidth;
                }

                progressRunning = false;
            }

            function runShakeRamp ()
            {
                if (!loaderProgressShellEl)
                {
                    return;
                }

                const now = performance.now();
                const elapsed = now - shakeStartAt;
                const progress = Math.max(0, Math.min(1, elapsed / shakeRampMs));
                const amplitude = 0.2 + (6.5 * progress);
                const x = (Math.random() * 2 - 1) * amplitude;
                const y = (Math.random() * 2 - 1) * (amplitude * 0.55);
                const rot = (Math.random() * 2 - 1) * (0.2 + progress * 2.2);
                loaderProgressShellEl.style.transform = 'translate(' + x.toFixed(2) + 'px,' + y.toFixed(2) + 'px) rotate(' + rot.toFixed(2) + 'deg)';

                if (progress < 1)
                {
                    shakeFrameId = window.requestAnimationFrame(runShakeRamp);
                    return;
                }

                shakeFrameId = null;
                loaderProgressShellEl.style.transform = 'translate(0,0) rotate(0deg)';
                loaderProgressShellEl.classList.add('tip-burst', 'spilling');

                if (loaderProgressFillEl)
                {
                    loaderProgressFillEl.style.transition = 'none';
                    loaderProgressFillEl.classList.add('draining');
                }

                burstTimeoutId = window.setTimeout(function ()
                {
                    progressRunning = false;
                }, drainDurationMs + 120);
            }

            function startProgressSequence ()
            {
                if (progressRunning)
                {
                    return;
                }

                resetProgressSequence();
                progressRunning = true;

                if (loaderProgressFillEl)
                {
                    loaderProgressFillEl.style.transition = 'width ' + fillDurationMs + 'ms linear';
                    loaderProgressFillEl.style.width = '100%';
                }

                fillTimeoutId = window.setTimeout(function ()
                {
                    if (!progressRunning)
                    {
                        return;
                    }

                    if (loaderProgressShellEl)
                    {
                        loaderProgressShellEl.classList.add('tip-fill');
                    }

                    tipFillTimeoutId = window.setTimeout(function ()
                    {
                        if (!progressRunning)
                        {
                            return;
                        }

                        shakeStartAt = performance.now();
                        shakeFrameId = window.requestAnimationFrame(runShakeRamp);
                    }, tipFillDurationMs);
                }, fillDurationMs);
            }

            function pickRandomLoadingText ()
            {
                if (!Array.isArray(loadingTexts) || loadingTexts.length === 0)
                {
                    return '';
                }

                const index = Math.floor(Math.random() * loadingTexts.length);
                return String(loadingTexts[index] || '');
            }

            function rotateLoadingText ()
            {
                if (!loaderTextEl)
                {
                    return;
                }

                const nextText = pickRandomLoadingText();
                if (nextText === '')
                {
                    return;
                }

                loaderTextEl.classList.add('fade-out');
                window.setTimeout(function ()
                {
                    loaderTextEl.textContent = nextText;
                    loaderTextEl.classList.remove('fade-out');
                }, 420);
            }

            document.querySelectorAll('form[data-nav-form]').forEach(function (formEl)
            {
                formEl.addEventListener('submit', function ()
                {
                    showLoader();
                });
            });

            document.querySelectorAll('a[data-nav-link], a.card[href]').forEach(function (linkEl)
            {
                linkEl.addEventListener('click', function (event)
                {
                    if (event.defaultPrevented)
                    {
                        return;
                    }

                    const href = (linkEl.getAttribute('href') || '').trim();
                    if (href === '' || href.charAt(0) === '#')
                    {
                        return;
                    }

                    showLoader();
                });
            });

            document.addEventListener('click', function (event)
            {
                const target = event.target;
                if (!(target instanceof Element))
                {
                    return;
                }

                const cacheActionButton = target.closest('.odata-cache-item-delete, .odata-cache-popout-clear');
                if (!cacheActionButton)
                {
                    return;
                }

                showLoader();
                window.setTimeout(hideLoader, 1300);
            }, true);

            window.addEventListener('beforeunload', function ()
            {
                isNavigatingAway = true;
                showLoader();
            });

            window.addEventListener('pageshow', function ()
            {
                isNavigatingAway = false;
                hideLoaderImmediately();
            });

            document.addEventListener('visibilitychange', function ()
            {
                if (document.visibilityState === 'hidden')
                {
                    isNavigatingAway = true;
                    return;
                }

                isNavigatingAway = false;

                if (loaderEl.classList.contains('visible') && !progressRunning)
                {
                    startProgressSequence();
                }
            });

            function submitFormSafely (formEl)
            {
                if (!formEl)
                {
                    return;
                }

                if (typeof formEl.requestSubmit === 'function')
                {
                    formEl.requestSubmit();
                    return;
                }

                formEl.submit();
            }

            if (loaderTextEl && Array.isArray(loadingTexts) && loadingTexts.length > 0)
            {
                window.setInterval(rotateLoadingText, 5000);
            }

            const personSelectEl = document.getElementById('person');
            const dateFromEl = document.getElementById('date_from');
            const dateToEl = document.getElementById('date_to');
            const searchInputEl = document.getElementById('q');
            const applyFiltersButtonEl = document.getElementById('apply-filters-button');
            const pickDayButtonEl = document.getElementById('pick-day');
            const dayPickerInputEl = document.getElementById('date_day');
            const statusModalEl = document.getElementById('status-filter-modal');
            const openStatusFilterEl = document.getElementById('open-status-filter');
            const saveStatusFilterEl = document.getElementById('save-status-filter');
            const statusFiltersInputEl = document.getElementById('status_filters');
            const webfleetStatusFiltersInputEl = document.getElementById('webfleet_status_filters');
            const statusFilterCheckboxEls = Array.from(document.querySelectorAll('.status-filter-checkbox'));
            const webfleetFilterCheckboxEls = Array.from(document.querySelectorAll('.webfleet-filter-checkbox'));
            const statusCloseEls = Array.from(document.querySelectorAll('[data-status-close]'));
            let searchApplyHintTimeoutId = 0;

            function nudgeApplyButton ()
            {
                if (!applyFiltersButtonEl)
                {
                    return;
                }

                applyFiltersButtonEl.classList.remove('is-attention-bounce');

                // Force reflow so repeated nudges retrigger the animation.
                void applyFiltersButtonEl.offsetWidth;

                applyFiltersButtonEl.classList.add('is-attention-bounce');
            }

            if (applyFiltersButtonEl)
            {
                applyFiltersButtonEl.addEventListener('animationend', function ()
                {
                    applyFiltersButtonEl.classList.remove('is-attention-bounce');
                });
            }

            if (pickDayButtonEl && dayPickerInputEl && dateFromEl && dateToEl)
            {
                pickDayButtonEl.addEventListener('click', function ()
                {
                    const currentValue = (dateFromEl.value || dateToEl.value || '').trim();
                    if (currentValue !== '')
                    {
                        dayPickerInputEl.value = currentValue;
                    }

                    if (typeof dayPickerInputEl.showPicker === 'function')
                    {
                        dayPickerInputEl.showPicker();
                        return;
                    }

                    dayPickerInputEl.click();
                });

                dayPickerInputEl.addEventListener('change', function ()
                {
                    const pickedDate = (dayPickerInputEl.value || '').trim();
                    if (pickedDate === '')
                    {
                        return;
                    }

                    dateFromEl.value = pickedDate;
                    dateToEl.value = pickedDate;

                    if (pickDayButtonEl.form)
                    {
                        showLoader();
                        submitFormSafely(pickDayButtonEl.form);
                    }
                });
            }

            if (dateFromEl)
            {
                dateFromEl.addEventListener('change', function ()
                {
                    nudgeApplyButton();
                });
            }

            if (dateToEl)
            {
                dateToEl.addEventListener('change', function ()
                {
                    nudgeApplyButton();
                });
            }

            if (searchInputEl)
            {
                searchInputEl.addEventListener('input', function ()
                {
                    if (searchApplyHintTimeoutId)
                    {
                        window.clearTimeout(searchApplyHintTimeoutId);
                    }

                    searchApplyHintTimeoutId = window.setTimeout(function ()
                    {
                        if ((searchInputEl.value || '').trim() !== '')
                        {
                            nudgeApplyButton();
                        }
                    }, 1000);
                });
            }

            function closeStatusModal ()
            {
                if (!statusModalEl)
                {
                    return;
                }
                statusModalEl.hidden = true;
            }

            function openStatusModal ()
            {
                if (!statusModalEl)
                {
                    return;
                }
                statusModalEl.hidden = false;
            }

            if (openStatusFilterEl)
            {
                openStatusFilterEl.addEventListener('click', function ()
                {
                    openStatusModal();
                });
            }

            statusCloseEls.forEach(function (closeEl)
            {
                closeEl.addEventListener('click', function ()
                {
                    closeStatusModal();
                });
            });

            document.addEventListener('keydown', function (event)
            {
                if (event.key === 'Escape' && statusModalEl && !statusModalEl.hidden)
                {
                    closeStatusModal();
                }
            });

            if (saveStatusFilterEl)
            {
                saveStatusFilterEl.addEventListener('click', function ()
                {
                    const payload = {};
                    const webfleetPayload = {};
                    statusFilterCheckboxEls.forEach(function (checkboxEl)
                    {
                        const status = (checkboxEl.getAttribute('data-status') || '').trim();
                        if (status === '')
                        {
                            return;
                        }
                        payload[status] = checkboxEl.checked;
                    });

                    webfleetFilterCheckboxEls.forEach(function (checkboxEl)
                    {
                        const status = (checkboxEl.getAttribute('data-webfleet-status') || '').trim();
                        if (status === '')
                        {
                            return;
                        }
                        webfleetPayload[status] = checkboxEl.checked;
                    });

                    if (statusFiltersInputEl)
                    {
                        statusFiltersInputEl.value = JSON.stringify(payload);
                    }

                    if (webfleetStatusFiltersInputEl)
                    {
                        webfleetStatusFiltersInputEl.value = JSON.stringify(webfleetPayload);
                    }

                    closeStatusModal();

                    if (openStatusFilterEl && openStatusFilterEl.form)
                    {
                        showLoader();
                        submitFormSafely(openStatusFilterEl.form);
                    }
                });
            }

            if (personSelectEl)
            {
                const countsUrl = (personSelectEl.getAttribute('data-counts-url') || '').trim();
                const ownResourceNo = (personSelectEl.getAttribute('data-own-resource') || '').trim();
                const personOptions = Array.from(personSelectEl.options || []).filter(function (optionEl)
                {
                    return (optionEl.value || '').trim() !== '';
                });

                if (countsUrl !== '' && personOptions.length > 0)
                {
                    const endpoint = new URL(countsUrl, window.location.href);
                    personOptions.forEach(function (optionEl)
                    {
                        endpoint.searchParams.append('resource_nos[]', (optionEl.value || '').trim());
                    });

                    fetch(endpoint.toString(), {
                        credentials: 'same-origin',
                        headers: {
                            'Accept': 'application/json'
                        }
                    })
                        .then(function (response)
                        {
                            if (!response.ok)
                            {
                                throw new Error('Counts request failed');
                            }
                            return response.json();
                        })
                        .then(function (payload)
                        {
                            const counts = payload && typeof payload === 'object' && payload.counts && typeof payload.counts === 'object'
                                ? payload.counts
                                : {};

                            personOptions.forEach(function (optionEl)
                            {
                                const resourceNo = (optionEl.value || '').trim();
                                if (resourceNo === '')
                                {
                                    return;
                                }

                                const baseName = (optionEl.getAttribute('data-name') || optionEl.textContent || '').trim();
                                const hasCount = Object.prototype.hasOwnProperty.call(counts, resourceNo);
                                const rawCount = counts[resourceNo];
                                const openCount = Number.isFinite(Number(rawCount)) ? Number(rawCount) : 0;
                                const keepOption = !hasCount || openCount > 0 || resourceNo === ownResourceNo || optionEl.selected;

                                if (!keepOption)
                                {
                                    optionEl.remove();
                                    return;
                                }

                                if (hasCount)
                                {
                                    optionEl.textContent = baseName + ' (' + openCount + ' werkorders)';
                                }
                            });

                            if (personSelectEl.options.length === 0)
                            {
                                const emptyOption = document.createElement('option');
                                emptyOption.value = '';
                                emptyOption.textContent = 'Geen servicemonteurs gevonden';
                                personSelectEl.appendChild(emptyOption);
                            }

                            const hasSelected = Array.from(personSelectEl.options).some(function (optionEl)
                            {
                                return optionEl.selected;
                            });
                            if (!hasSelected && personSelectEl.options.length > 0)
                            {
                                personSelectEl.selectedIndex = 0;
                            }
                        })
                        .catch(function ()
                        {
                        });
                }
            }
        })();
    </script>
    <script>
        if ('serviceWorker' in navigator)
        {
            window.addEventListener('load', function ()
            {
                navigator.serviceWorker.register('sw.js').catch(function ()
                {
                });
            });
        }
    </script>
</body>

</html>