<?php

namespace App\Support\Storefront\Stock;

/**
 * The Inventory workbooks: the styled export, the blank import template, and the
 * parser for an uploaded import file.
 *
 * Port of the Stock manager's App\Support\InventoryXlsx. The column set, the header
 * aliases, the example row, the sheet names, the palette and every parsing rule are
 * carried over as they were, so a workbook exported from the old app can be re-uploaded
 * here and a workbook exported here opens looking like the one people already read.
 *
 * ONE STRUCTURAL CHANGE. The reference drove PhpSpreadsheet, which is not in this
 * backend's composer.json and cannot be installed from inside the module (no composer
 * runs). So the layout is expressed against App\Support\Storefront\Stock\InventoryWorkbook, the
 * dependency-free OOXML builder written for exactly this — navy banner, striped rows,
 * red/amber/green stock colouring, column widths, freeze panes, autofilter and the
 * Active/Inactive dropdown all survive.
 *
 * (App\Support\Storefront\Stock\XlsxWriter is a DIFFERENT class, the Orders side's plain
 * name => rows report builder. Right tool for a sheet whose value is its numbers;
 * it cannot express a designed artefact like these two.)
 *
 * The one casualty is the hover note on each header cell, which needs a VML drawing
 * part per sheet for a tooltip. Those notes were not dropped: each column's `note` is
 * printed in full on the template's "How to use" sheet, which is a better place to read
 * them than a tooltip anyway — and Google Sheets discards cell comments regardless.
 *
 * TWO NOTES ABOUT THE DATA, both from the remap in InventoryData:
 *
 *   - "On Hand Qty" is product_variants.on_hand, presented as `available`. The two
 *     read-only columns beside it, Order Allocated and Cancelled, are the stored
 *     product_variants.allocated / .cancelled_qty rather than a live scan of the order
 *     book, so an export and the Inventory grid can no longer disagree.
 *   - "Size (cm)" is TWO components, "5.00*5.00" (width*length): reefer_db has no
 *     height column. A three-part value typed by someone used to the old app still
 *     parses — InventoryData::parseDimensions reads the first two numbers — it simply
 *     comes back two-part on the next export.
 */
class InventoryXlsx
{
    private const NAVY = 'FF0B1F3A';

    private const WHITE = 'FFFFFFFF';

    private const ORANGE = 'FFD97706';

    private const AMBER_BG = 'FFFFF7E6';

    private const AMBER_BORDER = 'FFF5C46B';

    private const GREEN = 'FF16A34A';

    private const RED = 'FFDC2626';

    private const STRIPE = 'FFF1F5F9';

    private const BORDER = 'FFD7DBE2';

    private const MUTED = 'FF64748B';

    private const INK = 'FF1E293B';

    private const TEMPLATE_BLANK_ROWS = 40;

    /**
     * The template's sample SKU. Deliberately fake: left in place by someone who
     * forgot to delete the row, it is reported as skipped rather than imported.
     */
    public const EXAMPLE_SKU = 'SAMPLE-001';

