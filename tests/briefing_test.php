<?php
/**
 * Tests voor het daily-briefing-endpoint, de xlsm-extractie, het PII-filter, has_notes,
 * Markdown → HTML en de aandachtspunten in de dagmail. BC en Microsoft Graph zijn gemockt;
 * er wordt niets naar buiten verstuurd.
 *
 * Run: php tests/briefing_test.php
 *
 * De test kopieert web/ naar een tijdelijke map met een nep-auth.php, zodat web/auth.php en
 * web/cache van de ontwikkelaar niet geraakt worden.
 */

/**
 * Includes/requires
 */
require __DIR__ . '/fixtures/xlsx_fixture.php';

/**
 * Variabelen
 */
$repoRoot = dirname(__DIR__);
$tmpRoot = sys_get_temp_dir() . '/daedalus-briefing-test-' . bin2hex(random_bytes(4));
$tmpWeb = $tmpRoot . '/web';
$fixtureDir = $tmpRoot . '/fixtures';
$assertions = 0;
ini_set('log_errors', '1');
ini_set('error_log', $tmpRoot . '.log');

/**
 * Functies
 */
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

function copy_tree(string $from, string $to): void
{
    @mkdir($to, 0777, true);
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($iterator as $item) {
        $relative = substr($item->getPathname(), strlen($from) + 1);
        if (preg_match('#^(cache|auth\.php$|analytics/.*\.sqlite)#', str_replace('\\', '/', $relative)) === 1) {
            continue;
        }
        $target = $to . '/' . $relative;
        if ($item->isDir()) {
            @mkdir($target, 0777, true);
        } else {
            copy($item->getPathname(), $target);
        }
    }
}

function remove_tree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($dir);
}

function clear_briefing_cache(): void
{
    global $tmpWeb;
    remove_tree($tmpWeb . '/cache/briefing');
    $memory = &briefing_index_memory();
    $memory = [];
    $token = &graph_memory_token();
    $token = [];
}

function call_endpoint(array $query, ?string $key = 'test-key-0123456789abcdef', string $method = 'GET'): array
{
    $server = ['REQUEST_METHOD' => $method];
    if ($key !== null) {
        $server['HTTP_X_API_KEY'] = $key;
    }
    $memory = &briefing_index_memory();
    $memory = [];

    return daily_briefing_handle($server, $query);
}

function history_json(array $body): string
{
    $parts = [];
    foreach ($body['resources'] ?? [] as $resource) {
        foreach ($resource['workorders'] as $workOrder) {
            $parts[] = $workOrder['history'];
        }
    }

    return (string) json_encode($parts, JSON_UNESCAPED_UNICODE);
}

function find_workorder(array $body, string $no): ?array
{
    foreach ($body['resources'] ?? [] as $resource) {
        foreach ($resource['workorders'] as $workOrder) {
            if ($workOrder['no'] === $no) {
                return $workOrder;
            }
        }
    }

    return null;
}

/**
 * Page load
 */
register_shutdown_function(static function () use ($tmpRoot): void {
    remove_tree($tmpRoot);
    @unlink($tmpRoot . '.log');
});

