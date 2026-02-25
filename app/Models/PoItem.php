<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PoItem extends Model
{
    use HasFactory;

    protected $table = 'po_items';

    protected $fillable = [
        'order_id',
        'sku',
        'design_code',
        'color',
        'size',
        'quantity',
        'qr_path',
        'barcode_path',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
