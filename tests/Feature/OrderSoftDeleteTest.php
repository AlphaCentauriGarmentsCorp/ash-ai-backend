<?php

use App\Models\Order;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * Soft-delete behaviour for the Order model.
 *
 * Verifies the parts of the soft-delete change that are testable on the
 * suite's in-memory SQLite (hand-built schema, consistent with the other
 * isolated tests in this suite):
 *   - delete() sets deleted_at instead of removing the row
 *   - default queries exclude trashed orders
 *   - withTrashed() / restore() recover them
 *
 * NOTE: the PO-code-collision fix (generatePoCode using withTrashed) is
 * NOT exercised here because generatePoCode relies on MySQL's
 * SUBSTRING_INDEX, which SQLite doesn't implement. That fix is verified by
 * reasoning (Eloquent's soft-delete global scope would otherwise hide the
 * latest trashed order and let its PO number be reused). A MySQL-backed
 * integration test would be the place to assert it end-to-end.
 */

beforeEach(function () {
    Schema::dropIfExists('orders');

    Schema::create('orders', function (Blueprint $table) {
        $table->id();
        $table->string('po_code')->nullable();
        $table->string('client_name')->nullable();
        $table->string('workflow_status')->nullable();
        $table->unsignedBigInteger('current_stage_id')->nullable();
        $table->timestamps();
        $table->softDeletes(); // the column this change adds
    });
});

afterEach(function () {
    Schema::dropIfExists('orders');
});

it('soft-deletes an order instead of removing the row', function () {
    $order = Order::create([
        'po_code'         => 'ASH-2026-000001',
        'client_name'     => 'Test Client',
        'workflow_status' => 'inquiry',
    ]);

    $order->delete();

    // Row still physically present, but marked deleted.
    expect(Order::withTrashed()->count())->toBe(1);
    expect($order->fresh()->deleted_at)->not->toBeNull();
});

it('excludes trashed orders from default queries', function () {
    Order::create(['po_code' => 'ASH-2026-000001', 'workflow_status' => 'inquiry']);
    $toTrash = Order::create(['po_code' => 'ASH-2026-000002', 'workflow_status' => 'inquiry']);

    $toTrash->delete();

    // Default query (what index/withActiveStage/show use) sees only the live one.
    expect(Order::count())->toBe(1);
    expect(Order::first()->po_code)->toBe('ASH-2026-000001');

    // But the data is still there and recoverable.
    expect(Order::withTrashed()->count())->toBe(2);
});

it('restores a soft-deleted order', function () {
    $order = Order::create(['po_code' => 'ASH-2026-000001', 'workflow_status' => 'inquiry']);
    $order->delete();
    expect(Order::count())->toBe(0);

    $order->restore();
    expect(Order::count())->toBe(1);
    expect($order->fresh()->deleted_at)->toBeNull();
});