    /**
     * `key` is the presented-row key each column maps to (see
     * InventoryData::presentProduct). Columns marked readOnly are shown on the export
     * for context and ignored by the parser.
     */
    private const COLUMNS = [
        ['header' => 'SKU', 'key' => 'sku', 'width' => 14, 'note' => 'Required. Identifies which product row to update. Must already exist in Inventory.'],
        ['header' => 'Product Name', 'key' => 'name', 'width' => 26, 'note' => 'Display name. Belongs to the DESIGN — editing it renames every size of that product.'],
        ['header' => 'Category', 'key' => 'category', 'width' => 14, 'note' => 'One of: tee, hoodie, shorts, pants, underwear, bag, socks. Everyday names (T-Shirt, Tee) are accepted and converted. Belongs to the design.'],
        ['header' => 'Size', 'key' => 'size', 'width' => 8, 'note' => 'Garment size: S, M, L, XL, 2XL, or OS for one-size items.'],
        ['header' => 'Weight (g)', 'key' => 'weight_g', 'width' => 11, 'numFmt' => '#,##0.##', 'align' => 'right', 'note' => 'Shipping weight in grams. Leave blank to keep the standard 150 g.'],
        ['header' => 'Shelf / Location', 'key' => 'location', 'width' => 18, 'note' => 'Shelf code within the area, e.g. A01 or G16.'],
        ['header' => 'On Hand Qty', 'key' => 'available', 'width' => 12, 'numFmt' => '#,##0', 'align' => 'right', 'note' => 'Units physically in the warehouse. Changing this is written to the Activity Log with a reason. A SKU that reaches 0 is forced Inactive.'],
        ['header' => 'Price (₱)', 'key' => 'price', 'width' => 12, 'numFmt' => '#,##0', 'align' => 'right', 'note' => 'Selling price in WHOLE pesos — the catalogue holds no centavos, so 690.50 is stored as 691. Belongs to the design.'],
        ['header' => 'Status', 'key' => 'active', 'width' => 10, 'note' => 'Active or Inactive, for THIS size. Pick from the dropdown.'],
        ['header' => 'Size (cm)', 'key' => 'dimensions', 'width' => 16, 'note' => 'Flat measurements as Width*Length, e.g. 5.00*5.00. A third number (height) is read but not stored — this catalogue measures garments flat.'],
        ['header' => 'Warehouse', 'key' => 'warehouse', 'width' => 14, 'note' => 'Physical site holding this stock, e.g. Reefer QC.'],
        ['header' => 'Area', 'key' => 'area', 'width' => 12, 'note' => 'Zone within the warehouse, e.g. Storage 1.'],
        ['header' => 'Product Code', 'key' => 'product_code', 'width' => 13, 'note' => 'Design code shared by every size of the same product, e.g. R001. Unique across designs — two products cannot share one.'],
        ['header' => 'Marketplace', 'key' => 'marketplace', 'width' => 18, 'note' => 'TikTok or REEFER (Website). Belongs to the design.'],
        ['header' => 'Order Allocated', 'key' => 'order_allocated', 'width' => 15, 'numFmt' => '#,##0', 'align' => 'right', 'readOnly' => true, 'note' => 'Read-only. Units reserved against orders that have not shipped.'],
        ['header' => 'Cancelled', 'key' => 'cancelled', 'width' => 11, 'numFmt' => '#,##0', 'align' => 'right', 'readOnly' => true, 'note' => 'Read-only. Units released back from cancelled orders.'],
        ['header' => 'Image', 'key' => 'image', 'width' => 24, 'readOnly' => true, 'note' => 'Read-only. Photo filename; change photos from the product detail panel.'],
        ['header' => 'ID', 'key' => 'id', 'width' => 7, 'align' => 'right', 'readOnly' => true, 'note' => 'Read-only. Internal database id of this size.'],
        ['header' => 'Created', 'key' => 'created_at', 'width' => 20, 'readOnly' => true, 'note' => 'Read-only.'],
        ['header' => 'Updated', 'key' => 'updated_at', 'width' => 20, 'readOnly' => true, 'note' => 'Read-only.'],
    ];

    /** Header text (letters and digits only, lowercased) -> presented-row key. */
    private const HEADER_ALIASES = [
        'sku' => 'sku',
        'productname' => 'name',
        'name' => 'name',
        'category' => 'category',
        'size' => 'size',
        'weightg' => 'weight_g',
        'weight' => 'weight_g',
        'shelflocation' => 'location',
        'shelf' => 'location',
        'location' => 'location',
        'onhandqty' => 'available',
        'onhand' => 'available',
        'available' => 'available',
        'qty' => 'available',
        'price' => 'price',
        'status' => 'active',
        'active' => 'active',
        'sizecm' => 'dimensions',
        'dimensions' => 'dimensions',
        'warehouse' => 'warehouse',
        'area' => 'area',
        'productcode' => 'product_code',
        'marketplace' => 'marketplace',
    ];

