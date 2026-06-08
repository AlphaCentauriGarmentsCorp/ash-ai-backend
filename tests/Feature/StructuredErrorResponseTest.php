<?php

use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\Route;

/**
 * Change 13 — structured failure responses.
 *
 * Verifies the global handler wired in bootstrap/app.php:
 *   - BusinessRuleException renders { type:business, code, message, errors }
 *     at its chosen status.
 *   - An unexpected Throwable renders { type:server, reference } (500), but
 *     only when app.debug is off (local debug still surfaces the trace).
 *   - Framework HTTP errors (e.g. 404) are NOT hijacked into the server
 *     envelope.
 *
 * No DB needed — we register throwaway routes for the duration of the test.
 */

beforeEach(function () {
    Route::get('/_t/business', function () {
        throw new BusinessRuleException('Nope, rule violated.', 'SAMPLE_RULE', 422, ['field' => 'bad']);
    });
    Route::get('/_t/business-403', function () {
        throw new BusinessRuleException('Forbidden by rule.', 'SAMPLE_FORBIDDEN', 403);
    });
    Route::get('/_t/boom', function () {
        throw new \RuntimeException('low-level kaboom that must not leak');
    });
    Route::get('/_t/notfound', function () {
        abort(404, 'missing');
    });
});

it('renders business-rule failures with a machine code', function () {
    $this->getJson('/_t/business')
        ->assertStatus(422)
        ->assertJson([
            'type'    => 'business',
            'code'    => 'SAMPLE_RULE',
            'message' => 'Nope, rule violated.',
            'errors'  => ['field' => 'bad'],
        ]);
});

it('honours the business exception status', function () {
    $this->getJson('/_t/business-403')
        ->assertStatus(403)
        ->assertJson(['type' => 'business', 'code' => 'SAMPLE_FORBIDDEN']);
});

it('masks unexpected 500s with a reference code when debug is off', function () {
    config(['app.debug' => false]);

    $res = $this->getJson('/_t/boom')->assertStatus(500);

    $res->assertJson(['type' => 'server', 'code' => 'SERVER_ERROR']);
    expect($res->json('reference'))->toBeString()->not->toBeEmpty();
    // The masked message must not leak the underlying exception text.
    expect($res->json('message'))->not->toContain('kaboom');
});

it('does not hijack framework HTTP errors into the server envelope', function () {
    config(['app.debug' => false]);

    $res = $this->getJson('/_t/notfound')->assertStatus(404);
    expect($res->json('type'))->not->toBe('server');
});
