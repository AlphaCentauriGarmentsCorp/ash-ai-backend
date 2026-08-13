# REEFER storefront inside ASH-AI

The REEFER customer storefront (previously its own Laravel app + its own React SPA)
now lives inside this repo and inside `reefer-frontend`. The port is **purely
additive**: no existing ERP file was rewritten, no ERP route or table changed, and
the whole thing can be switched off again by deleting one line from
`bootstrap/providers.php`.

- Backend API: `/api/storefront/v1/*` (the ERP keeps `/api/v1/*`)
- Frontend: its own SPA (`reefer-frontend`), served at the root of its own domain

---

## 1. Why everything is namespaced

The two apps were written independently and collide head-on. A flat copy would have
overwritten the ERP:

| Symbol / table | ERP means | Storefront means |
|---|---|---|
| `App\Models\User` + `users` | staff accounts (`username`, `domain_role`, `domain_access` are NOT NULL) | shoppers (email + password only) |
| `App\Models\Order` + `orders` | a production order, 20+ migrations of workflow | a customer's checkout |
| `App\Http\Controllers\Api\AuthController` | staff login | shopper login |
| `App\Http\Controllers\Api\AccountController` | staff profile | shopper account page |
| `password_reset_tokens` | staff resets | shopper resets |
| `config/cors.php`, `config/auth.php`, `bootstrap/app.php`, `routes/api.php` | ERP's | storefront's |

So every ported class sits under a `Storefront` segment and every ported table under a
`storefront_` prefix:

| Where | What |
|---|---|
| `app/Http/Controllers/Storefront/` | the storefront's API controllers (`App\Http\Controllers\Storefront`) |
| `app/Http/Middleware/Storefront/` | `AuthenticateApiToken`, `ResolveApiUser`, `AuthenticateInventoryClient`, `ApiToken`, `ForceJsonResponse` |
| `app/Http/Requests/Storefront/`, `app/Http/Resources/Storefront/` | form requests + API resources |
| `app/Models/Storefront/` | `Customer`, `Product`, `ProductVariant`, `Address`, `Order`, `OrderItem`, `Cart`, `CartItem`, `ProductReview`, `Discount`, `StockAlert`, `ReturnRequest`, `ReturnRequestItem` |
| `app/Services/Storefront/` | pricing, payments (simulated + PayMongo), discounts, stock-alert notifier, SPA meta |
| `app/Contracts/Storefront/PaymentGateway.php` | the gateway interface |
| `app/Mail/Storefront/`, `resources/views/storefront/emails/` | shopper mail |
| `app/Console/Commands/Storefront/NotifyBackInStock.php` | back-in-stock job |
| `database/migrations/2026_07_31_0001*` | 15 `storefront_*` tables |
| `database/seeders/Storefront/`, `database/factories/Storefront/` | demo data |
| `tests/Feature/Storefront/` | the ported feature suite |
| `config/reefer.php` | storefront settings (new file, no collision) |
| `routes/storefront.php` | the storefront API route file (new file) |
| `app/Providers/StorefrontServiceProvider.php` | the one integration point |

Two renames worth knowing about, because the old names appear all over the source
project's git history:

- `App\Models\User` → **`App\Models\Storefront\Customer`** (table `storefront_users`).
  `App\Models\Storefront\User` sitting next to `App\Models\User` is a maintenance trap
  for a shared repo — the two are not the same kind of thing and should not read as if
  they are.
- `App\Http\Resources\UserResource` → **`App\Http\Resources\Storefront\CustomerResource`**.

Google sign-in was **not** ported: it had already been rolled back in the source and
its files were orphaned there. Nothing references it.

---

## 2. The one integration point

`app/Providers/StorefrontServiceProvider.php`, registered by a single appended line in
`bootstrap/providers.php`. It does five things and nothing else:

1. **Payment/pricing singletons.** `PaymentGateway` resolves to `PayMongoGateway` when
   `config('reefer.paymongo.secret')` is set, otherwise the simulator. The key is read
   from `config/reefer.php` rather than `config/services.php` because that file is the
   ERP's.
