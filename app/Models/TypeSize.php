<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TypeSize extends Model
{
    protected $table = 'type_sizes';
    protected $fillable = ['name', 'description'];
}
