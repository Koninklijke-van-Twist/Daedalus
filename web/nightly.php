<?php
/**
 * Nightly endpoint (GET), door de server rond middernacht vanaf localhost aangeroepen.
 * Ververst de Zoek Klant-cache (onderhoudscontracten) voor elk bedrijf.
 * Niet-localhost verzoeken vallen via logincheck.php onder de normale login.
 */

/**
 * Includes en requires
 */
require __DIR__ . '/auth.php';
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/odata.php';
require_once __DIR__ . '/lib/zoek_klant.php';

@ini_set('max_execution_time', '0');
if (function_exists('set_time_limit')) {
    @set_time_limit(0);
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/**
 * Page load
 */
// Alleen deze bedrijven hebben onderhoudscontracten.
$companies = ['Koninklijke van Twist', 'Hunter van Twist'];

$result = ['success' => true, 'recorded_at' => date('c'), 'zoek_klant' => []];
foreach ($companies as $company) {
    try {
        odata_mimir_circuit_reset();
        $cache = zk_build_cache((string) $company);
        $result['zoek_klant'][$company] = ['success' => true, 'contracts' => count($cache['contracts'])];
    } catch (Throwable $exception) {
        $result['zoek_klant'][$company] = ['success' => false, 'error' => $exception->getMessage()];
    }
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
