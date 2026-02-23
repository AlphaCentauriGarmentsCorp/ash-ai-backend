<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SizeLabel extends Model
{
    protected $table = 'size_labels';
    protected $fillable = [
        'name',
        'description',
    ];
}
