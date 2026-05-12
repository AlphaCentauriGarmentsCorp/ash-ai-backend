<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StageSampleUpload extends Model
{
    protected $table = 'stage_sample_uploads';

    public const STATUS_PENDING      = 'pending';
    public const STATUS_FOR_APPROVAL = 'for_approval';
    public const STATUS_APPROVED     = 'approved';
    public const STATUS_REJECTED     = 'rejected';

    protected $fillable = [
        'order_id',
        'order_stage_id',
        'uploaded_by_user_id',
        'photo_front_path',
        'photo_back_path',
        'remarks',
        'sample_status',
        'completed_at',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
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

    public function isApproved(): bool
    {
        return $this->sample_status === self::STATUS_APPROVED;
    }

    public function isForApproval(): bool
    {
        return $this->sample_status === self::STATUS_FOR_APPROVAL;
    }
}
