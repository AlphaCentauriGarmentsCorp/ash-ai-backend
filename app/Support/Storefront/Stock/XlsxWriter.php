<?php

namespace App\Support\Storefront\Stock;

/**
 * A dependency-free .xlsx writer: sheets of rows in, workbook bytes out.
 *
 * WHY THIS EXISTS. The source built its Analytics export with PhpSpreadsheet
 * (App\Support\ReportBuilder — a styled, colour-coded, 828-line workbook). This
 * backend does not have phpoffice/phpspreadsheet in composer.json, and adding it
 * is a composer run, which the module is not allowed to perform. The choice was
 * therefore: ship an export button that always 503s, or ship one that downloads a
 * real workbook with every figure the source computed and none of its styling.
 *
 * This is the second. OrderReport::sheets() produces the same sheet set the
 * source's workbook had (FINANCE · ORDERS · INVENTORY) with the same columns and
 * the same numbers; what is missing is the palette, the merged title bands, the
 * conditional colours and the number formats. Bold headers are the only styling
 * kept, because an unheadered sheet is genuinely hard to read.
 *
 * TO RESTORE THE STYLED WORKBOOK: `composer require phpoffice/phpspreadsheet`,
 * copy the source's App\Support\ReportBuilder into App\Support\Storefront\Stock, and call it
 * from OrdersController::report() in place of this class. The aggregation it
 * expects is already computed — OrderReport::build() returns exactly the payload
 * ReportBuilder::build() takes.
 *
 * The output is minimal OOXML: inline strings (no shared-string table), one
 * styles part with a normal and a bold cell format. Written with ZipArchive,
 * which ships with PHP; callers check available() first.
 */
class XlsxWriter
{
    private const NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const NS_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private const NS_PKG_REL = 'http://schemas.openxmlformats.org/package/2006/relationships';

    public static function available(): bool
    {
        return class_exists(\ZipArchive::class);
    }

    /**
     * @param  array<string, array<int, array<int, mixed>>>  $sheets  name => rows of cells
     * @return string the .xlsx bytes
     *
     * @throws \RuntimeException when the zip extension is missing or the archive cannot be written
     */
    public static function build(array $sheets): string
    {
        if (! self::available()) {
            throw new \RuntimeException('The zip extension is required to build an .xlsx file.');
        }

        if ($sheets === []) {
            $sheets = ['Report' => [['No data']]];
        }

        $names = self::uniqueSheetNames(array_keys($sheets));
        $rowsBySheet = array_values($sheets);

        $path = tempnam(sys_get_temp_dir(), 'stock-xlsx-');

        if ($path === false) {
            throw new \RuntimeException('Could not create a temporary file for the workbook.');
        }

        $zip = new \ZipArchive;

        if ($zip->open($path, \ZipArchive::OVERWRITE | \ZipArchive::CREATE) !== true) {
            @unlink($path);

            throw new \RuntimeException('Could not open the workbook archive for writing.');
        }

        $zip->addFromString('[Content_Types].xml', self::contentTypes(count($names)));
        $zip->addFromString('_rels/.rels', self::rootRels());
        $zip->addFromString('xl/workbook.xml', self::workbook($names));
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::workbookRels(count($names)));
        $zip->addFromString('xl/styles.xml', self::styles());

        foreach ($rowsBySheet as $index => $rows) {
            $zip->addFromString('xl/worksheets/sheet'.($index + 1).'.xml', self::sheet($rows));
        }

        $zip->close();

        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    /**
     * Excel sheet names: 31 characters, no []:*?/\ and no duplicates. A clash is
     * suffixed rather than dropped, so no sheet ever silently disappears.
     *
     * @param  array<int, string>  $names
     * @return array<int, string>
     */
    private static function uniqueSheetNames(array $names): array
    {
        $used = [];
        $out = [];

        foreach ($names as $raw) {
            $name = trim((string) preg_replace('/[\[\]:*?\/\\\\]/', ' ', (string) $raw));
            $name = $name !== '' ? mb_substr($name, 0, 31) : 'Sheet';

            $candidate = $name;
            $n = 2;

            while (isset($used[mb_strtolower($candidate)])) {
                $suffix = ' ('.$n.')';
                $candidate = mb_substr($name, 0, 31 - mb_strlen($suffix)).$suffix;
                $n++;
            }

            $used[mb_strtolower($candidate)] = true;
            $out[] = $candidate;
        }

        return $out;
    }

