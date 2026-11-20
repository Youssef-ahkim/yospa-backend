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
* **Tenant Models:** `Client`, `Service`, `Reservation`. (The `User` model belongs to a `Spa` via `spa_id` foreign key and relation).

> **Crucial Rule for Agents:** Never disable global scopes (`withoutGlobalScopes()`) unless explicitly writing super-admin system management endpoints.

---

## 3. Database Schema Overview

[ spas ]
id (PK), name, slug, status, created_at, updated_at

[ users ]
id (PK), spa_id (FK -> spas.id), name, email, password, role ('owner'|'staff'), created_at, updated_at

[ clients ]
id (PK), spa_id (FK -> spas.id), full_name, phone, email, notes, created_at, updated_at

[ services ]
id (PK), spa_id (FK -> spas.id), name, description (nullable text), duration_minutes (int, default 60), price (decimal 10,2), is_active (boolean, default true), created_at, updated_at
Unique index: (spa_id, name)

[ reservations ]
id (PK), spa_id (FK -> spas.id), client_id (FK -> clients.id), service_id (FK -> services.id),
start_time (dateTime), end_time (dateTime), status ('pending'|'confirmed'|'completed'|'cancelled'),
total_price (decimal 10,2), notes (nullable text), created_at, updated_at
Indexes: (spa_id, start_time)


---

## 4. API Endpoints & Auth Contracts

* **Base URL:** `http://127.0.0.1:8000/api`
* **Authentication Header:** `Authorization: Bearer <sanctum_token>`
* **Content Headers:** `Accept: application/json`, `Content-Type: application/json`

### Authentication Routes (`routes/api.php`)
* `POST /api/login` — Public endpoint. Validates `email` and `password`. Returns `{ message, token, user }`.
* `POST /api/logout` — Protected (`auth:sanctum`). Revokes current access token.
* `GET /api/user` — Protected (`auth:sanctum`). Returns authenticated user profile with `spa` relation.

### Resource Routes (`routes/api.php`)
All resource controllers run inside `Route::middleware('auth:sanctum')`:

#### Clients (`Api\ClientController`)
* `GET /api/clients` — Returns list of clients for the authenticated user's `spa_id`.
* `POST /api/clients` — Creates client auto-bound to active `spa_id`. Returns 201 `{ message, client }`.
* `GET|PUT|DELETE /api/clients/{id}` — Scoped client operations. Cross-tenant IDs 404.

#### Services (`Api\ServiceController`)
* `GET /api/services` — Returns list of services ordered by `name`. By default, returns **active services only** (`is_active = true`). Pass `?all=1` to return all services (both active and inactive). Returns a plain JSON array `[{ ... }]`.
* `POST /api/services` — Creates a service auto-bound to active `spa_id`. Returns 201 `{ message, service }`.
  * **Validation Rules:**
    * `name`: `required|string|max:255` (tenant-unique case-insensitively via `LOWER(name)`; returns 422 on duplicate).
    * `duration_minutes`: `required|integer|min:5|max:1440`
    * `price`: `required|numeric|min:0`
    * `description`: `nullable|string|max:2000`
    * `is_active`: `sometimes|boolean` (defaults to `true` if omitted)
* `GET /api/services/{id}` — Returns service object directly. Cross-tenant IDs return 404.
* `PUT|PATCH /api/services/{id}` — Updates a service. Same validation rules marked `sometimes` (unique rule ignores current record). Returns 200 `{ message, service }`. Cross-tenant IDs return 404.
* `DELETE /api/services/{id}` — Deletes service.
  * **409 Conflict Guard:** If the service has **any** reservations (`$service->reservations()->count() > 0`), deletion is refused with HTTP 409:
    `{ "message": "This service has N reservation(s) and can't be deleted. Deactivate it instead." }`.
  * When no reservations exist, deletes record and returns 200 `{ "message": "Service deleted successfully" }`. Cross-tenant IDs return 404.

#### Reservations (`Api\ReservationController`)
* `GET /api/reservations` — Returns list of reservations with `client` and `service` eager loaded (`with(['client', 'service'])`).
* `POST /api/reservations` — Creates reservation bound to active `spa_id`.
  * **Validation Rules:**
    * `client_id`: `required|exists:clients,id` scoped to current `spa_id`. Cross-tenant clients rejected with 422.
    * `service_id`: `required|exists:services,id` scoped to current `spa_id`. Inactive services rejected with 422: `"This service is not available."`.
    * `start_time`: `required|date`
    * `end_time`: `required|date|after:start_time`
    * `status`: `sometimes|string|in:pending,confirmed,completed,cancelled`
    * `total_price`: `required|numeric|min:0`
    * `notes`: `nullable|string`
* `GET /api/reservations/{id}` — Returns reservation with `client` and `service`. Cross-tenant IDs 404.
* `PUT|PATCH /api/reservations/{id}` — Updates reservation. Scoped to current `spa_id`. Inactive service rejection applies only if `service_id` is being changed. Cross-tenant IDs 404.
* `DELETE /api/reservations/{id}` — Deletes reservation. Cross-tenant IDs 404.

---

## 5. Core Configuration & Gotchas

* **Unauthenticated Requests:** Exception handling is explicitly configured in `bootstrap/app.php` using `$exceptions->render()` for `AuthenticationException` on `api/*` paths to force JSON `{ "message": "Unauthenticated." }` (401 status) instead of redirecting to a web `login` route.
* **Database Drivers:** PostgreSQL enabled via `pdo_pgsql` and `pgsql` extensions in `php.ini`.
* **Testing / Database Seeding:**
  * Clean reset & seed: `php artisan migrate:fresh --seed --class=SpaTestDataSeeder`
  * Default test credentials: `admin@yospa.test` / `password123` (`spa_id: 1`).

---

## 6. Known Gaps

* **Role Enforcement:** User roles (e.g. `'owner'`, `'staff'`) exist on the `users` table and model `$fillable`, but are not currently enforced in controllers or middleware. All authenticated tenant users can manage tenant resources.
* **Reservation Time Window Validation on Partial Update:** When PATCHing `end_time` alone without `start_time`, Laravel's `after:start_time` rule cannot validate against the existing database start time unless `start_time` is also provided in the payload. Clients should send both `start_time` and `end_time` when updating reservation times.
