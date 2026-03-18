<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScreenCheckingItem extends Model
{
    protected $table = 'screen_checking_items';
    protected $fillable = [
        'screen_checking_id',
        'placement_id',
        'screen_id',
        'color_index',
        'pantone',
        'clean',
        'no_damage',
        'emulsion_ok',
        'verified',
        'issues',
        'verified_at',
    ];

    protected $casts = [
        'clean' => 'boolean',
        'no_damage' => 'boolean',
        'emulsion_ok' => 'boolean',
        'verified' => 'boolean',
        'verified_at' => 'datetime',
    ];


    public function checking()
    {
        return $this->belongsTo(ScreenChecking::class, 'screen_checking_id');
    }

    public function screen()
    {
        return $this->belongsTo(Screens::class);
    }

    public function placement()
    {
        return $this->belongsTo(OrderDesignPlacement::class);
    }
}
