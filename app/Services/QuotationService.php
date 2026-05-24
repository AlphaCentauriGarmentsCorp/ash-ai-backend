<?php

namespace App\Services;

use App\Models\ApparelNeckline;
use App\Models\ApparelPatternPrice;
use App\Models\ApparelType;
use App\Models\PatternType;
use App\Models\PricingSetting;
use App\Models\PrintMethod;
use App\Models\Quotation;
use Illuminate\Database\Eloquent\Collection;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use App\Mail\QuotationPdfMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;

class QuotationService
{
    protected PoCodeGenerator $poCodeGenerator;

    public function __construct(PoCodeGenerator $poCodeGenerator)
    {
        $this->poCodeGenerator = $poCodeGenerator;
    }
    
    public function getAll(): Collection
    {
        return Quotation::with('user')->get();
    }

    public function find(int $id): ?Quotation
    {
        return Quotation::with('user')->find($id);
    }

    /**
     * Compute quotation totals WITHOUT persisting — used by the live price
     * preview on the Add/Edit Quotation form. Runs the exact same pricing
     * path as store()/update() (normalizePayload), so the preview can never
     * disagree with the saved quote. Accepts the same payload shape the form
     * submits (item_config_json / items_json / print_parts_json as JSON
     * strings or arrays). No DB writes, no PDF, no side effects.
     */
    public function preview(array $data): array
    {
        $computed = $this->normalizePayload($data);

        // Return only the fields the form needs to render the preview.
        return [
            'subtotal' => $computed['subtotal'] ?? 0,
            'discount_amount' => $computed['discount_amount'] ?? 0,
            'grand_total' => $computed['grand_total'] ?? 0,
            'downpayment' => $computed['downpayment'] ?? 0,
            'balance' => $computed['balance'] ?? 0,
            'items_json' => $computed['items_json'] ?? [],
            'addons_json' => $computed['addons_json'] ?? [],
            'breakdown_json' => $computed['breakdown_json'] ?? [],
        ];
    }

    public function store(array $data, ?Request $request = null): Quotation
{
        return DB::transaction(function () use ($data, $request) {
            $normalized = $this->normalizePayload($data, $request);
            $normalized['quotation_id'] = $this->generatePoCode('QUO');
            $normalized['user_id'] = Auth::id();

            // Handle PSD file upload
            if ($request->hasFile('print_parts_psd')) {
            $validated = $request->validate([
                'print_parts_psd' => 'nullable|file|mimes:psd|max:10240',  // PSD file validation
            ]);
            if ($validated['print_parts_psd']) {
                $filePath = $validated['print_parts_psd']->store('quotation-psd-files', 'public');
                $normalized['print_parts_psd'] = $filePath;
            }
        }

            // Calculate total square inches and total price based on length, width, and price per square inch
            if (isset($normalized['length'], $normalized['width'], $normalized['price_per_square_inch'])) {
            $totalSquareInches = $normalized['length'] * $normalized['width'];  // length * width
            $totalPrice = $totalSquareInches * $normalized['price_per_square_inch'];  // total square inches * price per square inch

            $normalized['total_square_inches'] = $totalSquareInches;
            $normalized['total_price'] = $totalPrice;
        }

            // Create the quotation
            $quotation = Quotation::create($normalized);

            // Defensive persistence of the financial split. Mass-assignment can
            // silently drop these if a stale/compiled model class lacks them in
            // $fillable (an environment quirk we hit in dev). Assigning the
            // properties directly bypasses mass-assignment guards entirely, so
            // these critical values always persist regardless of cache state.
            $quotation->forceFill([
                'downpayment' => $normalized['downpayment'] ?? 0,
                'balance' => $normalized['balance'] ?? 0,
            ])->save();

            // Generate PDF after creation
            $pdf = Pdf::loadView('pdf', ['quotation' => $quotation]);

            $fileName = $quotation->quotation_id . '.pdf';
            $filePath = "quotations/{$fileName}";

            Storage::disk('public')->put($filePath, $pdf->output());

            $quotation->update([
               'pdf_path' => $filePath
            ]);

            // Send email if client_email exists
            if (!empty($quotation->client_email)) {
                Mail::to($quotation->client_email)->send(new QuotationPdfMail($filePath));
        }

            return $quotation->fresh();
    });
}
        /**
         * Create a Draft quotation from a pre-built payload.
         *
         * Internal method used by InquiryService::convertToQuotation() to
         * spawn a draft quotation from an inquiry without going through
         * the full store() path (which validates/PDFs/emails).
         *
         * The caller is responsible for the payload shape. This method
         * only auto-fills `quotation_id` and `user_id`, then persists.
         *
         * param array $payload Pre-built quotation attributes
         * return Quotation The created (and freshly-loaded) quotation
         */
        public function createDraft(array $payload): Quotation
        {
            return DB::transaction(function () use ($payload) {
                $payload['quotation_id'] = $this->poCodeGenerator->generate('QUO');
                $payload['user_id']      = $payload['user_id'] ?? Auth::id();
                $payload['status']       = $payload['status'] ?? Quotation::STATUS_DRAFT;

                // Ensure JSON fields are present as empty arrays
                // (Quotation casts them to array; null may pass on create
                // but downstream readers expect array shape.)
                $payload['item_config_json'] = $payload['item_config_json'] ?? [];
                $payload['items_json']       = $payload['items_json']       ?? [];
                $payload['addons_json']      = $payload['addons_json']      ?? [];

                return Quotation::create($payload)->fresh();
            });
        }