    private const EXAMPLE_ROW = [
        'sku' => self::EXAMPLE_SKU,
        'name' => "REEFER'S INN (Black)",
        'category' => 'tee',
        'size' => 'M',
        'weight_g' => 200,
        'location' => 'A22',
        'available' => 7,
        'price' => 690,
        'active' => 'Active',
        'dimensions' => '5.00*5.00',
        'warehouse' => 'Reefer QC',
        'area' => 'Storage 1',
        'product_code' => 'R001',
        'marketplace' => 'REEFER (Website)',
    ];

    /** The columns a person may actually fill in — the read-only ones are dropped. */
    private static function templateColumns(): array
    {
        return array_values(array_filter(self::COLUMNS, fn ($c) => empty($c['readOnly'])));
    }

    // ------------------------------------------------------------------ styling

    /** Two-row navy banner: bold white title, italic orange subtitle, thin strip. */
    private static function paintBanner(InventoryWorkbook $wb, int $sheet, int $colCount, string $title, string $subtitle): void
    {
        $last = InventoryWorkbook::columnLetter($colCount);

        for ($row = 1; $row <= 3; $row++) {
            $wb->mergeCells($sheet, 'A'.$row.':'.$last.$row);
            $wb->setRowHeight($sheet, $row, $row === 3 ? 6 : 22);
            // Every cell of a merged run carries the fill, or the banner stops at
            // column A in readers that do not paint merges from the anchor.
            for ($col = 1; $col <= $colCount; $col++) {
                $wb->setCell($sheet, $row, $col, null, ['fill' => self::NAVY]);
            }
        }

        $wb->setCell($sheet, 1, 1, $title, [
            'fill' => self::NAVY,
            'font' => ['bold' => true, 'size' => 15, 'color' => self::WHITE],
            'align' => ['v' => 'center', 'indent' => 1],
        ]);
        $wb->setCell($sheet, 2, 1, $subtitle, [
            'fill' => self::NAVY,
            'font' => ['italic' => true, 'size' => 10.5, 'color' => self::ORANGE],
            'align' => ['v' => 'center', 'indent' => 1],
        ]);
    }

    /** Navy header band, white bold labels, wrapped and centred. */
    private static function paintHeader(InventoryWorkbook $wb, int $sheet, int $rowNumber, array $columns): void
    {
        $wb->setRowHeight($sheet, $rowNumber, 26);

        foreach ($columns as $i => $col) {
            $wb->setCell($sheet, $rowNumber, $i + 1, $col['header'], [
                'fill' => self::NAVY,
                'font' => ['bold' => true, 'size' => 10.5, 'color' => self::WHITE],
                'align' => ['v' => 'center', 'h' => 'center', 'wrap' => true],
                'borders' => [
                    'top' => self::NAVY, 'bottom' => self::NAVY,
                    'left' => self::WHITE, 'right' => self::WHITE,
                ],
            ]);
        }
    }

    private static function applyColumnWidths(InventoryWorkbook $wb, int $sheet, array $columns): void
    {
        foreach ($columns as $i => $col) {
            if (isset($col['width'])) {
                $wb->setColumnWidth($sheet, $i + 1, (float) $col['width']);
            }
        }
    }

    /** Alternating fill + hairline rule, so a long list stays readable across. */
    private static function stripeRow(InventoryWorkbook $wb, int $sheet, int $rowNumber, int $colCount, bool $striped): void
    {
        for ($c = 1; $c <= $colCount; $c++) {
            $style = ['borders' => ['bottom' => self::BORDER]];
            if ($striped) {
                $style['fill'] = self::STRIPE;
            }
            $wb->styleCell($sheet, $rowNumber, $c, $style);
        }
    }

    /** The style a data column carries before stripes and stock colouring. */
    private static function cellStyle(array $col): array
    {
        $style = [];

        if (! empty($col['numFmt'])) {
            $style['numFmt'] = $col['numFmt'];
        }
        if (($col['align'] ?? '') === 'right') {
            $style['align'] = ['h' => 'right'];
        }
        if (! empty($col['readOnly'])) {
            $style['font'] = ['color' => self::MUTED];
        }

        return $style;
    }

