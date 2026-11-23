# AGENT.MD — YoSpa Architecture & Operational Context

This file serves as the definitive reference for AI agents working on **YoSpa**, a multi-tenant Spa Management SaaS platform. Read this document to understand the codebase structure, database schema, multi-tenancy rules, and API conventions.

---

## 1. System Overview & Tech Stack

* **Architecture:** Decoupled Architecture (Laravel REST API Backend + Next.js App Router Frontend).
* **Backend:** Laravel 11/12 running on PHP 8.2.4.
* **Database:** PostgreSQL 18 (`spa_saas` database).
* **Authentication:** Laravel Sanctum (Personal Access Tokens / Bearer Headers).
* **Tenancy Strategy:** Single-database / Shared-schema multi-tenancy isolated strictly via `spa_id` foreign keys.

---

## 2. Multi-Tenancy Strategy (`spa_id` Global Scope)

Data isolation across tenants is enforced at the Eloquent ORM level via a global scope applied to tenant models.

### Eloquent Trait: `App\Traits\BelongsToSpa`
* **Query Filtering:** Automatically appends `WHERE spa_id = auth()->user()->spa_id` to all Eloquent queries when a user belongs to a spa.
* **Fail-Closed Security:** If an authenticated user has `spa_id = null` (e.g., superuser), the trait strictly appends `WHERE 1 = 0` (fail-closed), guaranteeing zero tenant data exposure even if tenant routes are accidentally accessed.
* **Auto-Assignment:** Automatically assigns `model->spa_id = auth()->user()->spa_id` during the `creating` event on standard record insertion.
* **Tenant Models:** `Client`, `Service`, `Reservation`, `Therapist`. (The `User` model belongs to a `Spa` via `spa_id` foreign key and relation).

> **Crucial Rule for Agents:** Never disable global scopes (`withoutGlobalScopes()`) unless explicitly writing superuser platform management endpoints.

---

## 3. Database Schema Overview

[ spas ]
id (PK), name, slug, status, created_at, updated_at

[ users ]
id (PK), spa_id (nullable FK -> spas.id, onDelete cascade), name, email, password, role ('owner'|'admin'|'staff'|'superuser', default 'staff'), is_active (boolean, default true), created_at, updated_at
Partial unique index: role = 'superuser' (single superuser system-wide)

[ platform_audit_logs ]
id (PK), actor_user_id (nullable FK -> users.id, nullOnDelete), action (string), subject_type (string), subject_id (nullable bigint), spa_id (nullable FK -> spas.id, nullOnDelete), meta (nullable json), ip (nullable string 45), created_at (timestamp)

[ therapists ]
id (PK), spa_id (FK -> spas.id), user_id (nullable FK -> users.id, nullOnDelete), name, phone (nullable), email (nullable), color (string 7, default '#3B82F6'), notes (nullable text), is_active (boolean, default true), created_at, updated_at
Unique index: (spa_id, name)

[ clients ]
id (PK), spa_id (FK -> spas.id), full_name, phone, email, notes, created_at, updated_at

[ services ]
id (PK), spa_id (FK -> spas.id), name, description (nullable text), duration_minutes (int, default 60), price (decimal 10,2), is_active (boolean, default true), created_at, updated_at
Unique index: (spa_id, name)

[ reservations ]
id (PK), spa_id (FK -> spas.id), client_id (FK -> clients.id), service_id (FK -> services.id), therapist_id (nullable FK -> therapists.id, nullOnDelete),
start_time (dateTime), end_time (dateTime), status ('pending'|'confirmed'|'completed'|'cancelled'),
total_price (decimal 10,2), notes (nullable text), created_at, updated_at
Indexes: (spa_id, start_time), (therapist_id)


---

## 4. API Endpoints & Auth Contracts

* **Base URL:** `http://127.0.0.1:8000/api`
* **Authentication Header:** `Authorization: Bearer <sanctum_token>`
* **Content Headers:** `Accept: application/json`, `Content-Type: application/json`

