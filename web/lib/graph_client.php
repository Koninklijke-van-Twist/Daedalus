<?php
/**
 * Minimale, alleen-lezen Microsoft Graph-client (client credentials) voor SharePoint.
 *
 * Config (web/auth.php, niet in git):
 *   $graphTenantId, $graphClientId, $graphClientSecret
 *   $graphSiteHostname = 'kvtnl.sharepoint.com';
 *   $graphSitePath     = '/sites/KVTAlgemeen';
 *   $graphDriveName    = '';   // optioneel; leeg = standaard documentbibliotheek van de site
 *
 * De Entra-app heeft alleen Sites.Selected (application) nodig, met een read-grant op de site.
 * Alle aanroepen zijn GET (plus de token-POST naar login.microsoftonline.com).
 *
 * Tests injecteren een transport via $GLOBALS['DAEDALUS_GRAPH_TRANSPORT']:
 *   function (array $request): array
 *   $request  = ['method', 'url', 'headers' => list<string>, 'body' => ?string, 'timeout' => int]
 *   antwoord  = ['status' => int, 'headers' => array<string,string> (lowercase keys), 'body' => string, 'error' => ?string]
 */

/**
 * Includes/requires
 */
require_once __DIR__ . '/briefing_cache.php';

/**
 * Constants
 */
const GRAPH_BASE_URL = 'https://graph.microsoft.com/v1.0';
const GRAPH_ERR_BUDGET = 1001;
const GRAPH_ERR_NOT_CONFIGURED = 1002;
const GRAPH_DEFAULT_TIMEOUT = 10;
const GRAPH_MAX_DOWNLOAD_BYTES = 25 * 1024 * 1024;

/**
 * Functies
 */
function graph_starts_with(string $haystack, string $needle): bool
{
    return strncmp($haystack, $needle, strlen($needle)) === 0;
}

function graph_config(): array
{
    $read = static function (string $name, string $default = ''): string {
        $value = $GLOBALS[$name] ?? null;
        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    };

    $sitePath = '/' . trim($read('graphSitePath', '/sites/KVTAlgemeen'), '/');

    return [
        'tenant_id' => $read('graphTenantId'),
        'client_id' => $read('graphClientId'),
        'client_secret' => $read('graphClientSecret'),
        'site_hostname' => $read('graphSiteHostname', 'kvtnl.sharepoint.com'),
        'site_path' => $sitePath,
        'drive_name' => $read('graphDriveName'),
    ];
}

function graph_is_configured(): bool
{
    $config = graph_config();
    return $config['tenant_id'] !== '' && $config['client_id'] !== '' && $config['client_secret'] !== '';
}

/**
 * Deadline (unix timestamp, float) voor alle Graph-aanroepen in dit proces; 0 = geen.
 */
function &graph_deadline_ref(): float
{
    static $deadline = 0.0;
    return $deadline;
}

function graph_set_deadline(float $unixTimestamp): void
{
    $ref = &graph_deadline_ref();
    $ref = $unixTimestamp;
}

function graph_seconds_left(): ?float
{
    $deadline = graph_deadline_ref();
    if ($deadline <= 0) {
        return null;
    }

    return $deadline - microtime(true);
}

function graph_effective_timeout(int $timeout): int
{
    $left = graph_seconds_left();
    if ($left === null) {
        return max(1, $timeout);
    }

    if ($left < 1.0) {
        throw new RuntimeException('Tijdbudget voor SharePoint is op.', GRAPH_ERR_BUDGET);
    }

    return max(1, min($timeout, (int) floor($left)));
}

function graph_curl_transport(array $request): array
{
    $responseHeaders = [];
    $ch = curl_init((string) $request['url']);
    $timeout = max(1, (int) ($request['timeout'] ?? GRAPH_DEFAULT_TIMEOUT));
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CUSTOMREQUEST => strtoupper((string) ($request['method'] ?? 'GET')),
        CURLOPT_HTTPHEADER => is_array($request['headers'] ?? null) ? $request['headers'] : [],
        CURLOPT_USERAGENT => 'Daedalus-Briefing/1.0',
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return strlen($line);
        },
    ];
    if (array_key_exists('body', $request) && $request['body'] !== null) {
        $options[CURLOPT_POSTFIELDS] = (string) $request['body'];
    }
    $maxBytes = (int) ($request['max_bytes'] ?? 0);
    if ($maxBytes > 0) {
        $options[CURLOPT_NOPROGRESS] = false;
        $options[CURLOPT_PROGRESSFUNCTION] = static function ($curl, $downloadTotal, $downloaded) use ($maxBytes): int {
            return ($downloadTotal > $maxBytes || $downloaded > $maxBytes) ? 1 : 0;
        };
    }
    curl_setopt_array($ch, $options);

    $body = curl_exec($ch);
    $error = $body === false ? curl_error($ch) : null;
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'status' => $status,
        'headers' => $responseHeaders,
        'body' => is_string($body) ? $body : '',
        'error' => $error,
    ];
}

