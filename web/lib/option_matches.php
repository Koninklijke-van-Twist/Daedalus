<?php
/**
 * Taalonafhankelijk vergelijken van BC-optiewaarden.
 *
 * BC levert optieteksten in de taal van de BC-gebruiker (NL/EN/DE), soms met een
 * nummerprefix ('02 IN PROGRESS'). Gedeeld door index.php en lib/daily_briefing.php.
 */

if (!function_exists('daily_briefing_option_matches')) {
    /** Normaliseert een optiewaarde: lowercase, alles behalve letters/cijfers weg. */
    function daily_briefing_option_normalize($input): string
    {
        $text = mb_strtolower(trim((string) $input), 'UTF-8');
        return preg_replace('/[^\p{L}\p{N}]+/u', '', $text) ?? $text;
    }

    /** Zelfde normalisatie, maar zonder nummerprefix (^\d+\s+), bv. '02 IN PROGRESS' -> 'inprogress'. */
    function daily_briefing_option_normalize_bare($input): string
    {
        $text = mb_strtolower(trim((string) $input), 'UTF-8');
        $text = preg_replace('/^\d+[\s_]+/u', '', $text) ?? $text;
        return daily_briefing_option_normalize($text);
    }

    /** True als $value (NL/EN/DE, met of zonder nummerprefix) gelijk is aan een van de aliassen. */
    function daily_briefing_option_matches($value, array $candidates): bool
    {
        $normalizedValue = daily_briefing_option_normalize($value);
        if ($normalizedValue === '') {
            return false;
        }
        $bareValue = daily_briefing_option_normalize_bare($value);

        foreach ($candidates as $candidate) {
            $c = daily_briefing_option_normalize($candidate);
            if ($c === '') {
                continue;
            }
            if ($c === $normalizedValue || $c === $bareValue || daily_briefing_option_normalize_bare($candidate) === $bareValue) {
                return true;
            }
        }

        return false;
    }
}
