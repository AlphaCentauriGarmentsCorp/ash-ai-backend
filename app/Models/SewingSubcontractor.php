<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SewingSubcontractor extends Model
{
    protected $table = 'sewing_subcontractors';
    protected $fillable = [
        'name',
        'address',
        'rate_per_pcs',
        'contact_number',
        'email',
    ];
}
