<?php
/**
 * Kleine bestandscache voor de daily briefing (Graph-token, SharePoint-index, mappenlijsten,
 * geëxtraheerde rapporttekst).
 *
 * - Alles staat onder web/cache/briefing/ (web/cache/ is gitignored en wordt niet gedeployed).
 * - Elk cachebestand is een .php-bestand dat begint met een PHP-guard, zodat een direct
 *   HTTP-verzoek niets teruggeeft, ook als .htaccess niet werkt (de deploy uploadt geen .htaccess).
 * - Daarnaast wordt bij het aanmaken van de map een .htaccess ("Require all denied") en een lege
 *   index.html geschreven.
 */

/**
 * Constants
 */
const BRIEFING_CACHE_GUARD = "<?php http_response_code(404); exit; ?>\n";

/**
 * Functies
 */
function briefing_cache_dir(): string
{
    $override = $GLOBALS['DAEDALUS_BRIEFING_CACHE_DIR'] ?? null;
    $dir = is_string($override) && trim($override) !== ''
        ? rtrim($override, '/\\')
        : dirname(__DIR__) . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'briefing';

    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }

    $htaccess = $dir . DIRECTORY_SEPARATOR . '.htaccess';
    if (is_dir($dir) && !is_file($htaccess)) {
        @file_put_contents($htaccess, "Require all denied\nDeny from all\n");
        @file_put_contents($dir . DIRECTORY_SEPARATOR . 'index.html', '');
    }

    return $dir;
}

function briefing_cache_tmp_dir(): string
{
    $dir = briefing_cache_dir() . DIRECTORY_SEPARATOR . 'tmp';
    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }

    return $dir;
}

function briefing_cache_path(string $key): string
{
    return briefing_cache_dir() . DIRECTORY_SEPARATOR . sha1($key) . '.php';
}

/**
 * @return array{data: mixed, stored_at: int, expires_at: int}|null
 */
function briefing_cache_read_entry(string $key): ?array
{
    $path = briefing_cache_path($key);
    if (!is_file($path)) {
        return null;
    }

    $raw = @file_get_contents($path);
    if (!is_string($raw) || strncmp($raw, BRIEFING_CACHE_GUARD, strlen(BRIEFING_CACHE_GUARD)) !== 0) {
        return null;
    }

    $decoded = json_decode(substr($raw, strlen(BRIEFING_CACHE_GUARD)), true);
    if (!is_array($decoded) || !array_key_exists('data', $decoded)) {
        return null;
    }

    return [
        'data' => $decoded['data'],
        'stored_at' => (int) ($decoded['stored_at'] ?? 0),
        'expires_at' => (int) ($decoded['expires_at'] ?? 0),
    ];
}

/**
 * Geeft de data terug zolang die niet verlopen is, anders null.
 *
 * @return mixed|null
 */
function briefing_cache_get(string $key)
{
    $entry = briefing_cache_read_entry($key);
    if ($entry === null) {
        return null;
    }

    if ($entry['expires_at'] > 0 && $entry['expires_at'] < time()) {
        return null;
    }

    return $entry['data'];
}

/**
 * @param mixed $data
 */
function briefing_cache_set(string $key, $data, int $ttlSeconds): bool
{
    $now = time();
    $json = json_encode([
        'stored_at' => $now,
        'expires_at' => $ttlSeconds > 0 ? $now + $ttlSeconds : 0,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($json)) {
        return false;
    }

    $path = briefing_cache_path($key);
    $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($tmp, BRIEFING_CACHE_GUARD . $json, LOCK_EX) === false) {
        return false;
    }
    @chmod($tmp, 0660);

    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }

    return true;
}

function briefing_cache_delete(string $key): void
{
    $path = briefing_cache_path($key);
    if (is_file($path)) {
        @unlink($path);
    }
}

/**
 * Schrijft binaire inhoud naar een tijdelijk bestand onder web/cache/briefing/tmp/.
 * Roep briefing_cache_remove_tmp() aan zodra het bestand niet meer nodig is.
 */
function briefing_cache_write_tmp(string $contents, string $extension): string
{
    $safeExtension = preg_replace('/[^a-z0-9]/i', '', $extension);
    $path = briefing_cache_tmp_dir() . DIRECTORY_SEPARATOR . 'dl_' . bin2hex(random_bytes(8)) . '.' . ($safeExtension !== '' ? $safeExtension : 'bin');
    if (@file_put_contents($path, $contents) === false) {
        throw new RuntimeException('Tijdelijk bestand kon niet worden geschreven.');
    }

    return $path;
}

function briefing_cache_remove_tmp(string $path): void
{
    if ($path !== '' && is_file($path)) {
        @unlink($path);
    }
}
