<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 5-H — Versioned design file for an order.
 *
 * One row per uploaded file. Multiple rows can share the same kind for
 * an order: each upload bumps `version`, and the highest-version row
 * per (order_id, kind) is flagged with is_latest=true.
 */
class OrderDesignFile extends Model
{
    protected $table = 'order_design_files';

    public const KIND_FRONT_DESIGN     = 'front_design';
    public const KIND_BACK_DESIGN      = 'back_design';
    public const KIND_FRONT_MOCKUP     = 'front_mockup';
    public const KIND_BACK_MOCKUP      = 'back_mockup';
    public const KIND_COLOR_SEPARATION = 'color_separation';
    public const KIND_OTHER            = 'other';

    public const KINDS = [
        self::KIND_FRONT_DESIGN,
        self::KIND_BACK_DESIGN,
        self::KIND_FRONT_MOCKUP,
        self::KIND_BACK_MOCKUP,
        self::KIND_COLOR_SEPARATION,
        self::KIND_OTHER,
    ];

    protected $fillable = [
        'order_id',
        'order_design_id',
        'kind',
        'version',
        'file_path',
        'original_name',
        'mime_type',
        'size_bytes',
        'is_latest',
        'uploaded_by_user_id',
        'notes',
    ];

    protected $casts = [
        'version'    => 'integer',
        'size_bytes' => 'integer',
        'is_latest'  => 'boolean',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function design(): BelongsTo
    {
        return $this->belongsTo(OrderDesign::class, 'order_design_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
