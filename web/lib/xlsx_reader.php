<?php
/**
 * Pure-PHP lezer voor .xlsx/.xlsm (ZipArchive + XMLReader), alleen wat de briefing nodig heeft:
 * - tekst uit benoemde bereiken (workbook.xml definedNames → blad/bereik → cellen)
 * - rijen van één blad streamen (voor de index servicerapporten.xlsx)
 *
 * Geen formules uitrekenen: de opgeslagen (gecachte) celwaarde wordt gebruikt.
 */

/**
 * Constants
 */
const XLSX_NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
const XLSX_NS_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
const XLSX_MAX_ENTRY_BYTES = 96 * 1024 * 1024;
const XLSX_MAX_RANGE_CELLS = 500;

/**
 * Functies
 */
function xlsx_open(string $path): ZipArchive
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('PHP-extensie zip ontbreekt.');
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Excel-bestand kon niet worden geopend.');
    }

    return $zip;
}

function xlsx_entry(ZipArchive $zip, string $name): ?string
{
    $name = ltrim($name, '/');
    $stat = $zip->statName($name);
    if (!is_array($stat)) {
        return null;
    }

    if ((int) ($stat['size'] ?? 0) > XLSX_MAX_ENTRY_BYTES) {
        throw new RuntimeException('Excel-onderdeel is te groot: ' . $name);
    }

    $contents = $zip->getFromName($name);
    return is_string($contents) ? $contents : null;
}

function xlsx_reader_for(string $xml): XMLReader
{
    $reader = new XMLReader();
    if (!$reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
        throw new RuntimeException('Excel-XML kon niet worden gelezen.');
    }

    return $reader;
}

/**
 * Tekst van een <si>/<is>-element: alle <t> behalve fonetische hulptekst (<rPh>).
 */
function xlsx_text_from_node(DOMNode $node): string
{
    $text = '';
    foreach ($node->childNodes as $child) {
        if (!($child instanceof DOMElement)) {
            continue;
        }
        if ($child->localName === 't') {
            $text .= $child->textContent;
        } elseif ($child->localName === 'r') {
            foreach ($child->childNodes as $runChild) {
                if ($runChild instanceof DOMElement && $runChild->localName === 't') {
                    $text .= $runChild->textContent;
                }
            }
        }
    }

    return $text;
}

/**
 * @return list<string>
 */
function xlsx_shared_strings(ZipArchive $zip): array
{
    $xml = xlsx_entry($zip, 'xl/sharedStrings.xml');
    if ($xml === null) {
        return [];
    }

    $strings = [];
    $reader = xlsx_reader_for($xml);
    while ($reader->read()) {
        if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'si') {
            break;
        }
    }
    while ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'si') {
        $node = $reader->expand();
        $strings[] = $node instanceof DOMNode ? xlsx_text_from_node($node) : '';
        if (!$reader->next('si')) {
            break;
        }
    }
    $reader->close();

    return $strings;
}

function xlsx_resolve_target(string $target): string
{
    $target = str_replace('\\', '/', $target);
    if (strncmp($target, '/', 1) === 0) {
        return ltrim($target, '/');
    }

    $parts = [];
    foreach (explode('/', 'xl/' . $target) as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..') {
            array_pop($parts);
            continue;
        }
        $parts[] = $part;
    }

    return implode('/', $parts);
}

/**
 * @return array{sheets: list<array{name: string, path: string}>, defined_names: list<array{name: string, local_sheet_id: ?int, ref: string}>}
 */
