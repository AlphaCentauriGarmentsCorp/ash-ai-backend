<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5-I — Return-verification fields on subcontract assignments.
 *
 * When the vendor returns work, Logistics verifies what came back:
 *   - How many pieces actually arrived (return_qty_received)
 *   - Any condition issues (return_condition_notes)
 *   - Photos of the received batch (front + back)
 *   - Who verified, when
 *
 * Submitting verification flips the existing assignment.status to
 * 'returned' (the Phase 4 / 5-D terminal state). This is the
 * Logistics-side trigger for SubcontractService::markReturned().
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('stage_subcontract_assignments', function (Blueprint $t) {
            if (! Schema::hasColumn('stage_subcontract_assignments', 'return_qty_received')) {
                $t->unsignedInteger('return_qty_received')->nullable()->after('returned_at');
            }
            if (! Schema::hasColumn('stage_subcontract_assignments', 'return_condition_notes')) {
                $t->text('return_condition_notes')->nullable()->after('return_qty_received');
            }
            if (! Schema::hasColumn('stage_subcontract_assignments', 'return_photo_front_path')) {
                $t->string('return_photo_front_path', 255)->nullable()->after('return_condition_notes');
            }
            if (! Schema::hasColumn('stage_subcontract_assignments', 'return_photo_back_path')) {
                $t->string('return_photo_back_path', 255)->nullable()->after('return_photo_front_path');
            }
            if (! Schema::hasColumn('stage_subcontract_assignments', 'return_verified_by_user_id')) {
                $t->foreignId('return_verified_by_user_id')
                    ->nullable()
                    ->after('return_photo_back_path')
                    ->constrained('users')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('stage_subcontract_assignments', 'return_verified_at')) {
                $t->timestamp('return_verified_at')->nullable()->after('return_verified_by_user_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('stage_subcontract_assignments', function (Blueprint $t) {
            // Drop the FK before the column to keep down() safe across drivers.
            if (Schema::hasColumn('stage_subcontract_assignments', 'return_verified_by_user_id')) {
                try {
                    $t->dropForeign(['return_verified_by_user_id']);
                } catch (\Throwable $e) {
                    // ignore — some drivers don't track named FKs reliably
                }
            }
            $t->dropColumn([
                'return_qty_received',
                'return_condition_notes',
                'return_photo_front_path',
                'return_photo_back_path',
                'return_verified_by_user_id',
                'return_verified_at',
            ]);
        });
    }
};