2. **A `storefront` auth guard, provider and password broker, registered at runtime**
   (`config([...])` in `register()`), so `config/auth.php` is untouched. Shopper
   password resets use `Password::broker('storefront')` and write to
   `storefront_password_reset_tokens` — the default broker would look up ERP *staff*.
   Storefront middleware calls `Auth::guard('storefront')->setUser($customer)` +
   `Auth::shouldUse('storefront')`, so `auth()->id()` in a storefront controller is a
   customer id and never touches the `web` guard.
3. **CORS origins merged additively** into `cors.allowed_origins` from
   `reefer.allowed_origins`. The ERP's origins survive; `config/cors.php` is untouched.
4. **Routes**: `Route::prefix('api/storefront')->middleware(['api', ForceJsonResponse::class, 'throttle:storefront-api'])->group(base_path('routes/storefront.php'))`.
5. **Rate limiters** `storefront-api` (the blanket 60/min per IP), `storefront-auth` and
   `storefront-auth-user`, named so the ERP's own limiters are not overwritten.
   `storefront-api` replaces the source's `throttle:api`, which the source appended to
   the **whole** `api` middleware group from its own `bootstrap/app.php`. That group here
   is the ERP's and carries ~440 `/api/v2/*` routes, so the ceiling is attached to the
   storefront route group instead — same protection, zero ERP effect.
6. **`ForceJsonResponse`** replaces the source's
   `$exceptions->shouldRenderJsonWhen(fn ($r) => $r->is('api/*'))`, which lived in its
   `bootstrap/app.php`. Without it Laravel decides JSON-vs-HTML from
   `$request->expectsJson()`, which is false for a client sending no `Accept` header —
   so a 422 became a 302-to-`/` with an HTML body and a 404 became an HTML error page.
   The provider also prepends it to the kernel's **middleware priority** list, because
   `ThrottleRequests` and `SubstituteBindings` are priority middleware and would
   otherwise sort ahead of it (leaving 429s and route-model-binding 404s as HTML).
   It is named in no other group or route, so ERP pipelines are unchanged.

It deliberately does **not** call `Model::preventLazyLoading()` even though the source's
`AppServiceProvider` did — that setting is global and would fail the ERP's own queries.

---

## 3. URL shape

Everything is under **`/api/storefront/v1/`**. A distinct prefix was mandatory: the ERP
already serves `/api/v1/orders`, `/api/v1/me`, `/api/v1/notifications`, `/api/v1/clients`,
and `/api/v1/orders` would have collided outright.

```
GET  /api/storefront/v1/health
GET  /api/storefront/v1/config          <- newly registered (see below)
POST /api/storefront/v1/auth/register | /auth/login | /auth/forgot-password | /auth/reset-password
GET  /api/storefront/v1/auth/me         POST /auth/logout  /auth/email/send  /auth/email/verify
GET  /api/storefront/v1/products        GET /products/{product}
GET  /api/storefront/v1/products/{product}/reviews   (+ POST, DELETE .../mine)
     /api/storefront/v1/addresses  /cart  /cart/items/{item}
     /api/storefront/v1/orders     /orders/{order}/advance  /orders/{order}/returns
     /api/storefront/v1/account  /favorites  /stock-alerts  /returns  /discounts/validate
     /api/storefront/v1/inventory        (machine-to-machine, INVENTORY_API_TOKEN)
```

Verify with `php artisan route:list --path=api/storefront`.

Two corrections were made while porting:

- **`/v1/config` is now registered.** The source app defined `ConfigController` but never
  routed it, which is why its `ConfigEndpointTest` failed there and the SPA's
  `configApi.js` 404'd. It is public and unauthenticated by design (currency, free-ship
  threshold, payment method list) — nothing secret may ever be added to that payload.
- Google auth routes dropped, matching the excluded controllers.

Auth is a bearer token in the `Authorization` header (`Bearer <token>`), hashed at rest —
this is the storefront's own token scheme, not Sanctum, and it does not interact with the
ERP's Sanctum tokens. Both front-ends share the `token` key in localStorage.

