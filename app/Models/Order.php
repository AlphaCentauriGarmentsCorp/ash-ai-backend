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
        'client_id',
        'client_brand',
        'deadline',
        'priority',
        'brand',

        'courier',
        'method',
        'receiver_name',
        'receiver_contact',
        'address',

        'design_name',
        'apparel_type',
        'pattern_type',
        'service_type',
        'print_method',
        'print_service',
        'size_label',
        'print_label_placement',

        'fabric_type',
        'fabric_supplier',
        'fabric_color',
        'thread_color',
        'ribbing_color',

        'placement_measurements',
        'notes',
        'options',

        'freebie_items',
        'freebie_color',
        'freebie_others',

        'payment_method',
        'payment_plan',
        'total_price',
        'average_unit_price',
        'total_quantity',
        'deposit',

        'design_files',
        'design_mockup',
        'size_label_files',
        'freebies_files',

        'qr_path',
        'barcode_path',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function items()
    {
        return $this->hasMany(PoItem::class);
    }

    protected $casts = [
        'deadline' => 'date',
        'total_price' => 'decimal:2',
        'average_unit_price' => 'decimal:2',
    ];
}