    // ------------------------------------------------------------------ export

    /**
     * The export: every SKU as the grid shows it, plus the Activity Log.
     *
     * The parser finds its header row by looking for a "SKU" cell rather than a fixed
     * row number, so this file — banner and all — can be edited and uploaded straight
     * back under Import Stock.
     *
     * @param  array  $rows  presented rows, InventoryData::presentedList()
     * @param  array  $logRows  Activity Log rows carrying log_id, newest first
     */
    public static function buildExportWorkbook(array $rows, array $logRows, string $generatedAt): string
    {
        $wb = new InventoryWorkbook;

        self::buildInventorySheet($wb, $wb->addSheet('Inventory'), $rows, $generatedAt);
        self::buildActivityLogSheet($wb, $wb->addSheet('Activity Log'), $logRows);

        return $wb->toBytes();
    }

    private static function buildInventorySheet(InventoryWorkbook $wb, int $sheet, array $rows, string $generatedAt): void
    {
        $colCount = count(self::COLUMNS);

        $wb->setShowGridlines($sheet, false);
        self::applyColumnWidths($wb, $sheet, self::COLUMNS);
        self::paintBanner($wb, $sheet, $colCount,
            'REEFER — Inventory Export',
            'Stock manager · '.count($rows).' SKUs · generated '.$generatedAt);

        $headerRow = 5;
        self::paintHeader($wb, $sheet, $headerRow, self::COLUMNS);

        $availableCol = null;
        $activeCol = null;
        foreach (self::COLUMNS as $i => $col) {
            if ($col['key'] === 'available') {
                $availableCol = $i + 1;
            }
            if ($col['key'] === 'active') {
                $activeCol = $i + 1;
            }
        }

        foreach ($rows as $i => $row) {
            $rowNum = $headerRow + 1 + $i;

            foreach (self::COLUMNS as $ci => $col) {
                $value = $col['key'] === 'active'
                    ? (! empty($row['active']) ? 'Active' : 'Inactive')
                    : self::cellValue($row[$col['key']] ?? '');
                $wb->setCell($sheet, $rowNum, $ci + 1, $value, self::cellStyle($col));
            }

            // The same red / amber / green the Inventory screen paints.
            $qty = (int) ($row['available'] ?? 0);
            $qtyColor = $qty === 0 ? self::RED : ($qty <= 5 ? self::ORANGE : self::GREEN);
            $wb->styleCell($sheet, $rowNum, $availableCol, ['font' => ['bold' => true, 'color' => $qtyColor]]);
            $wb->styleCell($sheet, $rowNum, $activeCol, [
                'font' => ['bold' => true, 'color' => ! empty($row['active']) ? self::GREEN : self::MUTED],
            ]);

            self::stripeRow($wb, $sheet, $rowNum, $colCount, $i % 2 === 1);
        }

        if ($rows === []) {
            $wb->setCell($sheet, $headerRow + 1, 1, 'No products in inventory.',
                ['font' => ['italic' => true, 'color' => self::MUTED]]);
        }

        $wb->freezePane($sheet, 'A'.($headerRow + 1));
        $wb->setAutoFilter($sheet,
            'A'.$headerRow.':'.InventoryWorkbook::columnLetter($colCount).($headerRow + max(count($rows), 1)));
    }

