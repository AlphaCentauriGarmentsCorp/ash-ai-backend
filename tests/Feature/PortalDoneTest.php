<?php

/**
 * Bundle 2 — Portal "Done" shared-queue ownership gate.
 *
 * Run with:
 *   php artisan test --filter=PortalDoneTest
 *
 * Same in-memory SQLite isolation as the other portal tests (hand-built
 * minimal schema, no RefreshDatabase / RBAC / HTTP). Covers the new
 * PortalAssignmentService::userMayActOnStage() gate that PortalController::done
 * and MaterialPrepPortalController::markPrepDone rely on:
 *
 *   - a stage at one of the role's stations, unassigned → may act
 *   - a stage assigned to ME → may act
 *   - a stage assigned to SOMEONE ELSE → may NOT act
 *   - a stage NOT among the role's stations → may NOT act (even if unassigned)
 *
 * Status/sequence guards are NOT this gate's job — they live in
 * OrderStagesService::markComplete and are covered by WorkflowEngineTest and
 * MaterialPrepAutoCompleteTest. The full HTTP layer (portal permission +
 * ownership + advance + payload) is exercised by the curl steps in
 * APPLY-AND-VERIFY.md against the running app.
 */

use App\Models\OrderStage;
use App\Models\User;
use App\Services\PortalAssignmentService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    foreach (['order_stages', 'users'] as $t) {
        Schema::dropIfExists($t);
    }

    Schema::create('users', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('email')->unique();
        $t->string('password')->default('x');
        $t->timestamps();
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
        $t->unsignedBigInteger('assigned_to')->nullable();
        $t->timestamps();
    });
});

afterEach(function () {
    foreach (['order_stages', 'users'] as $t) {
        Schema::dropIfExists($t);
    }
});

/** Insert an order_stage row with just the columns this gate reads. */
function makeStage(string $slug, ?int $assignedTo = null, string $status = 'in_progress'): OrderStage
{
    return OrderStage::create([
        'order_id'    => 1,
        'stage'       => $slug,
        'sequence'    => 7,
        'status'      => $status,
        'assigned_to' => $assignedTo,
    ]);
}

it('lets a worker act on an unassigned stage at their own station', function () {
    $svc  = new PortalAssignmentService();
    $user = User::create(['name' => 'Cutter', 'email' => 'cut@test.dev']);

    // sample_cutting is one of the cutter's stations; unassigned = shared pool.
    $stage = makeStage('sample_cutting', assignedTo: null);

    expect($svc->userMayActOnStage($user, 'cutter', $stage))->toBeTrue();
});

it('lets a worker act on a stage explicitly assigned to them', function () {
    $svc  = new PortalAssignmentService();
    $user = User::create(['name' => 'Cutter', 'email' => 'cut@test.dev']);

    $stage = makeStage('mass_cutting', assignedTo: $user->id);

    expect($svc->userMayActOnStage($user, 'cutter', $stage))->toBeTrue();
});

it('blocks a worker from acting on a stage assigned to someone else', function () {
    $svc   = new PortalAssignmentService();
    $me    = User::create(['name' => 'Me',    'email' => 'me@test.dev']);
    $other = User::create(['name' => 'Other', 'email' => 'other@test.dev']);

    // Manager handed this one to $other — it must not surface as mine to act on.
    $stage = makeStage('sample_cutting', assignedTo: $other->id);

    expect($svc->userMayActOnStage($me, 'cutter', $stage))->toBeFalse();
});

it('blocks a worker from acting on a stage outside their stations', function () {
    $svc  = new PortalAssignmentService();
    $user = User::create(['name' => 'Cutter', 'email' => 'cut@test.dev']);

    // sample_printing belongs to the printer, not the cutter — even unassigned
    // it is not in the cutter's queue.
    $stage = makeStage('sample_printing', assignedTo: null);

    expect($svc->userMayActOnStage($user, 'cutter', $stage))->toBeFalse();
});

it('gates the other plain-Done production roles the same way', function () {
    $svc  = new PortalAssignmentService();
    $user = User::create(['name' => 'Worker', 'email' => 'worker@test.dev']);

    expect($svc->userMayActOnStage($user, 'graphic_artist', makeStage('graphic_artwork')))->toBeTrue();
    expect($svc->userMayActOnStage($user, 'screen_maker',   makeStage('screen_making')))->toBeTrue();
    expect($svc->userMayActOnStage($user, 'sewer',          makeStage('mass_sewing')))->toBeTrue();

    // Cross-role URL tampering: a sewer slug under the graphic_artist role fails.
    expect($svc->userMayActOnStage($user, 'graphic_artist', makeStage('mass_sewing')))->toBeFalse();
});

it('recognises the active material-prep stages for the material_prep role', function () {
    $svc  = new PortalAssignmentService();
    $user = User::create(['name' => 'Prep', 'email' => 'prep@test.dev']);

    // Both prep stages are material_prep's stations (used by the manual fallback).
    expect($svc->userMayActOnStage($user, 'material_prep', makeStage('material_prep_sample')))->toBeTrue();
    expect($svc->userMayActOnStage($user, 'material_prep', makeStage('material_prep_mass')))->toBeTrue();
    expect($svc->userMayActOnStage($user, 'material_prep', makeStage('mass_cutting')))->toBeFalse();
});
