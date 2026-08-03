# REEFER storefront inside ASH-AI

The REEFER customer storefront (previously its own Laravel app + its own React SPA)
now lives inside this repo and inside `reefer-frontend`. The port is **purely
additive**: no existing ERP file was rewritten, no ERP route or table changed, and
the whole thing can be switched off again by deleting one line from
`bootstrap/providers.php`.

- Backend API: `/api/storefront/v1/*` (the ERP keeps `/api/v1/*`)
- Frontend: mounted at `/store` inside the existing React app

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

# 2. schema — 15 new storefront_* tables; no existing table is altered
php artisan migrate

# 3. product photography lives on the public disk
php artisan storage:link

# 4. demo catalogue + data (order matters: products before the demo orders)
php artisan db:seed --class="Database\Seeders\Storefront\ProductSeeder"
php artisan db:seed --class="Database\Seeders\Storefront\PhotographedProductSeeder"
php artisan db:seed --class="Database\Seeders\Storefront\DiscountSeeder"
php artisan db:seed --class="Database\Seeders\Storefront\DemoSeeder"
```

`DemoSeeder` creates a shopper you can sign in as: **demo@reefer.mnl / password**. All
four seeders are `updateOrCreate`-based, so re-running them will not duplicate products
or reset stock that orders have already drawn down.

`database/seeders/DatabaseSeeder.php` is the ERP's and was **not** modified — the
storefront seeders are run explicitly, as above.

Tests:

```bash
php artisan test                                  # ERP + storefront
php artisan test --filter=Storefront              # storefront only
```

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

- ERP app: <http://localhost:5173/>
- Storefront: <http://localhost:5173/store>

`reefer-frontend/.env` holds:

```
VITE_API_URL=http://127.0.0.1:8000/api/storefront
```

The API clients append `/v1/...` themselves. Port 5173 is already in this repo's
`config/cors.php` allowlist, so **local dev needs no CORS change at all** —
`STOREFRONT_ALLOWED_ORIGINS` can stay empty.

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
PAYMONGO_SUCCESS_URL=https://shop.example.com/store/checkout/success
PAYMONGO_FAILED_URL=https://shop.example.com/store/checkout/failed
```

⚠ **`REEFER_MANUAL_ADVANCE` is a demo affordance.** It lets a buyer walk their own order
down the tracker. Movement is one-way and owner-scoped, but someone who can mark their
own parcel *Delivered* can also open its returns window and dispute a delivery they set
themselves. Turn it off the moment real fulfilment exists and move stage changes onto the
inventory API.

The PayMongo redirect URLs must point at the **SPA**, not at `APP_URL`. Their default is
`APP_URL + /checkout/...`, which on a split deployment is the API host — a host that
serves no checkout page.

**Frontend (`reefer-frontend/.env.production`, read at build time)**

```dotenv
VITE_API_URL=https://api.example.com/api/storefront
```

Then `npm run build` and deploy `dist/`. Vite bakes this in at build time, so a change
here means a rebuild, not a restart. Serve `dist/` with an SPA fallback (every unmatched
path returns `index.html`) or deep links into `/store/...` will 404 on refresh.

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

Everything above is new. The only pre-existing files edited, all append-only:

- `bootstrap/providers.php` — one line registering `StorefrontServiceProvider`
- `reefer-frontend/src/App.jsx` — one import + one `<Route path="/store/*">`
- `reefer-frontend/.env`, `.env.production` — `VITE_API_URL`
- `reefer-frontend/public/` — the storefront's image assets (new files only)

`config/cors.php`, `config/auth.php`, `config/services.php`, `bootstrap/app.php`,
`routes/api.php`, `routes/web.php`, `database/seeders/DatabaseSeeder.php`, `composer.json`
and `package.json` were **not** modified.
