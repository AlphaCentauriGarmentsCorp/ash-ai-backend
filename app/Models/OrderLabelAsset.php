<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 5-H — Per-order label / tag asset.
 *
 * Three kinds:
 *   - main_label  (neck label)
 *   - size_label
 *   - hangtag     (etiketa)
 *
 * Unique on (order_id, kind) — see migration. Use upsert when writing.
 */
class OrderLabelAsset extends Model
{
    protected $table = 'order_label_assets';

    public const KIND_MAIN_LABEL = 'main_label';
    public const KIND_SIZE_LABEL = 'size_label';
    public const KIND_HANGTAG    = 'hangtag';

    public const KINDS = [
        self::KIND_MAIN_LABEL,
        self::KIND_SIZE_LABEL,
        self::KIND_HANGTAG,
    ];

    public const PROCESS_SILKSCREEN = 'silkscreen';
    public const PROCESS_DIGITAL    = 'digital';
    public const PROCESS_EMBROIDERY = 'embroidery';
    public const PROCESS_DTF        = 'dtf';
    public const PROCESS_OTHER      = 'other';

    public const PRINTING_PROCESSES = [
        self::PROCESS_SILKSCREEN,
        self::PROCESS_DIGITAL,
        self::PROCESS_EMBROIDERY,
        self::PROCESS_DTF,
        self::PROCESS_OTHER,
    ];

    protected $fillable = [
        'order_id',
        'kind',
        'file_path',
        'original_name',
        'mime_type',
        'size_bytes',
        'width_in',
        'height_in',
        'printing_process',
        'color_count',
        'background_color',
        'material',
        'notes',
        'uploaded_by_user_id',
    ];

    protected $casts = [
        'size_bytes'  => 'integer',
        'width_in'    => 'decimal:2',
        'height_in'   => 'decimal:2',
        'color_count' => 'integer',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
