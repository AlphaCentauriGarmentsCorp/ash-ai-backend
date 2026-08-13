<?php

namespace App\Support\Storefront\Stock;

use SimpleXMLElement;
use ZipArchive;

/**
 * The read half of the .xlsx plumbing (see InventoryWorkbook for why this is
 * hand-rolled rather than PhpSpreadsheet).
 *
 * Only what the bulk import needs: sheet names, and every non-empty cell as
 * trimmed text keyed [rowNumber][columnNumber]. The importer re-parses numbers
 * and booleans itself, so nothing here tries to be clever about types.
 *
 * Dates are deliberately NOT decoded from Excel serial numbers. No importable
 * column is a date — the workbook's only date-ish columns (Created, Updated) are
 * read-only and ignored by the parser — so a serial would only ever reach this
 * code as a mis-typed cell, and a bare number is a more honest thing to hand
 * back than a date guessed from a number format.
 *
 * SECURITY. The archive is opened with the entity loader left at PHP 8's safe
 * default and LIBXML_NONET set, so a workbook carrying a DOCTYPE cannot pull in
 * a local file or a remote URL (the XXE / billion-laughs shape that hits every
 * naive spreadsheet parser). Part names are read from the archive index rather
 * than followed as filesystem paths, so a crafted "../" relationship target
 * cannot escape the zip.
 */
class XlsxReader
{
    /**
     * @return array<int, array{name: string, cells: array<int, array<int, string>>}>
     *                                                                               every worksheet in workbook order
     */
    public static function read(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('That file is not a readable .xlsx workbook.');
        }

        try {
            $workbook = self::xml($zip, 'xl/workbook.xml');
            if ($workbook === null) {
                throw new \RuntimeException('The file has no workbook part — is it really an .xlsx?');
            }

            $targets = self::relationshipTargets($zip);
            $shared = self::sharedStrings($zip);

            $sheets = [];
            foreach ($workbook->sheets->sheet ?? [] as $sheetNode) {
                $rid = (string) $sheetNode->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')->id;
                $target = $targets[$rid] ?? null;
                if ($target === null) {
                    continue;
                }

                $part = self::resolvePart($target);
                $sheetXml = self::xml($zip, $part);
                if ($sheetXml === null) {
                    continue;
                }

                $sheets[] = [
                    'name' => (string) $sheetNode['name'],
                    'cells' => self::cells($sheetXml, $shared),
                ];
            }

            if ($sheets === []) {
                throw new \RuntimeException('The file has no worksheets.');
            }

            return $sheets;
        } finally {
            $zip->close();
        }
    }

    private static function xml(ZipArchive $zip, string $part): ?SimpleXMLElement
    {
        $raw = $zip->getFromName($part);
        if ($raw === false) {
            return null;
        }

        $xml = @simplexml_load_string($raw, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);

        return $xml === false ? null : $xml;
    }

    /** rId => part path, from xl/_rels/workbook.xml.rels. */
    private static function relationshipTargets(ZipArchive $zip): array
    {
        $rels = self::xml($zip, 'xl/_rels/workbook.xml.rels');
        if ($rels === null) {
            return [];
        }

        $targets = [];
        foreach ($rels->Relationship ?? [] as $rel) {
            $targets[(string) $rel['Id']] = (string) $rel['Target'];
        }

        return $targets;
    }

    /**
     * A relationship target is relative to xl/. Any leading slash or "../" is
     * stripped rather than resolved: the only legitimate targets here live under
     * xl/, and a part name is a zip index key, never a filesystem path.
     */
    private static function resolvePart(string $target): string
    {
        $target = str_replace('\\', '/', $target);
        $target = preg_replace('#(^/+)|(\.\./)#', '', $target) ?? $target;

        return str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
    }

    /** The shared string table, rich-text runs flattened to plain text. */
    private static function sharedStrings(ZipArchive $zip): array
    {
        $xml = self::xml($zip, 'xl/sharedStrings.xml');
        if ($xml === null) {
            return [];
        }

        $strings = [];
        foreach ($xml->si ?? [] as $si) {
            $strings[] = self::flattenText($si);
        }

        return $strings;
    }

    /**
     * Concatenate every <t> under a node — a plain string has one, a rich-text
     * string has one per formatting run and they have to be joined in order.
     */
    private static function flattenText(SimpleXMLElement $node): string
    {
        $text = '';
        foreach ($node->xpath('.//*[local-name()="t"]') ?: [] as $t) {
            $text .= (string) $t;
        }

        return $text;
    }

    /** @return array<int, array<int, string>> [rowNumber][colNumber] => text */
    private static function cells(SimpleXMLElement $sheet, array $shared): array
    {
        $out = [];

        foreach ($sheet->sheetData->row ?? [] as $row) {
            $rowNumber = (int) $row['r'];
            $fallbackCol = 0;

            foreach ($row->c ?? [] as $cell) {
                $fallbackCol++;
                $ref = (string) $cell['r'];
                // r is optional in the schema; when absent, cells are positional.
                $colNumber = preg_match('/^([A-Z]+)/', $ref, $m)
                    ? InventoryWorkbook::columnIndex($m[1])
                    : $fallbackCol;
                $fallbackCol = $colNumber;

                $text = self::cellText($cell, $shared);
                if ($text === '') {
                    continue;
                }

                if ($rowNumber === 0) {
                    continue;
                }
                $out[$rowNumber][$colNumber] = $text;
            }
        }

        return $out;
    }

    private static function cellText(SimpleXMLElement $cell, array $shared): string
    {
        $type = (string) $cell['t'];

        return trim(match ($type) {
            's' => $shared[(int) $cell->v] ?? '',
            'inlineStr' => isset($cell->is) ? self::flattenText($cell->is) : '',
            // A cached formula result, as text or as a number.
            'str' => (string) $cell->v,
            'b' => ((string) $cell->v) === '1' ? 'TRUE' : 'FALSE',
            // 'e' is an error cell (#REF!, #N/A). It carries no usable value.
            'e' => '',
            default => (string) $cell->v,
        });
    }
}