        public function update(array $data, int $id, ?Request $request = null): Quotation
        {
            return DB::transaction(function () use ($id, $data, $request) {
            $quotation = Quotation::findOrFail($id);

            // Handle PSD file upload
            if ($request->hasFile('print_parts_psd')) {
            $validated = $request->validate([
                'print_parts_psd' => 'nullable|file|mimes:psd|max:10240',  // PSD file validation
            ]);
            if ($validated['print_parts_psd']) {
                $filePath = $validated['print_parts_psd']->store('quotation-psd-files', 'public');
                $data['print_parts_psd'] = $filePath;
            }
        }

        if (array_key_exists('print_parts_json', $data) || array_key_exists('print_parts', $data) || ($request && $request->hasFile('print_parts_files'))) {
                Log::info('Quotation public update incoming print parts payload', [
                    'quotation_id' => $quotation->id,
                    'print_parts_json' => $data['print_parts_json'] ?? null,
                    'print_parts' => $data['print_parts'] ?? null,
                ]);
            }

            // Calculate total square inches and total price based on length, width, and price per square inch
            if (isset($data['length'], $data['width'], $data['price_per_square_inch'])) {
                $totalSquareInches = $data['length'] * $data['width'];  // length * width
                $totalPrice = $totalSquareInches * $data['price_per_square_inch'];  // total square inches * price per square inch

                $data['total_square_inches'] = $totalSquareInches;
                $data['total_price'] = $totalPrice;
            }

            // Normalize and save the updated data
            $normalized = $this->normalizePayload($data, $request, $quotation);

            if (array_key_exists('print_parts_json', $normalized)) {
                Log::info('Quotation public update saved print parts', [
                    'quotation_id' => $quotation->id,
                    'print_parts_json' => $quotation->fresh()->print_parts_json,
                ]);
            }
           $quotation->update($normalized);

           // Defensive persistence of the financial split (see store() — guards
           // against mass-assignment silently dropping these fields).
           $quotation->forceFill([
               'downpayment' => $normalized['downpayment'] ?? $quotation->downpayment ?? 0,
               'balance' => $normalized['balance'] ?? $quotation->balance ?? 0,
           ])->save();

           // Regenerate PDF after update
           $pdf = Pdf::loadView('pdf', ['quotation' => $quotation->fresh()]);

           $fileName = $quotation->quotation_id . '.pdf';
           $filePath = "quotations/{$fileName}";
  
           // Overwrite existing PDF
           Storage::disk('public')->put($filePath, $pdf->output());

           if (!empty($quotation->client_email)) {
           Mail::to($quotation->client_email)->send(new QuotationPdfMail($filePath));
           }

           return $quotation->fresh();
    });
}

    protected function normalizePayload(array $data, ?Request $request = null, ?Quotation $existing = null): array
    {
        
        $hasPrintPartPayload = array_key_exists('print_parts_json', $data)
        || array_key_exists('print_parts', $data)
        || ($request && $request->hasFile('print_parts_files'));

        $isPrintPartsOnlyUpdate = $existing
        && $hasPrintPartPayload
        && !array_key_exists('item_config_json', $data);

        $hasIncomingPrintPartsMetadata = array_key_exists('print_parts_json', $data)
        || array_key_exists('print_parts', $data);

        $printPartsMetadata = $this->extractPrintPartsMetadata($data, $existing);

        // If it's an update to print parts only
        if ($isPrintPartsOnlyUpdate) {
        $resolvedPrintParts = $this->handlePrintParts($printPartsMetadata, $request, $existing, false);
        $printPartsUnitTotal = $this->calculatePrintPartsTotal($resolvedPrintParts);

        $existingItems = is_array($existing?->items_json) ? $existing->items_json : [];
        $totalQuantity = collect($existingItems)->sum(fn ($row) => (float) ($row['quantity'] ?? 0));
        $appliedPrintPartsTotal = round($printPartsUnitTotal * $totalQuantity, 2);

        $existingBreakdown = is_array($existing?->breakdown_json) ? $existing?->breakdown_json : [];
        $normalizedBreakdown = array_merge($existingBreakdown, [
            'print_parts_unit_total' => $printPartsUnitTotal,
            'print_parts_total' => $appliedPrintPartsTotal,
        ]);

        return [
            'print_parts_json' => $resolvedPrintParts,
            'breakdown_json' => $normalizedBreakdown,
            ];
        }

        // File storage for print_parts_files is handled by the controller
        // BEFORE this service runs (see QuotationController::store /
        // collectUploadedPrintParts). By the time we get here, the request
        // file has already been moved to storage and the path was added to
        // the validated data. Re-validating + re-storing here would just
        // double-handle the upload and conflict with the controller.

        if ($request && $request->hasFile('print_parts_psd')) {
            // Validate the PSD file
            $validated = $request->validate([
            'print_parts_psd' => 'nullable|file|mimes:psd|max:10240',  // PSD file type and size validation
        ]);

        // Process and store the PSD file
        if ($validated['print_parts_psd']) {
            $filePath = $validated['print_parts_psd']->store('quotation-psd-files', 'public');  // Store PSD in the public storage
            $data['print_parts_psd'] = $filePath;  // Save the file path in the data array
            }
       }

        // Length / width / price-per-square-inch (optional pricing model
        // alongside the per-item flow). Compute totals so they can be
        // persisted on the row.
        $length = $data['length'] ?? $existing?->length;
        $width = $data['width'] ?? $existing?->width;
        $pricePerSquareInch = $data['price_per_square_inch'] ?? $existing?->price_per_square_inch;

        if ($length && $width && $pricePerSquareInch) {
            $totalSquareInches = $length * $width;
            $totalPrice = $totalSquareInches * $pricePerSquareInch;
            $data['total_square_inches'] = $totalSquareInches;
            $data['total_price'] = $totalPrice;
        }

        $itemConfig = $this->decodeJsonField($data['item_config_json'] ?? ($existing?->item_config_json));
        $items = $this->decodeJsonField($data['items_json'] ?? ($existing?->items_json));
        $addons = $this->decodeJsonField($data['addons_json'] ?? ($existing?->addons_json)) ?? [];
        $breakdown = $this->decodeJsonField($data['breakdown_json'] ?? ($existing?->breakdown_json)) ?? [];

        if (! is_array($itemConfig)) {
            throw ValidationException::withMessages(['item_config_json' => 'The item_config_json field is required and must be a valid JSON object.']);
        }

        $apparelPatternPriceId = $itemConfig['apparel_pattern_price_id'] ?? null;
        if (! $apparelPatternPriceId) {
            throw ValidationException::withMessages(['item_config_json.apparel_pattern_price_id' => 'The item_config_json.apparel_pattern_price_id field is required.']);
        }

        $patternPrice = null;
        if ($apparelPatternPriceId) {
            $patternPrice = ApparelPatternPrice::find($apparelPatternPriceId);
            if (! $patternPrice) {
                throw ValidationException::withMessages(['item_config_json.apparel_pattern_price_id' => 'The selected apparel_pattern_price_id is invalid.']);
            }
        }

        $apparelNecklineId = $data['apparel_neckline_id'] ?? $existing?->apparel_neckline_id;
        $necklinePrice = 0.0;
        if ($apparelNecklineId) {
            $neckline = ApparelNeckline::find($apparelNecklineId);
            if (! $neckline) {
                throw ValidationException::withMessages(['apparel_neckline_id' => 'The selected apparel neckline is invalid.']);
            }
            $necklinePrice = (float) $neckline->price;
        }

        if (! is_array($items) || count($items) < 1) {
            throw ValidationException::withMessages(['items_json' => 'The items_json field must contain at least 1 row.']);
        }

        if (! is_array($items)) {
            $items = [];
        }

        // Method-aware print charge (Addendum Section 4). Silkscreen,
        // embroidery, and sublimation attach a PER-PIECE charge (added to each
        // garment below). DTF is priced per placement across the whole order,
        // so it is computed once as an order-level total and added to the
        // subtotal — not multiplied per piece.
        $printMethodKey = $this->resolvePrintMethodKey($itemConfig);
        $printPartsTotal = $this->calculatePerPiecePrintCharge($printMethodKey, $printPartsMetadata, $itemConfig);
        $dtfOrderTotal = $printMethodKey === 'dtf'
            ? $this->calculateDtfTotal($printPartsMetadata, $itemConfig)
            : 0.0;

        // Hoodie option add-ons (owner): hoodies default to pullover, one free
        // kangaroo pocket, no hood strings. Each opted extra (zipper +₱50,
        // additional pocket +₱50 each, strings +₱40) adds an editable per-piece
        // charge on top of the hoodie base. Applies only to hoodies.
        $hoodieOptionsPerPiece = $this->resolveHoodieOptionsCharge($itemConfig);

        // Legacy single base price (used as a fallback when a pattern row has
        // no per-size prices configured yet).
        $legacyBasePrice = (float) ($patternPrice?->price ?? 0);
        $computedItems = [];
        $itemsTotal = 0.0;
        $totalQuantity = 0.0;

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                throw ValidationException::withMessages(["items_json.{$index}" => 'Each item row must be an object.']);
            }

