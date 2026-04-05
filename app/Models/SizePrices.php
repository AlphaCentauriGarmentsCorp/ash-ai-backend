<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SizePrices extends Model
{
    protected $table = 'size_prices';
    protected $fillable = [
        'shirt_id',
        'size_id',
        'price',
    ];

    public function shirt()
    {
        return $this->belongsTo(TshirtTypes::class, 'shirt_id');
    }

    public function size()
    {
        return $this->belongsTo(TshirtSize::class, 'size_id');
    }
}
