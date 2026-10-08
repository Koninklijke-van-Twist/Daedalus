<?php
/**
 * Read-only endpoint voor de Copilot-MVP "aandachtspunten uit eerdere bezoekrapporten".
 *
 *   GET web/api/daily_briefing.php?date=JJJJ-MM-DD[&scope=subscribers|all][&company=...][&history=1..10]
 *   Header: X-API-Key: <een van $briefingApiKeys uit web/auth.php>
 *
 * Geen sessie/login, alleen GET, JSON, schrijft niets naar BC of SharePoint.
 * Zie README.md voor de JSON-vorm en de configuratie.
 */

/**
 * Includes/requires
 */
define('DAEDALUS_EMAIL_NOTIFICATIONS_LIB_ONLY', true);
require_once dirname(__DIR__) . '/api_send_email_notifications.php';
require_once dirname(__DIR__) . '/lib/daily_briefing.php';

/**
 * Page load
 */
// odata.php zet display_errors aan; in een JSON-endpoint mogen notices de uitvoer niet breken.
ini_set('display_errors', '0');

$response = daily_briefing_handle($_SERVER, $_GET);

http_response_code($response['status']);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
foreach ($response['headers'] as $headerName => $headerValue) {
    header($headerName . ': ' . $headerValue);
}

echo json_encode($response['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
