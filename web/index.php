<?php
require_once __DIR__ . '/functions.php';
require __DIR__ . "/auth.php";
require_once __DIR__ . "/logincheck.php";
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

$odataTtl = [
    'resource_by_email' => 600_00,
    'usersetup_by_email' => 3600_00,
    'resource_by_userid' => 3600_00,
    'service_resources' => 3600_00,
    'workorders_counts' => 300_00,
    'werkorders_material_flags' => 300_00,
    'workorders_list' => 600_00,
    'workorder_detail' => 300_00,
    'planning_lines' => 300_00,
];

$userEmail = strtolower(trim((string) ($_SESSION['user']['email'] ?? '')));
$selectedWorkOrderNo = trim((string) ($_GET['workorder'] ?? ''));
$selectedPersonNoRequest = trim((string) ($_GET['person'] ?? ''));
$searchQuery = trim((string) ($_GET['q'] ?? ''));
$dateFromRequest = trim((string) ($_GET['date_from'] ?? ''));
$dateToRequest = trim((string) ($_GET['date_to'] ?? ''));

function user_pref_path(): string
{
    return __DIR__ . '/cache/user-company-preferences.json';
}

function read_user_company_preferences(): array
{
    $path = user_pref_path();
    if (!is_file($path)) {
        return [];
    }

    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function write_user_company_preferences(array $data): void
{
    $path = user_pref_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }

    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if (is_string($json)) {
        @file_put_contents($path, $json, LOCK_EX);
    }
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

function fetch_app_resources_by_email(string $environment, string $company, string $email, array $auth): array
{
    if ($email === '') {
        return [];
    }

    $url = odata_company_url($environment, $company, 'AppResource', [
        '$select' => 'No,Name,E_Mail,KVT_User_ID',
        '$filter' => "E_Mail eq '" . odata_quote_string($email) . "'",
    ]);

    return odata_get_all($url, $auth, odata_ttl('resource_by_email'));
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
    if ($userId === '') {
        return [];
    }

    $url = odata_company_url($environment, $company, 'AppResource', [
        '$select' => 'No,Name,E_Mail,KVT_User_ID',
        '$filter' => "KVT_User_ID eq '" . odata_quote_string($userId) . "'",
    ]);

    return odata_get_all($url, $auth, odata_ttl('resource_by_userid'));
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
        if ($type !== '' && $type !== 'person') {
            continue;
        }

        $result[] = [
            'No' => $no,
            'Name' => $name,
            'E_Mail' => trim((string) ($row['E_Mail'] ?? '')),
        ];
    }

    return $result;
}

