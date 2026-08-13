<?php

namespace App\Support\Storefront\Stock;

use ZipArchive;

/**
 * A small, self-contained OOXML (.xlsx) builder — the engine under
 * App\Support\Storefront\Stock\InventoryXlsx.
 *
 * WHY THIS EXISTS. The Stock manager builds its Inventory export and its import
 * template with PhpSpreadsheet. That package is not in Reefer_Backend's
 * composer.json and adding it is a composer run this module may not perform, so
 * both endpoints would answer 500 on a live shop until somebody noticed. The
 * subset of the format those two workbooks actually use is small — inline
 * strings, solid fills, fonts, alignment, thin borders, number formats, column
 * widths, row heights, merges, a freeze pane, an autofilter and one list
 * validation — and all of it is a few hundred lines of XML over ext-zip, which
 * IS present.
 *
 * WHY NOT App\Support\Storefront\Stock\XlsxWriter, which also writes .xlsx. That one is the
 * Analytics/Orders report writer: a deliberately plain name => rows-of-values
 * builder with two cell formats. It is the right tool for a report whose value
 * is its numbers. It cannot express the Inventory workbooks, which are a
 * designed artefact people have been reading for months — the navy banner, the
 * amber example row, the red/amber/green stock colouring, the Status dropdown.
 * Rather than bend one class into serving both, they stay separate: that one
 * stays simple, this one stays faithful.
 *
 * Deliberately NOT supported, because neither workbook needs it: formulas,
 * images, charts, conditional formatting, and cell comments. The reference
 * attaches a hover note to every header cell; that needs a VML drawing part per
 * sheet for one tooltip, so the notes ride on the template's "How to use" sheet
 * instead, where they are more discoverable anyway.
 *
 * Strings are written inline (t="inlineStr") rather than through a shared string
 * table: slightly larger files, one less part to keep consistent, and Excel,
 * LibreOffice and Google Sheets all read it.
 *
 * Every method takes the sheet handle returned by addSheet(), and 1-based
 * row/column numbers, matching the spreadsheet's own numbering.
 */
class InventoryWorkbook
{
    /** @var array<int, array<string, mixed>> */
    private array $sheets = [];

    /**
     * Style registry. Each distinct style spec is interned once and reused, so a
     * 61-row export across 20 styled columns emits a handful of <xf> records
     * rather than 1,220 of them.
     *
     * @var array<string, int>
     */
    private array $styleIndex = [];

    /** @var array<int, array<string, mixed>> */
    private array $styles = [];

    public function __construct()
    {
        // Style 0 is the default. Excel requires cellXfs[0] to exist and treats
        // it as "General".
        $this->styleIndex[$this->styleKey([])] = 0;
        $this->styles[] = [];
    }

    public static function available(): bool
    {
        return class_exists(ZipArchive::class);
    }

    // ------------------------------------------------------------------ sheets

    /** @return int the sheet handle to pass to every other method */
    public function addSheet(string $name): int
    {
        $this->sheets[] = [
            // Excel forbids : \ / ? * [ ] in a sheet name and caps it at 31.
            'name' => mb_substr((string) preg_replace('/[:\\\\\/?*\[\]]/', '-', $name), 0, 31),
            'cells' => [],          // [row][col] => ['v' => mixed, 's' => int]
            'cols' => [],           // col => width
            'rowHeights' => [],     // row => height
            'merges' => [],
            'freeze' => null,
            'autoFilter' => null,
            'validations' => [],
            'showGridlines' => true,
        ];

        return count($this->sheets) - 1;
    }

    /**
     * Write one cell.
     *
     * A numeric $value lands as a number, so Excel can sum and format it; every
     * other scalar lands as text. Null or '' WITH a style still emits a styled
     * empty cell — that is how the import template's blank rows get their
     * stripes and borders.
     */
    public function setCell(int $sheet, int $row, int $col, mixed $value, array $style = []): void
    {
        $styleId = $this->internStyle($style);
        if (($value === null || $value === '') && $styleId === 0) {
            return;
        }
        $this->sheets[$sheet]['cells'][$row][$col] = ['v' => $value, 's' => $styleId];
    }

