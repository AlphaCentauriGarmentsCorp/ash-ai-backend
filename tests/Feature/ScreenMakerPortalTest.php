<?php

/**
 * Phase 5-F — Screen Maker Portal tests.
 *
 * Run with:
 *   php artisan test --filter=ScreenMakerPortalTest
 *
 * Coverage:
 *   1. buildContext returns full payload for active screen_making stage
 *   2. buildContext rejects stages outside screen_making scope
 *   3. buildContext rejects unknown stage
 *   4. designs section returns placements with nested screens
 *   5. subcontract info returned when service_type=subcontract
 */

use App\Models\OrderStage;
use App\Models\StageSubcontractAssignment;
use App\Services\ScreenMakerPortalService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    foreach ([
        'stage_audit_logs',
        'stage_subcontract_assignments',
        'subcontractors',
        'material_requests',
        'screen_assignments',
        'screens',
        'order_design_placements',
        'order_designs',
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
        $t->string('password')->default('x');
        $t->timestamps();
    });

    Schema::create('orders', function (Blueprint $t) {
        $t->id();
        $t->string('po_code')->unique();
        $t->string('client_name')->nullable();
        $t->string('client_brand')->nullable();
        $t->string('shirt_color', 64)->nullable();
        $t->string('special_print', 64)->nullable();
        $t->string('print_area', 64)->nullable();
        $t->text('items_json')->nullable();
        $t->text('notes')->nullable();
        $t->string('workflow_status', 32)->default('inquiry');
        $t->timestamps();
    });

    Schema::create('order_stages', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->text('stage');
        $t->unsignedSmallInteger('sequence')->default(0);
        $t->string('status')->default('pending');
        $t->string('service_type', 16)->default('in_house');
        $t->timestamp('started_at')->nullable();
        $t->timestamp('completed_at')->nullable();
        $t->timestamp('delayed_at')->nullable();
        $t->unsignedBigInteger('assigned_to')->nullable();
        $t->string('assigned_role', 64)->nullable();
        $t->text('notes')->nullable();
        $t->timestamps();
    });

    Schema::create('order_designs', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->unsignedBigInteger('artist_id')->nullable();
        $t->text('notes')->nullable();
        $t->text('size_label')->nullable();
        $t->timestamps();
    });

    Schema::create('order_design_placements', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('order_design_id');
        $t->string('type');
        $t->text('mockup_image')->nullable();
        $t->text('pantones')->nullable();
        $t->timestamps();
    });

    Schema::create('screens', function (Blueprint $t) {
        $t->id();
        $t->string('name')->nullable();
        $t->string('mesh_count')->nullable();
        $t->string('address')->nullable();
        $t->string('size')->nullable();
        $t->integer('total_use')->default(0);
        $t->string('status')->nullable();
        $t->timestamps();
    });

    Schema::create('screen_assignments', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->unsignedBigInteger('placement_id');
        $t->unsignedBigInteger('screen_id');
        $t->integer('color_index');
        $t->timestamps();
    });

    Schema::create('material_requests', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->unsignedBigInteger('stage_id')->nullable();
        $t->string('mr_code', 32);
        $t->string('status')->default('pending');
        $t->text('reason')->nullable();
        $t->timestamp('approved_at')->nullable();
        $t->timestamps();
    });

    Schema::create('subcontractors', function (Blueprint $t) {
        $t->id();
        $t->string('name')->nullable();
        $t->timestamps();
    });

    Schema::create('stage_subcontract_assignments', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->unsignedBigInteger('order_stage_id');
        $t->unsignedBigInteger('subcontractor_id');
        $t->integer('quantity_pcs')->default(0);
        $t->decimal('rate_per_pcs', 10, 2)->default(0);
        $t->decimal('total_amount', 10, 2)->default(0);
        $t->string('status')->default('pending');
        $t->timestamp('sent_at')->nullable();
        $t->timestamp('returned_at')->nullable();
        $t->timestamp('expected_return_at')->nullable();
        $t->string('turnover_method', 64)->nullable();
        $t->text('notes')->nullable();
        $t->string('payment_terms', 64)->nullable();
        $t->decimal('agreed_price_per_sample', 10, 2)->nullable();
        $t->string('waybill_number', 64)->nullable();
        $t->string('gc_chat_link', 255)->nullable();
        $t->string('vendor_contact_number', 32)->nullable();
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
});

afterEach(function () {
    foreach ([
        'stage_audit_logs', 'stage_subcontract_assignments', 'subcontractors',
        'material_requests', 'screen_assignments', 'screens',
        'order_design_placements', 'order_designs',
        'order_stages', 'orders', 'users',
    ] as $t) {
        Schema::dropIfExists($t);
    }
});

