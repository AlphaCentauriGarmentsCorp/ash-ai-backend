<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    protected $table = 'clients';

    protected $fillable = [
        'user_id',
        'company_name',
        'client_name',
        'email',
        'contact',
        'street_address',
        'city',
        'province',
        'postal',
        'country',
        'status',
    ];
}