### Roles & Permission Matrix
* **`superuser`**: Platform superuser (single account, `spa_id` null). Dedicated to `/api/platform/*` management (spas, cross-spa users, platform stats, audit trail). Strictly forbidden from accessing tenant routes (`tenant` middleware returns 403). Sanctum login token automatically expires in 12 hours.
* **`owner`**: Full access to all tenant resources, including creating/updating owner and admin accounts.
* **`admin`**: Manages services, therapists, and staff-role users; can create, update, and delete clients and reservations; cannot create, modify, deactivate, or reset passwords of owner or admin accounts.
* **`staff`**: Read access across all tenant resources; can create and update clients and reservations; cannot delete clients or reservations; cannot write services, therapists, or staff accounts.

### Authentication & Non-Tenant Routes (`routes/api.php`)
* `POST /api/login` — Public endpoint. Validates `email` and `password`. Allows `spa_id: null` ONLY for role `superuser`. For non-superusers without `spa_id` or with suspended spa, returns 403 (`{"message": "Your account has been deactivated."}`). Returns `{ message, token, user }`. Superuser tokens have `expires_at` set to 12 hours; tenant tokens remain standard (`null`).
* `POST /api/logout` — Protected (`auth:sanctum`, `active`). Revokes current access token. Allowed for both tenant users and superuser (skips `tenant` middleware).
* `GET /api/user` — Protected (`auth:sanctum`, `active`). Returns authenticated user profile with `spa` relation (`spa: null` for superuser). Skips `tenant` middleware.
* `POST /api/user/password` — Protected (`auth:sanctum`, `active`, `throttle:password_change`). Open to all roles (`owner`, `admin`, `staff`, `superuser`). Skips `tenant` middleware. Requires `current_password`, `password` (min 8, confirmed, different from current), `password_confirmation`. Wrong current password returns 422 (`"The current password is incorrect."`). On success, updates password hash, revokes all other Sanctum tokens while preserving current token, returns 200 `{ "message": "Password updated successfully" }`. Throttled at 5 attempts/min per user.

### Tenant Middleware (`tenant` / `EnsureBelongsToSpa`)
Applied to all tenant-scoped routes (`clients.*`, `reservations.*`, `services.*`, `therapists.*`, `staff.*`). Requires authenticated user to have a non-null `spa_id`. If user lacks a spa (such as the superuser), immediately halts with 403 (`"Unauthorized action."`). Prioritized before `SubstituteBindings` in `bootstrap/app.php` so unauthorized requests reject before model queries execute.

### Platform Superuser Routes (`/api/platform/*`)
Protected by `['auth:sanctum', 'active', 'role:superuser', 'throttle:60,1']`. Zero tenant middleware; all queries execute with manual ID binding and explicit `withoutGlobalScopes()`. All write operations log to `platform_audit_logs` without storing passwords or tokens.
* `GET /api/platform/stats` — Platform aggregates: `spas: { total, active, suspended }`, `users`, `clients`, `reservations`.
* `GET /api/platform/spas` — List of spas with `id`, `name`, `slug`, `status`, `owner_emails`, `counts` (users, clients, services, therapists, reservations), `created_at`. Supports `?status=active|suspended` and `?search=` (searches name, slug, owner email case-insensitively).
* `POST /api/platform/spas` — Creates spa and initial owner in one transaction. Payload: `{ name, slug?, owner_name, owner_email, owner_password }`. Auto-suffixes slug collisions (`slug-1`, `slug-2`); enforces case-insensitive email uniqueness. Returns 201 `{ message, spa, owner }`.
* `GET /api/platform/spas/{id}` — Spa details with entity `counts` and its `users` (`id`, `name`, `email`, `role`, `is_active`).
* `PATCH /api/platform/spas/{id}` — Updates spa (`name`, `slug`, `status: active|suspended`). Suspending immediately locks out all that spa's users on their next authenticated request. Returns `{ message, spa }`.
* `DELETE /api/platform/spas/{id}` — Permanently deletes spa and all tenant records.
  * **Guards:** Requires payload `{ confirm_slug: "<exact-slug>" }` (422 if mismatched); requires status `suspended` AND 0 reservations (409 otherwise).
  * Cleans up therapists, services, clients, users, and revokes users' personal access tokens.
  * Audit row keeps snapshot of deleted spa name, slug, and entity counts with `spa_id = null`.
* `POST /api/platform/spas/{id}/users` — Provisions user under a spa. Payload: `{ name, email, password (min 8), role: owner|admin|staff }`. Role `superuser` rejected. Returns 201 `{ message, user }`.
* `PATCH /api/platform/users/{id}` — Updates spa user (`name`, `email`, `role`, `is_active`, optional `password`).
  * **Guards:** 404 for superuser or spa-less users; 409 when deactivating or demoting the last active owner of that spa; revokes active tokens on deactivation, role change, or password reset.
