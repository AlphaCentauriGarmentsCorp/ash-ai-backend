<?php

/**
 * Phase 6-A — CSR Hub backend tests.
 *
 * Run with:
 *   php artisan test --filter=CsrDashboardTest
 *
 * Coverage:
 *   1. dashboard endpoint returns all 8 KPI fields
 *   2. KPI counts return integers (zero, never null)
 *   3. create inquiry creates row with auto inquiry_code
 *   4. convert inquiry to quotation creates draft + sets back-ref + status=converted
 *   5. convert inquiry rejects if already converted (returns 409)
 *   6. payment proof upload sets status to for_verification
 *   7. payment verify by user with action.verify-payment sets status to verified
 *   8. payment verify rejected for CSR without action.verify-payment (403)
 *   9. HTTP: GET /csr/dashboard returns 200 with JSON structure
 */

use App\Models\ClientApproval;
use App\Models\Inquiry;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Quotation;
use App\Services\CsrActivityLogger;
use App\Services\CsrDashboardService;
use App\Services\ClientApprovalService;
use App\Services\InquiryService;
use App\Services\OrderPaymentService;
use App\Services\PoCodeGenerator;
use App\Services\QuotationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    // ── Drop tables in reverse-FK order ────────────────────────────
    foreach ([
        'role_has_permissions',
        'model_has_permissions',
        'model_has_roles',
        'roles',
        'permissions',

        'csr_activity_logs',
        'client_approvals',
        'order_payments',
        'inquiries',
        'quotations',
        'notifications',
        'payment_methods',
        'orders',
        'clients',
        'users',
    ] as $t) {
        Schema::dropIfExists($t);
    }

    // ── Domain tables ──────────────────────────────────────────────

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

    Schema::create('clients', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('email')->nullable();
        $t->string('contact_number')->nullable();
        $t->string('address')->nullable();
        $t->string('method')->nullable();
        $t->string('courier')->nullable();
        $t->text('notes')->nullable();
        $t->string('messenger_link')->nullable();
        $t->string('facebook_link')->nullable();
        $t->string('gc_link')->nullable();
        $t->text('internal_notes')->nullable();
        $t->timestamps();
    });

    Schema::create('orders', function (Blueprint $t) {
        $t->id();
        $t->string('po_code')->unique();
        $t->unsignedBigInteger('client_id')->nullable();
        $t->string('client_name')->nullable();
        $t->string('client_brand')->nullable();
        $t->decimal('subtotal', 10, 2)->default(0);
        $t->decimal('grand_total', 10, 2)->default(0);
        $t->string('status')->default('new');
        $t->string('workflow_status', 32)->default('inquiry');
        $t->timestamp('delayed_at')->nullable();

        // Phase 6-A new fields
        $t->string('messenger_link')->nullable();
        $t->string('gc_link')->nullable();
        $t->string('priority', 16)->default('normal');
        $t->boolean('rush_order')->default(false);
        $t->string('sales_channel', 32)->nullable();
        $t->unsignedBigInteger('assigned_csr_user_id')->nullable();
        $t->date('deadline')->nullable();
        $t->text('internal_notes')->nullable();

        $t->timestamps();
        $t->softDeletes();
    });

    Schema::create('quotations', function (Blueprint $t) {
        $t->id();
        $t->string('quotation_id')->unique();
        $t->unsignedBigInteger('user_id')->nullable();
        $t->unsignedBigInteger('client_id')->nullable();
        $t->string('client_name')->nullable();
        $t->string('client_email')->nullable();
        $t->string('client_facebook')->nullable();
        $t->string('client_brand')->nullable();
        $t->text('notes')->nullable();
        $t->decimal('subtotal', 10, 2)->default(0);
        $t->decimal('grand_total', 10, 2)->default(0);
        $t->text('item_config_json')->nullable();
        $t->text('items_json')->nullable();
        $t->text('addons_json')->nullable();
        $t->string('status', 32)->default('Pending');
        $t->timestamps();
    });

    Schema::create('payment_methods', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->text('description')->nullable();
        $t->timestamps();
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

    Schema::create('inquiries', function (Blueprint $t) {
        $t->id();
        $t->string('inquiry_code', 32)->unique();
        $t->unsignedBigInteger('client_id')->nullable();
        $t->string('client_name');
        $t->string('client_email')->nullable();
        $t->string('client_contact')->nullable();
        $t->string('brand_name')->nullable();
        $t->string('source', 32)->nullable();
        $t->string('messenger_link')->nullable();
        $t->string('facebook_link')->nullable();
        $t->string('gc_link')->nullable();
        $t->text('product_interest')->nullable();
        $t->string('status', 16)->default('new');
        $t->unsignedBigInteger('assigned_csr_user_id')->nullable();
        $t->unsignedBigInteger('quotation_id')->nullable();
        $t->text('internal_notes')->nullable();
        $t->timestamps();
    });

    Schema::create('order_payments', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->string('payment_type', 16);
        $t->decimal('amount', 10, 2);
        $t->unsignedBigInteger('payment_method_id')->nullable();
        $t->string('reference_number')->nullable();
        $t->string('payer_name')->nullable();
        $t->timestamp('paid_at')->nullable();
        $t->string('proof_path', 255)->nullable();
        $t->string('status', 24)->default('waiting');
        $t->unsignedBigInteger('uploaded_by_user_id')->nullable();
        $t->timestamp('uploaded_at')->nullable();
        $t->unsignedBigInteger('verified_by_user_id')->nullable();
        $t->timestamp('verified_at')->nullable();
        $t->text('rejection_reason')->nullable();
        $t->text('notes')->nullable();
        $t->timestamps();
    });

    Schema::create('client_approvals', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->string('kind', 24);
        $t->string('status', 24)->default('waiting');
        $t->timestamp('requested_at')->nullable();
        $t->timestamp('responded_at')->nullable();
        $t->string('screenshot_path', 255)->nullable();
        $t->text('client_response_notes')->nullable();
        $t->text('internal_notes')->nullable();
        $t->unsignedBigInteger('requested_by_user_id')->nullable();
        $t->unsignedBigInteger('recorded_by_user_id')->nullable();
        $t->timestamps();
    });

    Schema::create('csr_activity_logs', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('user_id')->nullable();
        $t->string('action', 64);
        $t->string('subject_type')->nullable();
        $t->unsignedBigInteger('subject_id')->nullable();
        $t->unsignedBigInteger('order_id')->nullable();
        $t->unsignedBigInteger('client_id')->nullable();
        $t->string('summary', 255)->nullable();
        $t->json('data')->nullable();
        $t->timestamp('created_at')->useCurrent();
    });

    // ── All 5 Spatie tables (BUG-004) ──────────────────────────────
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

    Storage::fake('public');
});

