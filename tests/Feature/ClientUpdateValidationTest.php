<?php

/**
 * Change 6 hotfix — ClientUpdateRequest must accept cleared/empty optional
 * fields. The global ConvertEmptyStringsToNull middleware turns an empty
 * address/courier/method/notes into null; without `nullable` on those rules
 * the `string` rule failed and the whole client update 422'd (e.g. editing a
 * client that has no address yet).
 *
 * Run with:
 *     php artisan test --filter=ClientUpdateValidationTest
 *
 * Pure rules check via the Validator — no HTTP/auth/DB needed.
 */

use App\Http\Requests\Client\ClientUpdateRequest;
use Illuminate\Support\Facades\Validator;

function clientUpdateRules(): array
{
    return (new ClientUpdateRequest())->rules();
}

it('passes when optional fields are null (empty form fields)', function () {
    // Mirrors the post-middleware payload for editing a client with no address.
    $payload = [
        'first_name'     => 'Andrew',
        'last_name'      => 'Egido',
        'email'          => 'egidoandrew19@gmail.com',
        'contact_number' => '09954764887',
        'street_address' => null,
        'barangay'       => null,
        'city'           => null,
        'province'       => null,
        'postal_code'    => null,
        'courier'        => null,
        'method'         => null,
        'notes'          => null,
        'brands'         => [['name' => 'LAPIS DE BLANKO']],
    ];

    $validator = Validator::make($payload, clientUpdateRules());

    expect($validator->fails())->toBeFalse();
    expect($validator->errors()->all())->toBe([]);
});

it('passes when optional fields carry real values', function () {
    $payload = [
        'first_name'     => 'Andrew',
        'last_name'      => 'Egido',
        'street_address' => '123 Rizal St, Unit 4',
        'barangay'       => 'Brgy Uno',
        'city'           => 'Cebu City',
        'province'       => 'Cebu',
        'postal_code'    => '6000',
    ];

    expect(Validator::make($payload, clientUpdateRules())->fails())->toBeFalse();
});

it('still rejects a genuinely invalid optional field', function () {
    // postal_code over max:10 should still fail — nullable doesn't loosen that.
    $payload = ['postal_code' => '12345678901234567890'];

    expect(Validator::make($payload, clientUpdateRules())->fails())->toBeTrue();
});
