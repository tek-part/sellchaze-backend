# Sellchase API architecture (audit)

## Stack
- Laravel 11, Spatie Permission (Admin, Merchant, Supplier roles in seeders)
- Wigpleasure → Sellchase: `POST /api/v1/orders` and `PATCH /api/v1/orders/{wigpleasureOrderId}` have **no API key**; `OrderSyncService` assigns the default Merchant (`SELLCHASE_MERCHANT_USER_ID` or first Merchant user). Optional `ApiKeyMiddleware` remains in the kernel for future use but is not attached to these routes.
- JWT: custom HS256 tokens via `App\Services\JwtTokenService` (Bearer on `/api/v1/*`)

## Domain reuse
- **User**: `HasRoles`, `HasApiTokens`; merchant/supplier relations via `merchant_supplier` pivot
- **Orders**: `OrderController` (web), `OrderSyncService`, `Api\V1\OrderApiController`
- **Dashboard**: `DashboardController@index` — stats scoped by `isAdmin` vs `b2bListingsUserId()`
- **RBAC**: Replace hardcoded admin id/email with `hasRole('Admin')` consistently

## Effective merchant user id
- Helper `b2bListingsUserId()` in `app/Helpers/Helpers.php` — used for non-admin scoping

## SPA (React)
- Standalone app in [`sellchase-ui/`](../sellchase-ui/README.md): **one dashboard** at `/dashboard`; Admin-only UI (users/roles) is shown only when the user has the `Admin` role. Same shell for all accounts in the single-store scenario.
- **Web routes:** `routes/web.php` only exposes a JSON welcome on `/`. There is no embedded SPA in Laravel; run `npm run dev` inside `sellchase-ui/` for local UI (e.g. `http://localhost:5173`). CORS for `/api/*` is configured in `config/cors.php` (defaults allow all origins; tighten for production).

## API surface (JWT `Authorization: Bearer`, prefix `/api/v1`)
- `POST /auth/login`, `POST /auth/google` (id_token or access_token), `POST /auth/refresh`, `POST /auth/logout`, `GET /auth/me`
- `GET /dashboard`
- `GET /orders` — optional `direction=in|out` (legacy “orders in / out” filters), `status`, `search`, `per_page`
- `GET /orders/{code}`
- `GET /quotations` — `direction=in|out` (customer vs supplier listing)
- `GET /deals` — `direction=in|out` (accepted quotations; supplier vs customer view)
- `GET /gateways` — payment gateways + wallet balance when present
- `GET /suppliers` — Admin: paginated Supplier-role users; Merchant: accepted partner suppliers only
- Admin: `GET /admin/users`, `GET /admin/roles`
