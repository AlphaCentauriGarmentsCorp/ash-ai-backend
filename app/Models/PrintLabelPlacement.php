<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrintLabelPlacement extends Model
{
    protected $table = 'print_label_placements';
    protected $fillable = [
        'name',
        'description',
    ];
}
