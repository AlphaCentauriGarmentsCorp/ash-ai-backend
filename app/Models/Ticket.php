<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Ticket extends Model
{
    protected $table = 'tickets';

    protected $fillable = [
        'request_type',
        'quotation_id',
        'order_id',
        'from_role',
        'to_role',
        'message',
        'status',
        'date_created',
        'attachments',
    ];

    protected $casts = [
        'attachments' => 'array',
        'date_created' => 'datetime',
    ];
}