<?php
/**
 * Eerdere servicerapporten per object (Equipmentnr = AppWerkorders.Component_No) uit SharePoint.
 *
 * Bron (site KVTAlgemeen, standaard documentbibliotheek "Gedeelde documenten"):
 * - Index: General/servicerapporten.xlsx, blad "Servicerapporten", kolommen o.a.
 *   Equipmentnr, Werkordernr, Datum (Excel-datum), Engineer, Equipment URL (map van het object).
 * - Per object: General/Equipments/<Equipmentnr> - <naam>/01 Service Rapporten Excel/*.xlsm
 *   en .../00 Service Rapporten PDF/*.pdf. Bestandsnaam bevat het werkordernummer.
 *
 * Uit een xlsm worden ALLEEN de benoemde bereiken "opmerkingen" en "storingsdiagnose" gelezen.
 * Zonder xlsm: pdftotext (als beschikbaar) en alleen de secties Opmerkingen/Storingsdiagnose/Advies.
 * Alles gaat daarna door briefing_scrub_pii().
 */

/**
 * Includes/requires
 */
require_once __DIR__ . '/briefing_cache.php';
require_once __DIR__ . '/graph_client.php';
require_once __DIR__ . '/xlsx_reader.php';
require_once __DIR__ . '/briefing_text.php';

/**
 * Constants
 */
const BRIEFING_INDEX_DEFAULT_PATH = 'General/servicerapporten.xlsx';
const BRIEFING_INDEX_CHECK_SECONDS = 1800;
const BRIEFING_FOLDER_TTL = 86400;
const BRIEFING_REPORT_LIST_TTL = 21600;
const BRIEFING_REPORT_LIST_REFRESH_AFTER = 900;
const BRIEFING_TEXT_TTL = 90 * 86400;
const BRIEFING_MAX_REPORT_BYTES = 12 * 1024 * 1024;
const BRIEFING_PDFTOTEXT_TIMEOUT = 10;
const BRIEFING_EXTRACT_VERSION = 1;

/**
 * Functies
 */
function briefing_config_string(string $name, string $default = ''): string
{
    $value = $GLOBALS[$name] ?? null;
    return is_string($value) && trim($value) !== '' ? trim($value) : $default;
}

function briefing_index_path(): string
{
    return trim(briefing_config_string('briefingIndexPath', BRIEFING_INDEX_DEFAULT_PATH), '/');
}

/**
 * Map van het object relatief aan de drive-root, uit de "Equipment URL" van de index, bijv.
 *   https://kvtnl.sharepoint.com/sites/KVTAlgemeen/Gedeelde%20documenten/General/Equipments/10002378 - NSA X
 *   → General/Equipments/10002378 - NSA X
 */
function briefing_folder_from_url(string $url, string $sitePath): string
{
    $path = (string) (parse_url(trim($url), PHP_URL_PATH) ?? '');
    if ($path === '') {
        return '';
    }

    $segments = array_values(array_filter(
        array_map('rawurldecode', explode('/', $path)),
        static fn(string $segment): bool => $segment !== ''
    ));
    $siteSegments = array_values(array_filter(explode('/', $sitePath), static fn(string $segment): bool => $segment !== ''));

    $matchesSite = count($segments) > count($siteSegments) + 1;
    foreach ($siteSegments as $index => $siteSegment) {
        if (!isset($segments[$index]) || mb_strtolower($segments[$index], 'UTF-8') !== mb_strtolower($siteSegment, 'UTF-8')) {
            $matchesSite = false;
            break;
        }
    }

    if ($matchesSite) {
        // Na de site volgt de bibliotheek ("Gedeelde documenten"); de rest is het pad in de drive.
        return implode('/', array_slice($segments, count($siteSegments) + 1));
    }

    foreach ($segments as $index => $segment) {
        if (mb_strtolower($segment, 'UTF-8') === 'general') {
            return implode('/', array_slice($segments, $index));
        }
    }

    return '';
}

function briefing_normalize_equipment_no(string $value): string
{
    $value = trim($value);
    if (preg_match('/^\d+\.0+$/', $value) === 1) {
        $value = substr($value, 0, (int) strpos($value, '.'));
    }

    return mb_strtoupper($value, 'UTF-8');
}

