<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Order — the production-ready record produced when a Quotation is
 * converted to an Order. The schema is intentionally a minimal,
 * quotation-derived shape: financials, FK-based apparel/pattern/print
 * method references, and JSON blobs that carry over from the source
 * quotation.
 *
 * Production-time details (courier, fabric, payment, files) live on
 * downstream tables (order_stages, order_designs, order_samples,
 * po_items, etc.) rather than here.
 */
class Order extends Model
{
    use HasFactory;

    protected $table = 'orders';

    protected $fillable = [
        // Linkage
        'quotation_id',
        'po_code',

        // Client
        'client_id',
        'client_name',
        'client_brand',

        // Apparel + print method (FK-based, like quotations)
        'apparel_type_id',
        'pattern_type_id',
        'apparel_neckline_id',
        'print_method_id',

        // Print details
        'shirt_color',
        'special_print',
        'print_area',

        // Misc descriptive
        'free_items',
        'notes',

        // Financials
        'discount_type',
        'discount_price',
        'discount_amount',
        'subtotal',
        'grand_total',

        // JSON carry-over from the quotation
        'item_config_json',
        'items_json',
        'addons_json',
        'breakdown_json',
        'print_parts_json',

        // Artifacts
        'qr_path',
        'barcode_path',

        // Status + Phase 1 workflow tracking
        'status',
        'workflow_status',
        'delayed_at',
        'current_stage_id',
    ];

    protected $casts = [
        'discount_price'   => 'decimal:2',
        'discount_amount'  => 'decimal:2',
        'subtotal'         => 'decimal:2',
        'grand_total'      => 'decimal:2',
        'item_config_json' => 'array',
        'items_json'       => 'array',
        'addons_json'      => 'array',
        'breakdown_json'   => 'array',
        'print_parts_json' => 'array',
        'delayed_at'       => 'datetime',
    ];

    // ── Relations ────────────────────────────────────────────────────────

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function quotation()
    {
        return $this->belongsTo(Quotation::class, 'quotation_id');
    }

    public function apparelType()
    {
        return $this->belongsTo(ApparelType::class, 'apparel_type_id');
    }

    public function patternType()
    {
        return $this->belongsTo(PatternType::class, 'pattern_type_id');
    }

    public function apparelNeckline()
    {
        return $this->belongsTo(ApparelNeckline::class, 'apparel_neckline_id');
    }

    public function printMethod()
    {
        return $this->belongsTo(PrintMethod::class, 'print_method_id');
    }

    public function items()
    {
        return $this->hasMany(PoItem::class);
    }

    public function samples()
    {
        return $this->hasMany(OrderSamples::class);
    }

    public function orderStages()
    {
        return $this->hasMany(OrderStage::class);
    }

    public function currentStage()
    {
        return $this->belongsTo(OrderStage::class, 'current_stage_id');
    }

    public function orderDesign()
    {
        return $this->hasOne(OrderDesign::class);
    }

    public function screenAssignment()
    {
        return $this->hasMany(ScreenAssignment::class);
    }

    public function screenChecking()
    {
        return $this->hasMany(ScreenChecking::class);
    }
}
