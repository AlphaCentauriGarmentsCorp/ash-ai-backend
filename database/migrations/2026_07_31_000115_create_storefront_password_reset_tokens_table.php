<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Password-reset tokens for CUSTOMER accounts.
 *
 * Same shape as Laravel's stock password_reset_tokens, but a separate table: the ERP
 * already owns `password_reset_tokens` for staff. StorefrontServiceProvider points
 * the `storefront` password broker here, and PasswordResetController must use
 * Password::broker('storefront') — the default broker would look up ERP staff
 * accounts and write to their table.
 *
 * No `sessions` table is created: the ERP owns that one and the storefront's
 * bearer-token auth does not use it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storefront_password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_password_reset_tokens');
    }
};
