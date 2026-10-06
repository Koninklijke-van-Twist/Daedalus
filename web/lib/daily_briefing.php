<?php
/**
 * Logica voor het read-only endpoint web/api/daily_briefing.php (Copilot-MVP "aandachtspunten").
 *
 * Vereist dat api_send_email_notifications.php als bibliotheek geladen is
 * (DAEDALUS_EMAIL_NOTIFICATIONS_LIB_ONLY), zodat dezelfde BC-functies als de dagmail
 * gebruikt worden (AppResource/AppUserSetup-koppeling, AppWerkorders per resource per dag).
 * Leest alleen; schrijft niets naar BC of SharePoint.
 */

/**
 * Includes/requires
 */
require_once __DIR__ . '/briefing_reports.php';
require_once __DIR__ . '/briefing_notes.php';

/**
 * Constants
 */
const DAILY_BRIEFING_TIMEZONE = 'Europe/Amsterdam';
const DAILY_BRIEFING_DEFAULT_COMPANIES = ['Koninklijke van Twist', 'Hunter van Twist', 'KVT Gas'];
const DAILY_BRIEFING_DEFAULT_HISTORY = 5;
const DAILY_BRIEFING_MAX_HISTORY = 10;
const DAILY_BRIEFING_DEFAULT_BUDGET = 40;
const DAILY_BRIEFING_REQUEST_TIMEOUT = 10;
const DAILY_BRIEFING_WORKORDER_FIELDS = 'No,Task_Code,Task_Description,Status,Main_Entity_Description,Component_No,Component_Description,Start_Date,Start_Time,End_Time,Resource_No';

/**
 * Functies
 */

/**
 * @return list<string>
 */
function daily_briefing_api_keys(): array
{
    $configured = $GLOBALS['briefingApiKeys'] ?? [];
    if (is_string($configured)) {
        $configured = [$configured];
    }
    if (!is_array($configured)) {
        return [];
    }

    $keys = [];
    foreach ($configured as $key) {
        if (is_string($key) && trim($key) !== '') {
            $keys[] = trim($key);
        }
    }

    return $keys;
}

/**
 * @param list<string> $keys
 */
function daily_briefing_is_authorized(string $providedKey, array $keys): bool
{
    $providedKey = trim($providedKey);
    if ($providedKey === '' || $keys === []) {
        return false;
    }

    $authorized = false;
    foreach ($keys as $key) {
        // Geen early return: elke sleutel wordt met hash_equals vergeleken.
        if (hash_equals($key, $providedKey)) {
            $authorized = true;
        }
    }

    return $authorized;
}

function daily_briefing_today(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone(DAILY_BRIEFING_TIMEZONE)))->format('Y-m-d');
}

function daily_briefing_parse_date(string $value): ?string
{
    $value = trim($value);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone(DAILY_BRIEFING_TIMEZONE));
    if (!($date instanceof DateTimeImmutable) || $date->format('Y-m-d') !== $value) {
        return null;
    }

    return $value;
}

/**
 * Robuuste vergelijking van BC-optiewaarden: Mímir geeft Nederlandse labels terug
 * ('Geannuleerd', 'Persoon'), direct BC Engelse ('Cancelled', 'Person'). Hoofdletters,
 * spaties en leestekens tellen niet mee.
 *
 * @param list<string> $candidates
 */
function daily_briefing_option_matches($value, array $candidates): bool
{
    $normalize = static function ($input): string {
        $text = mb_strtolower(trim((string) $input), 'UTF-8');
        return preg_replace('/[^\p{L}\p{N}]+/u', '', $text) ?? $text;
    };

    $normalizedValue = $normalize($value);
    if ($normalizedValue === '') {
        return false;
    }

    foreach ($candidates as $candidate) {
        if ($normalize($candidate) === $normalizedValue) {
            return true;
        }
    }

    return false;
}

function daily_briefing_is_cancelled(array $workOrder): bool
{
    return daily_briefing_option_matches($workOrder['Status'] ?? '', ['Cancelled', 'Canceled', 'Geannuleerd']);
}

/**
 * @return list<string>
 */