            $quantity = (float) ($item['quantity'] ?? 0);
            $unitPrice = (float) ($item['unit_price'] ?? 0);
            $size = $item['size'] ?? null;

            if ($quantity < 0) {
                throw ValidationException::withMessages(["items_json.{$index}.quantity" => 'Quantity must be greater than or equal to 0.']);
            }

            if ($unitPrice < 0) {
                throw ValidationException::withMessages(["items_json.{$index}.unit_price" => 'Unit price must be greater than or equal to 0.']);
            }

            // Per-size base price (Paraan 1): the CSR picked one
            // apparel+pattern combination; we resolve the correct base for
            // THIS row's size from the pattern's size_prices map. Order of
            // preference:
            //   1) per-size price configured by Superadmin (size_prices)
            //   2) the row's manual unit_price (legacy / overrides)
            //   3) the legacy single base price
            $sizeBasePrice = $patternPrice
                ? $patternPrice->priceForSize($size)
                : 0.0;

            $hasConfiguredSizePrice = $patternPrice
                && is_array($patternPrice->size_prices)
                && $patternPrice->size_prices !== [];

            $basePrice = $hasConfiguredSizePrice
                ? $sizeBasePrice
                : ($unitPrice > 0 ? $unitPrice : $legacyBasePrice);

            $pricePerPiece = round($basePrice + $necklinePrice + $printPartsTotal + $hoodieOptionsPerPiece, 2);
            $totalAmount = round($pricePerPiece * $quantity, 2);
            $itemsTotal += $totalAmount;
            $totalQuantity += $quantity;

