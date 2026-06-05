<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderPackingBox;
use App\Models\OrderStage;
use App\Models\StageAuditLog;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase 7-B Bundle 4a — Packing-box QR code lifecycle.
 *
 * Owns three responsibilities:
 *   1. Generate the stable QR string ASH-PO-YYYY-NNNNNN-BOX-NN
 *      from the order's po_code and the box's sequential number
 *   2. Render the QR + label PDF for thermal-printer-friendly output
 *   3. Auto-create the first box for an order's packing stage
 *      (per the 4a "one box per order" decision; multi-box support
 *      already in the schema for future use)
 *
 * Matches the existing endroid/qr-code usage pattern in OrderService.
 */
class BoxQrCodeService
{
    /**
     * Auto-create box #1 for an order, idempotent.
     *
     * Called when the packer first opens the packing portal — if no
     * box exists yet, we create one with the full order quantity
     * defaulted from items_json. If a box already exists, we return it.
     *
     * @throws ValidationException if order doesn't exist
     */
    public function ensureFirstBox(int $orderId, User $user): OrderPackingBox
    {
        $order = Order::find($orderId);
        if (! $order) {
            throw ValidationException::withMessages([
                'order_id' => 'Order not found.',
            ]);
        }

        // Idempotent: if any box exists for this order, return box #1.
        $existing = OrderPackingBox::where('order_id', $orderId)
            ->orderBy('box_number')
            ->first();
        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($order, $user) {
            $qrCode = $this->generateCode($order, 1);
            $contents = $this->defaultContentsFromOrder($order);

            return OrderPackingBox::create([
                'order_id'          => $order->id,
                'box_number'        => 1,
                'qr_code'           => $qrCode,
                'contents_json'     => $contents,
                'weight_kg'         => null,
                'sealed_at'         => null,
                'sealed_by_user_id' => null,
            ]);
        });
    }

    /**
     * Seal a box (lock its contents and mark it ready for label print).
     */
    public function seal(int $boxId, User $user): OrderPackingBox
    {
        $box = OrderPackingBox::find($boxId);
        if (! $box) {
            throw ValidationException::withMessages([
                'id' => 'Box not found.',
            ]);
        }

        if ($box->isSealed()) {
            throw ValidationException::withMessages([
                'id' => 'Box is already sealed.',
            ]);
        }

        $box->update([
            'sealed_at'         => now(),
            'sealed_by_user_id' => $user->id,
        ]);

        return $box->fresh();
    }

    /**
     * Un-seal a box so its contents can be edited again.
     *
     * Bundle 4a-2 — packer self-service. Guard rules:
     *   - Box must currently be sealed.
     *   - The order's packing stage must NOT already be completed
     *     (i.e., the task hasn't been submitted yet). Once submitted,
     *     the box is permanently locked; a manager must intervene at
     *     the data level.
     *   - Permission to act is enforced at the route layer
     *     (portal.qa-packer). Any holder may unseal — accountability
     *     is preserved via the audit log entry rather than via
     *     per-user ownership.
     *
     * @throws ValidationException
     */
    public function unseal(int $boxId, User $user): OrderPackingBox
    {
        $box = OrderPackingBox::find($boxId);
        if (! $box) {
            throw ValidationException::withMessages([
                'id' => 'Box not found.',
            ]);
        }

        if (! $box->isSealed()) {
            throw ValidationException::withMessages([
                'id' => 'Box is not sealed.',
            ]);
        }

        // "Before submit" guard: find the order's (mass) packing stage and
        // ensure it isn't completed yet.
        $packingStage = OrderStage::where('order_id', $box->order_id)
            ->where('stage', 'mass_packing')
            ->first();

        if ($packingStage && $packingStage->status === OrderStage::STATUS_COMPLETED) {
            throw ValidationException::withMessages([
                'id' => 'Cannot unseal — packing has already been submitted.',
            ]);
        }

        return DB::transaction(function () use ($box, $user, $packingStage) {
            $box->update([
                'sealed_at'         => null,
                'sealed_by_user_id' => null,
            ]);

            // Accountability: log who unsealed it and when. We attach
            // the entry to the packing stage if one exists.
            if ($packingStage) {
                StageAuditLog::create([
                    'order_id'       => $box->order_id,
                    'order_stage_id' => $packingStage->id,
                    'user_id'        => $user->id,
                    'action'         => 'box_unsealed',
                    'from_status'    => null,
                    'to_status'      => null,
                    'notes'          => "Box {$box->qr_code} unsealed",
                    'created_at'     => now(),
                ]);
            }

            return $box->fresh();
        });
    }

    /**
     * Generate the canonical QR code string for a box.
     *
     * Format: ASH-PO-YYYY-NNNNNN-BOX-NN
     *
     * Falls back to the order id if po_code is missing (shouldn't
     * happen in production but defensive).
     */
    public function generateCode(Order $order, int $boxNumber): string
    {
        $year = $order->created_at?->format('Y') ?? date('Y');

        // Prefer the human PO code; otherwise derive from id.
        $coreId = $order->po_code
            ?: sprintf('ASH-%s-%06d', $year, $order->id);

        return sprintf('%s-BOX-%02d', $coreId, $boxNumber);
    }

    /**
     * Render the QR label as a PDF binary suitable for thermal-printer
     * download (4×6" or 2×4" depending on the rendered view).
     *
     * Returns raw PDF bytes. Caller decides whether to stream or save.
     */
    public function renderLabelPdf(OrderPackingBox $box): string
    {
        $order = $box->order ?? Order::find($box->order_id);
        if (! $order) {
            throw ValidationException::withMessages([
                'order_id' => 'Order not found for box.',
            ]);
        }

        // Generate the QR PNG as base64 so the Blade view can inline it
        // without hitting storage (works in print preview too).
        $qrPngBase64 = base64_encode($this->renderQrPng($box->qr_code));

        $pdf = Pdf::loadView('qa-packer.box-label', [
            'box'           => $box,
            'order'         => $order,
            'qr_data_uri'   => 'data:image/png;base64,' . $qrPngBase64,
            'total_pieces'  => $box->totalPieces(),
        ])
            ->setPaper([0, 0, 288, 432], 'portrait'); // 4×6 inches @ 72 dpi

        return $pdf->output();
    }

    /**
     * Render the QR PNG bytes for a code string.
     *
     * Uses the endroid/qr-code library that's already a project
     * dependency. Same builder pattern as OrderService.
     */
    public function renderQrPng(string $code): string
    {
        return Builder::create()
            ->writer(new PngWriter())
            ->data($code)
            ->encoding(new Encoding('UTF-8'))
            ->size(300)
            ->margin(8)
            ->build()
            ->getString();
    }

    // ── Helpers ─────────────────────────────────────────────────

    /**
     * Build a sensible default contents_json from the order's items_json.
     *
     * If items_json has size/quantity breakdowns, mirror them. Otherwise
     * fall back to a single entry with the full order quantity.
     */
    protected function defaultContentsFromOrder(Order $order): array
    {
        $raw = $order->items_json;
        $items = is_array($raw) ? $raw : (is_string($raw) ? (json_decode($raw, true) ?: []) : []);

        $contents = [];
        foreach ($items as $item) {
            if (! is_array($item)) continue;
            $size = $item['size'] ?? null;
            $qty  = isset($item['quantity']) ? (int) $item['quantity'] : 0;
            if ($qty > 0) {
                $contents[] = [
                    'size' => $size,
                    'sku'  => $item['sku'] ?? null,
                    'qty'  => $qty,
                ];
            }
        }

        return $contents;
    }
}
