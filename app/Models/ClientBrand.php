<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClientBrand extends Model
{
    //
    protected $table = 'client_brands';
    protected $fillable = [
        'client_id',
        'brand_name',
        'logo_url',
        'notes',
    ];
}
