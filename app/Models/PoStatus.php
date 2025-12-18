<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Order;


class PoStatus extends Model
{
    protected $table = 'po_status';

    protected $fillable = [
        'po_id',
        'updated_by',
        'message',
    ];

    public function order()
    {
         return $this->belongsTo(Order::class, 'po_id');
    }
}
