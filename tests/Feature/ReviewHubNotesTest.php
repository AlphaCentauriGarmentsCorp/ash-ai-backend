<?php

use App\Models\Order;
use App\Models\OrderStage;
use App\Models\StageReview;
use App\Models\User;
use App\Services\StageReviewService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * ReviewHubNotesTest — the Review Hub is a notes-only surface: staff append
 * freeform notes (decision = 'note') to a stage's record.
 *
 * Covered:
 *   1. note() records an append-only row with actor + comment, and it flows
 *      through historyForOrder;
 *   2. empty / whitespace comments are rejected;
 *   3. notes NEVER touch the decision state machine — a note posted after a
 *      reject leaves the rejection OPEN (portal banners unaffected), and a
 *      note on an unreviewed stage keeps review_state 'none'.
 *
 * Hand-built minimal schema (no RefreshDatabase).
 */

$TABLES = ['stage_reviews', 'order_stages', 'orders', 'users', 'notifications',
    'model_has_roles', 'roles'];

beforeEach(function () use ($TABLES) {
    foreach ($TABLES as $t) {
        Schema::dropIfExists($t);
    }

    Schema::create('users', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('email')->unique();
        $t->string('password')->default('hashed');
        $t->timestamps();
        $t->softDeletes();
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
    Schema::create('orders', function (Blueprint $t) {
        $t->id();
        $t->string('po_code')->unique();
        $t->unsignedBigInteger('client_id')->nullable();
        $t->string('status')->default('Pending Approval');
        $t->string('workflow_status', 32)->nullable();
        $t->unsignedBigInteger('current_stage_id')->nullable();
        $t->timestamp('delayed_at')->nullable();
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
        $t->unsignedBigInteger('actor_user_id')->nullable();
        $t->string('decision', 16);
        $t->text('comment')->nullable();
        $t->string('image_path')->nullable();
        $t->timestamps();
    });
});

afterEach(function () use ($TABLES) {
    foreach ($TABLES as $t) {
        Schema::dropIfExists($t);
    }
});

// ---------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------

function rhnOrderStage(string $status = 'in_progress'): OrderStage
{
    $order = Order::create([
        'po_code' => 'ASH-RHN-' . uniqid(),
        'status'  => 'Pending Approval',
    ]);

    return OrderStage::create([
        'order_id' => $order->id,
        'stage'    => 'graphic_artwork',
        'sequence' => 5,
        'status'   => $status,
    ]);
}

function rhnUser(string $name = 'Staff One'): User
{
    return User::create([
        'name'  => $name,
        'email' => uniqid() . '@x.test',
    ]);
}

function rhnSvc(): StageReviewService
{
    return app(StageReviewService::class);
}

// ---------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------

it('records an append-only note with actor and surfaces it in the history', function () {
    $stage = rhnOrderStage();
    $user  = rhnUser('C. S. Rep');

    $note = rhnSvc()->note($stage->id, $user, '  Fabric supplier confirmed the dye lot.  ');

    expect($note->decision)->toBe(StageReview::DECISION_NOTE)
        ->and($note->comment)->toBe('Fabric supplier confirmed the dye lot.')
        ->and($note->actor->name)->toBe('C. S. Rep');

    $history = rhnSvc()->historyForOrder($stage->order_id);
    expect($history)->toHaveKey($stage->id)
        ->and($history[$stage->id])->toHaveCount(1)
        ->and($history[$stage->id][0]['decision'])->toBe('note');
});

it('rejects an empty or whitespace-only note', function () {
    $stage = rhnOrderStage();

    rhnSvc()->note($stage->id, rhnUser(), '   ');
})->throws(ValidationException::class);

it('never closes an open rejection — a later note leaves the banner state intact', function () {
    $stage = rhnOrderStage();
    $user  = rhnUser();

    StageReview::create([
        'order_id'       => $stage->order_id,
        'order_stage_id' => $stage->id,
        'actor_user_id'  => $user->id,
        'decision'       => StageReview::DECISION_REJECT,
        'comment'        => 'Wrong pantone.',
    ]);

    rhnSvc()->note($stage->id, $user, 'Called the client about the shade.');

    $state = rhnSvc()->stateFor($stage->id);
    expect($state['review_state'])->toBe('rejected')
        ->and($state['open_rejection'])->toBeTrue()
        ->and(rhnSvc()->hasOpenRejection($stage->id))->toBeTrue();
});

it('keeps review_state none when the only rows are notes', function () {
    $stage = rhnOrderStage();

    rhnSvc()->note($stage->id, rhnUser(), 'First look — sizes chart double-checked.');

    $state = rhnSvc()->stateFor($stage->id);
    expect($state['review_state'])->toBe('none')
        ->and($state['open_rejection'])->toBeFalse();
});
