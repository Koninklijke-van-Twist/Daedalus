<?php
// Achtergrondscript: Mímir-verzoeken zonder "interactive" (lange slotwacht).
defined('DAEDALUS_MIMIR_BACKGROUND') || define('DAEDALUS_MIMIR_BACKGROUND', true);
/**
 * Elke 10 minuten (GET, vanaf localhost, zoals hourly/nightly). Houdt de Mímir-cache warm
 * voor wat live pagina's vragen, alleen voor actieve monteurs (werkorder in vorige, huidige of
 * volgende week): lijst (standaardperiode), aantallen, weekkalender van die drie weken en
 * werkorderdetails van vandaag en morgen. Andere weken haalt een live pagina normaal op.
 * Gaat verder waar de vorige run stopte (cache/frequently-state.json).
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
const FREQUENTLY_REQUEST_TIMEOUT = 60;
const FREQUENTLY_BUDGET_SECONDS = 470;
const FREQUENTLY_LOCK_STALE_SECONDS = 900;
const FREQUENTLY_DETAIL_DAYS = 2;
const FREQUENTLY_DETAIL_MAX = 150;
const FREQUENTLY_RESOURCE_BATCH = 6;
const FREQUENTLY_SLOWEST = 10;

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
 * Houdt de traagste verzoeken bij (duur + Server-Timing).
 */
function frequently_note_request(array $query, float $seconds, string $serverTiming, bool $failed): void
{
    $slowest = &$GLOBALS['FREQUENTLY_SLOWEST_REQUESTS'];
    unset($query['daedalus_warm']);
    if (isset($query['resource_nos'])) {
        $query['resource_nos'] = count((array) $query['resource_nos']) . ' monteurs';
    }
    $slowest[] = [
        'seconds' => round($seconds, 1),
        'failed' => $failed,
        'query' => http_build_query($query),
        'server_timing' => $serverTiming,
    ];
    usort($slowest, static fn(array $a, array $b): int => $b['seconds'] <=> $a['seconds']);
    $slowest = array_slice($slowest, 0, FREQUENTLY_SLOWEST);
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
    $timingHeaders = [];
    $next = 0;
    $total = count($queries);
    while ($next < $total || $active !== []) {
        while ($next < $total && count($active) < FREQUENTLY_PARALLEL) {
            $remaining = (int) floor($deadline - microtime(true));
            if ($remaining < 1) {
                $result['skipped'] += $total - $next;
                $next = $total;
                break;
            }
            $query = $queries[$next] + ['daedalus_warm' => '1'];
            $ch = curl_init($base . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => min(FREQUENTLY_REQUEST_TIMEOUT, $remaining),
                CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$timingHeaders): int {
                    if (stripos($line, 'Server-Timing:') === 0) {
                        $timingHeaders[(int) $handle] = trim(substr($line, 14));
                    }
                    return strlen($line);
                },
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
            $failed = !($info['result'] === CURLE_OK && $code >= 200 && $code < 300);
            if (!$failed) {
                $result['ok']++;
                $result['bodies'][$index] = (string) curl_multi_getcontent($ch);
            } else {
                $result['failed']++;
            }
            frequently_note_request($queries[$index], (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME), (string) ($timingHeaders[(int) $ch] ?? ''), $failed);
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
            unset($active[(int) $ch], $timingHeaders[(int) $ch]);
        }
    }
    curl_multi_close($multi);

    return $result;
}

/**
 * Actieve monteurs: werkorder in [$rangeStart, $rangeEnd]. Geeft per monteur de werkordernummers
 * die vandaag of morgen lopen (voor de details). Limiet = resterend budget.
 *
 * @return array<string, list<string>>|null null als de query mislukte
 */