function daily_briefing_companies(): array
{
    $configured = $GLOBALS['briefingCompanies'] ?? null;
    if (is_array($configured)) {
        $companies = array_values(array_filter(array_map(static fn($c): string => trim((string) $c), $configured), static fn(string $c): bool => $c !== ''));
        if ($companies !== []) {
            return $companies;
        }
    }

    return DAILY_BRIEFING_DEFAULT_COMPANIES;
}

function daily_briefing_config_int(string $name, int $default, int $min, int $max): int
{
    $value = $GLOBALS[$name] ?? null;
    if (!is_numeric($value)) {
        return $default;
    }

    return max($min, min($max, (int) $value));
}

/**
 * Alle (niet-geannuleerde) werkorders van een bedrijf op een dag, uit AppWerkorders
 * (niet de kaartpagina Werkorders: die lockt/geeft 409 bij lezen).
 */
function daily_briefing_fetch_company_workorders(string $environment, string $company, string $day, array $auth): array
{
    $url = odata_company_url($environment, $company, 'AppWerkorders', [
        '$select' => DAILY_BRIEFING_WORKORDER_FIELDS,
        '$filter' => 'Start_Date ge ' . $day . ' and Start_Date le ' . $day,
        '$orderby' => 'Start_Date asc,Start_Time asc,No asc',
    ]);

    return odata_get_all($url, $auth, odata_ttl('workorders_list'));
}

/**
 * Vaste foutmelding voor de JSON-output; de exceptiontekst gaat alleen naar de serverlog.
 */
function daily_briefing_safe_message(Throwable $throwable): string
{
    // Details (kunnen response-bodies van BC bevatten) alleen in de serverlog, nooit in de JSON.
    // Ook in de log: geen querystrings/inloggegevens uit URLs, en ingekort.
    $detail = preg_replace('#(https?://)(?:[^/\s@]+@)?([^/\s?]+)([^\s?]*)\?\S*#i', '$1$2$3?[…]', $throwable->getMessage()) ?? '';
    $detail = preg_replace('#(https?://)[^/\s@]+@#i', '$1', $detail) ?? '';
    error_log('[Daedalus] daily_briefing BC-fout (' . get_class($throwable) . '): ' . mb_substr($detail, 0, 500, 'UTF-8'));

    return 'ophalen van gegevens is mislukt.';
}

/**
 * Dezelfde monteurs als de dagmail: gebruikers in web/cache/users met notificaties én dagoverzicht aan,
 * waarvan de resource een actieve persoon is die bij hun e-mailadres hoort.
 *
 * @return list<array{resource_no: string, name: string, email: string, company: string, workorders: ?array, error: ?string}>
 */
function daily_briefing_subscriber_resources(string $environment, array $auth, ?string $companyFilter, string $day): array
{
    $resources = [];
    foreach (list_user_cache_files() as $userFile) {
        $email = email_from_user_cache_path($userFile);
        if ($email === '' || $email === 'onbekend') {
            continue;
        }

        $payload = read_user_payload($userFile);
        $settings = normalize_notification_settings(is_array($payload['notification_settings'] ?? null) ? $payload['notification_settings'] : []);
        if (empty($settings['enabled']) || empty($settings['daily_overview_enabled'])) {
            continue;
        }

        $company = trim((string) ($settings['company'] ?? ''));
        $resourceNo = trim((string) ($settings['resource_no'] ?? ''));
        if ($company === '' || $resourceNo === '' || ($companyFilter !== null && $company !== $companyFilter)) {
            continue;
        }

        $key = $company . '|' . $resourceNo . '|' . $email;
        if (isset($resources[$key])) {
            continue;
        }

        try {
            $serviceResource = resolve_notification_service_resource($environment, $company, $email, $resourceNo, $auth);
            if (empty($serviceResource)) {
                continue;
            }

            $workOrders = fetch_workorders_for_resource_on_day($environment, $company, $resourceNo, $day, $auth, ['Component_No']);
            $resources[$key] = [
                'resource_no' => $resourceNo,
                'name' => trim((string) ($serviceResource['Name'] ?? $settings['resource_name'] ?? '')),
                'email' => $email,
                'company' => $company,
                'workorders' => $workOrders,
                'error' => null,
            ];
        } catch (Throwable $throwable) {
            $resources[$key] = [
                'resource_no' => $resourceNo,
                'name' => trim((string) ($settings['resource_name'] ?? '')),
                'email' => $email,
                'company' => $company,
                'workorders' => null,
                'error' => 'Business Central niet bereikbaar: ' . daily_briefing_safe_message($throwable),
            ];
        }
    }

    return array_values($resources);
}

