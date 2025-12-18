<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PoStatus extends Model
{
    protected $table = 'po_status';

    protected $fillable = [
        'po_id',
        'updated_by',
        'message',
    ];


}
