<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrdersPayment extends Model
{
    
    protected $table = 'orders_payment';
    protected $fillable = [
        'po_id',
        'payment_type',
        'amount',
        'currency',
        'payment_method',
        'reference_number',
        'proof',
        'remarks',
        'verified_by',
        'verified_at',
        'status',
    ];

    // Relationships
    public function order()
    {
        return $this->belongsTo(Order::class, 'po_id');
    }

    public function verifiedBy()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}


