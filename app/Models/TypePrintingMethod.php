<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TypePrintingMethod extends Model
{
    protected $table = 'type_printing_methods';

    protected $fillable = [
        'name',
        'description',
        'minimum',
    ];
}
