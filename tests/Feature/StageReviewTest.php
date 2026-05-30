<?php

/**
 * CSR Review Hub — StageReviewService tests.
 *
 * Run with:
 *     php artisan test --filter=StageReviewTest
 *
 * Same in-memory SQLite isolation strategy as the Phase 2/4/5 tests:
 * we build only the tables we need and pre-seed Spatie roles so the
 * NotificationService fan-out queries don't throw RoleDoesNotExist.
 *
 * Coverage:
 *   1.  approve() records an 'approve' row; state = 'approved'
 *   2.  reject() with empty comment throws ValidationException
 *   3.  reject() records a 'reject' row + image_path; state = 'rejected';
 *       open_rejection = true
 *   4.  reject() notifies the OWNING role (not managers)
 *   5.  reject() works on an ALREADY-COMPLETED stage (advisory layer) and
 *       does NOT change order_stages.status (decoupled from the engine)
 *   6.  resubmit() with no open rejection throws
 *   7.  resubmit() after a reject records a 'resubmit' row, closes the
 *       rejection (open_rejection = false), state = 'resubmitted'
 *   8.  resubmit() notifies the reviewers (csr + managers)
 *   9.  latestReview() / stateFor() reflect the most recent row through a
 *       full reject → resubmit → approve loop
 *  10.  historyForOrder() groups all rows by stage in chronological order
 */