---

## 4. Install

From the repo root, with the ERP's `.env` already working:

```bash
# 1. env — append the storefront keys (all optional; defaults are the demo shape)
cat .env.storefront.example >> .env        # then edit as needed

# 2. schema — 18 new storefront_* tables; no existing table is altered
php artisan migrate

# 3. product photography lives on the public disk
php artisan storage:link

# 4. THE STOCK MANAGER'S FIRST ADMIN — do this BEFORE the host is reachable.
#    Stock\AuthController::register() grants the first account ever created both
#    `admin` and `approved`. On an empty storefront_stock_users that means the
#    first stranger to POST /api/storefront/stocks/auth/register owns the
#    warehouse. Seeding one account closes it: everyone after lands `pending`.
php artisan db:seed --class="Database\Seeders\Storefront\StockAdminSeeder"

# 5. catalogue + demo data (order matters: products before the demo orders)
php artisan db:seed --class="Database\Seeders\Storefront\PhotographedProductSeeder"
php artisan db:seed --class="Database\Seeders\Storefront\DiscountSeeder"
php artisan db:seed --class="Database\Seeders\Storefront\DemoSeeder"
```

> ⚠ **Never run a bare `php artisan db:seed` on a deployed box.** That runs the
> ERP's `DatabaseSeeder`, whose `UsersTableSeeder` creates `superadmin@com` and 14
> other accounts with the password `password` — and `AppServiceProvider`'s
> `Gate::before` lets `superadmin` pass every gate, while `/api/v2/login` carries
> no throttle. Because it uses `updateOrCreate`, it also RESETS those passwords on
> every re-run. Always seed by `--class`, as above.

`DemoSeeder` creates a shopper you can sign in as: **demo@reefer.mnl / password**. The
seeders are `updateOrCreate`-based, so re-running them will not duplicate products or
reset stock that orders have already drawn down.

> **`ProductSeeder` is deliberately NOT in that list.** It seeds 15 additional products
> that have no photography, so every one of them renders as a grey placeholder card. The
> catalogue is the three photographed products — `PhotographedProductSeeder` is the one
> that matters, and `DemoSeeder`'s orders reference only those three. The file is kept
> for anyone who wants a fuller catalogue to test paging and filters against; running it
> is opt-in:
>
> ```bash
> php artisan db:seed --class="Database\Seeders\Storefront\ProductSeeder"   # optional filler
> ```

`database/seeders/DatabaseSeeder.php` is the ERP's and was **not** modified — the
storefront seeders are run explicitly, as above.

Tests:

```bash
php artisan test                                  # ERP + storefront
php artisan test --filter=Storefront              # storefront only
```

> ### ⚠ Never run the suite with a cached config
>
> `php artisan config:cache` writes `bootstrap/cache/config.php`, and **a cached config
> silently overrides `phpunit.xml`'s `<env>` entries** — Laravel loads the cached file
> and never consults them. The suite's `DB_CONNECTION=sqlite` / `DB_DATABASE=:memory:`
> pinning is defeated, so `RefreshDatabase` runs `migrate:fresh` against whatever
> connection the cached config names: **your development MySQL database**. Every table
> is dropped.
>
> This is not hypothetical — it emptied `ash_ai_backend` during this integration.
>
> Run `php artisan config:clear` before `php artisan test`, always. Caching config is
> for deployed servers, not for a machine you also run tests on.
> `StressInputTest` carries a guard that refuses to run when the target database does
> not look disposable; the other test files do not, so the rule above is the real
> protection.

---

## 5. Running locally

Two processes, two ports.

```bash
# backend  — http://127.0.0.1:8000
cd ash-ai-backend
php artisan serve

# frontend — http://localhost:5173
cd reefer-frontend
npm install
npm run dev
```

- Storefront: <http://localhost:5173/> — it owns the root; `reefer-frontend` is the
  storefront now, not an ERP app with a storefront bolted into a sub-path.

