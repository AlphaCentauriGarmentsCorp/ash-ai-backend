<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ClientApproval — generic client approval event.
 *
 * Tracks any approval CSR shepherds through the workflow:
 * quotation, design, mockup, sample, production_change, delivery.
 *
 * Lifecycle:
 *   waiting → approved | revision_requested | rejected
 *
 * `revision_requested` is a non-terminal status — once CSR uploads
 * a new mockup/sample, a NEW ClientApproval row is created. The
 * old `revision_requested` row stays for audit history.
 */
class ClientApproval extends Model
{
    protected $table = 'client_approvals';

    protected $fillable = [
        'order_id',
        'kind',
        'status',
        'requested_at',
        'responded_at',
        'screenshot_path',
        'client_response_notes',
        'internal_notes',
        'requested_by_user_id',
        'recorded_by_user_id',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'responded_at' => 'datetime',
    ];

    // Kind constants
    public const KIND_QUOTATION         = 'quotation';
    public const KIND_DESIGN            = 'design';
    public const KIND_MOCKUP            = 'mockup';
    public const KIND_SAMPLE            = 'sample';
    public const KIND_PRODUCTION_CHANGE = 'production_change';
    public const KIND_DELIVERY          = 'delivery';

    public const KINDS = [
        self::KIND_QUOTATION,
        self::KIND_DESIGN,
        self::KIND_MOCKUP,
        self::KIND_SAMPLE,
        self::KIND_PRODUCTION_CHANGE,
        self::KIND_DELIVERY,
    ];

    // Status constants
    public const STATUS_WAITING            = 'waiting';
    public const STATUS_APPROVED           = 'approved';
    public const STATUS_REVISION_REQUESTED = 'revision_requested';
    public const STATUS_REJECTED           = 'rejected';

    public const STATUSES = [
        self::STATUS_WAITING,
        self::STATUS_APPROVED,
        self::STATUS_REVISION_REQUESTED,
        self::STATUS_REJECTED,
    ];

    // ── Relations ────────────────────────────────────────────────────────

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