function graph_send(array $request): array
{
    $transport = $GLOBALS['DAEDALUS_GRAPH_TRANSPORT'] ?? null;
    $response = is_callable($transport) ? $transport($request) : graph_curl_transport($request);
    if (!is_array($response)) {
        $response = [];
    }

    $headers = [];
    foreach ((is_array($response['headers'] ?? null) ? $response['headers'] : []) as $name => $value) {
        $headers[strtolower((string) $name)] = is_array($value) ? (string) reset($value) : (string) $value;
    }

    return [
        'status' => (int) ($response['status'] ?? 0),
        'headers' => $headers,
        'body' => (string) ($response['body'] ?? ''),
        'error' => isset($response['error']) && $response['error'] !== '' ? (string) $response['error'] : null,
    ];
}

/**
 * Korte, veilige foutomschrijving (nooit URLs met tokens of secrets).
 */
function graph_error_summary(array $response): string
{
    if ($response['error'] !== null) {
        return 'verbindingsfout (' . mb_substr(preg_replace('/https?:\/\/\S+/i', '[url]', $response['error']) ?? '', 0, 160) . ')';
    }

    $decoded = json_decode($response['body'], true);
    $code = '';
    if (is_array($decoded)) {
        if (is_array($decoded['error'] ?? null)) {
            $code = (string) ($decoded['error']['code'] ?? '');
        } elseif (is_string($decoded['error'] ?? null)) {
            $code = (string) $decoded['error'];
        }
    }

    return 'HTTP ' . $response['status'] . ($code !== '' ? ' ' . mb_substr($code, 0, 80) : '');
}

function graph_token_cache_key(array $config): string
{
    return 'graph_token|' . $config['tenant_id'] . '|' . $config['client_id'] . '|' . sha1($config['client_secret']);
}

function &graph_memory_token(): array
{
    static $token = [];
    return $token;
}

function graph_access_token(int $timeout = GRAPH_DEFAULT_TIMEOUT): string
{
    if (!graph_is_configured()) {
        throw new RuntimeException('Microsoft Graph is niet geconfigureerd.', GRAPH_ERR_NOT_CONFIGURED);
    }

    $config = graph_config();
    $cacheKey = graph_token_cache_key($config);
    $memory = &graph_memory_token();
    if (($memory['key'] ?? '') === $cacheKey && (int) ($memory['expires_at'] ?? 0) > time() + 60) {
        return (string) $memory['token'];
    }

    $cached = briefing_cache_get($cacheKey);
    if (is_array($cached) && (int) ($cached['expires_at'] ?? 0) > time() + 60 && is_string($cached['token'] ?? null)) {
        $memory = ['key' => $cacheKey, 'token' => $cached['token'], 'expires_at' => (int) $cached['expires_at']];
        return $cached['token'];
    }

    $response = graph_send([
        'method' => 'POST',
        'url' => 'https://login.microsoftonline.com/' . rawurlencode($config['tenant_id']) . '/oauth2/v2.0/token',
        'headers' => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
        'body' => http_build_query([
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'scope' => 'https://graph.microsoft.com/.default',
            'grant_type' => 'client_credentials',
        ], '', '&'),
        'timeout' => graph_effective_timeout($timeout),
    ]);

    $decoded = json_decode($response['body'], true);
    if ($response['status'] !== 200 || !is_array($decoded) || !is_string($decoded['access_token'] ?? null)) {
        throw new RuntimeException('Graph-token ophalen mislukt: ' . graph_error_summary($response) . '.');
    }

    $expiresAt = time() + max(60, (int) ($decoded['expires_in'] ?? 3599)) - 120;
    $memory = ['key' => $cacheKey, 'token' => $decoded['access_token'], 'expires_at' => $expiresAt];
    briefing_cache_set($cacheKey, ['token' => $decoded['access_token'], 'expires_at' => $expiresAt], max(60, $expiresAt - time()));

    return $decoded['access_token'];
}

