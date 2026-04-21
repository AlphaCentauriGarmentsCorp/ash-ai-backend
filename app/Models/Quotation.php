<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Quotation extends Model
{
    protected $table = 'quotations';

    protected $fillable = [
        'quotation_id',
        'user_id',
        'client_id',
        'client_name',
        'client_email',
        'client_facebook',
        'client_brand',
        'shirt_color',
        'apparel_neckline_id',
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
        'status',
    ];

    protected $casts = [
        'item_config_json' => 'array',
        'items_json'       => 'array',
        'addons_json'      => 'array',
        'breakdown_json'   => 'array',
        'print_parts_json' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function shareTokens()
    {
        return $this->hasMany(QuotationShareToken::class, 'quotation_id');
    }
}
