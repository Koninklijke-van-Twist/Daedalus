<?php
/**
 * Bouwt kleine .xlsx/.xlsm-bestanden voor tests (geen echte klantdata).
 *
 * $sheets = [
 *   ['name' => 'Rapport', 'hidden' => false, 'cells' => ['A1' => 'tekst' | 123 | ['inline' => 'x'] | ['rich' => ['a', 'b']]]],
 * ]
 * $definedNames = [['name' => 'Opmerkingen', 'local' => null|int, 'ref' => "Rapport!\$A\$102"]]
 * Strings gaan standaard naar sharedStrings.xml; ['inline' => ...] wordt een inline string.
 */

function fixture_xml(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function fixture_build_xlsx(string $path, array $sheets, array $definedNames = []): void
{
    $shared = [];
    $sharedIndex = static function ($value) use (&$shared): int {
        $key = json_encode($value);
        if (!isset($shared[$key])) {
            $shared[$key] = ['index' => count($shared), 'value' => $value];
        }
        return $shared[$key]['index'];
    };

    $sheetXml = [];
    foreach ($sheets as $sheet) {
        $rows = [];
        foreach ($sheet['cells'] as $ref => $value) {
            preg_match('/^([A-Z]+)(\d+)$/', $ref, $m);
            $rows[(int) $m[2]][$ref] = $value;
        }
        ksort($rows);
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheetData>';
        foreach ($rows as $rowNumber => $cells) {
            $xml .= '<row r="' . $rowNumber . '">';
            foreach ($cells as $ref => $value) {
                if (is_int($value) || is_float($value)) {
                    $xml .= '<c r="' . $ref . '"><v>' . $value . '</v></c>';
                } elseif (is_array($value) && isset($value['inline'])) {
                    $xml .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . fixture_xml($value['inline']) . '</t></is></c>';
                } elseif (is_array($value) && isset($value['formula'])) {
                    $xml .= '<c r="' . $ref . '" t="str"><f>' . fixture_xml($value['formula']) . '</f><v>' . fixture_xml($value['value']) . '</v></c>';
                } else {
                    $xml .= '<c r="' . $ref . '" t="s"><v>' . $sharedIndex($value) . '</v></c>';
                }
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData></worksheet>';
        $sheetXml[] = $xml;
    }

    $sst = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($shared) . '" uniqueCount="' . count($shared) . '">';
    foreach ($shared as $entry) {
        $value = $entry['value'];
        if (is_array($value) && isset($value['rich'])) {
            $sst .= '<si>';
            foreach ($value['rich'] as $run) {
                $sst .= '<r><rPr><b/></rPr><t xml:space="preserve">' . fixture_xml($run) . '</t></r>';
            }
            $sst .= '<rPh sb="0" eb="1"><t>FONETISCH</t></rPh></si>';
        } else {
            $sst .= '<si><t xml:space="preserve">' . fixture_xml((string) $value) . '</t></si>';
        }
    }
    $sst .= '</sst>';

    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    foreach ($sheets as $i => $sheet) {
        $n = $i + 1;
        $workbook .= '<sheet name="' . fixture_xml($sheet['name']) . '" sheetId="' . ($n + 10) . '"' . (!empty($sheet['hidden']) ? ' state="hidden"' : '') . ' r:id="rId' . $n . '"/>';
        $rels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
    }
    $rels .= '<Relationship Id="rId99" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/></Relationships>';
    $workbook .= '</sheets>';
    if ($definedNames !== []) {
        $workbook .= '<definedNames>';
        foreach ($definedNames as $definedName) {
            $workbook .= '<definedName name="' . fixture_xml($definedName['name']) . '"'
                . (isset($definedName['local']) ? ' localSheetId="' . (int) $definedName['local'] . '"' : '')
                . '>' . fixture_xml($definedName['ref']) . '</definedName>';
        }
        $workbook .= '</definedNames>';
    }
    $workbook .= '</workbook>';

    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Kan fixture niet schrijven: ' . $path);
    }
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', $workbook);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $rels);
    $zip->addFromString('xl/sharedStrings.xml', $sst);
    foreach ($sheetXml as $i => $xml) {
        $zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', $xml);
    }
    $zip->close();
}

/**
 * Fictief servicerapport zoals de KVT-xlsm: rapportkop met PII, benoemde bereiken
 * Opmerkingen (werkmapbreed + bladlokaal) en Storingsdiagnose (bladlokaal).
 */
function fixture_build_service_report(string $path, string $opmerkingen, $storingsdiagnose): void
{
    fixture_build_xlsx($path, [
        ['name' => 'Blad1', 'hidden' => true, 'cells' => ['A1' => 'verborgen']],
        ['name' => 'Rapport', 'cells' => [
            'A5' => 'Contactpersoon', 'C5' => 'Fictieve Klant BV', 'C6' => 'Jan Fictief', 'C7' => '010-9998887',
            'A91' => 'Storingsdiagnose',
            'A92' => $storingsdiagnose,
            'A101' => 'Opmerkingen',
            'A102' => $opmerkingen,
            'A103' => 'Handtekening contactpersoon op locatie',
            'G104' => ['formula' => 'LEFT(C8,LEN(C8)-4)', 'value' => 'Testmonteur, Henk'],
        ]],
        ['name' => 'Rapport ENG', 'cells' => ['A97' => 'Failure diagnose', 'A107' => 'Comments', 'A108' => '']],
    ], [
        ['name' => 'Klantgegevens', 'ref' => "Rapport!\$C\$5:\$C\$7"],
        ['name' => 'Opmerkingen', 'ref' => "Rapport!\$A\$102"],
        ['name' => 'Opmerkingen', 'local' => 2, 'ref' => "'Rapport ENG'!\$A\$108"],
        ['name' => 'Storingsdiagnose', 'local' => 1, 'ref' => "Rapport!\$A\$92:\$M\$92"],
        ['name' => 'Storingsdiagnose', 'local' => 2, 'ref' => "'Rapport ENG'!\$A\$98"],
        ['name' => 'Kapot', 'ref' => '#REF!'],
    ]);
}

/**
 * Fictieve index zoals General/servicerapporten.xlsx.
 *
 * @param list<array{0: string, 1: string, 2: string, 3: string, 4: string}> $rows [equipmentnr, naam, wo, datum Y-m-d, engineer]
 */
function fixture_build_index(string $path, array $rows): void
{
    $cells = [
        'A1' => 'Equipmentnr', 'B1' => 'Equipment naam', 'C1' => 'Werkordernr', 'D1' => 'Accountmanager',
        'E1' => 'Datum', 'F1' => 'Engineer', 'G1' => 'Equipment URL', 'H1' => 'Draaiuren',
    ];
    foreach ($rows as $i => $row) {
        $r = $i + 2;
        $serial = (int) ((strtotime($row[3] . ' 00:00:00 UTC') / 86400) + 25569);
        $cells['A' . $r] = (int) $row[0];
        $cells['B' . $r] = $row[1];
        $cells['C' . $r] = $row[2];
        $cells['D' . $r] = 'Planning';
        $cells['E' . $r] = $serial;
        $cells['F' . $r] = $row[4];
        $cells['G' . $r] = 'https://kvtnl.sharepoint.com/sites/KVTAlgemeen/Gedeelde%20documenten/General/Equipments/' . $row[0] . ' - ' . $row[1];
        $cells['H' . $r] = '100H';
    }

    fixture_build_xlsx($path, [
        ['name' => 'Servicerapporten', 'cells' => $cells],
        ['name' => 'Dashboard', 'cells' => ['A1' => 'x']],
    ]);
}