/**
 * Parseert servicerapporten.xlsx naar een compacte index.
 *
 * @return array{equipments: array<string, array{f: list<string>, r: list<array{0: string, 1: string, 2: int}>}>, engineers: list<string>, rows: int}
 */
function briefing_parse_index_file(string $path, string $sitePath): array
{
    $columns = null;
    $equipments = [];
    $engineers = [];
    $rows = 0;

    xlsx_each_row($path, 'Servicerapporten', static function (array $cells) use (&$columns, &$equipments, &$engineers, &$rows, $sitePath) {
        if ($columns === null) {
            $map = [];
            foreach ($cells as $column => $value) {
                $map[mb_strtolower(trim((string) $value), 'UTF-8')] = $column;
            }
            if (isset($map['equipmentnr'], $map['werkordernr'], $map['datum'])) {
                $columns = [
                    'equipment' => $map['equipmentnr'],
                    'workorder' => $map['werkordernr'],
                    'date' => $map['datum'],
                    'url' => $map['equipment url'] ?? null,
                    'engineer' => $map['engineer'] ?? null,
                ];
            }
            return null;
        }

        $equipmentNo = briefing_normalize_equipment_no((string) ($cells[$columns['equipment']] ?? ''));
        $workOrderNo = trim((string) ($cells[$columns['workorder']] ?? ''));
        $date = xlsx_date_to_ymd((string) ($cells[$columns['date']] ?? ''));
        if ($equipmentNo === '' || $workOrderNo === '' || $date === '') {
            return null;
        }

        $folder = $columns['url'] !== null ? briefing_folder_from_url((string) ($cells[$columns['url']] ?? ''), $sitePath) : '';
        if (!isset($equipments[$equipmentNo])) {
            $equipments[$equipmentNo] = ['f' => [], 'r' => []];
        }
        $folderIndex = -1;
        if ($folder !== '') {
            $folderIndex = array_search($folder, $equipments[$equipmentNo]['f'], true);
            if ($folderIndex === false) {
                $equipments[$equipmentNo]['f'][] = $folder;
                $folderIndex = count($equipments[$equipmentNo]['f']) - 1;
            }
        }
        $equipments[$equipmentNo]['r'][] = [$date, $workOrderNo, (int) $folderIndex];

        if ($columns['engineer'] !== null) {
            $engineer = trim(preg_replace('/\s+\d+\s*$/', '', (string) ($cells[$columns['engineer']] ?? '')) ?? '');
            if ($engineer !== '' && mb_strlen($engineer) <= 80) {
                $engineers[$engineer] = true;
            }
        }
        $rows++;
        return null;
    });

    if ($columns === null) {
        throw new RuntimeException('Index servicerapporten.xlsx heeft niet de verwachte kolommen (Equipmentnr, Werkordernr, Datum).');
    }

    return ['equipments' => $equipments, 'engineers' => array_keys($engineers), 'rows' => $rows];
}

function &briefing_index_memory(): array
{
    static $memory = [];
    return $memory;
}

/**
 * Laadt de index (cache; elke 30 min een goedkope eTag-check; opnieuw downloaden alleen als die wijzigt).
 * Bij een Graph-fout wordt een oudere cache gebruikt (status 'stale').
 *
 * @return array{status: string, message: string, index: array}
 */
