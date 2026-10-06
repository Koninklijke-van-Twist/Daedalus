<?php
/**
 * Tekstfuncties voor de daily briefing en de aandachtspunten in de dagmail:
 * - PII-filter (telefoon, e-mail, namen na aanspreekvormen, bekende namen, handtekeningregels)
 * - has_notes (lege/triviale opmerkingen herkennen)
 * - secties uit pdftotext-uitvoer (fallback zonder xlsm)
 * - veilige, simpele Markdown → HTML voor e-mail
 * - Nederlandse datumlabels ('7 oktober 2026')
 */

/**
 * Constants
 */
const BRIEFING_TEXT_MAX_LENGTH = 2000;
const BRIEFING_MARKDOWN_MAX_LENGTH = 16000;
const BRIEFING_NL_MONTHS = [
    1 => 'januari', 2 => 'februari', 3 => 'maart', 4 => 'april', 5 => 'mei', 6 => 'juni',
    7 => 'juli', 8 => 'augustus', 9 => 'september', 10 => 'oktober', 11 => 'november', 12 => 'december',
];

/**
 * Functies
 */
function briefing_nl_date_label(string $ymd): string
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', trim($ymd));
    if (!($date instanceof DateTimeImmutable) || $date->format('Y-m-d') !== trim($ymd)) {
        return trim($ymd);
    }

    return (int) $date->format('j') . ' ' . BRIEFING_NL_MONTHS[(int) $date->format('n')] . ' ' . $date->format('Y');
}

function briefing_normalize_whitespace(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace('/[^\P{C}\n\t]/u', '', $text) ?? $text;
    $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text) ?? $text;
    $text = preg_replace('/ *\n */', "\n", $text) ?? $text;
    $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

    return trim($text);
}

/**
 * Bouwt naamvarianten uit BC-/indexnamen zoals "Mierlo, Ricardo van 1574" of "Jan de Vries".
 * Alleen varianten met minstens twee woorden (losse voor- of achternamen zijn te ambigu).
 *
 * @param list<string> $names
 * @return list<string>
 */
