<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Storefront customer accounts.
 *
 * Squashed from the source project's users chain: create_users_table +
 * add_storefront_fields + add_api_token + rework_api_token + add_profile_fields +
 * add_two_factor_and_verification + add_google_id + drop_two_factor. The TOTP
 * columns are absent on purpose — they were added and then removed upstream, so
 * the final schema never had them.
 *
 * The table is `storefront_users`, NOT `users`: the ERP owns `users` (staff
 * accounts with NOT NULL username/domain_role/domain_access) and a customer row
 * could not satisfy that shape. Model: App\Models\Storefront\Customer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storefront_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();

            /*
             * google_id is Google's 'sub' claim — stable for the life of the account,
             * and the only thing that identifies the Google identity itself rather
             * than the address it currently carries. UNIQUE is the whole safety
             * property: it is what stops one Google identity being attached to two
             * rows, and it is what makes the two-concurrent-signups race resolvable
             * instead of silently forking an account.
             */
            $table->string('google_id')->nullable()->unique();

            // 512, not the default 255: Google's 'picture' URLs are long and grow a
            // size suffix. A URL that did not fit would fail the INSERT under strict
            // mode — a 500 in the middle of signing in, over an avatar.
            $table->string('avatar_url', 512)->nullable();

            $table->string('phone')->nullable();

            // The My Account profile form collects a birth date and gender, but there
            // was nowhere to put them — so they only ever lived in the browser. These
            // columns give the form somewhere real to save to.
            $table->date('birth_date')->nullable();
            $table->string('gender', 40)->nullable();

            $table->timestamp('email_verified_at')->nullable();

            // Nullable: an account created purely through Google has no password and
            // may never have one. Customer::hasPassword() is what every
            // prove-or-change-your-password flow has to ask first.
            $table->string('password')->nullable();

            /*
             * api_token used to hold a bcrypt hash, which is unsearchable —
             * authenticating meant loading every user and hashing against each. It
             * stores a SHA-256 hex digest instead: still useless to an attacker who
             * reads the table, but it can be looked up by index in one query. Hence
             * char(64) rather than string(80).
             */
            $table->char('api_token', 64)->nullable()->unique();
            $table->timestamp('api_token_last_used_at')->nullable();

            $table->string('email_verification_code', 12)->nullable();
            $table->timestamp('email_verification_sent_at')->nullable();

            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_users');
    }
};
