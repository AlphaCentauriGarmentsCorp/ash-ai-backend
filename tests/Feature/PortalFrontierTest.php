<?php

/**
 * Bundle 1.1 — Portal queue "frontier" filter.
 *
 * Run with:
 *   php artisan test --filter=PortalFrontierTest
 *
 * Verifies PortalAssignmentService::activeTasks() and activeCountForRole()
 * only surface stages that are actionable at a station RIGHT NOW:
 *   - any non-pending active row (in_progress / delayed / for_approval), or
 *   - a pending row the fork-join engine would start now (WorkflowStages::
 *     nextActivations()).
 *
 * Unlike PortalActiveTasksTest (which seeds one isolated stage per order, so
 * every pending stage is trivially its own frontier), these tests seed the
 * FULL canonical pipeline per order — exactly what initializeForOrder() does
 * in production — so the filter actually bites: future pending stages are
 * hidden and a station's sample/mass twin collapses to the order's real phase.
 *
 * Same in-memory SQLite isolation as the other portal tests.
 */

use App\Models\OrderStage;
use App\Models\User;
use App\Services\PortalAssignmentService;
use App\Support\WorkflowStages;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    foreach (['stage_reviews', 'order_stages', 'orders', 'users'] as $t) {
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

    Schema::create('orders', function (Blueprint $t) {
        $t->id();
        $t->string('po_code')->unique();
        $t->string('client_name')->nullable();
        $t->string('client_brand')->nullable();
        $t->string('workflow_status', 32)->default('inquiry');
        $t->integer('total_quantity')->nullable();
        $t->string('shirt_color')->nullable();
        $t->string('print_area')->nullable();
        $t->json('print_parts_json')->nullable();
        $t->boolean('rush_order')->default(false);
        $t->string('priority', 16)->nullable();
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

    // buildTaskList() consults stage_reviews (For Revision); keep it present/empty.
    Schema::create('stage_reviews', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->unsignedBigInteger('order_stage_id');
        $t->unsignedBigInteger('actor_user_id')->nullable();
        $t->string('decision', 16);
        $t->text('comment')->nullable();
        $t->string('image_path')->nullable();
        $t->timestamps();
    });
});

afterEach(function () {
    foreach (['stage_reviews', 'order_stages', 'orders', 'users'] as $t) {
        Schema::dropIfExists($t);
    }
});

// ─── Helpers ──────────────────────────────────────────────────────