    private static function contentTypes(int $sheetCount): string
    {
        $overrides = '';

        for ($i = 1; $i <= $sheetCount; $i++) {
            $overrides .= '<Override PartName="/xl/worksheets/sheet'.$i.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .$overrides
            .'</Types>';
    }

    private static function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="'.self::NS_PKG_REL.'">'
            .'<Relationship Id="rId1" Type="'.self::NS_REL.'/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    /** @param array<int, string> $names */
    private static function workbook(array $names): string
    {
        $sheets = '';

        foreach ($names as $index => $name) {
            $sheets .= '<sheet name="'.self::escape($name).'" sheetId="'.($index + 1).'" r:id="rId'.($index + 1).'"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="'.self::NS_MAIN.'" xmlns:r="'.self::NS_REL.'">'
            .'<sheets>'.$sheets.'</sheets>'
            .'</workbook>';
    }

    private static function workbookRels(int $sheetCount): string
    {
        $rels = '';

        for ($i = 1; $i <= $sheetCount; $i++) {
            $rels .= '<Relationship Id="rId'.$i.'" Type="'.self::NS_REL.'/worksheet" Target="worksheets/sheet'.$i.'.xml"/>';
        }

        $rels .= '<Relationship Id="rId'.($sheetCount + 1).'" Type="'.self::NS_REL.'/styles" Target="styles.xml"/>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="'.self::NS_PKG_REL.'">'.$rels.'</Relationships>';
    }

    /** Two cell formats: 0 = normal, 1 = bold (the header row). */
    private static function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="'.self::NS_MAIN.'">'
            .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>';
    }

    /** @param array<int, array<int, mixed>> $rows */
    private static function sheet(array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="'.self::NS_MAIN.'"><sheetData>';

        $rowNumber = 0;

        foreach ($rows as $row) {
            $rowNumber++;

            if (! is_array($row) || $row === []) {
                continue;
            }

            // Row 1 is the header on every sheet the report builds.
            $style = $rowNumber === 1 ? ' s="1"' : '';

            $cells = '';
            $column = 0;

            foreach ($row as $value) {
                $column++;

                if ($value === null || $value === '') {
                    continue;
                }

                $ref = self::columnLetter($column).$rowNumber;

                if (is_bool($value)) {
                    $value = $value ? 'Yes' : 'No';
                }

                if (is_int($value) || is_float($value)) {
                    $cells .= '<c r="'.$ref.'"'.$style.'><v>'.self::number($value).'</v></c>';

                    continue;
                }

                $cells .= '<c r="'.$ref.'"'.$style.' t="inlineStr"><is><t xml:space="preserve">'
                    .self::escape((string) $value).'</t></is></c>';
            }

            if ($cells === '') {
                continue;
            }

            $xml .= '<row r="'.$rowNumber.'"'.$style.'>'.$cells.'</row>';
        }

        return $xml.'</sheetData></worksheet>';
    }

    private static function number(int|float $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        // Excel wants a plain decimal, not 1.0E+15 and not a thousands separator.
        if (! is_finite($value)) {
            return '0';
        }

        return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.') ?: '0';
    }

    private static function columnLetter(int $index): string
    {
        $letters = '';

        while ($index > 0) {
            $index--;
            $letters = chr(65 + ($index % 26)).$letters;
            $index = intdiv($index, 26);
        }

        return $letters === '' ? 'A' : $letters;
    }

    /** XML text, with the control characters Excel refuses stripped out. */
    private static function escape(string $value): string
    {
        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value);

        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
