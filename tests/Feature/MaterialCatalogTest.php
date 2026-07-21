<?php

/**
 * Materials & Suppliers — nullable schema + catalog seeder.
 *
 * Run with:
 *     php artisan test --filter=MaterialCatalogTest
 *
 * Mirrors the hand-built-schema isolation used by MaterialPurchaseRequestTest
 * (suppliers + materials only). Verifies:
 *   - a material can be created with ONLY a name (supplier_id + material_type null)
 *   - MaterialCatalogSeeder loads all 54 rows and is idempotent (re-run = still 54)
 */

use App\Models\Materials;
use Database\Seeders\MaterialCatalogSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    foreach (['materials', 'suppliers'] as $t) {
        Schema::dropIfExists($t);
    }

    Schema::create('suppliers', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->timestamps();
    });

    // Matches the live schema after the nullable migration.
    Schema::create('materials', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('supplier_id')->nullable();
        $t->string('name');
        $t->string('material_type')->nullable();
        $t->string('unit')->nullable();
        $t->decimal('price', 10, 2)->nullable();
        $t->decimal('stock_on_hand', 12, 2)->default(0);
        $t->string('minimum')->nullable();
        $t->string('lead')->nullable();
        $t->text('notes')->nullable();
        $t->timestamps();
    });
});

it('creates a material with only a name (supplier + type optional)', function () {
    $material = Materials::create(['name' => 'Mystery Material']);

    expect($material->exists)->toBeTrue()
        ->and($material->name)->toBe('Mystery Material')
        ->and($material->supplier_id)->toBeNull()
        ->and($material->material_type)->toBeNull();

    expect(Materials::count())->toBe(1);
});

it('seeds the 54-item catalog and is idempotent', function () {
    (new MaterialCatalogSeeder())->run();
    expect(Materials::count())->toBe(54);

    // A known row lands correctly, with no supplier attached.
    $carmine = Materials::where('name', 'Carmine Red Silkscreen Paint (Keenworth)')->first();
    expect($carmine)->not->toBeNull()
        ->and($carmine->material_type)->toBe('Silkscreen Paint')
        ->and($carmine->unit)->toBe('per kg')
        ->and((float) $carmine->price)->toBe(3075.00)
        ->and($carmine->supplier_id)->toBeNull();

    // Poly bags carry the pack note.
    $bag = Materials::where('name', '40x60 Poly Bag')->first();
    expect($bag->notes)->toBe('Sold in packs of 100');

    // Re-running must NOT duplicate (firstOrCreate on name + material_type).
    (new MaterialCatalogSeeder())->run();
    expect(Materials::count())->toBe(54);
});

it('reads + updates a material via the service (supplier/type clearable)', function () {
    $svc = new \App\Services\MaterialsService();

    $m = Materials::create([
        'name'          => 'Temp Ink',
        'supplier_id'   => null,
        'material_type' => 'Ink',
        'price'         => 10,
    ]);

    // getById returns the row (with supplier eager-loaded → null here)
    expect($svc->getById($m->id)->name)->toBe('Temp Ink');

    // update can clear material_type and rename
    $updated = $svc->update($m->id, ['name' => 'Renamed Ink', 'material_type' => null]);
    expect($updated->name)->toBe('Renamed Ink')
        ->and($updated->material_type)->toBeNull();

    // missing id → null (controller turns this into a 404)
    expect($svc->update(999999, ['name' => 'nope']))->toBeNull();
    expect($svc->getById(999999))->toBeNull();
});