function briefing_load_index(int $timeout = GRAPH_DEFAULT_TIMEOUT): array
{
    $memory = &briefing_index_memory();
    if (isset($memory['result'])) {
        return $memory['result'];
    }

    $config = graph_config();
    $cacheKey = 'sp_index|' . $config['site_hostname'] . '|' . strtolower($config['site_path']) . '|' . graph_normalize_name($config['drive_name']) . '|' . briefing_index_path();
    $entry = briefing_cache_read_entry($cacheKey);
    $cached = is_array($entry['data'] ?? null) ? $entry['data'] : null;

    if ($cached !== null && (int) ($cached['checked_at'] ?? 0) > time() - BRIEFING_INDEX_CHECK_SECONDS) {
        return $memory['result'] = ['status' => 'ok', 'message' => '', 'index' => $cached];
    }

    $tmp = '';
    try {
        $item = graph_item_by_path(briefing_index_path(), $timeout);
        if ($item === null) {
            throw new RuntimeException('Index ' . briefing_index_path() . ' niet gevonden op SharePoint.');
        }

        $eTag = (string) ($item['eTag'] ?? ($item['lastModifiedDateTime'] ?? ''));
        if ($cached !== null && $eTag !== '' && $eTag === (string) ($cached['etag'] ?? '')) {
            $cached['checked_at'] = time();
            briefing_cache_set($cacheKey, $cached, 0);
            return $memory['result'] = ['status' => 'ok', 'message' => '', 'index' => $cached];
        }

        $contents = graph_download_item((string) $item['id'], max($timeout, 20), 64 * 1024 * 1024);
        $tmp = briefing_cache_write_tmp($contents, 'xlsx');
        unset($contents);
        $parsed = briefing_parse_index_file($tmp, $config['site_path']);
        $index = $parsed + ['etag' => $eTag, 'checked_at' => time(), 'loaded_at' => time()];
        briefing_cache_set($cacheKey, $index, 0);

        return $memory['result'] = ['status' => 'ok', 'message' => '', 'index' => $index];
    } catch (Throwable $throwable) {
        if ($cached !== null) {
            return $memory['result'] = [
                'status' => 'stale',
                'message' => 'Index niet ververst, oudere versie gebruikt: ' . $throwable->getMessage(),
                'index' => $cached,
            ];
        }
        throw $throwable;
    } finally {
        briefing_cache_remove_tmp($tmp);
    }
}

/**
 * Laatste $limit rapporten van een object vóór $beforeDay (nieuwste eerst), uniek per WO+datum.
 *
 * @return list<array{date: string, workorder_no: string, folder: string}>
 */
function briefing_reports_for_equipment(array $index, string $equipmentNo, string $beforeDay, int $limit): array
{
    $entry = $index['equipments'][briefing_normalize_equipment_no($equipmentNo)] ?? null;
    if (!is_array($entry)) {
        return [];
    }

    $folders = is_array($entry['f'] ?? null) ? $entry['f'] : [];
    $unique = [];
    foreach ((is_array($entry['r'] ?? null) ? $entry['r'] : []) as $row) {
        $date = (string) ($row[0] ?? '');
        $workOrderNo = (string) ($row[1] ?? '');
        if ($date === '' || $workOrderNo === '' || strcmp($date, $beforeDay) >= 0) {
            continue;
        }
        $key = mb_strtoupper($workOrderNo, 'UTF-8') . '|' . $date;
        $folderIndex = (int) ($row[2] ?? -1);
        $unique[$key] = [
            'date' => $date,
            'workorder_no' => $workOrderNo,
            'folder' => $folderIndex >= 0 ? (string) ($folders[$folderIndex] ?? '') : '',
        ];
    }

    $reports = array_values($unique);
    usort($reports, static function (array $a, array $b): int {
        return [$b['date'], $b['workorder_no']] <=> [$a['date'], $a['workorder_no']];
    });

    // Ontbrekende mapverwijzing: gebruik de laatst bekende map van dit object.
    $fallbackFolder = $folders === [] ? '' : (string) end($folders);
    foreach ($reports as &$report) {
        if ($report['folder'] === '') {
            $report['folder'] = $fallbackFolder;
        }
    }
    unset($report);

    return array_slice($reports, 0, max(0, $limit));
}

/**
 * Kinderen van een map met cache. $maxAge = maximale leeftijd van de cache in seconden.
 *
 * @return list<array<string, mixed>>|null null = map bestaat niet
 */
