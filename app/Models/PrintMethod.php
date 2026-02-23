<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrintMethod extends Model
{
    protected $table = 'print_methods';
    protected $fillable = [
        'name',
        'description',
    ];
}
