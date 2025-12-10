<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TypeSize extends Model
{
    protected $table = 'type_size';
    protected $fillable = ['name', 'description'];
}
