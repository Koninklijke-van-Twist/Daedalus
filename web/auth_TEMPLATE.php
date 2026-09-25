<?php
/**
 * Auth template for Daedalus.
 *
 * Prefer Mímir (no BC credentials needed):
 *   $mimirApi  = 'mimir_…';  // required to activate Mímir
 *   $mimirBase = 'https://sleutels.kvt.nl/mimir/api'; // optional
 *
 * With $mimirApi set, the BC vars below are unused.
 * Without $mimirApi, keep the BC block for the legacy OData path.
 */

// --- Mímir (recommended) ---
// $mimirApi  = 'mimir_…';
// $mimirBase = 'https://sleutels.kvt.nl/mimir/api';

// --- Legacy Business Central (only when $mimirApi is not set) ---
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
