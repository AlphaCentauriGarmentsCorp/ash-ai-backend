<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * CsrActivityLog — append-only CSR audit log.
 *
 * Rows are immutable once written. There is no `updated_at`,
 * no Eloquent timestamps in the standard sense — only `created_at`.
 *
 * Use CsrActivityLogger service (NOT this model directly) to write
 * new rows. The service enforces the convention that every row
 * gets user_id, action, and either subject_type+id OR order_id/client_id.
 */
class CsrActivityLog extends Model
{
    protected $table = 'csr_activity_logs';

    // Only created_at — no updated_at on append-only audit
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'action',
        'subject_type',
        'subject_id',
        'order_id',
        'client_id',
        'summary',
        'data',
        'created_at',
    ];

    protected $casts = [
        'data'       => 'array',
        'created_at' => 'datetime',
    ];

    // ── Relations ────────────────────────────────────────────────────────

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    /**
     * Polymorphic subject — Inquiry / Order / OrderPayment / ClientApproval / etc.
     */
    public function subject()
    {
        return $this->morphTo();
    }
}