function briefing_cached_children(string $folderPath, int $ttl, int $timeout, int $maxAge = PHP_INT_MAX): ?array
{
    $config = graph_config();
    $cacheKey = 'sp_children|' . $config['site_hostname'] . '|' . strtolower($config['site_path']) . '|' . graph_normalize_name($config['drive_name']) . '|' . mb_strtolower($folderPath, 'UTF-8');
    $entry = briefing_cache_read_entry($cacheKey);
    if ($entry !== null && ($entry['expires_at'] === 0 || $entry['expires_at'] >= time()) && $entry['stored_at'] >= time() - $maxAge) {
        $data = $entry['data'];
        return $data === false ? null : (is_array($data) ? $data : null);
    }

    $children = graph_list_children($folderPath, $timeout);
    $compact = null;
    if ($children !== null) {
        $compact = [];
        foreach ($children as $child) {
            $compact[] = [
                'id' => (string) ($child['id'] ?? ''),
                'name' => (string) ($child['name'] ?? ''),
                'size' => (int) ($child['size'] ?? 0),
                'eTag' => (string) ($child['eTag'] ?? ''),
                'modified' => (string) ($child['lastModifiedDateTime'] ?? ''),
                'is_folder' => isset($child['folder']),
            ];
        }
    }

    briefing_cache_set($cacheKey, $compact === null ? false : $compact, $ttl);
    return $compact;
}

/**
 * @return array{excel: string, pdf: string}|null null = objectmap bestaat niet
 */
function briefing_report_subfolders(string $equipmentFolder, int $timeout): ?array
{
    $children = briefing_cached_children($equipmentFolder, BRIEFING_FOLDER_TTL, $timeout);
    if ($children === null) {
        return null;
    }

    $result = ['excel' => '', 'pdf' => ''];
    foreach ($children as $child) {
        if (empty($child['is_folder'])) {
            continue;
        }
        $name = (string) $child['name'];
        if ($result['excel'] === '' && preg_match('/^0*1\b.*excel/iu', $name) === 1) {
            $result['excel'] = $equipmentFolder . '/' . $name;
        } elseif ($result['pdf'] === '' && preg_match('/^0*0\b.*pdf/iu', $name) === 1) {
            $result['pdf'] = $equipmentFolder . '/' . $name;
        }
    }

    return $result;
}

/**
 * Kiest het rapportbestand voor een WO uit een maplijst. Voorkeur: datum (JJJJMMDD) in de naam,
 * geen kopie "(1)", daarna de meest recente wijziging.
 *
 * @param list<array<string, mixed>> $children
 * @param list<string> $extensions
 */
function briefing_match_report_file(array $children, string $workOrderNo, string $date, array $extensions): ?array
{
    $workOrderPattern = '/(?<![A-Za-z0-9])' . preg_quote($workOrderNo, '/') . '(?![0-9])/i';
    $dateToken = str_replace('-', '', $date);
    $best = null;
    $bestScore = null;

    foreach ($children as $child) {
        if (!empty($child['is_folder'])) {
            continue;
        }
        $name = (string) ($child['name'] ?? '');
        $extension = mb_strtolower((string) pathinfo($name, PATHINFO_EXTENSION), 'UTF-8');
        if (!in_array($extension, $extensions, true) || preg_match($workOrderPattern, $name) !== 1) {
            continue;
        }

        $score = [
            strpos($name, $dateToken) !== false ? 1 : 0,
            preg_match('/\(\d+\)\.[a-z0-9]+$/i', $name) === 1 ? 0 : 1,
            (string) ($child['modified'] ?? ''),
        ];
        if ($bestScore === null || ($score <=> $bestScore) > 0) {
            $best = $child;
            $bestScore = $score;
        }
    }

    return $best;
}

/**
 * Zoekt het rapportbestand; ververst de (gecachte) maplijst één keer als de WO er niet in staat.
 */
function briefing_find_report_file(string $folder, string $workOrderNo, string $date, array $extensions, int $timeout): ?array
{
    if ($folder === '') {
        return null;
    }

    $children = briefing_cached_children($folder, BRIEFING_REPORT_LIST_TTL, $timeout);
    if ($children === null) {
        return null;
    }

    $match = briefing_match_report_file($children, $workOrderNo, $date, $extensions);
    if ($match === null) {
        $fresh = briefing_cached_children($folder, BRIEFING_REPORT_LIST_TTL, $timeout, BRIEFING_REPORT_LIST_REFRESH_AFTER);
        if ($fresh !== null) {
            $match = briefing_match_report_file($fresh, $workOrderNo, $date, $extensions);
        }
    }

    return $match;
}

