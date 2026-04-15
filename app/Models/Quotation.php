<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Quotation extends Model
{
    protected $table = 'quotations';

    protected $fillable = [
        'quotation_id',
        'user_id',
        'client_name',
        'client_email',
        'client_brand',
        'shirt_color',
        'free_items',
        'notes',
        'subtotal',
        'discount_type',
        'discount_price',
        'grand_total',
        'items_json',
        'addons_json',
        'breakdown_json',
        'print_parts_json',
        'status',
    ];

    protected $casts = [
        'items_json' => 'array',
        'addons_json' => 'array',
        'breakdown_json' => 'array',
        'print_parts_json' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}