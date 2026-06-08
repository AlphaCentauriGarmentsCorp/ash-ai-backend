<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;


return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {

        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            'frontend.access' => \App\Http\Middleware\FrontendAccess::class
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // ── Change 13: structured failure responses ──────────────────────
        // Business-rule failures (expected, user-facing) render with a
        // stable machine `code` the frontend maps to a specific message.
        $exceptions->render(function (\App\Exceptions\BusinessRuleException $e, $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'type'    => 'business',
                    'code'    => $e->errorCode(),
                    'message' => $e->getMessage(),
                    'errors'  => $e->details(),
                ], $e->status());
            }

            return null;
        });

        // Unexpected failures (500s) render a masked message plus a short
        // reference code that is ALSO written to the log, so a user report
        // can be correlated to the exact log line. Framework exceptions that
        // already render correctly (validation, auth, HTTP 4xx, and our own
        // business errors) are left untouched. In local debug we surface the
        // real trace instead of masking it.
        $exceptions->render(function (\Throwable $e, $request) {
            if (! ($request->expectsJson() || $request->is('api/*'))) {
                return null;
            }

            if ($e instanceof \App\Exceptions\BusinessRuleException
                || $e instanceof \Illuminate\Validation\ValidationException
                || $e instanceof \Illuminate\Auth\AuthenticationException
                || $e instanceof \Illuminate\Auth\Access\AuthorizationException
                || $e instanceof \Illuminate\Http\Exceptions\HttpResponseException
                || $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                return null;
            }

            if (config('app.debug')) {
                return null; // surface the real error locally
            }

            $reference = strtoupper(\Illuminate\Support\Str::random(10));

            \Illuminate\Support\Facades\Log::error('Unhandled API exception [' . $reference . ']', [
                'reference' => $reference,
                'message'   => $e->getMessage(),
                'exception' => $e,
            ]);

            return response()->json([
                'type'      => 'server',
                'code'      => 'SERVER_ERROR',
                'message'   => 'Something went wrong on our end. Please try again. If this keeps happening, quote reference ' . $reference . '.',
                'reference' => $reference,
            ], 500);
        });
    })->create();