    /** Merge a style into whatever a cell already has, leaving its value alone. */
    public function styleCell(int $sheet, int $row, int $col, array $style): void
    {
        $existing = $this->sheets[$sheet]['cells'][$row][$col] ?? ['v' => null, 's' => 0];
        $merged = $this->mergeStyles($this->styles[$existing['s']], $style);
        $this->sheets[$sheet]['cells'][$row][$col] = [
            'v' => $existing['v'],
            's' => $this->internStyle($merged),
        ];
    }

    public function setColumnWidth(int $sheet, int $col, float $width): void
    {
        $this->sheets[$sheet]['cols'][$col] = $width;
    }

    public function setRowHeight(int $sheet, int $row, float $height): void
    {
        $this->sheets[$sheet]['rowHeights'][$row] = $height;
    }

    public function mergeCells(int $sheet, string $range): void
    {
        $this->sheets[$sheet]['merges'][] = $range;
    }

    /** Freeze everything above and left of $cell, e.g. 'A6'. */
    public function freezePane(int $sheet, string $cell): void
    {
        $this->sheets[$sheet]['freeze'] = $cell;
    }

    public function setAutoFilter(int $sheet, string $range): void
    {
        $this->sheets[$sheet]['autoFilter'] = $range;
    }

    /** An in-cell dropdown. $formula1 is a literal list: '"Active,Inactive"'. */
    public function addListValidation(int $sheet, string $sqref, string $formula1): void
    {
        $this->sheets[$sheet]['validations'][] = ['sqref' => $sqref, 'formula1' => $formula1];
    }

    public function setShowGridlines(int $sheet, bool $show): void
    {
        $this->sheets[$sheet]['showGridlines'] = $show;
    }

    // ----------------------------------------------------------- coordinates

    /** 1 => A, 27 => AA. */
    public static function columnLetter(int $index): string
    {
        $letters = '';
        while ($index > 0) {
            $rem = ($index - 1) % 26;
            $letters = chr(65 + $rem).$letters;
            $index = intdiv($index - 1, 26);
        }

        return $letters;
    }

    /** A => 1, AA => 27. */
    public static function columnIndex(string $letters): int
    {
        $index = 0;
        foreach (str_split(strtoupper($letters)) as $char) {
            $index = $index * 26 + (ord($char) - 64);
        }

        return $index;
    }

    // ---------------------------------------------------------------- styles

    private function styleKey(array $style): string
    {
        // Sorted, so ['bold'=>1,'size'=>9] and ['size'=>9,'bold'=>1] intern once.
        $normalise = function (array $a) use (&$normalise): array {
            ksort($a);
            foreach ($a as $k => $v) {
                if (is_array($v)) {
                    $a[$k] = $normalise($v);
                }
            }

            return $a;
        };

        return (string) json_encode($normalise($style));
    }

    private function internStyle(array $style): int
    {
        $key = $this->styleKey($style);
        if (! isset($this->styleIndex[$key])) {
            $this->styleIndex[$key] = count($this->styles);
            $this->styles[] = $style;
        }

        return $this->styleIndex[$key];
    }

    private function mergeStyles(array $base, array $extra): array
    {
        foreach ($extra as $key => $value) {
            $base[$key] = is_array($value) && is_array($base[$key] ?? null)
                ? array_merge($base[$key], $value)
                : $value;
        }

        return $base;
    }

    // ----------------------------------------------------------------- output

    public function toBytes(): string
    {
        if (! self::available()) {
            throw new \RuntimeException('The zip extension is required to build an .xlsx file.');
        }

        [$numFmts, $fonts, $fills, $borders, $cellXfs] = $this->buildStyleTables();

        $parts = [
            '[Content_Types].xml' => $this->contentTypesXml(),
            '_rels/.rels' => $this->rootRelsXml(),
            'docProps/core.xml' => $this->coreXml(),
            'xl/workbook.xml' => $this->workbookXml(),
            'xl/_rels/workbook.xml.rels' => $this->workbookRelsXml(),
            'xl/styles.xml' => $this->stylesXml($numFmts, $fonts, $fills, $borders, $cellXfs),
        ];

        foreach ($this->sheets as $i => $sheet) {
            $parts['xl/worksheets/sheet'.($i + 1).'.xml'] = $this->sheetXml($sheet);
        }

        return $this->zip($parts);
    }

