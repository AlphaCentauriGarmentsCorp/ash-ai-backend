<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\CourierList;

class ShippingMethod extends Model
{
    protected $table = 'shipping_methods';

    protected $fillable = [
        'courier_id',
        'name',
        'description',
    ];

    public function courier()
    {
        return $this->belongsTo(CourierList::class, 'courier_id');
    }
}