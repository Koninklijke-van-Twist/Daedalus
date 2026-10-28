<?php
/**
 * Zoek Klant: onderhoudscontracten per bedrijf cachen en doorzoeken.
 *
 * - Cache per bedrijf in web/cache/zoekklant/ (web/cache/ is gitignored en wordt niet gedeployed/gewist).
 * - Cachebestanden zijn .php met een PHP-guard, zodat een direct HTTP-verzoek niets teruggeeft;
 *   daarnaast schrijft zk_cache_dir() een .htaccess ("Require all denied") en een lege index.html.
 * - Ververst door nightly.php; ontbreekt de cache, dan bouwt zoek_klant.php hem bij het eerste bezoek.
 */

/**
 * Constants
 */
const ZK_CACHE_GUARD = "<?php http_response_code(404); exit; ?>\n";
const ZK_PAGE_SIZE = 500;
const ZK_MAX_AGE = 3600;

/**
 * Functies: cache-opslag
 */
function zk_cache_dir(): string
{
    $override = $GLOBALS['DAEDALUS_ZK_CACHE_DIR'] ?? null;
    $dir = is_string($override) && trim($override) !== ''
        ? rtrim($override, '/\\')
        : dirname(__DIR__) . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'zoekklant';

    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }
    if (is_dir($dir) && !is_file($dir . DIRECTORY_SEPARATOR . '.htaccess')) {
        @file_put_contents($dir . DIRECTORY_SEPARATOR . '.htaccess', "Require all denied\nDeny from all\n");
        @file_put_contents($dir . DIRECTORY_SEPARATOR . 'index.html', '');
    }

    return $dir;
}

function zk_cache_path(string $company): string
{
    $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($company)), '-');
    return zk_cache_dir() . DIRECTORY_SEPARATOR . 'contracts_' . ($slug !== '' ? $slug : md5($company)) . '.php';
}

function zk_load_cache(string $company): ?array
{
    $path = zk_cache_path($company);
    if (!is_file($path)) {
        return null;
    }
    $raw = (string) @file_get_contents($path);
    if (strncmp($raw, ZK_CACHE_GUARD, strlen(ZK_CACHE_GUARD)) === 0) {
        $raw = substr($raw, strlen(ZK_CACHE_GUARD));
    }
    $data = json_decode($raw, true);
    return is_array($data) && isset($data['contracts']) ? $data : null;
}

function zk_save_cache(string $company, array $cache): void
{
    $path = zk_cache_path($company);
    $tmp = $path . '.' . getmypid() . '.tmp';
    $json = json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || @file_put_contents($tmp, ZK_CACHE_GUARD . $json) === false) {
        throw new Exception('Zoek Klant-cache kon niet worden geschreven.');
    }
    @rename($tmp, $path);
}

/**
 * Functies: ophalen uit Mímir
 */

/**
 * Haalt een tabel in pagina's op (filter "<key> gt '<laatste>'"), omdat top 0 op grote tabellen kan timeouten.
 *
 * @param list<string> $select
 * @return list<array<string, mixed>>
 */
function zk_fetch_paged(string $company, string $table, string $keyField, array $select): array
{
    $rows = [];
    $last = '';
    for ($page = 0; $page < 100; $page++) {
        $body = ['company' => $company, 'table' => $table, 'top' => ZK_PAGE_SIZE, 'max_age' => ZK_MAX_AGE, 'select' => $select];
        if ($last !== '') {
            $body['filter'] = $keyField . " gt '" . str_replace("'", "''", $last) . "'";
        }
        $response = odata_mimir_request('POST', 'query.php', $body);
        $value = $response['value'] ?? null;
        if (!is_array($value)) {
            throw new Exception("Mímir-antwoord voor $table mist 'value'.");
        }
        $rows = array_merge($rows, $value);
        if (count($value) < ZK_PAGE_SIZE) {
            break;
        }
        $last = '';
        foreach ($value as $row) {
            $key = (string) ($row[$keyField] ?? '');
            if (strcmp($key, $last) > 0) {
                $last = $key;
            }
        }
        if ($last === '') {
            break;
        }
    }
    return $rows;
}

