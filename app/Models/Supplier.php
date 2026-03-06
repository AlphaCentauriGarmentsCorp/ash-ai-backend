<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    protected $table = 'suppliers';
    protected $fillable = [
        'name',
        'contact_person',
        'contact_number',
        'email',
        'address',
        'notes',
    ];
}
