<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Client;

class ClientBrand extends Model
{
    //
    protected $table = 'client_brands';
    protected $fillable = [
        'client_id',
        'brand_name',
        'logo_url',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }
}
