<?php

/**
 * Phase 7-B Bundle 1 — QA/Packer Portal tests.
 *
 * Run with:
 *   php artisan test --filter=QaPackerPortalTest
 *
 * Coverage:
 *   1. buildContext() returns full payload for an active quality_control stage
 *   2. buildContext() returns full payload for an active packing stage
 *   3. buildContext() rejects stages outside QA/Packer scope (e.g., quotation)
 *   4. buildContext() rejects a stage that doesn't exist
 *   5. RejectLogService creates a reject row with disposition='reject'
 *   6. RejectLogService creates a repair row with disposition='repair'
 *   7. RejectLogService rejects writes to non-QA stages
 *   8. RejectLogService rejects writes to a completed stage
 *   9. RejectLogService prevents deletion by another user
 *  10. computeRejectSummary tallies only disposition=reject (repairs excluded)
 *  11. Submit advances quality_control → packing
 *  12. Submit returns exceeds_threshold=true when rejects ≥5 pcs
 *  13. Submit returns exceeds_threshold=true when rejects ≥10% of order qty
 *  14. Submit returns exceeds_threshold=false when both thresholds clear
 *  15. NotificationSetting::getValue returns default when key missing
 *  16. NotificationSetting::setValue upserts the row
 *  17. OrderPackingBox::totalPieces() sums qty across contents_json
 */

use App\Models\NotificationSetting;
use App\Models\Order;
use App\Models\OrderPackingBox;
use App\Models\OrderStage;
use App\Models\PackingChecklistItem;
use App\Models\QaChecklistItem;
use App\Models\RejectReason;
use App\Models\StageRejectLog;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\OrderStagesService;
use App\Services\QaPackerPortalService;
use App\Services\QaPackerSubmitService;
use App\Services\RejectLogService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    // Drop in reverse-dependency order. Lookups + new tables last.
    foreach ([
        'stage_audit_logs',
        'stage_reject_logs',
        'order_packing_boxes',
        'reject_reasons',
        'qa_checklist_items',
        'packing_checklist_items',
        'notification_settings',
        'notifications',
        'order_design_files',
        'stage_sample_uploads',
        'model_has_permissions',
        'role_has_permissions',
        'model_has_roles',
        'permissions',
        'roles',
        'order_stages',
        'orders',
        'users',
        'qa_packer_task_completions',
    ] as $t) {
        Schema::dropIfExists($t);
    }

    Schema::create('users', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('email')->unique();
        $t->string('password')->default('x');
        $t->timestamps();
        $t->softDeletes(); // User model uses SoftDeletes
    });

    Schema::create('orders', function (Blueprint $t) {
        $t->id();
        $t->string('po_code')->unique();
        $t->string('client_name')->nullable();
        $t->string('client_brand')->nullable();
        $t->string('shirt_color', 64)->nullable();
        $t->string('special_print', 64)->nullable();
        $t->text('items_json')->nullable();
        $t->date('deadline')->nullable();
        $t->string('priority', 16)->default('normal');
        $t->boolean('rush_order')->default(false);
        $t->text('notes')->nullable();
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
    });

    // Reference-image source tables — QaPackerPortalService::referenceImages
    // reads from these. Minimal columns to cover the read path.
    Schema::create('order_design_files', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->unsignedBigInteger('order_design_id')->nullable();
        $t->string('kind', 32);
        $t->unsignedInteger('version')->default(1);
        $t->string('file_path');
        $t->string('original_name')->nullable();
        $t->string('mime_type', 64)->nullable();
        $t->unsignedBigInteger('size_bytes')->nullable();
        $t->boolean('is_latest')->default(true);
        $t->unsignedBigInteger('uploaded_by_user_id')->nullable();
        $t->text('notes')->nullable();
        $t->timestamps();
    });

    Schema::create('stage_sample_uploads', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->unsignedBigInteger('order_stage_id');
        $t->unsignedBigInteger('uploaded_by_user_id')->nullable();
        $t->string('photo_front_path')->nullable();
        $t->string('photo_back_path')->nullable();
        $t->text('remarks')->nullable();
        $t->string('sample_status', 16)->default('for_approval');
        $t->timestamp('completed_at')->nullable();
        $t->timestamps();
    });

    // Phase 7-B Bundle 1 tables ───────────────────────────────────

    Schema::create('reject_reasons', function (Blueprint $t) {
        $t->id();
        $t->string('slug', 64)->unique();
        $t->string('label', 128);
        $t->boolean('is_fabric')->default(false);
        $t->unsignedSmallInteger('display_order')->default(0);
        $t->boolean('active')->default(true);
        $t->timestamps();
    });

    Schema::create('qa_checklist_items', function (Blueprint $t) {
        $t->id();
        $t->string('slug', 64)->unique();
        $t->string('label', 128);
        $t->unsignedSmallInteger('display_order')->default(0);
        $t->boolean('active')->default(true);
        $t->timestamps();
    });

    Schema::create('packing_checklist_items', function (Blueprint $t) {
        $t->id();
        $t->string('slug', 64)->unique();
        $t->string('label', 128);
        $t->unsignedSmallInteger('display_order')->default(0);
        $t->boolean('active')->default(true);
        $t->timestamps();
    });

    Schema::create('stage_reject_logs', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->unsignedBigInteger('order_stage_id');
        $t->unsignedBigInteger('logged_by_user_id');
        $t->integer('quantity_pcs');
        $t->enum('disposition', ['reject', 'repair'])->default('reject');
        $t->unsignedBigInteger('reject_reason_id')->nullable();
        $t->string('photo_path')->nullable();
        $t->text('notes')->nullable();
        $t->timestamps();
    });

    Schema::create('order_packing_boxes', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->unsignedSmallInteger('box_number');
        $t->string('qr_code', 64)->unique();
        $t->text('contents_json')->nullable();
        $t->decimal('weight_kg', 6, 2)->nullable();
        $t->timestamp('sealed_at')->nullable();
        $t->unsignedBigInteger('sealed_by_user_id')->nullable();
        $t->timestamps();
    });

    Schema::create('qa_packer_task_completions', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->unsignedBigInteger('order_stage_id');
        $t->unsignedBigInteger('submitted_by_user_id');
        $t->json('checklist_state_json')->nullable();
        $t->json('final_photos_json')->nullable();
        $t->json('reject_summary_json')->nullable();
        $t->text('notes')->nullable();
        $t->timestamp('submitted_at')->nullable();
        $t->timestamps();
    });

    Schema::create('notification_settings', function (Blueprint $t) {
        $t->id();
        $t->string('key', 128)->unique();
        $t->text('value_json');
        $t->string('description')->nullable();
        $t->timestamps();
    });

    // Notifications table — used by NotificationService.
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

    // Spatie permission tables (minimal).
    Schema::create('roles', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('guard_name')->default('web');
        $t->timestamps();
    });

    Schema::create('permissions', function (Blueprint $t) {
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

    Schema::create('model_has_permissions', function (Blueprint $t) {
        $t->unsignedBigInteger('permission_id');
        $t->string('model_type');
        $t->unsignedBigInteger('model_id');
        $t->primary(['permission_id', 'model_id', 'model_type']);
    });

    Schema::create('role_has_permissions', function (Blueprint $t) {
        $t->unsignedBigInteger('permission_id');
        $t->unsignedBigInteger('role_id');
        $t->primary(['permission_id', 'role_id']);
    });

    // Seed the canonical lookup data the service depends on.
    (new Database\Seeders\RejectReasonSeeder())->run();
    (new Database\Seeders\QaChecklistItemSeeder())->run();
    (new Database\Seeders\PackingChecklistItemSeeder())->run();
    (new Database\Seeders\NotificationSettingsSeeder())->run();
});

