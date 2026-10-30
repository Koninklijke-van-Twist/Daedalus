<?php
// Achtergrondscript: Mímir-verzoeken zonder "interactive" (lange slotwacht).
defined('DAEDALUS_MIMIR_BACKGROUND') || define('DAEDALUS_MIMIR_BACKGROUND', true);
/**
 * Elke 10 minuten (GET, vanaf localhost, zoals hourly/nightly). Houdt de Mímir-cache warm
 * voor wat live pagina's vragen: lijst, aantallen, weekkalender (deze en volgende week) per
 * monteur, en werkorderdetails van vandaag en morgen.
 *
 * Werkt door de echte pagina's van index.php via localhost op te vragen met daedalus_warm=1.
 * Zo zijn de querystrings ($select/$filter) exact gelijk aan die van een live page view en
 * serveert Mímir die daarna uit de exacte filterdekking. Max_age bij opwarmen: 540 s.
 */

/**
 * Includes en requires
 */
define('DAEDALUS_INDEX_LIB_ONLY', true);
require __DIR__ . '/index.php';

/**
 * Constants
 */
const FREQUENTLY_PARALLEL = 3;
const FREQUENTLY_REQUEST_TIMEOUT = 180;
const FREQUENTLY_BUDGET_SECONDS = 480;
const FREQUENTLY_DETAIL_DAYS = 2;
const FREQUENTLY_DETAIL_MAX = 150;

@ini_set('max_execution_time', '0');
if (function_exists('set_time_limit')) {
    @set_time_limit(0);
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/**
 * Functies
 */
function frequently_base_url(): string
{
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $dir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/daedalus/frequently.php'))), '/');

    return 'http://' . $host . $dir . '/index.php';
}

/**
 * Vraagt de pagina's parallel op (hoogstens FREQUENTLY_PARALLEL tegelijk).
 *
 * @param list<array<string, mixed>> $queries
 * @return array{ok: int, failed: int, skipped: int, bodies: list<string|null>}
 */
function frequently_fetch_pages(array $queries, float $deadline): array
{
    $base = frequently_base_url();
    $result = ['ok' => 0, 'failed' => 0, 'skipped' => 0, 'bodies' => array_fill(0, count($queries), null)];
    $multi = curl_multi_init();
    $active = [];
    $next = 0;
    $total = count($queries);
    while ($next < $total || $active !== []) {
        while ($next < $total && count($active) < FREQUENTLY_PARALLEL) {
            if (microtime(true) >= $deadline) {
                $result['skipped'] += $total - $next;
                $next = $total;
                break;
            }
            $query = $queries[$next] + ['daedalus_warm' => '1'];
            $ch = curl_init($base . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => FREQUENTLY_REQUEST_TIMEOUT,
            ]);
            curl_multi_add_handle($multi, $ch);
            $active[(int) $ch] = [$ch, $next];
            $next++;
        }
        curl_multi_exec($multi, $running);
        curl_multi_select($multi, 1.0);
        while (($info = curl_multi_info_read($multi)) !== false) {
            $ch = $info['handle'];
            [, $index] = $active[(int) $ch];
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($info['result'] === CURLE_OK && $code >= 200 && $code < 300) {
                $result['ok']++;
                $result['bodies'][$index] = (string) curl_multi_getcontent($ch);
            } else {
                $result['failed']++;
            }
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
            unset($active[(int) $ch]);
        }
    }
    curl_multi_close($multi);

    return $result;
}

/**
 * Page load
 */
$startedAt = microtime(true);
$deadline = $startedAt + FREQUENTLY_BUDGET_SECONDS;
$lockPath = __DIR__ . '/cache/frequently.lock';
@mkdir(dirname($lockPath), 0775, true);
$lock = @fopen($lockPath, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo json_encode(['success' => true, 'skipped' => 'vorige run loopt nog'], JSON_UNESCAPED_UNICODE);
    exit;
}

$today = new DateTimeImmutable('today');
$thisWeek = monday_of_iso_week($today);
$weeks = [$thisWeek->format('Y-m-d'), $thisWeek->modify('+7 days')->format('Y-m-d')];
$detailDays = [];
for ($offset = 0; $offset < FREQUENTLY_DETAIL_DAYS; $offset++) {
    $detailDays[$today->modify('+' . $offset . ' days')->format('Y-m-d')] = true;
}

$summary = ['success' => true, 'recorded_at' => date('c'), 'max_age' => 540, 'companies' => []];
foreach ($companies as $company) {
    $companySummary = ['resources' => 0, 'pages' => 0, 'failed' => 0, 'skipped' => 0, 'details' => 0];
    try {
        odata_mimir_circuit_reset();
        $resourceNos = [];
        foreach (fetch_service_resources($environment, (string) $company, $auth) as $resource) {
            $no = trim((string) ($resource['No'] ?? ''));
            if ($no !== '' && !is_all_resources_selection($no)) {
                $resourceNos[] = $no;
            }
        }
        $companySummary['resources'] = count($resourceNos);
        if ($resourceNos === []) {
            $summary['companies'][$company] = $companySummary;
            continue;
        }

        // Lijst (standaardperiode) en weekkalenders per monteur, plus de aantallen in de keuzelijst.
        $queries = [['ajax' => 'resource_counts', 'company' => $company, 'resource_nos' => $resourceNos]];
        $calendarIndexes = [];
        foreach ($resourceNos as $resourceNo) {
            $queries[] = ['company' => $company, 'person' => $resourceNo];
            foreach ($weeks as $week) {
                $calendarIndexes[] = count($queries);
                $queries[] = ['ajax' => 'week_calendar', 'company' => $company, 'person' => $resourceNo, 'week' => $week];
            }
        }
        $pages = frequently_fetch_pages($queries, $deadline);

        // Details van werkorders die vandaag/morgen in een kalender staan.
        $detailNos = [];
        foreach ($calendarIndexes as $index) {
            $payload = json_decode((string) ($pages['bodies'][$index] ?? ''), true);
            foreach ((is_array($payload) ? ($payload['days'] ?? []) : []) as $day) {
                if (!isset($detailDays[(string) ($day['date'] ?? '')])) {
                    continue;
                }
                foreach ((array) ($day['workorders'] ?? []) as $workOrder) {
                    $no = trim((string) ($workOrder['no'] ?? ''));
                    if ($no !== '') {
                        $detailNos[$no] = true;
                    }
                }
            }
        }
        $detailQueries = [];
        foreach (array_slice(array_keys($detailNos), 0, FREQUENTLY_DETAIL_MAX) as $no) {
            $detailQueries[] = ['company' => $company, 'workorder' => $no];
        }
        $details = frequently_fetch_pages($detailQueries, $deadline);

        $companySummary['pages'] = $pages['ok'] + $details['ok'];
        $companySummary['failed'] = $pages['failed'] + $details['failed'];
        $companySummary['skipped'] = $pages['skipped'] + $details['skipped'];
        $companySummary['details'] = $details['ok'];
    } catch (Throwable $exception) {
        $summary['success'] = false;
        $companySummary['error'] = $exception->getMessage();
    }
    $summary['companies'][$company] = $companySummary;
}
$summary['duration_seconds'] = round(microtime(true) - $startedAt, 1);

flock($lock, LOCK_UN);
fclose($lock);
echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