function xlsx_workbook_info(ZipArchive $zip): array
{
    $workbookXml = xlsx_entry($zip, 'xl/workbook.xml');
    if ($workbookXml === null) {
        throw new RuntimeException('Geen workbook.xml in Excel-bestand.');
    }

    $relsXml = xlsx_entry($zip, 'xl/_rels/workbook.xml.rels');
    $targets = [];
    if ($relsXml !== null) {
        $reader = xlsx_reader_for($relsXml);
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'Relationship') {
                $targets[(string) $reader->getAttribute('Id')] = xlsx_resolve_target((string) $reader->getAttribute('Target'));
            }
        }
        $reader->close();
    }

    $sheets = [];
    $definedNames = [];
    $reader = xlsx_reader_for($workbookXml);
    while ($reader->read()) {
        if ($reader->nodeType !== XMLReader::ELEMENT) {
            continue;
        }

        if ($reader->localName === 'sheet') {
            $relId = (string) $reader->getAttributeNs('id', XLSX_NS_REL);
            $sheets[] = [
                'name' => (string) $reader->getAttribute('name'),
                'path' => $targets[$relId] ?? '',
            ];
        } elseif ($reader->localName === 'definedName') {
            $localSheetId = $reader->getAttribute('localSheetId');
            $definedNames[] = [
                'name' => (string) $reader->getAttribute('name'),
                'local_sheet_id' => $localSheetId === null || $localSheetId === '' ? null : (int) $localSheetId,
                'ref' => trim($reader->readString()),
            ];
        }
    }
    $reader->close();

    return ['sheets' => $sheets, 'defined_names' => $definedNames];
}

/**
 * 'A1' → [rij, kolom] (1-based). Geeft null bij een ongeldige verwijzing.
 *
 * @return array{0: int, 1: int}|null
 */
function xlsx_cell_coordinates(string $cell): ?array
{
    if (preg_match('/^\$?([A-Z]{1,3})\$?(\d{1,7})$/i', trim($cell), $match) !== 1) {
        return null;
    }

    $column = 0;
    foreach (str_split(strtoupper($match[1])) as $char) {
        $column = $column * 26 + (ord($char) - 64);
    }

    return [(int) $match[2], $column];
}

/**
 * Splitst een definedName-verwijzing in gebieden, bijv.
 *   "Rapport!$A$102"  /  "'Blad 1'!$A$1:$M$3,Rapport!$B$2"
 *
 * @return list<array{sheet: string, from: array{0: int, 1: int}, to: array{0: int, 1: int}}>
 */
function xlsx_parse_reference(string $reference): array
{
    $areas = [];
    $pattern = "/(?:'((?:[^']|'')+)'|([^'!,()\\s]+))!(\\$?[A-Z]{1,3}\\$?\\d{1,7})(?::(\\$?[A-Z]{1,3}\\$?\\d{1,7}))?/i";
    if (preg_match_all($pattern, $reference, $matches, PREG_SET_ORDER) === false) {
        return [];
    }

    foreach ($matches as $match) {
        $sheet = $match[1] !== '' ? str_replace("''", "'", $match[1]) : $match[2];
        $from = xlsx_cell_coordinates($match[3]);
        $to = isset($match[4]) && $match[4] !== '' ? xlsx_cell_coordinates($match[4]) : $from;
        if ($from === null || $to === null) {
            continue;
        }

        $areas[] = [
            'sheet' => $sheet,
            'from' => [min($from[0], $to[0]), min($from[1], $to[1])],
            'to' => [max($from[0], $to[0]), max($from[1], $to[1])],
        ];
    }

    return $areas;
}

/**
 * Zet de reader op het eerste <row>-element (of het einde van het document).
 */
function xlsx_move_to_first_row(XMLReader $reader): void
{
    while ($reader->read()) {
        if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'row') {
            return;
        }
    }
}

/**
 * Tekst van één <c>-cel (shared string, inline string, getal of gecachte formulewaarde).
 */