copy_tree($repoRoot . '/web', $tmpWeb);
@mkdir($tmpWeb . '/cache/users', 0777, true);
@mkdir($fixtureDir, 0777, true);
file_put_contents($tmpWeb . '/auth.php', <<<'PHP'
<?php
$auth_list = ['Production' => ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret']];
$environment = 'Production';
$auth = $auth_list[$environment];
$baseUrl = 'https://bc.invalid:7148/';
$reportMail = ['subject_prefix' => 'Daedalus', 'smtp' => ['host' => '']];
$briefingApiKeys = ['test-key-0123456789abcdef', 'tweede-sleutel-abcdefghijkl'];
// Zelfde vorm als Clio's auth.php; site_id/drive_id/list_id zijn van Clio's transcript-site en moeten genegeerd worden.
$sharepointSettings = [
    'site_id' => 'clio.example,clio-transcript-site',
    'drive_id' => 'clio-transcript-drive',
    'list_id' => 'clio-list',
    'upload_folder' => '',
    'status_field' => 'Transcript',
    'access_token' => '',
    'tenant_id' => 'tenant-test',
    'client_id' => 'client-test',
    'client_secret' => 'secret-test-should-not-leak',
    'token_scope' => 'https://graph.microsoft.com/.default',
    'token_url' => '',
    'verify_ssl' => true,
    'ca_bundle' => '',
];
$briefingSiteHostname = 'kvtnl.sharepoint.com';
$briefingSitePath = '/sites/KVTAlgemeen';
$briefingNotesFolder = 'General/Daedalus/Aandachtspunten';
$briefingPdftotextPath = '/bestaat/niet/pdftotext';
PHP);

file_put_contents($tmpWeb . '/cache/users/henk@example.com.json', json_encode([
    'filters' => [],
    'notification_settings' => [
        'enabled' => true, 'daily_overview_enabled' => true, 'company' => 'Koninklijke van Twist',
        'resource_no' => '1001', 'resource_name' => 'Testmonteur, Henk',
    ],
]));
file_put_contents($tmpWeb . '/cache/users/uit@example.com.json', json_encode([
    'notification_settings' => ['enabled' => true, 'daily_overview_enabled' => false, 'company' => 'Koninklijke van Twist', 'resource_no' => '1002'],
]));

// --- Fixtures (fictief) ---
$equipmentFolder = 'General/Equipments/10000001 - Testaggregaat Fictief';
fixture_build_index($fixtureDir . '/index.xlsx', [
    ['10000001', 'Testaggregaat Fictief', 'WO2600001', '2026-09-01', 'Testmonteur, Henk 1001'],
    ['10000001', 'Testaggregaat Fictief', 'WO2600002', '2026-08-01', 'Collega, Kees 1002'],
    ['10000001', 'Testaggregaat Fictief', 'WO2600003', '2025-06-01', 'Oud, Piet 1003'],
    ['10000001', 'Testaggregaat Fictief', '4012345', '2025-01-15', 'Oud, Piet 1003'],
    ['10000001', 'Testaggregaat Fictief', 'WO2610007', '2026-10-07', 'Testmonteur, Henk 1001'],
    ['10000001', 'Testaggregaat Fictief', 'WO2610010', '2026-10-08', 'Testmonteur, Henk 1001'],
    ['10000003', 'Ander Object', 'WO2500001', '2025-03-03', 'Oud, Piet 1003'],
]);
fixture_build_service_report($fixtureDir . '/x1.xlsm', 'Accu zwak, advies vervangen. Gesproken met dhr. Pietersen (06-11122233).', ['rich' => ['Alarm ', 'lage koelvloeistof']]);
fixture_build_service_report($fixtureDir . '/x2.xlsm', '-', ['inline' => 'geen']);
fixture_build_service_report($fixtureDir . '/x2dup.xlsm', 'DUPLICAAT mag niet gekozen worden', 'DUPLICAAT');
fixture_build_service_report($fixtureDir . '/x3.xlsm', "Oud, Piet heeft brandstoffilter vervangen.\nTel: 0612345678", '');

$files = [
    'idx' => file_get_contents($fixtureDir . '/index.xlsx'),
    'x1' => file_get_contents($fixtureDir . '/x1.xlsm'),
    'x2' => file_get_contents($fixtureDir . '/x2.xlsm'),
    'x2dup' => file_get_contents($fixtureDir . '/x2dup.xlsm'),
    'x3' => file_get_contents($fixtureDir . '/x3.xlsm'),
    'md1' => "# Let op\n- **Accu** vervangen <b>direct</b>\n- [rapport](https://kvtnl.sharepoint.com/x) en [kwaad](javascript:alert(1))\n<script>alert('x')</script>",
];

// --- Mocks ---
$bcCalls = [];
$GLOBALS['DAEDALUS_ODATA_BC_FETCH'] = static function (string $url, array $auth, int $ttl) use (&$bcCalls): array {
    $query = [];
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    preg_match("#Company\\('([^']*)'\\)/([^/?]+)#", rawurldecode((string) parse_url($url, PHP_URL_PATH)), $m);
    $entity = $m[2] ?? '';
    $filter = (string) ($query['$filter'] ?? '');
    $select = (string) ($query['$select'] ?? '');
    $bcCalls[] = $entity . ' ' . $filter;
    if (!empty($GLOBALS['TEST_BC_DOWN'])) {
        throw new RuntimeException('HTTP 503 bij https://bc.invalid:7148/geheim?$filter=x');
    }
    if ($entity === 'Werkorders') {
        fail('De kaartpagina Werkorders mag niet gelezen worden.');
    }

    $people = [
        ['No' => '1001', 'Name' => 'Testmonteur, Henk', 'E_Mail' => 'henk@example.com', 'Type' => 'Persoon', 'Blocked' => false],
        ['No' => '1002', 'Name' => 'Collega, Kees', 'E_Mail' => 'Kees@Example.com', 'Type' => 'Person', 'Blocked' => false],
        ['No' => 'V01', 'Name' => 'Servicebus 1', 'E_Mail' => '', 'Type' => 'Machine', 'Blocked' => false],
    ];
    $workOrders = [
        ['No' => 'WO2610001', 'Task_Code' => 'PD', 'Task_Description' => 'Proefdraaien', 'Status' => 'Gepland', 'Main_Entity_Description' => 'Fictieve locatie', 'Component_No' => '10000001', 'Component_Description' => 'Testaggregaat Fictief', 'Start_Date' => '2026-10-07', 'Start_Time' => '10:00:00', 'End_Time' => '12:00:00', 'Resource_No' => '1001'],
        ['No' => 'WO2610002', 'Task_Code' => 'BA', 'Task_Description' => '', 'Status' => 'In Progress', 'Main_Entity_Description' => 'Fictieve locatie', 'Component_No' => '10000001', 'Component_Description' => 'Testaggregaat Fictief', 'Start_Date' => '2026-10-07', 'Start_Time' => '08:00:00', 'End_Time' => '09:00:00', 'Resource_No' => '1001'],
        ['No' => 'WO2610003', 'Task_Code' => 'PD', 'Task_Description' => 'Proefdraaien', 'Status' => 'Geannuleerd', 'Main_Entity_Description' => 'x', 'Component_No' => '10000001', 'Component_Description' => 'x', 'Start_Date' => '2026-10-07', 'Start_Time' => '13:00:00', 'End_Time' => '14:00:00', 'Resource_No' => '1001'],
        ['No' => 'WO2610004', 'Task_Code' => 'CO', 'Task_Description' => 'Correctief', 'Status' => 'Open', 'Main_Entity_Description' => 'Zonder object', 'Component_No' => '', 'Component_Description' => '', 'Start_Date' => '2026-10-07', 'Start_Time' => '14:00:00', 'End_Time' => '15:00:00', 'Resource_No' => '1001'],
        ['No' => 'WO2610005', 'Task_Code' => 'ST', 'Task_Description' => 'Storing', 'Status' => 'Open', 'Main_Entity_Description' => 'Nieuw object', 'Component_No' => '10000002', 'Component_Description' => 'Nieuw', 'Start_Date' => '2026-10-07', 'Start_Time' => '15:00:00', 'End_Time' => '16:00:00', 'Resource_No' => '1001'],
        ['No' => 'WO2610006', 'Task_Code' => 'PD', 'Task_Description' => 'Proefdraaien', 'Status' => 'Planned', 'Main_Entity_Description' => 'Ander', 'Component_No' => '10000003', 'Component_Description' => 'Ander Object', 'Start_Date' => '2026-10-07', 'Start_Time' => '09:00:00', 'End_Time' => '10:00:00', 'Resource_No' => '1002'],
        ['No' => 'WO2610008', 'Task_Code' => 'TR', 'Task_Description' => 'Transport', 'Status' => 'Planned', 'Main_Entity_Description' => 'x', 'Component_No' => '', 'Component_Description' => '', 'Start_Date' => '2026-10-07', 'Start_Time' => '09:00:00', 'End_Time' => '10:00:00', 'Resource_No' => 'V01'],
    ];

    if ($entity === 'AppResource') {
        if (preg_match("/^No eq '([^']*)'$/", $filter, $mm) === 1) {
            return array_values(array_filter($people, static fn(array $p): bool => $p['No'] === $mm[1]));
        }
        if (preg_match("/^E_Mail eq '([^']*)'$/", $filter, $mm) === 1) {
            return array_values(array_map(static fn(array $p): array => ['No' => $p['No']], array_filter($people, static fn(array $p): bool => strtolower($p['E_Mail']) === strtolower($mm[1]))));
        }
        if ($filter === 'Blocked eq false') {
            return $people;
        }
        return [];
    }
    if ($entity === 'AppUserSetup') {
        return [];
    }
    if ($entity === 'AppWerkorders') {
        if (strpos($select, 'Component_No') === false) {
            fail('AppWerkorders-query voor de briefing mist Component_No: ' . $select);
        }
        if (preg_match("/^Resource_No eq '([^']*)' and Start_Date ge (\\S+) and Start_Date le (\\S+)$/", $filter, $mm) === 1) {
            return array_values(array_filter($workOrders, static fn(array $w): bool => $w['Resource_No'] === $mm[1] && $w['Start_Date'] === $mm[2]));
        }
        if (preg_match('/^Start_Date ge (\S+) and Start_Date le (\S+)$/', $filter, $mm) === 1) {
            return array_values(array_filter($workOrders, static fn(array $w): bool => $w['Start_Date'] === $mm[1]));
        }
    }

    return [];
};

$graph = ['calls' => [], 'downloads' => [], 'logins' => 0, 'mode' => 'ok'];
$item = static function (string $id, string $name, int $size, bool $folder = false): array {
    return ['id' => $id, 'name' => $name, 'size' => $size, 'eTag' => '"{' . $id . '},1"', 'lastModifiedDateTime' => '2026-10-01T10:00:00Z'] + ($folder ? ['folder' => ['childCount' => 1]] : ['file' => ['mimeType' => 'application/octet-stream']]);
};
$GLOBALS['DAEDALUS_GRAPH_TRANSPORT'] = static function (array $request) use (&$graph, $files, $item, $equipmentFolder): array {
    $url = (string) $request['url'];
    $graph['calls'][] = $request['method'] . ' ' . $url;
    $headers = implode("\n", $request['headers'] ?? []);
    $json = static fn(int $status, array $body): array => ['status' => $status, 'headers' => ['Content-Type' => 'application/json'], 'body' => json_encode($body), 'error' => null];

    if ($graph['mode'] === 'down') {
        return ['status' => 0, 'headers' => [], 'body' => '', 'error' => 'Operation timed out after 5000 milliseconds'];
    }
    if ($graph['mode'] === 'error500') {
        return $json(500, ['error' => ['code' => 'generalException']]);
    }

    if (strpos($url, 'https://download.example/') === 0) {
        if (stripos($headers, 'Authorization') !== false) {
            fail('Download-URL mag geen Authorization-header krijgen.');
        }
        $id = substr($url, strlen('https://download.example/'));
        $graph['downloads'][] = $id;
        return ['status' => 200, 'headers' => [], 'body' => $files[$id] ?? '', 'error' => null];
    }

    if (strpos($url, 'https://login.microsoftonline.com/tenant-test/oauth2/v2.0/token') === 0) {
        $graph['logins']++;
        check(strpos((string) $request['body'], 'grant_type=client_credentials') !== false, 'token-aanvraag gebruikt client credentials');
        check(strpos((string) $request['body'], 'scope=' . rawurlencode('https://graph.microsoft.com/.default')) !== false, 'token-aanvraag gebruikt token_scope');
        check(($request['verify_ssl'] ?? null) === true, 'verify_ssl uit $sharepointSettings doorgegeven');
        return $json(200, ['access_token' => $graph['token'] ?? 'tok-123', 'expires_in' => 3599, 'token_type' => 'Bearer']);
    }

    if (strpos($headers, 'Authorization: Bearer tok-123') === false) {
        fail('Graph-aanroep zonder geldig token: ' . $url);
    }

    $path = rawurldecode((string) parse_url($url, PHP_URL_PATH));
    if ($path === '/v1.0/sites/kvtnl.sharepoint.com:/sites/KVTAlgemeen') {
        return $json(200, ['id' => 'site-1', 'webUrl' => 'https://kvtnl.sharepoint.com/sites/KVTAlgemeen']);
    }
    if ($path === '/v1.0/sites/site-1/drive') {
        return $json(200, ['id' => 'drive-1', 'name' => 'Documenten', 'webUrl' => 'https://kvtnl.sharepoint.com/sites/KVTAlgemeen/Gedeelde%20documenten']);
    }
    if (preg_match('#^/v1\.0/drives/drive-1/items/([^/]+)/content$#', $path, $m) === 1) {
        return ['status' => 302, 'headers' => ['Location' => 'https://download.example/' . $m[1]], 'body' => '', 'error' => null];
    }
    if ($path === '/v1.0/drives/drive-1/root:/General/servicerapporten.xlsx') {
        return $json(200, $item('idx', 'servicerapporten.xlsx', strlen($files['idx'])));
    }

    $children = [
        $equipmentFolder => [
            $item('f00', '00 Service Rapporten PDF', 0, true),
            $item('f01', '01 Service Rapporten Excel', 0, true),
            $item('f15', '15 Fotos', 0, true),
        ],
        $equipmentFolder . '/01 Service Rapporten Excel' => [
            $item('x1', '10000001_20260901_WO2600001_100H_PD_1001.xlsm', strlen($files['x1'])),
            $item('x2dup', '10000001_20260801_WO2600002_99H_PD_1002 (1).xlsm', strlen($files['x2dup'])),
            $item('x2', '10000001_20260801_WO2600002_99H_PD_1002.xlsm', strlen($files['x2'])),
            $item('x3', 'Servicerapport 4012345 10000001.xlsm', strlen($files['x3'])),
            $item('x9', '10000001_20240101_WO26000011_1H_PD_1.xlsm', 10),
        ],
        $equipmentFolder . '/00 Service Rapporten PDF' => [
            $item('p3', '10000001_20250601_WO2600003_Testaggregaat Fictief_90H_PD_1003.pdf', 1000),
        ],
        'General/Daedalus/Aandachtspunten/2026-10-07' => [
            $item('md1', 'WO2600100.md', strlen($files['md1'])),
            $item('md2', 'WO2600101.txt', 10),
        ],
    ];
    if (preg_match('#^/v1\.0/drives/drive-1/root:/(.+):/children$#', $path, $m) === 1) {
        if (!isset($children[$m[1]])) {
            return $json(404, ['error' => ['code' => 'itemNotFound']]);
        }
        return $json(200, ['value' => $children[$m[1]]]);
    }

    return $json(404, ['error' => ['code' => 'itemNotFound']]);
};

// Laad de app-code uit de tijdelijke kopie (zelfde globale scope als op de server).
define('DAEDALUS_EMAIL_NOTIFICATIONS_LIB_ONLY', true);
require $tmpWeb . '/api_send_email_notifications.php';
require_once $tmpWeb . '/lib/daily_briefing.php';
ini_set('display_errors', '0');

// ---------------------------------------------------------------------------
// 1. xlsm: benoemde bereiken (werkmapbreed + bladlokaal, shared/inline/rich strings)
// ---------------------------------------------------------------------------
$texts = xlsx_named_range_texts($fixtureDir . '/x1.xlsm', ['Opmerkingen', 'STORINGSDIAGNOSE']);
same(['Accu zwak, advies vervangen. Gesproken met dhr. Pietersen (06-11122233).'], $texts['opmerkingen'], 'opmerkingen uit werkmapbrede naam');
same(['Alarm lage koelvloeistof'], $texts['storingsdiagnose'], 'storingsdiagnose uit bladlokale naam met rich text, zonder fonetische tekst');
$allNames = xlsx_named_range_texts($fixtureDir . '/x1.xlsm', ['klantgegevens']);
check(strpos(json_encode($allNames), 'Fictieve Klant') !== false, 'controle: fixture bevat echt een rapportkop met klantgegevens');
$texts2 = xlsx_named_range_texts($fixtureDir . '/x2.xlsm', ['opmerkingen', 'storingsdiagnose']);
same(['-'], $texts2['opmerkingen'], 'triviale opmerking wordt gelezen');
same(['geen'], $texts2['storingsdiagnose'], 'inline string wordt gelezen');
same([['sheet' => "Rapport ENG", 'from' => [98, 1], 'to' => [98, 13]]], xlsx_parse_reference("'Rapport ENG'!\$A\$98:\$M\$98"), 'verwijzing met quotes en bereik');
same([], xlsx_parse_reference('#REF!'), 'kapotte verwijzing wordt genegeerd');
same('2026-10-02', xlsx_date_to_ymd('46297'), 'Excel-serienummer naar datum');
same('2026-10-02', xlsx_date_to_ymd('02-10-2026'), 'NL-datumtekst naar datum');

// ---------------------------------------------------------------------------
// 2. Index-parsing en selectie laatste rapporten
// ---------------------------------------------------------------------------
$parsedIndex = briefing_parse_index_file($fixtureDir . '/index.xlsx', '/sites/KVTAlgemeen');
same(7, $parsedIndex['rows'], 'alle indexregels gelezen');
same([$equipmentFolder], $parsedIndex['equipments']['10000001']['f'], 'map uit Equipment URL afgeleid');
check(in_array('Testmonteur, Henk', $parsedIndex['engineers'], true), 'engineernamen (zonder nummer) bewaard voor het PII-filter');
$reports = briefing_reports_for_equipment($parsedIndex, '10000001', '2026-10-07', 3);
same(['WO2600001', 'WO2600002', 'WO2600003'], array_column($reports, 'workorder_no'), 'laatste 3 rapporten vóór de dag, nieuwste eerst');
same('General/Equipments/X - Y', briefing_folder_from_url('https://kvtnl.sharepoint.com/sites/KVTAlgemeen/Gedeelde%20documenten/General/Equipments/X%20-%20Y', '/sites/KVTAlgemeen'), 'map uit URL met %20');

// ---------------------------------------------------------------------------
// 3. has_notes / triviale opmerkingen
// ---------------------------------------------------------------------------
foreach (['', '-', ' -- ', 'geen', 'Geen.', 'n.v.t.', 'NVT', 'N/A', 'geen opmerkingen', 'Geen bijzonderheden!', 'ok', 'geen actieve storing'] as $trivial) {
    check(briefing_is_trivial_note($trivial), 'triviaal: ' . json_encode($trivial));
}
foreach (['Accu vervangen', 'Tankinhoud < 40%, bijvullen', 'geen koelvloeistof aanwezig'] as $notTrivial) {
    check(!briefing_is_trivial_note($notTrivial), 'niet triviaal: ' . json_encode($notTrivial));
}
check(!briefing_reports_have_notes([['opmerkingen' => '-', 'storingsdiagnose' => 'geen'], ['opmerkingen' => 'n.v.t.', 'storingsdiagnose' => '']]), 'has_notes false bij alleen triviale tekst');
check(briefing_reports_have_notes([['opmerkingen' => '-', 'storingsdiagnose' => 'Alarm lage koelvloeistof']]), 'has_notes true bij echte diagnose');
check(!briefing_reports_have_notes([]), 'has_notes false zonder rapporten');

// ---------------------------------------------------------------------------
// 4. PII-filter
// ---------------------------------------------------------------------------
$scrubbed = briefing_scrub_pii("Gesproken met dhr. Pietersen, tel 06-11122233 of +31 (0)20 123 4567, mail a.b@klant.nl.\nNaam:\nHandtekening service engineer\nTelefoon: 010-9998887\nOud, Piet heeft filter vervangen, Piet Oud komt terug. Equipment 10000001, 130H, WO2600001.", ['Oud, Piet 1003']);
foreach (['Pietersen', '06-111', '123 4567', 'a.b@klant.nl', 'Handtekening', '010-999', 'Oud, Piet', 'Piet Oud'] as $pii) {
    check(stripos($scrubbed, $pii) === false, 'PII verwijderd: ' . $pii . ' in ' . $scrubbed);
}
foreach (['10000001', '130H', 'WO2600001', 'filter vervangen'] as $keep) {
    check(strpos($scrubbed, $keep) !== false, 'inhoud behouden: ' . $keep);
}
same(['opmerkingen' => "Tankinhoud bedraagt minder dan 40%, advies bijvullen", 'storingsdiagnose' => ''], briefing_pdf_sections("Situatie bij vertrek\nVoor eventuele aanbevelingen zie “opmerkingen”.\nOpmerkingen\nTankinhoud bedraagt minder dan 40%, advies bijvullen\nHandtekening contactpersoon op locatie\nnaam:\nTestmonteur, Henk"), 'pdf-secties: alleen Opmerkingen, geen handtekening');

// Ongeldige UTF-8 mag het filter niet uitschakelen.
$scrubbedInvalid = briefing_scrub_pii("Bel 06-12345678 of mail jan@example.com \xC3\x28 ok");
check(strpos($scrubbedInvalid, '12345678') === false && strpos($scrubbedInvalid, 'jan@example.com') === false, 'PII-filter werkt ook bij ongeldige UTF-8');
check(mb_check_encoding($scrubbedInvalid, 'UTF-8'), 'output is geldige UTF-8');

// ---------------------------------------------------------------------------
// 5. Markdown → HTML (escaping)
// ---------------------------------------------------------------------------
$html = briefing_markdown_to_html($files['md1']);
check(strpos($html, '<strong>Accu</strong>') !== false, 'vet wordt <strong>');
check(strpos($html, '&lt;b&gt;direct&lt;/b&gt;') !== false, 'raw HTML wordt ge-escaped');
check(strpos($html, '<script') === false && strpos($html, '&lt;script&gt;') !== false, 'script-tag wordt ge-escaped');
check(strpos($html, 'href="https://kvtnl.sharepoint.com/x"') !== false, 'https-link blijft link');
check(stripos($html, 'href="javascript') === false, 'javascript:-link wordt geen link');
check(strpos($html, '<ul') !== false && strpos($html, '<li') !== false, 'bullets worden een lijst');
same('<p style="margin:4px 0;">&quot;a&quot; &amp; &#039;b&#039;</p>', briefing_markdown_to_html('"a" & \'b\''), 'quotes en ampersand ge-escaped');
same('7 oktober 2026', briefing_nl_date_label('2026-10-07'), 'Nederlands datumlabel');
same('1 januari 2027', briefing_nl_date_label('2027-01-01'), 'Nederlands datumlabel januari');

// ---------------------------------------------------------------------------
// 6. Endpoint: auth, methode, parameters
// ---------------------------------------------------------------------------
same(401, call_endpoint(['date' => '2026-10-07'], null)['status'], 'geen API-key → 401');
same(401, call_endpoint(['date' => '2026-10-07'], 'fout')['status'], 'foute API-key → 401');
same(401, call_endpoint(['date' => '2026-10-07'], ' ')['status'], 'lege API-key → 401');
same(405, call_endpoint(['date' => '2026-10-07'], 'test-key-0123456789abcdef', 'POST')['status'], 'POST → 405');
same(400, call_endpoint(['date' => '2026-02-30'])['status'], 'ongeldige datum → 400');
same(400, call_endpoint(['date' => '2026-10-07', 'scope' => 'iedereen'])['status'], 'ongeldige scope → 400');
same(400, call_endpoint(['date' => '2026-10-07', 'company' => 'Onbekend BV'])['status'], 'onbekend bedrijf → 400');
same(400, call_endpoint(['date' => '2026-10-07', 'history' => '0'])['status'], 'history 0 → 400');
$saved = $GLOBALS['briefingApiKeys'];
$GLOBALS['briefingApiKeys'] = [];
same(401, call_endpoint(['date' => '2026-10-07'])['status'], 'zonder geconfigureerde keys altijd 401');
$GLOBALS['briefingApiKeys'] = $saved;
check(empty($bcCalls) && empty($graph['calls']), 'geweigerde verzoeken raken BC en Graph niet');

// ---------------------------------------------------------------------------
// 7. Endpoint: volledige payload met gemockte BC + Graph
// ---------------------------------------------------------------------------
$response = call_endpoint(['date' => '2026-10-07'], 'tweede-sleutel-abcdefghijkl');
same(200, $response['status'], 'tweede key werkt, 200');
$body = $response['body'];
same(true, $body['ok'], 'ok');
same('2026-10-07', $body['date'], 'datum');
same('7 oktober 2026', $body['date_label'], 'datumlabel');
same('subscribers', $body['scope'], 'standaard scope = dagmail-abonnees');
same('ok', $body['sharepoint']['index_status'], 'index geladen');
same(1, count($body['resources']), 'alleen de monteur met dagoverzicht aan');
$resource = $body['resources'][0];
same(['1001', 'Testmonteur, Henk', 'henk@example.com', 'Koninklijke van Twist'], [$resource['resource_no'], $resource['name'], $resource['email'], $resource['company']], 'resourcegegevens');
same(['WO2610002', 'WO2610001', 'WO2610004', 'WO2610005'], array_column($resource['workorders'], 'no'), 'werkorders gesorteerd op tijd, geannuleerde (Geannuleerd) weggelaten');
$wo1 = find_workorder($body, 'WO2610001');
same(['PD', 'Proefdraaien', '10:00', '12:00', 'Fictieve locatie', '10000001', 'Testaggregaat Fictief'], [$wo1['task_code'], $wo1['task'], $wo1['start_time'], $wo1['end_time'], $wo1['main_entity_description'], $wo1['component_no'], $wo1['component_description']], 'werkordervelden');
check(strpos($wo1['link'], 'https://sleutels.kvt.nl/daedalus/index.php?') === 0 && strpos($wo1['link'], 'workorder=WO2610001') !== false, 'Daedalus-link');
same('ok', $wo1['history_status'], 'history-status ok');
same(true, $wo1['has_notes'], 'has_notes true');
same(['WO2600001', 'WO2600002', 'WO2600003', '4012345'], array_column($wo1['history'], 'workorder_no'), 'geschiedenis: vóór de dag, nieuwste eerst, oude WO-nummers ook');
same(['ok', 'ok', 'pdf_unavailable', 'ok'], array_column($wo1['history'], 'status'), 'status per rapport (pdf zonder pdftotext netjes overgeslagen)');
same('1 september 2026', $wo1['history'][0]['date_label'], 'datumlabel per rapport');
same('xlsm', $wo1['history'][0]['source'], 'bron xlsm');
same('Accu zwak, advies vervangen. Gesproken met dhr. [naam] ([telefoon]).', $wo1['history'][0]['opmerkingen'], 'opmerkingen gefilterd');
same('Alarm lage koelvloeistof', $wo1['history'][0]['storingsdiagnose'], 'storingsdiagnose');
same(false, $wo1['history'][1]['has_notes'], 'rapport met alleen "-"/"geen" heeft geen notes');
same('[naam] heeft brandstoffilter vervangen.', $wo1['history'][3]['opmerkingen'], 'engineernaam uit index gefilterd, telefoonregel weg');
$historyText = history_json($body);
foreach (['Pietersen', '06-111', 'Fictieve Klant', 'Jan Fictief', '010-999', 'DUPLICAAT', 'Henk', 'Testmonteur', 'Handtekening', '0612345678'] as $forbidden) {
    check(stripos($historyText, $forbidden) === false, 'geen PII/kop in history: ' . $forbidden);
}
same($wo1['history'], find_workorder($body, 'WO2610002')['history'], 'zelfde object → zelfde geschiedenis');
same('no_component', find_workorder($body, 'WO2610004')['history_status'], 'zonder Component_No');
same('no_reports', find_workorder($body, 'WO2610005')['history_status'], 'object zonder rapporten');
same(false, find_workorder($body, 'WO2610005')['has_notes'], 'zonder rapporten geen notes');
same(['idx', 'x1', 'x2', 'x3'], $graph['downloads'], 'alleen benodigde bestanden gedownload (geen duplicaat, geen pdf)');
same(1, $graph['logins'], 'één token-aanvraag');
check(strpos(json_encode($body), 'secret-test-should-not-leak') === false && strpos(json_encode($body), 'tok-123') === false, 'geen secrets in de output');

// Tweede aanroep: alles uit cache (index eTag pas na 30 min gecontroleerd).
$downloadsBefore = count($graph['downloads']);
$callsBefore = count($graph['calls']);
$response2 = call_endpoint(['date' => '2026-10-07', 'history' => '2']);
same(200, $response2['status'], 'tweede aanroep 200');
same($downloadsBefore, count($graph['downloads']), 'tweede aanroep downloadt niets opnieuw');
same($callsBefore, count($graph['calls']), 'tweede aanroep doet geen Graph-calls (token, site, drive, lijsten, tekst gecachet)');
same(['WO2600001', 'WO2600002'], array_column(find_workorder($response2['body'], 'WO2610001')['history'], 'workorder_no'), 'history=2');

// Cachebestanden zijn PHP-guarded.
$cacheFiles = glob($tmpWeb . '/cache/briefing/*.php') ?: [];
check(count($cacheFiles) > 3, 'cachebestanden aangemaakt onder web/cache/briefing');
foreach ($cacheFiles as $cacheFile) {
    check(strpos((string) file_get_contents($cacheFile), "<?php http_response_code(404); exit; ?>\n") === 0, 'cachebestand begint met PHP-guard: ' . basename($cacheFile));
}
check(is_file($tmpWeb . '/cache/briefing/.htaccess'), '.htaccess in cachemap');
same([], glob($tmpWeb . '/cache/briefing/tmp/*') ?: [], 'tijdelijke downloads opgeruimd');

// scope=all: alle persoon-resources met werkorders (machine-resource niet).
$all = call_endpoint(['date' => '2026-10-07', 'scope' => 'all', 'company' => 'Koninklijke van Twist']);
same(200, $all['status'], 'scope=all 200');
same(['1002', '1001'], array_column($all['body']['resources'], 'resource_no'), 'scope=all: personen, gesorteerd op naam');
same('kees@example.com', $all['body']['resources'][0]['email'], 'e-mail uit AppResource');
same('ok', find_workorder($all['body'], 'WO2610006')['history_status'], 'ander object: index gevonden');
same('folder_not_found', find_workorder($all['body'], 'WO2610006')['history'][0]['status'], 'ontbrekende objectmap → status i.p.v. fout');

// Graph onbereikbaar, lege cache → 200 met status per werkorder.
clear_briefing_cache();
$graph['mode'] = 'down';
$down = call_endpoint(['date' => '2026-10-07']);
same(200, $down['status'], 'SharePoint down → toch 200');
same('error', $down['body']['sharepoint']['index_status'], 'index_status error');
same('error', find_workorder($down['body'], 'WO2610001')['history_status'], 'history_status error per werkorder');
check(strpos((string) find_workorder($down['body'], 'WO2610001')['history_message'], 'Graph-token') !== false, 'foutmelding per werkorder');
$graph['mode'] = 'ok';

// Budget op → budget_exceeded i.p.v. hangen.
clear_briefing_cache();
graph_set_deadline(microtime(true) - 1);
$budget = briefing_history_for_equipment($parsedIndex, '10000001', '2026-10-07', 2, [], 5);
graph_set_deadline(0);
same('budget_exceeded', $budget['status'], 'budget op → budget_exceeded');

// Zonder Graph-config → not_configured.
$savedTenant = $GLOBALS['sharepointSettings']['tenant_id'];
$GLOBALS['sharepointSettings']['tenant_id'] = '';
$noGraph = call_endpoint(['date' => '2026-10-07']);
same(200, $noGraph['status'], 'zonder Graph 200');
same('not_configured', find_workorder($noGraph['body'], 'WO2610001')['history_status'], 'history_status not_configured');
same(false, $noGraph['body']['sharepoint']['configured'], 'sharepoint.configured false');
$GLOBALS['sharepointSettings']['tenant_id'] = $savedTenant;

// Drive-keuze: webUrl-segment gaat voor weergavenaam (KVTAlgemeen heeft ook een lege "Gedeelde Documenten").
$drives = [
    ['id' => 'leeg', 'name' => 'Gedeelde Documenten', 'webUrl' => 'https://kvtnl.sharepoint.com/sites/KVTAlgemeen/Gedeelde%20Documenten1'],
    ['id' => 'goed', 'name' => 'Documenten', 'webUrl' => 'https://kvtnl.sharepoint.com/sites/KVTAlgemeen/Gedeelde%20documenten'],
];
same('goed', graph_pick_drive($drives, 'Gedeelde documenten')['id'], 'drive op webUrl-segment');
same('goed', graph_pick_drive($drives, 'Documenten')['id'], 'drive op weergavenaam als fallback');

// BC volledig onbereikbaar → 502 (subscribers én all), zonder URLs in de output.
$GLOBALS['TEST_BC_DOWN'] = true;
$bcDown = call_endpoint(['date' => '2026-11-03']);
same(502, $bcDown['status'], 'BC down (subscribers) → 502');
check(strpos(json_encode($bcDown['body']), 'bc.invalid') === false, 'geen BC-URL in foutmelding');
check(strpos(json_encode($bcDown['body']), 'HTTP 503') === false, 'geen exceptiontekst in foutmelding');
same('Business Central niet bereikbaar: ophalen van gegevens is mislukt.', (string) ($bcDown['body']['errors'][0]['message'] ?? ''), 'vaste foutmelding');
same(502, call_endpoint(['date' => '2026-11-03', 'scope' => 'all', 'company' => 'Koninklijke van Twist'])['status'], 'BC down (all) → 502');
$GLOBALS['TEST_BC_DOWN'] = false;

// Clio-config: transcript-site/drive uit $sharepointSettings nooit gebruikt.
$clioCalls = array_filter($graph['calls'], static fn(string $call): bool => strpos($call, 'clio-') !== false);
same([], array_values($clioCalls), "Clio's site_id/drive_id worden genegeerd");

// Token-claims (zelfde controle als Clio): JWT zonder roles → nette fout, met roles → ok.
$jwt = static fn(array $claims): string => 'eyJhbGciOiJub25lIn0.' . rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=') . '.sig';
$validated = true;
try {
    graph_validate_token_claims($jwt(['aud' => 'https://graph.microsoft.com', 'roles' => ['Sites.Read.All']]));
} catch (RuntimeException $e) {
    $validated = false;
}
check($validated, 'token met roles geaccepteerd');
$rejected = '';
try {
    graph_validate_token_claims($jwt(['aud' => 'https://graph.microsoft.com']));
} catch (RuntimeException $e) {
    $rejected = $e->getMessage();
}
check(strpos($rejected, 'roles') !== false, 'token zonder roles geweigerd');
same(['roles' => ['Sites.Selected']], graph_jwt_payload($jwt(['roles' => ['Sites.Selected']])), 'jwt-payload gedecodeerd');

// token_url-override en token_scope uit $sharepointSettings.
$savedSettings = $GLOBALS['sharepointSettings'];
$GLOBALS['sharepointSettings']['token_url'] = 'https://login.microsoftonline.com/tenant-test/oauth2/v2.0/token?override=1';
$GLOBALS['sharepointSettings']['client_secret'] = 'ander-geheim';
$loginsBefore = $graph['logins'];
graph_access_token();
same($loginsBefore + 1, $graph['logins'], 'nieuwe secret → nieuw token');
check(strpos((string) end($graph['calls']), 'override=1') !== false, 'token_url-override gebruikt');
$GLOBALS['sharepointSettings'] = $savedSettings;
same('kvtnl.sharepoint.com', graph_config()['site_hostname'], 'site-hostname uit $briefingSiteHostname');
same('/sites/KVTAlgemeen', graph_config()['site_path'], 'site-pad uit $briefingSitePath');

// Vaste $briefingDriveId: geen site-lookup (werkt dan ook met alleen Files.Read(Write).All).
$GLOBALS['briefingDriveId'] = 'drive-1';
$callsBefore = count($graph['calls']);
same('drive-1', graph_drive_id(), 'drive-id uit $briefingDriveId');
same($callsBefore, count($graph['calls']), 'geen site-/drive-lookup met vaste drive-id');
unset($GLOBALS['briefingDriveId']);

// Mímir-optiewaarden.
check(daily_briefing_option_matches('Geannuleerd', ['Cancelled', 'Geannuleerd']), 'Geannuleerd = geannuleerd');
check(daily_briefing_option_matches(' CANCELLED ', ['Cancelled']), 'hoofdletters/spaties');
check(!daily_briefing_option_matches('Gepland', ['Cancelled', 'Geannuleerd']), 'Gepland is niet geannuleerd');

// ---------------------------------------------------------------------------
// 8. Dagmail: aandachtspunten en fallback
// ---------------------------------------------------------------------------
$mailWorkOrders = [
    ['No' => 'WO2600100', 'Task_Description' => 'Proefdraaien', 'Status' => 'Gepland', 'Start_Date' => '2026-10-07', 'Start_Time' => '08:00:00', 'End_Time' => '10:00:00', 'Main_Entity_Description' => 'Locatie', 'Component_Description' => 'Object'],
    ['No' => 'WO2600101', 'Task_Description' => 'Storing', 'Status' => 'Gepland', 'Start_Date' => '2026-10-07', 'Start_Time' => '11:00:00', 'End_Time' => '12:00:00', 'Main_Entity_Description' => 'Locatie', 'Component_Description' => 'Object'],
];
$legacyHtml = build_email_html('Koninklijke van Twist', '1001', 'Testmonteur, Henk', $mailWorkOrders, [], [], []);
same($legacyHtml, build_email_html('Koninklijke van Twist', '1001', 'Testmonteur, Henk', $mailWorkOrders, [], [], [], []), 'zonder aandachtspunten exact dezelfde mail');
check(strpos($legacyHtml, 'Aandachtspunten') === false, 'zonder notes geen blok');

// Zonder Graph-config: geen calls, lege array.
$GLOBALS['sharepointSettings']['tenant_id'] = '';
briefing_notes_reset_state();
$callsBefore = count($graph['calls']);
same([], briefing_attention_notes_html(['WO2600100'], '2026-10-07'), 'zonder Graph-config geen aandachtspunten');
same($callsBefore, count($graph['calls']), 'zonder Graph-config geen Graph-calls');
$GLOBALS['sharepointSettings']['tenant_id'] = $savedTenant;

// Met Graph: bestand voor WO2600100, niet voor WO2600101 (.txt telt niet).
briefing_notes_reset_state();
$notes = briefing_attention_notes_html(['WO2600100', 'WO2600101', "../../etc"], '2026-10-07');
same(['WO2600100'], array_keys($notes), 'alleen werkorder met <WO>.md');
$mailHtml = build_email_html('Koninklijke van Twist', '1001', 'Testmonteur, Henk', $mailWorkOrders, [], [], [], $notes);
check(substr_count($mailHtml, 'Aandachtspunten') === 1, 'blok Aandachtspunten één keer in de mail');
check(strpos($mailHtml, '<strong>Accu</strong>') !== false && strpos($mailHtml, '<script') === false, 'veilige HTML in de mail');
check(strpos(str_replace(attention_notes_block_html($notes['WO2600100']), '', $mailHtml), 'Aandachtspunten') === false, 'blok staat bij de juiste werkorder');
$listingCalls = count(array_filter($graph['calls'], static fn(string $c): bool => strpos(rawurldecode($c), 'Aandachtspunten/2026-10-07:/children') !== false));
same(1, $listingCalls, 'één maplijst voor de dag');
briefing_attention_notes_html(['WO2600100'], '2026-10-07');
same(1, count(array_filter($graph['calls'], static fn(string $c): bool => strpos(rawurldecode($c), 'Aandachtspunten/2026-10-07:/children') !== false)), 'maplijst hergebruikt binnen de run');

// Map voor de dag bestaat niet → leeg.
briefing_notes_reset_state();
same([], briefing_attention_notes_html(['WO2600100'], '2026-10-09'), 'geen map voor de dag → geen aandachtspunten');

// Graph down/time-out → leeg, geen exception, rest van de run overgeslagen.
clear_briefing_cache();
briefing_notes_reset_state();
$graph['mode'] = 'down';
$started = microtime(true);
same([], briefing_attention_notes_html(['WO2600100'], '2026-10-07'), 'Graph down → mail zonder aandachtspunten');
$callsBefore = count($graph['calls']);
same([], briefing_attention_notes_html(['WO2600100'], '2026-10-07'), 'na fout overgeslagen');
same($callsBefore, count($graph['calls']), 'na fout geen nieuwe Graph-calls in dezelfde run');
check(microtime(true) - $started < 2, 'fallback is snel');
$graph['mode'] = 'error500';
briefing_notes_reset_state();
same([], briefing_attention_notes_html(['WO2600100'], '2026-10-07'), 'Graph 500 → mail zonder aandachtspunten');
same([], briefing_attention_notes_html(['WO2600100'], 'gisteren'), 'ongeldige dag → leeg');
$graph['mode'] = 'ok';

// ---------------------------------------------------------------------------
// 9. HTTP: echte request via de PHP built-in server (401/405 zonder BC/Graph)
// ---------------------------------------------------------------------------
$port = 18000 + random_int(0, 999);
$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $tmpWeb], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
if (is_resource($server) && function_exists('curl_init')) {
    $request = static function (string $method, ?string $key) use ($port): array {
        $ch = curl_init('http://127.0.0.1:' . $port . '/api/daily_briefing.php?date=2026-10-07');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 5, CURLOPT_HTTPHEADER => $key !== null ? ['X-API-Key: ' . $key] : []]);
        $bodyText = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$status, json_decode($bodyText, true)];
    };
    $ready = false;
    for ($i = 0; $i < 50 && !$ready; $i++) {
        usleep(100000);
        $ready = $request('GET', null)[0] > 0;
    }
    if ($ready) {
        [$status, $json] = $request('GET', null);
        same(401, $status, 'HTTP zonder key → 401');
        same(false, $json['ok'] ?? null, 'HTTP 401 geeft JSON');
        same(401, $request('GET', 'verkeerd')[0], 'HTTP foute key → 401');
        same(405, $request('POST', 'test-key-0123456789abcdef')[0], 'HTTP POST → 405');
    } else {
        fwrite(STDERR, "SKIP: PHP built-in server niet bereikbaar\n");
    }
    proc_terminate($server);
    proc_close($server);
}

echo "OK ($assertions checks)\n";
