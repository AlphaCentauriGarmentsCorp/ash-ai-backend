<?php

/**
 * Change 6 (option B) — ClientService address persistence.
 *
 * Verifies the client master now stores REAL granular address columns
 * (instead of the old concat-into-one-string + positional-split hack), and
 * that the derived single-line `address` is composed from those parts.
 *
 * Run with:
 *     php artisan test --filter=ClientServiceAddressTest
 *
 * Same isolation pattern as the other Phase tests — hand-built schema so we
 * don't depend on full migrations/seeders.
 */

use App\Models\Client;
use App\Services\ClientService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    foreach (['client_brands', 'clients'] as $t) {
        Schema::dropIfExists($t);
    }

    Schema::create('clients', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('email')->nullable();
        $table->string('contact_number')->nullable();
        $table->string('address')->nullable();
        $table->string('street_address')->nullable();
        $table->string('barangay')->nullable();
        $table->string('city')->nullable();
        $table->string('province')->nullable();
        $table->string('postal_code', 10)->nullable();
        $table->string('courier')->nullable();
        $table->string('method')->nullable();
        $table->text('notes')->nullable();
        $table->timestamps();
    });

    Schema::create('client_brands', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('client_id');
        $table->string('brand_name');
        $table->string('logo_url')->nullable();
        $table->timestamps();
    });
});

afterEach(function () {
    foreach (['client_brands', 'clients'] as $t) {
        Schema::dropIfExists($t);
    }
});

function makeClientData(array $overrides = []): array
{
    return array_merge([
        'first_name'     => 'Juan',
        'last_name'      => 'Dela Cruz',
        'email'          => 'juan@example.com',
        'contact_number' => '09171234567',
        'street_address' => '123 Rizal St, Unit 4',  // note the comma — the old split would corrupt this
        'barangay'       => 'Brgy Uno',
        'city'           => 'Cebu City',
        'province'       => 'Cebu',
        'postal_code'    => '6000',
        'method'         => null,
        'courier'        => null,
        'notes'          => null,
        'brands'         => [['name' => 'Acme']],
    ], $overrides);
}

it('persists granular address columns on create', function () {
    $svc = app(ClientService::class);
    $client = $svc->create(makeClientData());

    $fresh = Client::find($client->id);

    expect($fresh->street_address)->toBe('123 Rizal St, Unit 4');
    expect($fresh->barangay)->toBe('Brgy Uno');
    expect($fresh->city)->toBe('Cebu City');
    expect($fresh->province)->toBe('Cebu');
    expect($fresh->postal_code)->toBe('6000');
});

it('derives the legacy single-line address from the granular parts', function () {
    $svc = app(ClientService::class);
    $client = $svc->create(makeClientData());

    // Comma-containing street stays intact; empty parts are skipped.
    expect($client->fresh()->address)
        ->toBe('123 Rizal St, Unit 4, Brgy Uno, Cebu City, Cebu, 6000');
});

it('updates a single address part without blanking the others', function () {
    $svc = app(ClientService::class);
    $client = $svc->create(makeClientData());

    // Partial update: only the city changes.
    $svc->update($client->id, [
        'first_name' => 'Juan',
        'last_name'  => 'Dela Cruz',
        'city'       => 'Mandaue City',
    ]);

    $fresh = Client::find($client->id);

    expect($fresh->city)->toBe('Mandaue City');
    // Untouched parts survive.
    expect($fresh->street_address)->toBe('123 Rizal St, Unit 4');
    expect($fresh->barangay)->toBe('Brgy Uno');
    expect($fresh->province)->toBe('Cebu');
    expect($fresh->postal_code)->toBe('6000');
    // Derived address reflects the change.
    expect($fresh->address)->toContain('Mandaue City');
});
