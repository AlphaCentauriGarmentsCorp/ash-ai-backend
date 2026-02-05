<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\User;

class Client extends Model
{
    protected $table = 'clients';

    protected $fillable = [
        'name',
        'email',
        'contact_number',
        'address',
        'notes',
    ];

    public function brands()
    {
        return $this->hasMany(ClientBrand::class, 'client_id');
    }
}