function frequently_active_resources(string $environment, string $company, array $auth, DateTimeImmutable $rangeStart, DateTimeImmutable $rangeEnd, string $detailFrom, string $detailTo, float $deadline): ?array
{
    $fetch = static function (string $dateFilter) use ($environment, $company, $auth): array {
        $url = odata_company_url($environment, $company, 'AppWerkorders', [
            '$select' => 'No,Resource_No,Start_Date,End_Date',
            '$filter' => $dateFilter,
        ]);
        return odata_get_all($url, $auth, odata_ttl('workorders_list'));
    };
    $GLOBALS['DAEDALUS_ODATA_PREFETCH_TIMEOUT'] = max(1, (int) floor($deadline - 5 - microtime(true)));
    try {
        odata_prefetch(static function () use ($rangeStart, $rangeEnd, $fetch): void {
            workorder_fetch_overlapping($rangeStart->format('Y-m-d'), $rangeEnd->format('Y-m-d'), $fetch);
        });
    } finally {
        unset($GLOBALS['DAEDALUS_ODATA_PREFETCH_TIMEOUT']);
    }
    if (microtime(true) >= $deadline - 5) {
        return null;
    }
    $rows = workorder_fetch_overlapping($rangeStart->format('Y-m-d'), $rangeEnd->format('Y-m-d'), $fetch);
    $detailRows = workorder_rows_overlapping($rows, $detailFrom, $detailTo);
    $result = [];
    foreach ($rows as $row) {
        $resourceNo = trim((string) ($row['Resource_No'] ?? ''));
        if ($resourceNo !== '') {
            $result[$resourceNo] = [];
        }
    }
    foreach ($detailRows as $row) {
        $resourceNo = trim((string) ($row['Resource_No'] ?? ''));
        $no = trim((string) ($row['No'] ?? ''));
        if ($resourceNo !== '' && $no !== '') {
            $result[$resourceNo][] = $no;
        }
    }
    ksort($result);

    return $result;
}

/**
 * Schrijft de (tussen)stand naar cache/frequently-last.json, zodat de voortgang ook na een kill zichtbaar is.
 */
