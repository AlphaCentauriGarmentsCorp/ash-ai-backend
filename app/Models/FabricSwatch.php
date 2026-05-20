<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * FabricSwatch — visual fabric catalog entry.
 *
 * Phase 6-B. The catalog page reads from here; Phase 6-C will use
 * the same rows to power the visual fabric dropdown in Quotation +
 * Create Order forms.
 *
 * `material_id` is the optional inventory bridge — when set, the
 * swatch's "in stock / low stock / out" status is derived from
 * Materials::stock_on_hand at read time (not denormalized on this row).
 */
class FabricSwatch extends Model
{
    protected $table = 'fabric_swatches';

    protected $fillable = [
        'name',
        'pantone_id',
        'hex_color',
        'fabric_type',
        'gsm',
        'collection',
        'supplier_id',
        'material_id',
        'color_family',
        'photo_path',
        'notes',
    ];

    protected $casts = [
        'gsm' => 'integer',
    ];

    // ── Relations ────────────────────────────────────────────────────────

    public function pantone()
    {
        return $this->belongsTo(Pantone::class, 'pantone_id');
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function material()
    {
        return $this->belongsTo(Materials::class, 'material_id');
    }
}
