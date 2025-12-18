<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\User;

class Design extends Model
{
  protected $table = 'designs';
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
        return $this->belongsTo(User::class, 'artist_id');
    }

    //public function printingMethod()
   // {
    //    return $this->belongsTo(PrintingMethod::class, 'type_printing_method');
   // }

}
