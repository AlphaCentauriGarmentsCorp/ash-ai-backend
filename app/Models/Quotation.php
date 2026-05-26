<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Quotation extends Model
{
    protected $table = 'quotations';

    // ── Status constants ─────────────────────────────────────────────────
    // STATUS_DRAFT was added in Phase 6-A (C15) for inquiry conversion.
    // Issue 12 adds the rest of the lifecycle vocabulary.
    public const STATUS_DRAFT     = 'Draft';     // spawned from an inquiry, not finalized
    public const STATUS_PENDING   = 'Pending';   // created directly (DB default), awaiting action
    public const STATUS_SENT      = 'Sent';      // PDF emailed to the client
    public const STATUS_APPROVED  = 'Approved';  // client approved the quote
    public const STATUS_CONVERTED = 'Converted'; // turned into an order (terminal)
    public const STATUS_REJECTED  = 'Rejected';  // client declined (reopenable → Draft)
    public const STATUS_EXPIRED   = 'Expired';   // lapsed (terminal)

    /**
     * Allowed status transitions (Issue 12 lifecycle state machine).
     *
     * Business rules confirmed with Josh:
     *   - Draft and Pending are equivalent "early" states (both may move forward).
     *   - Skipping straight to Converted is allowed (walk-in paid on the spot),
     *     so Converted is reachable from every non-terminal state.
     *   - Rejected is reopenable back to Draft; Expired is terminal.
     *   - Converted is terminal.
     *
     * @var array<string, string[]>
     */
    public const STATUS_TRANSITIONS = [
        self::STATUS_DRAFT     => [self::STATUS_SENT, self::STATUS_APPROVED, self::STATUS_CONVERTED, self::STATUS_REJECTED, self::STATUS_EXPIRED],
        self::STATUS_PENDING   => [self::STATUS_SENT, self::STATUS_APPROVED, self::STATUS_CONVERTED, self::STATUS_REJECTED, self::STATUS_EXPIRED],
        self::STATUS_SENT      => [self::STATUS_APPROVED, self::STATUS_CONVERTED, self::STATUS_REJECTED, self::STATUS_EXPIRED],
        self::STATUS_APPROVED  => [self::STATUS_CONVERTED, self::STATUS_REJECTED, self::STATUS_EXPIRED],
        self::STATUS_REJECTED  => [self::STATUS_DRAFT],   // reopenable
        self::STATUS_CONVERTED => [],                     // terminal
        self::STATUS_EXPIRED   => [],                     // terminal
    ];

    /**
     * Every valid status value.
     *
     * @return string[]
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_DRAFT,
            self::STATUS_PENDING,
            self::STATUS_SENT,
            self::STATUS_APPROVED,
            self::STATUS_CONVERTED,
            self::STATUS_REJECTED,
            self::STATUS_EXPIRED,
        ];
    }

    /**
     * Can this quotation legally move from its current status to $to?
     * Status comparison is case-insensitive (legacy rows may vary in case),
     * but $to is matched against the canonical constant set.
     */
    public function canTransitionTo(string $to): bool
    {
        $current = $this->normalizedStatus();
        $allowed = self::STATUS_TRANSITIONS[$current] ?? [];

        return in_array($to, $allowed, true);
    }

    /**
     * The current status normalized to a canonical constant. Falls back to
     * Pending (the DB default) if the stored value is empty/unrecognized, so
     * the state machine always has a defined starting point.
     */
    public function normalizedStatus(): string
    {
        $raw = trim((string) $this->status);
        foreach (self::statuses() as $status) {
            if (strcasecmp($raw, $status) === 0) {
                return $status;
            }
        }

        return self::STATUS_PENDING;
    }

    protected $fillable = [
        'quotation_id',
        'user_id',
        'client_id',
        'client_name',
        'client_email',
        'client_facebook',
        'client_brand',
        'apparel_type_id',
        'pattern_type_id',
        'shirt_color',
        'apparel_neckline_id',
        'print_method_id',
        'special_print',
        'print_area',
        'free_items',
        'notes',
        'subtotal',
        'discount_type',
        'discount_price',
        'discount_amount',
        'grand_total',
        'item_config_json',
        'items_json',
        'addons_json',
        'breakdown_json',
        'print_parts_json',
        'custom_pattern_image',
        // ── Issue 7: Brand Label + Care/Size Label spec + shared design upload
        'brand_label_json',
        'care_label_json',
        'label_design_path',
        'pdf_path',
        'status',
    ];

    protected $casts = [
        'print_method_id'  => 'integer',
        'item_config_json' => 'array',
        'items_json'       => 'array',
        'addons_json'      => 'array',
        'breakdown_json'   => 'array',
        'print_parts_json' => 'array',
        // ── Issue 7: label specs are read/written as arrays
        'brand_label_json' => 'array',
        'care_label_json'  => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function shareTokens()
    {
        return $this->hasMany(QuotationShareToken::class, 'quotation_id');
    }

    public function tickets()
    {
        return $this->hasMany(Ticket::class);
    }

    /**
     * Issue 12 — status transition history (immutable audit rows).
     */
    public function statusLogs()
    {
        return $this->hasMany(QuotationStatusLog::class, 'quotation_id')->latest('created_at');
    }
}