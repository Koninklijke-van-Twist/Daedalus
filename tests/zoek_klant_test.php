<?php
/**
 * Zoek Klant: join, normalisatie, zoeken (AND) en actief-logica.
 * Run: php tests/zoek_klant_test.php
 * Optioneel: ZK_SAMPLE_DIR=/pad met AppMaintenanceContracts_kvt.json + lines_kvt.json (echte BC-export).
 */

require __DIR__ . '/../web/lib/zoek_klant.php';

function zk_check(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$today = '2026-10-28';
$headers = [
    ['Contract_Type' => 'Onderhoud', 'Contract_No' => '40000001', 'Description' => 'Gebouw Zuid', 'Customer_No' => '10398', 'Name' => 'Attero Noord BV', 'Control11095585' => 'Vrijgegeven', 'Starting_Date' => '2026-01-01', 'End_Date' => '2026-12-31', 'City' => 'Wijster'],
    ['Contract_Type' => 'Onderhoud', 'Contract_No' => '40000002', 'Description' => 'Oud', 'Customer_No' => '10398', 'Name' => 'Attero Noord BV', 'Status' => 'Vrijgegeven', 'Starting_Date' => '2024-01-01', 'End_Date' => '2025-12-31'],
    ['Contract_Type' => 'Onderhoud', 'Contract_No' => '40000003', 'Description' => 'Concept', 'Customer_No' => '20000', 'Name' => 'Café Ëlsenga', 'Status' => 'Open', 'Starting_Date' => '2026-01-01', 'End_Date' => '2026-12-31'],
    ['Contract_Type' => 'Onderhoud', 'Contract_No' => '40000004', 'Description' => 'Later', 'Customer_No' => '30000', 'Name' => 'Toekomst BV', 'Status' => 'Vrijgegeven', 'Starting_Date' => '2027-01-01', 'End_Date' => '2027-12-31'],
];
$lines = [
    ['Contract_Type' => 'Onderhoud', 'Contract_No' => '40000001', 'Main_Entity' => '81011', 'Main_Entity_Description' => 'Locatie Wijster   ', 'Description' => 'Compressor', 'Ship_to_Code' => 'ZUID'],
];
$cache = zk_join($headers, $lines, ['81011' => ['KVT_Address' => 'Parabaan 10 ', 'KVT_Post_Code' => '4706 CN', 'KVT_City' => 'Roosendaal']]);

zk_check(count($cache['contracts']) === 4, '4 contracten');
zk_check($cache['contracts'][0]['locations'][0]['name'] === 'Locatie Wijster', 'trailing spaces weg');
zk_check(zk_normalize(' Café  ËLSENGA ') === 'cafe elsenga', 'normalisatie');

zk_check(zk_contract_state($cache['contracts'][0], $today) === 'actief', 'actief');
zk_check(zk_contract_state($cache['contracts'][1], $today) === 'verlopen', 'verlopen');
zk_check(zk_contract_state($cache['contracts'][2], $today) === 'open', 'open');
zk_check(zk_contract_state($cache['contracts'][3], $today) === 'toekomstig', 'toekomstig');
zk_check(zk_contract_state(['status' => 'Open', 'start' => '2024-01-01', 'end' => '2025-12-31'], $today) === 'verlopen', 'open + verlopen = verlopen');

$r = zk_search($cache, 'attero', $today);
zk_check(count($r) === 2 && $r[0]['no'] === '40000001' && $r[0]['state'] === 'actief', 'actief eerst');
zk_check($r[1]['state'] === 'verlopen', 'verlopen na actief');
zk_check(count(zk_search($cache, '10398 oud', $today)) === 1, 'AND over woorden');
zk_check(count(zk_search($cache, 'elsenga cafe', $today)) === 1, 'diacrieten');
zk_check(count(zk_search($cache, 'roosendaal', $today)) === 1, 'locatie-adres');
zk_check(count(zk_search($cache, 'zuid compressor', $today)) === 1, 'ship-to + regelomschrijving');
zk_check(zk_search($cache, 'bestaatniet', $today) === [], 'niets gevonden');
zk_check(zk_search($cache, '   ', $today) === [], 'lege zoekopdracht');

$dir = getenv('ZK_SAMPLE_DIR');
if (is_string($dir) && is_file("$dir/AppMaintenanceContracts_kvt.json") && is_file("$dir/lines_kvt.json")) {
    $h = json_decode((string) file_get_contents("$dir/AppMaintenanceContracts_kvt.json"), true)['value'];
    $l = json_decode((string) file_get_contents("$dir/lines_kvt.json"), true)['value'];
    $real = zk_join($h, $l);
    zk_check(count($real['contracts']) === count($h), 'sample: alle headers');
    zk_check(count(zk_search($real, '40000717', $today)) === 1, 'sample: contractnummer');
    echo "sample ok (" . count($h) . " contracten)\n";
}

echo "OK\n";
