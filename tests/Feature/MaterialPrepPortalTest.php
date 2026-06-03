<?php

/**
 * Phase 5-G — Material Prep Portal tests.
 *
 * Run with:
 *   php artisan test --filter=MaterialPrepPortalTest
 *
 * Coverage:
 *   1. myActiveRequests returns 'none' when no active PRs
 *   2. myActiveRequests returns 'single' when exactly one active
 *   3. myActiveRequests returns 'multiple' when more than one
 *   4. buildContext returns full payload for valid PR
 *   5. buildContext returns alternative suppliers when materials match
 *   6. assignSupplier succeeds on a pending PR
 *   7. assignSupplier fails when PR is not pending (locked)
 */

use App\Models\Materials;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\Supplier;
use App\Models\User;
use App\Services\MaterialPrepPortalService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    foreach ([
        'purchase_request_items',
        'purchase_requests',
        'material_request_items',
        'material_requests',
        'materials',
        'suppliers',
        'model_has_permissions',
        'role_has_permissions',
        'model_has_roles',
        'permissions',
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
        $t->string('password')->default('x');
        $t->timestamps();
        $t->softDeletes(); // User model uses SoftDeletes
    });

    Schema::create('orders', function (Blueprint $t) {
        $t->id();
        $t->string('po_code')->unique();
        $t->string('client_name')->nullable();
        $t->string('client_brand')->nullable();
        $t->timestamps();
        $t->softDeletes();
    });

    Schema::create('order_stages', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->text('stage');
        $t->string('status')->default('pending');
        $t->timestamps();
    });

    // Spatie tables (all 5 — lesson from 5-D hotfix)
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

    Schema::create('suppliers', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('contact_person')->nullable();
        $t->string('contact_number')->nullable();
        $t->string('email')->nullable();
        $t->string('address')->nullable();
        $t->text('notes')->nullable();
        $t->timestamps();
    });

    Schema::create('materials', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('supplier_id');
        $t->string('name');
        $t->string('material_type');
        $t->string('unit')->nullable();
        $t->decimal('price', 10, 2)->nullable();
        $t->decimal('stock_on_hand', 12, 2)->default(0);
        $t->string('minimum')->nullable();
        $t->string('lead')->nullable();
        $t->text('notes')->nullable();
        $t->timestamps();
    });

    Schema::create('material_requests', function (Blueprint $t) {
        $t->id();
        $t->string('mr_code')->unique();
        $t->unsignedBigInteger('order_id');
        $t->unsignedBigInteger('stage_id')->nullable();
        $t->unsignedBigInteger('requested_by_user_id');
        $t->string('status', 16)->default('pending');
        $t->text('reason')->nullable();
        $t->timestamps();
    });

    Schema::create('material_request_items', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('material_request_id');
        $t->unsignedBigInteger('material_id');
        $t->decimal('quantity_requested', 12, 2);
        $t->decimal('quantity_available', 12, 2)->default(0);
        $t->decimal('quantity_short', 12, 2)->default(0);
        $t->string('unit')->nullable();
        $t->text('notes')->nullable();
        $t->timestamps();
    });

    Schema::create('purchase_requests', function (Blueprint $t) {
        $t->id();
        $t->string('pr_code')->unique();
        $t->unsignedBigInteger('order_id');
        $t->unsignedBigInteger('material_request_id')->nullable();
        $t->unsignedBigInteger('supplier_id')->nullable();
        $t->string('status', 16)->default('pending');
        $t->decimal('total_amount', 12, 2)->default(0);
        $t->text('reason')->nullable();
        $t->unsignedBigInteger('approved_by_user_id')->nullable();
        $t->timestamp('approved_at')->nullable();
        $t->timestamp('ordered_at')->nullable();
        $t->timestamp('received_at')->nullable();
        $t->timestamps();
    });

    Schema::create('purchase_request_items', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('purchase_request_id');
        $t->unsignedBigInteger('material_id');
        $t->decimal('quantity', 12, 2);
        $t->decimal('unit_price', 12, 2)->default(0);
        $t->decimal('line_total', 12, 2)->default(0);
        $t->string('unit')->nullable();
        $t->text('notes')->nullable();
        $t->timestamps();
    });

    DB::table('permissions')->insert([
        'name' => 'action.process-purchase', 'guard_name' => 'web',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function () {
    foreach ([
        'purchase_request_items', 'purchase_requests',
        'material_request_items', 'material_requests',
        'materials', 'suppliers',
        'model_has_permissions', 'role_has_permissions', 'model_has_roles',
        'permissions', 'roles',
        'order_stages', 'orders', 'users',
    ] as $t) {
        Schema::dropIfExists($t);
    }
});

// ─── Helpers ──────────────────────────────────────────────────

function phase5g_makeUser(string $name, bool $withPerm = true): User
{
    $id = DB::table('users')->insertGetId([
        'name' => $name,
        'email' => strtolower(str_replace(' ', '', $name)) . uniqid() . '@example.com',
        'password' => 'x',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    if ($withPerm) {
        $pid = DB::table('permissions')->where('name', 'action.process-purchase')->value('id');
        DB::table('model_has_permissions')->insert([
            'permission_id' => $pid,
            'model_type' => 'App\\Models\\User',
            'model_id' => $id,
        ]);
    }

    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    return User::find($id);
}

function phase5g_makeSupplier(string $name): Supplier
{
    return Supplier::create([
        'name' => $name,
        'contact_person' => 'Kuya Test',
        'contact_number' => '0917 000 0000',
        'email' => strtolower(str_replace(' ', '', $name)) . '@example.com',
        'address' => 'Manila',
    ]);
}

function phase5g_makeMaterial(string $name, Supplier $supplier): Materials
{
    return Materials::create([
        'supplier_id' => $supplier->id,
        'name' => $name,
        'material_type' => 'fabric',
        'unit' => 'kg',
        'price' => 100,
        'stock_on_hand' => 0,
    ]);
}

function phase5g_makePR(string $status, Supplier $supplier, array $materials = []): PurchaseRequest
{
    $orderId = DB::table('orders')->insertGetId([
        'po_code' => 'ASH-MP-' . uniqid(),
        'client_name' => 'Test',
        'client_brand' => 'TestBrand',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $pr = PurchaseRequest::create([
        'pr_code' => 'PR-' . uniqid(),
        'order_id' => $orderId,
        'supplier_id' => $supplier->id,
        'status' => $status,
        'total_amount' => 0,
    ]);

    $total = 0;
    foreach ($materials as $m) {
        $line = 10 * $m->price;
        PurchaseRequestItem::create([
            'purchase_request_id' => $pr->id,
            'material_id' => $m->id,
            'quantity' => 10,
            'unit_price' => $m->price,
            'line_total' => $line,
            'unit' => $m->unit,
        ]);
        $total += $line;
    }

    $pr->update(['total_amount' => $total]);
    return $pr->fresh(['items', 'supplier', 'order']);
}

// ─── Tests ────────────────────────────────────────────────────

it('returns none when there are no active PRs', function () {
    $user = phase5g_makeUser('Purchaser');

    $svc = new MaterialPrepPortalService();
    $result = $svc->myActiveRequests($user);

    expect($result['status'])->toBe('none');
});

it('returns single when exactly one active PR exists', function () {
    $user = phase5g_makeUser('Purchaser');
    $supplier = phase5g_makeSupplier('ABC Supply');
    phase5g_makePR('approved', $supplier);

    $svc = new MaterialPrepPortalService();
    $result = $svc->myActiveRequests($user);

    expect($result['status'])->toBe('single');
    expect($result['assignment']['supplier']['name'])->toBe('ABC Supply');
});

it('returns multiple when more than one active PR exists', function () {
    $user = phase5g_makeUser('Purchaser');
    $s1 = phase5g_makeSupplier('Supplier A');
    $s2 = phase5g_makeSupplier('Supplier B');

    phase5g_makePR('approved', $s1);
    phase5g_makePR('pending', $s2);
    phase5g_makePR('ordered', $s1);
    phase5g_makePR('received', $s2);    // should be excluded (not active)
    phase5g_makePR('cancelled', $s2);   // should be excluded (not active)

    $svc = new MaterialPrepPortalService();
    $result = $svc->myActiveRequests($user);

    expect($result['status'])->toBe('multiple');
    expect($result['assignments'])->toHaveCount(3);
});

it('builds full context for a valid PR', function () {
    $user = phase5g_makeUser('Purchaser');
    Auth::login($user);

    $supplier = phase5g_makeSupplier('ABC Supply');
    $m1 = phase5g_makeMaterial('Cotton 20s', $supplier);
    $pr = phase5g_makePR('pending', $supplier, [$m1]);

    $svc = new MaterialPrepPortalService();
    $ctx = $svc->buildContext($pr->id);

    expect($ctx)->toHaveKeys([
        'pr', 'order', 'items', 'supplier',
        'alternative_suppliers', 'totals', 'permissions',
    ]);
    expect($ctx['pr']['status'])->toBe('pending');
    expect($ctx['supplier']['name'])->toBe('ABC Supply');
    expect($ctx['items'])->toHaveCount(1);
    expect($ctx['items'][0]['material_name'])->toBe('Cotton 20s');
    expect($ctx['totals']['total_items'])->toBe(1);
    expect($ctx['permissions']['can_change_supplier'])->toBeTrue();   // pending + has perm
});

it('returns alternative suppliers carrying the same materials', function () {
    $user = phase5g_makeUser('Purchaser');
    Auth::login($user);

    $s1 = phase5g_makeSupplier('ABC Supply');
    $s2 = phase5g_makeSupplier('XYZ Supply');
    $s3 = phase5g_makeSupplier('Unrelated Supply');

    // Both s1 and s2 carry the same material name (different IDs)
    $m1FromS1 = phase5g_makeMaterial('Cotton 20s', $s1);
    $m1FromS2 = phase5g_makeMaterial('Cotton 20s', $s2);

    // s3 carries something else
    phase5g_makeMaterial('Polyester', $s3);

    // PR uses material from s1
    $pr = phase5g_makePR('pending', $s1, [$m1FromS1]);

    $svc = new MaterialPrepPortalService();
    $ctx = $svc->buildContext($pr->id);

    // Alternative suppliers should NOT include s1 (current) or s3 (different material)
    $altNames = collect($ctx['alternative_suppliers'])->pluck('name')->all();
    expect($altNames)->toContain('XYZ Supply');
    expect($altNames)->not->toContain('ABC Supply');
    expect($altNames)->not->toContain('Unrelated Supply');
});

it('assigns supplier on a pending PR', function () {
    $user = phase5g_makeUser('Purchaser');
    Auth::login($user);

    $s1 = phase5g_makeSupplier('ABC Supply');
    $s2 = phase5g_makeSupplier('XYZ Supply');
    $pr = phase5g_makePR('pending', $s1);

    $svc = new MaterialPrepPortalService();
    $updated = $svc->assignSupplier($pr->id, $s2->id, $user);

    expect($updated->supplier_id)->toBe($s2->id);
    expect($updated->supplier->name)->toBe('XYZ Supply');
});

it('rejects supplier change when PR is no longer pending', function () {
    $user = phase5g_makeUser('Purchaser');
    Auth::login($user);

    $s1 = phase5g_makeSupplier('ABC Supply');
    $s2 = phase5g_makeSupplier('XYZ Supply');
    $pr = phase5g_makePR('approved', $s1);

    $svc = new MaterialPrepPortalService();

    expect(fn () => $svc->assignSupplier($pr->id, $s2->id, $user))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});