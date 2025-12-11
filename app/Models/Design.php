<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Design extends Model
{
    protected $table = 'Design';
    protected $fillable = [
        'artist_id',
        'po_number',
        'design_name',
        'type_printing_method',
        'resolution',
        'color_count',
        'mockup_files',
        'production_diles',
        'design_placements',
        'color_palette',
        'notes',
        'status',
        'version',
    ];
}