afterEach(function () {
    foreach ([
        'notifications',
        'notification_settings',
        'order_packing_boxes',
        'stage_reject_logs',
        'packing_checklist_items',
        'qa_checklist_items',
        'reject_reasons',
        'stage_sample_uploads',
        'order_design_files',
        'stage_audit_logs',
        'model_has_permissions',
        'role_has_permissions',
        'model_has_roles',
        'permissions',
        'roles',
        'order_stages',
        'orders',
        'users',
        'qa_packer_task_completions',
    ] as $t) {
        Schema::dropIfExists($t);
    }
});

// ─── Helpers ──────────────────────────────────────────────────────

function phase7b_makeUser(string $name = 'QA User'): User
{
    $id = DB::table('users')->insertGetId([
        'name' => $name,
        'email' => strtolower(str_replace(' ', '', $name)) . uniqid() . '@example.com',
        'password' => 'x',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    return User::find($id);
}

/**
 * Create an order with two stages — quality_control (sequence=10, in_progress)
 * and packing (sequence=11, pending). Used as the canonical fixture.
 */
function phase7b_makeOrderWithQaAndPackingStages(int $totalQty = 100): array
{
    $orderId = DB::table('orders')->insertGetId([
        'po_code' => 'ASH-QA-' . uniqid(),
        'client_name' => 'Test Client',
        'client_brand' => 'Sorbetes',
        'shirt_color' => 'Black',
        'special_print' => 'Silkscreen',
        'items_json' => json_encode([
            ['size' => 'M', 'quantity' => intdiv($totalQty, 2)],
            ['size' => 'L', 'quantity' => $totalQty - intdiv($totalQty, 2)],
        ]),
        'deadline' => now()->addDays(7)->toDateString(),
        'priority' => 'normal',
        'rush_order' => false,
        'workflow_status' => 'mass_qa',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $qaStageId = DB::table('order_stages')->insertGetId([
        'order_id' => $orderId,
        'stage' => 'mass_qa',
        'sequence' => 10,
        'status' => 'in_progress',
        'started_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $packingStageId = DB::table('order_stages')->insertGetId([
        'order_id' => $orderId,
        'stage' => 'mass_packing',
        'sequence' => 11,
        'status' => 'pending',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [
        'order_id'          => $orderId,
        'qa_stage_id'       => $qaStageId,
        'packing_stage_id'  => $packingStageId,
        'order'             => Order::find($orderId),
        'qa_stage'          => OrderStage::find($qaStageId),
        'packing_stage'     => OrderStage::find($packingStageId),
    ];
}

// Build the service the same way the controller does — DI through the container.
function phase7b_portalService(): QaPackerPortalService
{
    return new QaPackerPortalService();
}

function phase7b_submitService(): QaPackerSubmitService
{
    return new QaPackerSubmitService(
        app(OrderStagesService::class),
        app(NotificationService::class),
    );
}

function phase7b_rejectService(): RejectLogService
{
    return new RejectLogService(app(NotificationService::class));
}

// ─── QaPackerPortalService tests ──────────────────────────────────

it('builds full context for an active mass_qa stage', function () {
    $made = phase7b_makeOrderWithQaAndPackingStages();
    $ctx = phase7b_portalService()->buildContext($made['qa_stage_id']);

    expect($ctx)->toHaveKeys([
        'task',
        'reference_images',
        'qa_checklist',
        'packing_checklist',
        'reject_reasons',
        'rejects_repairs',
        'packing_boxes',
        'activity_log',
    ]);

    expect($ctx['task']['stage'])->toBe('mass_qa')
        ->and($ctx['task']['stage_status'])->toBe('in_progress')
        ->and($ctx['task']['total_pcs'])->toBe(100)
        ->and($ctx['task']['client_name'])->toBe('Test Client')
        ->and($ctx['task']['client_brand'])->toBe('Sorbetes');

    expect($ctx['qa_checklist'])->toHaveCount(7);
    expect($ctx['packing_checklist'])->toHaveCount(7);
    expect($ctx['reject_reasons'])->toHaveCount(7);

    // The 7 QA checklist slugs come back in display order.
    $qaSlugs = array_column($ctx['qa_checklist'], 'slug');
    expect($qaSlugs[0])->toBe('correct_print');
    expect($qaSlugs[6])->toBe('correct_quantity');
});

it('builds full context for an active mass_packing stage', function () {
    $made = phase7b_makeOrderWithQaAndPackingStages();
    DB::table('order_stages')->where('id', $made['qa_stage_id'])
        ->update(['status' => 'completed', 'completed_at' => now()]);
    DB::table('order_stages')->where('id', $made['packing_stage_id'])
        ->update(['status' => 'in_progress', 'started_at' => now()]);

    $ctx = phase7b_portalService()->buildContext($made['packing_stage_id']);

    expect($ctx['task']['stage'])->toBe('mass_packing')
        ->and($ctx['task']['stage_status'])->toBe('in_progress');
});

it('rejects stages outside QA/Packer scope', function () {
    $orderId = DB::table('orders')->insertGetId([
        'po_code' => 'ASH-X-' . uniqid(),
        'items_json' => json_encode([['size' => 'M', 'quantity' => 10]]),
        'workflow_status' => 'graphic_artwork',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $stageId = DB::table('order_stages')->insertGetId([
        'order_id' => $orderId,
        'stage' => 'graphic_artwork',
        'sequence' => 2,
        'status' => 'in_progress',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(fn () => phase7b_portalService()->buildContext($stageId))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('rejects a stage that does not exist', function () {
    expect(fn () => phase7b_portalService()->buildContext(99999))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('includes mockup + approved-sample images in the reference_images gallery', function () {
    $made = phase7b_makeOrderWithQaAndPackingStages();

    // One latest front-mockup design file.
    DB::table('order_design_files')->insert([
        'order_id'     => $made['order_id'],
        'kind'         => 'front_mockup',
        'version'      => 1,
        'file_path'    => 'design/front-mockup.png',
        'is_latest'    => true,
        'created_at'   => now(), 'updated_at' => now(),
    ]);
    // A non-mockup file that should NOT appear (color_separation is not in the kind filter).
    DB::table('order_design_files')->insert([
        'order_id'     => $made['order_id'],
        'kind'         => 'color_separation',
        'version'      => 1,
        'file_path'    => 'design/color-sep.png',
        'is_latest'    => true,
        'created_at'   => now(), 'updated_at' => now(),
    ]);

    // One approved sample with both photos.
    DB::table('stage_sample_uploads')->insert([
        'order_id'        => $made['order_id'],
        'order_stage_id'  => $made['qa_stage_id'],
        'photo_front_path'=> 'samples/front.jpg',
        'photo_back_path' => 'samples/back.jpg',
        'sample_status'   => 'approved',
        'created_at'      => now(), 'updated_at' => now(),
    ]);
    // A for_approval (not approved) sample that should be excluded.
    DB::table('stage_sample_uploads')->insert([
        'order_id'        => $made['order_id'],
        'order_stage_id'  => $made['qa_stage_id'],
        'photo_front_path'=> 'samples/pending.jpg',
        'sample_status'   => 'for_approval',
        'created_at'      => now(), 'updated_at' => now(),
    ]);

    $ctx = phase7b_portalService()->buildContext($made['qa_stage_id']);

    // Expect: 1 mockup + 2 sample photos (front + back) = 3 entries.
    expect($ctx['reference_images'])->toHaveCount(3);

    $kinds = array_column($ctx['reference_images'], 'kind');
    expect($kinds)->toContain('mockup')->toContain('sample');

    $urls = array_column($ctx['reference_images'], 'url');
    expect($urls)->toContain('design/front-mockup.png')
        ->toContain('samples/front.jpg')
        ->toContain('samples/back.jpg')
        ->not->toContain('design/color-sep.png')   // wrong kind, excluded
        ->not->toContain('samples/pending.jpg');   // not approved, excluded
});

// ─── RejectLogService tests ───────────────────────────────────────

it('creates a reject row with disposition=reject', function () {
    $made = phase7b_makeOrderWithQaAndPackingStages();
    $user = phase7b_makeUser();
    $reason = RejectReason::where('slug', 'stain')->first();

    $log = phase7b_rejectService()->create([
        'order_id'         => $made['order_id'],
        'order_stage_id'   => $made['qa_stage_id'],
        'disposition'      => 'reject',
        'reject_reason_id' => $reason->id,
        'quantity_pcs'     => 3,
        'notes'            => 'Test reject',
    ], $user);

    expect($log->disposition)->toBe('reject')
        ->and($log->quantity_pcs)->toBe(3)
        ->and($log->reject_reason_id)->toBe($reason->id)
        ->and($log->logged_by_user_id)->toBe($user->id);
});

it('creates a repair row with disposition=repair', function () {
    $made = phase7b_makeOrderWithQaAndPackingStages();
    $user = phase7b_makeUser();
    $reason = RejectReason::where('slug', 'print_issue')->first();

    $log = phase7b_rejectService()->create([
        'order_id'         => $made['order_id'],
        'order_stage_id'   => $made['qa_stage_id'],
        'disposition'      => 'repair',
        'reject_reason_id' => $reason->id,
        'quantity_pcs'     => 2,
        'notes'            => 'Loose stitching, fixable',
    ], $user);

    expect($log->disposition)->toBe('repair')
        ->and($log->isRepair())->toBeTrue()
        ->and($log->isReject())->toBeFalse();
});

it('rejects writes to non-QA stages', function () {
    $orderId = DB::table('orders')->insertGetId([
        'po_code' => 'ASH-NQ-' . uniqid(),
        'items_json' => json_encode([['size' => 'M', 'quantity' => 10]]),
        'workflow_status' => 'sample_cutting',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $stageId = DB::table('order_stages')->insertGetId([
        'order_id' => $orderId,
        'stage' => 'sample_cutting',
        'sequence' => 7,
        'status' => 'in_progress',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $reason = RejectReason::where('slug', 'damaged')->first();
    $user = phase7b_makeUser();

    expect(fn () => phase7b_rejectService()->create([
        'order_id'         => $orderId,
        'order_stage_id'   => $stageId,
        'disposition'      => 'reject',
        'reject_reason_id' => $reason->id,
        'quantity_pcs'     => 1,
    ], $user))->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('rejects writes to a completed stage', function () {
    $made = phase7b_makeOrderWithQaAndPackingStages();
    DB::table('order_stages')->where('id', $made['qa_stage_id'])
        ->update(['status' => 'completed', 'completed_at' => now()]);

    $reason = RejectReason::where('slug', 'stain')->first();
    $user = phase7b_makeUser();

    expect(fn () => phase7b_rejectService()->create([
        'order_id'         => $made['order_id'],
        'order_stage_id'   => $made['qa_stage_id'],
        'disposition'      => 'reject',
        'reject_reason_id' => $reason->id,
        'quantity_pcs'     => 1,
    ], $user))->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('prevents deletion of a reject log by another user', function () {
    $made = phase7b_makeOrderWithQaAndPackingStages();
    $original = phase7b_makeUser('Original Logger');
    $other = phase7b_makeUser('Some Other User');
    $reason = RejectReason::where('slug', 'wrong_size')->first();

    $log = phase7b_rejectService()->create([
        'order_id'         => $made['order_id'],
        'order_stage_id'   => $made['qa_stage_id'],
        'disposition'      => 'reject',
        'reject_reason_id' => $reason->id,
        'quantity_pcs'     => 1,
    ], $original);

    expect(fn () => phase7b_rejectService()->delete($log->id, $other))
        ->toThrow(\Illuminate\Validation\ValidationException::class);

    // Original user can delete it themselves.
    phase7b_rejectService()->delete($log->id, $original);
    expect(StageRejectLog::find($log->id))->toBeNull();
});

// ─── Threshold + submit tests ─────────────────────────────────────

it('tallies only disposition=reject when computing threshold (repairs excluded)', function () {
    $made = phase7b_makeOrderWithQaAndPackingStages(totalQty: 100);
    $user = phase7b_makeUser();
    $rs = phase7b_rejectService();

    $rs->create([
        'order_id' => $made['order_id'], 'order_stage_id' => $made['qa_stage_id'],
        'disposition' => 'reject', 'reject_reason_id' => RejectReason::where('slug', 'stain')->first()->id,
        'quantity_pcs' => 3,
    ], $user);
    $rs->create([
        'order_id' => $made['order_id'], 'order_stage_id' => $made['qa_stage_id'],
        'disposition' => 'repair', 'reject_reason_id' => RejectReason::where('slug', 'print_issue')->first()->id,
        'quantity_pcs' => 20,
    ], $user);

    // Use reflection to test the protected method directly.
    $submit = phase7b_submitService();
    $ref = new ReflectionMethod($submit, 'computeRejectSummary');
    $ref->setAccessible(true);
    $summary = $ref->invoke($submit, $made['qa_stage'], $made['order']);

    expect($summary['total_pcs'])->toBe(3)
        ->and($summary['pct'])->toBe(0.03)
        ->and($summary['exceeds_threshold'])->toBeFalse(); // 3 < 5 and 3% < 10%
});

it('returns exceeds_threshold=true when rejects ≥5 pcs', function () {
    $made = phase7b_makeOrderWithQaAndPackingStages(totalQty: 1000); // big order, low %
    $user = phase7b_makeUser();

    phase7b_rejectService()->create([
        'order_id' => $made['order_id'], 'order_stage_id' => $made['qa_stage_id'],
        'disposition' => 'reject', 'reject_reason_id' => RejectReason::where('slug', 'damaged')->first()->id,
        'quantity_pcs' => 5,
    ], $user);

    $submit = phase7b_submitService();
    $ref = new ReflectionMethod($submit, 'computeRejectSummary');
    $ref->setAccessible(true);
    $summary = $ref->invoke($submit, $made['qa_stage'], $made['order']);

    expect($summary['total_pcs'])->toBe(5)
        ->and($summary['pct'])->toBe(0.005) // 5/1000
        ->and($summary['exceeds_threshold'])->toBeTrue(); // 5 ≥ 5
});

it('returns exceeds_threshold=true when rejects ≥10% of order qty', function () {
    $made = phase7b_makeOrderWithQaAndPackingStages(totalQty: 20); // small order
    $user = phase7b_makeUser();

    phase7b_rejectService()->create([
        'order_id' => $made['order_id'], 'order_stage_id' => $made['qa_stage_id'],
        'disposition' => 'reject', 'reject_reason_id' => RejectReason::where('slug', 'fabric_issue')->first()->id,
        'quantity_pcs' => 3, // < 5 pcs but 15% of qty
    ], $user);

    $submit = phase7b_submitService();
    $ref = new ReflectionMethod($submit, 'computeRejectSummary');
    $ref->setAccessible(true);
    $summary = $ref->invoke($submit, $made['qa_stage'], $made['order']);

    expect($summary['total_pcs'])->toBe(3)
        ->and($summary['pct'])->toBe(0.15)
        ->and($summary['exceeds_threshold'])->toBeTrue();
});

it('returns exceeds_threshold=false when both thresholds clear', function () {
    $made = phase7b_makeOrderWithQaAndPackingStages(totalQty: 200);
    $user = phase7b_makeUser();

    phase7b_rejectService()->create([
        'order_id' => $made['order_id'], 'order_stage_id' => $made['qa_stage_id'],
        'disposition' => 'reject', 'reject_reason_id' => RejectReason::where('slug', 'stain')->first()->id,
        'quantity_pcs' => 4, // < 5 pcs AND 2% < 10%
    ], $user);

    $submit = phase7b_submitService();
    $ref = new ReflectionMethod($submit, 'computeRejectSummary');
    $ref->setAccessible(true);
    $summary = $ref->invoke($submit, $made['qa_stage'], $made['order']);

    expect($summary['exceeds_threshold'])->toBeFalse();
});

it('submit advances mass_qa → mass_packing', function () {
    $made = phase7b_makeOrderWithQaAndPackingStages();
    $user = phase7b_makeUser();

    $result = phase7b_submitService()->submit(
        $made['qa_stage_id'],
        [
            'qa_checklist_state' => ['correct_print' => true, 'correct_size' => true],
            'packing_checklist_state' => [],
            'notes' => 'QA complete',
        ],
        $user,
    );

    expect($result['stage'])->toBe('mass_qa')
        ->and($result['new_stage'])->toBe('mass_packing');

    $qa = OrderStage::find($made['qa_stage_id']);
    expect($qa->status)->toBe('completed');

    $packing = OrderStage::find($made['packing_stage_id']);
    expect($packing->status)->toBe('in_progress');
});

// ─── NotificationSetting tests ────────────────────────────────────

it('NotificationSetting::getValue returns the seeded value', function () {
    expect(NotificationSetting::getValue('qa_reject_alert_threshold_pcs'))->toBe(5)
        ->and(NotificationSetting::getValue('qa_reject_alert_threshold_pct'))->toBe(0.10);
});

it('NotificationSetting::getValue returns default when key missing', function () {
    expect(NotificationSetting::getValue('does_not_exist', 'fallback'))->toBe('fallback')
        ->and(NotificationSetting::getValue('also_missing', 42))->toBe(42);
});

it('NotificationSetting::setValue upserts a row', function () {
    NotificationSetting::setValue('test_key', ['a' => 1, 'b' => 2], 'a test');
    expect(NotificationSetting::getValue('test_key'))->toBe(['a' => 1, 'b' => 2]);

    // Upsert — same key, new value.
    NotificationSetting::setValue('test_key', 'new value');
    expect(NotificationSetting::getValue('test_key'))->toBe('new value');

    // Only one row exists.
    expect(NotificationSetting::where('key', 'test_key')->count())->toBe(1);
});

// ─── OrderPackingBox tests ────────────────────────────────────────

it('OrderPackingBox::totalPieces sums qty across contents_json', function () {
    $made = phase7b_makeOrderWithQaAndPackingStages();

    $box = OrderPackingBox::create([
        'order_id'      => $made['order_id'],
        'box_number'    => 1,
        'qr_code'       => 'ASH-PO-2026-000001-BOX-01',
        'contents_json' => [
            ['size' => 'S', 'sku' => 'SKU-S', 'qty' => 10],
            ['size' => 'M', 'sku' => 'SKU-M', 'qty' => 15],
            ['size' => 'L', 'sku' => 'SKU-L', 'qty' => 25],
        ],
    ]);

    expect($box->totalPieces())->toBe(50);
});

it('OrderPackingBox::totalPieces handles null/empty contents_json', function () {
    $made = phase7b_makeOrderWithQaAndPackingStages();
    $box = OrderPackingBox::create([
        'order_id'   => $made['order_id'],
        'box_number' => 1,
        'qr_code'    => 'ASH-PO-2026-000001-BOX-01',
    ]);
    expect($box->totalPieces())->toBe(0);
});

it('OrderPackingBox::isSealed reflects sealed_at presence', function () {
    $made = phase7b_makeOrderWithQaAndPackingStages();

    $b1 = OrderPackingBox::create([
        'order_id' => $made['order_id'], 'box_number' => 1,
        'qr_code' => 'ASH-PO-2026-000001-BOX-01',
    ]);
    expect($b1->isSealed())->toBeFalse();

    $b2 = OrderPackingBox::create([
        'order_id' => $made['order_id'], 'box_number' => 2,
        'qr_code' => 'ASH-PO-2026-000001-BOX-02',
        'sealed_at' => now(),
    ]);
    expect($b2->isSealed())->toBeTrue();
});

// ─── Bundle 4a — BoxQrCodeService tests ───────────────────────────

it('auto-creates box #1 with contents derived from items_json', function () {
    $made = phase7b_makeOrderWithQaAndPackingStages(totalQty: 100);
    $user = phase7b_makeUser('Packer User');

    $service = new \App\Services\BoxQrCodeService();
    $box = $service->ensureFirstBox($made['order_id'], $user);

    expect($box->box_number)->toBe(1)
        ->and($box->qr_code)->toContain('BOX-01')
        ->and($box->totalPieces())->toBe(100)   // sum of M=50 + L=50 from fixture
        ->and($box->isSealed())->toBeFalse();
});

it('ensureFirstBox is idempotent', function () {
    $made = phase7b_makeOrderWithQaAndPackingStages();
    $user = phase7b_makeUser();

    $service = new \App\Services\BoxQrCodeService();
    $box1 = $service->ensureFirstBox($made['order_id'], $user);
    $box2 = $service->ensureFirstBox($made['order_id'], $user);

    expect($box1->id)->toBe($box2->id);
    expect(\App\Models\OrderPackingBox::where('order_id', $made['order_id'])->count())->toBe(1);
});

it('generates QR codes with the canonical format', function () {
    $made = phase7b_makeOrderWithQaAndPackingStages();
    $order = $made['order'];
    $service = new \App\Services\BoxQrCodeService();

    $code = $service->generateCode($order, 7);

    // Format: ASH-PO-YYYY-NNNNNN-BOX-NN  OR  custom-po-code-BOX-NN
    expect($code)->toEndWith('-BOX-07');
});

it('seal() locks the box and records who sealed it', function () {
    $made = phase7b_makeOrderWithQaAndPackingStages();
    $user = phase7b_makeUser('Sealer');

    $service = new \App\Services\BoxQrCodeService();
    $box = $service->ensureFirstBox($made['order_id'], $user);

    expect($box->isSealed())->toBeFalse();

    $sealed = $service->seal($box->id, $user);

    expect($sealed->isSealed())->toBeTrue()
        ->and($sealed->sealed_by_user_id)->toBe($user->id)
        ->and($sealed->sealed_at)->not->toBeNull();
});

it('seal() refuses to re-seal an already-sealed box', function () {
    $made = phase7b_makeOrderWithQaAndPackingStages();
    $user = phase7b_makeUser();

    $service = new \App\Services\BoxQrCodeService();
    $box = $service->ensureFirstBox($made['order_id'], $user);
    $service->seal($box->id, $user);

    expect(fn () => $service->seal($box->id, $user))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('renderQrPng returns non-empty PNG bytes', function () {
    $service = new \App\Services\BoxQrCodeService();
    $bytes = $service->renderQrPng('ASH-TEST-001-BOX-01');

    expect(strlen($bytes))->toBeGreaterThan(100)
        ->and(substr($bytes, 1, 3))->toBe('PNG');   // magic header bytes
});

// ─── Bundle 4a — Submit persistence ───────────────────────────────

it('submit persists a qa_packer_task_completions row', function () {
    $made = phase7b_makeOrderWithQaAndPackingStages();
    $user = phase7b_makeUser();

    $result = phase7b_submitService()->submit(
        $made['qa_stage_id'],
        [
            'qa_checklist_state' => ['correct_print' => true, 'correct_size' => true],
            'packing_checklist_state' => [],
            'final_photos' => ['completed_product' => 'qa-packer/final-photos/x.jpg'],
            'notes' => 'All good',
        ],
        $user,
    );

    $completion = \App\Models\QaPackerTaskCompletion::where('order_stage_id', $made['qa_stage_id'])
        ->first();

    expect($completion)->not->toBeNull()
        ->and($completion->submitted_by_user_id)->toBe($user->id)
        ->and($completion->checklist_state_json['qa']['correct_print'])->toBeTrue()
        ->and($completion->checklist_state_json['qa']['correct_size'])->toBeTrue()
        ->and($completion->final_photos_json['completed_product'])->toBe('qa-packer/final-photos/x.jpg')
        ->and($completion->notes)->toBe('All good');
});

it('submit also captures the reject summary in the completion row', function () {
    $made = phase7b_makeOrderWithQaAndPackingStages(totalQty: 100);
    $user = phase7b_makeUser();

    // Log 6 pcs reject (>=5 trips the threshold)
    phase7b_rejectService()->create([
        'order_id' => $made['order_id'],
        'order_stage_id' => $made['qa_stage_id'],
        'disposition' => 'reject',
        'reject_reason_id' => \App\Models\RejectReason::where('slug', 'damaged')->first()->id,
        'quantity_pcs' => 6,
    ], $user);

    phase7b_submitService()->submit($made['qa_stage_id'], [], $user);

    $completion = \App\Models\QaPackerTaskCompletion::where('order_stage_id', $made['qa_stage_id'])->first();
    expect($completion->reject_summary_json['total_pcs'])->toBe(6)
        ->and($completion->reject_summary_json['exceeds_threshold'])->toBeTrue();
});

// ─── Bundle 4a — Repair notifications ─────────────────────────────

it('repair entries trigger stage.repair_logged not stage.reject_logged', function () {
    $made = phase7b_makeOrderWithQaAndPackingStages();
    $user = phase7b_makeUser();

    // Seed a CSR user + role so NotificationService has a recipient
    // to dispatch to. Without this, the fan-out resolves to zero
    // recipients and zero notification rows are written.
    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'csr', 'guard_name' => 'web']);
    $csr = phase7b_makeUser('CSR User');
    $csr->assignRole('csr');

    phase7b_rejectService()->create([
        'order_id' => $made['order_id'],
        'order_stage_id' => $made['qa_stage_id'],
        'disposition' => 'repair',
        'reject_reason_id' => \App\Models\RejectReason::where('slug', 'print_issue')->first()->id,
        'quantity_pcs' => 2,
    ], $user);

    // Find the dispatched notification for THIS reject_log row.
    $notif = \Illuminate\Support\Facades\DB::table('notifications')
        ->where('user_id', $csr->id)
        ->orderByDesc('id')
        ->first();

    // Defensive: confirm the row was actually written.
    expect($notif)->not->toBeNull();
    expect($notif->type)->toBe('stage.repair_logged');

    // Belt-and-braces: a REJECT disposition should yield a different type.
    $rejectReason = \App\Models\RejectReason::where('slug', 'damaged')->first();
    phase7b_rejectService()->create([
        'order_id' => $made['order_id'],
        'order_stage_id' => $made['qa_stage_id'],
        'disposition' => 'reject',
        'reject_reason_id' => $rejectReason->id,
        'quantity_pcs' => 1,
    ], $user);

    $rejectNotif = \Illuminate\Support\Facades\DB::table('notifications')
        ->where('user_id', $csr->id)
        ->orderByDesc('id')
        ->first();
    expect($rejectNotif->type)->toBe('stage.reject_logged');
});

it('unseal returns a sealed box to draft state', function () {
    $made = phase7b_makeOrderWithQaAndPackingStages();
    $user = phase7b_makeUser();

    $service = new \App\Services\BoxQrCodeService();
    $box = $service->ensureFirstBox($made['order_id'], $user);
    $service->seal($box->id, $user);

    expect($box->fresh()->isSealed())->toBeTrue();

    $unsealed = $service->unseal($box->id, $user);

    expect($unsealed->isSealed())->toBeFalse()
        ->and($unsealed->sealed_by_user_id)->toBeNull()
        ->and($unsealed->sealed_at)->toBeNull();
});

it('unseal refuses a box that is not sealed', function () {
    $made = phase7b_makeOrderWithQaAndPackingStages();
    $user = phase7b_makeUser();

    $service = new \App\Services\BoxQrCodeService();
    $box = $service->ensureFirstBox($made['order_id'], $user);

    // Box is in draft (never sealed) — unseal should fail.
    expect(fn () => $service->unseal($box->id, $user))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('unseal refuses once the packing stage is completed (submitted)', function () {
    $made = phase7b_makeOrderWithQaAndPackingStages();
    $user = phase7b_makeUser();

    $service = new \App\Services\BoxQrCodeService();
    $box = $service->ensureFirstBox($made['order_id'], $user);
    $service->seal($box->id, $user);

    // Simulate the packing stage having been submitted.
    \App\Models\OrderStage::where('id', $made['packing_stage_id'])
        ->update(['status' => 'completed', 'completed_at' => now()]);

    expect(fn () => $service->unseal($box->id, $user))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('unseal writes a box_unsealed audit log entry', function () {
    $made = phase7b_makeOrderWithQaAndPackingStages();
    $user = phase7b_makeUser();

    $service = new \App\Services\BoxQrCodeService();
    $box = $service->ensureFirstBox($made['order_id'], $user);
    $service->seal($box->id, $user);
    $service->unseal($box->id, $user);

    $log = \Illuminate\Support\Facades\DB::table('stage_audit_logs')
        ->where('order_stage_id', $made['packing_stage_id'])
        ->where('action', 'box_unsealed')
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBe($user->id);
});