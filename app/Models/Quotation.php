<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Quotation extends Model
{
    protected $table = 'quotations';

    // ── Status constants (Phase 6-A adds STATUS_DRAFT for inquiry conversion)
    public const STATUS_DRAFT     = 'Draft';   // ⬅️ NEW (Phase 6-A C15)

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
}