<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 7-B Bundle 1 — One packing box within an order.
 *
 * QR-code-identified with a stable `ASH-PO-YYYY-NNNNNN-BOX-NN` string.
 * `contents_json` is an array of {size, sku, qty} entries describing
 * what was placed in this box.
 *
 * A box is "draft" while contents are being added (sealed_at is null)
 * and "sealed" once the packer marks it ready for QR-label print and
 * downstream pickup.
 */
class OrderPackingBox extends Model
{
    protected $table = 'order_packing_boxes';

    protected $fillable = [
        'order_id',
        'box_number',
        'qr_code',
        'contents_json',
        'weight_kg',
        'sealed_at',
        'sealed_by_user_id',
    ];

    protected $casts = [
        'box_number'    => 'integer',
        'contents_json' => 'array',
        'weight_kg'     => 'decimal:2',
        'sealed_at'     => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function sealedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sealed_by_user_id');
    }

    /** A box is sealed once sealed_at has been set. */
    public function isSealed(): bool
    {
        return $this->sealed_at !== null;
    }

    /** Total pieces in this box across all SKU/size entries. */
    public function totalPieces(): int
    {
        $contents = $this->contents_json;
        if (! is_array($contents)) {
            return 0;
        }

        $sum = 0;
        foreach ($contents as $entry) {
            if (is_array($entry) && isset($entry['qty']) && is_numeric($entry['qty'])) {
                $sum += (int) $entry['qty'];
            }
        }
        return $sum;
    }
}
