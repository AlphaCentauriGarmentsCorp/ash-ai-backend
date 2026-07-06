<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GA Portal CP1 — custom_colors table.
 *
 * Separate from the canonical `pantones` catalog (decision "D"). The
 * official catalog stays READ-ONLY from the GA portal; artist-defined
 * colors live here so they are reusable and de-duplicated across
 * placements/orders. Columns mirror `pantones` (name, hexcolor,
 * pantone_code) plus pick_count and created_by.
 *
 * De-dup key is the normalised hexcolor (see CustomColorService) — a
 * custom color has no official Pantone code, so pantone_code is nullable.
 * No DB-level FK on created_by (ASH convention).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('custom_colors')) {
            return;
        }

        Schema::create('custom_colors', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('hexcolor');
            $table->string('pantone_code')->nullable();
            $table->unsignedInteger('pick_count')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index('hexcolor', 'custom_colors_hex_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_colors');
    }
};
