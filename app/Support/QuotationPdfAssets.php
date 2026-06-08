<?php

namespace App\Support;

use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Quotation;
use Illuminate\Support\Facades\Storage;

/**
 * Change 5 — image assets for the quotation PDF (pdf.blade.php).
 *
 * Builds the view data DomPDF needs, resolving every image to a base64 data
 * URI. This is required because DomPDF here runs with enable_remote = false,
 * so it cannot fetch images from /storage/... URLs — they must be embedded.
 *
 * Three asset groups, each omitted cleanly when empty:
 *   - mockups        per-placement print images (print_parts_json)
 *   - designAssets   the shared label design + custom pattern image
 *   - paymentProofs  VERIFIED payment proofs from the converted order
 *                    (a quotation has no payment of its own; we reach forward
 *                    via Order.quotation_id to the order's verified payments)
 *
 * The blade stays dumb: it iterates the arrays this returns. All disk I/O,
 * base64 encoding, and the order/payment lookup live here so they're testable
 * and never blow up PDF generation (every resolve is guarded).
 */
class QuotationPdfAssets
{
    /** Human labels for the order payment phases (sample → 60% → 40%). */
    private const PAYMENT_LABELS = [
        OrderPayment::TYPE_SAMPLE       => 'Sample Payment',
        OrderPayment::TYPE_DOWN_PAYMENT => 'Down Payment (60%)',
        OrderPayment::TYPE_BALANCE      => 'Balance (40%)',
        OrderPayment::TYPE_FULL         => 'Full Payment',
    ];

    /**
     * The full view payload for Pdf::loadView('pdf', ...).
     */
    public static function for(Quotation $quotation): array
    {
        return [
            'quotation'     => $quotation,
            'mockups'       => self::mockups($quotation),
            'designAssets'  => self::designAssets($quotation),
            'paymentProofs' => self::paymentProofs($quotation),
        ];
    }

    /**
     * Per-placement mockup images from print_parts_json. Each entry:
     * ['label' => 'Front', 'meta' => '4 colors', 'src' => 'data:...'].
     */
    private static function mockups(Quotation $quotation): array
    {
        $parts = is_array($quotation->print_parts_json)
            ? $quotation->print_parts_json
            : (json_decode((string) $quotation->print_parts_json, true) ?: []);

        $out = [];
        foreach ($parts as $part) {
            if (! is_array($part)) {
                continue;
            }

            $raw = $part['image_link']
                ?? $part['image_url']
                ?? $part['image_path']
                ?? $part['image']
                ?? null;

            $src = self::resolve($raw);
            if (! $src) {
                continue;
            }

            $colors = $part['num_colors'] ?? $part['color_count'] ?? null;
            $meta = $colors !== null
                ? ((int) $colors) . ' color' . (((int) $colors) === 1 ? '' : 's')
                : null;

            $out[] = [
                'label' => $part['part'] ?? 'Placement',
                'meta'  => $meta,
                'src'   => $src,
            ];
        }

        return $out;
    }

    /**
     * The shared label-design upload + the custom pattern image.
     */
    private static function designAssets(Quotation $quotation): array
    {
        $candidates = [
            ['Label Design',   $quotation->label_design_path],
            ['Custom Pattern', $quotation->custom_pattern_image],
        ];

        $out = [];
        foreach ($candidates as [$label, $raw]) {
            $src = self::resolve($raw);
            if ($src) {
                $out[] = ['label' => $label, 'src' => $src];
            }
        }

        return $out;
    }

    /**
     * Verified payment proofs from the order this quotation was converted into.
     * Returns [] when the quotation hasn't been converted, the order has no
     * verified payments, or none carry a proof image. Shows ALL verified ones.
     */
    private static function paymentProofs(Quotation $quotation): array
    {
        if (! $quotation->id) {
            return [];
        }

        $order = Order::where('quotation_id', $quotation->id)->latest('id')->first();
        if (! $order) {
            return [];
        }

        $payments = $order->payments()
            ->where('status', OrderPayment::STATUS_VERIFIED)
            ->whereNotNull('proof_path')
            ->orderBy('id')
            ->get();

        $out = [];
        foreach ($payments as $payment) {
            $src = self::resolve($payment->proof_path);
            if (! $src) {
                continue;
            }

            $out[] = [
                'label'      => self::PAYMENT_LABELS[$payment->payment_type]
                    ?? ucwords(str_replace('_', ' ', (string) $payment->payment_type)),
                'amount'     => $payment->amount,
                'ref'        => $payment->reference_number,
                'verifiedAt' => $payment->verified_at,
                'src'        => $src,
            ];
        }

        return $out;
    }

    /**
     * Resolve a stored image reference to a base64 data URI DomPDF can embed.
     * Returns null (so the caller omits it) for empty/remote/missing/unreadable
     * inputs. Data URIs pass through unchanged. Never throws.
     */
    public static function resolve(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }

        // Already embeddable.
        if (str_starts_with($raw, 'data:')) {
            return $raw;
        }

        // Remote URLs can't be embedded (enable_remote = false) — skip.
        if (str_starts_with($raw, 'http://') || str_starts_with($raw, 'https://')) {
            return null;
        }

        // Normalise to a path relative to the `public` disk.
        $rel = ltrim(preg_replace('#^/?storage/#', '', $raw), '/');
        if ($rel === '') {
            return null;
        }

        try {
            $disk = Storage::disk('public');
            if (! $disk->exists($rel)) {
                return null;
            }

            $bytes = $disk->get($rel);
            if ($bytes === null || $bytes === '') {
                return null;
            }

            $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
            $mime = match ($ext) {
                'png'  => 'image/png',
                'gif'  => 'image/gif',
                'webp' => 'image/webp',
                'svg'  => 'image/svg+xml',
                default => 'image/jpeg',
            };

            return 'data:' . $mime . ';base64,' . base64_encode($bytes);
        } catch (\Throwable $e) {
            // An unreadable image must never break PDF generation.
            return null;
        }
    }
}
