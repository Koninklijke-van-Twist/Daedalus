<?php
/**
 * Taalonafhankelijke BC-optiewaarden (BC-gebruiker NL, EN of DE).
 * Run: php tests/option_matches_test.php
 */

require __DIR__ . '/../web/lib/option_matches.php';

function om_check(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

om_check(daily_briefing_option_matches('Geannuleerd', ['Cancelled', 'Geannuleerd']), 'NL blijft matchen');
om_check(daily_briefing_option_matches(' CANCELLED ', ['Cancelled']), 'hoofdletters/spaties');
om_check(daily_briefing_option_matches('02 IN PROGRESS', ['In Progress']), 'nummerprefix wordt genegeerd');
om_check(daily_briefing_option_matches('in_progress', ['IN PROGRESS']), 'underscores tellen niet');
om_check(daily_briefing_option_matches('05 Completed', ['Completed']), 'prefix + EN');
om_check(!daily_briefing_option_matches('Gepland', ['Cancelled', 'Geannuleerd']), 'Gepland is niet geannuleerd');
om_check(!daily_briefing_option_matches('', ['Cancelled']), 'leeg matcht niet');
om_check(!daily_briefing_option_matches('02 IN PROGRESS', ['Completed']), 'andere optie matcht niet');

// De statusfuncties uit index.php (index.php zelf heeft auth-side-effects; laad alleen de functies).
$index = (string) file_get_contents(__DIR__ . '/../web/index.php');
$start = strpos($index, 'function is_open_workorder_status(');
$end = strpos($index, 'function status_enabled_default(');
om_check($start !== false && $end !== false && $end > $start, 'statusfuncties gevonden in index.php');
eval(substr($index, $start, $end - $start));

foreach (['Uitgevoerd', 'Completed', '05 COMPLETED', 'Ausgeführt'] as $s) {
    om_check(is_executed_workorder_status($s), "{$s} is uitgevoerd");
    om_check(!is_open_workorder_status($s), "{$s} is niet open");
}
foreach (['Afgesloten', 'Closed', 'Geschlossen'] as $s) {
    om_check(is_closed_workorder_status($s), "{$s} is afgesloten");
}
foreach (['Gecontroleerd', 'Checked', '07 CHECKED'] as $s) {
    om_check(is_checked_workorder_status($s), "{$s} is gecontroleerd");
}
foreach (['Ondertekend', 'Signed', 'Unterschrieben'] as $s) {
    om_check(is_signed_workorder_status($s), "{$s} is ondertekend");
}
foreach (['', 'Open', 'Gepland', 'Planned', 'Onderhanden', '02 IN PROGRESS', 'In Progress'] as $s) {
    om_check(is_open_workorder_status($s), "'{$s}' is open");
    om_check(!is_executed_workorder_status($s) && !is_closed_workorder_status($s), "'{$s}' is niet uitgevoerd/afgesloten");
}
foreach (['Geannuleerd', 'Cancelled', 'Invoiced', 'Gefactureerd', 'Storniert'] as $s) {
    om_check(!is_open_workorder_status($s), "{$s} is niet open");
}

echo "OK option_matches_test\n";
