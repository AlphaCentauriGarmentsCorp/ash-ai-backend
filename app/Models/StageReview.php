<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * StageReview — one row in the CSR Review Hub ledger for an order stage.
 *
 * See the create_stage_reviews migration for the full design rationale. In
 * short: an immutable, append-only record of approve / reject / resubmit
 * actions taken against a stage's output. The CURRENT review state of a stage
 * is the LATEST row (highest id); helpers below express that.
 *
 * This layer is ADVISORY — it never mutates order_stages.status and never
 * moves the workflow pointer. The owning role fixes a rejection in parallel
 * with the order continuing to advance.
 */
class StageReview extends Model
{
    protected $table = 'stage_reviews';

    protected $fillable = [
        'order_id',
        'order_stage_id',
        'actor_user_id',
        'decision',
        'comment',
        'image_path',
    ];

    // ---- Decision constants ---------------------------------------------
    public const DECISION_APPROVE  = 'approve';
    public const DECISION_REJECT   = 'reject';
    public const DECISION_RESUBMIT = 'resubmit';

    public static function allDecisions(): array
    {
        return [
            self::DECISION_APPROVE,
            self::DECISION_REJECT,
            self::DECISION_RESUBMIT,
        ];
    }

    // ---- Relations -------------------------------------------------------
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(OrderStage::class, 'order_stage_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    // ---- State helpers ---------------------------------------------------
    public function isApprove(): bool
    {
        return $this->decision === self::DECISION_APPROVE;
    }

    public function isReject(): bool
    {
        return $this->decision === self::DECISION_REJECT;
    }

    public function isResubmit(): bool
    {
        return $this->decision === self::DECISION_RESUBMIT;
    }
}
