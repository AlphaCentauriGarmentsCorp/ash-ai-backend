<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5-H — Graphic Artist Portal: design file vault.
 *
 * Versioned storage for the design assets the Graphic Artist uploads:
 *   - front_design / back_design  (the artwork files themselves)
 *   - front_mockup / back_mockup  (rendered mockups on the garment)
 *   - color_separation            (the print-room separation PDF)
 *   - other                       (catch-all)
 *
 * Versioning rule: each (order_id, kind) pair has a running version
 * counter. Every upload writes a new row and flips is_latest=true on
 * the new row + is_latest=false on the previous latest. Deletes hard-
 * remove the row and recompute is_latest for the same kind.
 *
 * Allowed mime types (enforced in form request, not schema):
 *   png, jpg, jpeg, pdf, psd, svg, webp, ai
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('order_design_files')) {
            return;
        }

        Schema::create('order_design_files', function (Blueprint $t) {
            $t->id();

            $t->foreignId('order_id')
                ->constrained('orders')
                ->cascadeOnDelete();

            // Optional link to the design record. Nullable because a file
            // might be uploaded before a placement row exists.
            $t->foreignId('order_design_id')
                ->nullable()
                ->constrained('order_designs')
                ->nullOnDelete();

            // front_design | back_design | front_mockup | back_mockup |
            // color_separation | other
            $t->string('kind', 32);

            // Running version per (order_id, kind). Starts at 1.
            $t->unsignedInteger('version')->default(1);

            $t->string('file_path', 255);
            $t->string('original_name', 255);
            $t->string('mime_type', 64);
            $t->unsignedBigInteger('size_bytes');

            // TRUE on the highest version per (order_id, kind). Allows a
            // single-row lookup for "show me the latest of each kind"
            // without window functions (which SQLite tests choke on).
            $t->boolean('is_latest')->default(true);

            $t->foreignId('uploaded_by_user_id')
                ->constrained('users');

            $t->text('notes')->nullable();

            $t->timestamps();

            $t->index(['order_id', 'kind']);
            $t->index(['order_id', 'kind', 'is_latest']);
            $t->index('uploaded_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_design_files');
    }
};