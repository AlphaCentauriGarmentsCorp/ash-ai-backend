<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Inquiry — pre-quotation lead.
 *
 * Lifecycle:
 *   new → contacted → quoted → converted (terminal) | lost (terminal)
 *
 * The `converted` status is set by InquiryService::convertToQuotation()
 * which also populates the `quotation_id` back-reference.
 *
 * `client_id` is nullable — anonymous walk-in inquiries are valid;
 * `client_name` is always required.
 */
class Inquiry extends Model
{
    protected $table = 'inquiries';

    protected $fillable = [
        'inquiry_code',
        'client_id',
        'client_name',
        'client_email',
        'client_contact',
        'brand_name',
        'source',
        'messenger_link',
        'facebook_link',
        'gc_link',
        'product_interest',
        'status',
        'assigned_csr_user_id',
        'quotation_id',
        'internal_notes',
    ];

    // Status constants — used by InquiryService + dashboard filters
    public const STATUS_NEW       = 'new';
    public const STATUS_CONTACTED = 'contacted';
    public const STATUS_QUOTED    = 'quoted';
    public const STATUS_CONVERTED = 'converted';
    public const STATUS_LOST      = 'lost';

    public const STATUSES = [
        self::STATUS_NEW,
        self::STATUS_CONTACTED,
        self::STATUS_QUOTED,
        self::STATUS_CONVERTED,
        self::STATUS_LOST,
    ];

    // ── Relations ────────────────────────────────────────────────────────

    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function assignedCsr()
    {
        return $this->belongsTo(User::class, 'assigned_csr_user_id');
    }

    public function quotation()
    {
        return $this->belongsTo(Quotation::class, 'quotation_id');
    }
}
