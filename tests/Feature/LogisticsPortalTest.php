<?php

/**
 * Phase 5-I — Logistics Portal tests.
 *
 * Run with:
 *   php artisan test --filter=LogisticsPortalTest
 *
 * Coverage:
 *   1.  listActiveShipments counts by status correctly
 *   2.  listActiveShipments includes bare assignments without shipments
 *   3.  create shipment defaults to status=for_pickup
 *   4.  transition for_pickup → in_transit auto-stamps booking_time (courier)
 *   5.  transition for_pickup → in_transit auto-stamps departure_time (in_house)
 *   6.  transition in_transit → delivered auto-stamps delivered_at
 *   7.  transition for_pickup → delivered is REJECTED (must pass in_transit)
 *   8.  transition issue → in_transit allowed (recovery)
 *   9.  uploadProof writes to the correct column per `kind`
 *   10. uploadProof rejects unknown kind
 *   11. verifyReturn flips assignment.status to 'returned' + records verified_by/at
 *   12. verifyReturn rejects qty_received > quantity_pcs
 *   13. HTTP: GET /portal/logistics/active-shipments returns 200
 *   14. HTTP: POST /portal/logistics/assignments/{id}/verify-return returns 201
 */

use App\Models\StageSubcontractAssignment;
use App\Models\StageSubcontractShipment;
use App\Services\LogisticsPortalService;
use App\Services\SubcontractReturnVerificationService;
use App\Services\SubcontractShipmentService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    // ── Drop tables (reverse FK order) ───────────────────────────
    foreach ([
        'role_has_permissions',
        'model_has_permissions',
        'model_has_roles',
        'roles',
        'permissions',

        'stage_subcontract_shipments',
        'stage_subcontract_assignments',
        'subcontractors',
        'shipping_methods',
        'courier_list',
        'order_stages',
        'orders',
        'users',
    ] as $t) {
        Schema::dropIfExists($t);
    }

    // ── Domain tables ────────────────────────────────────────────
    Schema::create('users', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('username')->nullable()->unique();
        $t->string('email')->unique();
        $t->string('password')->default('x');
        $t->text('domain_role')->nullable();
        $t->text('domain_access')->nullable();
        $t->timestamps();
        $t->softDeletes(); // User model uses SoftDeletes
    });

    Schema::create('orders', function (Blueprint $t) {
        $t->id();
        $t->string('po_code')->unique();
        $t->string('client_name')->nullable();
        $t->string('client_brand')->nullable();
        $t->string('shirt_color', 64)->nullable();
        $t->text('items_json')->nullable();
        $t->text('notes')->nullable();
        $t->string('workflow_status', 32)->default('inquiry');
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
        $t->unsignedBigInteger('assigned_to')->nullable();
        $t->text('notes')->nullable();
        $t->timestamps();
    });

    Schema::create('courier_list', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->timestamps();
    });

    Schema::create('shipping_methods', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('courier_id');
        $t->string('name');
        $t->text('description')->nullable();
        $t->timestamps();
    });

    Schema::create('subcontractors', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('address');
        $t->decimal('rate_per_pcs', 10, 2)->default(0);
        $t->string('contact_number')->nullable();
        $t->string('email')->nullable();
        $t->string('service_type', 32)->default('sewing');
        $t->timestamps();
    });

    Schema::create('stage_subcontract_assignments', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->unsignedBigInteger('order_stage_id');
        $t->unsignedBigInteger('subcontractor_id')->nullable();
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
        // 5-I return-verification columns
        $t->unsignedInteger('return_qty_received')->nullable();
        $t->text('return_condition_notes')->nullable();
        $t->string('return_photo_front_path', 255)->nullable();
        $t->string('return_photo_back_path', 255)->nullable();
        $t->unsignedBigInteger('return_verified_by_user_id')->nullable();
        $t->timestamp('return_verified_at')->nullable();
        $t->timestamps();
    });

    Schema::create('stage_subcontract_shipments', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('stage_subcontract_assignment_id');
        $t->string('direction', 16)->default('outbound');
        $t->string('status', 16)->default('for_pickup');
        $t->string('delivery_mode', 16)->default('courier');
        $t->unsignedBigInteger('courier_id')->nullable();
        $t->unsignedBigInteger('shipping_method_id')->nullable();
        $t->string('waybill_number', 64)->nullable();
        $t->text('pickup_address')->nullable();
        $t->text('dropoff_address')->nullable();
        $t->string('contact_person_name', 120)->nullable();
        $t->string('contact_person_number', 32)->nullable();
        $t->text('instructions')->nullable();
        $t->timestamp('booking_time')->nullable();
        $t->timestamp('departure_time')->nullable();
        $t->timestamp('delivered_at')->nullable();
        $t->text('issue_note')->nullable();
        $t->decimal('payment_amount', 10, 2)->nullable();
        $t->string('payment_method', 32)->nullable();
        $t->string('payment_reference', 120)->nullable();
        $t->string('payment_proof_path', 255)->nullable();
        $t->string('pickup_proof_path', 255)->nullable();
        $t->string('delivery_proof_path', 255)->nullable();
        $t->string('receiver_signature_path', 255)->nullable();
        $t->string('receiver_name', 120)->nullable();
        $t->string('driver_name', 120)->nullable();
        $t->string('driver_vehicle_plate', 32)->nullable();
        $t->string('gas_receipt_path', 255)->nullable();
        $t->decimal('gas_amount', 10, 2)->nullable();
        $t->date('gas_date')->nullable();
        $t->text('gas_notes')->nullable();
        $t->unsignedBigInteger('created_by_user_id')->nullable();
        $t->timestamps();
    });

    // ── All 5 Spatie tables (BUG-004) ───────────────────────────
    Schema::create('permissions', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('guard_name');
        $t->timestamps();
    });
    Schema::create('roles', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('guard_name');
        $t->timestamps();
    });
    Schema::create('model_has_permissions', function (Blueprint $t) {
        $t->unsignedBigInteger('permission_id');
        $t->string('model_type');
        $t->unsignedBigInteger('model_id');
        $t->primary(['permission_id', 'model_id', 'model_type']);
    });
    Schema::create('model_has_roles', function (Blueprint $t) {
        $t->unsignedBigInteger('role_id');
        $t->string('model_type');
        $t->unsignedBigInteger('model_id');
        $t->primary(['role_id', 'model_id', 'model_type']);
    });
    Schema::create('role_has_permissions', function (Blueprint $t) {
        $t->unsignedBigInteger('permission_id');
        $t->unsignedBigInteger('role_id');
        $t->primary(['permission_id', 'role_id']);
    });

    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function () {
    foreach ([
        'role_has_permissions',
        'model_has_permissions',
        'model_has_roles',
        'roles',
        'permissions',
        'stage_subcontract_shipments',
        'stage_subcontract_assignments',
        'subcontractors',
        'shipping_methods',
        'courier_list',
        'order_stages',
        'orders',
        'users',
    ] as $t) {
        Schema::dropIfExists($t);
    }
});

