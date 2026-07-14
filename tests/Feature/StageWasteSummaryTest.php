<?php

/**
 * StageWasteSummaryService — per-stage waste aggregation for the Review Hub.
 *
 * Run with:
 *     php artisan test --filter=StageWasteSummaryTest
 *
 * Same hand-built in-memory SQLite strategy as the portal tests: we create only
 * the tables the service reads. No Spatie roles / HTTP needed — the service is a
 * pure read aggregation.
 *
 * Coverage:
 *   1. Fabric logs sum used/waste per stage and count entries.
 *   2. Ink logs sum used/waste per stage.
 *   3. Reject logs split quantity by disposition (reject vs repair).
 *   4. Legacy stage_waste_logs pcs merge into the same stage bucket.
 *   5. Stages with no logs are absent from the map.
 *   6. Aggregation is scoped to the order (other orders don't bleed in).
 *   7. Missing table (Schema::hasTable guard) => that source is skipped, no throw.
 */

use App\Services\StageWasteSummaryService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    foreach ([
        'stage_waste_logs',
        'stage_reject_logs',
        'stage_ink_logs',
        'stage_fabric_logs',
        'order_stages',
        'orders',
    ] as $t) {
        Schema::dropIfExists($t);
    }

    Schema::create('orders', function (Blueprint $t) {
        $t->id();
        $t->string('order_number')->nullable();
        $t->timestamps();
    });

    Schema::create('order_stages', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->string('stage', 64);
        $t->unsignedInteger('sequence')->default(0);
        $t->string('status', 32)->default('pending');
        $t->timestamps();
    });

    Schema::create('stage_fabric_logs', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->unsignedBigInteger('order_stage_id');
        $t->unsignedBigInteger('logged_by_user_id')->nullable();
        $t->string('material_type', 32)->nullable();
        $t->decimal('fabric_used_kg', 10, 2);
        $t->decimal('waste_kg', 10, 2)->default(0);
        $t->decimal('usable_remaining_kg', 10, 2)->default(0);
        $t->string('fabric_roll_id', 64)->nullable();
        $t->text('notes')->nullable();
        $t->timestamps();
    });

    Schema::create('stage_ink_logs', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->unsignedBigInteger('order_stage_id');
        $t->unsignedBigInteger('logged_by_user_id')->nullable();
        $t->string('ink_color', 64)->nullable();
        $t->decimal('ink_used_kg', 10, 3);
        $t->decimal('ink_waste_kg', 10, 3)->default(0);
        $t->decimal('usable_remaining_kg', 10, 3)->default(0);
        $t->text('notes')->nullable();
        $t->timestamps();
    });

    Schema::create('stage_reject_logs', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->unsignedBigInteger('order_stage_id');
        $t->unsignedBigInteger('logged_by_user_id')->nullable();
        $t->integer('quantity_pcs');
        // MySQL ENUM -> VARCHAR in the test harness (SQLite/Pest compatibility).
        $t->string('disposition', 16)->default('reject');
        $t->unsignedBigInteger('reject_reason_id')->nullable();
        $t->string('photo_path')->nullable();
        $t->text('notes')->nullable();
        $t->timestamps();
    });

    Schema::create('stage_waste_logs', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('order_id');
        $t->unsignedBigInteger('order_stage_id');
        $t->unsignedBigInteger('logged_by_user_id')->nullable();
        $t->unsignedInteger('quantity_pcs');
        $t->string('photo_path')->nullable();
        $t->text('notes')->nullable();
        $t->timestamps();
    });

    // Order 1 with three stages that get logs + one that gets none.
    DB::table('orders')->insert(['id' => 1, 'order_number' => 'ASH-2026-000025']);
    DB::table('orders')->insert(['id' => 2, 'order_number' => 'ASH-2026-000099']);

    foreach ([
        ['id' => 101, 'order_id' => 1, 'stage' => 'mass_cutting',  'sequence' => 1],
        ['id' => 102, 'order_id' => 1, 'stage' => 'mass_printing', 'sequence' => 2],
        ['id' => 103, 'order_id' => 1, 'stage' => 'mass_qa',       'sequence' => 3],
        ['id' => 104, 'order_id' => 1, 'stage' => 'packing',       'sequence' => 4],
    ] as $s) {
        DB::table('order_stages')->insert($s + ['status' => 'in_progress']);
    }

    // Fabric on cutting (two entries) — order 1.
    DB::table('stage_fabric_logs')->insert([
        ['order_id' => 1, 'order_stage_id' => 101, 'fabric_used_kg' => 10.00, 'waste_kg' => 1.00],
        ['order_id' => 1, 'order_stage_id' => 101, 'fabric_used_kg' => 5.50,  'waste_kg' => 0.50],
    ]);
    // Fabric on the SAME stage id but a DIFFERENT order — must not bleed in.
    DB::table('stage_fabric_logs')->insert([
        ['order_id' => 2, 'order_stage_id' => 101, 'fabric_used_kg' => 99.00, 'waste_kg' => 9.00],
    ]);

    // Ink on printing (one entry).
    DB::table('stage_ink_logs')->insert([
        ['order_id' => 1, 'order_stage_id' => 102, 'ink_used_kg' => 0.800, 'ink_waste_kg' => 0.050],
    ]);

    // Rejects on QA — 3 reject + 2 reject + 1 repair.
    DB::table('stage_reject_logs')->insert([
        ['order_id' => 1, 'order_stage_id' => 103, 'quantity_pcs' => 3, 'disposition' => 'reject'],
        ['order_id' => 1, 'order_stage_id' => 103, 'quantity_pcs' => 2, 'disposition' => 'reject'],
        ['order_id' => 1, 'order_stage_id' => 103, 'quantity_pcs' => 1, 'disposition' => 'repair'],
    ]);

    // Legacy generic waste on the printing stage — merges into the ink bucket.
    DB::table('stage_waste_logs')->insert([
        ['order_id' => 1, 'order_stage_id' => 102, 'quantity_pcs' => 4],
    ]);
});

