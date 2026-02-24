<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdditionalOption extends Model
{
    protected $table = 'additional_options';
    protected $fillable = [
        'name',
        'description',
    ];
}
