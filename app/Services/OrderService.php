<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Order;
use App\Models\Quotation;
use App\Models\OrderSamples;
use App\Models\PoItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Encoding\Encoding;
use Picqer\Barcode\BarcodeGeneratorPNG;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use App\Exceptions\BusinessRuleException;

/**
 * OrderService — owns Order creation.
 *
 * The `orders` table now uses a quotation-derived schema: FK-based
 * apparel/pattern/print method, financials, and JSON carry-over from
 * the source quotation. Production-time fields (courier, fabric, etc.)
 * that used to live on Order are no longer on the table.
 *
 * The store() method is tolerant of either-shape input — it will
 * accept the new payload (`subtotal`/`grand_total`/`apparel_type_id`/...)
 * AND legacy payloads (`total_amount`/`apparel_type` string), so that
 * the existing `/orders/new` form keeps working as the frontend evolves.
 */
class OrderService
{
    protected OrderStagesService $stagesService;
    protected NotificationService $notifications;
    protected QuotationService $quotation;
    protected CsrActivityLogger $activityLogger;
    protected ClientService $clientService;

    public function __construct(OrderStagesService $stagesService, NotificationService $notifications, QuotationService $quotation, CsrActivityLogger $activityLogger, ClientService $clientService)
    {
        $this->stagesService = $stagesService;
        $this->notifications = $notifications;
        $this->quotation = $quotation;
        $this->activityLogger = $activityLogger;
        $this->clientService = $clientService;
    }

    public function store(array $data, array $meta = []): Order
    {
        // Resolve + normalise every persisted field (also enforces the
        // line-item floor and the superadmin Incomplete flag). Shared with
        // update() so both paths normalise, re-price and floor-check the same.
        $f = $this->resolveOrderFields($data, $meta);

        $order = DB::transaction(function () use ($f, $data) {
            // Generate unique PO code + its QR / Barcode artifacts.
            $poCode = $this->generatePoCode('ASH');
            $codes  = $this->generateQrAndBarcode($poCode, 'Orders');

            // Create the Order using ONLY columns present in the orders table.
            $order = Order::create(array_merge(
                $this->buildOrderAttributes($f, $data),
                [
                    'po_code'      => $poCode,
                    'qr_path'      => $codes['qr_path'],
                    'barcode_path' => $codes['barcode_path'],
                ],
            ));

            // PO items + samples — best-effort (form `sizes` / items_json).
            $this->createPoItems($data, $order);
            $this->createOrderSamples($data, $order);

            // File uploads → disk / order_designs.
            $this->storeFiles($data, $order);

            // Auto-create the full sequential workflow.
            $this->stagesService->initializeForOrder($order);

            return $order->load('items', 'samples', 'orderStages');
        });

        // Notifications fire after the commit.
        $this->notifications->orderCreated($order);

        // Change 11 — audit the override.
        if ($order->is_incomplete) {
            $actor = $meta['actor'] ?? null;
            $this->activityLogger->log(
                action:   'order.saved_incomplete',
                summary:  "{$order->po_code} saved with " . count($f['incomplete_fields']) . " missing field(s) via superadmin override",
                subject:  $order,
                orderId:  $order->id,
                clientId: $order->client_id,
                data:     ['incomplete_fields' => $f['incomplete_fields']],
                userId:   $actor?->getKey(),
            );
        }

        return $order;
    }

