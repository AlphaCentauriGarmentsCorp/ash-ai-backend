<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5-H — Graphic Artist Portal: label / tag assets.
 *
 * One unified table for the three label kinds shown on the mockup:
 *   - main_label  (neck label inside the garment)
 *   - size_label  (size + care info)
 *   - hangtag     (etiketa, attached on a string)
 *
 * Unique on (order_id, kind) — each order has at most one of each.
 *
 * All metadata fields nullable: a row can exist with just file + kind,
 * or with metadata but no file (yet), depending on workflow stage.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('order_label_assets')) {
            return;
        }

        Schema::create('order_label_assets', function (Blueprint $t) {
            $t->id();

            $t->foreignId('order_id')
                ->constrained('orders')
                ->cascadeOnDelete();

            // main_label | size_label | hangtag
            $t->string('kind', 32);

            // File reference — nullable so metadata can be filled in first.
            $t->string('file_path', 255)->nullable();
            $t->string('original_name', 255)->nullable();
            $t->string('mime_type', 64)->nullable();
            $t->unsignedBigInteger('size_bytes')->nullable();

            // Print dimensions in inches (matches existing UI convention).
            $t->decimal('width_in', 6, 2)->nullable();
            $t->decimal('height_in', 6, 2)->nullable();

            // silkscreen | digital | embroidery | dtf | other
            $t->string('printing_process', 32)->nullable();

            $t->unsignedTinyInteger('color_count')->nullable();

            // Free text — "Black label", "White", "Natural"
            $t->string('background_color', 32)->nullable();

            // Free text — "300gsm matte", "Cotton woven"
            $t->string('material', 64)->nullable();

            $t->text('notes')->nullable();

            $t->foreignId('uploaded_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $t->timestamps();

            // One of each kind per order — enforced at DB level.
            $t->unique(['order_id', 'kind']);
            $t->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_label_assets');
    }
};
