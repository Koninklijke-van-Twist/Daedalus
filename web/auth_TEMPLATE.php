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

// Microsoft Graph: dezelfde Entra-app als Clio. Kopieer het $sharepointSettings-blok uit Clio's
// auth.php 1-op-1 (zelfde sleutels). Daedalus gebruikt alleen tenant_id/client_id/client_secret
// (+ optioneel token_scope/token_url/verify_ssl/ca_bundle); Clio's site_id/drive_id/list_id horen
// bij Clio's transcript-site en worden genegeerd. Zonder tenant/client/secret: endpoint levert geen
// history (status not_configured) en de dagmail blijft precies zoals hij was.
// $sharepointSettings = [
//     'tenant_id'     => '00000000-0000-0000-0000-000000000000',
//     'client_id'     => '00000000-0000-0000-0000-000000000000',
//     'client_secret' => '…',
//     'token_scope'   => 'https://graph.microsoft.com/.default', // optioneel
//     'token_url'     => '',    // optioneel
//     'verify_ssl'    => true,  // optioneel
//     'ca_bundle'     => '',    // optioneel
//     // 'site_id', 'drive_id', 'list_id', … (Clio) mogen blijven staan; Daedalus negeert ze.
// ];
//
// Daedalus-specifiek: de site met servicerapporten (standaardwaarden hieronder).
// $briefingSiteHostname = 'kvtnl.sharepoint.com';
// $briefingSitePath     = '/sites/KVTAlgemeen';
// $briefingDriveName    = '';  // leeg = standaard documentbibliotheek ("Gedeelde documenten"); of 'Gedeelde documenten'
// $briefingDriveId      = '';  // optioneel: vaste drive-id, slaat de site-lookup over (nodig als de app alleen Files.* heeft)
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
