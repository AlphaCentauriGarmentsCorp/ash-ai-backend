<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Role-directed order notes — the Hub → portal "instructions" channel.
 *
 * An append-only, ORDER-LEVEL thread of instructions aimed at a specific
 * production role (audience_role), written from the Review Hub by the
 * reviewer roles (csr / admin / super_admin) and read inside the target
 * role's portal (Graphic Artist first; other portals reuse the same
 * channel by passing their own audience_role).
 *
 * Design (owner's confirmed spec, this session):
 *   - SEPARATE from stage_reviews: those notes are "about a stage"; these
 *     are "for a role, about the order". Order-level on purpose — the
 *     thread survives stage resets from the sample-rejection loop.
 *   - Append-only + immutable (no update/delete endpoints), matching the
 *     stage_reviews ledger and the "every action logged" principle.
 *   - audience_role holds a canonical WorkflowStages role slug, validated
 *     at the service layer (OrderRoleNoteService::allowedRoles()).
 */
return new class extends Migration {

    public function up(): void
    {
        if (Schema::hasTable('order_role_notes')) {
            return;
        }

        Schema::create('order_role_notes', function (Blueprint $t) {
            $t->id();

            $t->foreignId('order_id')
                ->constrained('orders')
                ->cascadeOnDelete();

            // Canonical role slug (e.g. 'graphic_artist').
            $t->string('audience_role', 64);

            // Hub staff member who wrote the instruction.
            $t->foreignId('author_user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $t->text('body');

            $t->timestamps();

            // Hot reads: "this order's thread for role X" (portal payload)
            // and "this order's threads grouped by role" (hub payload)
            // both hit this composite.
            $t->index(['order_id', 'audience_role'], 'idx_role_notes_order_role');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_role_notes');
    }
};
