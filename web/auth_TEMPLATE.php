<?php
/**
 * Auth template for Daedalus.
 *
 * Prefer Mímir, and keep the BC block as automatic fallback when Mímir is down:
 *   $mimirApi  = 'mimir_…';  // required to activate Mímir
 *   $mimirBase = 'https://sleutels.kvt.nl/mimir/api'; // optional
 *
 * With $mimirApi set, fetches try Mímir first and fall back to the BC vars below.
 * Without $mimirApi, only the BC block is used.
 */

// --- Mímir (recommended) ---
// $mimirApi  = 'mimir_…';
// $mimirBase = 'https://sleutels.kvt.nl/mimir/api';

// --- Business Central (direct path, and fallback when Mímir fails) ---
$auth_list =
    [
        "env1" => ['mode' => 'basic', 'user' => 'USERNAME', 'pass' => 'PASSWORD'],
        "env2" => ['mode' => 'basic', 'user' => 'USERNAME', 'pass' => 'PASSWORD'],
        "env3" => ['mode' => 'basic', 'user' => 'USERNAME', 'pass' => 'PASSWORD']
    ];
$environment = "env1";
$auth = $auth_list[$environment];
$baseUrl = "https://my-bc-domain.com:7148/";

$allowedUsers = [
    "user@domain.nl"
];

// --- Daily briefing / Copilot-MVP "aandachtspunten" (optioneel) ---
// Read-only endpoint web/api/daily_briefing.php; elke client krijgt een eigen, lange random key
// (bijv. bin2hex(random_bytes(32))). Header: X-API-Key. Lege lijst = endpoint geeft altijd 401.
// $briefingApiKeys = [
//     'copilot-flow-…',
// ];

// Microsoft Graph (Entra-app, client credentials, application permission Sites.Selected met
// alleen een read-grant op site KVTAlgemeen). Zonder deze drie waarden: endpoint levert geen
// history (status not_configured) en de dagmail blijft precies zoals hij was.
// $graphTenantId     = '00000000-0000-0000-0000-000000000000';
// $graphClientId     = '00000000-0000-0000-0000-000000000000';
// $graphClientSecret = '…';
// $graphSiteHostname = 'kvtnl.sharepoint.com';
// $graphSitePath     = '/sites/KVTAlgemeen';
// $graphDriveName    = '';  // leeg = standaard documentbibliotheek ("Gedeelde documenten"); of 'Gedeelde documenten'
//
// Map waar de flow per dag per werkorder een Markdown-bestand neerzet:
//   <briefingNotesFolder>/<JJJJ-MM-DD>/<WO-nr>.md  (in dezelfde bibliotheek)
// $briefingNotesFolder = 'General/Daedalus/Aandachtspunten';
//
// Optioneel:
// $briefingIndexPath          = 'General/servicerapporten.xlsx';
// $briefingTimeBudgetSeconds  = 40;   // max. SharePoint-tijd per endpoint-aanroep
// $briefingNotesBudgetSeconds = 20;   // max. SharePoint-tijd per dagmail-run
// $briefingPdftotextPath      = '/usr/bin/pdftotext'; // pdf-fallback; standaard automatisch gezocht
// $briefingCompanies          = ['Koninklijke van Twist', 'Hunter van Twist', 'KVT Gas'];
