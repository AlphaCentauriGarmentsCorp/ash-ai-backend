<?php

namespace App\Services;

use App\Models\ApparelNeckline;
use App\Models\ApparelPatternPrice;
use App\Models\ApparelType;
use App\Models\PatternType;
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

        $printPartsTotal = $this->calculatePrintPartsTotal($printPartsMetadata);

        $basePrice = (float) ($patternPrice?->price ?? 0);
        $computedItems = [];
        $itemsTotal = 0.0;
        $totalQuantity = 0.0;

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                throw ValidationException::withMessages(["items_json.{$index}" => 'Each item row must be an object.']);
            }

            $quantity = (float) ($item['quantity'] ?? 0);
            $unitPrice = (float) ($item['unit_price'] ?? 0);

            if ($quantity < 0) {
                throw ValidationException::withMessages(["items_json.{$index}.quantity" => 'Quantity must be greater than or equal to 0.']);
            }

            if ($unitPrice < 0) {
                throw ValidationException::withMessages(["items_json.{$index}.unit_price" => 'Unit price must be greater than or equal to 0.']);
            }

            $pricePerPiece = round($basePrice + $necklinePrice + $unitPrice + $printPartsTotal, 2);
            $totalAmount = round($pricePerPiece * $quantity, 2);
            $itemsTotal += $totalAmount;
            $totalQuantity += $quantity;

            $computedItems[] = [
                'id' => $item['id'] ?? null,
                'size_id' => $item['size_id'] ?? null,
                'size' => $item['size'] ?? null,
                'quantity' => $quantity,
                'unit_price' => round($unitPrice, 2),
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
            $lineTotal = round($addonPrice * $quantity, 2);
            $addonsTotal += $lineTotal;

            $normalizedAddons[] = [
                'name' => $addon['name'] ?? null,
                'price' => round($addonPrice, 2),
                'quantity' => $quantity,
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

        $subtotal = round($itemsTotal + $addonsTotal + $sampleTotal, 2);
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

        return [
            'client_id' => $data['client_id'] ?? $existing?->client_id,
            'client_name' => $data['client_name'] ?? $existing?->client_name,
            'client_email' => $data['client_email'] ?? $existing?->client_email,
            'client_facebook' => $data['client_facebook'] ?? $existing?->client_facebook,
            'client_brand' => $data['client_brand'] ?? $existing?->client_brand,
            'shirt_color' => $data['shirt_color'] ?? $existing?->shirt_color,
            'apparel_neckline_id' => $apparelNecklineId,
            'free_items' => $data['free_items'] ?? $existing?->free_items,
            'notes' => $data['notes'] ?? $existing?->notes,
            'discount_type' => $discountType,
            'discount_price' => $discountPrice,
            'discount_amount' => $discountAmount,
            'subtotal' => $subtotal,
            'grand_total' => $grandTotal,
            'item_config_json' => $itemConfig,
            'items_json' => $computedItems,
            'addons_json' => $normalizedAddons,
            'breakdown_json' => array_merge($normalizedBreakdown, [
                'print_parts_total' => $appliedPrintPartsTotal,
                'print_parts_unit_total' => $printPartsTotal,
            ]),
            'print_parts_json' => $resolvedPrintParts,
        ];
    }

    protected function calculatePrintPartsTotal($printParts): float
    {
        if (! is_array($printParts)) {
            return 0.0;
        }

        return round(array_sum(array_map(function ($partData) {
            if (! is_array($partData)) {
                return 0.0;
            }

            $colorCount = (float) ($partData['color_count'] ?? $partData['colorCount'] ?? 0);
            $pricePerColor = (float) ($partData['price_per_color'] ?? $partData['pricePerColor'] ?? 0);

            return round($colorCount * $pricePerColor, 2);
        }, $printParts)), 2);
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