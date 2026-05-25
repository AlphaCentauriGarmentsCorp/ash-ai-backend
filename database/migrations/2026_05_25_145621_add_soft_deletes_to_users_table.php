<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds soft-delete support to users so that "deleting" an employee
     * account from User Accounts (Issue 14) deactivates the record and
     * keeps it restorable, rather than permanently removing accountability
     * history. Deleted rows automatically drop out of default queries via
     * the SoftDeletes trait on the User model.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};