/**
 * GET op Graph met JSON-antwoord. Geeft null terug bij 404 (als $allowNotFound).
 */
function graph_get_json(string $pathOrUrl, int $timeout = GRAPH_DEFAULT_TIMEOUT, bool $allowNotFound = false): ?array
{
    $url = graph_starts_with($pathOrUrl, 'https://') ? $pathOrUrl : GRAPH_BASE_URL . '/' . ltrim($pathOrUrl, '/');
    if (!graph_starts_with($url, GRAPH_BASE_URL . '/')) {
        throw new RuntimeException('Onverwachte Graph-URL.');
    }

    $attempts = 0;
    while (true) {
        $attempts++;
        $response = graph_send([
            'method' => 'GET',
            'url' => $url,
            'headers' => ['Authorization: Bearer ' . graph_access_token($timeout), 'Accept: application/json'],
            'body' => null,
            'timeout' => graph_effective_timeout($timeout),
        ]);

        if ($response['status'] === 404 && $allowNotFound) {
            return null;
        }

        if (in_array($response['status'], [429, 503], true) && $attempts === 1) {
            $retryAfter = (int) ($response['headers']['retry-after'] ?? 0);
            $left = graph_seconds_left();
            if ($retryAfter > 0 && $retryAfter <= 3 && ($left === null || $left > $retryAfter + 2)) {
                sleep($retryAfter);
                continue;
            }
        }

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException('SharePoint-aanroep mislukt: ' . graph_error_summary($response) . '.');
        }

        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded)) {
            throw new RuntimeException('SharePoint gaf ongeldige JSON terug.');
        }

        return $decoded;
    }
}

function graph_encode_path(string $path): string
{
    $segments = [];
    foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            continue;
        }
        $segments[] = rawurlencode($segment);
    }

    return implode('/', $segments);
}

function graph_site_id(int $timeout = GRAPH_DEFAULT_TIMEOUT): string
{
    $config = graph_config();
    $cacheKey = 'graph_site|' . $config['site_hostname'] . '|' . strtolower($config['site_path']);
    $cached = briefing_cache_get($cacheKey);
    if (is_string($cached) && $cached !== '') {
        return $cached;
    }

    $site = graph_get_json('sites/' . rawurlencode($config['site_hostname']) . ':/' . graph_encode_path($config['site_path']) . '?$select=id,webUrl', $timeout);
    $siteId = (string) ($site['id'] ?? '');
    if ($siteId === '') {
        throw new RuntimeException('SharePoint-site niet gevonden.');
    }

    briefing_cache_set($cacheKey, $siteId, 86400);
    return $siteId;
}

function graph_normalize_name(string $value): string
{
    return mb_strtolower(trim(rawurldecode($value)), 'UTF-8');
}

/**
 * Kiest de drive. Zonder $graphDriveName: de standaard documentbibliotheek van de site.
 * Met $graphDriveName: eerst match op het laatste pad-segment van webUrl (uniek binnen een site,
 * bijv. 'Gedeelde documenten'), daarna op de weergavenaam. Let op: op KVTAlgemeen heet de
 * standaardbibliotheek 'Documenten' (webUrl .../Gedeelde%20documenten) en bestaat er óók een
 * lege bibliotheek met weergavenaam 'Gedeelde Documenten'. Daarom gaat webUrl voor.
 *
 * @param list<array<string, mixed>> $drives
 */
function graph_pick_drive(array $drives, string $wantedName): ?array
{
    $wanted = graph_normalize_name($wantedName);
    if ($wanted === '') {
        return null;
    }

    foreach ($drives as $drive) {
        $webUrl = (string) ($drive['webUrl'] ?? '');
        $path = (string) (parse_url($webUrl, PHP_URL_PATH) ?? '');
        $segments = array_values(array_filter(explode('/', $path), static fn(string $s): bool => $s !== ''));
        $last = $segments === [] ? '' : graph_normalize_name((string) end($segments));
        if ($last !== '' && $last === $wanted) {
            return $drive;
        }
    }

    foreach ($drives as $drive) {
        if (graph_normalize_name((string) ($drive['name'] ?? '')) === $wanted) {
            return $drive;
        }
    }

    return null;
}