* `GET /api/platform/audit` — Returns recent platform audit logs (`latest('id')`, default limit 50, max 100). Meta never exposes secrets.

### Resource Routes (`routes/api.php`)
All resource controllers run inside `Route::middleware(['auth:sanctum', 'active'])`:

#### Dashboard Aggregates (`Api\DashboardController`)
* `GET /api/dashboard/summary` — Open to all tenant roles (`owner`, `admin`, `staff`). Returns server-computed SQL aggregates strictly scoped to the authenticated user's `spa_id`:
  * `today_reservations_count`: count of reservations where `DATE(start_time) = TODAY` and `status != 'cancelled'`.
  * `upcoming_count`: count of future reservations where `start_time >= NOW` and `status != 'cancelled'`.
  * `revenue`: sum of `total_price` for `completed` reservations (filtered by optional `?from` and `?to` date range, or defaults to current month).
  * `counts_by_status`: map of reservation counts `{ pending, confirmed, completed, cancelled }`.
  * `total_clients`: total count of clients.
  * `total_reservations`: total count of reservations.

#### Staff Accounts (`Api\StaffController`) — Owner & Admin Only
* `GET /api/staff` — Returns list of staff members for the authenticated user's `spa_id` ordered by `name`. Defaults to active staff only; pass `?all=1` to include inactive. Supports `?page`, `?per_page` (1-100, default 25), `?role=owner|admin|staff`, `?search` (name/email with escaped wildcards), `?sort=name|email|role|created_at`, `?direction=asc|desc`. When pagination params are omitted, returns legacy flat array with header `X-Legacy-Unpaginated: 1`.
* `POST /api/staff` — Creates user auto-bound to active `spa_id`. Password hashed. Role defaults to `staff`. Admins can only create `staff` role accounts (403 otherwise). Returns 201 `{ message, staff }`.
* `PATCH /api/staff/{id}` — Updates user attributes. Cross-tenant IDs return 404.
  * **Safeguards:** Self-demotion (422) and self-deactivation (422) rejected; removing the last active owner rejected with 409; admins cannot modify, deactivate, or reset password of owner/admin accounts (403). Deactivation or password reset revokes all of the target user's active tokens.
* *No delete route is exposed.*

#### Therapists (`Api\TherapistController`)
* `GET /api/therapists` — Plain array or paginated list of therapists ordered by `name` (tiebreaker `id`). Default active only; pass `?all=1` to include inactive. Supports `?page`, `?per_page` (1-100, default 25), `?search` (name, email, phone with escaped wildcards), `?sort=name|created_at|email|phone`, `?direction=asc|desc`. Returns paginator object when `?page` or `?per_page` present; flat array with `X-Legacy-Unpaginated: 1` otherwise. Open to all roles (`owner`, `admin`, `staff`).
* `GET /api/therapists/{id}` — Returns therapist object. Open to all roles. Cross-tenant IDs return 404.
* `POST /api/therapists` — Creates therapist (Owner & Admin only). Validates `name` (case-insensitive unique per spa via `LOWER(name)`), `color` (hex regex `#RRGGBB`), and optional `user_id` (must belong to same spa and not already linked). Returns 201 `{ message, therapist }`.
* `PUT|PATCH /api/therapists/{id}` — Updates therapist (Owner & Admin only). Same validation marked `sometimes` (unique rules ignore self). Returns 200 `{ message, therapist }`.
* `DELETE /api/therapists/{id}` — Deletes therapist (Owner & Admin only).
  * **409 Conflict Guard:** If the therapist has any reservations, deletion is refused with HTTP 409:
    `{ "message": "This therapist has N reservation(s) and can't be deleted. Deactivate them instead." }`.
  * Deletes record and returns 200 `{ "message": "Therapist deleted successfully" }` when no reservations exist.

