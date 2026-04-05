<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AddonCategories extends Model
{
    protected $table = 'addon_categories';
    protected $fillable = [
        'name',
        'description',
    ];
}