    private function zip(array $parts): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'stock-inv-xlsx-');
        if ($tmp === false) {
            throw new \RuntimeException('Could not create a temporary file for the workbook.');
        }

        $zip = new ZipArchive;
        if ($zip->open($tmp, ZipArchive::OVERWRITE | ZipArchive::CREATE) !== true) {
            @unlink($tmp);

            throw new \RuntimeException('Could not open the workbook archive for writing.');
        }
        foreach ($parts as $name => $xml) {
            $zip->addFromString($name, $xml);
        }
        $zip->close();

        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $bytes;
    }

    // ------------------------------------------------------------- xml parts

    private static function esc(string $text): string
    {
        // Control characters below 0x20 (bar tab/LF/CR) are illegal in XML 1.0
        // and make the whole workbook unopenable, so they are stripped rather
        // than escaped. Nothing in an inventory row should carry them.
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $text) ?? $text;

        return htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function contentTypesXml(): string
    {
        $overrides = '';
        foreach ($this->sheets as $i => $sheet) {
            $overrides .= '<Override PartName="/xl/worksheets/sheet'.($i + 1).'.xml"'
                .' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .$overrides
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            .'</Types>';
    }

    private function rootRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            .'</Relationships>';
    }

    private function coreXml(): string
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties"'
            .' xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/"'
            .' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            .'<dc:creator>REEFER Stock Manager</dc:creator>'
            .'<cp:lastModifiedBy>REEFER Stock Manager</cp:lastModifiedBy>'
            .'<dcterms:created xsi:type="dcterms:W3CDTF">'.$now.'</dcterms:created>'
            .'<dcterms:modified xsi:type="dcterms:W3CDTF">'.$now.'</dcterms:modified>'
            .'</cp:coreProperties>';
    }

    private function workbookXml(): string
    {
        $sheets = '';
        foreach ($this->sheets as $i => $sheet) {
            $sheets .= '<sheet name="'.self::esc($sheet['name']).'" sheetId="'.($i + 1).'" r:id="rId'.($i + 1).'"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            .' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'.$sheets.'</sheets></workbook>';
    }

    private function workbookRelsXml(): string
    {
        $rels = '';
        foreach ($this->sheets as $i => $sheet) {
            $rels .= '<Relationship Id="rId'.($i + 1).'"'
                .' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"'
                .' Target="worksheets/sheet'.($i + 1).'.xml"/>';
        }
        $rels .= '<Relationship Id="rId'.(count($this->sheets) + 1).'"'
            .' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .$rels.'</Relationships>';
    }

    /**
     * Turn the interned style specs into the tables styles.xml wants, plus the
     * cellXfs records that point into them.
     */
    private function buildStyleTables(): array
    {
        // Index 0 of fonts/fills/borders is the default, and fills index 1 MUST
        // be gray125 — Excel calls the file damaged if it is anything else.
        $fonts = ['<font><sz val="11"/><color theme="1"/><name val="Calibri"/><family val="2"/></font>'];
        $fills = ['<fill><patternFill patternType="none"/></fill>', '<fill><patternFill patternType="gray125"/></fill>'];
        $borders = ['<border><left/><right/><top/><bottom/><diagonal/></border>'];
        $numFmts = [];
        $cellXfs = [];

        $intern = function (array &$table, string $xml): int {
            $found = array_search($xml, $table, true);
            if ($found !== false) {
                return (int) $found;
            }
            $table[] = $xml;

            return count($table) - 1;
        };

        foreach ($this->styles as $style) {
            $fontId = 0;
            if (! empty($style['font'])) {
                $f = $style['font'];
                $xml = '<font>'
                    .(! empty($f['bold']) ? '<b/>' : '')
                    .(! empty($f['italic']) ? '<i/>' : '')
                    .'<sz val="'.(float) ($f['size'] ?? 11).'"/>'
                    .(isset($f['color']) ? '<color rgb="'.self::esc((string) $f['color']).'"/>' : '<color theme="1"/>')
                    .'<name val="Calibri"/><family val="2"/></font>';
                $fontId = $intern($fonts, $xml);
            }

            $fillId = 0;
            if (! empty($style['fill'])) {
                $xml = '<fill><patternFill patternType="solid">'
                    .'<fgColor rgb="'.self::esc((string) $style['fill']).'"/><bgColor indexed="64"/>'
                    .'</patternFill></fill>';
                $fillId = $intern($fills, $xml);
            }

            $borderId = 0;
            if (! empty($style['borders'])) {
                $b = $style['borders'];
                $side = fn (string $name) => isset($b[$name])
                    ? '<'.$name.' style="thin"><color rgb="'.self::esc((string) $b[$name]).'"/></'.$name.'>'
                    : '<'.$name.'/>';
                $xml = '<border>'.$side('left').$side('right').$side('top').$side('bottom').'<diagonal/></border>';
                $borderId = $intern($borders, $xml);
            }

            $numFmtId = 0;
            if (! empty($style['numFmt'])) {
                $code = (string) $style['numFmt'];
                $pos = array_search($code, $numFmts, true);
                if ($pos === false) {
                    $numFmts[] = $code;
                    $pos = count($numFmts) - 1;
                }
                $numFmtId = 164 + $pos;   // 0..163 are the built-ins
            }

            $alignment = '';
            $applyAlignment = '0';
            if (! empty($style['align'])) {
                $a = $style['align'];
                $attrs = '';
                if (! empty($a['h'])) {
                    $attrs .= ' horizontal="'.self::esc((string) $a['h']).'"';
                }
                if (! empty($a['v'])) {
                    $attrs .= ' vertical="'.self::esc((string) $a['v']).'"';
                }
                if (! empty($a['wrap'])) {
                    $attrs .= ' wrapText="1"';
                }
                if (! empty($a['indent'])) {
                    $attrs .= ' indent="'.(int) $a['indent'].'"';
                }
                if ($attrs !== '') {
                    $alignment = '<alignment'.$attrs.'/>';
                    $applyAlignment = '1';
                }
            }

            $cellXfs[] = '<xf numFmtId="'.$numFmtId.'" fontId="'.$fontId.'" fillId="'.$fillId.'" borderId="'.$borderId.'" xfId="0"'
                .' applyNumberFormat="'.($numFmtId ? '1' : '0').'"'
                .' applyFont="'.($fontId ? '1' : '0').'"'
                .' applyFill="'.($fillId ? '1' : '0').'"'
                .' applyBorder="'.($borderId ? '1' : '0').'"'
                .' applyAlignment="'.$applyAlignment.'">'
                .$alignment.'</xf>';
        }

        return [$numFmts, $fonts, $fills, $borders, $cellXfs];
    }

    private function stylesXml(array $numFmts, array $fonts, array $fills, array $borders, array $cellXfs): string
    {
        $numFmtXml = '';
        foreach ($numFmts as $i => $code) {
            $numFmtXml .= '<numFmt numFmtId="'.(164 + $i).'" formatCode="'.self::esc($code).'"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .($numFmtXml !== '' ? '<numFmts count="'.count($numFmts).'">'.$numFmtXml.'</numFmts>' : '')
            .'<fonts count="'.count($fonts).'">'.implode('', $fonts).'</fonts>'
            .'<fills count="'.count($fills).'">'.implode('', $fills).'</fills>'
            .'<borders count="'.count($borders).'">'.implode('', $borders).'</borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="'.count($cellXfs).'">'.implode('', $cellXfs).'</cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>';
    }

    private function sheetXml(array $sheet): string
    {
        $rows = $sheet['cells'];
        ksort($rows);

        $maxRow = 1;
        $maxCol = 1;
        $body = '';

        foreach ($rows as $rowNumber => $cells) {
            ksort($cells);
            $maxRow = max($maxRow, $rowNumber);

            $cellXml = '';
            foreach ($cells as $colNumber => $cell) {
                $maxCol = max($maxCol, $colNumber);
                $ref = self::columnLetter($colNumber).$rowNumber;
                $s = $cell['s'] > 0 ? ' s="'.$cell['s'].'"' : '';
                $value = $cell['v'];

                if ($value === null || $value === '') {
                    $cellXml .= '<c r="'.$ref.'"'.$s.'/>';
                } elseif (is_int($value) || is_float($value)) {
                    // A non-finite float has no XML representation and would make
                    // the file unopenable; fall back to text.
                    $cellXml .= is_finite((float) $value)
                        ? '<c r="'.$ref.'"'.$s.'><v>'.rtrim(rtrim(number_format((float) $value, 6, '.', ''), '0'), '.').'</v></c>'
                        : '<c r="'.$ref.'"'.$s.' t="inlineStr"><is><t>'.self::esc((string) $value).'</t></is></c>';
                } else {
                    // xml:space="preserve" keeps leading/trailing spaces, which a
                    // shelf code or a size string can legitimately carry.
                    $cellXml .= '<c r="'.$ref.'"'.$s.' t="inlineStr"><is><t xml:space="preserve">'
                        .self::esc((string) $value).'</t></is></c>';
                }
            }

            $ht = isset($sheet['rowHeights'][$rowNumber])
                ? ' ht="'.(float) $sheet['rowHeights'][$rowNumber].'" customHeight="1"' : '';
            $body .= '<row r="'.$rowNumber.'"'.$ht.'>'.$cellXml.'</row>';
        }

        if ($sheet['cols'] !== []) {
            $maxCol = max($maxCol, max(array_keys($sheet['cols'])));
        }

        $colsXml = '';
        if ($sheet['cols'] !== []) {
            $colsXml = '<cols>';
            foreach ($sheet['cols'] as $col => $width) {
                $colsXml .= '<col min="'.$col.'" max="'.$col.'" width="'.(float) $width.'" customWidth="1"/>';
            }
            $colsXml .= '</cols>';
        }

        $paneXml = '';
        if ($sheet['freeze'] !== null && preg_match('/^([A-Z]+)(\d+)$/', $sheet['freeze'], $m)) {
            $xSplit = self::columnIndex($m[1]) - 1;
            $ySplit = (int) $m[2] - 1;
            // The active pane names the quadrant below/right of the split, and
            // which quadrants exist depends on which splits were asked for.
            $activePane = $xSplit > 0
                ? ($ySplit > 0 ? 'bottomRight' : 'topRight')
                : 'bottomLeft';
            $paneXml = ($xSplit > 0 || $ySplit > 0) ? '<pane'
                .($xSplit > 0 ? ' xSplit="'.$xSplit.'"' : '')
                .($ySplit > 0 ? ' ySplit="'.$ySplit.'"' : '')
                .' topLeftCell="'.$sheet['freeze'].'" activePane="'.$activePane.'" state="frozen"/>'
                .'<selection pane="'.$activePane.'"/>' : '';
        }

        $mergeXml = '';
        if ($sheet['merges'] !== []) {
            $mergeXml = '<mergeCells count="'.count($sheet['merges']).'">';
            foreach ($sheet['merges'] as $range) {
                $mergeXml .= '<mergeCell ref="'.self::esc($range).'"/>';
            }
            $mergeXml .= '</mergeCells>';
        }

        $validationXml = '';
        if ($sheet['validations'] !== []) {
            $validationXml = '<dataValidations count="'.count($sheet['validations']).'">';
            foreach ($sheet['validations'] as $dv) {
                $validationXml .= '<dataValidation type="list" allowBlank="1" showInputMessage="1" showErrorMessage="1"'
                    .' sqref="'.self::esc($dv['sqref']).'">'
                    .'<formula1>'.self::esc($dv['formula1']).'</formula1></dataValidation>';
            }
            $validationXml .= '</dataValidations>';
        }

        // Element order is fixed by the schema: dimension, sheetViews,
        // sheetFormatPr, cols, sheetData, autoFilter, mergeCells,
        // dataValidations. Out of order, Excel calls the file corrupt.
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            .' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<dimension ref="A1:'.self::columnLetter($maxCol).$maxRow.'"/>'
            .'<sheetViews><sheetView'.($sheet['showGridlines'] ? '' : ' showGridLines="0"').' workbookViewId="0">'
            .$paneXml.'</sheetView></sheetViews>'
            .'<sheetFormatPr defaultRowHeight="15"/>'
            .$colsXml
            .'<sheetData>'.$body.'</sheetData>'
            .($sheet['autoFilter'] !== null ? '<autoFilter ref="'.self::esc($sheet['autoFilter']).'"/>' : '')
            .$mergeXml
            .$validationXml
            .'</worksheet>';
    }
}
