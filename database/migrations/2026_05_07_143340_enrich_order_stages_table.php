<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // First pass – add columns
        Schema::table('order_stages', function (Blueprint $table) {
            $table->unsignedSmallInteger('sequence')->default(0)->after('stage');
            $table->timestamp('started_at')->nullable()->after('status');
            $table->timestamp('completed_at')->nullable()->after('started_at');
            $table->timestamp('delayed_at')->nullable()->after('completed_at');

            $table->unsignedBigInteger('assigned_to')->nullable()->after('delayed_at');
            $table->string('assigned_role', 64)->nullable()->after('assigned_to');

            $table->text('notes')->nullable()->after('assigned_role');

            // Foreign key for assigned user
            $table->foreign('assigned_to')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });

        // Second pass – indexes + unique constraint
        // (kept separate so we can easily drop only the FK in down())
        Schema::table('order_stages', function (Blueprint $table) {
            // Stage values are short slugs (e.g. "graphic_editing"), but the
            // existing column is text(). To make a unique index we use a
            // shorter prefix index via raw – for portability we just trust
            // application logic to prevent duplicates and instead add a
            // composite index for fast lookup by (order, sequence).
            $table->index(['order_id', 'sequence'], 'idx_order_stage_sequence');
            $table->index(['order_id', 'status'], 'idx_order_stage_status');
        });

        // Third pass – backfill `sequence` for any pre-existing rows.
        // The legacy "checkbox selection" model meant stages had no inherent
        // order. We map their slug to the canonical workflow position.
        // Stages whose slug is no longer in the canonical list keep sequence 0.
        $canonicalOrder = [
            'inquiry'                       => 1,
            'quotation'                     => 2,
            'quotation_approval'            => 3,
            'payment_verification_sample'   => 4,
            'graphic_artwork'               => 5,
            // Legacy slug aliases mapped to nearest canonical position:
            'graphic_editing'               => 5,
            'screen_making'                 => 6,
            'screen_checking'               => 6,
            'sample_creation'               => 7,
            'sample_material_preparation'   => 7,
            'sample_material_receiving'     => 7,
            'sample_cutting'                => 7,
            'sample_printing'               => 7,
            'sample_sewing'                 => 7,
            'sample_quality_assurance'      => 7,
            'sample_approval'               => 8,
            'mass_production'               => 9,
            'production_material_preparation' => 9,
            'production_material_receiving'   => 9,
            'production_cutting'              => 9,
            'production_printing'             => 9,
            'production_sewing'               => 9,
            'production_revision'             => 9,
            'production_quality_assurance'    => 10,
            'quality_control'                 => 10,
            'packing'                         => 11,
            'delivery'                        => 12,
            'order_completed'                 => 13,
            'client_notification'             => 14,
        ];

        foreach ($canonicalOrder as $slug => $seq) {
            \Illuminate\Support\Facades\DB::table('order_stages')
                ->where('stage', $slug)
                ->where('sequence', 0)
                ->update(['sequence' => $seq]);
        }
    }

    public function down(): void
    {
        Schema::table('order_stages', function (Blueprint $table) {
            $table->dropIndex('idx_order_stage_sequence');
            $table->dropIndex('idx_order_stage_status');

            $table->dropForeign(['assigned_to']);

            $table->dropColumn([
                'sequence',
                'started_at',
                'completed_at',
                'delayed_at',
                'assigned_to',
                'assigned_role',
                'notes',
            ]);
        });
    }
};
