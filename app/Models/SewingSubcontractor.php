<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Vendor directory record.
 *
 * The class name is "SewingSubcontractor" for backward-compat with
 * existing controllers, services, resources, and frontend imports.
 * Phase 4 renamed the underlying table to `subcontractors` since it
 * now also covers cutting and printing vendors. The `service_type`
 * column ('sewing' | 'cutting' | 'printing' | 'multiple') indicates
 * what each vendor does.
 */
class SewingSubcontractor extends Model
{
    // Renamed in Phase 4
    protected $table = 'subcontractors';

    protected $fillable = [
        'name',
        'address',
        'rate_per_pcs',
        'contact_number',
        'email',
        'service_type',
    ];

    protected $casts = [
        'rate_per_pcs' => 'decimal:2',
    ];

    /**
     * Phase 4 — assignments tracked against this vendor.
     */
    public function subcontractAssignments(): HasMany
    {
        return $this->hasMany(StageSubcontractAssignment::class, 'subcontractor_id');
    }
}
