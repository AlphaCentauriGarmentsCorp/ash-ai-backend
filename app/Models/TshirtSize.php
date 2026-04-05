<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TshirtSize extends Model
{
    protected $table = 'tshirt_sizes';
    protected $fillable = [
        'name',
        'description',
    ];
}
