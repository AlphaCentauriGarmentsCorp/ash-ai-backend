<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'po_number',
        'client_id',
        'brand_id',
        'channel',
        'order_type',
        'design_name',
        'type_fabric',
        'type_size',
        'type_garment',
        'type_printing_method',
        'design_files',
        'artist_filename',
        'mockup_url',
        'mockup_images',
        'mockup_notes',
        'print_location',
        'total_quantity',
        'size_breakdown',
        'target_date',
        'instruction_files',
        'insturction_notes',
        'unit_price',
        'deposit_percentage',
        'payment_terms',
        'currency',
        'status'
    ];
}