#### Clients (`Api\ClientController`)
* `GET /api/clients` — Open to all roles. Scoped to authenticated user's `spa_id`. Supports `?page`, `?per_page` (1-100, default 25), `?search` (name, phone, email with escaped wildcards), stable ordering `full_name` asc, `id` asc (or `?sort` & `?direction`). Returns paginator object when `?page` or `?per_page` present; flat array with `X-Legacy-Unpaginated: 1` otherwise.
* `GET /api/clients/{id}` — Open to all roles. Returns client object.
* `POST /api/clients` & `PUT|PATCH /api/clients/{id}` — Open to all roles. Returns `{ message, client }`.
* `DELETE /api/clients/{id}` — Restricted to `owner` and `admin` only (`role:owner,admin`). Staff receives 403.

#### Services (`Api\ServiceController`)
* `GET /api/services` — List of services ordered by `name`. Defaults to active only; pass `?all=1` for all. Open to all roles.
* `GET /api/services/{id}` — Returns service object. Open to all roles. Cross-tenant IDs return 404.
* `POST /api/services` & `PUT|PATCH /api/services/{id}` — Restricted to `owner` and `admin` only (`role:owner,admin`). Validates `name` (tenant-unique case-insensitively), `duration_minutes` (5-1440), `price` (min 0). Staff receives 403.
* `DELETE /api/services/{id}` — Restricted to `owner` and `admin` only (`role:owner,admin`). Blocked with 409 if reservations exist. Staff receives 403.

#### Reservations (`Api\ReservationController`)
* All responses (`index`, `show`, `store`, `update`) eager-load `client`, `service`, and `therapist` (`id`, `name`, `color`) with constant query count (no N+1).
* `GET /api/reservations` — Open to all roles. Supports:
  * `?page`, `?per_page` (1-100, default 25)
  * `?status=pending|confirmed|completed|cancelled`
  * `?therapist_id` (validated against caller's spa; foreign ID returns 422)
  * `?client_id` (validated against caller's spa; foreign ID returns 422)
  * `?from` & `?to` (inclusive date range on `start_time`, validated `from <= to`)
  * `?search` (client full_name and phone with escaped LIKE wildcards)
  * `?sort=start_time|created_at|status|total_price` (whitelisted, default `start_time`), `?direction=asc|desc` (default `desc`), `id` tiebreaker
  * Returns Laravel LengthAwarePaginator object when `?page` or `?per_page` present; flat array with `X-Legacy-Unpaginated: 1` otherwise.
* `GET /api/reservations/{id}` — Open to all roles.
* `POST /api/reservations` — Open to all roles.
  * **Validation Rules:**
    * `client_id`: `required|exists:clients,id` scoped to current `spa_id`.
    * `service_id`: `required|exists:services,id` scoped to current `spa_id`. Inactive services rejected with 422: `"This service is not available."`.
    * `therapist_id`: `nullable|exists:therapists,id` scoped to current `spa_id`. Inactive therapists rejected with 422: `"This therapist is not available."`.
    * `start_time`: `required|date`
    * `end_time`: `required|date|after:start_time`
    * `status`: `sometimes|string|in:pending,confirmed,completed,cancelled`
    * `total_price`: `required|numeric|min:0`
* `PUT|PATCH /api/reservations/{id}` — Open to all roles. Inactive service or therapist validation applies only if the respective ID is being changed. `therapist_id: null` unassigns.
* `DELETE /api/reservations/{id}` — Restricted to `owner` and `admin` only (`role:owner,admin`). Staff receives 403.

---

## 5. Core Configuration & Gotchas

* **Unauthenticated Requests:** Configured in `bootstrap/app.php` to force JSON `{ "message": "Unauthenticated." }` (401 status) for `api/*` requests.
* **Deactivated Accounts:** `EnsureUserIsActive` middleware intercepts deactivated users, revokes their Sanctum tokens, and returns 403 JSON `{ "message": "Your account has been deactivated." }`.
* **Database Drivers:** PostgreSQL enabled via `pdo_pgsql` and `pgsql` extensions in `php.ini`.
* **Testing / Database Seeding:**
  * Clean reset & seed: `php artisan migrate:fresh --seed --class=SpaTestDataSeeder`
  * Default test credentials: `admin@yospa.test` / `password123` (`spa_id: 1`, `role: owner`).

---

## 6. Known Gaps

* **Reservation Time Window Validation on Partial Update:** When PATCHing `end_time` alone without `start_time`, Laravel's `after:start_time` rule cannot validate against the existing database start time unless `start_time` is also provided in the payload. Clients should send both `start_time` and `end_time` when updating reservation times.
