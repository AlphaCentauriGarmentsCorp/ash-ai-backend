<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 7-B Bundle 4a — Audit row for one SUBMIT COMPLETED action.
 *
 * Written by QaPackerSubmitService::submit() inside the atomic
 * transaction that also marks the stage complete + fans out
 * notifications.
 */
class QaPackerTaskCompletion extends Model
{
    protected $table = 'qa_packer_task_completions';

    protected $fillable = [
        'order_id',
        'order_stage_id',
        'submitted_by_user_id',
        'checklist_state_json',
        'final_photos_json',
        'reject_summary_json',
        'notes',
        'submitted_at',
    ];

    protected $casts = [
        'checklist_state_json' => 'array',
        'final_photos_json'    => 'array',
        'reject_summary_json'  => 'array',
        'submitted_at'         => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(OrderStage::class, 'order_stage_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }
}
