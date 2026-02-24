<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Freebie extends Model
{
    protected $table = 'freebies';
    protected $fillable = [
        'name',
        'description',
    ];
}
