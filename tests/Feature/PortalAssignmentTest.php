<?php

/**
 * Phase 5-A — Portal Assignment Service tests.
 *
 * Run with:
 *   php artisan test --filter=PortalAssignmentTest
 *
 * Same in-memory SQLite isolation strategy as Phase 3/4 tests.
 * Helpers prefixed phase5_ to avoid collisions with phase3_/phase4_.
 *
 * Coverage:
 *   1. Returns "none" when user has no active assignments
 *   2. Returns "single" when user has exactly one active assignment
 *      in a stage their role works on
 *   3. Returns "multiple" when user has 2+ active assignments
 *   4. Filters out completed/pending stages (only active counted)
 *   5. Filters by role's stage slug list (cross-role assignment ignored)
 *   6. Material-prep returns "none" by design (no stage-based work)
 *
 * Mix-mode shared queue (Workstream B):
 *   7. UNASSIGNED active stage at the role station is visible (the bug fix)
 *   8. Stage explicitly assigned to me is visible
 *   9. Stage assigned to a DIFFERENT user is hidden (override respected)
 *  10. Mine + unassigned shown together; another user's hidden (picker)
 *  11. Unassigned pool still scoped to the role station (no cross-role leak)
 */

use App\Models\Order;
use App\Models\OrderStage;
use App\Models\User;
use App\Services\PortalAssignmentService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    foreach ([
        'order_stages',
        'orders',
        'users',
    ] as $t) {
        Schema::dropIfExists($t);
    }

    Schema::create('users', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('email')->unique();
        $t->string('password')->default('hashed');
        $t->timestamps();
    });

    Schema::create('orders', function (Blueprint $t) {
        $t->id();
        $t->string('po_code')->unique();
        $t->string('client_name')->nullable();
        $t->string('client_brand')->nullable();
        $t->string('workflow_status', 32)->default('inquiry');
        $t->timestamp('delayed_at')->nullable();
        $t->unsignedBigInteger('current_stage_id')->nullable();
        $t->timestamps();
        // Order model uses SoftDeletes (added in the order soft-delete work),
        // so Eloquent appends `deleted_at is null` to every query. The
        // hand-built schema must include this column or order hydration in
        // summarizeStage() throws "no such column: orders.deleted_at".
        $t->softDeletes();
    });

    Schema::create('order_stages', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->text('stage');
        $t->unsignedSmallInteger('sequence')->default(0);
        $t->string('status')->default('pending');
        $t->timestamp('started_at')->nullable();
        $t->timestamp('completed_at')->nullable();
        $t->timestamp('delayed_at')->nullable();
        $t->unsignedBigInteger('assigned_to')->nullable();
        $t->string('assigned_role', 64)->nullable();
        $t->text('notes')->nullable();
        $t->timestamps();
    });
});

afterEach(function () {
    foreach ([
        'order_stages',
        'orders',
        'users',
    ] as $t) {
        Schema::dropIfExists($t);
    }
});

// ─── Helpers ──────────────────────────────────────────────────────

