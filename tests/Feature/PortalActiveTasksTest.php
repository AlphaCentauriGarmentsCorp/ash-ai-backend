<?php

/**
 * Batch 2 — Portal "My Active Tasks" list (Change 2) + station counts (Change 3).
 *
 * Run with:
 *   php artisan test --filter=PortalActiveTasksTest
 *
 * Same in-memory SQLite isolation as the other portal tests. Covers the data
 * logic of PortalAssignmentService::activeTasks() and activeCountForRole():
 *   - rich row fields (project no, client/brand, qty, color, print area, age)
 *   - FIFO ordering with Rush pinned to the top
 *   - "For Revision" derived from the latest advisory stage_review
 *   - station total count ignores the shared-queue user scoping
 *
 * badgeCounts() visibility (oversight vs own-portal) layers Spatie role/can on
 * top of these two methods and is verified against the running app.
 */

use App\Models\OrderStage;
use App\Models\StageReview;
use App\Models\User;
use App\Services\PortalAssignmentService;
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

function pat_makeUser(string $name = 'Artist'): User
{
    $id = DB::table('users')->insertGetId([
        'name' => $name,
        'email' => strtolower(str_replace(' ', '', $name)) . uniqid() . '@example.com',
        'password' => 'x',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    return User::find($id);
}

/** Create an order + a stage at $slug. Returns [orderId, orderStageId]. */
function pat_makeTask(string $slug, array $orderAttrs = [], array $stageAttrs = []): array
{
    $orderId = DB::table('orders')->insertGetId(array_merge([
        'po_code' => 'ASH-' . uniqid(),
        'client_name' => 'Acme', 'client_brand' => 'AcmeWear',
        'workflow_status' => $slug,
        'total_quantity' => 100, 'shirt_color' => 'Black',
        'print_area' => 'Regular',
        'print_parts_json' => json_encode([['part' => 'Front'], ['part' => 'Back']]),
        'rush_order' => false,
        'created_at' => now(), 'updated_at' => now(),
    ], $orderAttrs));

    $stageId = DB::table('order_stages')->insertGetId(array_merge([
        'order_id' => $orderId,
        'stage' => $slug,
        'sequence' => 5,
        'status' => 'in_progress',
        'started_at' => now(),
        'assigned_to' => null,
        'created_at' => now(), 'updated_at' => now(),
    ], $stageAttrs));

    return [$orderId, $stageId];
}

function pat_review(int $orderId, int $stageId, string $decision): void
{
    DB::table('stage_reviews')->insert([
        'order_id' => $orderId, 'order_stage_id' => $stageId,
        'decision' => $decision, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

// ─── Tests ────────────────────────────────────────────────────────

it('returns rich task rows for the role queue', function () {
    [$orderId, $stageId] = pat_makeTask('graphic_artwork', [
        'po_code' => 'ASH-2026-000042',
        'client_name' => 'Nike', 'client_brand' => 'Jordan',
        'total_quantity' => 250, 'shirt_color' => 'Red',
    ]);

    $svc = new PortalAssignmentService();
    $result = $svc->activeTasks(pat_makeUser(), 'graphic_artist');

    expect($result['count'])->toBe(1);
    $row = $result['tasks'][0];
    expect($row['order_stage_id'])->toBe($stageId)
        ->and($row['project_no'])->toBe('ASH-2026-000042')
        ->and($row['client_name'])->toBe('Nike')
        ->and($row['client_brand'])->toBe('Jordan')
        ->and($row['quantity'])->toBe(250)
        ->and($row['color'])->toBe('Red')
        ->and($row['print_area'])->toBe('Front, Back')   // derived from print parts
        ->and($row['rush'])->toBeFalse()
        ->and($row['status'])->toBe('in_progress')
        ->and($row['queue_age_at'])->not->toBeNull();
});

it('pins rush orders to the top, then FIFO (oldest first)', function () {
    // Three graphic_artwork tasks at different ages; the middle one is rush.
    pat_makeTask('graphic_artwork', ['po_code' => 'OLD', 'rush_order' => false],
        ['started_at' => now()->subHours(5)]);
    pat_makeTask('graphic_artwork', ['po_code' => 'RUSH', 'rush_order' => true],
        ['started_at' => now()->subHours(2)]);
    pat_makeTask('graphic_artwork', ['po_code' => 'NEW', 'rush_order' => false],
        ['started_at' => now()->subHour()]);

    $svc = new PortalAssignmentService();
    $tasks = $svc->activeTasks(pat_makeUser(), 'graphic_artist')['tasks'];

    expect(array_column($tasks, 'project_no'))->toBe(['RUSH', 'OLD', 'NEW']);
});

it('treats priority=rush like the rush_order flag', function () {
    pat_makeTask('graphic_artwork', ['po_code' => 'P1', 'priority' => 'rush'],
        ['started_at' => now()->subHour()]);
    pat_makeTask('graphic_artwork', ['po_code' => 'P2'],
        ['started_at' => now()->subHours(3)]);

    $tasks = (new PortalAssignmentService())->activeTasks(pat_makeUser(), 'graphic_artist')['tasks'];
    expect($tasks[0]['project_no'])->toBe('P1')
        ->and($tasks[0]['rush'])->toBeTrue();
});

it('flags a task For Revision when its latest review is a reject', function () {
    [$orderId, $stageId] = pat_makeTask('graphic_artwork');
    pat_review($orderId, $stageId, StageReview::DECISION_REJECT);

    $row = (new PortalAssignmentService())->activeTasks(pat_makeUser(), 'graphic_artist')['tasks'][0];
    expect($row['for_revision'])->toBeTrue()
        ->and($row['display_status'])->toBe('for_revision')
        ->and($row['status'])->toBe('in_progress'); // raw status unchanged (advisory)
});

it('clears For Revision once the latest review is a resubmit', function () {
    [$orderId, $stageId] = pat_makeTask('graphic_artwork');
    pat_review($orderId, $stageId, StageReview::DECISION_REJECT);
    pat_review($orderId, $stageId, StageReview::DECISION_RESUBMIT);

    $row = (new PortalAssignmentService())->activeTasks(pat_makeUser(), 'graphic_artist')['tasks'][0];
    expect($row['for_revision'])->toBeFalse()
        ->and($row['display_status'])->toBe('in_progress');
});

it('excludes completed, on-hold, and another users assigned tasks', function () {
    $me = pat_makeUser('Me');
    pat_makeTask('graphic_artwork', [], ['status' => 'completed']);
    pat_makeTask('graphic_artwork', [], ['status' => 'on_hold']);
    pat_makeTask('graphic_artwork', [], ['assigned_to' => 999]); // someone else
    [, $mine] = pat_makeTask('graphic_artwork', [], ['assigned_to' => $me->id]);

    $tasks = (new PortalAssignmentService())->activeTasks($me, 'graphic_artist')['tasks'];
    expect($tasks)->toHaveCount(1)
        ->and($tasks[0]['order_stage_id'])->toBe($mine);
});

it('counts the whole station for activeCountForRole, ignoring user scoping', function () {
    pat_makeTask('graphic_artwork', [], ['assigned_to' => 1]);
    pat_makeTask('graphic_artwork', [], ['assigned_to' => 2]);
    pat_makeTask('graphic_artwork', [], ['status' => 'pending', 'assigned_to' => null]);
    pat_makeTask('graphic_artwork', [], ['status' => 'completed']); // excluded

    expect((new PortalAssignmentService())->activeCountForRole('graphic_artist'))->toBe(3);
});

it('maps cutter to both sample and mass cutting stages', function () {
    pat_makeTask('sample_cutting', [], ['status' => 'in_progress']);
    pat_makeTask('mass_cutting', [], ['status' => 'pending']);
    pat_makeTask('graphic_artwork', [], ['status' => 'in_progress']); // not cutter

    expect((new PortalAssignmentService())->activeCountForRole('cutter'))->toBe(2);
});

it('falls back to the print_area column when there are no print parts', function () {
    pat_makeTask('graphic_artwork', [
        'print_parts_json' => null,
        'print_area' => 'Full Print',
    ]);

    $row = (new PortalAssignmentService())->activeTasks(pat_makeUser(), 'graphic_artist')['tasks'][0];
    expect($row['print_area'])->toBe('Full Print');
});
