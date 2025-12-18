<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Design extends Model
{
   protected $fillable = [
        'artist_id',
        'po_number',
        'design_name',
        'type_printing_method',
        'resolution',
        'color_count',
        'mockup_files',
        'production_files',
        'design_placements',
        'color_palette',
        'notes',
        'status',
        'version',
    ];

    public function artist()
    {
        return $this->belongsTo(Artist::class);
    }

    //public function printingMethod()
   // {
    //    return $this->belongsTo(PrintingMethod::class, 'type_printing_method');
   // }

}