    private static function buildActivityLogSheet(InventoryWorkbook $wb, int $sheet, array $logRows): void
    {
        $cols = [
            ['header' => 'Log ID', 'key' => 'log_id', 'width' => 11],
            ['header' => 'Timestamp', 'key' => 'timestamp', 'width' => 20],
            ['header' => 'SKU', 'key' => 'sku', 'width' => 14],
            ['header' => 'Product', 'key' => 'product_name', 'width' => 26],
            ['header' => 'Field', 'key' => 'field', 'width' => 14],
            ['header' => 'Old Value', 'key' => 'old_value', 'width' => 16],
            ['header' => 'New Value', 'key' => 'new_value', 'width' => 16],
            ['header' => 'Change', 'key' => 'delta', 'width' => 10, 'align' => 'right'],
            ['header' => 'Reason', 'key' => 'reason', 'width' => 22],
            ['header' => 'Notes', 'key' => 'notes', 'width' => 30],
            ['header' => 'User', 'key' => 'user', 'width' => 14],
        ];

        self::applyColumnWidths($wb, $sheet, $cols);
        self::paintHeader($wb, $sheet, 1, $cols);

        foreach ($logRows as $i => $log) {
            $log = (array) $log;
            $rowNum = $i + 2;

            foreach ($cols as $ci => $col) {
                $wb->setCell($sheet, $rowNum, $ci + 1, self::cellValue($log[$col['key']] ?? ''), self::cellStyle($col));
            }

            // A movement's sign is the thing the eye looks for first.
            $delta = is_numeric($log['delta'] ?? null) ? (float) $log['delta'] : null;
            if ($delta !== null && $delta != 0.0) {
                $wb->styleCell($sheet, $rowNum, 8,
                    ['font' => ['bold' => true, 'color' => $delta > 0 ? self::GREEN : self::RED]]);
            }

            self::stripeRow($wb, $sheet, $rowNum, count($cols), $i % 2 === 1);
        }

        if ($logRows === []) {
            $wb->setCell($sheet, 2, 1, 'No inventory activity recorded yet.',
                ['font' => ['italic' => true, 'color' => self::MUTED]]);
        }

        $wb->freezePane($sheet, 'A2');
        $wb->setAutoFilter($sheet, 'A1:'.InventoryWorkbook::columnLetter(count($cols)).'1');
    }

    // ---------------------------------------------------------------- template

    /**
     * The blank workbook for bulk stock updates: banner, header, one amber example row,
     * 40 striped rows to type into, and the instructions sheet carrying what used to be
     * the header tooltips.
     */
    public static function buildImportTemplate(): string
    {
        $wb = new InventoryWorkbook;
        $sheet = $wb->addSheet('Import Template');
        $cols = self::templateColumns();
        $colCount = count($cols);

        $wb->setShowGridlines($sheet, false);
        self::applyColumnWidths($wb, $sheet, $cols);
        self::paintBanner($wb, $sheet, $colCount, 'Import Template',
            'Fill in your rows below, then delete the example row before uploading.');

        $headerRow = 5;
        self::paintHeader($wb, $sheet, $headerRow, $cols);

        // The example row, italic amber so it reads as a sample rather than as data.
        $exampleRowNum = $headerRow + 1;
        $wb->setRowHeight($sheet, $exampleRowNum, 18);

        foreach ($cols as $i => $col) {
            $style = self::cellStyle($col);
            $style['font'] = ['italic' => true, 'color' => self::ORANGE];
            $style['fill'] = self::AMBER_BG;
            $style['borders'] = [
                'top' => self::AMBER_BORDER, 'bottom' => self::AMBER_BORDER,
                'left' => self::AMBER_BORDER, 'right' => self::AMBER_BORDER,
            ];
            $wb->setCell($sheet, $exampleRowNum, $i + 1,
                self::cellValue(self::EXAMPLE_ROW[$col['key']] ?? ''), $style);
        }

        // Blank striped rows, pre-formatted so a typed number lands right-aligned.
        $firstBlank = $exampleRowNum + 1;
        for ($i = 0; $i < self::TEMPLATE_BLANK_ROWS; $i++) {
            $rowNum = $firstBlank + $i;
            $wb->setRowHeight($sheet, $rowNum, 17);

            foreach ($cols as $ci => $col) {
                $wb->setCell($sheet, $rowNum, $ci + 1, null, self::cellStyle($col));
            }

            self::stripeRow($wb, $sheet, $rowNum, $colCount, $i % 2 === 0);
        }

        // Status is a fixed vocabulary — offer it rather than let it be mistyped.
        foreach ($cols as $i => $col) {
            if ($col['key'] !== 'active') {
                continue;
            }
            $letter = InventoryWorkbook::columnLetter($i + 1);
            $wb->addListValidation($sheet,
                $letter.$exampleRowNum.':'.$letter.($firstBlank + self::TEMPLATE_BLANK_ROWS - 1),
                '"Active,Inactive"');
        }

        $wb->freezePane($sheet, 'A'.($headerRow + 1));

        self::buildInstructionsSheet($wb, $wb->addSheet('How to use'), $cols);

        return $wb->toBytes();
    }

