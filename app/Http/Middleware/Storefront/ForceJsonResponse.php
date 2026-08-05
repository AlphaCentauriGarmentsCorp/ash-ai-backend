<?php

namespace App\Http\Middleware\Storefront;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guarantee that every storefront endpoint answers in JSON, including on the
 * framework-rendered error paths.
 *
 * The source project got this from its own bootstrap/app.php:
 *
 *     $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*'));
 *
 * Here bootstrap/app.php belongs to the ERP and stays untouched, so the guarantee is
 * re-established from inside the storefront's own route group instead. Without it,
 * Illuminate's handler falls back to $request->expectsJson(), which is FALSE for any
 * client that sends a wildcard Accept (curl's `Accept: * / *`, without the spaces) or
 * no Accept header at all (the default of most server-to-server HTTP clients). On
 * that path a failed
 * ValidationException does not become a 422 JSON error bag — it goes down
 * Handler::invalid() and comes back as a 302 redirect to '/' with an HTML body, and a
 * route-model-binding miss comes back as an HTML error page. A machine client that
 * follows redirects would then see 200 OK HTML for a rejected write.
 *
 * The SPA is unaffected either way (its axios instance hard-sets Accept), so the
 * callers this protects are the non-browser ones: the machine-to-machine inventory
 * client and any curl/server-side integration.
 *
 * Implemented by rewriting the request's Accept header rather than by registering
 * $exceptions->shouldRenderJsonWhen() from the service provider, deliberately:
 *
 *  - that setter takes a SINGLE global callback, so setting it would also decide
 *    JSON-vs-HTML for the ERP's ~440 /api/v2/* routes — a behaviour change outside
 *    this integration's boundary;
 *  - the handler bound in console/test runs is Collision's adapter, which is `final`
 *    and does not implement the method at all, so the call is not even safe to make.
 *
 * Mutating the header is scoped to exactly the requests in this route group, needs no
 * ERP file, and additionally makes the controllers' own expectsJson()/wantsJson()
 * checks agree with what the client will actually be sent.
 */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        // Only ever widened, never narrowed: a caller that already asked for JSON
        // (the SPA, the test suite) is left exactly as it was.
        if (! $request->expectsJson()) {
            $request->headers->set('Accept', 'application/json');
        }

        return $next($request);
    }
}
