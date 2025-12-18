<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Order;
use App\Models\User;


class PoStatus extends Model
{
    protected $table = 'po_statuses';

    protected $fillable = [
        'po_id',
        'updated_by',
        'message',
    ];

    public function order()
    {
         return $this->belongsTo(Order::class, 'po_id');
    }    
    public function user()
    {
         return $this->belongsTo(User::class, 'updated_by');
    }
}
