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

            $quotation = Quotation::create($normalized);

            // Generate PDF after creation
            $pdf = Pdf::loadView('pdf', [
                'quotation' => $quotation
            ]);

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

    public function update(array $data, int $id, ?Request $request = null): Quotation
    {
        return DB::transaction(function () use ($id, $data, $request) {
            $quotation = Quotation::findOrFail($id);

            if (array_key_exists('print_parts_json', $data) || array_key_exists('print_parts', $data) || ($request && $request->hasFile('print_parts_files'))) {
                Log::info('Quotation public update incoming print parts payload', [
                    'quotation_id' => $quotation->id,
                    'print_parts_json' => $data['print_parts_json'] ?? null,
                    'print_parts' => $data['print_parts'] ?? null,
                ]);
            }

            $normalized = $this->normalizePayload($data, $request, $quotation);

            if (array_key_exists('print_parts_json', $normalized)) {
                Log::info('Quotation public update normalized print parts before save', [
                    'quotation_id' => $quotation->id,
                    'print_parts_json' => $normalized['print_parts_json'],
                ]);
            }

            $quotation->update($normalized);

            if (array_key_exists('print_parts_json', $normalized)) {
                Log::info('Quotation public update saved print parts', [
                    'quotation_id' => $quotation->id,
                    'print_parts_json' => $quotation->fresh()->print_parts_json,
                ]);
            }

            // Regenerate PDF after update
            $pdf = Pdf::loadView('pdf', [
                'quotation' => $quotation->fresh()
            ]);

            $fileName = $quotation->quotation_id . '.pdf';
            $filePath = "quotations/{$fileName}";

            //Overwrite existing PDF
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
            && ! array_key_exists('item_config_json', $data);
        $hasIncomingPrintPartsMetadata = array_key_exists('print_parts_json', $data)
            || array_key_exists('print_parts', $data);

        $printPartsMetadata = $this->extractPrintPartsMetadata($data, $existing);
        [$printMethodId, $specialPrint, $printArea] = $this->resolvePrintMethodFields($data, $existing);

        if ($isPrintPartsOnlyUpdate) {
            $resolvedPrintParts = $this->handlePrintParts($printPartsMetadata, $request, $existing, false);
            $printPartsUnitTotal = $this->calculatePrintPartsTotal($resolvedPrintParts);

            $existingItems = is_array($existing?->items_json) ? $existing->items_json : [];
            $totalQuantity = collect($existingItems)->sum(fn ($row) => (float) ($row['quantity'] ?? 0));
            $appliedPrintPartsTotal = round($printPartsUnitTotal * $totalQuantity, 2);

            $existingBreakdown = is_array($existing?->breakdown_json) ? $existing->breakdown_json : [];
            $normalizedBreakdown = array_merge($existingBreakdown, [
                'print_parts_unit_total' => $printPartsUnitTotal,
                'print_parts_total' => $appliedPrintPartsTotal,
            ]);

            return [
                'print_method_id' => $printMethodId,
                'special_print' => $specialPrint,
                'print_area' => $printArea,
                'print_parts_json' => $resolvedPrintParts,
                'breakdown_json' => $normalizedBreakdown,
            ];
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

        // Resolve apparel_type_id and pattern_type_id from item_config_json or direct payload
        $apparelTypeId  = $itemConfig['apparel_type_id']  ?? $data['apparel_type_id']  ?? $existing?->apparel_type_id;
        $patternTypeId  = $itemConfig['pattern_type_id']  ?? $data['pattern_type_id']  ?? $existing?->pattern_type_id;

        return [
            'client_id' => $data['client_id'] ?? $existing?->client_id,
            'client_name' => $data['client_name'] ?? $existing?->client_name,
            'client_email' => $data['client_email'] ?? $existing?->client_email,
            'client_facebook' => $data['client_facebook'] ?? $existing?->client_facebook,
            'client_brand' => $data['client_brand'] ?? $existing?->client_brand,
            'apparel_type_id' => $apparelTypeId,
            'pattern_type_id' => $patternTypeId,
            'shirt_color' => $data['shirt_color'] ?? $existing?->shirt_color,
            'apparel_neckline_id' => $apparelNecklineId,
            'print_method_id' => $printMethodId,
            'special_print' => $specialPrint,
            'print_area' => $printArea,
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

            $unitCount = (float) ($partData['unit_count'] ?? 0);
            $pricePerUnit = (float) ($partData['price_per_unit'] ?? 0);
            $fullUnitCount = (float) ($partData['full_unit_count'] ?? 0);
            $pricePerFullUnit = (float) ($partData['price_per_full_unit'] ?? 0);

            return round(($unitCount * $pricePerUnit) + ($fullUnitCount * $pricePerFullUnit), 2);
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
        if (! is_array($printParts)) {
            return [];
        }

        $result = [];
        $existingParts = $allowExistingImageFallback ? ($quotation?->print_parts_json ?? []) : [];

        foreach ($printParts as $index => $partData) {
            if (! is_array($partData)) {
                continue;
            }

            $partId = $partData['part_id'] ?? null;
            $partName = $partData['part'] ?? null;
            $unitCount = max(0, (float) ($partData['unit_count'] ?? 0));
            $pricePerUnit = max(0, (float) ($partData['price_per_unit'] ?? 0));
            $fullUnitCount = max(0, (float) ($partData['full_unit_count'] ?? 0));
            $pricePerFullUnit = max(0, (float) ($partData['price_per_full_unit'] ?? 0));

            $imageInputType = $partData['image_input_type'] ?? null;
            $imageLink = $partData['image_link'] ?? null;
            $imagePath = $existingParts[$index]['image'] ?? null;

            if ($request && $request->hasFile("print_parts_files.{$index}")) {
                $imagePath = $request->file("print_parts_files.{$index}")->store('quotation-print-parts', 'public');
                $imageInputType = 'file';
                $imageLink = null;
            } elseif ($imageInputType === 'file' && is_string($imageLink) && $imageLink !== '') {
                $imagePath = $imageLink;
                $imageLink = null;
            } elseif ($imageInputType === 'link') {
                $imagePath = null;
            }

            $result[] = $this->buildPrintPartRow(
                $partData,
                $partId,
                $partName,
                $unitCount,
                $pricePerUnit,
                $fullUnitCount,
                $pricePerFullUnit,
                $imageInputType,
                $imageLink,
                $imagePath
            );
        }

        return $result;
    }

    protected function buildPrintPartRow(
        array $partData,
        mixed $partId,
        mixed $partName,
        float $unitCount,
        float $pricePerUnit,
        float $fullUnitCount,
        float $pricePerFullUnit,
        mixed $imageInputType,
        mixed $imageLink,
        mixed $imagePath
    ): array {
        $unitPriceTotal = round($unitCount * $pricePerUnit, 2);
        $fullUnitPriceTotal = round($fullUnitCount * $pricePerFullUnit, 2);

        return array_merge($partData, [
            'part_id' => $partId,
            'part' => $partName,
            'unit_count' => $unitCount,
            'price_per_unit' => $pricePerUnit,
            'full_unit_count' => $fullUnitCount,
            'price_per_full_unit' => $pricePerFullUnit,
            'image_input_type' => $imageInputType,
            'image_link' => $imageLink,
            'unit_price_total' => $unitPriceTotal,
            'full_unit_price_total' => $fullUnitPriceTotal,
            'print_part_total' => round($unitPriceTotal + $fullUnitPriceTotal, 2),
            'image' => $imagePath,
        ]);
    }

    protected function resolvePrintMethodFields(array $data, ?Quotation $existing = null): array
    {
        $printMethodId = $data['print_method_id'] ?? $existing?->print_method_id;
        $specialPrint = $data['special_print'] ?? $existing?->special_print;
        $printArea = $data['print_area'] ?? $existing?->print_area;

        if (! $printMethodId) {
            return [null, null, null];
        }

        $printMethod = PrintMethod::find($printMethodId);
        if (! $printMethod) {
            throw ValidationException::withMessages(['print_method_id' => 'The selected print method is invalid.']);
        }

        if (strcasecmp(trim((string) $printMethod->name), 'silkscreen') !== 0) {
            return [$printMethodId, null, null];
        }

        return [$printMethodId, $specialPrint, $printArea];
    }

    protected function generatePoCode(string $prefix = 'QUO'): string
    {
        $year = now()->year;
        $lastQuotationId = Quotation::whereYear('created_at', $year)
            ->lockForUpdate()
            ->orderByDesc('id')
            ->value('quotation_id');

        $lastNumber = 0;
        if (is_string($lastQuotationId) && preg_match('/-(\d+)$/', $lastQuotationId, $matches)) {
            $lastNumber = (int) $matches[1];
        }

        $nextNumber = $lastNumber + 1;
        return sprintf('%s-%d-%06d', $prefix, $year, $nextNumber);
    }

    public function delete(int $id): bool
    {
        $quotation = Quotation::find($id);

        if (!$quotation) {
            return false;
        }

        return $quotation->delete();
    }

    /**
     * Confirm a quotation and return its data shaped for Order pre-filling.
     * Changes quotation status to "Converted" (one-time only).
     */
    public function confirmAndConvert(int $id): array
    {
        $quotation = Quotation::findOrFail($id);

        if ($quotation->status === 'Converted') {
            return [
                'already_converted' => true,
                'quotation' => $quotation,
            ];
        }

        $itemConfig = is_string($quotation->item_config_json)
            ? json_decode($quotation->item_config_json, true)
            : ($quotation->item_config_json ?? []);

        // Resolve IDs (apparel_type_id / pattern_type_id may live in item_config_json)
        $apparelTypeId = $quotation->apparel_type_id ?? ($itemConfig['apparel_type_id'] ?? null);
        $patternTypeId = $quotation->pattern_type_id ?? ($itemConfig['pattern_type_id'] ?? null);
        $printMethodId = $quotation->print_method_id;

        // Resolve human-readable names so the frontend can populate dropdowns by name
        $apparelTypeName = null;
        $patternTypeName = null;
        $printMethodName = null;

        if ($apparelTypeId) {
            $at = ApparelType::find($apparelTypeId);
            $apparelTypeName = $at?->name;
        }
        if ($patternTypeId) {
            $pt = PatternType::find($patternTypeId);
            $patternTypeName = $pt?->name;
        }
        if ($printMethodId) {
            $pm = PrintMethod::find($printMethodId);
            $printMethodName = $pm?->name;
        }

        // Mark as Converted only after all lookups succeed
        $quotation->update(['status' => 'Converted']);

        $orderPayload = [
            'quotation_id'        => $quotation->id,
            'client_id'           => $quotation->client_id,
            'client_name'         => $quotation->client_name,
            'client_brand'        => $quotation->client_brand,
            'apparel_type_id'     => $apparelTypeId,
            'pattern_type_id'     => $patternTypeId,
            'apparel_type_name'   => $apparelTypeName,
            'pattern_type_name'   => $patternTypeName,
            'apparel_neckline_id' => $quotation->apparel_neckline_id,
            'print_method_id'     => $printMethodId,
            'print_method_name'   => $printMethodName,
            'shirt_color'         => $quotation->shirt_color,
            'special_print'       => $quotation->special_print,
            'print_area'          => $quotation->print_area,
            'free_items'          => $quotation->free_items,
            'notes'               => $quotation->notes,
            'discount_type'       => $quotation->discount_type,
            'discount_price'      => $quotation->discount_price,
            'discount_amount'     => $quotation->discount_amount,
            'subtotal'            => $quotation->subtotal,
            'grand_total'         => $quotation->grand_total,
            'item_config_json'    => $quotation->item_config_json,
            'items_json'          => $quotation->items_json,
            'addons_json'         => $quotation->addons_json,
            'breakdown_json'      => $quotation->breakdown_json,
            'print_parts_json'    => $quotation->print_parts_json,
        ];

        return [
            'already_converted' => false,
            'quotation'         => $quotation,
            'order_payload'     => $orderPayload,
        ];
    }
}