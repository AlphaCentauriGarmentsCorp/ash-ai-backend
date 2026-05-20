<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Phase 7-B Bundle 1 — Packing checklist item (master list).
 *
 * 7 canonical items seeded via PackingChecklistItemSeeder. See
 * QaChecklistItem for the tick-state storage strategy (mirror).
 */
class PackingChecklistItem extends Model
{
    protected $table = 'packing_checklist_items';

    protected $fillable = [
        'slug',
        'label',
        'display_order',
        'active',
    ];

    protected $casts = [
        'display_order' => 'integer',
        'active'        => 'boolean',
    ];
}