/**
 * @return list<array<string, mixed>>
 */
function zk_fetch_all(string $company, string $table, array $select): array
{
    $response = odata_mimir_request('POST', 'query.php', [
        'company' => $company, 'table' => $table, 'top' => 0, 'max_age' => ZK_MAX_AGE, 'select' => $select,
    ]);
    $value = $response['value'] ?? null;
    if (!is_array($value)) {
        throw new Exception("Mímir-antwoord voor $table mist 'value'.");
    }
    return $value;
}

/**
 * Haalt headers (kaart Onderhoudscontract, incl. adres), regels en servicelocaties op en schrijft de cache.
 */
function zk_build_cache(string $company): array
{
    if (function_exists('set_time_limit')) {
        @set_time_limit(600);
    }

    $headers = zk_fetch_paged($company, 'Onderhoudscontract', 'Contract_No', [
        'Contract_Type', 'Contract_No', 'Description', 'Customer_No', 'Name', 'Address', 'Post_Code', 'City',
        'Bill_to_Customer_No', 'Bill_to_Name', 'Bill_to_City', 'Your_Reference', 'KVT_External_Document_No',
        'Control11095585', 'Starting_Date', 'End_Date',
    ]);
    $lines = zk_fetch_all($company, 'AppMaintenanceContractLines', [
        'Contract_Type', 'Contract_No', 'Line_No', 'Main_Entity', 'Main_Entity_Description', 'Description',
        'Ship_to_Code', 'Contract_Customer_No', 'Start_Date', 'End_Date',
    ]);
    $locations = [];
    try {
        $locationRows = zk_fetch_all($company, 'LVS_MainEntityCard', ['No', 'Description', 'KVT_Address', 'KVT_Post_Code', 'KVT_City']);
        foreach ($locationRows as $row) {
            $locations[trim((string) ($row['No'] ?? ''))] = $row;
        }
    } catch (Throwable $ignored) {
        // Locatie-adressen zijn optioneel; zonder deze tabel wordt alleen op code/naam gezocht.
    }

    $cache = zk_join($headers, $lines, $locations);
    $cache['company'] = $company;
    $cache['built_at'] = date('c');
    zk_save_cache($company, $cache);
    return $cache;
}

/**
 * Functies: joinen en zoeken (puur, getest in tests/zoek_klant_test.php)
 */
function zk_clean($value): string
{
    return trim((string) preg_replace('/\s+/u', ' ', (string) $value));
}

function zk_normalize(string $text): string
{
    $text = mb_strtolower(zk_clean($text), 'UTF-8');
    if (class_exists('Normalizer')) {
        $decomposed = Normalizer::normalize($text, Normalizer::FORM_D);
        if (is_string($decomposed)) {
            $text = (string) preg_replace('/\p{Mn}+/u', '', $decomposed);
        }
    } else {
        $text = strtr($text, [
            'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
            'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i', 'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u', 'ç' => 'c', 'ñ' => 'n', 'ÿ' => 'y',
        ]);
    }
    return $text;
}

/**
 * @param list<array<string, mixed>> $headers
 * @param list<array<string, mixed>> $lines
 * @param array<string, array<string, mixed>> $locations
 */
