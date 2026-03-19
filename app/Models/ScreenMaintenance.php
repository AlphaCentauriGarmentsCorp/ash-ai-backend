<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScreenMaintenance extends Model
{
    protected $table = 'screen_maintenance';
    protected $fillable = [
        'screen_id',
        'maintenance_type',
        'description',
        'assigned_to',
        'start_timestamp',
        'end_timestamp',
        'status',
    ];

    protected $casts = [
        'start_timestamp' => 'datetime',
        'end_timestamp'   => 'datetime',
    ];

    public function screen()
    {
        return $this->belongsTo(Screens::class, 'screen_id');
    }

    public function employee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
