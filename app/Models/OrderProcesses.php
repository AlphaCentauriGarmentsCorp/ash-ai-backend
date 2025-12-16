<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Order;
use App\Models\User;

class OrderProcesses extends Model
{
    protected $table = 'order_processes';

    protected $fillable = [
        'po_id',
        'stage',
        'assigned_by',
        'assigned_to',
        'started_at',
        'completed_at',
        'deadline',
        'status',
        'notes',
    ];

    public function order(){return $this->belongsTo(Order::class, 'po_id');}
    public function assignedBy(){return $this->belongsTo(User::class, 'assigned_by');}
    public function assignedTo(){return $this->belongsTo(User::class, 'assigned_to');}
}
