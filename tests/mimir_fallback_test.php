<?php
/**
 * Simuleert een onbereikbare Mímir en controleert de directe BC-fallback.
 * Run: php tests/mimir_fallback_test.php
 */

$logFile = sys_get_temp_dir() . '/daedalus-mimir-fallback-test.log';
@unlink($logFile);
ini_set('error_log', $logFile);
ini_set('log_errors', '1');

$mimirApi = 'mimir_test_key_should_not_leak';
$mimirBase = 'http://127.0.0.1:9';
$baseUrl = 'https://bc.example:7148/';
$environment = 'Production';
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'];
$auth_list = ['Production' => $auth];

$calls = [];
$GLOBALS['DAEDALUS_ODATA_BC_FETCH'] = static function (string $url, array $auth, int $ttl) use (&$calls): array {
    $calls[] = [
        'url' => $url,
        'user' => (string) ($auth['user'] ?? ''),
        'ttl' => $ttl,
    ];
    if (preg_match('#/([^/]+)/ODataV4/Company(?:\\?|$)#', $url, $envMatch) === 1) {
        $envName = rawurldecode($envMatch[1]);
        if (strcasecmp($envName, 'Sandbox') === 0) {
            return [['Name' => 'Andere BV']];
        }
        if (strcasecmp($envName, 'Sand box') === 0) {
            return [['Name' => 'Space BV']];
        }
        return [
            ['Name' => 'KVT Gas'],
            ['Name' => 'Hunter van Twist'],
            ['name' => 'Koninklijke van Twist'],
        ];
    }
    return [['No' => 'WO-1']];
};

require dirname(__DIR__) . '/web/odata.php';
require dirname(__DIR__) . '/web/functions.php';

