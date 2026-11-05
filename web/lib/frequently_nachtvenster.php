<?php
/**
 * Nachtvenster voor frequently.php-warmup (Europe/Amsterdam).
 * Alleen deze warmup; nightly.php en de UI niet.
 */

const FREQUENTLY_NACHTVENSTER_START = '01:45';
const FREQUENTLY_NACHTVENSTER_END = '03:00';

/**
 * Of lokale tijd in [start, end) valt (Europe/Amsterdam).
 * Optionele auth.php-override: $frequentlyNachtvensterStart / $frequentlyNachtvensterEnd (HH:MM).
 */
function frequently_in_nachtvenster(?DateTimeInterface $now = null): bool
{
    $tz = new DateTimeZone('Europe/Amsterdam');
    $now = ($now instanceof DateTimeInterface)
        ? DateTimeImmutable::createFromInterface($now)->setTimezone($tz)
        : new DateTimeImmutable('now', $tz);
    $start = FREQUENTLY_NACHTVENSTER_START;
    $end = FREQUENTLY_NACHTVENSTER_END;
    if (isset($GLOBALS['frequentlyNachtvensterStart']) && is_string($GLOBALS['frequentlyNachtvensterStart'])
        && preg_match('/^\d{2}:\d{2}$/', $GLOBALS['frequentlyNachtvensterStart']) === 1) {
        $start = $GLOBALS['frequentlyNachtvensterStart'];
    }
    if (isset($GLOBALS['frequentlyNachtvensterEnd']) && is_string($GLOBALS['frequentlyNachtvensterEnd'])
        && preg_match('/^\d{2}:\d{2}$/', $GLOBALS['frequentlyNachtvensterEnd']) === 1) {
        $end = $GLOBALS['frequentlyNachtvensterEnd'];
    }
    $hm = $now->format('H:i');

    return $hm >= $start && $hm < $end;
}