function briefing_name_variants(array $names): array
{
    $variants = [];
    foreach ($names as $name) {
        $clean = trim(preg_replace('/\s+\d+\s*$/', '', (string) $name) ?? '');
        $clean = trim(preg_replace('/\s+/', ' ', $clean) ?? '');
        if ($clean === '' || mb_strlen($clean) < 5) {
            continue;
        }

        $candidates = [$clean];
        if (strpos($clean, ',') !== false) {
            [$last, $first] = array_map('trim', explode(',', $clean, 2));
            if ($last !== '' && $first !== '') {
                $candidates[] = $first . ' ' . $last;
                $candidates[] = $last . ', ' . $first;
                $candidates[] = $last . ' ' . $first;
            }
        }

        foreach ($candidates as $candidate) {
            if (count(preg_split('/[\s,]+/', $candidate, -1, PREG_SPLIT_NO_EMPTY) ?: []) >= 2) {
                $variants[mb_strtolower($candidate, 'UTF-8')] = $candidate;
            }
        }
    }

    $result = array_values($variants);
    usort($result, static fn(string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

    return $result;
}

/**
 * Eén regex voor alle naamvarianten (gecachet per namenlijst); '' als er geen namen zijn.
 *
 * @param list<string> $knownNames
 */
function briefing_known_names_pattern(array $knownNames): string
{
    static $cache = [];
    if ($knownNames === []) {
        return '';
    }

    $cacheKey = sha1(implode("\n", $knownNames));
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $alternatives = [];
    foreach (briefing_name_variants($knownNames) as $variant) {
        $words = preg_split('/[\s,]+/u', $variant, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $alternatives[] = implode('[\s,]+', array_map(static fn(string $word): string => preg_quote($word, '/'), $words));
    }

    if (count($cache) > 20) {
        $cache = [];
    }

    return $cache[$cacheKey] = $alternatives === [] ? '' : '/(?<![\p{L}])(?:' . implode('|', $alternatives) . ')(?![\p{L}])/iu';
}

/**
 * Verwijdert persoonsgegevens uit vrije tekst. Bedoeld als vangnet: de bron is al beperkt
 * tot de secties Opmerkingen/Storingsdiagnose.
 *
 * @param list<string> $knownNames extra namen (monteurs, engineers uit de index)
 */
function briefing_scrub_pii(string $text, array $knownNames = []): string
{
    // Ongeldige UTF-8 laat /u-regexen falen (null/false); dan zou er niets gefilterd worden.
    if (!mb_check_encoding($text, 'UTF-8')) {
        $text = mb_scrub($text, 'UTF-8');
    }
    $text = briefing_normalize_whitespace($text);
    if ($text === '') {
        return '';
    }

    // Regels met een kop-/handtekeninglabel ("Naam:", "Tel: ...", "Handtekening ...") helemaal weglaten.
    $lines = [];
    foreach (explode("\n", $text) as $line) {
        if (preg_match('/^\s*(naam|name|handtekening|signature|tel\.?|telefoon(nummer)?|telephone|phone|mobiel|gsm|e-?mail(adres)?|contactpersoon|contact person)\s*(:|$)/iu', $line) === 1
            || preg_match('/^\s*(handtekening|signature)\b/iu', $line) === 1) {
            continue;
        }
        $lines[] = $line;
    }
    $text = implode("\n", $lines);

    // E-mailadressen.
    $text = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', '[e-mail]', $text) ?? $text;

    // Telefoonnummers: internationaal (+31 / 0031 / +32 ...) en Nederlands 0xx-xxxxxxx / 06-xxxxxxxx.
    $text = preg_replace('/(?<![\w+])(?:\+|00)\d{2,3}[\s.\-]?(?:\(0\)[\s.\-]?)?\d(?:[\s.\-]?\d){7,10}(?!\d)/u', '[telefoon]', $text) ?? $text;
    $text = preg_replace('/(?<![\w\/.,])0\d(?:[\s.\-]?\d){8}(?![\d,.]\d|\w)/u', '[telefoon]', $text) ?? $text;

    // Namen na aanspreekvormen/contactwoorden: "dhr. Jansen", "mevrouw de Vries", "contactpersoon Piet".
    $namePart = '(?:(?:van|de|der|den|ter|ten|te|het|v\.?d\.?|la|le|el)\s+)*\p{Lu}[\p{L}\'\-]+';
    $text = preg_replace(
        '/(?<![\p{L}])((?i:dhr\.?|mevr\.?|mw\.?|mr\.?|mrs\.?|meneer|mevrouw|de heer|contactpersoon|gesproken met|overleg met|i\.o\.m\.?|t\.a\.v\.?))(\s*[:\-]?\s+)(?:\p{Lu}\.\s*){0,3}' . $namePart . '(?:\s+' . $namePart . '){0,2}/u',
        '$1$2[naam]',
        $text
    ) ?? $text;

    // Bekende namen (monteurs/engineers), als één gecachte regex.
    $namePattern = briefing_known_names_pattern($knownNames);
    if ($namePattern !== '') {
        $text = preg_replace($namePattern, '[naam]', $text) ?? $text;
    }

    $text = briefing_normalize_whitespace($text);
    if (mb_strlen($text) > BRIEFING_TEXT_MAX_LENGTH) {
        $text = rtrim(mb_substr($text, 0, BRIEFING_TEXT_MAX_LENGTH)) . '…';
    }

    return $text;
}

/**
 * True als de tekst leeg of triviaal is ('-', 'geen', 'n.v.t.', 'geen opmerkingen', ...).
 */
function briefing_is_trivial_note(?string $text): bool
{
    $normalized = mb_strtolower(trim((string) $text), 'UTF-8');
    $normalized = preg_replace('/[\p{P}\p{S}]+/u', ' ', $normalized) ?? $normalized;
    $normalized = trim(preg_replace('/\s+/u', ' ', $normalized) ?? $normalized);
    if ($normalized === '') {
        return true;
    }

    $compact = str_replace(' ', '', $normalized);
    $trivial = [
        'geen', 'nee', 'niets', 'niks', 'leeg', 'x', '0', 'ok', 'oke', 'oké', 'okay', 'goed', 'akkoord',
        'nvt', 'na', 'none', 'nil', 'no', 'nothing', 'nocomments', 'nocomment', 'noremarks',
        'geenopmerkingen', 'geenopmerking', 'geenbijzonderheden', 'geenbijzonderheid', 'geenstoring',
        'geenstoringen', 'geenactievestoring', 'geenactievestoringen', 'geenadvies', 'inorde', 'allesinorde',
        'allesok', 'allesgoed', 'geenbijzonderhedengevonden', 'geenafwijkingen', 'geenafwijking',
        'nofailure', 'nofailures', 'noissues', 'nvtgeen', 'zieboven', 'idem',
    ];

    return in_array($compact, $trivial, true);
}

/**
 * @param list<array{opmerkingen?: string, storingsdiagnose?: string}> $reports
 */
function briefing_reports_have_notes(array $reports): bool
{
    foreach ($reports as $report) {
        if (!briefing_is_trivial_note((string) ($report['opmerkingen'] ?? ''))
            || !briefing_is_trivial_note((string) ($report['storingsdiagnose'] ?? ''))) {
            return true;
        }
    }

    return false;
}

/**
 * Haalt de secties Opmerkingen, Storingsdiagnose en Advies uit pdftotext-uitvoer.
 * Een sectie begint bij een regel die alleen uit de kop bestaat en loopt tot de volgende bekende kop.
 *
 * @return array{opmerkingen: string, storingsdiagnose: string}
 */
function briefing_pdf_sections(string $text): array
{
    $text = str_replace(["\r\n", "\r", "\f"], "\n", $text);
    $heads = [
        'opmerkingen' => 'opmerkingen', 'comments' => 'opmerkingen', 'opmerking' => 'opmerkingen',
        'advies' => 'opmerkingen', 'aanbevelingen' => 'opmerkingen', 'advice' => 'opmerkingen',
        'storingsdiagnose' => 'storingsdiagnose', 'failure diagnose' => 'storingsdiagnose', 'failure diagnosis' => 'storingsdiagnose',
    ];
    $stops = '/^(uitgevoerde\b|executed\b|handtekening|signature|situatie bij|situation (on|at)|naam\s*:|name\s*:|pagina \d|page \d|meetwaarde|resultaat\b|controlepunten|bevindingen|werkzaamheden\b)/iu';

    $sections = ['opmerkingen' => [], 'storingsdiagnose' => []];
    $current = null;
    foreach (explode("\n", $text) as $line) {
        $trimmed = trim($line);
        $key = mb_strtolower(rtrim($trimmed, ': '), 'UTF-8');
        if (isset($heads[$key])) {
            $current = $heads[$key];
            continue;
        }

        if ($current === null) {
            continue;
        }

        if ($trimmed !== '' && preg_match($stops, $trimmed) === 1) {
            $current = null;
            continue;
        }

        $sections[$current][] = $trimmed;
    }

    return [
        'opmerkingen' => briefing_normalize_whitespace(implode("\n", $sections['opmerkingen'])),
        'storingsdiagnose' => briefing_normalize_whitespace(implode("\n", $sections['storingsdiagnose'])),
    ];
}

/**
 * Inline Markdown (vet, links) op één regel; alles wordt ge-escaped.
 * Links alleen als http(s); andere schema's blijven platte tekst.
 */
function briefing_markdown_inline(string $text): string
{
    $html = '';
    $offset = 0;
    if (preg_match_all('/\[([^\]\n]{1,300})\]\(([^)\s]{1,2000})\)/u', $text, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) > 0) {
        foreach ($matches as $match) {
            $start = $match[0][1];
            $html .= briefing_markdown_emphasis(substr($text, $offset, $start - $offset));
            $label = $match[1][0];
            $url = $match[2][0];
            if (preg_match('#^https?://[^\s<>"\'`]+$#i', $url) === 1) {
                $html .= '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" style="color:#0f5bb7;">'
                    . briefing_markdown_emphasis($label) . '</a>';
            } else {
                $html .= briefing_markdown_emphasis($label);
            }
            $offset = $start + strlen($match[0][0]);
        }
    }

    return $html . briefing_markdown_emphasis(substr($text, $offset));
}

function briefing_markdown_emphasis(string $text): string
{
    $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $escaped = preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/u', '<strong>$1</strong>', $escaped) ?? $escaped;
    $escaped = preg_replace('/(?<![\w])__(?=\S)(.+?)(?<=\S)__(?![\w])/u', '<strong>$1</strong>', $escaped) ?? $escaped;

    return $escaped;
}

/**
 * Simpele, veilige Markdown → HTML voor e-mail: kopjes (#..######), bullets (-, *, +),
 * genummerde lijsten, alinea's, vet en http(s)-links. Raw HTML wordt ge-escaped.
 */
function briefing_markdown_to_html(string $markdown): string
{
    $markdown = preg_replace('/^\x{FEFF}/u', '', $markdown) ?? $markdown;
    if (mb_strlen($markdown) > BRIEFING_MARKDOWN_MAX_LENGTH) {
        $markdown = mb_substr($markdown, 0, BRIEFING_MARKDOWN_MAX_LENGTH) . "\n\n…";
    }
    $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
    $markdown = preg_replace('/[^\P{C}\n\t]/u', '', $markdown) ?? '';

    $html = '';
    $paragraph = [];
    $listType = '';

    $flushParagraph = static function () use (&$paragraph, &$html): void {
        if ($paragraph !== []) {
            $html .= '<p style="margin:4px 0;">' . implode('<br />', array_map('briefing_markdown_inline', $paragraph)) . '</p>';
            $paragraph = [];
        }
    };
    $closeList = static function () use (&$listType, &$html): void {
        if ($listType !== '') {
            $html .= '</' . $listType . '>';
            $listType = '';
        }
    };

    foreach (explode("\n", $markdown) as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') {
            $flushParagraph();
            $closeList();
            continue;
        }

        if (preg_match('/^(#{1,6})\s+(.+?)\s*#*$/u', $trimmed, $m) === 1) {
            $flushParagraph();
            $closeList();
            $size = strlen($m[1]) <= 2 ? '15px' : '14px';
            $html .= '<p style="margin:8px 0 4px 0;font-size:' . $size . ';font-weight:700;color:#152233;">' . briefing_markdown_inline($m[2]) . '</p>';
            continue;
        }

        if (preg_match('/^([-*+]|\d{1,3}[.)])\s+(.+)$/u', $trimmed, $m) === 1) {
            $flushParagraph();
            $wanted = ctype_digit(substr($m[1], 0, 1)) ? 'ol' : 'ul';
            if ($listType !== $wanted) {
                $closeList();
                $html .= '<' . $wanted . ' style="margin:4px 0 4px 20px;padding:0;">';
                $listType = $wanted;
            }
            $html .= '<li style="margin:2px 0;">' . briefing_markdown_inline($m[2]) . '</li>';
            continue;
        }

        if (preg_match('/^(-{3,}|\*{3,}|_{3,})$/', $trimmed) === 1) {
            $flushParagraph();
            $closeList();
            continue;
        }

        $closeList();
        $paragraph[] = preg_replace('/^>\s?/', '', $trimmed) ?? $trimmed;
    }

    $flushParagraph();
    $closeList();

    return $html;
}
