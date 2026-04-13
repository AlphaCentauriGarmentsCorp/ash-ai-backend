<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CourierList extends Model
{
    protected $table = 'courier_list';
    protected $fillable = [
        'name',
        'description',
    ];
    public function shippingMethods()
{
    return $this->hasMany(ShippingMethod::class, 'courier_id');
}
}