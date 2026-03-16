<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderDesignPlacement extends Model
{
    protected $table = 'order_design_placements';
    protected $fillable = [
        'order_design_id',
        'type',
        'mockup_image',
        'pantones'
    ];

    protected $casts = [
        'pantones' => 'array'
    ];

    public function design()
    {
        return $this->belongsTo(OrderDesign::class, 'order_design_placements');
    }
}
