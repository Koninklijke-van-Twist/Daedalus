<?php
/**
 * frequently.php nachtvenster: [01:45, 03:00) Europe/Amsterdam, geen BC-calls.
 * Run: php tests/frequently_nachtvenster_test.php
 */

require_once __DIR__ . '/../web/lib/frequently_nachtvenster.php';

$assertions = 0;

function fail(string $message): void
{
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

function check(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fail($message);
    }
}

function at(string $ymdHis): DateTimeImmutable
{
    $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $ymdHis, new DateTimeZone('Europe/Amsterdam'));
    if (!($dt instanceof DateTimeImmutable)) {
        fail('ongeldige testdatum ' . $ymdHis);
    }

    return $dt;
}

check(!frequently_in_nachtvenster(at('2026-11-05 01:44:59')), '01:44:59 valt erbuiten');
check(frequently_in_nachtvenster(at('2026-11-05 01:45:00')), '01:45:00 is de start (inclusief)');
check(frequently_in_nachtvenster(at('2026-11-05 02:40:00')), '02:40 zit in het BC-lockvenster');
check(frequently_in_nachtvenster(at('2026-11-05 02:59:59')), '02:59:59 zit erin');
check(!frequently_in_nachtvenster(at('2026-11-05 03:00:00')), '03:00:00 is het einde (exclusief)');
check(!frequently_in_nachtvenster(at('2026-11-05 12:00:00')), 'overdag geen skip');

// UTC 00:45 = Amsterdam 01:45 (CET, UTC+1 in november) → binnen
$utc = new DateTimeImmutable('2026-11-05 00:45:00', new DateTimeZone('UTC'));
check(frequently_in_nachtvenster($utc), 'UTC 00:45 (CET 01:45) moet skippen');

$GLOBALS['frequentlyNachtvensterStart'] = '02:00';
$GLOBALS['frequentlyNachtvensterEnd'] = '02:30';
check(!frequently_in_nachtvenster(at('2026-11-05 01:50:00')), 'auth-override start 02:00');
check(frequently_in_nachtvenster(at('2026-11-05 02:10:00')), 'auth-override midden');
check(!frequently_in_nachtvenster(at('2026-11-05 02:30:00')), 'auth-override einde exclusief');
unset($GLOBALS['frequentlyNachtvensterStart'], $GLOBALS['frequentlyNachtvensterEnd']);

$GLOBALS['frequentlyNachtvensterStart'] = 'invalid';
check(frequently_in_nachtvenster(at('2026-11-05 01:45:00')), 'ongeldige override valt terug op default');
unset($GLOBALS['frequentlyNachtvensterStart']);

echo "OK ($assertions assertions)\n";
