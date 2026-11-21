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
* **Query Filtering:** Automatically appends `WHERE spa_id = auth()->user()->spa_id` to all Eloquent SELECT queries when a user is authenticated via Sanctum.
* **Auto-Assignment:** Automatically assigns `model->spa_id = auth()->user()->spa_id` during the `creating` event on standard record insertion.
* **Tenant Models:** `Client`, `Service`, `Reservation`, `Therapist`. (The `User` model belongs to a `Spa` via `spa_id` foreign key and relation).

> **Crucial Rule for Agents:** Never disable global scopes (`withoutGlobalScopes()`) unless explicitly writing super-admin system management endpoints.

---

## 3. Database Schema Overview

[ spas ]
id (PK), name, slug, status, created_at, updated_at

[ users ]
id (PK), spa_id (FK -> spas.id), name, email, password, role ('owner'|'admin'|'staff', default 'staff'), is_active (boolean, default true), created_at, updated_at

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
* **`owner`**: Full access to all tenant resources, including creating/updating owner and admin accounts.
* **`admin`**: Manages services, therapists, and staff-role users; can create, update, and delete clients and reservations; cannot create, modify, deactivate, or reset passwords of owner or admin accounts.
* **`staff`**: Read access across all tenant resources; can create and update clients and reservations; cannot delete clients or reservations; cannot write services, therapists, or staff accounts.

### Authentication Routes (`routes/api.php`)
* `POST /api/login` — Public endpoint. Validates `email` and `password`. Refuses inactive accounts with 403 (`{"message": "Your account has been deactivated."}`). Returns `{ message, token, user }`.
* `POST /api/logout` — Protected (`auth:sanctum`, `active`). Revokes current access token.
* `GET /api/user` — Protected (`auth:sanctum`, `active`). Returns authenticated user profile with `spa` relation.
* `POST /api/user/password` — Protected (`auth:sanctum`, `active`, `throttle:password_change`). Open to all roles (`owner`, `admin`, `staff`). Requires `current_password`, `password` (min 8, confirmed, different from current), `password_confirmation`. Wrong current password returns 422 (`"The current password is incorrect."`). On success, updates password hash, revokes all other Sanctum tokens while preserving current token, returns 200 `{ "message": "Password updated successfully" }`. Throttled at 5 attempts/min per user.

### Resource Routes (`routes/api.php`)
All resource controllers run inside `Route::middleware(['auth:sanctum', 'active'])`:

#### Staff Accounts (`Api\StaffController`) — Owner & Admin Only
* `GET /api/staff` — Returns list of staff members for the authenticated user's `spa_id` ordered by `name`. Defaults to active staff only; pass `?all=1` to include inactive. Fields: `id`, `name`, `email`, `role`, `is_active`.
* `POST /api/staff` — Creates user auto-bound to active `spa_id`. Password hashed. Role defaults to `staff`. Admins can only create `staff` role accounts (403 otherwise). Returns 201 `{ message, staff }`.
* `PATCH /api/staff/{id}` — Updates user attributes. Cross-tenant IDs return 404.
  * **Safeguards:** Self-demotion (422) and self-deactivation (422) rejected; removing the last active owner rejected with 409; admins cannot modify, deactivate, or reset password of owner/admin accounts (403). Deactivation or password reset revokes all of the target user's active tokens.
* *No delete route is exposed.*

#### Therapists (`Api\TherapistController`)
* `GET /api/therapists` — Plain array of therapists ordered by `name`. Default active only; pass `?all=1` to include inactive. Open to all roles (`owner`, `admin`, `staff`).
* `GET /api/therapists/{id}` — Returns therapist object. Open to all roles. Cross-tenant IDs return 404.
* `POST /api/therapists` — Creates therapist (Owner & Admin only). Validates `name` (case-insensitive unique per spa via `LOWER(name)`), `color` (hex regex `#RRGGBB`), and optional `user_id` (must belong to same spa and not already linked). Returns 201 `{ message, therapist }`.
* `PUT|PATCH /api/therapists/{id}` — Updates therapist (Owner & Admin only). Same validation marked `sometimes` (unique rules ignore self). Returns 200 `{ message, therapist }`.
* `DELETE /api/therapists/{id}` — Deletes therapist (Owner & Admin only).
  * **409 Conflict Guard:** If the therapist has any reservations, deletion is refused with HTTP 409:
    `{ "message": "This therapist has N reservation(s) and can't be deleted. Deactivate them instead." }`.
  * Deletes record and returns 200 `{ "message": "Therapist deleted successfully" }` when no reservations exist.

#### Clients (`Api\ClientController`)
* `GET /api/clients` & `GET /api/clients/{id}` — Open to all roles. Scoped to authenticated user's `spa_id`.
* `POST /api/clients` & `PUT|PATCH /api/clients/{id}` — Open to all roles. Returns `{ message, client }`.
* `DELETE /api/clients/{id}` — Restricted to `owner` and `admin` only (`role:owner,admin`). Staff receives 403.

#### Services (`Api\ServiceController`)
* `GET /api/services` — List of services ordered by `name`. Defaults to active only; pass `?all=1` for all. Open to all roles.
* `GET /api/services/{id}` — Returns service object. Open to all roles. Cross-tenant IDs return 404.
* `POST /api/services` & `PUT|PATCH /api/services/{id}` — Restricted to `owner` and `admin` only (`role:owner,admin`). Validates `name` (tenant-unique case-insensitively), `duration_minutes` (5-1440), `price` (min 0). Staff receives 403.
* `DELETE /api/services/{id}` — Restricted to `owner` and `admin` only (`role:owner,admin`). Blocked with 409 if reservations exist. Staff receives 403.

#### Reservations (`Api\ReservationController`)
* All responses (`index`, `show`, `store`, `update`) eager-load `client`, `service`, and `therapist` (`id`, `name`, `color`).
* `GET /api/reservations` & `GET /api/reservations/{id}` — Open to all roles.
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