    /**
     * Per-Color auto-split conversion. For a MULTI-colour quotation, mint one
     * single-colour order per colour group directly (no per-form review),
     * reusing store() so pricing / line items / workflow / po_code are
     * identical to a normal create.
     *
     * Why this reconciles to the quote: the silkscreen charge is a PER-PIECE
     * amount (same rate for every garment colour — colour doesn't move the
     * print price), so re-pricing each colour's quantities and summing returns
     * the quoted total. Allocation rules (confirmed): sample -> first PO only;
     * fixed discount -> first PO only; percentage discount -> every PO.
     * Atomic: all-or-nothing in one transaction.
     *
     * @return Order[]
     */
    public function convertQuotationSplit(Quotation $quote, array $meta = []): array
    {
        if (strcasecmp((string) $quote->status, Quotation::STATUS_CONVERTED) === 0) {
            throw new BusinessRuleException(
                'This quotation has already been converted to an order.',
                'QUOTATION_ALREADY_CONVERTED',
                409,
            );
        }

        // Base payload (apparel/pattern/print names, client address, labels,
        // blobs) built once from the quotation.
        $base = $this->quotation->buildOrderPayload($quote);

        // Colour groups that actually carry quantities.
        $breakdown = is_array($quote->breakdown_json) ? $quote->breakdown_json : [];
        $groups = is_array($breakdown['color_breakdowns'] ?? null) ? $breakdown['color_breakdowns'] : [];
        $groups = array_values(array_filter($groups, function ($g) {
            foreach ((is_array($g['sizes'] ?? null) ? $g['sizes'] : []) as $sz) {
                if ((int) ($sz['quantity'] ?? 0) > 0) {
                    return true;
                }
            }
            return false;
        }));

        if (count($groups) < 2) {
            throw new BusinessRuleException(
                'Auto-split needs a multi-colour quotation (2+ colours with quantities).',
                'QUOTATION_NOT_MULTICOLOR',
                422,
            );
        }

        $isPercentDiscount = strcasecmp((string) ($quote->discount_type ?? ''), 'percentage') === 0;
        $quoteItems = is_array($quote->items_json) ? $quote->items_json : [];

        $orders = DB::transaction(function () use ($quote, $base, $groups, $isPercentDiscount, $quoteItems, $meta) {
            $created = [];

            foreach ($groups as $idx => $group) {
                $isFirst = $idx === 0;
                $color = trim((string) ($group['color'] ?? '')) ?: ($quote->shirt_color ?: 'Unspecified');

                // Per-colour line items: clone the matching quote rows (keeps the
                // priced row shape) with this colour's quantities. store() re-prices
                // anyway; the engine sees only this colour's quantities.
                $perColorItems = $this->sliceItemsForColor($quoteItems, $group['sizes'] ?? []);

                // Per-colour subtotal from per-piece price x this colour's qty.
                // store() overrides this via the engine when priceable (always,
                // for a real quote); this is a guard so an unpriced quote can
                // never carry the whole-job total onto each split P.O.
                $perColorSubtotal = 0.0;
                foreach ($perColorItems as $r) {
                    $perColorSubtotal += (float) ($r['price_per_piece'] ?? $r['unit_price'] ?? 0)
                        * (int) ($r['quantity'] ?? 0);
                }
                $perColorSubtotal = round($perColorSubtotal, 2);

                // breakdown_json: a single-colour order has no per-colour split;
                // strip color_breakdowns, and keep the sample only on the first PO.
                $bd = is_array($base['breakdown_json'] ?? null) ? $base['breakdown_json'] : [];
                unset($bd['color_breakdowns']);
                if (! $isFirst) {
                    unset($bd['sample_breakdown']);
                }

                $payload = array_merge($base, [
                    'shirt_color'    => $color,
                    'fabric_color'   => $color,
                    'items_json'     => $perColorItems,
                    'subtotal'       => $perColorSubtotal,
                    'grand_total'    => $perColorSubtotal,
                    'sizes'          => array_map(fn ($r) => [
                        'name'     => $r['size'] ?? $r['name'] ?? '',
                        'size'     => $r['size'] ?? $r['name'] ?? '',
                        'quantity' => (int) ($r['quantity'] ?? 0),
                    ], $perColorItems),
                    'breakdown_json' => $bd,
                    // Sample → FIRST P.O. only, as an OrderSamples record (a
                    // separate table, so it survives store()'s engine re-price,
                    // unlike breakdown_json.sample_breakdown). The production
                    // grand_total stays sample-free, exactly as a normal
                    // converted order; the sample rides its own sample-payment
                    // stage on this one P.O. so it's never charged per colour.
                    'samples'        => $isFirst ? $this->sampleRowsFromQuote($base) : [],
                ]);

                // Fixed discount applies once (first PO); percentage applies to all.
                if (! $isPercentDiscount && ! $isFirst) {
                    $payload['discount_type']   = null;
                    $payload['discount_price']  = 0;
                    $payload['discount_amount'] = 0;
                }

                $created[] = $this->store($payload, $meta);
            }

            // Finalise the quotation (status + audit) inside the same transaction.
            $actorId = isset($meta['actor']) ? $meta['actor']?->getKey() : null;
            $this->quotation->markConverted(
                $quote,
                'Converted to ' . count($created) . ' single-colour orders (per-colour split).',
                $actorId,
            );

            return $created;
        });

        return $orders;
    }

    /**
     * Build a single colour's items_json by cloning the quotation's priced rows
     * (matched by size name) and overriding the quantity with that colour's.
     * Sizes present in the colour but missing from the quote items fall back to
     * a minimal {size, quantity} row.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function sliceItemsForColor(array $quoteItems, $colorSizes): array
    {
        $colorSizes = is_array($colorSizes) ? $colorSizes : [];

        $byName = [];
        foreach ($colorSizes as $sz) {
            if (! is_array($sz)) {
                continue;
            }
            $name = strtoupper(trim((string) ($sz['size'] ?? $sz['name'] ?? '')));
            $qty  = (int) ($sz['quantity'] ?? 0);
            if ($name !== '' && $qty > 0) {
                $byName[$name] = $qty;
            }
        }

        $rows = [];
        foreach ($quoteItems as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = strtoupper(trim((string) ($row['size'] ?? $row['name'] ?? '')));
            if ($name !== '' && array_key_exists($name, $byName)) {
                $clone = $row;
                $clone['quantity'] = $byName[$name];
                $rows[] = $clone;
                unset($byName[$name]);
            }
        }

        // Any colour sizes not present in the quote items (edge case).
        foreach ($byName as $name => $qty) {
            $rows[] = ['size' => $name, 'quantity' => $qty];
        }

        return $rows;
    }

    /**
     * Map the quotation's single sample_breakdown ({sample_apparel, unit_price,
     * quantity, price_per_piece}) into the `samples` payload shape that
     * createOrderSamples() consumes ({size, quantity, unit_price, total_price}).
     * Returns [] when the quote carries no sample, so createOrderSamples no-ops.
     *
     * @param array<string, mixed> $base  the buildOrderPayload() result
     * @return array<int, array<string, mixed>>
     */
    protected function sampleRowsFromQuote(array $base): array
    {
        $bd = is_array($base['breakdown_json'] ?? null) ? $base['breakdown_json'] : [];
        $sb = is_array($bd['sample_breakdown'] ?? null) ? $bd['sample_breakdown'] : [];

        $qty   = (float) ($sb['quantity'] ?? 0);
        $unit  = (float) ($sb['unit_price'] ?? 0);
        $total = (float) ($sb['price_per_piece'] ?? ($unit * $qty));

        if ($qty <= 0 && $total <= 0) {
            return [];
        }

        return [[
            'size'        => $sb['sample_apparel'] ?? null,
            'quantity'    => $qty > 0 ? $qty : 1,
            'unit_price'  => $unit,
            'total_price' => $total,
        ]];
    }

