<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Order extends Model
{
    use HasFactory;

    protected $table = 'orders';

    protected $fillable = [
        'po_code',
        'quotation_id',

        // Client
        'client_id',
        'client_name',
        'client_brand',

        // Apparel config IDs (from quotation)
        'apparel_type_id',
        'pattern_type_id',
        'apparel_neckline_id',
        'print_method_id',

        // Shirt / Print details
        'shirt_color',
        'special_print',
        'print_area',
        'free_items',
        'notes',

        // Pricing & discount
        'discount_type',
        'discount_price',
        'discount_amount',
        'subtotal',
        'grand_total',

        // JSON blobs (carried from quotation)
        'item_config_json',
        'items_json',
        'addons_json',
        'breakdown_json',
        'print_parts_json',

        // QR / Barcode
        'qr_path',
        'barcode_path',

        'status',
    ];

    protected $casts = [
        'item_config_json' => 'array',
        'items_json'       => 'array',
        'addons_json'      => 'array',
        'breakdown_json'   => 'array',
        'print_parts_json' => 'array',
        'subtotal'         => 'decimal:2',
        'discount_price'   => 'decimal:2',
        'discount_amount'  => 'decimal:2',
        'grand_total'      => 'decimal:2',
    ];

    // ── Relationships ──────────────────────────────────────────────────────────

    public function quotation()
    {
        return $this->belongsTo(Quotation::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function apparelType()
    {
        return $this->belongsTo(ApparelType::class);
    }

    public function patternType()
    {
        return $this->belongsTo(PatternType::class);
    }

    public function printMethod()
    {
        return $this->belongsTo(PrintMethod::class);
    }

    public function apparelNeckline()
    {
        return $this->belongsTo(ApparelNeckline::class);
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

    public function tickets()
    {
        return $this->hasMany(Ticket::class);
    }
}