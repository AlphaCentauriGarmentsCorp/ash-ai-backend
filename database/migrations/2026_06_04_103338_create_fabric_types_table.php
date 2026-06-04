<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Change 7.1 — Fabric Type as a superadmin-managed Drop Down Settings list.
 * Mirrors the other managed dropdowns (service_types, print_methods, …).
 * `description` is optional — a fabric type is just an option (e.g. "CVC").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fabric_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fabric_types');
    }
};