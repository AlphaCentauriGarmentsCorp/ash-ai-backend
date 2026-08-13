<?php

namespace App\Models\Storefront\Stock;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A warehouse STAFF account for the Stock manager module — table `stock_users`.
 *
 * Not App\Models\User. That model is the shop's customer: Sanctum-ish api_token,
 * addresses, carts, favourites, orders. This one is a warehouse operator: a Bearer
 * token from App\Support\Storefront\Stock\TokenSessions, an admin approval before they may log
 * in, and none of the shopper relations. Nothing in this class or its callers reads
 * or writes `users`.
 *
 * The Active/Inactive/Suspended value the UI shows is a projection of two columns:
 *
 *   status 'approved' + active 1  ->  "active"
 *   status 'approved' + active 0  ->  "inactive"
 *   status 'rejected'             ->  "suspended"
 *   status 'pending'              ->  "pending"  (shown, but not settable from the
 *                                                 Edit Account modal)
 *
 * @property int $id
 * @property string $username
 * @property string $password_hash
 * @property string $first_name
 * @property string $last_name
 * @property string $full_name
 * @property string $role
 * @property string $status
 * @property bool $active
 * @property string|null $created_date
 */
class StockUser extends Model
{
    protected $table = 'storefront_stock_users';

    /**
     * No created_at/updated_at on this table — see the migration. `created_date` is
     * the ERP's own registration date and is written by hand.
     */
    public $timestamps = false;

    /**
     * Nothing is mass-assignable. Every write in AuthController is an explicit,
     * hand-built array of validated fields; letting a request body reach `role` or
     * `status` directly is how a self-registration turns itself into an admin.
     */
    protected $fillable = [];

    /**
     * Belt and braces for anything that serialises a model straight to JSON. The
     * controller builds its own arrays and never leaks this, but a future caller
     * doing `response()->json($staff)` should not ship a bcrypt hash to a browser.
     */
    protected $hidden = ['password_hash'];

    protected function casts(): array
    {
        // `active` only. `created_date` is deliberately NOT cast to a date: the
        // approvals table renders it verbatim, and a Carbon instance would serialise
        // as "2026-08-13T00:00:00.000000Z" where the UI expects "2026-08-13".
        return [
            'active' => 'boolean',
        ];
    }

    /**
     * Usernames are compared case-insensitively — "JDelaCruz" and "jdelacruz" are the
     * same account, so one cannot be registered while the other exists.
     */
    public function scopeUsername(Builder $query, string $username): Builder
    {
        return $query->whereRaw('LOWER(username) = LOWER(?)', [$username]);
    }

    /**
     * The account behind a live session, or null if it may no longer be used.
     *
     * Called by the middleware on every authenticated request, which is what makes
     * "Deactivate" and "Reject" take effect immediately: the token in the cache
     * outlives the change, but the account behind it does not.
     */
    public static function activeStaff(string $username): ?self
    {
        return static::query()
            ->where('username', $username)
            ->where('status', 'approved')
            ->where('active', true)
            ->first();
    }

    /** The single Active/Inactive/Suspended/pending value the UI displays. */
    public function displayStatus(): string
    {
        if ($this->status === 'approved') {
            return $this->active ? 'active' : 'inactive';
        }

        if ($this->status === 'rejected') {
            return 'suspended';
        }

        return $this->status; // 'pending' stays as-is
    }
}
