<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * OrderRoleNote — one entry in an order's role-directed instruction thread.
 *
 * Written from the Review Hub (csr / admin / super_admin) and aimed at a
 * single production role (audience_role); the target role reads its thread
 * inside their portal. Append-only and immutable — there are deliberately
 * no update/delete paths, matching the stage_reviews ledger.
 *
 * NOT the same thing as:
 *   - stage_reviews decision='note'      → per-STAGE staff notes in the Hub
 *   - order_stages.notes                 → the owning role's editable blob
 *   - orders.notes / order_designs.notes → CSR + design freeform fields
 */
class OrderRoleNote extends Model
{
    protected $table = 'order_role_notes';

    protected $fillable = [
        'order_id',
        'audience_role',
        'author_user_id',
        'body',
    ];

    // ---- Relations -------------------------------------------------------

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    // ---- Scopes ----------------------------------------------------------

    public function scopeForRole($query, string $role)
    {
        return $query->where('audience_role', $role);
    }
}