function briefing_pdftotext_path(): string
{
    static $resolved = null;
    if ($resolved !== null) {
        return $resolved;
    }

    $resolved = '';
    if (!function_exists('proc_open')) {
        return $resolved;
    }

    $configured = briefing_config_string('briefingPdftotextPath');
    $candidates = $configured !== '' ? [$configured] : ['/usr/bin/pdftotext', '/usr/local/bin/pdftotext', '/opt/homebrew/bin/pdftotext'];
    foreach ($candidates as $candidate) {
        if (@is_file($candidate) && @is_executable($candidate)) {
            $resolved = $candidate;
            break;
        }
    }

    return $resolved;
}

function briefing_pdf_to_text(string $pdfPath): string
{
    $binary = briefing_pdftotext_path();
    if ($binary === '') {
        throw new RuntimeException('pdftotext is niet beschikbaar.');
    }

    $process = proc_open(
        [$binary, '-enc', 'UTF-8', '-nopgbrk', '-q', $pdfPath, '-'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($process)) {
        throw new RuntimeException('pdftotext kon niet worden gestart.');
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $output = '';
    $deadline = microtime(true) + BRIEFING_PDFTOTEXT_TIMEOUT;
    $left = graph_seconds_left();
    if ($left !== null) {
        $deadline = min($deadline, microtime(true) + max(1.0, $left));
    }

    while (true) {
        $chunk = stream_get_contents($pipes[1]);
        if (is_string($chunk)) {
            $output .= $chunk;
        }
        stream_get_contents($pipes[2]);
        if (feof($pipes[1]) || strlen($output) > 4 * 1024 * 1024) {
            break;
        }
        if (microtime(true) > $deadline) {
            proc_terminate($process);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            throw new RuntimeException('pdftotext duurde te lang.');
        }
        usleep(20000);
    }

    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    return $output;
}

/**
 * Downloadt en extraheert één rapportbestand (met cache op item-id + eTag).
 *
 * @return array{opmerkingen: string, storingsdiagnose: string, source: string}
 */
function briefing_extract_report(array $file, string $type, int $timeout): array
{
    $cacheKey = 'sp_text|v' . BRIEFING_EXTRACT_VERSION . '|' . $file['id'] . '|' . ($file['eTag'] !== '' ? $file['eTag'] : $file['modified']);
    $cached = briefing_cache_get($cacheKey);
    if (is_array($cached) && isset($cached['source'])) {
        return [
            'opmerkingen' => (string) ($cached['opmerkingen'] ?? ''),
            'storingsdiagnose' => (string) ($cached['storingsdiagnose'] ?? ''),
            'source' => (string) $cached['source'],
        ];
    }

    if ((int) $file['size'] > BRIEFING_MAX_REPORT_BYTES) {
        throw new RuntimeException('Rapportbestand is te groot.');
    }

    $contents = graph_download_item((string) $file['id'], $timeout, BRIEFING_MAX_REPORT_BYTES);
    $tmp = briefing_cache_write_tmp($contents, $type === 'xlsm' ? 'xlsm' : 'pdf');
    unset($contents);

    try {
        if ($type === 'xlsm') {
            $texts = xlsx_named_range_texts($tmp, ['opmerkingen', 'storingsdiagnose']);
            $result = [
                'opmerkingen' => implode("\n", $texts['opmerkingen'] ?? []),
                'storingsdiagnose' => implode("\n", $texts['storingsdiagnose'] ?? []),
                'source' => 'xlsm',
            ];
        } else {
            $sections = briefing_pdf_sections(briefing_pdf_to_text($tmp));
            $result = $sections + ['source' => 'pdf'];
        }
    } finally {
        briefing_cache_remove_tmp($tmp);
    }

    // Al bij opslaan het generieke PII-filter toepassen (bekende namen volgen bij de output).
    $result['opmerkingen'] = briefing_scrub_pii($result['opmerkingen']);
    $result['storingsdiagnose'] = briefing_scrub_pii($result['storingsdiagnose']);
    briefing_cache_set($cacheKey, $result, BRIEFING_TEXT_TTL);

    return $result;
}

function briefing_is_budget_error(Throwable $throwable): bool
{
    return $throwable->getCode() === GRAPH_ERR_BUDGET;
}

/**
 * Tekst van één eerder rapport (xlsm, anders pdf-fallback).
 *
 * @param list<string> $knownNames
 */
function briefing_history_entry(array $report, array $knownNames, int $timeout): array
{
    $entry = [
        'date' => $report['date'],
        'date_label' => briefing_nl_date_label($report['date']),
        'workorder_no' => $report['workorder_no'],
        'source' => null,
        'opmerkingen' => '',
        'storingsdiagnose' => '',
        'has_notes' => false,
        'status' => 'not_found',
    ];

    try {
        $subfolders = briefing_report_subfolders($report['folder'], $timeout);
        if ($subfolders === null) {
            $entry['status'] = 'folder_not_found';
            return $entry;
        }

        $result = null;
        $xlsmError = null;
        $excelFile = briefing_find_report_file($subfolders['excel'], $report['workorder_no'], $report['date'], ['xlsm', 'xlsx'], $timeout);
        if ($excelFile !== null) {
            try {
                $result = briefing_extract_report($excelFile, 'xlsm', $timeout);
            } catch (Throwable $throwable) {
                if (briefing_is_budget_error($throwable)) {
                    throw $throwable;
                }
                $xlsmError = $throwable;
            }
        }

        if ($result === null) {
            $pdfFile = briefing_find_report_file($subfolders['pdf'], $report['workorder_no'], $report['date'], ['pdf'], $timeout);
            if ($pdfFile !== null) {
                if (briefing_pdftotext_path() === '') {
                    $entry['status'] = 'pdf_unavailable';
                    return $entry;
                }
                $result = briefing_extract_report($pdfFile, 'pdf', $timeout);
            } elseif ($xlsmError !== null) {
                throw $xlsmError;
            }
        }

        if ($result === null) {
            return $entry;
        }

        $entry['source'] = $result['source'];
        $entry['opmerkingen'] = briefing_scrub_pii($result['opmerkingen'], $knownNames);
        $entry['storingsdiagnose'] = briefing_scrub_pii($result['storingsdiagnose'], $knownNames);
        $entry['has_notes'] = briefing_reports_have_notes([$entry]);
        $entry['status'] = 'ok';
    } catch (Throwable $throwable) {
        $entry['status'] = briefing_is_budget_error($throwable) ? 'budget_exceeded' : 'error';
        $entry['message'] = $throwable->getMessage();
    }

    return $entry;
}

/**
 * Geschiedenis voor een object: lijst rapporten + status.
 *
 * @param list<string> $knownNames
 * @return array{status: string, message: ?string, history: list<array>, has_notes: bool}
 */
function briefing_history_for_equipment(array $index, string $equipmentNo, string $beforeDay, int $limit, array $knownNames, int $timeout): array
{
    if (trim($equipmentNo) === '') {
        return ['status' => 'no_component', 'message' => null, 'history' => [], 'has_notes' => false];
    }

    $reports = briefing_reports_for_equipment($index, $equipmentNo, $beforeDay, $limit);
    if ($reports === []) {
        return ['status' => 'no_reports', 'message' => null, 'history' => [], 'has_notes' => false];
    }

    $history = [];
    $status = 'ok';
    foreach ($reports as $report) {
        $entry = briefing_history_entry($report, $knownNames, $timeout);
        if ($entry['status'] === 'budget_exceeded') {
            $status = 'budget_exceeded';
        } elseif ($entry['status'] === 'error' && $status === 'ok') {
            $status = 'partial';
        }
        $history[] = $entry;
    }

    return [
        'status' => $status,
        'message' => $status === 'budget_exceeded' ? 'Tijdbudget op; probeer later opnieuw (resultaten worden gecachet).' : null,
        'history' => $history,
        'has_notes' => briefing_reports_have_notes($history),
    ];
}