function xlsx_cell_value(DOMElement $cell, array $sharedStrings): string
{
    $type = $cell->getAttribute('t');
    $valueNode = null;
    $inlineNode = null;
    foreach ($cell->childNodes as $child) {
        if ($child instanceof DOMElement) {
            if ($child->localName === 'v') {
                $valueNode = $child;
            } elseif ($child->localName === 'is') {
                $inlineNode = $child;
            }
        }
    }

    if ($type === 'inlineStr') {
        return $inlineNode !== null ? xlsx_text_from_node($inlineNode) : '';
    }

    if ($valueNode === null) {
        return '';
    }

    $raw = $valueNode->textContent;
    if ($type === 's') {
        $index = (int) $raw;
        return (string) ($sharedStrings[$index] ?? '');
    }

    if ($type === 'e') {
        return '';
    }

    if ($type === 'b') {
        return $raw === '1' ? 'WAAR' : 'ONWAAR';
    }

    return $raw;
}

/**
 * Leest de gevraagde gebieden uit één blad.
 *
 * @param list<array{from: array{0: int, 1: int}, to: array{0: int, 1: int}}> $areas
 * @return array<string, string> 'rij:kolom' => tekst
 */
function xlsx_read_sheet_areas(ZipArchive $zip, string $sheetPath, array $sharedStrings, array $areas): array
{
    $xml = xlsx_entry($zip, $sheetPath);
    if ($xml === null || $areas === []) {
        return [];
    }

    $minRow = PHP_INT_MAX;
    $maxRow = 0;
    foreach ($areas as $area) {
        $minRow = min($minRow, $area['from'][0]);
        $maxRow = max($maxRow, $area['to'][0]);
    }

    $values = [];
    $reader = xlsx_reader_for($xml);
    xlsx_move_to_first_row($reader);
    while ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'row') {
        $rowNumber = (int) $reader->getAttribute('r');
        if ($rowNumber > $maxRow) {
            break;
        }

        if ($rowNumber >= $minRow) {
            $node = $reader->expand();
            if ($node instanceof DOMElement) {
                foreach ($node->childNodes as $cell) {
                    if (!($cell instanceof DOMElement) || $cell->localName !== 'c') {
                        continue;
                    }
                    $coordinates = xlsx_cell_coordinates($cell->getAttribute('r'));
                    if ($coordinates === null) {
                        continue;
                    }
                    foreach ($areas as $area) {
                        if ($coordinates[0] >= $area['from'][0] && $coordinates[0] <= $area['to'][0]
                            && $coordinates[1] >= $area['from'][1] && $coordinates[1] <= $area['to'][1]) {
                            $values[$coordinates[0] . ':' . $coordinates[1]] = xlsx_cell_value($cell, $sharedStrings);
                            break;
                        }
                    }
                }
            }
        }

        if (!$reader->next('row')) {
            break;
        }
    }
    $reader->close();

    return $values;
}

/**
 * Tekst uit benoemde bereiken. Namen zijn hoofdletterongevoelig; zowel werkmapbrede als
 * bladlokale definities worden gelezen. Per naam: unieke, niet-lege teksten in volgorde.
 *
 * @param list<string> $names
 * @return array<string, list<string>> lowercase naam => teksten
 */
