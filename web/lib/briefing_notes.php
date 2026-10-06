<?php
/**
 * Aandachtspunten in de dagmail.
 *
 * Contract voor de Copilot/Power Automate-flow (zelfde drive als de servicerapporten):
 *   <$briefingNotesFolder>/<JJJJ-MM-DD>/<WO-nr>.md
 *   standaard: General/Daedalus/Aandachtspunten/2026-10-07/WO2608680.md
 *
 * Fail-soft: zonder Graph-config, bij een fout of time-out komt er niets bij en gaat de mail
 * ongewijzigd de deur uit. Per aanroep een korte timeout en per run (PHP-proces) een totaalbudget;
 * na een fout op map-/tokenniveau wordt de lookup voor de rest van de run overgeslagen.
 */

/**
 * Includes/requires
 */
require_once __DIR__ . '/graph_client.php';
require_once __DIR__ . '/briefing_text.php';

/**
 * Constants
 */
const BRIEFING_NOTES_DEFAULT_FOLDER = 'General/Daedalus/Aandachtspunten';
const BRIEFING_NOTES_REQUEST_TIMEOUT = 5;
const BRIEFING_NOTES_RUN_BUDGET = 20;
const BRIEFING_NOTES_MAX_BYTES = 64 * 1024;

/**
 * Functies
 */
function briefing_notes_folder(): string
{
    $value = $GLOBALS['briefingNotesFolder'] ?? null;
    $folder = is_string($value) && trim($value) !== '' ? trim($value) : BRIEFING_NOTES_DEFAULT_FOLDER;

    return trim(str_replace('\\', '/', $folder), '/');
}

function briefing_notes_path(string $day, string $workOrderNo): string
{
    return briefing_notes_folder() . '/' . $day . '/' . $workOrderNo . '.md';
}

function &briefing_notes_state(): array
{
    static $state = null;
    if ($state === null) {
        $state = ['disabled' => false, 'started_at' => 0.0, 'listings' => [], 'html' => []];
    }

    return $state;
}

function briefing_notes_reset_state(): void
{
    $state = &briefing_notes_state();
    $state = ['disabled' => false, 'started_at' => 0.0, 'listings' => [], 'html' => []];
}

function briefing_notes_run_budget(): int
{
    $value = $GLOBALS['briefingNotesBudgetSeconds'] ?? null;
    return is_numeric($value) && (int) $value > 0 ? (int) $value : BRIEFING_NOTES_RUN_BUDGET;
}

/**
 * HTML-fragmenten (al veilig gemaakt) per werkordernummer voor de opgegeven dag.
 * Werkorders zonder bestand ontbreken in het resultaat. Gooit nooit een exception.
 *
 * @param list<string> $workOrderNos
 * @return array<string, string>
 */
function briefing_attention_notes_html(array $workOrderNos, string $day): array
{
    $result = [];
    $state = &briefing_notes_state();

    try {
        if ($state['disabled'] || !graph_is_configured() || $workOrderNos === []) {
            return [];
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $day);
        if (!($date instanceof DateTimeImmutable) || $date->format('Y-m-d') !== $day) {
            return [];
        }

        if ($state['started_at'] <= 0) {
            $state['started_at'] = microtime(true);
        }
        $deadline = $state['started_at'] + briefing_notes_run_budget();
        if (microtime(true) >= $deadline - 1) {
            return [];
        }
        graph_set_deadline($deadline);

        if (!array_key_exists($day, $state['listings'])) {
            try {
                $children = graph_list_children(briefing_notes_folder() . '/' . $day, BRIEFING_NOTES_REQUEST_TIMEOUT, 2000);
            } catch (Throwable $throwable) {
                $state['disabled'] = true;
                error_log('[Daedalus] Aandachtspunten overgeslagen: ' . $throwable->getMessage());
                return [];
            }

            $files = [];
            foreach ($children ?? [] as $child) {
                $name = mb_strtolower((string) ($child['name'] ?? ''), 'UTF-8');
                if (isset($child['file']) && substr($name, -3) === '.md') {
                    $files[$name] = [
                        'id' => (string) ($child['id'] ?? ''),
                        'size' => (int) ($child['size'] ?? 0),
                        'eTag' => (string) ($child['eTag'] ?? ''),
                    ];
                }
            }
            $state['listings'][$day] = $files;
        }

        $files = $state['listings'][$day];
        foreach ($workOrderNos as $workOrderNo) {
            $workOrderNo = trim((string) $workOrderNo);
            if ($workOrderNo === '' || preg_match('/^[A-Za-z0-9_\-]{1,40}$/', $workOrderNo) !== 1) {
                continue;
            }

            $file = $files[mb_strtolower($workOrderNo, 'UTF-8') . '.md'] ?? null;
            if ($file === null || $file['id'] === '' || $file['size'] > BRIEFING_NOTES_MAX_BYTES) {
                continue;
            }

            $cacheKey = $file['id'] . '|' . $file['eTag'];
            if (!array_key_exists($cacheKey, $state['html'])) {
                try {
                    $markdown = graph_download_item($file['id'], BRIEFING_NOTES_REQUEST_TIMEOUT, BRIEFING_NOTES_MAX_BYTES);
                    if (!mb_check_encoding($markdown, 'UTF-8')) {
                        $markdown = mb_convert_encoding($markdown, 'UTF-8', 'Windows-1252');
                    }
                    $state['html'][$cacheKey] = trim(briefing_markdown_to_html($markdown));
                } catch (Throwable $throwable) {
                    error_log('[Daedalus] Aandachtspunten voor ' . $workOrderNo . ' overgeslagen: ' . $throwable->getMessage());
                    $state['html'][$cacheKey] = '';
                    if ($throwable->getCode() === GRAPH_ERR_BUDGET) {
                        break;
                    }
                }
            }

            if ($state['html'][$cacheKey] !== '') {
                $result[$workOrderNo] = $state['html'][$cacheKey];
            }
        }
    } catch (Throwable $throwable) {
        $state['disabled'] = true;
        error_log('[Daedalus] Aandachtspunten overgeslagen: ' . $throwable->getMessage());
    } finally {
        graph_set_deadline(0);
    }

    return $result;
}
