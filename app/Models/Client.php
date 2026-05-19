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
        'method',
        'courier',
        'notes',
        // ── Phase 6-A: client communication links + internal notes
        'messenger_link',
        'facebook_link',
        'gc_link',
        'internal_notes',
    ];

    public function brands()
    {
        return $this->hasMany(ClientBrand::class, 'client_id');
    }

    public function orders()
    {
        return $this->hasMany(Order::class, 'client_id');
    }
}
