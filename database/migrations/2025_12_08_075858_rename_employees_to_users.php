<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        //
        // Rename the table
        Schema::rename('employees', 'users');

        // Add new columns to users table
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar')->nullable();
            $table->string('otp', 10)->nullable();
            $table->timestamp('otp_expires_at')->nullable();
            $table->timestamp('last_verified')->nullable();
            $table->string('domain_role');
            $table->string('domain_access')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop the new columns
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'avatar',
                'otp',
                'otp_expires_at',
                'last_verified',
                'domain_role',
                'domain_access',
            ]);
        });

        // Rename the table back
        Schema::rename('users', 'employees');
    }
};