// ── Fixture builders ────────────────────────────────────────────

function logiMakeUser(array $permissionNames = ['portal.logistics', 'action.manage-subcontract', 'action.upload-photos']): \App\Models\User
{
    $user = \App\Models\User::create([
        'name'          => 'Logi ' . uniqid(),
        'username'      => 'logi_' . uniqid(),
        'email'         => 'logi_' . uniqid() . '@test.local',
        'domain_access' => ['ash'],
        'domain_role'   => ['logistics'],
    ]);

    foreach ($permissionNames as $pname) {
        \Spatie\Permission\Models\Permission::firstOrCreate([
            'name'       => $pname,
            'guard_name' => 'web',
        ]);
    }
    if ($permissionNames !== []) {
        $user->givePermissionTo($permissionNames);
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    return $user;
}

function logiMakeAssignment(int $qty = 100): StageSubcontractAssignment
{
    $order = \App\Models\Order::create([
        'po_code'         => 'ASH-2026-' . str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
        'client_name'     => 'ACME',
        'client_brand'    => 'Sorbetes',
        'shirt_color'     => 'Black',
        'workflow_status' => 'in_progress',
    ]);
    $stage = \App\Models\OrderStage::create([
        'order_id'     => $order->id,
        'stage'        => 'sample_sewing',
        'sequence'     => 9,
        'status'       => 'in_progress',
        'service_type' => 'subcontract',
    ]);
    $vendor = \App\Models\SewingSubcontractor::create([
        'name'           => 'ABC Printing Services',
        'address'        => '11 Kapitan Pepe St., Caloocan',
        'rate_per_pcs'   => 25.00,
        'contact_number' => '0917 123 4567',
        'service_type'   => 'printing',
    ]);
    return StageSubcontractAssignment::create([
        'order_id'         => $order->id,
        'order_stage_id'   => $stage->id,
        'subcontractor_id' => $vendor->id,
        'quantity_pcs'     => $qty,
        'rate_per_pcs'     => 25.00,
        'total_amount'     => $qty * 25,
        'status'           => 'pending',
    ]);
}

// ── Tests ───────────────────────────────────────────────────────

test('listActiveShipments counts by status correctly', function () {
    $a = logiMakeAssignment();
    StageSubcontractShipment::create([
        'stage_subcontract_assignment_id' => $a->id,
        'direction' => 'outbound', 'status' => 'for_pickup', 'delivery_mode' => 'courier',
    ]);
    StageSubcontractShipment::create([
        'stage_subcontract_assignment_id' => $a->id,
        'direction' => 'outbound', 'status' => 'in_transit', 'delivery_mode' => 'courier',
    ]);
    StageSubcontractShipment::create([
        'stage_subcontract_assignment_id' => $a->id,
        'direction' => 'outbound', 'status' => 'delivered', 'delivery_mode' => 'courier',
    ]);

    $svc = app(LogisticsPortalService::class);
    $data = $svc->listActiveShipments();

    expect($data['counts']['for_pickup'])->toBe(1);
    expect($data['counts']['in_transit'])->toBe(1);
    expect($data['counts']['delivered'])->toBe(1);
    expect($data['counts']['issue'])->toBe(0);
    expect($data['shipments'])->toHaveCount(3);
});

test('listActiveShipments includes bare assignments without shipments', function () {
    $a = logiMakeAssignment();
    // No shipment row created. Should still show up.

    $svc = app(LogisticsPortalService::class);
    $data = $svc->listActiveShipments();

    expect($data['pending_assignments'])->toHaveCount(1);
    expect($data['pending_assignments'][0]['id'])->toBe($a->id);
    expect($data['pending_assignments'][0]['no_shipment_yet'])->toBeTrue();
    // Bare assignments count toward "for_pickup" semantically.
    expect($data['counts']['for_pickup'])->toBe(1);
});

test('create shipment defaults to for_pickup status', function () {
    $a = logiMakeAssignment();
    $user = logiMakeUser();

    $svc = app(SubcontractShipmentService::class);
    $shipment = $svc->create([
        'stage_subcontract_assignment_id' => $a->id,
        'delivery_mode'                   => 'courier',
        'waybill_number'                  => 'LM1234567890',
    ], $user);

    expect($shipment->status)->toBe('for_pickup');
    expect($shipment->direction)->toBe('outbound');
    expect($shipment->delivery_mode)->toBe('courier');
    expect($shipment->waybill_number)->toBe('LM1234567890');
    expect($shipment->created_by_user_id)->toBe($user->id);
});

test('transition for_pickup to in_transit stamps booking_time for courier', function () {
    $a = logiMakeAssignment();
    $user = logiMakeUser();

    $svc = app(SubcontractShipmentService::class);
    $shipment = $svc->create([
        'stage_subcontract_assignment_id' => $a->id,
        'delivery_mode'                   => 'courier',
    ], $user);

    $updated = $svc->transitionStatus($shipment->id, 'in_transit', null, $user);

    expect($updated->status)->toBe('in_transit');
    expect($updated->booking_time)->not->toBeNull();
    expect($updated->departure_time)->toBeNull();
});

test('transition for_pickup to in_transit stamps departure_time for in_house driver', function () {
    $a = logiMakeAssignment();
    $user = logiMakeUser();

    $svc = app(SubcontractShipmentService::class);
    $shipment = $svc->create([
        'stage_subcontract_assignment_id' => $a->id,
        'delivery_mode'                   => 'in_house_driver',
    ], $user);

    $updated = $svc->transitionStatus($shipment->id, 'in_transit', null, $user);

    expect($updated->status)->toBe('in_transit');
    expect($updated->departure_time)->not->toBeNull();
    expect($updated->booking_time)->toBeNull();
});

test('transition in_transit to delivered stamps delivered_at', function () {
    $a = logiMakeAssignment();
    $user = logiMakeUser();

    $svc = app(SubcontractShipmentService::class);
    $shipment = $svc->create([
        'stage_subcontract_assignment_id' => $a->id,
        'delivery_mode'                   => 'courier',
    ], $user);
    $svc->transitionStatus($shipment->id, 'in_transit', null, $user);
    $updated = $svc->transitionStatus($shipment->id, 'delivered', null, $user);

    expect($updated->status)->toBe('delivered');
    expect($updated->delivered_at)->not->toBeNull();
});

test('transition for_pickup directly to delivered is rejected', function () {
    $a = logiMakeAssignment();
    $user = logiMakeUser();

    $svc = app(SubcontractShipmentService::class);
    $shipment = $svc->create([
        'stage_subcontract_assignment_id' => $a->id,
        'delivery_mode'                   => 'courier',
    ], $user);

    $svc->transitionStatus($shipment->id, 'delivered', null, $user);
})->throws(\Illuminate\Validation\ValidationException::class);

test('transition from issue back to in_transit is allowed (recovery)', function () {
    $a = logiMakeAssignment();
    $user = logiMakeUser();

    $svc = app(SubcontractShipmentService::class);
    $shipment = $svc->create([
        'stage_subcontract_assignment_id' => $a->id,
        'delivery_mode'                   => 'courier',
    ], $user);
    $svc->transitionStatus($shipment->id, 'in_transit', null, $user);
    $svc->transitionStatus($shipment->id, 'issue', 'Driver lost the package', $user);

    $recovered = $svc->transitionStatus($shipment->id, 'in_transit', null, $user);

    expect($recovered->status)->toBe('in_transit');
});

test('uploadProof writes to the correct column per kind', function () {
    $a = logiMakeAssignment();
    $user = logiMakeUser();

    $svc = app(SubcontractShipmentService::class);
    $shipment = $svc->create([
        'stage_subcontract_assignment_id' => $a->id,
        'delivery_mode'                   => 'in_house_driver',
    ], $user);

    $updated = $svc->uploadProof($shipment->id, 'gas_receipt', 'logistics/shipments/1/gas.png', $user);
    expect($updated->gas_receipt_path)->toBe('logistics/shipments/1/gas.png');

    $updated = $svc->uploadProof($shipment->id, 'delivery', 'logistics/shipments/1/del.jpg', $user);
    expect($updated->delivery_proof_path)->toBe('logistics/shipments/1/del.jpg');

    $updated = $svc->uploadProof($shipment->id, 'signature', 'logistics/shipments/1/sig.png', $user);
    expect($updated->receiver_signature_path)->toBe('logistics/shipments/1/sig.png');
});

test('uploadProof rejects unknown kind', function () {
    $a = logiMakeAssignment();
    $user = logiMakeUser();

    $svc = app(SubcontractShipmentService::class);
    $shipment = $svc->create([
        'stage_subcontract_assignment_id' => $a->id,
        'delivery_mode'                   => 'courier',
    ], $user);

    $svc->uploadProof($shipment->id, 'random_kind', 'x.png', $user);
})->throws(\Illuminate\Validation\ValidationException::class);

test('verifyReturn flips assignment to returned and records verifier', function () {
    $a = logiMakeAssignment(100);
    $user = logiMakeUser();

    $svc = app(SubcontractReturnVerificationService::class);
    $updated = $svc->verify($a->id, [
        'return_qty_received'    => 98,
        'return_condition_notes' => '2 pcs with misprint',
    ], $user);

    expect($updated->return_qty_received)->toBe(98);
    expect($updated->status)->toBe('returned');
    expect($updated->return_verified_by_user_id)->toBe($user->id);
    expect($updated->return_verified_at)->not->toBeNull();
    expect($updated->returned_at)->not->toBeNull();
});

test('verifyReturn rejects qty_received greater than original quantity', function () {
    $a = logiMakeAssignment(100);
    $user = logiMakeUser();

    $svc = app(SubcontractReturnVerificationService::class);
    $svc->verify($a->id, [
        'return_qty_received' => 105,
    ], $user);
})->throws(\Illuminate\Validation\ValidationException::class);

test('HTTP: GET /portal/logistics/active-shipments returns 200', function () {
    $user = logiMakeUser();
    $this->actingAs($user, 'sanctum');

    $response = $this->getJson('/api/v2/portal/logistics/active-shipments');

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'data' => [
            'counts' => ['for_pickup', 'in_transit', 'delivered', 'issue'],
            'shipments',
            'pending_assignments',
        ],
    ]);
});

test('HTTP: POST /portal/logistics/assignments/{id}/verify-return returns 201', function () {
    $a = logiMakeAssignment(50);
    $user = logiMakeUser();
    $this->actingAs($user, 'sanctum');

    $response = $this->postJson("/api/v2/portal/logistics/assignments/{$a->id}/verify-return", [
        'return_qty_received'    => 50,
        'return_condition_notes' => 'All good',
    ]);

    $response->assertStatus(201);
    $response->assertJsonPath('data.assignment.status', 'returned');
    $response->assertJsonPath('data.assignment.return_qty_received', 50);
});