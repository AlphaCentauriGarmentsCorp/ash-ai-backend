<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * GA Portal CP1 — artist-defined custom color.
 *
 * Kept apart from the canonical Pantone catalog. De-duplicated on the
 * normalised hexcolor by CustomColorService::findOrCreate().
 */
class CustomColor extends Model
{
    protected $table = 'custom_colors';

    protected $fillable = [
        'name',
        'hexcolor',
        'pantone_code',
        'pick_count',
        'created_by',
    ];

    protected $casts = [
        'pick_count' => 'integer',
    ];
}