// ─── Helpers ───────────────────────────────────────────────────

function phase5f_makeStage(string $stageSlug = 'screen_making', string $serviceType = 'in_house', string $status = 'in_progress'): array
{
    $orderId = DB::table('orders')->insertGetId([
        'po_code' => 'ASH-SM-' . uniqid(),
        'client_name' => 'Test Client',
        'client_brand' => 'TestBrand',
        'shirt_color' => 'Black',
        'special_print' => 'Silkscreen',
        'print_area' => 'Regular',
        'items_json' => json_encode([
            ['size' => 'M', 'quantity' => 50],
        ]),
        'workflow_status' => $stageSlug,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $stageId = DB::table('order_stages')->insertGetId([
        'order_id' => $orderId,
        'stage' => $stageSlug,
        'sequence' => 6,
        'status' => $status,
        'service_type' => $serviceType,
        'started_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return [
        'order_id'       => $orderId,
        'order_stage_id' => $stageId,
    ];
}

// ─── Tests ─────────────────────────────────────────────────────

it('builds full context for an active screen_making stage', function () {
    $made = phase5f_makeStage();

    $svc = new ScreenMakerPortalService();
    $ctx = $svc->buildContext($made['order_stage_id']);

    expect($ctx)->toHaveKeys([
        'order', 'stage', 'designs',
        'material_requests', 'activity_log', 'subcontract',
    ]);

    expect($ctx['order']['po_code'])->toStartWith('ASH-SM-');
    expect($ctx['stage']['stage'])->toBe('screen_making');
    expect($ctx['stage']['service_type'])->toBe('in_house');
    expect($ctx['designs'])->toBe([]);  // no designs created
    expect($ctx['subcontract'])->toBeNull();
});

it('rejects context for a stage outside screen_making scope', function () {
    $made = phase5f_makeStage('sample_creation');

    $svc = new ScreenMakerPortalService();

    expect(fn () => $svc->buildContext($made['order_stage_id']))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('rejects context for an unknown stage', function () {
    $svc = new ScreenMakerPortalService();

    expect(fn () => $svc->buildContext(99999))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('returns designs with nested screens when assignments exist', function () {
    $made = phase5f_makeStage();

    // Create a design + placement + screen + screen_assignment
    $designId = DB::table('order_designs')->insertGetId([
        'order_id' => $made['order_id'],
        'notes' => 'Test design',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $placementId = DB::table('order_design_placements')->insertGetId([
        'order_design_id' => $designId,
        'type' => 'Front',
        'pantones' => json_encode(['Black']),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $screenId = DB::table('screens')->insertGetId([
        'name' => 'S-001',
        'size' => '14 x 6 in',
        'mesh_count' => '110',
        'address' => 'Screen Cabinet A - Shelf 2',
        'status' => 'available',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('screen_assignments')->insert([
        'order_id' => $made['order_id'],
        'placement_id' => $placementId,
        'screen_id' => $screenId,
        'color_index' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $svc = new ScreenMakerPortalService();
    $ctx = $svc->buildContext($made['order_stage_id']);

    expect($ctx['designs'])->toHaveCount(1);
    $design = $ctx['designs'][0];
    expect($design['type'])->toBe('Front');
    expect($design['pantones'])->toBe(['Black']);
    expect($design['screens'])->toHaveCount(1);
    expect($design['screens'][0]['screen']['name'])->toBe('S-001');
    expect($design['screens'][0]['screen']['mesh_count'])->toBe('110');
});

it('returns subcontract info when service_type is subcontract', function () {
    $made = phase5f_makeStage('screen_making', 'subcontract');

    $vendorId = DB::table('subcontractors')->insertGetId([
        'name' => 'Screen Pro PH',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    StageSubcontractAssignment::create([
        'order_id' => $made['order_id'],
        'order_stage_id' => $made['order_stage_id'],
        'subcontractor_id' => $vendorId,
        'status' => 'out',
        'quantity_pcs' => 2,
        'rate_per_pcs' => 500,
        'total_amount' => 1000,
        'sent_at' => now(),
        'turnover_method' => 'Personal pickup',
    ]);

    $svc = new ScreenMakerPortalService();
    $ctx = $svc->buildContext($made['order_stage_id']);

    expect($ctx['subcontract'])->not->toBeNull();
    expect($ctx['subcontract']['has_assignment'])->toBeTrue();
    expect($ctx['subcontract']['vendor']['name'])->toBe('Screen Pro PH');
    expect($ctx['subcontract']['turnover_method'])->toBe('Personal pickup');
});