`reefer-frontend/.env` holds:

```
VITE_API_URL=http://127.0.0.1:8000/api/storefront
```

The API clients append `/v1/...` themselves. This is cross-origin (`:5173` → `:8000`),
so **set the dev origin in the backend `.env`**:

```
STOREFRONT_ALLOWED_ORIGINS=http://localhost:5173,http://127.0.0.1:5173
```

The committed `config/cors.php` reads `CORS_ALLOWED_ORIGINS`, which is unset in a fresh
`.env.example` — so on a clean clone the allowlist is empty and the browser blocks every
request while `curl` against the same URLs returns 200. `StorefrontServiceProvider`
merges `STOREFRONT_ALLOWED_ORIGINS` in additively, keeping the ERP's own origins, so
setting it here never requires editing `config/cors.php`.

Mail (verification codes, password resets, back-in-stock) goes to
`storage/logs/laravel.log` while `MAIL_MAILER=log`.

---

## 6. Deploying the two halves to separate domains

Say the API lands on `https://api.example.com` and the SPA on `https://shop.example.com`.

**Backend (`.env` on the server)**

```dotenv
APP_URL=https://api.example.com
SESSION_SECURE_COOKIE=true

# the SPA's origin — scheme + host (+ port), no path, no trailing slash, never "*"
STOREFRONT_ALLOWED_ORIGINS="https://shop.example.com"
```

`STOREFRONT_ALLOWED_ORIGINS` is merged into `cors.allowed_origins` at runtime, so the
ERP's own `config/cors.php` entries keep working alongside it. `cors.paths` already
covers `api/*`, which includes `api/storefront/*`. If the SPA is also reachable on a
`www.` host or a preview domain, list each one — they are distinct origins to a browser.

Also set, if you are going live in any real sense:

```dotenv
REEFER_MANUAL_ADVANCE=false        # ⚠ see below
INVENTORY_API_TOKEN=<64 hex chars> # turns on the ERP↔storefront stock API
PAYMONGO_SECRET_KEY=<live key>     # switches checkout off the simulator
PAYMONGO_PUBLIC_KEY=<live key>
PAYMONGO_SUCCESS_URL=https://reeferclothing.com/checkout
PAYMONGO_FAILED_URL=https://reeferclothing.com/checkout
STOREFRONT_SPA_URL=https://reeferclothing.com  # base for links in outgoing email
```

⚠ Both redirect URLs point at the SPA's `/checkout`, because the SPA has no
`/checkout/success` or `/checkout/failed` route — `Checkout.jsx` renders the outcome
in-page from a `phase` state. The config *default* is `APP_URL + /checkout/success`,
which is wrong twice over on a split deployment: `APP_URL` is the API host, and that
path exists nowhere. Before enabling PayMongo, either add real outcome routes to the
SPA and point these at them, or keep them on `/checkout` and have it read the result
from the query string.

⚠ **`REEFER_MANUAL_ADVANCE` is a demo affordance, and it now moves real stock.**
It lets a buyer walk their own order down the tracker. That was cosmetic while it only
rewrote `stage`/`status`; it now also applies the stock movement (`OrderStock::apply`,
so the shop and the warehouse cannot disagree about the same rows), which makes it a
free `on_hand` drain — COD takes no money, so place an order, advance it to Delivered,
repeat, and the catalogue empties. It also opens a returns window on a delivery the
buyer set themselves. **It defaults to `false`** so a deployment that forgets the key
fails closed; leave it that way on any public host.

### Before the host is reachable

```bash
php artisan migrate --force
php artisan storage:link
php artisan db:seed --class="Database\Seeders\Storefront\StockAdminSeeder" --force
php artisan optimize:clear
```

`StockAdminSeeder` is not optional on a public domain. Until one staff account
exists, `Stock\AuthController::register()` hands the first anonymous caller
`role=admin, status=approved` — full inventory delete, order writes, and the
approvals screen they can use to lock the real staff out. Verify with
`SELECT COUNT(*) FROM storefront_stock_users;` before opening the host.

