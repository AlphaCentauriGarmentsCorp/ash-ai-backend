<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Addons extends Model
{
    protected $table = 'addons';
    protected $fillable = [
        'category_id',
        'name',
        'price_type',
        'price',
        'description',
    ];

    public function category()
    {
        return $this->belongsTo(AddonCategories::class, 'category_id');
    }
}