    private static function buildInstructionsSheet(InventoryWorkbook $wb, int $sheet, array $cols): void
    {
        $wb->setShowGridlines($sheet, false);
        $wb->setColumnWidth($sheet, 1, 22);
        $wb->setColumnWidth($sheet, 2, 92);

        self::paintBanner($wb, $sheet, 2, 'How to use this template', 'Inventory → Import Stock → Excel File');

        $steps = [
            ['1.', 'Fill one row per SKU on the "Import Template" sheet. Only the SKU column is required.'],
            ['2.', 'Delete the amber example row (SKU '.self::EXAMPLE_SKU.') before uploading. Left in, it is reported as skipped and never imported.'],
            ['3.', 'Leave a cell blank to keep whatever value that product already has — blank never clears a field.'],
            ['4.', 'Upload the saved .xlsx under Inventory → Import Stock → Excel File.'],
            ['', ''],
            ['Note', 'The SKU must already exist in Inventory. Unknown SKUs are reported and skipped unless you tick "create missing SKUs", which also needs a Product Name on the row.'],
            ['Note', 'Product Name, Category, Price (₱), Product Code and Marketplace belong to the DESIGN, so changing one changes every size of that product. Everything else is per size.'],
            ['Note', 'On Hand Qty changes are written to the Activity Log against your user, exactly as editing by hand is.'],
            ['Note', 'A file saved from "Export to Excel" can be edited and re-uploaded here — its read-only columns are ignored.'],
        ];

        $row = 5;
        foreach ($steps as $pair) {
            $wb->setCell($sheet, $row, 1, $pair[0], [
                'font' => ['bold' => true, 'size' => 10.5,
                    'color' => $pair[0] === 'Note' ? self::ORANGE : self::NAVY],
                'align' => ['v' => 'top'],
            ]);
            $wb->setCell($sheet, $row, 2, $pair[1], [
                'font' => ['size' => 10.5, 'color' => self::INK],
                'align' => ['v' => 'top', 'wrap' => true],
            ]);
            $wb->setRowHeight($sheet, $row, mb_strlen($pair[1]) > 90 ? 28 : 16);
            $row++;
        }

        $row++;
        $wb->setCell($sheet, $row, 1, 'COLUMN REFERENCE',
            ['font' => ['bold' => true, 'size' => 11, 'color' => self::NAVY]]);
        $row++;

        self::paintHeader($wb, $sheet, $row, [
            ['header' => 'Column'],
            ['header' => 'What it means'],
        ]);
        $row++;

        foreach ($cols as $i => $col) {
            $wb->setCell($sheet, $row, 1, $col['header'], ['font' => ['bold' => true, 'size' => 10]]);
            $wb->setCell($sheet, $row, 2, $col['note'] ?? '', ['align' => ['wrap' => true, 'v' => 'top']]);
            $wb->setRowHeight($sheet, $row, mb_strlen($col['note'] ?? '') > 90 ? 26 : 16);
            self::stripeRow($wb, $sheet, $row, 2, $i % 2 === 1);
            $row++;
        }
    }

    // ----------------------------------------------------------------- parsing