Three more that will bite on a real domain:

- **`RATE_LIMIT_AUTH`** guards login for shoppers *and* warehouse staff. `0` means
  unlimited; ship `5`.
- **Never run a bare `php artisan db:seed`** — see the warning in §4. It creates
  `superadmin@com` / `password`, which passes every gate via `Gate::before`, on an
  ERP login that has no throttle at all.
- **Trusted proxies**, if TLS terminates at a proxy (it does on the Hostinger box).

### Product photos and the TLS proxy

Both halves build image URLs with `asset()` — the shop in `ProductResource`, the
stock manager in `InventoryData::rows()` and `InventoryController::photo()`.
`asset()` takes its scheme *and host* from the incoming request. Behind a proxy
that terminates TLS, the request reaching PHP is plain `http`, so every image URL
comes out `http://` on an `https://` page and the browser blocks it as mixed
content: the catalogue renders with every thumbnail missing and **nothing marked
as an error in the network tab**.

`StorefrontServiceProvider::honourHttpsBehindProxy()` covers the scheme — it calls
`URL::forceScheme('https')` when `APP_URL` is an `https://` address, so **setting
`APP_URL` correctly is not optional**.

The complete fix is trusted proxies, which also repairs the client IP that every
rate limiter keys on — without it all traffic looks like it comes from the proxy,
so one visitor's requests exhaust everyone's budget. That is a **one-line change
to `bootstrap/app.php`**, an ERP file this integration is contractually forbidden
from touching (§7), so it needs whoever owns the ERP to apply it:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->trustProxies(at: '*');   // or the proxy's actual CIDR
    // ...existing ERP middleware configuration...
})
```

Verify once live — this must print `https://` and the real host:

```bash
curl -s https://api.sorbetesapparel.com/api/storefront/v1/products | grep -o '"image":"[^"]*"' | head -1
```

The PayMongo redirect URLs must point at the **SPA**, not at `APP_URL`. Their default is
`APP_URL + /checkout/...`, which on a split deployment is the API host — a host that
serves no checkout page.

**Frontend (`reefer-frontend/.env.production`, read at build time)**

```dotenv
VITE_API_URL=https://api.example.com/api/storefront
```

Then `npm run build` and deploy `dist/`. Vite bakes this in at build time, so a change
here means a rebuild, not a restart. Serve `dist/` with an SPA fallback (every unmatched
path returns `index.html`) or deep links like `/product/behemoth` will 404 on refresh.
`public/.htaccess` (Apache/Hostinger) and `public/_redirects` (Netlify) ship that
fallback and are copied into `dist/` by the build.

**After deploying**, sanity-check in this order:

```bash
curl https://api.example.com/api/storefront/v1/health
curl https://api.example.com/api/storefront/v1/config
curl -H "Origin: https://shop.example.com" -i https://api.example.com/api/storefront/v1/health | grep -i access-control-allow-origin
```

The last one is the CORS check — no `Access-Control-Allow-Origin` header back means
`STOREFRONT_ALLOWED_ORIGINS` does not match the origin exactly (a trailing slash or a
missing port is the usual cause), and the browser will block every API call from the SPA.

---

## 7. Files this integration is allowed to have touched

In THIS repo (`ash-ai-backend`) everything above is new, and exactly one pre-existing
file was edited:

- `bootstrap/providers.php` — one line registering `StorefrontServiceProvider`

`reefer-frontend` is a different matter and is **not** append-only: that repo's previous
app was replaced wholesale by the storefront (138 files: 40 added, 20 modified, 78
removed). The storefront owns `/` there now. Its deployment files — `.github/workflows/
deploy.yml`, `public/.htaccess`, `public/_redirects`, `vercel.json` — were carried over
untouched.

`config/cors.php`, `config/auth.php`, `config/services.php`, `bootstrap/app.php`,
`routes/api.php`, `routes/web.php`, `database/seeders/DatabaseSeeder.php`, `composer.json`
and `package.json` were **not** modified.
