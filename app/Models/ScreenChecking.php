<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScreenChecking extends Model
{

    protected $table = 'screen_checkings';
    protected $fillable = [
        'order_id',
        'status',
        'verified_by',
        'verification_date',
    ];

    protected $casts = [
        'verification_date' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function items()
    {
        return $this->hasMany(ScreenCheckingItem::class);
    }

    public function verifier()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