use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderStage;
use App\Models\StageReview;
use App\Services\NotificationService;
use App\Services\StageReviewService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    foreach ([
        'stage_reviews',
        'stage_audit_logs',
        'notifications',
        'model_has_roles',
        'roles',
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
        // The User model uses SoftDeletes, so Eloquent appends
        // `deleted_at is null` to every User query. The hand-built schema
        // must include this column or User::find() throws
        // "no such column: users.deleted_at".
        $t->softDeletes();
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

    Schema::create('stage_reviews', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->unsignedBigInteger('order_stage_id');
        $t->unsignedBigInteger('actor_user_id');
        $t->string('decision', 16);
        $t->text('comment')->nullable();
        $t->string('image_path')->nullable();
        $t->timestamps();
    });

    // Needed because approve() now advances the workflow via
    // OrderStagesService::markComplete, which writes a stage_audit_logs row.
    Schema::create('stage_audit_logs', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->unsignedBigInteger('order_stage_id');
        $t->unsignedBigInteger('user_id')->nullable();
        $t->string('action', 32);
        $t->string('from_status', 32)->nullable();
        $t->string('to_status', 32)->nullable();
        $t->unsignedBigInteger('duration_seconds')->nullable();
        $t->unsignedBigInteger('business_duration_seconds')->nullable();
        $t->text('notes')->nullable();
        $t->timestamp('created_at')->nullable();
        $t->index(['order_id', 'action']);
        $t->index(['order_stage_id', 'action']);
    });

    Schema::create('roles', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('guard_name')->default('web');
        $t->timestamps();
    });

    Schema::create('model_has_roles', function (Blueprint $t) {
        $t->unsignedBigInteger('role_id');
        $t->string('model_type');
        $t->unsignedBigInteger('model_id');
        $t->primary(['role_id', 'model_id', 'model_type']);
    });

    Schema::create('notifications', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('user_id');
        $t->string('type', 64);
        $t->string('title');
        $t->text('body')->nullable();
        $t->json('data')->nullable();
        $t->timestamp('read_at')->nullable();
        $t->timestamps();
    });

    foreach ([
        'superadmin', 'admin', 'general_manager',
        'csr', 'finance', 'purchasing', 'warehouse_manager',
        'graphic_artist', 'screen_maker', 'sample_maker',
        'cutter', 'printer', 'sewer', 'quality_assurance',
        'packer', 'driver', 'logistics',
    ] as $role) {
        DB::table('roles')->insert([
            'name' => $role, 'guard_name' => 'web',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function () {
    foreach ([
        'stage_reviews', 'stage_audit_logs', 'notifications', 'model_has_roles', 'roles',
        'order_stages', 'orders', 'users',
    ] as $t) {
        Schema::dropIfExists($t);
    }
});

// ---------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------

function sr_makeUser(string $name, ?string $role = null): App\Models\User
{
    $id = DB::table('users')->insertGetId([
        'name' => $name, 'email' => $name . '@example.com', 'password' => 'x',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    if ($role) {
        $roleId = DB::table('roles')->where('name', $role)->value('id');
        DB::table('model_has_roles')->insert([
            'role_id'    => $roleId,
            'model_type' => App\Models\User::class,
            'model_id'   => $id,
        ]);
    }

    return App\Models\User::find($id);
}

function sr_makeStage(string $slug, string $role, string $status = 'in_progress', ?int $assignedTo = null): OrderStage
{
    $order = Order::create([
        'po_code'         => 'ASH-TEST-' . uniqid(),
        'workflow_status' => $slug,
    ]);

    return OrderStage::create([
        'order_id'      => $order->id,
        'stage'         => $slug,
        'sequence'      => 5,
        'status'        => $status,
        'assigned_role' => $role,
        'assigned_to'   => $assignedTo,
    ]);
}

function sr_service(): StageReviewService
{
    return new StageReviewService(
        app(NotificationService::class),
        app(App\Services\OrderStagesService::class),
    );
}

// ---------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------

it('records an approve row, advances the stage, and hides further approval', function () {
    $reviewer = sr_makeUser('csr1', 'csr');
    $stage    = sr_makeStage('graphic_artwork', 'graphic_artist', 'for_approval');

    $review = sr_service()->approve($stage->id, $reviewer, 'looks good');

    expect($review->decision)->toBe(StageReview::DECISION_APPROVE);

    // Approve now ADVANCES the workflow (single source of truth).
    expect($stage->fresh()->status)->toBe('completed');

    $state = sr_service()->stateFor($stage->id);
    expect($state['review_state'])->toBe('approved')
        ->and($state['open_rejection'])->toBeFalse()
        // Already approved + completed → Approve button hidden (no spam).
        ->and($state['can_approve'])->toBeFalse();
});

it('promotes the next stage when approving advances the workflow', function () {
    $reviewer = sr_makeUser('csr1', 'csr');

    $order = Order::create([
        'po_code'         => 'ASH-TEST-' . uniqid(),
        'workflow_status' => 'graphic_artwork',
    ]);
    $s1 = OrderStage::create([
        'order_id' => $order->id, 'stage' => 'graphic_artwork',
        'sequence' => 5, 'status' => 'for_approval',
        'assigned_role' => 'graphic_artist',
    ]);
    $s2 = OrderStage::create([
        'order_id' => $order->id, 'stage' => 'screen_making',
        'sequence' => 6, 'status' => 'pending',
        'assigned_role' => 'screen_maker',
    ]);

    sr_service()->approve($s1->id, $reviewer);

    expect($s1->fresh()->status)->toBe('completed')
        ->and($s2->fresh()->status)->toBe('in_progress');
});

it('does not advance when approving an already-completed stage', function () {
    $reviewer = sr_makeUser('csr1', 'csr');
    $stage    = sr_makeStage('graphic_artwork', 'graphic_artist', 'completed');

    // Late sign-off: records the review row but leaves the engine alone.
    sr_service()->approve($stage->id, $reviewer, 'retroactive ok');

    expect($stage->fresh()->status)->toBe('completed')
        ->and(sr_service()->stateFor($stage->id)['review_state'])->toBe('approved');
});

it('rejects an empty comment', function () {
    $reviewer = sr_makeUser('csr1', 'csr');
    $stage    = sr_makeStage('graphic_artwork', 'graphic_artist');

    sr_service()->reject($stage->id, $reviewer, '   ');
})->throws(ValidationException::class);

it('records a reject row with image and opens a rejection', function () {
    $reviewer = sr_makeUser('csr1', 'csr');
    $stage    = sr_makeStage('graphic_artwork', 'graphic_artist');

    $review = sr_service()->reject($stage->id, $reviewer, 'wrong pantone', 'stage-reviews/x.png');

    expect($review->decision)->toBe(StageReview::DECISION_REJECT)
        ->and($review->image_path)->toBe('stage-reviews/x.png');

    $state = sr_service()->stateFor($stage->id);
    expect($state['review_state'])->toBe('rejected')
        ->and($state['open_rejection'])->toBeTrue();
});

it('notifies the owning role on reject, not the managers', function () {
    $reviewer = sr_makeUser('csr1', 'csr');
    $artist   = sr_makeUser('artist1', 'graphic_artist');
    $manager  = sr_makeUser('gm1', 'general_manager');
    $stage    = sr_makeStage('graphic_artwork', 'graphic_artist');

    sr_service()->reject($stage->id, $reviewer, 'wrong pantone');

    expect(Notification::where('user_id', $artist->id)->where('type', 'stage.rejected')->count())->toBe(1)
        ->and(Notification::where('user_id', $manager->id)->count())->toBe(0);
});

it('allows rejecting an already-completed stage without touching its status', function () {
    $reviewer = sr_makeUser('csr1', 'csr');
    $stage    = sr_makeStage('graphic_artwork', 'graphic_artist', 'completed');

    sr_service()->reject($stage->id, $reviewer, 'found a defect later');

    // Advisory layer: order_stages.status is untouched.
    expect($stage->fresh()->status)->toBe('completed')
        ->and(sr_service()->hasOpenRejection($stage->id))->toBeTrue();
});

it('refuses resubmit when there is no open rejection', function () {
    $owner = sr_makeUser('artist1', 'graphic_artist');
    $stage = sr_makeStage('graphic_artwork', 'graphic_artist');

    sr_service()->resubmit($stage->id, $owner, 'fixed');
})->throws(ValidationException::class);

it('closes the rejection on resubmit and reports resubmitted state', function () {
    $reviewer = sr_makeUser('csr1', 'csr');
    $owner    = sr_makeUser('artist1', 'graphic_artist');
    $stage    = sr_makeStage('graphic_artwork', 'graphic_artist');

    sr_service()->reject($stage->id, $reviewer, 'wrong pantone');
    $resub = sr_service()->resubmit($stage->id, $owner, 'corrected pantone');

    expect($resub->decision)->toBe(StageReview::DECISION_RESUBMIT);

    $state = sr_service()->stateFor($stage->id);
    expect($state['review_state'])->toBe('resubmitted')
        ->and($state['open_rejection'])->toBeFalse();
});

it('notifies reviewers on resubmit', function () {
    $reviewer = sr_makeUser('csr1', 'csr');
    $owner    = sr_makeUser('artist1', 'graphic_artist');
    $stage    = sr_makeStage('graphic_artwork', 'graphic_artist');

    sr_service()->reject($stage->id, $reviewer, 'wrong pantone');
    sr_service()->resubmit($stage->id, $owner, 'corrected');

    expect(Notification::where('user_id', $reviewer->id)->where('type', 'stage.resubmitted')->count())->toBe(1);
});

it('tracks the latest row through a full reject → resubmit → approve loop', function () {
    $reviewer = sr_makeUser('csr1', 'csr');
    $owner    = sr_makeUser('artist1', 'graphic_artist');
    $stage    = sr_makeStage('graphic_artwork', 'graphic_artist');

    sr_service()->reject($stage->id, $reviewer, 'v1 bad');
    expect(sr_service()->stateFor($stage->id)['review_state'])->toBe('rejected');

    sr_service()->resubmit($stage->id, $owner, 'v2');
    expect(sr_service()->stateFor($stage->id)['review_state'])->toBe('resubmitted');

    sr_service()->approve($stage->id, $reviewer, 'v2 good');
    $state = sr_service()->stateFor($stage->id);
    expect($state['review_state'])->toBe('approved')
        ->and($state['open_rejection'])->toBeFalse();
});

it('groups history by stage in chronological order', function () {
    $reviewer = sr_makeUser('csr1', 'csr');
    $owner    = sr_makeUser('artist1', 'graphic_artist');
    $stage    = sr_makeStage('graphic_artwork', 'graphic_artist');

    sr_service()->reject($stage->id, $reviewer, 'bad');
    sr_service()->resubmit($stage->id, $owner, 'fixed');
    sr_service()->approve($stage->id, $reviewer, 'good');

    $history = sr_service()->historyForOrder($stage->order_id);
    $rows = $history[$stage->id];

    expect($rows)->toHaveCount(3)
        ->and($rows[0]['decision'])->toBe('reject')
        ->and($rows[1]['decision'])->toBe('resubmit')
        ->and($rows[2]['decision'])->toBe('approve');
});