test('aggregates fabric used/waste per stage and counts entries', function () {
    $out = (new StageWasteSummaryService())->forOrder(1);

    expect($out)->toHaveKey(101);
    expect($out[101]['fabric']['used_kg'])->toBe(15.5);
    expect($out[101]['fabric']['waste_kg'])->toBe(1.5);
    expect($out[101]['fabric']['entries'])->toBe(2);
    // Cutting has no ink / rejects / generic waste.
    expect($out[101])->not->toHaveKey('ink');
    expect($out[101])->not->toHaveKey('rejects');
    expect($out[101])->not->toHaveKey('other');
});

test('aggregates ink used/waste and merges legacy waste into the same stage', function () {
    $out = (new StageWasteSummaryService())->forOrder(1);

    expect($out)->toHaveKey(102);
    expect($out[102]['ink']['used_kg'])->toBe(0.8);
    expect($out[102]['ink']['waste_kg'])->toBe(0.05);
    expect($out[102]['ink']['entries'])->toBe(1);
    // Legacy stage_waste_logs merged into the SAME stage bucket.
    expect($out[102]['other']['pcs'])->toBe(4);
    expect($out[102]['other']['entries'])->toBe(1);
});

test('splits reject quantity by disposition (reject vs repair)', function () {
    $out = (new StageWasteSummaryService())->forOrder(1);

    expect($out)->toHaveKey(103);
    expect($out[103]['rejects']['reject_pcs'])->toBe(5);
    expect($out[103]['rejects']['repair_pcs'])->toBe(1);
    expect($out[103]['rejects']['entries'])->toBe(3);
});

test('stages with no waste rows are absent from the map', function () {
    $out = (new StageWasteSummaryService())->forOrder(1);

    expect($out)->not->toHaveKey(104);
});

test('aggregation is scoped to the order (no cross-order bleed)', function () {
    $out = (new StageWasteSummaryService())->forOrder(1);

    // Order 2 has a 99kg fabric row on stage 101, but order 1's stage 101
    // must still report exactly its own two entries / 15.5kg.
    expect($out[101]['fabric']['entries'])->toBe(2);
    expect($out[101]['fabric']['used_kg'])->toBe(15.5);

    // And order 2's own summary sees only its row.
    $out2 = (new StageWasteSummaryService())->forOrder(2);
    expect($out2)->toHaveKey(101);
    expect($out2[101]['fabric']['used_kg'])->toBe(99.0);
    expect($out2[101]['fabric']['entries'])->toBe(1);
});

test('a missing source table is skipped without throwing', function () {
    Schema::dropIfExists('stage_ink_logs');

    $out = (new StageWasteSummaryService())->forOrder(1);

    // Fabric + rejects + generic waste still aggregate; ink is simply gone.
    expect($out)->toHaveKey(101);
    expect($out[101]['fabric']['used_kg'])->toBe(15.5);
    expect($out)->toHaveKey(102);
    expect($out[102])->not->toHaveKey('ink');
    expect($out[102]['other']['pcs'])->toBe(4);
    expect($out)->toHaveKey(103);
});
