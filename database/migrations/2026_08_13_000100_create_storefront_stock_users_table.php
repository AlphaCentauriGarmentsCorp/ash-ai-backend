<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Warehouse STAFF accounts for the Stock manager module.
     *
     * Deliberately a second, separate account table — not extra columns on `users`.
     * `users` holds the shop's 34 customers: people who bought a hoodie, authenticate
     * with Sanctum/api_token, and have addresses, carts, favourites and orders hanging
     * off them. This table holds the people who run the warehouse: they authenticate
     * with the module's own Bearer tokens (App\Support\Stock\TokenSessions), are
     * approved by an admin before they may log in at all, and own none of that
     * shopper-side data. Merging the two populations would mean every customer row
     * carrying a `role`/`status`/`approved` triplet that means nothing for them, and —
     * worse — one leaked shopper credential landing inside the ERP. Separate tables,
     * separate guards, separate blast radius.
     *
     * The column list is the ERP's own account model, taken from the INSERT in the
     * Stock manager's AuthController::register(): every column below is written on
     * every new account.
     *
     * Notes on the shape:
     *
     * - `role` and `status` are plain strings, not enums. The application validates
     *   them in PHP and uses a wider set than any single call site suggests
     *   (role: admin/staff and whatever an admin types into the Edit Account modal;
     *   status: pending/approved/rejected). An enum column would reject values the
     *   app itself produces.
     *
     * - `active` is separate from `status` on purpose: an admin can deactivate an
     *   account without losing the fact that it was once approved. The UI collapses
     *   the pair into one Active/Inactive/Suspended value; the database keeps both
     *   halves so "reactivate" is a one-column change.
     *
     * - `created_date` is a DATE written as Y-m-d in Asia/Manila, not a timestamp:
     *   it is rendered verbatim in the Registered column of the User Approvals table,
     *   so it must come back out of MySQL as the same plain string it went in as.
     *
     * - No timestamps(). This table mirrors the ERP's account model exactly, and
     *   "who changed what, when" for this module lives in stock_inventory_log rather
     *   than in per-row created_at/updated_at.
     */
    public function up(): void
    {
        Schema::create('storefront_stock_users', function (Blueprint $table) {
            $table->id();

            // Looked up case-insensitively via LOWER(username); 190 chars keeps the
            // unique index inside InnoDB's key-length limit on utf8mb4.
            $table->string('username', 190)->unique();

            // password_hash() with PASSWORD_BCRYPT returns 60 chars today, but the
            // algorithm is allowed to grow — 255 is the standard headroom.
            $table->string('password_hash', 255);

            $table->string('first_name');
            $table->string('last_name');
            $table->string('full_name');

            $table->string('role', 32)->default('staff');
            $table->string('status', 32)->default('pending');
            $table->boolean('active')->default(true);

            $table->date('created_date')->nullable();

            // The approvals screen loads one tab per status, and every authenticated
            // request re-checks (status, active) for the logged-in account.
            $table->index(['status', 'active'], 'stock_users_status_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_stock_users');
    }
};
