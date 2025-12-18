<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Order;

class PoItems extends Model
{
   protected $table = 'po_items';

    protected $fillable = [
        'po_id',
        'design_code',
        'color',
        'size',
        'quantity_ordered',
        'variant_code',
        'variant_barcode',
        'variant_qr_code',
    ];
    
    public function order()
    {
         return $this->belongsTo(Order::class, 'po_id');
    }
}