function phase5_makeUser(string $name = 'Cutter User'): User
{
    $id = DB::table('users')->insertGetId([
        'name' => $name,
        'email' => strtolower(str_replace(' ', '', $name)) . '@example.com',
        'password' => 'x',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    return User::find($id);
}

function phase5_makeOrderStage(int $userId, string $stageSlug, string $status = 'in_progress'): array
{
    $orderId = DB::table('orders')->insertGetId([
        'po_code' => 'ASH-TEST-' . uniqid(),
        'client_name' => 'Test',
        'client_brand' => 'Brand',
        'workflow_status' => $stageSlug,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $stageId = DB::table('order_stages')->insertGetId([
        'order_id' => $orderId,
        'stage' => $stageSlug,
        'sequence' => 7,
        'status' => $status,
        'assigned_to' => $userId,
        'started_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return ['order_id' => $orderId, 'stage_id' => $stageId];
}

// ─── Tests ────────────────────────────────────────────────────────

it('returns none when user has no active assignments', function () {
    $user = phase5_makeUser();
    $svc = new PortalAssignmentService();
    $result = $svc->myActive($user, 'cutter');

    expect($result)->toMatchArray(['status' => 'none']);
});

it('returns single when user has exactly one active assignment', function () {
    $user = phase5_makeUser();
    $made = phase5_makeOrderStage($user->id, 'sample_creation');

    $svc = new PortalAssignmentService();
    $result = $svc->myActive($user, 'cutter');

    expect($result['status'])->toBe('single');
    expect($result['assignment']['order_stage_id'])->toBe($made['stage_id']);
    expect($result['assignment']['stage'])->toBe('sample_creation');
    expect($result['assignment']['order']['po_code'])->toStartWith('ASH-TEST-');
});

it('returns multiple when user has 2 or more active assignments', function () {
    $user = phase5_makeUser();
    phase5_makeOrderStage($user->id, 'sample_creation');
    phase5_makeOrderStage($user->id, 'mass_production');

    $svc = new PortalAssignmentService();
    $result = $svc->myActive($user, 'cutter');

    expect($result['status'])->toBe('multiple');
    expect($result['assignments'])->toHaveCount(2);
});

it('ignores completed and pending stages', function () {
    $user = phase5_makeUser();
    phase5_makeOrderStage($user->id, 'sample_creation', 'in_progress');
    phase5_makeOrderStage($user->id, 'mass_production', 'completed');
    phase5_makeOrderStage($user->id, 'mass_production', 'pending');

    $svc = new PortalAssignmentService();
    $result = $svc->myActive($user, 'cutter');

    // Only the in_progress one counts → single, not multiple.
    expect($result['status'])->toBe('single');
});

it('filters by role stage slug list', function () {
    // QA user assigned to a sample_creation stage shouldn't see it
    // through the QA portal (QA only works on quality_control).
    $user = phase5_makeUser();
    phase5_makeOrderStage($user->id, 'sample_creation');

    $svc = new PortalAssignmentService();
    $result = $svc->myActive($user, 'qa');

    expect($result['status'])->toBe('none');
});

it('returns none for material_prep regardless of stage assignments', function () {
    // Material prep is not stage-bound; it should always return 'none'
    // and let the portal page take over with PR-based logic.
    $user = phase5_makeUser();
    phase5_makeOrderStage($user->id, 'sample_creation');

    $svc = new PortalAssignmentService();
    $result = $svc->myActive($user, 'material_prep');

    expect($result['status'])->toBe('none');
});

// ─── Mix-mode shared queue (Workstream B) ─────────────────────────
//
// Helper that creates a stage with an explicit assigned_to value
// ($assignedTo may be null for an UNASSIGNED / shared stage).

function phase5_makeStageAssigned(?int $assignedTo, string $stageSlug, string $status = 'in_progress'): array
{
    $orderId = DB::table('orders')->insertGetId([
        'po_code' => 'ASH-TEST-' . uniqid(),
        'client_name' => 'Test',
        'client_brand' => 'Brand',
        'workflow_status' => $stageSlug,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $stageId = DB::table('order_stages')->insertGetId([
        'order_id' => $orderId,
        'stage' => $stageSlug,
        'sequence' => 5,
        'status' => $status,
        'assigned_to' => $assignedTo,           // null = unassigned/shared
        'assigned_role' => 'graphic_artist',
        'started_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return ['order_id' => $orderId, 'stage_id' => $stageId];
}

it('shows an UNASSIGNED active stage at the role station (shared queue)', function () {
    // This is the exact bug from the runtime walkthrough: an in_progress
    // graphic_artwork stage with assigned_to = null was invisible to the GA.
    $ga = phase5_makeUser('GA User');
    $made = phase5_makeStageAssigned(null, 'graphic_artwork');

    $svc = new PortalAssignmentService();
    $result = $svc->myActive($ga, 'graphic_artist');

    expect($result['status'])->toBe('single');
    expect($result['assignment']['order_stage_id'])->toBe($made['stage_id']);
    expect($result['assignment']['stage'])->toBe('graphic_artwork');
});

it('shows a stage explicitly assigned to me', function () {
    $ga = phase5_makeUser('GA User');
    $made = phase5_makeStageAssigned(null, 'graphic_artwork');
    // Reassign it explicitly to this GA.
    DB::table('order_stages')->where('id', $made['stage_id'])
        ->update(['assigned_to' => $ga->id]);

    $svc = new PortalAssignmentService();
    $result = $svc->myActive($ga, 'graphic_artist');

    expect($result['status'])->toBe('single');
    expect($result['assignment']['order_stage_id'])->toBe($made['stage_id']);
});

it('HIDES a stage assigned to a different user (manager override respected)', function () {
    $gaA = phase5_makeUser('GA A');
    $gaB = phase5_makeUser('GA B');
    // Stage assigned explicitly to GA A.
    phase5_makeStageAssigned($gaA->id, 'graphic_artwork');

    $svc = new PortalAssignmentService();
    // GA B should NOT see it — it belongs to someone else.
    $result = $svc->myActive($gaB, 'graphic_artist');

    expect($result['status'])->toBe('none');
});

it('shows MINE + UNASSIGNED together, but not another users stage', function () {
    $gaA = phase5_makeUser('GA A');
    $gaB = phase5_makeUser('GA B');

    phase5_makeStageAssigned($gaA->id, 'graphic_artwork'); // mine
    phase5_makeStageAssigned(null, 'graphic_artwork');     // shared/unassigned
    phase5_makeStageAssigned($gaB->id, 'graphic_artwork'); // someone else's

    $svc = new PortalAssignmentService();
    $result = $svc->myActive($gaA, 'graphic_artist');

    // GA A sees their own + the unassigned one (2), NOT GA B's → picker.
    expect($result['status'])->toBe('multiple');
    expect($result['assignments'])->toHaveCount(2);
});

it('still scopes the shared pool to the role station', function () {
    // An UNASSIGNED stage at a DIFFERENT station (sample_creation) must not
    // leak into the GA portal — the role slug filter still applies.
    $ga = phase5_makeUser('GA User');
    phase5_makeStageAssigned(null, 'sample_creation'); // not a GA stage

    $svc = new PortalAssignmentService();
    $result = $svc->myActive($ga, 'graphic_artist');

    expect($result['status'])->toBe('none');
});