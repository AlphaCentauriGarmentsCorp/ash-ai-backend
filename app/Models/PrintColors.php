<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrintColors extends Model
{
    protected $table = 'print_colors';
    protected $fillable = [
        'type_id',
        'color_count',
        'price',
    ];

    public function printType()
    {
        return $this->belongsTo(PrintTypes::class, 'type_id');
    }
}