function frequently_write_progress(array $summary, float $startedAt): void
{
    $summary['duration_seconds'] = round(microtime(true) - $startedAt, 1);
    $summary['slowest_requests'] = $GLOBALS['FREQUENTLY_SLOWEST_REQUESTS'] ?? [];
    $path = __DIR__ . '/cache/frequently-last.json';
    @file_put_contents($path . '.tmp', json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    @rename($path . '.tmp', $path);
}

function frequently_write_state(array $state): void
{
    $path = __DIR__ . '/cache/frequently-state.json';
    @file_put_contents($path . '.tmp', json_encode($state));
    @rename($path . '.tmp', $path);
}

/**
 * Pakt de lock; een lock van een vastgelopen run (ouder dan FREQUENTLY_LOCK_STALE_SECONDS) wordt vervangen.
 *
 * @return resource|false
 */
function frequently_acquire_lock(string $lockPath)
{
    $lock = @fopen($lockPath, 'c+');
    if ($lock !== false && flock($lock, LOCK_EX | LOCK_NB)) {
        ftruncate($lock, 0);
        fwrite($lock, (string) time());
        fflush($lock);
        return $lock;
    }
    $lockedAt = (int) @file_get_contents($lockPath);
    if ($lock !== false) {
        fclose($lock);
    }
    if ($lockedAt <= 0 || time() - $lockedAt < FREQUENTLY_LOCK_STALE_SECONDS) {
        return false;
    }
    // Vorige run hangt: nieuw lockbestand (nieuwe inode), de oude flock geldt dan niet meer.
    @unlink($lockPath);
    return frequently_acquire_lock($lockPath);
}

/**
 * Page load
 */
$startedAt = microtime(true);
$deadline = $startedAt + FREQUENTLY_BUDGET_SECONDS;
$GLOBALS['FREQUENTLY_SLOWEST_REQUESTS'] = [];
$lockPath = __DIR__ . '/cache/frequently.lock';
@mkdir(dirname($lockPath), 0775, true);
$lock = frequently_acquire_lock($lockPath);
if ($lock === false) {
    echo json_encode(['success' => true, 'skipped' => 'vorige run loopt nog'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Vorige, huidige en volgende week: exact de week-queries van de live weekkalender.
$today = new DateTimeImmutable('today');
$thisWeek = monday_of_iso_week($today);
$weeks = [];
foreach (['-7 days', '+0 days', '+7 days'] as $shift) {
    $weeks[] = $thisWeek->modify($shift)->format('Y-m-d');
}
$activeFrom = $thisWeek->modify('-7 days');
$activeTo = $thisWeek->modify('+13 days');
$detailFrom = $today->format('Y-m-d');
$detailTo = $today->modify('+' . (FREQUENTLY_DETAIL_DAYS - 1) . ' days')->format('Y-m-d');

// Hervatten: bedrijf + positie in de monteurslijst van de vorige run.
$companyList = array_values(array_map('strval', $companies));
$state = json_decode((string) @file_get_contents(__DIR__ . '/cache/frequently-state.json'), true);
$state = is_array($state) ? $state : [];
$startIndex = array_search((string) ($state['company'] ?? ''), $companyList, true);
$startIndex = $startIndex === false ? 0 : (int) $startIndex;
$resumeOffset = (int) ($state['offset'] ?? 0);

$summary = ['success' => true, 'finished' => false, 'recorded_at' => date('c'), 'max_age' => 540, 'weeks' => $weeks, 'companies' => []];
frequently_write_progress($summary, $startedAt);
$companyCount = count($companyList);
for ($step = 0; $step < $companyCount; $step++) {
    $company = $companyList[($startIndex + $step) % $companyCount];
    $offset = $step === 0 ? $resumeOffset : 0;
    if (microtime(true) >= $deadline - 5) {
        break;
    }
    $companySummary = ['active_resources' => 0, 'from_offset' => $offset, 'pages' => 0, 'failed' => 0, 'skipped' => 0, 'details' => 0, 'timing' => []];
    try {
        odata_mimir_circuit_reset();
        $stepAt = microtime(true);
        $active = frequently_active_resources($environment, $company, $auth, $activeFrom, $activeTo, $detailFrom, $detailTo, $deadline);
        $companySummary['timing']['actieve_monteurs_s'] = round(microtime(true) - $stepAt, 1);
        if ($active === null) {
            $companySummary['error'] = 'actieve monteurs niet opgehaald';
            $summary['companies'][$company] = $companySummary;
            frequently_write_state(['company' => $company, 'offset' => $offset]);
            break;
        }
        $resourceNos = array_keys($active);
        $companySummary['active_resources'] = count($resourceNos);
        $offset = $offset < count($resourceNos) ? $offset : 0;

        $stepAt = microtime(true);
        $queries = [];
        if ($offset === 0) {
            // Aantallen in de keuzelijst: zelfde lijst monteurs als de live pagina.
            $allNos = [];
            foreach (fetch_service_resources($environment, $company, $auth) as $resource) {
                $no = trim((string) ($resource['No'] ?? ''));
                if ($no !== '' && !is_all_resources_selection($no)) {
                    $allNos[] = $no;
                }
            }
            if ($allNos !== []) {
                $queries[] = ['ajax' => 'resource_counts', 'company' => $company, 'resource_nos' => $allNos];
            }
        }
        $pages = frequently_fetch_pages($queries, $deadline);
        $companySummary['pages'] += $pages['ok'];
        $companySummary['failed'] += $pages['failed'];

        // Monteurs in porties; na elke portie de positie opslaan.
        while ($offset < count($resourceNos) && microtime(true) < $deadline - 5) {
            $queries = [];
            $detailNos = [];
            foreach (array_slice($resourceNos, $offset, FREQUENTLY_RESOURCE_BATCH) as $resourceNo) {
                $queries[] = ['company' => $company, 'person' => $resourceNo];
                foreach ($weeks as $week) {
                    $queries[] = ['ajax' => 'week_calendar', 'company' => $company, 'person' => $resourceNo, 'week' => $week];
                }
                foreach ($active[$resourceNo] as $no) {
                    $detailNos[$no] = true;
                }
            }
            foreach (array_slice(array_keys($detailNos), 0, FREQUENTLY_DETAIL_MAX) as $no) {
                $queries[] = ['company' => $company, 'workorder' => $no];
            }
            $pages = frequently_fetch_pages($queries, $deadline);
            $companySummary['pages'] += $pages['ok'];
            $companySummary['failed'] += $pages['failed'];
            $companySummary['skipped'] += $pages['skipped'];
            $companySummary['details'] += count($detailNos);
            if ($pages['skipped'] > 0) {
                break;
            }
            $offset += FREQUENTLY_RESOURCE_BATCH;
            $companySummary['to_offset'] = min($offset, count($resourceNos));
            $summary['companies'][$company] = $companySummary;
            frequently_write_state(['company' => $company, 'offset' => $offset]);
            frequently_write_progress($summary, $startedAt);
        }
        $companySummary['timing']['pagina_s'] = round(microtime(true) - $stepAt, 1);
        if ($offset >= count($resourceNos)) {
            $companySummary['done'] = true;
            frequently_write_state(['company' => $companyList[($startIndex + $step + 1) % $companyCount], 'offset' => 0]);
        }
    } catch (Throwable $exception) {
        $summary['success'] = false;
        $companySummary['error'] = $exception->getMessage();
    }
    $summary['companies'][$company] = $companySummary;
    frequently_write_progress($summary, $startedAt);
    if (empty($companySummary['done'])) {
        break;
    }
}
$summary['finished'] = true;
$summary['duration_seconds'] = round(microtime(true) - $startedAt, 1);
frequently_write_progress($summary, $startedAt);

flock($lock, LOCK_UN);
fclose($lock);
$summary['slowest_requests'] = $GLOBALS['FREQUENTLY_SLOWEST_REQUESTS'];
echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
