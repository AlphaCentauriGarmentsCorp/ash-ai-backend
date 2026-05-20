<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 7-B Bundle 1 — Reject reason lookup row.
 *
 * 7 canonical reasons seeded via RejectReasonSeeder. `is_fabric`
 * shortcut flag is read by NotificationService to decide whether to
 * cc the Cutter on a reject notification (per PDF §6 rule).
 */
class RejectReason extends Model
{
    protected $table = 'reject_reasons';

    protected $fillable = [
        'slug',
        'label',
        'is_fabric',
        'display_order',
        'active',
    ];

    protected $casts = [
        'is_fabric'     => 'boolean',
        'display_order' => 'integer',
        'active'        => 'boolean',
    ];

    /** All reject logs that cited this reason. */
    public function stageRejectLogs(): HasMany
    {
        return $this->hasMany(StageRejectLog::class, 'reject_reason_id');
    }
}
