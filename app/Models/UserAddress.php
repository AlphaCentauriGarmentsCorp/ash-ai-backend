<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserAddress extends Model
{
    protected $table = 'user_address';
    protected $fillable = [
        'user_id',
        'type',
        'street',
        'province',
        'brangay',
        'city',
        'postal',
        'country',
    ];


    public function user()
    {
        return $this->belongsTo(User::class);
    }

    
}
