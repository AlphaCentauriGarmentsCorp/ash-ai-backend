<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * In-app notification record.
 *
 * Lives in the `notifications` table created by Phase 2. Independent of
 * Laravel's built-in `Illuminate\Notifications\Notification` channel
 * abstraction – we wanted simple read/write control of the data shape.
 *
 * Common `type` values:
 *   stage.delayed              – stage was flagged as delayed
 *   stage.on_hold              – stage was put on hold
 *   stage.for_approval         – stage moved to for_approval (waits on approver)
 *   stage.assigned             – stage assigned to a user
 *   stage.in_progress          – stage moved to in_progress (your turn)
 *   order.completed            – the order is fully done
 *   quotation.approved         – quotation accepted by client
 *   quotation.rejected         – quotation rejected by client
 *   material_request.created   – Phase 3 hook
 *
 * The `data` column carries any context needed to render the notification
 * and to deep-link the user to the relevant page (order_id, stage_id, etc.).
 */
class Notification extends Model
{
    protected $fillable = [
        'user_id',
        'type',
        'title',
        'body',
        'data',
        'read_at',
    ];

    protected $casts = [
        'data'    => 'array',
        'read_at' => 'datetime',
    ];

    // ---- Relations ------------------------------------------------------

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ---- Scopes ---------------------------------------------------------

    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }

    public function scopeRead($query)
    {
        return $query->whereNotNull('read_at');
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    // ---- State helpers --------------------------------------------------

    public function isUnread(): bool
    {
        return $this->read_at === null;
    }

    public function markRead(): self
    {
        if ($this->isUnread()) {
            $this->forceFill(['read_at' => now()])->save();
        }
        return $this;
    }
}