/**
 * Alle persoon-resources met werkorders op de dag (per bedrijf één AppWerkorders-query).
 *
 * @param list<string> $companies
 * @return array{resources: list<array>, errors: list<array{company: string, message: string}>}
 */
function daily_briefing_all_resources(string $environment, array $auth, array $companies, string $day): array
{
    $resources = [];
    $errors = [];
    foreach ($companies as $company) {
        try {
            $people = [];
            foreach (fetch_service_resources($environment, $company, $auth) as $person) {
                $people[(string) $person['No']] = $person;
            }

            $byResource = [];
            foreach (daily_briefing_fetch_company_workorders($environment, $company, $day, $auth) as $workOrder) {
                $resourceNo = trim((string) ($workOrder['Resource_No'] ?? ''));
                if ($resourceNo === '' || !isset($people[$resourceNo])) {
                    continue;
                }
                $byResource[$resourceNo][] = $workOrder;
            }

            foreach ($byResource as $resourceNo => $workOrders) {
                $resources[] = [
                    'resource_no' => (string) $resourceNo,
                    'name' => (string) $people[$resourceNo]['Name'],
                    'email' => normalize_email((string) ($people[$resourceNo]['E_Mail'] ?? '')),
                    'company' => $company,
                    'workorders' => $workOrders,
                    'error' => null,
                ];
            }
        } catch (Throwable $throwable) {
            $errors[] = ['company' => $company, 'message' => 'Business Central niet bereikbaar: ' . daily_briefing_safe_message($throwable)];
        }
    }

    return ['resources' => $resources, 'errors' => $errors];
}

function daily_briefing_sort_workorders(array $workOrders): array
{
    usort($workOrders, static function (array $a, array $b): int {
        return [
            (string) ($a['Start_Date'] ?? ''),
            format_workorder_time_value((string) ($a['Start_Time'] ?? '')),
            (string) ($a['No'] ?? ''),
        ] <=> [
            (string) ($b['Start_Date'] ?? ''),
            format_workorder_time_value((string) ($b['Start_Time'] ?? '')),
            (string) ($b['No'] ?? ''),
        ];
    });

    return $workOrders;
}

/**
 * Namen die uit rapporttekst moeten worden gefilterd: monteurs in BC + engineers uit de index.
 *
 * @param list<string> $companies
 * @return list<string>
 */
function daily_briefing_known_names(string $environment, array $auth, array $companies, array $index, array $resources): array
{
    $names = [];
    foreach ($resources as $resource) {
        if ((string) ($resource['name'] ?? '') !== '') {
            $names[(string) $resource['name']] = true;
        }
    }

    foreach ($companies as $company) {
        try {
            foreach (fetch_service_resources($environment, $company, $auth) as $person) {
                $names[(string) $person['Name']] = true;
            }
        } catch (Throwable $throwable) {
            // Alleen een vangnet; het generieke filter blijft actief.
        }
    }

    foreach ((is_array($index['engineers'] ?? null) ? $index['engineers'] : []) as $engineer) {
        $names[(string) $engineer] = true;
    }

    return array_keys($names);
}

/**
 * Bouwt de volledige JSON-payload (zonder auth-check).
 *
 * @param array{date: string, scope: string, company: ?string, history: int} $options
 * @return array{status: int, body: array}
 */