    private static function normalizeHeader($text): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower((string) ($text ?? '')));
    }

    /**
     * A presented value on its way into a cell. Numbers stay numbers so Excel can sum
     * them; booleans are spelled out rather than landing as 1/0; timestamps are
     * flattened to the string form the ERP's rows always carried.
     */
    private static function cellValue(mixed $value): mixed
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'Active' : 'Inactive';
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return $value;
    }

    private static function parseBool(string $text): ?bool
    {
        $t = strtolower(trim($text));
        if (in_array($t, ['active', 'true', 'yes', '1', 'y'], true)) {
            return true;
        }
        if (in_array($t, ['inactive', 'false', 'no', '0', 'n'], true)) {
            return false;
        }

        return null;
    }

    /**
     * Read an uploaded .xlsx into ['sheetName' => ..., 'rows' => [['sku', 'fields',
     * 'rowNumber'], ...]].
     *
     * Only cells that were actually filled in produce entries in `fields` — a blank
     * cell means "leave this alone", never "clear it". That rule is the whole contract
     * of the import and it is why the parser never emits a key for an empty cell.
     *
     * The header row is found by looking for the first row with a cell that normalises
     * to "sku", so a file with a banner above the header, or with the columns in a
     * different order, or an export from the old app, all import without special cases.
     *
     * @return array{headerRowNumber: int, sheetName: string, rows: array<int, array{sku: string, fields: array<string, mixed>, rowNumber: int}>}
     */
    public static function parseImportWorkbook(string $path): array
    {
        $sheets = XlsxReader::read($path);

        // Prefer a sheet we recognise, but fall back to the first one.
        $sheet = null;
        foreach (['Import Template', 'Inventory'] as $wanted) {
            foreach ($sheets as $candidate) {
                if (($candidate['name'] ?? '') === $wanted) {
                    $sheet = $candidate;
                    break 2;
                }
            }
        }
        $sheet ??= $sheets[0] ?? null;

        if ($sheet === null) {
            throw new \RuntimeException('The file has no worksheets.');
        }

        $cells = $sheet['cells'] ?? [];
        ksort($cells);

        $headerRowNumber = 0;
        $fieldByCol = [];

        foreach ($cells as $rowNumber => $row) {
            $map = [];
            $sawSku = false;

            foreach ($row as $colNumber => $text) {
                $field = self::HEADER_ALIASES[self::normalizeHeader($text)] ?? null;
                if ($field === null) {
                    continue;
                }
                $map[$colNumber] = $field;
                if ($field === 'sku') {
                    $sawSku = true;
                }
            }

            if ($sawSku) {
                $headerRowNumber = $rowNumber;
                $fieldByCol = $map;
                break;
            }
        }

        if ($headerRowNumber === 0) {
            throw new \RuntimeException('Could not find a header row with a "SKU" column. Use the downloadable template.');
        }

        $rows = [];

        foreach ($cells as $rowNumber => $row) {
            if ($rowNumber <= $headerRowNumber) {
                continue;
            }

            $fields = [];
            $sku = '';

            foreach ($fieldByCol as $colNumber => $field) {
                $text = trim((string) ($row[$colNumber] ?? ''));
                if ($text === '') {
                    continue;
                }

                if ($field === 'sku') {
                    $sku = $text;

                    continue;
                }

                if (in_array($field, ['available', 'price', 'weight_g'], true)) {
                    // "₱1,250.00" is a number somebody formatted, not text.
                    $num = preg_replace('/[,₱\s]/u', '', $text);
                    if (is_numeric($num)) {
                        $fields[$field] = (float) $num;
                    }

                    continue;
                }

                if ($field === 'active') {
                    $bool = self::parseBool($text);
                    if ($bool !== null) {
                        $fields['active'] = $bool;
                    }

                    continue;
                }

                $fields[$field] = $text;
            }

            if ($sku === '') {
                continue; // blank spacer row
            }

            $rows[] = ['sku' => $sku, 'fields' => $fields, 'rowNumber' => $rowNumber];
        }

        return ['headerRowNumber' => $headerRowNumber, 'sheetName' => (string) $sheet['name'], 'rows' => $rows];
    }
}