function zk_join(array $headers, array $lines, array $locations = []): array
{
    $linesByContract = [];
    foreach ($lines as $line) {
        $key = zk_clean($line['Contract_Type'] ?? '') . '|' . zk_clean($line['Contract_No'] ?? '');
        $linesByContract[$key][] = $line;
    }

    $contracts = [];
    foreach ($headers as $header) {
        $no = zk_clean($header['Contract_No'] ?? '');
        if ($no === '') {
            continue;
        }
        $key = zk_clean($header['Contract_Type'] ?? '') . '|' . $no;
        $contract = [
            'no' => $no,
            'description' => zk_clean($header['Description'] ?? ''),
            'customer_no' => zk_clean($header['Customer_No'] ?? ''),
            'customer_name' => zk_clean($header['Name'] ?? ''),
            'address' => zk_clean(implode(' ', [$header['Address'] ?? '', $header['Post_Code'] ?? '', $header['City'] ?? ''])),
            'status' => zk_clean($header['Control11095585'] ?? $header['Status'] ?? ''),
            'start' => substr(zk_clean($header['Starting_Date'] ?? ''), 0, 10),
            'end' => substr(zk_clean($header['End_Date'] ?? ''), 0, 10),
            'locations' => [],
        ];
        $extra = [
            $header['Bill_to_Customer_No'] ?? '', $header['Bill_to_Name'] ?? '', $header['Bill_to_City'] ?? '',
            $header['Your_Reference'] ?? '', $header['KVT_External_Document_No'] ?? '',
        ];
        foreach ($linesByContract[$key] ?? [] as $line) {
            $code = zk_clean($line['Main_Entity'] ?? '');
            $location = $locations[$code] ?? [];
            $contract['locations'][] = [
                'code' => $code,
                'name' => zk_clean($line['Main_Entity_Description'] ?? ''),
                'description' => zk_clean($line['Description'] ?? ''),
                'address' => zk_clean(implode(' ', [$location['KVT_Address'] ?? '', $location['KVT_Post_Code'] ?? '', $location['KVT_City'] ?? ''])),
            ];
            $extra[] = $line['Ship_to_Code'] ?? '';
            $extra[] = $location['Description'] ?? '';
        }
        $parts = [$contract['no'], $contract['description'], $contract['customer_no'], $contract['customer_name'], $contract['address']];
        foreach ($contract['locations'] as $location) {
            array_push($parts, $location['code'], $location['name'], $location['description'], $location['address']);
        }
        $contract['index'] = zk_normalize(implode(' ', array_merge($parts, $extra)));
        $contracts[] = $contract;
    }

    return ['contracts' => $contracts];
}

/**
 * actief | open | verlopen | toekomstig
 */
function zk_contract_state(array $contract, string $today): string
{
    $status = zk_normalize((string) ($contract['status'] ?? ''));
    if (!in_array($status, ['vrijgegeven', 'released', 'freigegeben'], true)) {
        return 'open';
    }
    $start = (string) ($contract['start'] ?? '');
    $end = (string) ($contract['end'] ?? '');
    if ($start !== '' && $start !== '0001-01-01' && $start > $today) {
        return 'toekomstig';
    }
    if ($end !== '' && $end !== '0001-01-01' && $end < $today) {
        return 'verlopen';
    }
    return 'actief';
}

/**
 * Alle zoekwoorden moeten voorkomen (AND). Resultaat gesorteerd: actief, toekomstig, open, verlopen.
 *
 * @return list<array<string, mixed>>
 */
function zk_search(array $cache, string $query, string $today): array
{
    $words = array_values(array_filter(explode(' ', zk_normalize($query)), static fn($w) => $w !== ''));
    if ($words === []) {
        return [];
    }
    $order = ['actief' => 0, 'toekomstig' => 1, 'open' => 2, 'verlopen' => 3];
    $results = [];
    foreach ($cache['contracts'] ?? [] as $contract) {
        $index = (string) ($contract['index'] ?? '');
        foreach ($words as $word) {
            if (strpos($index, $word) === false) {
                continue 2;
            }
        }
        $contract['state'] = zk_contract_state($contract, $today);
        $results[] = $contract;
    }
    usort($results, static function (array $a, array $b) use ($order): int {
        return [$order[$a['state']], $b['end'] ?? ''] <=> [$order[$b['state']], $a['end'] ?? ''];
    });
    return $results;
}
