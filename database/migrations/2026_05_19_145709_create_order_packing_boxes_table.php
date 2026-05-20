<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7-B Bundle 1 — Box-level packing units (Option B per audit §7-B.5).
 *
 * One row per packing box for an order. Identified by a stable QR
 * code in the shape `ASH-PO-YYYY-NNNNNN-BOX-NN`, which encodes the
 * PO number's year + sequence + box number for the order.
 *
 * Why box-level (not piece-level): the spec says "system should know
 * SKU, size, PO, box, crate" — that's box-language. Box-level keeps
 * the packer scanning once per box (minimal typing per PDF §3) while
 * still allowing per-box contents tracking via the `contents_json`
 * array.
 *
 * contents_json schema:
 *   [
 *     {"size": "S", "sku": "...", "qty": 20},
 *     {"size": "M", "sku": "...", "qty": 30},
 *     ...
 *   ]
 *
 * sealed_at / sealed_by_user_id are set when the packer marks the
 * box as sealed and ready for the QR label print. Rows live in
 * "draft" state (sealed_at = null) while contents are being added.
 */
return new class extends Migration {

    public function up(): void
    {
        Schema::create('order_packing_boxes', function (Blueprint $t) {
            $t->id();

            $t->foreignId('order_id')
                ->constrained('orders')
                ->cascadeOnDelete();

            $t->unsignedSmallInteger('box_number'); // 1, 2, 3... within order

            $t->string('qr_code', 64)->unique();    // ASH-PO-2026-000045-BOX-01

            $t->json('contents_json')->nullable();

            $t->decimal('weight_kg', 6, 2)->nullable();

            $t->timestamp('sealed_at')->nullable();

            $t->foreignId('sealed_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $t->timestamps();

            $t->unique(['order_id', 'box_number']);
            $t->index('sealed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_packing_boxes');
    }
};