function daily_briefing_build(array $options): array
{
    $environment = (string) ($GLOBALS['environment'] ?? '');
    $auth = is_array($GLOBALS['auth'] ?? null) ? $GLOBALS['auth'] : [];
    $day = $options['date'];
    $companies = $options['company'] !== null ? [$options['company']] : daily_briefing_companies();
    $started = microtime(true);
    $budget = daily_briefing_config_int('briefingTimeBudgetSeconds', DAILY_BRIEFING_DEFAULT_BUDGET, 5, 240);
    @set_time_limit($budget + 60);

    $errors = [];
    if ($options['scope'] === 'all') {
        $collected = daily_briefing_all_resources($environment, $auth, $companies, $day);
        $resources = $collected['resources'];
        $errors = $collected['errors'];
    } else {
        $resources = daily_briefing_subscriber_resources($environment, $auth, $options['company'], $day);
    }

    // Business Central helemaal onbereikbaar → 502. scope=all: elk gevraagd bedrijf faalde;
    // scope=subscribers: voor elke monteur faalde BC. Gedeeltelijke fouten blijven 200.
    if ($resources === [] && $errors !== [] && count($errors) === count($companies)) {
        return ['status' => 502, 'body' => ['ok' => false, 'error' => 'Business Central niet bereikbaar.', 'errors' => $errors]];
    }
    if ($options['scope'] !== 'all' && $resources !== []) {
        $failed = array_values(array_filter($resources, static fn(array $resource): bool => $resource['error'] !== null));
        if (count($failed) === count($resources)) {
            return ['status' => 502, 'body' => ['ok' => false, 'error' => 'Business Central niet bereikbaar.', 'errors' => array_map(
                static fn(array $resource): array => ['company' => $resource['company'], 'resource_no' => $resource['resource_no'], 'message' => (string) $resource['error']],
                $failed
            )]];
        }
    }

    $sharepoint = [
        'configured' => graph_is_configured(),
        'index_status' => 'not_configured',
        'index_message' => null,
        'pdf_fallback' => briefing_pdftotext_path() !== '',
    ];
    $index = null;
    if ($sharepoint['configured']) {
        graph_set_deadline($started + $budget);
        try {
            $loaded = briefing_load_index(DAILY_BRIEFING_REQUEST_TIMEOUT);
            $index = $loaded['index'];
            $sharepoint['index_status'] = $loaded['status'];
            $sharepoint['index_message'] = $loaded['message'] !== '' ? $loaded['message'] : null;
        } catch (Throwable $throwable) {
            $sharepoint['index_status'] = briefing_is_budget_error($throwable) ? 'budget_exceeded' : 'error';
            $sharepoint['index_message'] = $throwable->getMessage();
        }
    }

    $knownNames = $index !== null ? daily_briefing_known_names($environment, $auth, $companies, $index, $resources) : [];
    $historyByEquipment = [];
    $output = [];

    usort($resources, static fn(array $a, array $b): int => [$a['company'], mb_strtolower($a['name'], 'UTF-8'), $a['resource_no']] <=> [$b['company'], mb_strtolower($b['name'], 'UTF-8'), $b['resource_no']]);
    foreach ($resources as $resource) {
        $resourceOut = [
            'resource_no' => $resource['resource_no'],
            'name' => $resource['name'],
            'email' => $resource['email'],
            'company' => $resource['company'],
            'workorders' => [],
        ];
        if ($resource['error'] !== null) {
            $resourceOut['error'] = $resource['error'];
        }

        foreach (daily_briefing_sort_workorders(is_array($resource['workorders']) ? $resource['workorders'] : []) as $workOrder) {
            $workOrderNo = trim((string) ($workOrder['No'] ?? ''));
            if ($workOrderNo === '' || daily_briefing_is_cancelled($workOrder)) {
                continue;
            }

            $componentNo = trim((string) ($workOrder['Component_No'] ?? ''));
            if ($index === null) {
                $history = [
                    'status' => $sharepoint['configured'] ? 'error' : 'not_configured',
                    'message' => $sharepoint['configured'] ? $sharepoint['index_message'] : 'SharePoint (Microsoft Graph) is niet geconfigureerd.',
                    'history' => [],
                    'has_notes' => false,
                ];
            } else {
                $memoKey = briefing_normalize_equipment_no($componentNo);
                if (!isset($historyByEquipment[$memoKey])) {
                    $historyByEquipment[$memoKey] = briefing_history_for_equipment($index, $componentNo, $day, $options['history'], $knownNames, DAILY_BRIEFING_REQUEST_TIMEOUT);
                }
                $history = $historyByEquipment[$memoKey];
            }

            $resourceOut['workorders'][] = [
                'no' => $workOrderNo,
                'task_code' => trim((string) ($workOrder['Task_Code'] ?? '')),
                'task' => workorder_task_text($workOrder),
                'status' => trim((string) ($workOrder['Status'] ?? '')),
                'start_date' => workorder_day_key((string) ($workOrder['Start_Date'] ?? '')),
                'start_time' => format_workorder_time_value((string) ($workOrder['Start_Time'] ?? '')),
                'end_time' => format_workorder_time_value((string) ($workOrder['End_Time'] ?? '')),
                'main_entity_description' => trim((string) ($workOrder['Main_Entity_Description'] ?? '')),
                'component_no' => $componentNo,
                'component_description' => trim((string) ($workOrder['Component_Description'] ?? '')),
                'link' => workorder_link($resource['company'], $resource['resource_no'], $workOrderNo),
                'history_status' => $history['status'],
                'history_message' => $history['message'],
                'has_notes' => $history['has_notes'],
                'history' => $history['history'],
            ];
        }

        $output[] = $resourceOut;
    }
    graph_set_deadline(0);

    $body = [
        'ok' => true,
        'date' => $day,
        'date_label' => briefing_nl_date_label($day),
        'timezone' => DAILY_BRIEFING_TIMEZONE,
        'generated_at' => (new DateTimeImmutable('now', new DateTimeZone(DAILY_BRIEFING_TIMEZONE)))->format(DATE_ATOM),
        'scope' => $options['scope'],
        'notes_path' => briefing_notes_folder() . '/' . $day . '/<WO-nr>.md',
        'sharepoint' => $sharepoint,
        'resources' => $output,
    ];
    if ($errors !== []) {
        $body['errors'] = $errors;
    }

    return ['status' => 200, 'body' => $body];
}