            $computedItems[] = [
                'id' => $item['id'] ?? null,
                'size_id' => $item['size_id'] ?? null,
                'size' => $size,
                'quantity' => $quantity,
                'unit_price' => round($unitPrice, 2),
                'base_price' => round($basePrice, 2),
                'print_parts_total' => $printPartsTotal,
                'price_per_piece' => $pricePerPiece,
                'total_amount' => $totalAmount,
            ];
        }

        $normalizedAddons = [];
        $addonsTotal = 0.0;
        foreach ($addons as $index => $addon) {
            if (! is_array($addon)) {
                throw ValidationException::withMessages(["addons_json.{$index}" => 'Each addon row must be an object.']);
            }

            $addonPrice = (float) ($addon['price'] ?? 0);
            if ($addonPrice < 0) {
                throw ValidationException::withMessages(["addons_json.{$index}.price" => 'Addon price must be greater than or equal to 0.']);
            }

            $quantity = (float) ($addon['quantity'] ?? 1);

            // MOQ minimum-charge rule (owner): add-ons are priced per piece,
            // but there is a supplier minimum batch. If the order quantity is
            // BELOW the MOQ, the client is still charged for the full minimum
            // batch (price × moq); at/above MOQ it is plain price × qty.
            // The MOQ is read authoritatively from the addons table when an
            // addon_id is given (so it can't be lowered from the frontend),
            // falling back to a sent moq, then the default of 50.
            $addonId = $addon['addon_id'] ?? $addon['id'] ?? null;
            $moq = null;
            if ($addonId) {
                $moq = optional(\App\Models\Addons::find($addonId))->moq;
            }
            if ($moq === null) {
                $moq = (int) ($addon['moq'] ?? 50);
            }
            $moq = max(0, (int) $moq);

            $chargeableQty = ($moq > 0 && $quantity < $moq) ? $moq : $quantity;
            $lineTotal = round($addonPrice * $chargeableQty, 2);
            $belowMoq = $moq > 0 && $quantity < $moq;
            $addonsTotal += $lineTotal;

            $normalizedAddons[] = [
                'name' => $addon['name'] ?? null,
                'price' => round($addonPrice, 2),
                'quantity' => $quantity,
                'moq' => $moq,
                // Flagged so the PDF/UI can show "charged at 50 pc minimum"
                // when the order is below MOQ, instead of a confusing total.
                'charged_quantity' => $chargeableQty,
                'below_moq' => $belowMoq,
                'line_total' => $lineTotal,
            ];
        }

        $sampleBreakdown = is_array($breakdown['sample_breakdown'] ?? null) ? $breakdown['sample_breakdown'] : [];
        $samplePricePerPiece = isset($sampleBreakdown['price_per_piece'])
            ? (float) $sampleBreakdown['price_per_piece']
            : round((float) ($sampleBreakdown['unit_price'] ?? 0) * (float) ($sampleBreakdown['quantity'] ?? 0), 2);

        $sampleTotal = max(0, round($samplePricePerPiece, 2));

        $normalizedSampleBreakdown = [
            'sample_apparel' => $sampleBreakdown['sample_apparel'] ?? null,
            'unit_price' => round((float) ($sampleBreakdown['unit_price'] ?? 0), 2),
            'quantity' => (float) ($sampleBreakdown['quantity'] ?? 0),
            'price_per_piece' => $sampleTotal,
        ];

        $normalizedBreakdown = [
            'items' => is_array($breakdown['items'] ?? null) ? $breakdown['items'] : [],
            'sample_breakdown' => $normalizedSampleBreakdown,
        ];

        $resolvedPrintParts = $this->handlePrintParts(
            $printPartsMetadata,
            $request,
            $existing,
            ! $hasIncomingPrintPartsMetadata
        );
        $appliedPrintPartsTotal = round($printPartsTotal * $totalQuantity, 2);

        // Custom-fit pattern-making fee (Blueprint Issue 6): a ONE-TIME charge
        // added once per order (NOT per piece) when the fit is Custom. The
        // base price itself uses the nearest existing fit, picked by the CSR;
        // this fee only covers making the custom pattern. Rate is
        // Superadmin-editable.
        $isCustomFit = $this->isCustomFit($itemConfig);
        $customPatternFee = $isCustomFit
            ? PricingSetting::rate(PricingSetting::CUSTOM_PATTERN_FEE, 500.0)
            : 0.0;

        $subtotal = round($itemsTotal + $addonsTotal + $sampleTotal + $customPatternFee + $dtfOrderTotal, 2);
        $discountType = $data['discount_type'] ?? $existing?->discount_type;
        $discountPrice = round((float) ($data['discount_price'] ?? $existing?->discount_price ?? 0), 2);

        $discountAmount = 0.0;
        if ($discountType === 'percentage') {
            $discountAmount = round($subtotal * ($discountPrice / 100), 2);
        } elseif ($discountType === 'fixed') {
            $discountAmount = min($discountPrice, $subtotal);
        }

        $discountAmount = max(0, $discountAmount);
        $grandTotal = round($subtotal - $discountAmount, 2);

        // Payment terms (Addendum 5.4): 60% downpayment to start the order,
        // 40% balance. Computed from the grand total. Stored in breakdown_json
        // so the client PDF (Blueprint 6.3) and order conversion (Issue 12)
        // can show them without recomputing.
        $downpayment = round($grandTotal * 0.60, 2);
        $balance = round($grandTotal - $downpayment, 2);

        return [
            'client_id' => $data['client_id'] ?? $existing?->client_id,
            'client_name' => $data['client_name'] ?? $existing?->client_name,
            'client_email' => $data['client_email'] ?? $existing?->client_email,
            'client_facebook' => $data['client_facebook'] ?? $existing?->client_facebook,
            'client_brand' => $data['client_brand'] ?? $existing?->client_brand,
            'shirt_color' => $data['shirt_color'] ?? $existing?->shirt_color,
            'apparel_neckline_id' => $apparelNecklineId,
            // Top-level apparel/pattern/print-method IDs. These live in
            // item_config_json but must ALSO be promoted to their dedicated
            // columns so the record, list views, and Edit hydration read the
            // correct values (previously these stayed NULL / defaulted, which
            // made Edit show the wrong print method). Source of truth is the
            // item config; fall back to request data then the existing record.
            'apparel_type_id' => $itemConfig['apparel_type_id']
                ?? $data['apparel_type_id'] ?? $existing?->apparel_type_id,
            'pattern_type_id' => $itemConfig['pattern_type_id']
                ?? $data['pattern_type_id'] ?? $existing?->pattern_type_id,
            'print_method_id' => $itemConfig['print_method_id']
                ?? $data['print_method_id'] ?? $existing?->print_method_id,
            'special_print' => $data['special_print'] ?? $existing?->special_print,
            'print_area' => $data['print_area'] ?? $existing?->print_area,
            'free_items' => $data['free_items'] ?? $existing?->free_items,
            'notes' => $data['notes'] ?? $existing?->notes,
            'custom_pattern_image' => $data['custom_pattern_image'] ?? $existing?->custom_pattern_image,
            'discount_type' => $discountType,
            'discount_price' => $discountPrice,
            'discount_amount' => $discountAmount,
            'subtotal' => $subtotal,
            'grand_total' => $grandTotal,
            // Promoted to first-class columns (migration 2026_05_22_030000) so
            // the system can report/filter on outstanding balances. Still kept
            // in breakdown_json for the PDF/order display path.
            'downpayment' => $downpayment,
            'balance' => $balance,
            'item_config_json' => $itemConfig,
            'items_json' => $computedItems,
            'addons_json' => $normalizedAddons,
            'breakdown_json' => array_merge($normalizedBreakdown, [
                'print_method' => $printMethodKey,
                'print_parts_total' => $appliedPrintPartsTotal,
                'print_parts_unit_total' => $printPartsTotal,
                'dtf_order_total' => round($dtfOrderTotal, 2),
                'custom_pattern_fee' => round($customPatternFee, 2),
                'hoodie_options_per_piece' => round($hoodieOptionsPerPiece, 2),
                'downpayment' => $downpayment,
                'balance' => $balance,
            ]),
            'print_parts_json' => $resolvedPrintParts,
        ];
    }

    /**
     * Silkscreen print charge (Blueprint Section 3.2 + full-print rule).
     *
     * Per-color pricing where each color's rate depends on whether the
     * placement it sits on is a FULL PRINT (larger than 14 × 20 inches) or a
     * regular size. A placement is full print when its row carries
     * print_size === 'full' (or is_full_print truthy).
     *
     * Rule (verified against client examples):
     *   - The job's FIRST color sets the base:
     *       * ₱150 if ANY placement in the job is full print
     *       * ₱100 if all placements are regular
     *   - EVERY remaining color is charged at its own placement's rate:
     *       * +₱20 if that color is on a regular placement
     *       * +₱50 if that color is on a full-print placement
     *   - The first color is drawn from a full-print placement when one
     *     exists, so the base (₱150) "uses up" one full-print color.
     *
     * Worked examples:
     *   Front 3 + Back 2, all regular   = 100 + 4×20            = ₱180
     *   Front full(1) + Back reg(1)     = 150 + 1×20            = ₱170
     *   Front full(1) + Back full(1)    = 150 + 1×50            = ₱200
     *   Front reg(1)  + Back full(1)    = 150 + 1×20            = ₱170
     *   Front full(2) + Back reg(1)     = 150 + 1×50 + 1×20     = ₱220
     *
     * All four rates are Superadmin-editable PricingSetting rows. Defaults
     * (100 / 20 / 150 / 50) are only used if a fresh DB has no seeded rows,
     * so quoting never hard-fails.
     *
     * Note: this is a per-piece amount, multiplied by total quantity later
     * in computeTotals (appliedPrintPartsTotal).
     */
    protected function calculatePrintPartsTotal($printParts): float
    {
        if (! is_array($printParts)) {
            return 0.0;
        }

        // Count colors per placement. The frontend sends an explicit split:
        //   unit_count       = number of REGULAR-size colors on this placement
        //   full_unit_count  = number of FULL-print colors on this placement
        // When that split is present we use it directly (most precise). If a
        // payload instead carries a single color_count plus a whole-placement
        // print_size/is_full_print flag, we fall back to classifying the whole
        // placement (legacy / simpler shape).
        $regularColors = 0;
        $fullColors = 0;
        foreach ($printParts as $partData) {
            if (! is_array($partData)) {
                continue;
            }

            $hasExplicitSplit = array_key_exists('unit_count', $partData)
                || array_key_exists('full_unit_count', $partData)
                || array_key_exists('unitCount', $partData)
                || array_key_exists('fullUnitCount', $partData);

            if ($hasExplicitSplit) {
                $regularColors += max(0, (int) ($partData['unit_count'] ?? $partData['unitCount'] ?? 0));
                $fullColors += max(0, (int) ($partData['full_unit_count'] ?? $partData['fullUnitCount'] ?? 0));
                continue;
            }

            $count = max(0, (int) (
                $partData['color_count']
                ?? $partData['colorCount']
                ?? 0
            ));
            if ($count <= 0) {
                continue;
            }

            if ($this->isFullPrintPart($partData)) {
                $fullColors += $count;
            } else {
                $regularColors += $count;
            }
        }

        $totalColors = $regularColors + $fullColors;
        if ($totalColors <= 0) {
            return 0.0;
        }

        $firstColorRegular = PricingSetting::rate(PricingSetting::SILKSCREEN_FIRST_COLOR, 100.0);
        $firstColorFull = PricingSetting::rate(PricingSetting::SILKSCREEN_FIRST_COLOR_FULL, 150.0);
        $addColorRegular = PricingSetting::rate(PricingSetting::SILKSCREEN_ADDITIONAL_COLOR, 20.0);
        $addColorFull = PricingSetting::rate(PricingSetting::SILKSCREEN_ADDITIONAL_COLOR_FULL, 50.0);

        $hasFull = $fullColors > 0;

        // The first color sets the base. If any placement is full print, the
        // base is the full-print first-color rate, and that color is taken
        // from the full-print pool so we don't double-charge it below.
        if ($hasFull) {
            $base = $firstColorFull;
            $remainingFull = $fullColors - 1;      // one full color consumed by the base
            $remainingRegular = $regularColors;
        } else {
            $base = $firstColorRegular;
            $remainingFull = 0;
            $remainingRegular = $regularColors - 1; // one regular color consumed by the base
        }

        $total = $base
            + ($remainingFull * $addColorFull)
            + ($remainingRegular * $addColorRegular);

        return round($total, 2);
    }

    /**
     * Hoodie option charges (owner). Hoodies default to: pullover (no zipper),
     * one kangaroo pocket (free), and NO hood drawstrings. The client can add,
     * each adding an editable per-piece charge:
     *   - Zipper            (+₱50, default)
     *   - Additional pocket (+₱50 each, beyond the free kangaroo pocket)
     *   - Hood strings      (+₱40, default off)
     * Returns the combined per-piece charge; 0 for non-hoodies. The apparel
     * name and option flags are read from item_config_json.
     */
    protected function resolveHoodieOptionsCharge($itemConfig): float
    {
        if (! is_array($itemConfig)) {
            return 0.0;
        }

        $apparel = strtolower((string) (
            $itemConfig['apparel_type_name'] ?? $itemConfig['apparel'] ?? ''
        ));
        if (! str_contains($apparel, 'hoodie')) {
            return 0.0;
        }

        $charge = 0.0;

        // Zipper (default pullover). Accept a boolean flag or a closure type.
        $hasZipper = filter_var(
            $itemConfig['has_zipper'] ?? $itemConfig['zipper'] ?? false,
            FILTER_VALIDATE_BOOLEAN
        );
        $closure = strtolower((string) ($itemConfig['hoodie_closure'] ?? ''));
        if ($closure === 'zipper' || $closure === 'zip') {
            $hasZipper = true;
        }
        if ($hasZipper) {
            $charge += PricingSetting::rate(PricingSetting::HOODIE_ZIPPER_ADDON, 50.0);
        }

        // Additional pockets beyond the one free kangaroo pocket. Accept either
        // an explicit count or a boolean (treated as 1 extra).
        $extraPockets = $itemConfig['additional_pockets'] ?? $itemConfig['extra_pockets'] ?? null;
        if ($extraPockets === null) {
            $extraPockets = filter_var($itemConfig['has_additional_pocket'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }
        $extraPockets = max(0, (int) $extraPockets);
        if ($extraPockets > 0) {
            $charge += $extraPockets * PricingSetting::rate(PricingSetting::HOODIE_ADDITIONAL_POCKET_ADDON, 50.0);
        }

        // Hood drawstrings (default off).
        $hasStrings = filter_var(
            $itemConfig['has_strings'] ?? $itemConfig['hood_strings'] ?? $itemConfig['drawstrings'] ?? false,
            FILTER_VALIDATE_BOOLEAN
        );
        if ($hasStrings) {
            $charge += PricingSetting::rate(PricingSetting::HOODIE_STRINGS_ADDON, 40.0);
        }

        return round($charge, 2);
    }

    /** Reads print_method_name (the
     * frontend stores method on item_config_json). Falls back to
     * "silkscreen" so existing quotations created before method-aware
     * pricing keep their previous behaviour.
     */
    protected function resolvePrintMethodKey($itemConfig): string
    {
        $name = is_array($itemConfig)
            ? ($itemConfig['print_method_name'] ?? $itemConfig['print_method'] ?? null)
            : null;

        $name = strtolower(trim((string) $name));

        if ($name === '') {
            return 'silkscreen';
        }

        // Map a few spellings/aliases to canonical keys.
        return match (true) {
            str_contains($name, 'dtf') => 'dtf',
            str_contains($name, 'direct-to-film') => 'dtf',
            str_contains($name, 'embroid') => 'embroidery',
            str_contains($name, 'subli') => 'sublimation',
            str_contains($name, 'silk') => 'silkscreen',
            str_contains($name, 'screen') => 'silkscreen',
            default => $name,
        };
    }

    /**
     * PER-PIECE print charge for the methods whose cost attaches to each
     * garment (silkscreen, embroidery, sublimation). This value is added to
     * the per-piece price and multiplied by quantity in computeTotals.
     *
     * DTF is NOT handled here — it is priced per placement across the whole
     * order (see calculateDtfTotal) because each DTF placement carries its
     * own design size and piece count, independent of the per-size rows.
     *
     * Embroidery (Addendum 4.3):
     *   - Small (pocket / left chest): flat ₱120 per piece (editable rate).
     *   - Large: the CSR enters a MANUAL per-piece price (subcontractor quote
     *     + markup), sent as item_config_json.embroidery_manual_price.
     *   The CSR chooses Small vs Large by judgment; we read an explicit flag.
     *
     * Sublimation (Addendum 4.4):
     *   - Full Jersey: ₱550/piece. Full Mesh Shorts: ₱650/piece (editable,
     *     same price all sizes). Other full sublimation can be set via a
     *     manual price.
     *   - Partial / small: MANUAL per-piece price (~₱200), sent as
     *     item_config_json.sublimation_manual_price.
     */
    protected function calculatePerPiecePrintCharge(string $methodKey, $printParts, $itemConfig): float
    {
        return match ($methodKey) {
            'silkscreen' => $this->calculatePrintPartsTotal($printParts),
            'embroidery' => $this->calculateEmbroideryCharge($itemConfig),
            'sublimation' => $this->calculateSublimationCharge($itemConfig),
            // DTF is order-level, not per-piece.
            'dtf' => 0.0,
            // Unknown/future method: no per-piece print charge until wired.
            default => 0.0,
        };
    }

    /**
     * Embroidery per-piece charge. Small = flat editable rate (default ₱120).
     * Large = CSR's manual price. Selection is by an explicit flag/size the
     * frontend sends; we accept a few shapes for safety.
     */
    protected function calculateEmbroideryCharge($itemConfig): float
    {
        if (! is_array($itemConfig)) {
            return 0.0;
        }

        $size = strtolower(trim((string) ($itemConfig['embroidery_size'] ?? '')));
        $isLarge = $size === 'large'
            || filter_var($itemConfig['embroidery_is_large'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($isLarge) {
            // Large embroidery is subcontracted; CSR enters the price.
            return max(0.0, round((float) ($itemConfig['embroidery_manual_price'] ?? 0), 2));
        }

        // Small embroidery (default): editable flat rate.
        return round(PricingSetting::rate(PricingSetting::EMBROIDERY_SMALL_PRICE, 120.0), 2);
    }

    /**
     * Sublimation per-piece charge. Full jersey / mesh shorts use editable
     * flat rates; other full or partial sublimation uses the CSR's manual
     * price. The frontend sends a sublimation_type ("jersey_full",
     * "mesh_shorts_full", "partial"/"manual") or an explicit manual price.
     */
    protected function calculateSublimationCharge($itemConfig): float
    {
        if (! is_array($itemConfig)) {
            return 0.0;
        }

        $type = strtolower(trim((string) ($itemConfig['sublimation_type'] ?? '')));

        return match ($type) {
            'jersey_full', 'jersey' => round(
                PricingSetting::rate(PricingSetting::SUBLIMATION_JERSEY_FULL_PRICE, 550.0), 2),
            'mesh_shorts_full', 'mesh_shorts', 'mesh' => round(
                PricingSetting::rate(PricingSetting::SUBLIMATION_MESH_SHORTS_FULL_PRICE, 650.0), 2),
            // Partial / manual / anything else: CSR-entered price (~₱200).
            default => max(0.0, round((float) ($itemConfig['sublimation_manual_price'] ?? 0), 2)),
        };
    }

    /**
     * DTF total for the WHOLE order (Addendum 4.2). DTF is priced per square
     * inch at a Superadmin-editable rate. A garment can have several DTF
     * placements (front, back, sleeve); each placement has its own design
     * size (width × height) and piece count:
     *
     *   placement charge = (width × height) × rate_per_sq_inch × pieces
     *   order DTF total  = sum of all placement charges
     *
     * Placements come from print_parts (each row may carry width/height/
     * pieces), falling back to a single placement built from the legacy
     * length/width/quantity fields on item_config_json for older payloads.
     *
     * This is an ORDER-LEVEL charge (added once to the subtotal), NOT a
     * per-piece charge, because the pieces are specified per placement.
     */
    protected function calculateDtfTotal($printParts, $itemConfig): float
    {
        $rate = PricingSetting::rate(PricingSetting::DTF_PRICE_PER_SQUARE_INCH, 0.0);
        if ($rate <= 0) {
            // Rate not set yet by the owner — no DTF charge rather than ₱0
            // silently hiding a missing setting. (Surfaced in breakdown.)
            return 0.0;
        }

        $placements = [];

        if (is_array($printParts) && $printParts !== []) {
            $placements = $printParts;
        } elseif (is_array($itemConfig)) {
            // Legacy single-placement fallback.
            $placements = [[
                'width' => $itemConfig['width'] ?? null,
                'height' => $itemConfig['height'] ?? ($itemConfig['length'] ?? null),
                'pieces' => $itemConfig['pieces'] ?? ($itemConfig['quantity'] ?? null),
            ]];
        }

        $total = 0.0;
        foreach ($placements as $p) {
            if (! is_array($p)) {
                continue;
            }

            $width = (float) ($p['width'] ?? $p['design_width'] ?? 0);
            $height = (float) ($p['height'] ?? $p['design_height'] ?? $p['length'] ?? 0);
            $pieces = (float) ($p['pieces'] ?? $p['piece_count'] ?? $p['quantity'] ?? 0);

            if ($width <= 0 || $height <= 0 || $pieces <= 0) {
                continue;
            }

            $total += ($width * $height) * $rate * $pieces;
        }

        return round($total, 2);
    }

    /**
     * Whether the quotation's fit/pattern is "Custom" (triggers the one-time
     * pattern-making fee). Reads the pattern name from item_config_json; we
     * accept a few shapes for safety since the frontend for this is evolving.
     */
    protected function isCustomFit($itemConfig): bool
    {
        if (! is_array($itemConfig)) {
            return false;
        }

        $candidates = [
            $itemConfig['pattern_type_name'] ?? null,
            $itemConfig['fit'] ?? null,
            $itemConfig['pattern'] ?? null,
        ];

        foreach ($candidates as $value) {
            if (is_string($value) && strtolower(trim($value)) === 'custom') {
                return true;
            }
        }

        // Explicit boolean flag, if the frontend sends one.
        return filter_var($itemConfig['is_custom_fit'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Whether a print-part row is a full print (larger than 14 × 20 inches).
     * The CSR marks this per placement; we accept a few shapes for safety.
     */
    protected function isFullPrintPart(array $partData): bool
    {
        $size = $partData['print_size'] ?? $partData['printSize'] ?? null;
        if (is_string($size) && strtolower(trim($size)) === 'full') {
            return true;
        }

        $flag = $partData['is_full_print'] ?? $partData['isFullPrint'] ?? null;

        return filter_var($flag, FILTER_VALIDATE_BOOLEAN);
    }

    protected function extractPrintPartsMetadata(array $data, ?Quotation $existing = null): array
    {
        if (array_key_exists('print_parts', $data) && is_array($data['print_parts'])) {
            return $data['print_parts'];
        }

        if (array_key_exists('print_parts_json', $data)) {
            if (is_array($data['print_parts_json'])) {
                return $data['print_parts_json'];
            }

            if (is_string($data['print_parts_json']) && $data['print_parts_json'] !== '') {
                $decoded = json_decode($data['print_parts_json'], true);
                return is_array($decoded) ? $decoded : [];
            }

            return [];
        }

        return $this->decodeJsonField($data['print_parts_json'] ?? ($existing?->print_parts_json)) ?? [];
    }

    protected function decodeJsonField($value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && !empty($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw ValidationException::withMessages([
                    'json' => 'Malformed JSON payload received.',
                ]);
            }
            return $decoded;
        }

        return null;
    }

    protected function handlePrintParts($printParts, ?Request $request = null, ?Quotation $quotation = null, bool $allowExistingImageFallback = true): array
    {
        if (!is_array($printParts)) {
            return [];
    }

        $result = [];
        $existingParts = $allowExistingImageFallback ? ($quotation?->print_parts_json ?? []) : [];

        foreach ($printParts as $index => $partData) {
        if (!is_array($partData)) {
            continue;
        }

        $partId = $partData['part_id'] ?? $partData['id'] ?? null;
        $partName = $partData['part'] ?? $partData['name'] ?? null;
        $colorCount = max(1, (int) ($partData['color_count'] ?? $partData['colorCount'] ?? 1));
        $pricePerColor = (float) ($partData['price_per_color'] ?? $partData['pricePerColor'] ?? 0);

        $imageInputType = $partData['image_input_type'] ?? $partData['imageInputType'] ?? null;
        $imageLink = $partData['image_link'] ?? $partData['imageLink'] ?? null;
        $imagePath = $existingParts[$index]['image'] ?? null;

        $files = [];
        if ($request && $request->hasFile('print_parts_files')) {
            $rawFiles = $request->file('print_parts_files');
            if (is_array($rawFiles)) {
                foreach ($rawFiles as $entry) {
                    // Flat shape: $entry is a single UploadedFile.
                    if ($entry instanceof \Illuminate\Http\UploadedFile) {
                        if ($entry->isValid()) {
                            $files[] = $entry->store('quotation-print-parts', 'public');
                        }
                        continue;
                    }
                    // Nested shape: $entry is an array of UploadedFile objects.
                    if (is_array($entry)) {
                        foreach ($entry as $sub) {
                            if ($sub instanceof \Illuminate\Http\UploadedFile && $sub->isValid()) {
                                $files[] = $sub->store('quotation-print-parts', 'public');
                            }
                        }
                    }
                }
            }
            // Store the array of files in the partData
            if (! empty($files)) {
                $partData['files'] = $files;
            }
        }

        $result[] = $this->buildPrintPartRow(
            $partData,
            $partId,
            $partName,
            $colorCount,
            $pricePerColor,
            $imageInputType,
            $imageLink,
            $imagePath
        );
    }

    return $result;  // Return the result with the added files
}

    protected function buildPrintPartRow(
        array $partData,
        mixed $partId,
        mixed $partName,
        int $colorCount,
        float $pricePerColor,
        mixed $imageInputType,
        mixed $imageLink,
        mixed $imagePath
    ): array {
        return array_merge($partData, [
            'part_id' => $partId,
            'id' => $partId,
            'part' => $partName,
            'name' => $partName,
            'color_count' => $colorCount,
            'colorCount' => $colorCount,
            'price_per_color' => $pricePerColor,
            'pricePerColor' => $pricePerColor,
            'image_input_type' => $imageInputType,
            'imageInputType' => $imageInputType,
            'image_link' => $imageLink,
            'imageLink' => $imageLink,
            'color_price_total' => round($colorCount * $pricePerColor, 2),
            'image' => $imagePath,
        ]);
    }

    /**
     * Generate the next quotation/PO code. Now delegates to the shared
     * PoCodeGenerator service so the same logic is reusable from
     * InquiryService (Phase 6-A).
     */
    protected function generatePoCode(string $prefix = 'QUO'): string
    {
        return $this->poCodeGenerator->generate($prefix);
    }

    /**
     * Convert a quotation to an "order ready" payload.
     *
     * Marks the quotation as `Converted` (idempotent — refuses if already
     * converted via 409) and returns an `order_payload` array that the
     * frontend uses to prefill /orders/new.
     *
     * The frontend's `useQuotationPrefill` hook expects the following keys
     * (we resolve names where IDs are stored in JSON):
     *   - quotation_id, client_id, client_brand, client_name
     *   - apparel_type_id, apparel_type_name
     *   - pattern_type_id, pattern_type_name
     *   - print_method_id, print_method_name
     *   - apparel_neckline_id
     *   - shirt_color, special_print, print_area, free_items, notes
     *   - subtotal, grand_total, discount_amount, discount_type, discount_price
     *   - items_json, addons_json, breakdown_json, print_parts_json,
     *     item_config_json
     *
     * @throws ValidationException with code 409 when already converted
     */
    public function confirmAndConvert(int $id): array
    {
        return DB::transaction(function () use ($id) {
            /** @var Quotation $quotation */
            $quotation = Quotation::lockForUpdate()->findOrFail($id);

            // Already converted? Block via a 409.
            if (strcasecmp((string) $quotation->status, 'Converted') === 0) {
                abort(409, 'This quotation has already been converted to an order.');
            }

            // ── Resolve apparel + pattern + print method names ────────────
            $itemConfig = is_array($quotation->item_config_json)
                ? $quotation->item_config_json
                : (json_decode((string) $quotation->item_config_json, true) ?: []);

            $apparelTypeId   = $itemConfig['apparel_type_id']  ?? null;
            $patternTypeId   = $itemConfig['pattern_type_id']  ?? null;
            $apparelPatternPriceId = $itemConfig['apparel_pattern_price_id'] ?? null;

            $apparelTypeName = null;
            $patternTypeName = null;

            // Fastest path: the apparel_pattern_prices row stores both names
            if ($apparelPatternPriceId) {
                $patternPrice = ApparelPatternPrice::find($apparelPatternPriceId);
                if ($patternPrice) {
                    $apparelTypeId   ??= $patternPrice->apparel_type_id;
                    $patternTypeId   ??= $patternPrice->pattern_type_id;
                    $apparelTypeName   = $patternPrice->apparel_type_name;
                    $patternTypeName   = $patternPrice->pattern_type_name;
                }
            }

            // Fallback: look up directly by id
            if (! $apparelTypeName && $apparelTypeId) {
                $apparelTypeName = ApparelType::find($apparelTypeId)?->name;
            }
            if (! $patternTypeName && $patternTypeId) {
                $patternTypeName = PatternType::find($patternTypeId)?->name;
            }

            // print_method_id is NOT a column on quotations – it's only sent
            // at create/update time. We try to recover it from item_config
            // first, then from any helper row, then leave blank.
            $printMethodId   = $itemConfig['print_method_id'] ?? null;
            $printMethodName = null;
            if ($printMethodId) {
                $printMethodName = PrintMethod::find($printMethodId)?->name;
            }

            // ── Build the order_payload ──────────────────────────────────
            $payload = [
                // Linkage
                'quotation_id' => $quotation->id,

                // Client
                'client_id'      => $quotation->client_id,
                'client_brand'   => $quotation->client_brand,
                'client_name'    => $quotation->client_name,

                // Resolved apparel / pattern / print
                'apparel_type_id'      => $apparelTypeId,
                'apparel_type_name'    => $apparelTypeName,
                'pattern_type_id'      => $patternTypeId,
                'pattern_type_name'    => $patternTypeName,
                'print_method_id'      => $printMethodId,
                'print_method_name'    => $printMethodName,
                'apparel_neckline_id'  => $quotation->apparel_neckline_id,

                // Misc descriptive
                'shirt_color'   => $quotation->shirt_color,
                'special_print' => $itemConfig['special_print'] ?? null,
                'print_area'    => $itemConfig['print_area'] ?? null,
                'free_items'    => $quotation->free_items,
                'notes'         => $quotation->notes,

                // Financials
                'subtotal'        => $quotation->subtotal,
                'grand_total'     => $quotation->grand_total,
                'discount_type'   => $quotation->discount_type,
                'discount_price'  => $quotation->discount_price,
                'discount_amount' => $quotation->discount_amount,

                // JSON blobs (already cast to arrays by the model)
                'item_config_json'  => $quotation->item_config_json,
                'items_json'        => $quotation->items_json,
                'addons_json'       => $quotation->addons_json,
                'breakdown_json'    => $quotation->breakdown_json,
                'print_parts_json'  => $quotation->print_parts_json,
            ];

            // Mark the quotation as converted
            $quotation->update(['status' => 'Converted']);

            return [
                'message'       => 'Quotation marked as converted.',
                'quotation'     => $quotation->fresh(),
                'order_payload' => $payload,
            ];
        });
    }

    public function delete(int $id): bool
    {
        $quotation = Quotation::find($id);

        if (!$quotation) {
            return false;
        }

        return $quotation->delete();
    }
}