function pf_makeUser(string $name = 'Worker'): User
{
    $id = DB::table('users')->insertGetId([
        'name' => $name,
        'email' => strtolower(str_replace(' ', '', $name)) . uniqid() . '@example.com',
        'password' => 'x',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    return User::find($id);
}

/** A full canonical stage map (every stage 'pending') with $overrides applied. */
function pf_pipeline(array $overrides): array
{
    $map = [];
    foreach (WorkflowStages::keys() as $slug) {
        $map[$slug] = 'pending';
    }
    return array_merge($map, $overrides);
}

/** Seed an order carrying the FULL canonical pipeline ($statusBySlug). */
function pf_seedOrder(array $statusBySlug, array $orderAttrs = []): int
{
    $orderId = DB::table('orders')->insertGetId(array_merge([
        'po_code' => 'ASH-' . uniqid(),
        'client_name' => 'Acme', 'client_brand' => 'AcmeWear',
        'workflow_status' => 'x',
        'total_quantity' => 100, 'shirt_color' => 'Black',
        'print_area' => 'Regular',
        'print_parts_json' => json_encode([['part' => 'Front']]),
        'rush_order' => false,
        'created_at' => now(), 'updated_at' => now(),
    ], $orderAttrs));

    foreach ($statusBySlug as $slug => $status) {
        $started = in_array($status, ['in_progress', 'completed', 'delayed', 'for_approval'], true);
        DB::table('order_stages')->insert([
            'order_id' => $orderId,
            'stage' => $slug,
            'sequence' => WorkflowStages::sequenceOf($slug) ?? 0,
            'status' => $status,
            'started_at' => $started ? now() : null,
            'completed_at' => $status === 'completed' ? now() : null,
            'assigned_to' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    return $orderId;
}

// Convenience: full pipelines parked at common frontiers.
function pf_atGraphicArtwork(): array
{
    return pf_pipeline([
        'payment_verification_sample' => 'completed',
        'graphic_artwork' => 'in_progress',
    ]);
}

function pf_atSampleCutting(): array
{
    return pf_pipeline([
        'payment_verification_sample' => 'completed',
        'graphic_artwork' => 'completed',
        'screen_making' => 'completed',
        'material_prep_sample' => 'completed',
        // sample_cutting is the (pending) frontier; mass_cutting is future.
    ]);
}

function pf_atMassCutting(): array
{
    return pf_pipeline([
        'payment_verification_sample' => 'completed',
        'graphic_artwork' => 'completed',
        'screen_making' => 'completed',
        'material_prep_sample' => 'completed',
        'sample_cutting' => 'completed',
        'sample_printing' => 'completed',
        'sample_sewing' => 'completed',
        'sample_packing' => 'completed',
        'sample_approval' => 'completed',
        'payment_verification_mass' => 'completed',
        'material_prep_mass' => 'completed',
        // mass_cutting is the (pending) frontier.
    ]);
}

// ─── Tests ────────────────────────────────────────────────────────

it('hides an order from downstream stations until it reaches them', function () {
    // THE BUG: an order still at Graphic Artwork must NOT flood the production
    // portals just because its later stages were pre-created as pending.
    pf_seedOrder(pf_atGraphicArtwork());
    $svc = new PortalAssignmentService();

    expect($svc->activeCountForRole('graphic_artist'))->toBe(1) // shown at its station
        ->and($svc->activeCountForRole('screen_maker'))->toBe(0)
        ->and($svc->activeCountForRole('cutter'))->toBe(0)
        ->and($svc->activeCountForRole('printer'))->toBe(0)
        ->and($svc->activeCountForRole('sewer'))->toBe(0)
        ->and($svc->activeCountForRole('qa_packer'))->toBe(0);
});

it('promotes BOTH parallel fork branches and makes the join wait', function () {
    // graphic_artwork done → tier-6 fork (screen_making ‖ material_prep_sample)
    // is live; the join (sample_cutting, tier 7) must still wait.
    pf_seedOrder(pf_pipeline([
        'payment_verification_sample' => 'completed',
        'graphic_artwork' => 'completed',
        // screen_making + material_prep_sample stay pending = the live fork
    ]));
    $svc = new PortalAssignmentService();

    expect($svc->activeCountForRole('screen_maker'))->toBe(1)
        ->and($svc->activeCountForRole('material_prep'))->toBe(1) // sample branch only
        ->and($svc->activeCountForRole('cutter'))->toBe(0);       // join waits
});

it('collapses the cutter twin to the SAMPLE stage during the sample phase', function () {
    pf_seedOrder(pf_atSampleCutting());
    $tasks = (new PortalAssignmentService())->activeTasks(pf_makeUser(), 'cutter')['tasks'];

    expect($tasks)->toHaveCount(1)
        ->and($tasks[0]['stage'])->toBe('sample_cutting'); // NOT mass_cutting
});

it('collapses the cutter twin to the MASS stage during mass production', function () {
    // Same station, same order shape later in its life — shows mass, never both.
    pf_seedOrder(pf_atMassCutting());
    $tasks = (new PortalAssignmentService())->activeTasks(pf_makeUser(), 'cutter')['tasks'];

    expect($tasks)->toHaveCount(1)
        ->and($tasks[0]['stage'])->toBe('mass_cutting');
});

it('always keeps a non-pending row (in_progress / delayed) regardless of tier', function () {
    // An in_progress mass_cutting is current work even though nextActivations
    // only ever returns *pending* slugs.
    pf_seedOrder(pf_pipeline([
        'payment_verification_sample' => 'completed',
        'graphic_artwork' => 'completed',
        'screen_making' => 'completed',
        'material_prep_sample' => 'completed',
        'sample_cutting' => 'completed',
        'sample_printing' => 'completed',
        'sample_sewing' => 'completed',
        'sample_packing' => 'completed',
        'sample_approval' => 'completed',
        'payment_verification_mass' => 'completed',
        'material_prep_mass' => 'completed',
        'mass_cutting' => 'in_progress',
    ]));
    // A delayed graphic_artwork on a second order is also current work.
    pf_seedOrder(pf_pipeline([
        'payment_verification_sample' => 'completed',
        'graphic_artwork' => 'delayed',
    ]));
    $svc = new PortalAssignmentService();

    expect($svc->activeCountForRole('cutter'))->toBe(1)
        ->and($svc->activeCountForRole('graphic_artist'))->toBe(1);
});

it('keeps the badge count and the list length identical (single source of truth)', function () {
    pf_seedOrder(pf_atSampleCutting()); // shows in cutter (sample)
    pf_seedOrder(pf_atMassCutting());   // shows in cutter (mass)
    pf_seedOrder(pf_atGraphicArtwork()); // hidden from cutter

    $svc = new PortalAssignmentService();
    $listCount = count($svc->activeTasks(pf_makeUser(), 'cutter')['tasks']);
    $badge = $svc->activeCountForRole('cutter');

    expect($badge)->toBe(2)
        ->and($listCount)->toBe(2)
        ->and($badge)->toBe($listCount);
});