/**
 * Volledige request-afhandeling: methode, API-key, parameters, payload.
 *
 * @return array{status: int, body: array, headers: array<string, string>}
 */
function daily_briefing_handle(array $server, array $query): array
{
    $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
    if ($method !== 'GET') {
        return ['status' => 405, 'body' => ['ok' => false, 'error' => 'Alleen GET is toegestaan.'], 'headers' => ['Allow' => 'GET']];
    }

    if (!daily_briefing_is_authorized((string) ($server['HTTP_X_API_KEY'] ?? ''), daily_briefing_api_keys())) {
        return ['status' => 401, 'body' => ['ok' => false, 'error' => 'Ongeldige of ontbrekende API-key.'], 'headers' => []];
    }

    $dateParam = trim((string) ($query['date'] ?? ''));
    $date = $dateParam === '' ? daily_briefing_today() : daily_briefing_parse_date($dateParam);
    if ($date === null) {
        return ['status' => 400, 'body' => ['ok' => false, 'error' => 'Ongeldige datum; gebruik JJJJ-MM-DD.'], 'headers' => []];
    }

    $scope = strtolower(trim((string) ($query['scope'] ?? 'subscribers')));
    if (!in_array($scope, ['subscribers', 'all'], true)) {
        return ['status' => 400, 'body' => ['ok' => false, 'error' => 'Ongeldige scope; gebruik subscribers of all.'], 'headers' => []];
    }

    $company = trim((string) ($query['company'] ?? ''));
    if ($company !== '' && !in_array($company, daily_briefing_companies(), true)) {
        return ['status' => 400, 'body' => ['ok' => false, 'error' => 'Onbekend bedrijf.'], 'headers' => []];
    }

    $historyParam = trim((string) ($query['history'] ?? ''));
    $history = DAILY_BRIEFING_DEFAULT_HISTORY;
    if ($historyParam !== '') {
        if (!ctype_digit($historyParam) || (int) $historyParam < 1 || (int) $historyParam > DAILY_BRIEFING_MAX_HISTORY) {
            return ['status' => 400, 'body' => ['ok' => false, 'error' => 'history moet tussen 1 en ' . DAILY_BRIEFING_MAX_HISTORY . ' liggen.'], 'headers' => []];
        }
        $history = (int) $historyParam;
    }

    try {
        $result = daily_briefing_build([
            'date' => $date,
            'scope' => $scope,
            'company' => $company !== '' ? $company : null,
            'history' => $history,
        ]);
    } catch (Throwable $throwable) {
        error_log('[Daedalus] daily_briefing mislukt: ' . $throwable->getMessage());
        return ['status' => 500, 'body' => ['ok' => false, 'error' => 'Interne fout bij het samenstellen van de briefing.'], 'headers' => []];
    }

    return $result + ['headers' => []];
}