function fail(string $message): void
{
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

function fallback_log(): string
{
    global $logFile;
    $raw = @file_get_contents($logFile);
    return is_string($raw) ? $raw : '';
}

function fallback_count(): int
{
    return substr_count(fallback_log(), '[Daedalus] Mímir failed, falling back to direct OData:');
}

function assert_auth_globals_copy(): void
{
    $saved = [];
    foreach (['baseUrl', 'auth', 'auth_list', 'environment', 'base'] as $name) {
        $saved[$name] = [
            'set' => array_key_exists($name, $GLOBALS),
            'value' => $GLOBALS[$name] ?? null,
        ];
    }

    $GLOBALS['baseUrl'] = 'https://already.example/';
    $GLOBALS['environment'] = 'mimir';
    $GLOBALS['auth'] = [];
    unset($GLOBALS['auth_list'], $GLOBALS['base']);

    $path = sys_get_temp_dir() . '/daedalus-auth-globals-test.php';
    $written = file_put_contents($path, <<<'PHP'
<?php
$baseUrl = 'https://from-auth.example/';
$environment = 'FromAuth';
$auth = ['mode' => 'basic', 'user' => 'file-user', 'pass' => 'file-secret'];
$auth_list = ['FromAuth' => $auth];
$base = 'https://base-from-file.example/';
$shouldNotLeak = 'local-only';
PHP
    );
    if ($written === false) {
        fail('tijdelijke auth.php kon niet worden geschreven');
    }

    if (isset($environment) || isset($baseUrl) || isset($auth) || isset($auth_list) || isset($base) || isset($shouldNotLeak)) {
        fail('testfunctie had al lokale auth-variabelen');
    }
    $vars = odata_read_auth_file($path);
    if (isset($environment) || isset($baseUrl) || isset($auth) || isset($auth_list) || isset($base) || isset($shouldNotLeak)) {
        @unlink($path);
        fail('auth.php require lekte variabelen naar de aanroeper');
    }
    odata_merge_auth_globals($vars);
    @unlink($path);

    if (($GLOBALS['baseUrl'] ?? '') !== 'https://already.example/') {
        fail('gezette baseUrl werd overschreven: ' . (string) ($GLOBALS['baseUrl'] ?? ''));
    }
    if (($GLOBALS['environment'] ?? '') !== 'FromAuth') {
        fail('placeholder environment werd niet uit auth.php gekopieerd');
    }
    if (($GLOBALS['auth']['user'] ?? '') !== 'file-user') {
        fail('auth werd niet naar $GLOBALS gekopieerd');
    }
    if (($GLOBALS['auth_list']['FromAuth']['user'] ?? '') !== 'file-user') {
        fail('auth_list werd niet naar $GLOBALS gekopieerd');
    }
    if (($GLOBALS['base'] ?? '') !== 'https://base-from-file.example/') {
        fail('base werd niet naar $GLOBALS gekopieerd');
    }
    if (isset($shouldNotLeak)) {
        fail('niet-auth variabele uit auth.php lekte');
    }

    odata_merge_auth_globals([
        'baseUrl' => 'https://other.example/',
        'environment' => 'OtherEnv',
        'auth' => ['mode' => 'basic', 'user' => 'other-user', 'pass' => 'other-secret'],
        'base' => 'https://other-base.example/',
    ]);
    if (($GLOBALS['baseUrl'] ?? '') !== 'https://already.example/' || ($GLOBALS['environment'] ?? '') !== 'FromAuth' || ($GLOBALS['auth']['user'] ?? '') !== 'file-user') {
        fail('tweede merge overschreef al gezette waarden');
    }

    foreach ($saved as $name => $state) {
        if ($state['set']) {
            $GLOBALS[$name] = $state['value'];
        } else {
            unset($GLOBALS[$name]);
        }
    }
}

if (odata_mimir_connect_timeout_seconds() !== 10) {
    fail('connect-timeout moet 10s zijn');
}
if (odata_mimir_timeout_seconds_for_sapi('cli') !== 600) {
    fail('CLI-timeout moet 600s blijven');
}
if (odata_mimir_timeout_seconds_for_sapi('fpm-fcgi') !== 90 || odata_mimir_timeout_seconds_for_sapi('apache2handler') !== 90) {
    fail('web-timeout moet ongeveer 90s zijn');
}
if (PHP_SAPI === 'cli' && odata_mimir_timeout_seconds() !== 600) {
    fail('huidige CLI-sapi moet de lange timeout gebruiken');
}

$syntheticCompanyUrl = odata_company_url('Production', 'KVT Gas', 'AppWerkorders', ['$select' => 'No']);
if (strpos($syntheticCompanyUrl, 'https://mimir.invalid/Production/ODataV4/Company(') !== 0) {
    fail('met Mímir aan moet de company-URL synthetisch zijn, kreeg: ' . $syntheticCompanyUrl);
}

$names = odata_mimir_list_companies(null);
$expectedNames = ['Hunter van Twist', 'Koninklijke van Twist', 'KVT Gas'];
if ($names !== $expectedNames) {
    fail('company-fallback gaf ' . json_encode($names) . ' i.p.v. de gesorteerde BC-namen');
}
if (!odata_mimir_circuit_open()) {
    fail('circuit moet open na de eerste Mímir-fout');
}
if (count($calls) !== 1 || strpos($calls[0]['url'], 'https://bc.example:7148/Production/ODataV4/Company') !== 0) {
    fail('company-fallback riep de directe BC-fetch niet aan: ' . json_encode($calls));
}
if ($calls[0]['user'] !== 'bcuser') {
    fail('company-fallback gebruikte niet de BC-credentials');
}

$directCompanyUrl = odata_company_url('Production', 'KVT Gas', 'AppWerkorders', ['$select' => 'No']);
if (strpos($directCompanyUrl, 'https://bc.example:7148/Production/ODataV4/Company(\'KVT%20Gas\')/AppWerkorders?') !== 0) {
    fail('na de circuit-open moet odata_company_url de oude BC-URL bouwen, kreeg: ' . $directCompanyUrl);
}
if (strpos($directCompanyUrl, 'mimir.invalid') !== false) {
    fail('synthetische host bleef staan na fallback');
}

$mimirBase = 'http://192.0.2.1:9';
$started = microtime(true);
$rows = odata_get_all(
    "https://mimir.invalid/Production/ODataV4/Company('Koninklijke%20van%20Twist')/AppWerkorders?\$select=No",
    $auth,
    120
);
$elapsed = microtime(true) - $started;
if ($elapsed >= 2.0) {
    fail('circuit breaker sloeg Mímir niet over (' . round($elapsed, 3) . 's)');
}
if (($rows[0]['No'] ?? '') !== 'WO-1') {
    fail('entity-fallback gaf niet de gestubde BC-rijen terug');
}
$entityCall = $calls[1] ?? null;
$expectedEntityUrl = "https://bc.example:7148/Production/ODataV4/Company('Koninklijke%20van%20Twist')/AppWerkorders?\$select=No";
if (!is_array($entityCall) || $entityCall['url'] !== $expectedEntityUrl || $entityCall['user'] !== 'bcuser' || $entityCall['ttl'] !== 120) {
    fail('entity-fallback URL/auth/ttl klopt niet: ' . json_encode($entityCall));
}
if (fallback_count() !== 1) {
    fail('alleen de eerste Mímir-fout mag een fallback loggen, count=' . fallback_count() . ' log=' . fallback_log());
}
$log = fallback_log();
if (strpos($log, 'mimir_test_key_should_not_leak') !== false || strpos($log, 'bc-secret') !== false) {
    fail('log bevat een geheim');
}
if (strpos($log, '[Daedalus] Mímir failed, falling back to direct OData:') === false) {
    fail('logregel mist het verwachte prefix');
}

odata_mimir_circuit_reset();
$mimirBase = 'http://127.0.0.1:9';
$beforeQuery = count($calls);
$queryRows = odata_mimir_query('KVT Gas', 'AppResource', ['$select' => 'No,Name'], 60);
if (($queryRows[0]['No'] ?? '') !== 'WO-1') {
    fail('odata_mimir_query viel niet terug op de stub');
}
$queryCall = $calls[$beforeQuery] ?? null;
if (!is_array($queryCall) || strpos($queryCall['url'], "https://bc.example:7148/Production/ODataV4/Company('KVT%20Gas')/AppResource?") !== 0) {
    fail('query-fallback bouwde niet de pre-Mímir BC-URL: ' . json_encode($queryCall));
}

odata_mimir_circuit_reset();
$beforeFetch = count($calls);
$fetchRows = odata_mimir_fetch_all(
    "https://mimir.invalid/Production/ODataV4/Company('KVT%20Gas')/AppWerkorders?\$select=No",
    15
);
if (($fetchRows[0]['No'] ?? '') !== 'WO-1') {
    fail('odata_mimir_fetch_all viel niet terug');
}
$fetchCall = $calls[$beforeFetch] ?? null;
if (!is_array($fetchCall) || $fetchCall['url'] !== "https://bc.example:7148/Production/ODataV4/Company('KVT%20Gas')/AppWerkorders?\$select=No") {
    fail('fetch_all-fallback herschreef de URL niet: ' . json_encode($fetchCall));
}

odata_mimir_circuit_reset();
$map = odata_mimir_company_environment_map(null);
if (($map['Hunter van Twist'] ?? '') !== 'Production' || ($map['KVT Gas'] ?? '') !== 'Production') {
    fail('environment-map viel niet terug op BC: ' . json_encode($map));
}

$auth_list['Sandbox'] = ['mode' => 'basic', 'user' => 'sandbox-user', 'pass' => 'sandbox-secret'];
$auth_list['Sand box'] = ['mode' => 'basic', 'user' => 'space-user', 'pass' => 'space-secret'];
$loggedBeforeSecondEnv = fallback_count();
$map = odata_mimir_company_environment_map(null);
if (fallback_count() !== $loggedBeforeSecondEnv) {
    fail('open circuit mag niet opnieuw loggen bij company-discovery');
}
if (($map['Andere BV'] ?? '') !== 'Sandbox') {
    fail('bedrijf in tweede environment ontbreekt in de map: ' . json_encode($map));
}
$beforeSecondQuery = count($calls);
$secondQuery = odata_mimir_query('Andere BV', 'AppWerkorders', ['$select' => 'No'], 30);
if (fallback_count() !== $loggedBeforeSecondEnv) {
    fail('query via open circuit mag niet opnieuw loggen');
}
if (($secondQuery[0]['No'] ?? '') !== 'WO-1') {
    fail('query voor tweede environment gaf geen stub-rij');
}
$secondQueryCall = $calls[$beforeSecondQuery] ?? null;
if (!is_array($secondQueryCall)
    || strpos((string) $secondQueryCall['url'], "https://bc.example:7148/Sandbox/ODataV4/Company('Andere%20BV')/AppWerkorders?") !== 0
    || $secondQueryCall['user'] !== 'sandbox-user') {
    fail('query gebruikte niet de environment en auth van het bedrijf: ' . json_encode($secondQueryCall));
}

$beforeSandboxGet = count($calls);
$sandboxRows = odata_get_all(
    "https://mimir.invalid/Sandbox/ODataV4/Company('Andere%20BV')/AppWerkorders?\$select=No",
    $auth,
    40
);
$sandboxCall = $calls[$beforeSandboxGet] ?? null;
if (($sandboxRows[0]['No'] ?? '') !== 'WO-1' || !is_array($sandboxCall) || $sandboxCall['user'] !== 'sandbox-user') {
    fail('URL-environment moet auth_list van die environment kiezen, niet de primaire auth: ' . json_encode($sandboxCall));
}
if (!is_array($sandboxCall) || $sandboxCall['url'] !== "https://bc.example:7148/Sandbox/ODataV4/Company('Andere%20BV')/AppWerkorders?\$select=No") {
    fail('mimir.invalid-URL met echte environment werd niet herschreven: ' . json_encode($sandboxCall));
}

$beforeMappedEnv = count($calls);
odata_get_all("https://mimir.invalid/mimir/ODataV4/Company('Andere%20BV')/AppWerkorders", $auth, 5);
$mappedEnvCall = $calls[$beforeMappedEnv] ?? null;
if (!is_array($mappedEnvCall) || strpos((string) $mappedEnvCall['url'], '/Sandbox/ODataV4/') === false || $mappedEnvCall['user'] !== 'sandbox-user') {
    fail('environment-segment mimir moet de company-map gebruiken: ' . json_encode($mappedEnvCall));
}
$beforeUnknown = count($calls);
odata_get_all("https://mimir.invalid/mimir/ODataV4/Company('Onbekend%20BV')/AppWerkorders", $auth, 5);
$unknownCall = $calls[$beforeUnknown] ?? null;
if (!is_array($unknownCall) || strpos((string) $unknownCall['url'], '/Production/ODataV4/') === false || $unknownCall['user'] !== 'bcuser') {
    fail('onbekend bedrijf moet terugvallen op de primaire environment en auth: ' . json_encode($unknownCall));
}

$spacedUrl = odata_bc_url_from_odata_url("https://mimir.invalid/Sand%20box/ODataV4/Company('Space%20BV')/AppWerkorders");
if ($spacedUrl !== "https://bc.example:7148/Sand%20box/ODataV4/Company('Space%20BV')/AppWerkorders") {
    fail('environment-segment moet precies één keer geëncodeerd worden, kreeg: ' . $spacedUrl);
}
$beforeSpacedGet = count($calls);
odata_get_all("https://mimir.invalid/Sand%20box/ODataV4/Company('Space%20BV')/AppWerkorders", $auth, 10);
$spacedCall = $calls[$beforeSpacedGet] ?? null;
if (!is_array($spacedCall) || $spacedCall['user'] !== 'space-user' || strpos((string) $spacedCall['url'], '/Sand%2520box/') !== false) {
    fail('geëncodeerde environment koos de verkeerde auth of werd dubbel geëncodeerd: ' . json_encode($spacedCall));
}
if (strpos(fallback_log(), 'sandbox-secret') !== false || strpos(fallback_log(), 'space-secret') !== false) {
    fail('log bevat een environment-wachtwoord');
}

$savedEnvironment = $environment;
$environment = 'mimir';
$cacheKey = build_cache_key(
    "https://bc.example:7148/Sandbox/ODataV4/Company('Andere%20BV')/AppWerkorders",
    ['mode' => 'basic', 'user' => 'cache-user', 'pass' => 'cache-secret']
);
$environment = $savedEnvironment;
if (substr($cacheKey, -strlen('|cache-user|Sandbox')) !== '|cache-user|Sandbox' || strpos($cacheKey, '|mimir') !== false) {
    fail('cache-key moet de echte BC-environment gebruiken, kreeg: ' . $cacheKey);
}

$loggedBeforeRethrow = fallback_count();
$callsBeforeRethrow = count($calls);
odata_mimir_circuit_reset();
$mimirBase = 'http://127.0.0.1:9';
$baseUrl = 'https://mimir.invalid/';
$environment = 'mimir';
$auth = [];
$auth_list = [];
$rethrown = null;
try {
    odata_get_all('https://mimir.invalid/mimir/ODataV4/Company(\'X\')/AppWerkorders', ['mode' => 'basic', 'user' => '', 'pass' => ''], 30);
    fail('zonder BC-credentials moet de oorspronkelijke Mímir-fout terugkomen');
} catch (Throwable $exception) {
    $rethrown = $exception;
}
if (strpos($rethrown->getMessage(), 'Mímir') !== 0 && strpos($rethrown->getMessage(), 'Mímir') === false) {
    fail('hergooide fout is niet de Mímir-fout: ' . $rethrown->getMessage());
}
if (stripos($rethrown->getMessage(), 'credential') !== false) {
    fail('hergooide fout maskeert Mímir met een credentials-melding: ' . $rethrown->getMessage());
}
if (count($calls) !== $callsBeforeRethrow) {
    fail('zonder BC-credentials mag de directe fetch niet starten');
}
if (fallback_count() !== $loggedBeforeRethrow) {
    fail('zonder BC-credentials mag er geen fallback gelogd worden');
}

odata_mimir_circuit_reset();
$mimirApi = '';
$mimirBase = 'http://127.0.0.1:9';
$baseUrl = 'https://bc.example:7148/';
$environment = 'Production';
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'];
$auth_list = ['Production' => $auth];
$loggedBeforeDirect = fallback_count();
$directOnlyUrl = 'https://mimir.invalid/Production/ODataV4/Company(\'KVT%20Gas\')/AppWerkorders?$select=No';
$directRows = odata_get_all($directOnlyUrl, $auth, 45);
if (odata_mimir_circuit_open()) {
    fail('lege $mimirApi mag Mímir niet proberen');
}
if (fallback_count() !== $loggedBeforeDirect) {
    fail('lege $mimirApi mag geen Mímir-fallback loggen');
}
$directCall = $calls[count($calls) - 1] ?? null;
if (($directRows[0]['No'] ?? '') !== 'WO-1' || !is_array($directCall) || $directCall['url'] !== $directOnlyUrl) {
    fail('lege $mimirApi moet de oude directe route ongewijzigd gebruiken: ' . json_encode($directCall));
}

odata_mimir_circuit_reset();
$mimirApi = 'mimir_test_key_should_not_leak';
$mimirBase = 'http://127.0.0.1:9';
$baseUrl = 'https://bc.example:7148/';
$environment = 'Production';
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'];
$auth_list = ['Production' => $auth];
$loggedBeforeCaller = fallback_count();
$callsBeforeCaller = count($calls);
$callerError = null;
try {
    odata_mimir_fetch_all('https://example.test/not-an-odata-url', 5);
} catch (Throwable $exception) {
    $callerError = $exception;
}
if (!$callerError instanceof Throwable || strpos($callerError->getMessage(), 'OData-URL kon niet worden vertaald') === false) {
    fail('exception uit de caller moet doorgaan, kreeg: ' . ($callerError instanceof Throwable ? $callerError->getMessage() : 'geen exception'));
}
if (odata_mimir_circuit_open() || fallback_count() !== $loggedBeforeCaller || count($calls) !== $callsBeforeCaller) {
    fail('exception uit de caller mag het circuit niet openen en geen fallback starten');
}

assert_auth_globals_copy();

echo "OK\n";