function graph_drive_id(int $timeout = GRAPH_DEFAULT_TIMEOUT): string
{
    $config = graph_config();
    $cacheKey = 'graph_drive|' . $config['site_hostname'] . '|' . strtolower($config['site_path']) . '|' . graph_normalize_name($config['drive_name']);
    $cached = briefing_cache_get($cacheKey);
    if (is_string($cached) && $cached !== '') {
        return $cached;
    }

    $siteId = graph_site_id($timeout);
    if ($config['drive_name'] === '') {
        $drive = graph_get_json('sites/' . rawurlencode($siteId) . '/drive?$select=id,name,webUrl', $timeout);
    } else {
        $list = graph_get_json('sites/' . rawurlencode($siteId) . '/drives?$select=id,name,webUrl', $timeout);
        $drives = is_array($list['value'] ?? null) ? $list['value'] : [];
        $drive = graph_pick_drive($drives, $config['drive_name']);
        if ($drive === null) {
            throw new RuntimeException('Documentbibliotheek "' . $config['drive_name'] . '" niet gevonden op de site.');
        }
    }

    $driveId = (string) ($drive['id'] ?? '');
    if ($driveId === '') {
        throw new RuntimeException('Documentbibliotheek niet gevonden.');
    }

    briefing_cache_set($cacheKey, $driveId, 86400);
    return $driveId;
}

/**
 * Metadata van een bestand/map op pad (relatief aan de drive-root), of null als het niet bestaat.
 */
function graph_item_by_path(string $path, int $timeout = GRAPH_DEFAULT_TIMEOUT): ?array
{
    $encoded = graph_encode_path($path);
    if ($encoded === '') {
        throw new RuntimeException('Leeg SharePoint-pad.');
    }

    return graph_get_json(
        'drives/' . rawurlencode(graph_drive_id($timeout)) . '/root:/' . $encoded . '?$select=id,name,size,eTag,lastModifiedDateTime,file,folder',
        $timeout,
        true
    );
}

/**
 * Kinderen van een map (alle pagina's, max $maxItems), of null als de map niet bestaat.
 *
 * @return list<array<string, mixed>>|null
 */
function graph_list_children(string $folderPath, int $timeout = GRAPH_DEFAULT_TIMEOUT, int $maxItems = 5000): ?array
{
    $encoded = graph_encode_path($folderPath);
    $driveId = rawurlencode(graph_drive_id($timeout));
    $next = 'drives/' . $driveId . ($encoded === '' ? '/root' : '/root:/' . $encoded . ':') . '/children?$select=id,name,size,eTag,lastModifiedDateTime,file,folder&$top=999';
    $items = [];
    $first = true;

    while ($next !== '') {
        $page = graph_get_json($next, $timeout, $first);
        if ($page === null) {
            return null;
        }
        $first = false;

        foreach ((is_array($page['value'] ?? null) ? $page['value'] : []) as $item) {
            if (is_array($item)) {
                $items[] = $item;
            }
        }

        $next = is_string($page['@odata.nextLink'] ?? null) && count($items) < $maxItems ? $page['@odata.nextLink'] : '';
    }

    return $items;
}

/**
 * Download de inhoud van een drive-item. Graph antwoordt met 302 naar een vooraf
 * geauthenticeerde URL; die wordt zonder Authorization-header opgehaald.
 */
function graph_download_item(string $itemId, int $timeout = GRAPH_DEFAULT_TIMEOUT, int $maxBytes = GRAPH_MAX_DOWNLOAD_BYTES): string
{
    $response = graph_send([
        'method' => 'GET',
        'url' => GRAPH_BASE_URL . '/drives/' . rawurlencode(graph_drive_id($timeout)) . '/items/' . rawurlencode($itemId) . '/content',
        'headers' => ['Authorization: Bearer ' . graph_access_token($timeout)],
        'body' => null,
        'timeout' => graph_effective_timeout($timeout),
        'max_bytes' => $maxBytes,
    ]);

    if (in_array($response['status'], [301, 302, 303, 307, 308], true)) {
        $location = (string) ($response['headers']['location'] ?? '');
        if (!graph_starts_with($location, 'https://')) {
            throw new RuntimeException('Download mislukt: ongeldige doorverwijzing.');
        }

        $response = graph_send([
            'method' => 'GET',
            'url' => $location,
            'headers' => [],
            'body' => null,
            'timeout' => graph_effective_timeout($timeout),
            'max_bytes' => $maxBytes,
        ]);
    }

    if ($response['status'] !== 200) {
        throw new RuntimeException('Download mislukt: ' . graph_error_summary($response) . '.');
    }

    if (strlen($response['body']) > $maxBytes) {
        throw new RuntimeException('Download mislukt: bestand is te groot.');
    }

    return $response['body'];
}