function xlsx_named_range_texts(string $path, array $names): array
{
    $wanted = [];
    foreach ($names as $name) {
        $wanted[mb_strtolower(trim($name), 'UTF-8')] = [];
    }

    $zip = xlsx_open($path);
    try {
        $info = xlsx_workbook_info($zip);
        $sheetPaths = [];
        foreach ($info['sheets'] as $sheet) {
            $sheetPaths[mb_strtolower($sheet['name'], 'UTF-8')] = $sheet['path'];
        }

        $areasBySheet = [];
        $areaOwners = [];
        foreach ($info['defined_names'] as $definedName) {
            $key = mb_strtolower($definedName['name'], 'UTF-8');
            if (!array_key_exists($key, $wanted)) {
                continue;
            }

            foreach (xlsx_parse_reference($definedName['ref']) as $area) {
                $cellCount = ($area['to'][0] - $area['from'][0] + 1) * ($area['to'][1] - $area['from'][1] + 1);
                if ($cellCount > XLSX_MAX_RANGE_CELLS) {
                    continue;
                }

                $sheetKey = mb_strtolower($area['sheet'], 'UTF-8');
                if (!isset($sheetPaths[$sheetKey]) || $sheetPaths[$sheetKey] === '') {
                    continue;
                }

                $areasBySheet[$sheetKey][] = $area;
                $areaOwners[$sheetKey][] = $key;
            }
        }

        $sharedStrings = $areasBySheet === [] ? [] : xlsx_shared_strings($zip);
        foreach ($areasBySheet as $sheetKey => $areas) {
            $values = xlsx_read_sheet_areas($zip, $sheetPaths[$sheetKey], $sharedStrings, $areas);
            foreach ($areas as $index => $area) {
                $owner = $areaOwners[$sheetKey][$index];
                $parts = [];
                for ($row = $area['from'][0]; $row <= $area['to'][0]; $row++) {
                    for ($column = $area['from'][1]; $column <= $area['to'][1]; $column++) {
                        $text = trim((string) ($values[$row . ':' . $column] ?? ''));
                        if ($text !== '') {
                            $parts[] = $text;
                        }
                    }
                }

                $combined = trim(implode("\n", $parts));
                if ($combined !== '' && !in_array($combined, $wanted[$owner], true)) {
                    $wanted[$owner][] = $combined;
                }
            }
        }
    } finally {
        $zip->close();
    }

    return $wanted;
}

/**
 * Streamt de rijen van een blad (op naam, of het eerste blad) naar $onRow(array $cells, int $rowNumber).
 * $cells is kolomnummer (1-based) => tekst. Retourneer false uit $onRow om te stoppen.
 */
function xlsx_each_row(string $path, ?string $sheetName, callable $onRow): void
{
    $zip = xlsx_open($path);
    try {
        $info = xlsx_workbook_info($zip);
        $sheetPath = '';
        foreach ($info['sheets'] as $sheet) {
            if ($sheetName !== null && mb_strtolower($sheet['name'], 'UTF-8') === mb_strtolower($sheetName, 'UTF-8')) {
                $sheetPath = $sheet['path'];
                break;
            }
        }
        if ($sheetPath === '' && isset($info['sheets'][0])) {
            $sheetPath = $info['sheets'][0]['path'];
        }
        if ($sheetPath === '') {
            throw new RuntimeException('Geen werkblad gevonden.');
        }

        $sharedStrings = xlsx_shared_strings($zip);
        $xml = xlsx_entry($zip, $sheetPath);
        if ($xml === null) {
            throw new RuntimeException('Werkblad ontbreekt in Excel-bestand.');
        }

        $reader = xlsx_reader_for($xml);
        unset($xml);
        xlsx_move_to_first_row($reader);
        while ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'row') {
            $rowNumber = (int) $reader->getAttribute('r');
            $node = $reader->expand();
            $cells = [];
            if ($node instanceof DOMElement) {
                foreach ($node->childNodes as $cell) {
                    if (!($cell instanceof DOMElement) || $cell->localName !== 'c') {
                        continue;
                    }
                    $coordinates = xlsx_cell_coordinates($cell->getAttribute('r'));
                    if ($coordinates === null) {
                        continue;
                    }
                    $cells[$coordinates[1]] = xlsx_cell_value($cell, $sharedStrings);
                }
            }

            if ($onRow($cells, $rowNumber) === false) {
                break;
            }

            if (!$reader->next('row')) {
                break;
            }
        }
        $reader->close();
    } finally {
        $zip->close();
    }
}

/**
 * Excel-serienummer (1900-systeem) of tekstdatum → 'Y-m-d', of '' als onbekend.
 */
function xlsx_date_to_ymd(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    if (is_numeric($value)) {
        $serial = (int) floor((float) $value);
        if ($serial < 20000 || $serial > 80000) {
            return '';
        }
        return gmdate('Y-m-d', ($serial - 25569) * 86400);
    }

    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        return $m[1] . '-' . $m[2] . '-' . $m[3];
    }

    if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})/', $value, $m) === 1 && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
        return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
    }

    return '';
}
