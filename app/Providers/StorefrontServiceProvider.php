<?php

namespace App\Providers;

use App\Contracts\Storefront\PaymentGateway;
use App\Http\Middleware\Storefront\AuthenticateApiToken;
use App\Http\Middleware\Storefront\ForceJsonResponse;
use App\Models\Storefront\Customer;
use App\Services\Storefront\PayMongoGateway;
use App\Services\Storefront\PricingService;
use App\Services\Storefront\SimulatedPaymentGateway;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The single integration point for the REEFER storefront inside the ASH-AI ERP.
 *
 * Everything the storefront needs at runtime is wired here — services, its own auth
 * guard and password broker, its CORS origins, its routes and its rate limiters —
 * so the port stays additive: no ERP config file, route file or bootstrap file is
 * edited, and removing the one line in bootstrap/providers.php removes the
 * storefront cleanly.
 *
 * Deliberately NOT here: Model::preventLazyLoading(). The source project calls it in
 * its AppServiceProvider, but the setting is global and the ERP's own queries lazy
 * load on purpose — enabling it would fail their code, not ours.
 */
class StorefrontServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->registerPaymentServices();
        $this->registerCustomerGuard();
        $this->registerCorsOrigins();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
        $this->prioritiseJsonResponses();
        $this->normaliseNotFoundResponses();
        $this->scheduleStockAlerts();
        $this->honourHttpsBehindProxy();

        /*
         * Mounted under its own prefix, NOT merged into routes/api.php: the ERP
         * already serves /api/v1/orders, /api/v1/me and friends, and the storefront
         * defines its own. Final URLs are /api/storefront/v1/...
         *
         * config/cors.php already covers 'api/*', so the cross-origin SPA is handled
         * without touching that file.
         *
         * ForceJsonResponse and 'throttle:storefront-api' are attached to THIS group
         * rather than to the shared `api` group, which is the ERP's. The source got
         * both from its own bootstrap/app.php — `$middleware->api(append:
         * ['throttle:api'])` and `$exceptions->shouldRenderJsonWhen(...)` — but here
         * `api` is bare and carries the ERP's ~440 /api/v2/* routes with it, so
         * appending there would silently change ERP behaviour. Scoping them to the
         * storefront group restores the source's protection with zero ERP effect.
         */
        Route::prefix('api/storefront')
            ->middleware(['api', ForceJsonResponse::class, 'throttle:storefront-api'])
            ->group(base_path('routes/storefront.php'));
    }

    /**
     * Generate https:// URLs when the site is served over TLS.
     *
     * Product photos are the reason. Both the shop's ProductResource and the stock
     * manager's InventoryData build image URLs with asset(), which takes its scheme
     * from the incoming request. On a host that terminates TLS at a proxy — which
     * is how the Hostinger deployment works — the request arriving at PHP is plain
     * http, so every image URL comes out http:// on an https:// page and the
     * browser blocks it as mixed content. The catalogue renders with every
     * thumbnail missing and nothing in the network tab marked as an error.
     *
     * The complete fix is TrustProxies, which also repairs the client IP that every
     * rate limiter keys on. That lives in bootstrap/app.php, and this integration is
     * contractually forbidden from touching it (see STOREFRONT_INTEGRATION.md §7) —
     * so this covers the URL half from the one file the storefront does own, and §6
     * documents the one-line proxy change for whoever owns the ERP.
     *
     * Keyed off APP_URL rather than a new flag: if the app is configured to live at
     * an https address, its generated URLs should say so. Forcing it unconditionally
     * would break local http development.
     */
    private function honourHttpsBehindProxy(): void
    {
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }
    }

    /**
     * Give every storefront 404 the same body.
     *
     * Laravel turns a route-model-binding miss into a NotFoundHttpException carrying
     * ModelNotFoundException's message, and that message survives into the JSON — with
     * APP_DEBUG=false too, which is worth stating because it looks like a debug artifact
     * and is not (verified by running the suite with APP_DEBUG=false). So a request for
     * an id that does not exist answered
     *     {"message":"No query results for model [App\\Models\\Storefront\\Address] 999999"}
     * while a request for an id that exists but belongs to somebody else answered a 404
     * with an empty message. Two things leak from that: the internal model class name,
     * and — because the two bodies differ — whether any given id is real. An owner-scoped
     * resource must not confirm its own existence to someone who cannot read it.
     *
     * Registered as a renderable on the shared handler but gated on the storefront path
     * prefix, so ERP routes keep whatever behaviour they have. Returning null for
     * anything else lets the handler carry on as normal.
     */
    private function normaliseNotFoundResponses(): void
    {
        $this->callAfterResolving(ExceptionHandler::class, function (ExceptionHandler $handler): void {
            if (! $handler instanceof Handler) {
                return;
            }

            $handler->renderable(function (NotFoundHttpException $e, Request $request) {
                if (! $request->is('api/storefront/*')) {
                    return null;
                }

                return response()->json(['message' => 'Not found.'], 404);
            });
        });
    }

    /**
     * Run the back-in-stock mailer on a schedule.
     *
     * Without this the feature is a dead end that looks alive: StockAlertController
     * happily takes subscriptions and writes storefront_stock_alerts rows, and
     * reefer:notify-back-in-stock is the only thing that ever reads them — so with
     * nothing calling it, shoppers wait for an email that has no sender. `artisan
     * schedule:list` reported "No scheduled tasks have been defined" for this whole
     * application, so there was no existing entry to sit beside.
     *
     * Declared here rather than in routes/console.php or bootstrap/app.php because
     * both are the ERP's, and the point of this provider is that the storefront adds
     * nothing to files it does not own. Removing the provider removes this too.
     *
     * Console-only: the schedule is read by `schedule:run`, so building it during an
     * HTTP request would cost the ERP's ~440 routes a container resolution none of
     * them use. withoutOverlapping so a slow SMTP run cannot stack on the next tick.
     *
     * NOTE this needs the server's cron to be calling `php artisan schedule:run`
     * every minute. Where it is not, this is inert rather than wrong — the same
     * silence as before, but now one cron entry away from working.
     */
    private function scheduleStockAlerts(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        // Deferred: the scheduler is resolved out of the container, which is not
        // ready to hand it over while providers are still booting.
        $this->app->booted(function () {
            $this->app->make(Schedule::class)
                ->command('reefer:notify-back-in-stock')
                ->everyFifteenMinutes()
                ->withoutOverlapping();
        });
    }

    /**
     * Make ForceJsonResponse run FIRST in the storefront pipeline.
     *
     * Listing it first in the route group is not enough. Router::sortMiddleware hoists
     * every middleware named in the kernel's priority list — which includes
     * ThrottleRequests and SubstituteBindings — ahead of anything that is not named
     * there. Measured, not assumed: before this, the storefront pipeline sorted to
     * [ThrottleRequests, SubstituteBindings, ForceJsonResponse], so a 429 from the
     * throttle and a 404 from a route-model-binding miss were both still rendered as
     * HTML for a client that sends no Accept header.
     *
     * Naming our own class in the priority list only affects where OUR middleware
     * sorts — it appears in no other group or route in this application — so the ERP's
     * pipelines are unchanged.
     *
     * Registered both ways on purpose: under PHP-FPM the HTTP kernel is resolved in
     * public/index.php BEFORE providers boot (so the direct call is the one that
     * lands), while under the test runner it is resolved lazily on the first request,
     * AFTER boot — and the framework's own afterResolving callback would otherwise
     * overwrite the priority list with the default and drop our entry.
     */
    private function prioritiseJsonResponses(): void
    {
        $prepend = function (object $kernel): void {
            // Guarded rather than type-hinted: the container key is the CONTRACT, which
            // does not declare this method. Anything but the standard kernel is simply
            // left alone instead of fatalling.
            if ($kernel instanceof HttpKernel) {
                /*
                 * Order matters, and these are prepended in reverse: the LAST prepend
                 * ends up first.
                 *
                 * AuthenticateApiToken must outrank SubstituteBindings. Both are in the
                 * storefront pipeline, but only SubstituteBindings is named in the
                 * framework's default priority list, so it sorted ahead of our custom
                 * class and route-model binding ran BEFORE authentication. That turned
                 * every owner-scoped route into an existence oracle for anonymous
                 * callers: a real id bound successfully and then answered 401, while a
                 * made-up id 404'd at binding — so the status code alone told an
                 * unauthenticated stranger which ids exist. Authenticating first makes
                 * both answer 401.
                 */
                $kernel->prependToMiddlewarePriority(AuthenticateApiToken::class);
                $kernel->prependToMiddlewarePriority(ForceJsonResponse::class);
            }
        };

        if ($this->app->resolved(HttpKernelContract::class)) {
            $prepend($this->app->make(HttpKernelContract::class));
        }

        // No-op when the kernel is already resolved; the sole path when it is not.
        $this->app->afterResolving(HttpKernelContract::class, $prepend);
    }

    private function registerPaymentServices(): void
    {
        $this->app->singleton(PricingService::class, fn () => new PricingService());
        $this->app->singleton(SimulatedPaymentGateway::class, fn () => new SimulatedPaymentGateway());
        $this->app->singleton(PayMongoGateway::class, fn () => new PayMongoGateway());

        // Credentials are the switch. With no PAYMONGO_SECRET_KEY — this
        // environment, and any fresh clone — checkout runs on the simulator
        // exactly as before; drop a key in .env and the real processor takes
        // over without a line of order code changing.
        //
        // The key is read from config/reefer.php rather than config/services.php:
        // services.php is the ERP's file and this integration does not edit it.
        $this->app->singleton(PaymentGateway::class, fn ($app) => config('reefer.paymongo.secret')
            ? $app->make(PayMongoGateway::class)
            : $app->make(SimulatedPaymentGateway::class));
    }

    /**
     * Register the customer guard, its provider and its password broker at runtime.
     *
     * config/auth.php is the ERP's and stays untouched. Its 'web'/'sanctum' guards
     * resolve App\Models\User — ERP STAFF, whose rows carry username/domain_role/
     * domain_access. Shoppers are App\Models\Storefront\Customer in
     * storefront_users, a different table with different columns, so they need
     * their own guard, provider and reset-token table or a customer password reset
     * would look up staff accounts and write to the staff table.
     */
    private function registerCustomerGuard(): void
    {
        config([
            'auth.guards.storefront' => [
                'driver' => 'session',
                'provider' => 'storefront_customers',
            ],
            'auth.providers.storefront_customers' => [
                'driver' => 'eloquent',
                'model' => Customer::class,
            ],
            'auth.passwords.storefront' => [
                'provider' => 'storefront_customers',
                'table' => 'storefront_password_reset_tokens',
                'expire' => 60,
                'throttle' => 60,
            ],
        ]);
    }

    /**
     * Add the storefront SPA's origins to the CORS allowlist.
     *
     * Additive by construction: the ERP's own origins are merged, never replaced,
     * so config/cors.php keeps its meaning and this integration never has to edit
     * it. HandleCors reads config at request time, so setting it here is enough.
     */
    private function registerCorsOrigins(): void
    {
        config(['cors.allowed_origins' => array_values(array_unique(array_merge(
            (array) config('cors.allowed_origins', []),
            (array) config('reefer.allowed_origins', []),
        )))]);
    }

    /**
     * Storefront-only limiters.
     *
     * Named 'storefront-*' rather than the source's 'api'/'auth'/'auth-user' so the
     * ERP's own limiters — and, more importantly, the shared `api` middleware group
     * it mounts its ~440 /api/v2/* routes with — are left exactly as they are.
     */
    private function configureRateLimiting(): void
    {
        // The blanket ceiling every storefront endpoint gets, restoring the source's
        // `throttle:api` (Limit::perMinute(60)->by(ip)). In the source that came from
        // its own bootstrap/app.php `$middleware->api(append: ['throttle:api'])`;
        // here bootstrap/app.php is the ERP's and its `api` group carries no throttle,
        // so without this the whole catalogue/cart/order surface would be unlimited.
        // Applied to the route group in boot(), never to the `api` group itself.
        // Both budgets are configurable, and 0 means UNLIMITED (Limit::none()).
        //
        // This group now also carries the stock manager (/api/storefront/stocks/*),
        // whose dashboards fetch several endpoints per view — a staff member
        // clicking through tabs blows past 60/minute easily, and every call then
        // answers 429, which on screen is indistinguishable from being logged out.
        // A warehouse tool and a public shop front have very different traffic
        // shapes behind one IP, which is why this is tunable rather than fixed.
        $apiPerMinute = (int) config('reefer.rate_limits.api', 60);
        RateLimiter::for('storefront-api', fn (Request $request) => $apiPerMinute <= 0
            ? Limit::none()
            : Limit::perMinute($apiPerMinute)->by($request->ip()));

        // Login and register are the brute-force surface, so they get a much tighter
        // budget than the rest of the API. Keyed on email+IP as well as IP alone, so
        // spraying one password across many accounts is caught by the second limit.
        //
        // ⚠ Setting this to 0 removes brute-force protection from login — for BOTH
        // shopper and warehouse-staff accounts, since the stock module's auth routes
        // use this same limiter. Acceptable on a demo with no real accounts.
        $authPerMinute = (int) config('reefer.rate_limits.auth', 5);
        RateLimiter::for('storefront-auth', function (Request $request) use ($authPerMinute) {
            if ($authPerMinute <= 0) {
                return Limit::none();
            }

            return [
                Limit::perMinute($authPerMinute)->by($request->input('email').'|'.$request->ip()),
                Limit::perMinute($authPerMinute * 4)->by($request->ip()),
            ];
        });

        // For the token-authenticated sensitive actions: confirming a TOTP code and
        // requesting/checking an email code. They identify by bearer token and carry no
        // 'email' field, so the limiter above would key every one of them to "|<ip>" —
        // one shared bucket in which a single person's failed attempts lock out everyone
        // behind the same NAT (a campus or office network, exactly our case).
        //
        // Keyed off the bearer token rather than $request->user(): ThrottleRequests sits
        // in Laravel's middleware priority list and is hoisted AHEAD of our
        // AuthenticateApiToken, so the user is not resolved yet when this closure runs —
        // measured, not assumed. Using it would silently collapse every caller back into
        // one bucket. The token is on the raw request from the start; hash it so raw
        // credentials never become cache keys.
        RateLimiter::for('storefront-auth-user', function (Request $request) {
            $token = $request->bearerToken();

            return [
                Limit::perMinute(6)->by($token ? 'tok:'.hash('sha256', $token) : 'ip:'.$request->ip()),
                // Loose per-IP ceiling: an anti-spray backstop, deliberately far above
                // anything one shared connection would hit legitimately.
                Limit::perMinute(60)->by($request->ip()),
            ];
        });
    }
}
