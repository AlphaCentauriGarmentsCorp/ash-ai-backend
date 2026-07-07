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
 *
 * SM Rework CP1 — the portal payload was widened to mirror the Graphic
 * Artist portal (enriched Order Details + read-only GA output +
 * role-directed instruction thread), and gained a Review Hub summary.
 * New coverage:
 *   6. buildContext exposes the enriched Order Details fields
 *   7. buildContext hydrates the GA placements + Pantones (read-only)
 *   8. buildContext surfaces the label specs / shared label design
 *   9. buildContext surfaces the Hub → screen_maker instruction thread
 *  10. reviewSummary returns the Screen Making output block incl. notes
 *
 * The enriched code path now reads order_role_notes + pantones + the
 * apparel/print lookup tables, so the hand-built schema seeds them
 * (per the "new table on a shared path → every affected test file adds
 * it" convention).
 */

use App\Models\Order;
use App\Models\OrderStage;
use App\Models\StageSubcontractAssignment;
use App\Services\ScreenMakerPortalService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$SMP_TABLES = [
    'stage_audit_logs',
    'stage_subcontract_assignments',
    'subcontractors',
    'material_requests',
    'screen_assignments',
    'screens',
    'order_design_placements',
    'order_designs',
    'order_role_notes',
    'pantones',
    'apparel_types',
    'pattern_types',
    'apparel_necklines',
    'print_methods',
    'order_stages',
    'orders',
    'users',
];

beforeEach(function () use ($SMP_TABLES) {
    foreach ($SMP_TABLES as $t) {
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

    // SM Rework CP1 — enriched orders (mirrors the GA portal's data
    // surface): Product Details columns + label specs + the apparel /
    // print lookup FKs.
    Schema::create('orders', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('quotation_id')->nullable();
        $t->string('po_code')->unique();
        $t->string('client_name')->nullable();
        $t->string('client_brand')->nullable();
        $t->string('shirt_color', 64)->nullable();
        $t->string('special_print', 64)->nullable();
        $t->string('print_area', 64)->nullable();
        $t->json('print_parts_json')->nullable();
        $t->text('items_json')->nullable();
        $t->text('notes')->nullable();
        $t->string('workflow_status', 32)->default('inquiry');

        // Product Details mirror.
        $t->unsignedBigInteger('apparel_type_id')->nullable();
        $t->unsignedBigInteger('pattern_type_id')->nullable();
        $t->unsignedBigInteger('apparel_neckline_id')->nullable();
        $t->unsignedBigInteger('print_method_id')->nullable();
        $t->string('design_name')->nullable();
        $t->string('service_type', 64)->nullable();
        $t->string('print_service', 64)->nullable();
        $t->string('fabric_type', 64)->nullable();
        $t->string('fabric_supplier', 64)->nullable();
        $t->string('fabric_color', 64)->nullable();
        $t->string('thread_color', 64)->nullable();
        $t->string('ribbing_color', 64)->nullable();

        // Label specs + shared label design.
        $t->json('brand_label_json')->nullable();
        $t->json('care_label_json')->nullable();
        $t->string('label_design_path')->nullable();

        $t->timestamps();
        $t->softDeletes();
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

    // Apparel / print lookup tables (belongsTo targets on orders).
    foreach (['apparel_types', 'pattern_types', 'apparel_necklines', 'print_methods'] as $lookup) {
        Schema::create($lookup, function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->timestamps();
        });
    }

    Schema::create('pantones', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('hexcolor');
        $t->string('pantone_code');
        $t->timestamps();
    });

    Schema::create('order_role_notes', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->string('audience_role', 64);
        $t->unsignedBigInteger('author_user_id');
        $t->text('body');
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
        $t->unsignedTinyInteger('color_count')->nullable();
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

afterEach(function () use ($SMP_TABLES) {
    foreach ($SMP_TABLES as $t) {
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

/**
 * SM Rework CP1 — an order fully populated with Product Details, label
 * specs, a design + placement (Pantone-linked), a mapped screen, and a
 * Hub → screen_maker instruction, so the enriched context can be asserted.
 */
function phase5f_makeEnrichedStage(): array
{
    $apparelTypeId  = DB::table('apparel_types')->insertGetId(['name' => 'Tshirt - Premium', 'created_at' => now(), 'updated_at' => now()]);
    $patternTypeId  = DB::table('pattern_types')->insertGetId(['name' => 'Standard', 'created_at' => now(), 'updated_at' => now()]);
    $necklineId     = DB::table('apparel_necklines')->insertGetId(['name' => 'Standard', 'created_at' => now(), 'updated_at' => now()]);
    $printMethodId  = DB::table('print_methods')->insertGetId(['name' => 'DTF', 'created_at' => now(), 'updated_at' => now()]);

    // Pantone that also doubles as the colour-name → hex source for the chip.
    $pantoneId = DB::table('pantones')->insertGetId([
        'name' => 'Dk. Royal Blue', 'hexcolor' => '#123456', 'pantone_code' => 'PMS 286 C',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $orderId = DB::table('orders')->insertGetId([
        'po_code'       => 'ASH-SM-ENR-' . uniqid(),
        'client_name'   => 'Krusada Client',
        'client_brand'  => 'KRUSADA PROJECT',
        'shirt_color'   => 'Dk. Royal Blue',
        'print_area'    => 'Regular',
        'items_json'    => json_encode([['size' => 'M', 'quantity' => 4]]),
        'workflow_status' => 'screen_making',

        'apparel_type_id'     => $apparelTypeId,
        'pattern_type_id'     => $patternTypeId,
        'apparel_neckline_id' => $necklineId,
        'print_method_id'     => $printMethodId,
        'design_name'         => 'Black Design',
        'service_type'        => 'Sew & Print / Embro',
        'print_service'       => 'In House',
        'fabric_type'         => 'Brushed Cotton',
        'fabric_supplier'     => 'CALOOCAN',
        'fabric_color'        => 'Dk. Royal Blue',
        'thread_color'        => 'Dk. Royal Blue',
        'ribbing_color'       => 'Dk. Royal Blue',

        'brand_label_json' => json_encode([
            'enabled' => true, 'material' => 'Woven Tag', 'method' => 'None', 'placement' => 'Body Back',
        ]),
        'care_label_json'   => null,
        'label_design_path' => 'order-label-designs/label-x.png',

        'created_at' => now(), 'updated_at' => now(),
    ]);

    $stageId = DB::table('order_stages')->insertGetId([
        'order_id' => $orderId,
        'stage' => 'screen_making',
        'sequence' => 6,
        'status' => 'in_progress',
        'service_type' => 'in_house',
        'started_at' => now(),
        'notes' => 'Ingatan sa exposure.',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $designId = DB::table('order_designs')->insertGetId([
        'order_id' => $orderId,
        'notes' => 'Front print design',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $placementId = DB::table('order_design_placements')->insertGetId([
        'order_design_id' => $designId,
        'type' => 'Front Print',
        'color_count' => 3,
        'pantones' => json_encode([$pantoneId]), // integer id → hydrated
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $screenId = DB::table('screens')->insertGetId([
        'name' => 'S-001', 'size' => '14 x 6 in', 'mesh_count' => '110',
        'address' => 'Cabinet A', 'status' => 'available',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('screen_assignments')->insert([
        'order_id' => $orderId,
        'placement_id' => $placementId,
        'screen_id' => $screenId,
        'color_index' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $authorId = DB::table('users')->insertGetId([
        'name' => 'CSR Reviewer',
        'email' => 'csr_' . uniqid() . '@test.local',
        'password' => 'x',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('order_role_notes')->insert([
        'order_id' => $orderId,
        'audience_role' => 'screen_maker',
        'author_user_id' => $authorId,
        'body' => 'Priority order — rush ang screens.',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return [
        'order_id'       => $orderId,
        'order_stage_id' => $stageId,
        'pantone_id'     => $pantoneId,
    ];
}

// ─── Tests ─────────────────────────────────────────────────────

it('builds full context for an active screen_making stage', function () {
    $made = phase5f_makeStage();

    $svc = new ScreenMakerPortalService();
    $ctx = $svc->buildContext($made['order_stage_id']);

    expect($ctx)->toHaveKeys([
        'order', 'stage', 'designs', 'placements', 'pantones_used',
        'material_requests', 'activity_log', 'subcontract', 'role_notes',
    ]);

    expect($ctx['order']['po_code'])->toStartWith('ASH-SM-');
    expect($ctx['stage']['stage'])->toBe('screen_making');
    expect($ctx['stage']['service_type'])->toBe('in_house');
    expect($ctx['designs'])->toBe([]);  // no designs created
    expect($ctx['placements'])->toBe([]);
    expect($ctx['subcontract'])->toBeNull();
});

it('rejects context for a stage outside screen_making scope', function () {
    $made = phase5f_makeStage('sample_cutting');

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

// ─── SM Rework CP1 ─────────────────────────────────────────────

it('exposes the enriched Order Details fields', function () {
    $made = phase5f_makeEnrichedStage();

    $ctx = (new ScreenMakerPortalService())->buildContext($made['order_stage_id']);
    $order = $ctx['order'];

    // Apparel Information (resolved lookup names).
    expect($order['apparel_type'])->toBe('Tshirt - Premium');
    expect($order['pattern_type'])->toBe('Standard');
    expect($order['apparel_neckline'])->toBe('Standard');
    expect($order['print_method'])->toBe('DTF');

    // Production Details.
    expect($order['design_name'])->toBe('Black Design');
    expect($order['service_type'])->toBe('Sew & Print / Embro');
    expect($order['fabric_type'])->toBe('Brushed Cotton');
    expect($order['fabric_supplier'])->toBe('CALOOCAN');

    // Colour-name → hex chip resolution (pantone fallback).
    expect($order['shirt_color'])->toBe('Dk. Royal Blue');
    expect($order['shirt_color_hex'])->toBe('#123456');
    expect($order['fabric_color_hex'])->toBe('#123456');

    expect($order['total_pcs'])->toBe(4);
});

it('hydrates the GA placements + Pantones for the Design Details view', function () {
    $made = phase5f_makeEnrichedStage();

    $ctx = (new ScreenMakerPortalService())->buildContext($made['order_stage_id']);

    expect($ctx['placements'])->toHaveCount(1);
    $placement = $ctx['placements'][0];
    expect($placement['type'])->toBe('Front Print');
    expect($placement['color_count'])->toBe(3);
    expect($placement['pantones'])->toHaveCount(1);
    expect($placement['pantones'][0]['pantone_code'])->toBe('PMS 286 C');
    expect($placement['pantones'][0]['hexcolor'])->toBe('#123456');

    // Aggregated palette.
    expect($ctx['pantones_used'])->toHaveCount(1);
    expect($ctx['pantones_used'][0]['pantone_code'])->toBe('PMS 286 C');
});

it('surfaces the label specs and shared label design', function () {
    $made = phase5f_makeEnrichedStage();

    $ctx = (new ScreenMakerPortalService())->buildContext($made['order_stage_id']);
    $order = $ctx['order'];

    expect($order['brand_label'])->toBeArray();
    expect($order['brand_label']['material'])->toBe('Woven Tag');
    expect($order['brand_label']['placement'])->toBe('Body Back');
    expect($order['care_label'])->toBeNull();
    expect($order['label_design_url'])->toContain('order-label-designs/label-x.png');
});

it('surfaces the Hub to screen_maker instruction thread', function () {
    $made = phase5f_makeEnrichedStage();

    $ctx = (new ScreenMakerPortalService())->buildContext($made['order_stage_id']);

    $notes = $ctx['role_notes'];
    expect($notes)->toHaveCount(1);
    expect($notes[0]['audience_role'])->toBe('screen_maker');
    expect($notes[0]['body'])->toBe('Priority order — rush ang screens.');
});

it('builds a Review Hub summary with the maker stage notes', function () {
    $made = phase5f_makeEnrichedStage();
    $order = Order::find($made['order_id']);

    $summary = (new ScreenMakerPortalService())->reviewSummary($order);

    expect($summary)->toHaveKeys([
        'kind', 'design', 'placements', 'pantones_used', 'labels', 'screens', 'stage_notes',
    ]);
    expect($summary['kind'])->toBe('screen_making');
    expect($summary['stage_notes'])->toBe('Ingatan sa exposure.');
    expect($summary['design'])->not->toBeNull();
    expect($summary['placements'])->toHaveCount(1);
    expect($summary['placements'][0]['type'])->toBe('Front Print');
    expect($summary['labels'])->toHaveKeys(['brand_label', 'care_label', 'label_design_url']);
    expect($summary['screens'])->toHaveCount(1);
    expect($summary['screens'][0]['screen']['name'])->toBe('S-001');
});
