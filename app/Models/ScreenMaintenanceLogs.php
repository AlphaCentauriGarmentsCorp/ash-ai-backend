<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScreenMaintenanceLogs extends Model
{
    protected $table = 'screen_maintenance_logs';
    protected $fillable = [
        'screen_id',
        'maintenance_type',
        'notes',
        'materials_used',
        'assigned_to',
        'start_timestamp',
        'end_timestamp',
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
