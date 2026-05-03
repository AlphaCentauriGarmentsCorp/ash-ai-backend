<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Pantone extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'hexcolor', 'pantone_code',
    ];
}