function unique_resource_nos(array $resources): array
{
    $nos = [];
    foreach ($resources as $resource) {
        $no = trim((string) ($resource['No'] ?? ''));
        if ($no !== '') {
            $nos[$no] = true;
        }
    }

    return array_keys($nos);
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

function material_line_status(array $line): array
{
    $statusMaterial = safe_text((string) ($line['KVT_Status_Material'] ?? ''), 'Onbekend');
    $binCode = trim((string) ($line['Bin_Code'] ?? ''));
    $completelyPicked = is_true_value($line['KVT_Completely_Picked'] ?? false);
    $qtyPicked = (float) ($line['KVT_Qty_Picked'] ?? 0);
    $purchaseOrderNo = trim((string) ($line['LVS_Purchase_Order_No'] ?? ''));
    $expectedReceiptDate = trim((string) ($line['KVT_Expected_Receipt_Date'] ?? ''));
    $outstandingQty = (float) ($line['LVS_Outstanding_Qty_Base'] ?? 0);

    if ($completelyPicked || ($binCode !== '' && $qtyPicked > 0)) {
        return ['label' => "In bin $binCode", 'class' => 'ok', 'detail' => $binCode !== '' ? 'Bin: ' . $binCode : $statusMaterial];
    }

    if ($purchaseOrderNo !== '' || $expectedReceiptDate !== '' || $outstandingQty > 0) {
        $detailParts = [];
        if ($purchaseOrderNo !== '') {
            $detailParts[] = 'PO: ' . $purchaseOrderNo;
        }
        if ($expectedReceiptDate !== '') {
            $detailParts[] = 'Verwacht: ' . nl_date($expectedReceiptDate);
        }
        if (empty($detailParts)) {
            $detailParts[] = $statusMaterial;
        }

        return ['label' => 'In bestelling', 'class' => 'warn', 'detail' => implode(' · ', $detailParts)];
    }

    return ['label' => $statusMaterial, 'class' => 'neutral', 'detail' => $binCode !== '' ? 'Bin: ' . $binCode : '-'];
}

function is_open_workorder_status(string $status): bool
{
    $normalized = strtolower(trim($status));
    if ($normalized === '') {
        return true;
    }

    $closedTerms = ['closed', 'afgerond', 'gereed', 'voltooid', 'cancelled', 'geannuleerd', 'finished'];
    foreach ($closedTerms as $term) {
        if (strpos($normalized, $term) !== false) {
            return false;
        }
    }

    return true;
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

function fetch_werkorder_no_material_needed_by_no(string $environment, string $company, string $workOrderNo, array $auth): ?bool
{
    $workOrderNo = trim($workOrderNo);
    if ($workOrderNo === '') {
        return null;
    }

    $url = odata_company_url($environment, $company, 'Werkorders', [
        '$select' => 'No,KVT_No_Material_Needed',
        '$filter' => "No eq '" . odata_quote_string($workOrderNo) . "'",
    ]);

    $rows = odata_get_all($url, $auth, odata_ttl('workorder_detail'));
    if (count($rows) === 0) {
        return null;
    }

    return is_true_value($rows[0]['KVT_No_Material_Needed'] ?? false);
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
            '$select' => 'No,Task_Code,Task_Description,Status,Resource_No,Resource_Name,Main_Entity_Description,Sub_Entity_Description,Component_Description,Serial_No,Start_Date,End_Date,External_Document_No,KVT_Lowest_Present_Status_Mat,KVT_Status_Purchase_Order,Job_No,Job_Task_No',
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
$workOrderNoMaterialNeededMap = [];
$workOrders = [];
$selectedWorkOrder = null;
$selectedWorkOrderNoMaterialNeeded = null;
$planningLines = [];
$today = new DateTimeImmutable('today');
$defaultRangeStart = $today->modify('-7 days');
$defaultRangeEnd = $today->modify('+28 days');
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
            $serviceResources[] = [
                'No' => trim((string) ($resource['No'] ?? '')),
                'Name' => trim((string) ($resource['Name'] ?? '')),
                'E_Mail' => trim((string) ($resource['E_Mail'] ?? '')),
            ];
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

    $openWorkOrderCounts = fetch_workorder_open_counts(
        $environment,
        $company,
        array_keys($serviceResourceMap),
        $auth
    );

    if ($selectedPersonNoRequest !== '' && isset($serviceResourceMap[$selectedPersonNoRequest])) {
        $selectedResourceNo = $selectedPersonNoRequest;
    } elseif ($ownResourceNo !== '' && isset($serviceResourceMap[$ownResourceNo])) {
        $selectedResourceNo = $ownResourceNo;
    } elseif ($ownResourceNo !== '') {
        $selectedResourceNo = $ownResourceNo;
    } elseif (!empty($serviceResources)) {
        $selectedResourceNo = (string) ($serviceResources[0]['No'] ?? '');
    }

    if ($selectedResourceNo !== '') {
        if (isset($serviceResourceMap[$selectedResourceNo])) {
            $selectedResourceName = safe_text((string) ($serviceResourceMap[$selectedResourceNo]['Name'] ?? ''));
        } else {
            foreach ($resourcesForUser as $resource) {
                if (trim((string) ($resource['No'] ?? '')) === $selectedResourceNo) {
                    $selectedResourceName = safe_text((string) ($resource['Name'] ?? ''));
                    break;
                }
            }
        }
    }

    if ($selectedResourceNo !== '') {
        $workOrderNoMaterialNeededMap = fetch_werkorders_no_material_needed_map(
            $environment,
            $company,
            $selectedResourceNo,
            $auth
        );

        $workOrders = fetch_app_workorders_chunked(
            $environment,
            $company,
            $selectedResourceNo,
            $rangeStart,
            $rangeEnd,
            $auth
        );

        if ($searchQuery !== '') {
            $workOrders = array_values(array_filter(
                $workOrders,
                static fn(array $workOrder): bool => workorder_matches_query($workOrder, $searchQuery)
            ));
        }

        usort($workOrders, static function (array $left, array $right): int {
            return strcmp(workorder_sort_key($left), workorder_sort_key($right));
        });
    }

    if ($selectedWorkOrderNo !== '') {
        if (isset($workOrderNoMaterialNeededMap[$selectedWorkOrderNo])) {
            $selectedWorkOrderNoMaterialNeeded = (bool) $workOrderNoMaterialNeededMap[$selectedWorkOrderNo];
        } else {
            $selectedWorkOrderNoMaterialNeeded = fetch_werkorder_no_material_needed_by_no(
                $environment,
                $company,
                $selectedWorkOrderNo,
                $auth
            );
        }

        $selectedUrl = odata_company_url($environment, $company, 'AppWerkorders', [
            '$select' => 'No,Task_Code,Task_Description,Status,Resource_No,Resource_Name,Main_Entity_Description,Sub_Entity_Description,Component_Description,Serial_No,Start_Date,End_Date,External_Document_No,KVT_Lowest_Present_Status_Mat,KVT_Status_Purchase_Order,Job_No,Job_Task_No,KVT_Memo_Service_Location,KVT_Memo_Component',
            '$filter' => "No eq '" . odata_quote_string($selectedWorkOrderNo) . "'",
        ]);
        $selectedRows = odata_get_all($selectedUrl, $auth, odata_ttl('workorder_detail'));
        $selectedWorkOrder = $selectedRows[0] ?? null;

        if ($selectedWorkOrder !== null) {
            $linesUrl = odata_company_url($environment, $company, 'LVS_JobPlanningLinesSub', [
                '$select' => 'Line_No,Type,No,Description,KVT_Extended_Text,Quantity,Unit_of_Measure_Code,KVT_Status_Material,Bin_Code,KVT_Completely_Picked,KVT_Qty_Picked,KVT_Expected_Receipt_Date,LVS_Purchase_Order_No,LVS_Outstanding_Qty_Base,Planning_Date,LVS_Vendor_Name,LVS_Supply_from',
                '$filter' => "LVS_Work_Order_No eq '" . odata_quote_string($selectedWorkOrderNo) . "'",
                '$orderby' => 'Line_No asc',
            ]);
            $planningLinesAll = odata_get_all($linesUrl, $auth, odata_ttl('planning_lines'));
            $planningLines = array_values(array_filter($planningLinesAll, static function (array $line): bool {
                $type = strtolower(trim((string) ($line['Type'] ?? '')));
                return $type === 'item' || $type === 'artikel';
            }));
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
$listHref = 'index.php' . (!empty($listQuery) ? ('?' . http_build_query($listQuery, '', '&', PHP_QUERY_RFC3986)) : '');

?>

<!doctype html>
<html lang="nl">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
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

        .wo-list,
        .line-list {
            display: grid;
            gap: 10px;
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

        .row {
            display: flex;
            justify-content: space-between;
            align-items: start;
            gap: 10px;
        }

        .wo-no {
            font-size: .85rem;
            color: var(--muted);
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
        }

        .range-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
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

        <form class="toolbar" method="get" action="index.php" data-nav-form>
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
                <select id="person" name="person" onchange="this.form.submit()">
                    <?php if (count($serviceResources) === 0): ?>
                        <option value="">Geen servicemonteurs gevonden</option>
                    <?php else: ?>
                        <?php foreach ($serviceResources as $serviceResource): ?>
                            <?php $resourceNo = trim((string) ($serviceResource['No'] ?? '')); ?>
                            <option value="<?= htmlspecialchars($resourceNo) ?>" <?= $resourceNo === $selectedResourceNo ? 'selected' : '' ?>>
                                <?php
                                $openCount = (int) ($openWorkOrderCounts[$resourceNo] ?? 0);
                                $optionLabel = safe_text((string) ($serviceResource['Name'] ?? '')) . ' (' . $openCount . ' open werkorders)';
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
            </div>
            <div class="field">
                <label for="q">Zoeken op werkorder of omschrijving</label>
                <input id="q" name="q" type="search" value="<?= htmlspecialchars($searchQuery) ?>"
                    placeholder="Bijv. WO-123 of onderhoud" />
            </div>
            <div class="actions">
                <button type="submit">Toepassen</button>
            </div>
        </form>

        <?php if ($errorMessage !== ''): ?>
            <div class="alert">Fout bij laden van Business Central data: <?= htmlspecialchars($errorMessage) ?></div>
        <?php endif; ?>

        <?php if ($selectedWorkOrder !== null): ?>
            <section class="detail-head">
                <a href="<?= htmlspecialchars($listHref) ?>" class="back" data-nav-link>← Terug naar werkorders</a>
                <p class="wo-no">Werkorder <?= htmlspecialchars(safe_text((string) ($selectedWorkOrder['No'] ?? ''))) ?></p>
                <h1 class="title">
                    <?= htmlspecialchars(safe_text((string) ($selectedWorkOrder['Task_Description'] ?? ''))) ?>
                </h1>
                <p class="subtitle">
                    Uitvoerdatum: <?= htmlspecialchars(nl_date((string) ($selectedWorkOrder['Start_Date'] ?? ''))) ?>
                    · Monteur: <?= htmlspecialchars(safe_text((string) ($selectedWorkOrder['Resource_Name'] ?? ''))) ?>
                </p>
                <div class="meta">
                    Object:
                    <?= htmlspecialchars(safe_text((string) ($selectedWorkOrder['Main_Entity_Description'] ?? ''))) ?><br />
                    Component:
                    <?= htmlspecialchars(safe_text((string) ($selectedWorkOrder['Component_Description'] ?? ''))) ?><br />
                    Materiaal nodig:
                    <?= htmlspecialchars($selectedWorkOrderNoMaterialNeeded === true ? 'Nee' : 'Ja') ?><br />
                    Materiaal (header):
                    <?= htmlspecialchars(safe_text((string) ($selectedWorkOrder['KVT_Lowest_Present_Status_Mat'] ?? ''))) ?>
                </div>
            </section>

            <h2 class="title">Details:</h2>

            <?php if (count($planningLines) === 0): ?>
                <div class="card empty">Geen details gevonden voor deze werkorder.</div>
            <?php else: ?>
                <section class="line-list">
                    <?php foreach ($planningLines as $line): ?>
                        <?php $lineStatus = material_line_status($line); ?>
                        <article class="card">
                            <div class="row">
                                <p class="line-name"><?= htmlspecialchars(safe_text((string) ($line['Description'] ?? ''))) ?></p>
                                <span
                                    class="badge <?= htmlspecialchars($lineStatus['class']) ?>"><?= htmlspecialchars($lineStatus['label']) ?></span>
                            </div>
                            <div class="meta">
                                Regel <?= htmlspecialchars((string) ($line['Line_No'] ?? '-')) ?>
                                · Aantal <?= htmlspecialchars((string) ($line['Quantity'] ?? '0')) ?>
                                <?= htmlspecialchars(safe_text((string) ($line['Unit_of_Measure_Code'] ?? ''), '')) ?>
                            </div>
                            <div class="line-desc">
                                <?= htmlspecialchars(safe_text((string) ($line['KVT_Extended_Text'] ?? ''), '-')) ?>
                            </div>
                            <div class="status-detail"><?= htmlspecialchars($lineStatus['detail']) ?></div>
                        </article>
                    <?php endforeach; ?>
                </section>
            <?php endif; ?>

        <?php else: ?>
            <h1 class="title">Mijn werkorders</h1>
            <p class="subtitle">
                <?= htmlspecialchars($selectedResourceName !== '' ? $selectedResourceName : $userEmail) ?> · gesorteerd op
                uitvoerdatum (oud → nieuw) · periode <?= htmlspecialchars($rangeStart->format('d-m-Y')) ?> t/m
                <?= htmlspecialchars($rangeEnd->format('d-m-Y')) ?>
            </p>

            <?php if ($selectedResourceNo === ''): ?>
                <div class="card empty">Geen servicemonteur geselecteerd.</div>
            <?php elseif (count($workOrders) === 0): ?>
                <div class="card empty">Geen werkorders gevonden.</div>
            <?php else: ?>
                <section class="wo-list">
                    <?php foreach ($workOrders as $workOrder): ?>
                        <?php
                        $workOrderHrefParams = $listQuery;
                        $workOrderHrefParams['workorder'] = (string) ($workOrder['No'] ?? '');
                        $workOrderHref = 'index.php?' . http_build_query($workOrderHrefParams, '', '&', PHP_QUERY_RFC3986);
                        $workOrderNo = (string) ($workOrder['No'] ?? '');
                        $noMaterialNeeded = (bool) ($workOrderNoMaterialNeededMap[$workOrderNo] ?? false);
                        ?>
                        <a class="card" href="<?= htmlspecialchars($workOrderHref) ?>" data-nav-link>
                            <div class="row">
                                <div>
                                    <p class="wo-no">Werkorder <?= htmlspecialchars(safe_text((string) ($workOrder['No'] ?? ''))) ?>
                                    </p>
                                    <h2 class="wo-task">
                                        <?= htmlspecialchars(safe_text((string) ($workOrder['Task_Description'] ?? ''))) ?>
                                    </h2>
                                </div>
                                <span
                                    class="badge neutral"><?= htmlspecialchars(safe_text((string) ($workOrder['Status'] ?? ''))) ?></span>
                            </div>
                            <div class="meta">
                                Uitvoerdatum: <?= htmlspecialchars(nl_date((string) ($workOrder['Start_Date'] ?? ''))) ?><br />
                                Object:
                                <?= htmlspecialchars(safe_text((string) ($workOrder['Main_Entity_Description'] ?? ''))) ?><br />
                                Component:
                                <?= htmlspecialchars(safe_text((string) ($workOrder['Component_Description'] ?? ''))) ?><br />
                                Materiaal nodig: <?= htmlspecialchars($noMaterialNeeded ? 'Nee' : 'Ja') ?><br />
                                Materiaal:
                                <?= htmlspecialchars(safe_text((string) ($workOrder['KVT_Lowest_Present_Status_Mat'] ?? ''))) ?>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </section>
            <?php endif; ?>
        <?php endif; ?>

        <?= injectTimerHtml([
            'statusUrl' => 'odata.php?action=cache_status',
            'deleteUrl' => 'odata.php?action=cache_delete',
            'clearUrl' => 'odata.php?action=cache_clear',
            'label' => 'Cache',
            'title' => 'OData cache',
            'css' => '{{root}} .odata-cache-widget{position:fixed;right:10px;bottom:12px;top:auto;z-index:1200;background:#ffffffed;backdrop-filter:blur(4px);}{{root}} .odata-cache-popout{position:fixed;left:8px;right:8px;top:66px;max-height:calc(100vh - 76px);width:auto;}',
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
            let progressRunning = false;
            let fillTimeoutId = null;
            let tipFillTimeoutId = null;
            let burstTimeoutId = null;
            let shakeFrameId = null;
            let shakeStartAt = 0;

            function showLoader ()
            {
                loaderEl.classList.add('visible');
                startProgressSequence();
            }

            function hideLoader ()
            {
                loaderEl.classList.remove('visible');
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
                showLoader();
            });

            document.addEventListener('visibilitychange', function ()
            {
                if (document.visibilityState === 'hidden')
                {
                    return;
                }

                if (loaderEl.classList.contains('visible') && !progressRunning)
                {
                    startProgressSequence();
                }
            });

            if (loaderTextEl && Array.isArray(loadingTexts) && loadingTexts.length > 0)
            {
                window.setInterval(rotateLoadingText, 5000);
            }
        })();
    </script>
</body>

</html>