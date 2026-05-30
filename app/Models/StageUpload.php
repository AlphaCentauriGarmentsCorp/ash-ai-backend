<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * StageUpload — one proof-of-work file attached to an order stage.
 *
 * Generic, stage-agnostic attachment (see the create_stage_uploads migration).
 * One row per file; `category` tags what kind of proof it is.
 */
class StageUpload extends Model
{
    protected $table = 'stage_uploads';

    protected $fillable = [
        'order_id',
        'order_stage_id',
        'uploaded_by_user_id',
        'category',
        'file_path',
        'original_name',
        'mime_type',
        'size_bytes',
        'notes',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(OrderStage::class, 'order_stage_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    /**
     * Best-effort check of whether this attachment is an image (for thumbnail
     * rendering in the Review Hub).
     */
    public function isImage(): bool
    {
        return is_string($this->mime_type)
            && str_starts_with($this->mime_type, 'image/');
    }
}