    /**
     * Update an existing order. Mirrors store()'s normalisation, line-item
     * floor, re-pricing and Incomplete-flag logic, but:
     *   - keeps po_code / qr_path / barcode_path / status / workflow intact,
     *   - does NOT (re)initialise the stage workflow,
     *   - DIFFS PO items (preserves SKU/QR/barcode for unchanged sizes),
     *   - clears the Incomplete flag on a clean save (no override).
     *
     * Editability (order not yet in production) is enforced by the caller.
     */
    public function update(Order $order, array $data, array $meta = []): Order
    {
        $f = $this->resolveOrderFields($data, $meta);
        $wasIncomplete = (bool) $order->is_incomplete;

        DB::transaction(function () use ($order, $f, $data, $meta) {
            // po_code / qr_path / barcode_path / status / workflow are NOT in
            // buildOrderAttributes(), so they stay as-is.
            $order->update($this->buildOrderAttributes($f, $data));

            // Reconcile line items against the submitted sizes.
            $this->diffPoItems($data, $order);

            // New files are additive (existing uploads are preserved).
            $this->storeFiles($data, $order);

            // Issue 2 — opt-in client-master write-back. Only when the CSR
            // explicitly confirmed it on the edit form (multipart sends "1").
            if (filter_var($data['sync_client'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $this->syncClientFromOrder($order, $meta['actor'] ?? null);
            }
        });

        $order->refresh()->load('items', 'samples', 'orderStages');

        // Audit: completing (flag cleared) and re-flagging are recorded
        // distinctly; a plain edit logs order.updated.
        $actor = $meta['actor'] ?? null;
        if ($order->is_incomplete) {
            $this->activityLogger->log(
                action:   'order.saved_incomplete',
                summary:  "{$order->po_code} updated, still missing " . count($f['incomplete_fields']) . " field(s) (superadmin override)",
                subject:  $order,
                orderId:  $order->id,
                clientId: $order->client_id,
                data:     ['incomplete_fields' => $f['incomplete_fields']],
                userId:   $actor?->getKey(),
            );
        } elseif ($wasIncomplete) {
            $this->activityLogger->log(
                action:   'order.completed',
                summary:  "{$order->po_code} completed — all required details filled in",
                subject:  $order,
                orderId:  $order->id,
                clientId: $order->client_id,
                data:     [],
                userId:   $actor?->getKey(),
            );
        } else {
            $this->activityLogger->log(
                action:   'order.updated',
                summary:  "{$order->po_code} updated",
                subject:  $order,
                orderId:  $order->id,
                clientId: $order->client_id,
                data:     [],
                userId:   $actor?->getKey(),
            );
        }

        return $order;
    }

    /**
     * Resolve + normalise every persisted order field from a raw payload.
     *
     * Accepts both legacy (form) and modern (quotation prefill / convert)
     * field names and maps to the orders-table shape. Also enforces the hard
     * line-item floor (throws BusinessRuleException), resolves the Change 11
     * Incomplete flag from the override meta, and re-prices through the engine
     * when the order is engine-priceable. Shared by store() and update().
     *
     * @return array<string, mixed>
     */
    protected function resolveOrderFields(array $data, array $meta = []): array
    {
        $pick = function (string ...$keys) use ($data) {
            foreach ($keys as $k) {
                if (array_key_exists($k, $data) && $data[$k] !== null && $data[$k] !== '') {
                    return $data[$k];
                }
            }
            return null;
        };

        $clientId       = $pick('client_id', 'client');
        $clientBrand    = $pick('client_brand', 'company');
        $clientName     = $pick('client_name');
        $apparelTypeId  = $pick('apparel_type_id');
        $patternTypeId  = $pick('pattern_type_id');
        $printMethodId  = $pick('print_method_id');
        $necklineId     = $pick('apparel_neckline_id');
        $shirtColor     = $pick('shirt_color');
        $specialPrint   = $pick('special_print');
        $printArea      = $pick('print_area');
        $freeItems      = $pick('free_items', 'freebie_others');
        $notes          = $pick('notes');

        $discountType   = $pick('discount_type');
        $discountPrice  = $pick('discount_price') ?? 0;
        $discountAmount = $pick('discount_amount') ?? 0;
        $subtotal       = $pick('subtotal', 'total_amount') ?? 0;
        $grandTotal     = $pick('grand_total', 'estimated_total', 'total_amount') ?? 0;

        $itemConfigJson = $this->decodeJson($pick('item_config_json'));
        $itemsJson      = $this->decodeJson($pick('items_json'));
        $addonsJson     = $this->decodeJson($pick('addons_json'));
        $breakdownJson  = $this->decodeJson($pick('breakdown_json'));
        $printPartsJson = $this->decodeJson($pick('print_parts_json'));

        $quotationId    = $pick('quotation_id');

        // ── Change 11: hard floor (never bypassable) ─────────────────────
        $itemSources = [
            $data['sizes'] ?? null,
            $itemsJson,
            is_array($breakdownJson) ? ($breakdownJson['items'] ?? $breakdownJson) : null,
        ];
        $hasLineItem = false;
        foreach ($itemSources as $src) {
            if (is_array($src)
                && count(array_filter($src, fn ($row) => is_array($row) && ! empty($row))) > 0) {
                $hasLineItem = true;
                break;
            }
        }
        if (! $hasLineItem) {
            throw new BusinessRuleException(
                'An order needs at least one line item (a size with quantity) before it can be saved.',
                'ORDER_NO_LINE_ITEMS',
                422,
                ['sizes' => 'Add at least one size and quantity.'],
            );
        }

        // ── Change 11: resolve the Incomplete flag from the override meta ──
        $overrideRequested = (bool) ($meta['override'] ?? false);
        $incompleteFields  = array_values(array_filter(
            array_map(fn ($v) => trim((string) $v), (array) ($meta['incomplete_fields'] ?? [])),
            fn ($v) => $v !== ''
        ));
        $isIncomplete = $overrideRequested && ! empty($incompleteFields);

        // ── Option-A pricing hardening ───────────────────────────────────
        if (is_array($itemConfigJson) && ! empty($itemConfigJson['apparel_pattern_price_id'])) {
            try {
                $computed = $this->quotation->preview([
                    'item_config_json'    => $itemConfigJson,
                    'items_json'          => $itemsJson,
                    'print_parts_json'    => $printPartsJson,
                    'addons_json'         => $addonsJson,
                    'apparel_neckline_id' => $necklineId,
                    'discount_type'       => $discountType,
                    'discount_price'      => $discountPrice,
                ]);

                $submittedGrand = (float) $grandTotal;

                $subtotal       = $computed['subtotal'] ?? $subtotal;
                $grandTotal     = $computed['grand_total'] ?? $grandTotal;
                $discountAmount = $computed['discount_amount'] ?? $discountAmount;
                if (! empty($computed['breakdown_json'])) {
                    $breakdownJson = $computed['breakdown_json'];
                }

                if (abs($submittedGrand - (float) $grandTotal) > 0.01) {
                    Log::warning('Order persist: client grand_total overridden by engine recompute', [
                        'submitted_grand_total' => $submittedGrand,
                        'engine_grand_total'    => (float) $grandTotal,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('Order persist: engine recompute failed; using submitted totals', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Print parts carry their artwork + per-colour price on the linked
        // quotation; the Add Order form sends them read-only and strips those
        // fields on submit. Re-hydrate from the quotation so the order's
        // print_parts_json holds image + price + colour count for display
        // (Print Parts table, orders-list thumbnail). Display data only —
        // pricing is already final above.
        $printPartsJson = $this->enrichPrintPartsFromQuotation($printPartsJson, $quotationId);

        // Sample fold (decision: sample is part of the order Grand Total).
        // The conversion otherwise prices the order sample-free; fold the
        // sample into subtotal / grand_total / 60-40 split so the order
        // matches the quotation. Payment amounts are entered manually, so
        // this is a reference/display total — the sample becomes part of the
        // downpayment + balance, NOT an additional charge on top.
        $sampleFold = $this->foldSampleIntoTotals(
            (float) $subtotal,
            is_array($data['samples'] ?? null) ? $data['samples'] : [],
            $discountType,
            (float) $discountPrice,
        );
        if ($sampleFold !== null) {
            $subtotal       = $sampleFold['subtotal'];
            $discountAmount = $sampleFold['discount_amount'];
            $grandTotal     = $sampleFold['grand_total'];
            $breakdownJson  = is_array($breakdownJson) ? $breakdownJson : [];
            $breakdownJson['sample_breakdown'] = $sampleFold['sample_breakdown'];
            $breakdownJson['downpayment']      = $sampleFold['downpayment'];
            $breakdownJson['balance']          = $sampleFold['balance'];
        }

        // Payment-plan split (runs LAST so it wins over the engine recompute
        // and the sample fold, both of which hardcode the Addendum 5.4 60/40
        // terms): a Full Payment order collects 100% upfront at the sample
        // gate, so its stored split is downpayment = grand total, balance = 0.
        // The workflow reads this plan to auto-pass the mass/balance gates.
        if (($data['payment_plan'] ?? null) === 'full_payment') {
            $breakdownJson = is_array($breakdownJson) ? $breakdownJson : [];
            $breakdownJson['downpayment']  = round((float) $grandTotal, 2);
            $breakdownJson['balance']      = 0.0;
            $breakdownJson['payment_plan'] = 'full_payment';
        }

        return [
            'client_id'           => $clientId,
            'client_brand'        => $clientBrand,
            'client_name'         => $clientName,
            'apparel_type_id'     => $apparelTypeId,
            'pattern_type_id'     => $patternTypeId,
            'print_method_id'     => $printMethodId,
            'apparel_neckline_id' => $necklineId,
            'shirt_color'         => $shirtColor,
            'special_print'       => $specialPrint,
            'print_area'          => $printArea,
            'free_items'          => $freeItems,
            'notes'               => $notes,
            'discount_type'       => $discountType,
            'discount_price'      => $discountPrice,
            'discount_amount'     => $discountAmount,
            'subtotal'            => $subtotal,
            'grand_total'         => $grandTotal,
            'item_config_json'    => $itemConfigJson,
            'items_json'          => $itemsJson,
            'addons_json'         => $addonsJson,
            'breakdown_json'      => $breakdownJson,
            'print_parts_json'    => $printPartsJson,
            'quotation_id'        => $quotationId,
            'is_incomplete'       => $isIncomplete,
            'incomplete_fields'   => $incompleteFields,
        ];
    }

    /**
     * Build the Order attribute array from resolved fields ($f) + the raw
     * payload ($data, for the form-only columns). Excludes po_code / qr_path /
     * barcode_path (store() generates those once; update() must not touch
     * them). Shared by store() and update().
     *
     * @param array<string, mixed> $f
     * @return array<string, mixed>
     */
    /**
     * Re-hydrate an order's print_parts_json rows with the artwork and
     * per-colour price stored on the linked quotation.
     *
     * The Add Order form carries print parts read-only from the quotation and
     * submits a stripped payload (placement + colour count only — no image,
     * no price). The quotation's own print_parts_json is the authoritative
     * source: it holds image / image_path / price_per_color / color_count.
     * Merging those in lets the order's Print Parts table and the orders-list
     * thumbnail display correctly. DISPLAY data only — pricing is already
     * computed into items / subtotal / grand_total.
     *
     * Rows are matched to the quotation by part name (case-insensitive), with a
     * positional fallback. The order row wins on overlapping keys (colour
     * count, print type, geometry); quotation-only keys (image, price) fill in.
     *
     * @param  array<int, mixed>|null  $orderParts
     * @param  int|string|null  $quotationId
     * @return array<int, mixed>|null
     */
    public function enrichPrintPartsFromQuotation(?array $orderParts, $quotationId): ?array
    {
        if (empty($orderParts) || empty($quotationId)) {
            return $orderParts;
        }

        $quotation = Quotation::find($quotationId);
        $quoteParts = is_array($quotation?->print_parts_json) ? $quotation->print_parts_json : [];
        if (empty($quoteParts)) {
            return $orderParts;
        }

        // Index the quotation rows by normalised part name for matching.
        $byName = [];
        foreach ($quoteParts as $qp) {
            if (! is_array($qp)) {
                continue;
            }
            $name = strtolower(trim((string) ($qp['part'] ?? $qp['name'] ?? '')));
            if ($name !== '') {
                $byName[$name] = $qp;
            }
        }

        $result = [];
        foreach (array_values($orderParts) as $i => $orderRow) {
            if (! is_array($orderRow)) {
                $result[] = $orderRow;
                continue;
            }

            $name = strtolower(trim((string) ($orderRow['part'] ?? $orderRow['name'] ?? '')));
            $quoteRow = $byName[$name] ?? ($quoteParts[$i] ?? null);

            if (! is_array($quoteRow)) {
                $result[] = $orderRow;
                continue;
            }

            // Quotation supplies image + price + colour count; the order row
            // (engine output) overrides on overlapping keys.
            $result[] = array_merge($quoteRow, $orderRow);
        }

        return $result;
    }

    /**
     * Fold a sample charge into an order's production totals so the order's
     * Grand Total reflects the full value (production + sample), matching the
     * quotation. The 60/40 split is recomputed on the sample-inclusive grand
     * total, and a discount (if any) is applied to the sample-inclusive
     * subtotal — exactly as the quotation engine does.
     *
     * DISPLAY/REFERENCE total only: payment amounts are entered manually, so
     * this does not auto-charge anything. The sample becomes part of the
     * downpayment + balance rather than a separate charge.
     *
     * @param  float  $sampleFreeSubtotal  items + addons + fees, BEFORE the sample
     * @param  array<int, mixed>  $samples  OrderSamples-shaped rows (size, quantity, unit_price, total_price)
     * @param  string|null  $discountType
     * @param  float  $discountPrice
     * @return array{subtotal: float, discount_amount: float, grand_total: float, downpayment: float, balance: float, sample_breakdown: array<string, mixed>}|null
     *   null when there is no sample to fold.
     */
    public function foldSampleIntoTotals(float $sampleFreeSubtotal, array $samples, ?string $discountType, float $discountPrice): ?array
    {
        $sampleTotal = 0.0;
        $first = null;
        foreach ($samples as $s) {
            if (! is_array($s)) {
                continue;
            }
            $sampleTotal += (float) ($s['total_price'] ?? 0);
            if ($first === null) {
                $first = $s;
            }
        }
        $sampleTotal = round($sampleTotal, 2);
        if ($sampleTotal <= 0 || $first === null) {
            return null;
        }

        $subtotal = round($sampleFreeSubtotal + $sampleTotal, 2);

        $discountAmount = 0.0;
        if ($discountType === 'percentage') {
            $discountAmount = round($subtotal * ($discountPrice / 100), 2);
        } elseif ($discountType === 'fixed') {
            $discountAmount = min($discountPrice, $subtotal);
        }
        $discountAmount = max(0, $discountAmount);

        $grandTotal = round($subtotal - $discountAmount, 2);
        $downpayment = round($grandTotal * 0.60, 2);
        $balance = round($grandTotal - $downpayment, 2);

        return [
            'subtotal' => $subtotal,
            'discount_amount' => $discountAmount,
            'grand_total' => $grandTotal,
            'downpayment' => $downpayment,
            'balance' => $balance,
            'sample_breakdown' => [
                'sample_apparel' => $first['size'] ?? $first['sample_apparel'] ?? null,
                'unit_price' => (float) ($first['unit_price'] ?? 0),
                'quantity' => (float) ($first['quantity'] ?? 0),
                'price_per_piece' => $sampleTotal,
            ],
        ];
    }

    protected function buildOrderAttributes(array $f, array $data): array
    {
        $attrs = [
            // Linkage
            'quotation_id'       => $f['quotation_id'],

            // Client
            'client_id'          => $f['client_id'],
            'client_name'        => $f['client_name'],
            'client_brand'       => $f['client_brand'],

            // Apparel + print method (FK based)
            'apparel_type_id'    => $f['apparel_type_id'],
            'pattern_type_id'    => $f['pattern_type_id'],
            'apparel_neckline_id'=> $f['apparel_neckline_id'],
            'print_method_id'    => $f['print_method_id'],

            // Print details
            'shirt_color'        => $f['shirt_color'],
            'special_print'      => $f['special_print'],
            'print_area'         => $f['print_area'],

            // Misc descriptive
            'free_items'         => $f['free_items'],
            'notes'              => $f['notes'],

            // Financials
            'discount_type'      => $f['discount_type'],
            'discount_price'     => $f['discount_price'],
            'discount_amount'    => $f['discount_amount'],
            'subtotal'           => $f['subtotal'],
            'grand_total'        => $f['grand_total'],

            // JSON blobs carried over from the quotation
            'item_config_json'   => $f['item_config_json'],
            'items_json'         => $f['items_json'],
            'addons_json'        => $f['addons_json'],
            'breakdown_json'     => $f['breakdown_json'],
            'print_parts_json'   => $f['print_parts_json'],

            // ── Order Information ────────────────────────────────────
            // These columns exist on `orders` and the form sends them, but
            // were previously dropped on create. Persist on store + update.
            'deadline'           => $data['deadline'] ?? null,
            'priority'           => $data['priority'] ?? null,
            'brand'              => $data['brand'] ?? null,

            // ── Shipping / courier (Add Order) ──────────────────────
            'courier'            => $data['courier'] ?? null,
            'method'             => $data['method'] ?? null,
            'receiver_name'      => $data['receiver_name'] ?? null,
            'contact_number'     => $data['contact_number'] ?? null,
            'street_address'     => $data['street_address'] ?? null,
            'barangay_address'   => $data['barangay_address'] ?? null,
            'city_address'       => $data['city_address'] ?? null,
            'province_address'   => $data['province_address'] ?? null,
            'postal_address'     => $data['postal_address'] ?? null,

            // ── Production details (Add Order) ──────────────────────
            'design_name'           => $data['design_name'] ?? null,
            'service_type'          => $data['service_type'] ?? null,
            'print_service'         => $data['print_service'] ?? null,

            // Labels: Brand + Care/Size spec (JSON blobs), mirroring the
            // quotation. decodeJson() accepts BOTH the form's JSON string and
            // an already-decoded array; the model's array cast re-encodes on
            // save. The shared label_design_path is handled below (so it can be
            // preserved on edit when the request carries no new value).
            'brand_label_json'      => $this->decodeJson($data['brand_label_json'] ?? null),
            'care_label_json'       => $this->decodeJson($data['care_label_json'] ?? null),
            'fabric_type'           => $data['fabric_type'] ?? null,
            'fabric_supplier'       => $data['fabric_supplier'] ?? null,
            'fabric_color'          => $data['fabric_color'] ?? null,
            'thread_color'          => $data['thread_color'] ?? null,
            'ribbing_color'         => $data['ribbing_color'] ?? null,
            'freebie_items'         => $data['freebie_items'] ?? null,
            'freebie_color'         => $data['freebie_color'] ?? null,
            'freebie_others'        => $data['freebie_others'] ?? null,
            'payment_plan'          => $data['payment_plan'] ?? null,
            'payment_method'        => $data['payment_method'] ?? null,
            'deposit_percentage'    => $data['deposit_percentage'] ?? null,

            // Change 11 — Incomplete flag (cleared on a clean save)
            'is_incomplete'         => $f['is_incomplete'],
            'incomplete_fields'     => $f['is_incomplete'] ? $f['incomplete_fields'] : null,
        ];

        // `priority` is NOT NULL with a DB default ('normal'). Never write an
        // explicit null: on create it would violate the constraint, and on
        // update it would clobber the value. Omitting the key lets create fall
        // back to the default and update keep the existing value.
        if ($attrs['priority'] === null) {
            unset($attrs['priority']);
        }

        // label_design_path: only touch it when the request actually carried a
        // value (an upload resolved by OrdersController, a link, or the existing
        // path echoed back on edit). Omitting the key on update preserves the
        // current value, mirroring the quotation's label-design edit semantics;
        // on create the column simply stays null.
        if (array_key_exists('label_design_path', $data)) {
            $attrs['label_design_path'] = $data['label_design_path'];
        }

        return $attrs;
    }

    /**
     * Reconcile an order's PO items against the submitted sizes. Existing rows
     * for a kept size are preserved (SKU / QR / barcode + any printed labels
     * stay) and only their quantity / design / color are refreshed; sizes no
     * longer present are deleted; brand-new sizes are minted via the same
     * SKU/QR/barcode scheme as createPoItems(). No-op when no sizes submitted.
     */
    protected function diffPoItems(array $data, Order $order): void
    {
        $sizes = $data['sizes'] ?? null;
        if (! is_array($sizes) || empty($sizes)) {
            $itemsJson = $data['items_json'] ?? $order->items_json;
            if (is_string($itemsJson)) {
                $decoded = json_decode($itemsJson, true);
                $sizes = is_array($decoded) ? $decoded : [];
            } elseif (is_array($itemsJson)) {
                $sizes = $itemsJson;
            } else {
                $sizes = [];
            }
        }

        if (empty($sizes)) return;

        $submitted = [];
        foreach ($sizes as $size) {
            if (! is_array($size)) continue;
            $name = (string) ($size['name'] ?? $size['size'] ?? '');
            if ($name === '') continue;
            $submitted[strtoupper($name)] = [
                'name'     => $name,
                'quantity' => $size['quantity'] ?? 0,
            ];
        }

        $existing = $order->items()->get()->keyBy(fn ($it) => strtoupper((string) $it->size));

        $designName  = $data['design_name'] ?? $order->design_name ?? null;
        $fabricColor = $data['fabric_color'] ?? $order->shirt_color ?? null;

        // Remove sizes no longer present.
        foreach ($existing as $key => $item) {
            if (! array_key_exists($key, $submitted)) {
                $item->delete();
            }
        }

        // Update kept sizes; mint new ones.
        foreach ($submitted as $key => $row) {
            if (isset($existing[$key])) {
                $existing[$key]->update([
                    'quantity'    => $row['quantity'],
                    'design_code' => $designName ?? '',
                    'color'       => $fabricColor ?? '',
                ]);
                continue;
            }

            $brandPrefix = 'X';
            $lastNumber = PoItem::where('sku', 'like', $brandPrefix . '%')
                ->orderByDesc('id')
                ->value('sku');
            $lastNumber = $lastNumber ? (int) substr($lastNumber, 1, 3) : 0;
            $nextNumber = $lastNumber + 1;
            $sizeCode   = strtoupper($row['name']);
            $sku        = $brandPrefix . str_pad((string) $nextNumber, 3, '0', STR_PAD_LEFT) . "U{$sizeCode}";
            $paths      = $this->generateQrAndBarcode($sku, 'ItemCodes');

            PoItem::create([
                'order_id'     => $order->id,
                'sku'          => $sku,
                'design_code'  => $designName ?? '',
                'color'        => $fabricColor ?? '',
                'size'         => $row['name'],
                'quantity'     => $row['quantity'],
                'qr_path'      => $paths['qr_path'],
                'barcode_path' => $paths['barcode_path'],
            ]);
        }
    }

    /**
     * Decode a JSON-string OR pass through an already-decoded array OR
     * return null. Useful for fields that arrive either way through the
     * controller depending on whether the request used FormData (string)
     * or JSON (array).
     */
    protected function decodeJson($value): ?array
    {
        if ($value === null || $value === '') return null;
        if (is_array($value)) return $value;
        if (! is_string($value)) return null;
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Generate QR and Barcode artifacts for a code, returning both paths.
     */
    protected function generateQrAndBarcode(string $code, string $folder): array
    {
        $basePath = "{$folder}";
        $publicPath = "/storage/{$folder}";

        $qrFile = "qr_{$code}.png";
        $barcodeFile = "barcode_{$code}.png";

        Storage::disk('public')->makeDirectory($basePath);

        $qrFullPath = "{$basePath}/{$qrFile}";
        $barcodeFullPath = "{$basePath}/{$barcodeFile}";

        $qrImage = Builder::create()
            ->writer(new PngWriter())
            ->data($code)
            ->encoding(new Encoding('UTF-8'))
            ->size(200)
            ->build()
            ->getString();

        Storage::disk('public')->put($qrFullPath, $qrImage);

        $generator = new BarcodeGeneratorPNG();
        $barcodeData = $generator->getBarcode($code, $generator::TYPE_CODE_128, 2, 50);
        Storage::disk('public')->put($barcodeFullPath, $barcodeData);

        return [
            'qr_path'      => "{$publicPath}/{$qrFile}",
            'barcode_path' => "{$publicPath}/{$barcodeFile}",
        ];
    }

    /**
     * Create PO items from the form `sizes` array OR from the items_json
     * carried over from the source quotation. Best-effort: skips quietly
     * when neither is present.
     *
     * SKU prefix used to come from $order->brand (now removed). We pick
     * a default 'X' prefix when no brand context is available.
     */
    protected function createPoItems(array $data, Order $order): void
    {
        // Try the form `sizes` shape first; fall back to items_json.
        $sizes = $data['sizes'] ?? null;
        if (! is_array($sizes) || empty($sizes)) {
            $itemsJson = $data['items_json'] ?? $order->items_json;
            if (is_string($itemsJson)) {
                $decoded = json_decode($itemsJson, true);
                $sizes = is_array($decoded) ? $decoded : [];
            } elseif (is_array($itemsJson)) {
                $sizes = $itemsJson;
            } else {
                $sizes = [];
            }
        }

        if (empty($sizes)) return;

        // SKU prefix. With brand gone from the schema, default to 'X'.
        $brandPrefix = 'X';

        $lastNumber = PoItem::where('sku', 'like', $brandPrefix . '%')
            ->orderByDesc('id')
            ->value('sku');

        $lastNumber = $lastNumber ? (int) substr($lastNumber, 1, 3) : 0;

        $designName = $data['design_name'] ?? null;
        $fabricColor = $data['fabric_color'] ?? $order->shirt_color ?? null;

        foreach ($sizes as $size) {
            if (! is_array($size)) continue;

            $sizeName = $size['name'] ?? $size['size'] ?? '';
            $sizeCode = strtoupper((string) $sizeName);
            if ($sizeCode === '') continue;

            $nextNumber = $lastNumber + 1;
            $sku = $brandPrefix . str_pad((string) $nextNumber, 3, '0', STR_PAD_LEFT) . "U{$sizeCode}";

            $paths = $this->generateQrAndBarcode($sku, 'ItemCodes');

            PoItem::create([
                'order_id'     => $order->id,
                'sku'          => $sku,
                // design_code + color are NOT NULL on po_items, but both are
                // OPTIONAL on the order form (and may be skipped entirely on a
                // superadmin "save anyway" / Incomplete order). Coalesce to ''
                // so an order missing these still creates its line items.
                'design_code'  => $designName ?? '',
                'color'        => $fabricColor ?? '',
                'size'         => $sizeName,
                'quantity'     => $size['quantity'] ?? 0,
                'qr_path'      => $paths['qr_path'],
                'barcode_path' => $paths['barcode_path'],
            ]);

            $lastNumber = $nextNumber;
        }
    }

    /**
     * Create Order Samples. Skips quietly when no `samples` array is
     * present in the payload (the convert-from-quotation flow doesn't
     * always include them).
     */
    protected function createOrderSamples(array $data, Order $order): void
    {
        $samples = $data['samples'] ?? [];
        if (! is_array($samples) || empty($samples)) return;

        foreach ($samples as $sample) {
            if (! is_array($sample)) continue;

            OrderSamples::create([
                'order_id'    => $order->id,
                'size'        => $sample['size'] ?? null,
                'quantity'    => $sample['quantity'] ?? 0,
                'total_price' => $sample['total_price'] ?? 0,
                'unit_price'  => $sample['unit_price'] ?? 0,
            ]);
        }
    }

    /**
     * Persist any uploaded design files into storage.
     *
     * NOTE: The new orders table does NOT have `design_files` /
     * `design_mockup` / `freebies_files` columns.
     * We physically store the uploaded files under `orders/{po_code}/...`
     * so they're not lost, but we don't write any path back to the
     * orders row (it would crash on a missing column). Phase 5 will
     * introduce a proper attachment table.
     */
    protected function storeFiles(array $data, Order $order): void
    {
        $map = [
            'design_files'     => 'design_files',
            'design_mockup'    => 'design_mockups',
            'freebies_files'   => 'freebies',
            'payments'         => 'payments',
        ];

        foreach ($map as $field => $folder) {
            $files = $data[$field] ?? null;
            if (empty($files) || ! is_array($files)) continue;

            foreach ($files as $file) {
                if (! is_object($file) || ! method_exists($file, 'storeAs')) continue;
                $filename = $file->getClientOriginalName();
                $file->storeAs("orders/{$order->po_code}/{$folder}", $filename, 'public');
            }
        }
    }

    /**
     * Generate unique PO code.
     */
    protected function generatePoCode(string $prefix = 'ASH'): string
    {
        $year = now()->year;
        // withTrashed(): PO numbers must never be reused, so a soft-deleted
        // order still "occupies" its number. Without this, deleting the most
        // recent order would let the next order reclaim its PO code and
        // collide on the unique index.
        //
        // The trailing sequence is parsed in PHP rather than via the MySQL-only
        // SUBSTRING_INDEX(), so the query is portable — identical result on
        // MySQL/MariaDB and on the sqlite test database. lockForUpdate stays
        // for concurrency on MySQL (it is a no-op on sqlite, which has no
        // row-level locks).
        $lastNumber = Order::withTrashed()
            ->whereYear('created_at', $year)
            ->lockForUpdate()
            ->pluck('po_code')
            ->map(fn ($code) => (int) substr((string) $code, strrpos((string) $code, '-') + 1))
            ->max();

        $nextNumber = ((int) $lastNumber) + 1;
        return sprintf('%s-%d-%06d', $prefix, $year, $nextNumber);
    }

    /**
     * Issue 2 — opt-in client-master write-back (decisions: opt-in confirm at
     * order save; address block + contact number ONLY; overwrite-on-confirm).
     *
     * Compares the order's shipping/contact snapshot against the client
     * master, overwrites the differing master fields via ClientService
     * (which merges partial address parts and recomposes the derived
     * single-line `address`), and writes ONE immutable csr_activity_logs row
     * PER CHANGED FIELD with {field, old, new} so the change is recoverable.
     *
     * Deliberately NOT synced: client_name (would rename the master for every
     * other order), client_brand (a ClientBrand selection, not free text),
     * email/links (the order does not carry them — edit on the Clients page).
     */
    private const CLIENT_SYNC_FIELD_MAP = [
        // order column        => client column
        'contact_number'   => 'contact_number',
        'street_address'   => 'street_address',
        'barangay_address' => 'barangay',
        'city_address'     => 'city',
        'province_address' => 'province',
        'postal_address'   => 'postal_code',
    ];

    private function syncClientFromOrder(Order $order, $actor = null): void
    {
        if (!$order->client_id) {
            return;
        }

        $client = Client::find($order->client_id);
        if (!$client) {
            return;
        }

        // Diff against the master so the audit trail only records REAL
        // changes (null and '' are treated as equal to avoid noise rows).
        $changes = [];
        foreach (self::CLIENT_SYNC_FIELD_MAP as $orderField => $clientField) {
            $new = $order->{$orderField};
            $old = $client->{$clientField};
            if ((string) ($new ?? '') !== (string) ($old ?? '')) {
                $changes[$clientField] = ['old' => $old, 'new' => $new];
            }
        }

        if ($changes === []) {
            return;
        }

        $this->clientService->update(
            $client->id,
            collect($changes)->map(fn ($c) => $c['new'])->all()
        );

        foreach ($changes as $field => $c) {
            $this->activityLogger->log(
                action:   'client.synced_from_order',
                summary:  "{$order->po_code}: client {$field} updated via order edit",
                subject:  $client,
                orderId:  $order->id,
                clientId: $client->id,
                data:     ['field' => $field, 'old' => $c['old'], 'new' => $c['new'], 'source' => 'order_update'],
                userId:   $actor?->getKey(),
            );
        }
    }
}