afterEach(function () {
    foreach ([
        'role_has_permissions',
        'model_has_permissions',
        'model_has_roles',
        'roles',
        'permissions',
        'csr_activity_logs',
        'client_approvals',
        'order_payments',
        'inquiries',
        'quotations',
        'notifications',
        'payment_methods',
        'orders',
        'clients',
        'users',
    ] as $t) {
        Schema::dropIfExists($t);
    }
});

// ── Fixture builders ────────────────────────────────────────────────────

function csrMakeUser(array $permissionNames = ['portal.csr']): \App\Models\User
{
    $user = \App\Models\User::create([
        'name'          => 'Csr ' . uniqid(),
        'username'      => 'csr_' . uniqid(),
        'email'         => 'csr_' . uniqid() . '@test.local',
        'domain_access' => ['ash'],
        'domain_role'   => ['csr'],
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

function csrMakeOrder(array $overrides = []): Order
{
    return Order::create(array_merge([
        'po_code'         => 'ASH-2026-' . str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
        'client_name'     => 'ACME',
        'client_brand'    => 'Sorbetes',
        'subtotal'        => 100.00,
        'grand_total'     => 100.00,
        'workflow_status' => 'in_progress',
    ], $overrides));
}

// ── Tests ───────────────────────────────────────────────────────────────

test('dashboard endpoint returns all 8 KPI fields', function () {
    $user = csrMakeUser();
    $this->actingAs($user, 'sanctum');

    $svc = app(CsrDashboardService::class);
    $payload = $svc->summary();

    expect($payload)->toHaveKeys([
        'kpis',
        'tasks_and_alerts',
        'recent_activity',
        'my_inquiries',
        'my_orders',
    ]);

    expect($payload['kpis'])->toHaveKeys([
        'pending_inquiries',
        'pending_quotations',
        'client_approvals_needed',
        'pending_payments',
        'in_production_orders',
        'delayed_orders',
        'ready_for_delivery',
        'completed_orders',
    ]);
});

test('KPI counts return integers, never null', function () {
    $user = csrMakeUser();
    $this->actingAs($user, 'sanctum');

    $svc = app(CsrDashboardService::class);
    $kpis = $svc->kpis();

    foreach ($kpis as $key => $value) {
        expect($value)->toBeInt("KPI '$key' should be an integer");
    }
});

test('create inquiry creates row with auto inquiry_code', function () {
    $user = csrMakeUser();
    $this->actingAs($user, 'sanctum');

    $svc = app(InquiryService::class);
    $inquiry = $svc->create([
        'client_name'      => 'Walk-in Client A',
        'brand_name'       => 'BrandX',
        'source'           => 'FB',
        'product_interest' => 'Polo shirts',
    ]);

    expect($inquiry)->toBeInstanceOf(Inquiry::class);
    expect($inquiry->inquiry_code)->toMatch('/^INQ-\d{4}-\d{6}$/');
    expect($inquiry->status)->toBe('new');
    expect(Inquiry::count())->toBe(1);
});

test('convert inquiry to quotation creates draft + sets back-ref + status=converted', function () {
    $user = csrMakeUser();
    $this->actingAs($user, 'sanctum');

    $svc = app(InquiryService::class);
    $inquiry = $svc->create([
        'client_name'      => 'Convert Test Client',
        'brand_name'       => 'BrandY',
        'product_interest' => 'Hoodies',
    ]);

    $result = $svc->convertToQuotation($inquiry->id);

    expect($result)->toHaveKey('inquiry');
    expect($result)->toHaveKey('quotation');

    $convertedInquiry = $result['inquiry'];
    $newQuotation    = $result['quotation'];

    expect($convertedInquiry->status)->toBe('converted');
    expect($convertedInquiry->quotation_id)->toBe($newQuotation->id);
    expect($newQuotation->quotation_id)->toMatch('/^QUO-\d{4}-\d{6}$/');
    expect($newQuotation->status)->toBe('Draft');
    expect($newQuotation->client_name)->toBe('Convert Test Client');
    expect($newQuotation->client_brand)->toBe('BrandY');
});

test('convert inquiry rejects if already converted (returns 409)', function () {
    $user = csrMakeUser();
    $this->actingAs($user, 'sanctum');

    $svc = app(InquiryService::class);
    $inquiry = $svc->create(['client_name' => 'Idempotency Test']);

    // First call — succeeds
    $svc->convertToQuotation($inquiry->id);

    // Second call — must throw 409 ValidationException
    try {
        $svc->convertToQuotation($inquiry->id);
        $this->fail('Expected ValidationException with 409 status; nothing was thrown.');
    } catch (ValidationException $e) {
        expect($e->status)->toBe(409);
    }
});

test('payment proof upload sets status to for_verification', function () {
    $user = csrMakeUser();
    $this->actingAs($user, 'sanctum');

    $order = csrMakeOrder();

    $svc = app(OrderPaymentService::class);
    $proof = UploadedFile::fake()->image('proof.jpg');

    $payment = $svc->uploadProof(
        $order->id,
        [
            'payment_type' => 'down_payment',
            'amount'       => 5000.00,
        ],
        $proof,
    );

    expect($payment->status)->toBe('for_verification');
    expect($payment->proof_path)->not->toBeNull();
    expect($payment->uploaded_by_user_id)->toBe($user->id);
    expect($payment->uploaded_at)->not->toBeNull();
});

test('payment verify by user with action.verify-payment sets status to verified', function () {
    $financeUser = csrMakeUser(['portal.csr', 'action.verify-payment']);
    $this->actingAs($financeUser, 'sanctum');

    $order = csrMakeOrder();
    $svc   = app(OrderPaymentService::class);

    $payment = $svc->uploadProof(
        $order->id,
        ['payment_type' => 'down_payment', 'amount' => 5000.00],
        UploadedFile::fake()->image('proof.jpg'),
    );
    expect($payment->status)->toBe('for_verification');

    $verified = $svc->verify($payment->id, OrderPayment::STATUS_VERIFIED);

    expect($verified->status)->toBe('verified');
    expect($verified->verified_by_user_id)->toBe($financeUser->id);
    expect($verified->verified_at)->not->toBeNull();
});

test('payment verify rejected for CSR without action.verify-payment (403)', function () {
    // Two-user scenario: CSR uploads proof, then tries to verify (should be denied)
    $csr = csrMakeUser(['portal.csr']);   // NO action.verify-payment
    $this->actingAs($csr, 'sanctum');

    $order = csrMakeOrder();
    $svc   = app(OrderPaymentService::class);

    $payment = $svc->uploadProof(
        $order->id,
        ['payment_type' => 'down_payment', 'amount' => 5000.00],
        UploadedFile::fake()->image('proof.jpg'),
    );

    try {
        $svc->verify($payment->id, OrderPayment::STATUS_VERIFIED);
        $this->fail('CSR should not be allowed to verify payments.');
    } catch (ValidationException $e) {
        expect($e->status)->toBe(403);
    }
});

test('HTTP: GET /csr/dashboard returns 200 with JSON structure', function () {
    $user = csrMakeUser(['portal.csr']);
    $this->actingAs($user, 'sanctum');

    $response = $this->getJson('/api/v2/csr/dashboard');

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'data' => [
            'kpis' => [
                'pending_inquiries',
                'pending_quotations',
                'client_approvals_needed',
                'pending_payments',
                'in_production_orders',
                'delayed_orders',
                'ready_for_delivery',
                'completed_orders',
            ],
            'tasks_and_alerts',
            'recent_activity',
            'my_inquiries',
            'my_orders',
        ],
    ]);
});
// ── Phase 2: CSR payment recording access (reverses Change-17 read-only) ──

test('CSR with portal.csr can record a payment via POST /csr/payments', function () {
    Storage::fake('public');
    $user = csrMakeUser(['portal.csr']);
    $this->actingAs($user, 'sanctum');

    $order = csrMakeOrder();

    $response = $this->post('/api/v2/csr/payments', [
        'order_id'     => $order->id,
        'payment_type' => 'down_payment',
        'amount'       => 5000.00,
        'payer_name'   => 'Juan Dela Cruz',
        'paid_at'      => '2026-06-16 10:00:00',
        'proof'        => UploadedFile::fake()->image('proof.jpg'),
    ]);

    $response->assertStatus(201);

    $p = OrderPayment::where('order_id', $order->id)->first();
    expect($p)->not->toBeNull()
        ->and($p->payer_name)->toBe('Juan Dela Cruz')
        ->and($p->status)->toBe('for_verification');
});

test('CSR with portal.csr can fetch the awaiting-payment list via GET /csr/payments/awaiting', function () {
    $user = csrMakeUser(['portal.csr']);
    $this->actingAs($user, 'sanctum');

    $this->getJson('/api/v2/csr/payments/awaiting')
        ->assertStatus(200)
        ->assertJsonStructure(['data', 'count']);
});

test('payment recording is rejected for a user without portal.csr (403)', function () {
    $user = csrMakeUser([]);   // no permissions
    $this->actingAs($user, 'sanctum');

    $order = csrMakeOrder();

    $this->postJson('/api/v2/csr/payments', [
        'order_id'     => $order->id,
        'payment_type' => 'down_payment',
        'amount'       => 5000.00,
    ])->assertStatus(403);
});
