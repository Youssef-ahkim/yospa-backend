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

### Eloquent Trait: `App\Models\Concerns\BelongsToSpa`
* **Query Filtering:** Automatically appends `WHERE spa_id = auth()->user()->spa_id` to all Eloquent SELECT queries when a user is authenticated via Sanctum.
* **Auto-Assignment:** Automatically assigns `model->spa_id = auth()->user()->spa_id` during the `creating` event on standard record insertion.
* **Tenant Models:** `User`, `Client`, `Service`, `Reservation`.

> **Crucial Rule for Agents:** Never disable global scopes (`withoutGlobalScopes()`) unless explicitly writing super-admin system management endpoints.

---

## 3. Database Schema Overview

[ spas ]
id (PK), name, slug, email, phone, created_at, updated_at

[ users ]
id (PK), spa_id (FK -> spas.id), name, email, password, role ('owner'|'staff'), created_at, updated_at

[ clients ]
id (PK), spa_id (FK -> spas.id), full_name, phone, email, notes, created_at, updated_at

[ services ]
id (PK), spa_id (FK -> spas.id), name, duration_minutes, price, description, created_at, updated_at

[ reservations ]
id (PK), spa_id (FK -> spas.id), client_id (FK -> clients.id), service_id (FK -> services.id),
user_id (FK -> users.id), reservation_time (timestamp), status ('pending'|'confirmed'|'completed'|'cancelled'),
total_price (decimal), created_at, updated_at


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
* `GET /api/clients` — Returns list of clients for the authenticated user's `spa_id`.
* `POST /api/clients` — Creates client auto-bound to active `spa_id`.
* `GET|PUT|DELETE /api/clients/{id}` — Scoped client operations.
* `GET /api/reservations` — Returns list of reservations with `client` and `service` eager loaded (`with(['client', 'service'])`).
* `POST /api/reservations` — Creates reservation bound to active `spa_id`.

---

## 5. Core Configuration & Gotchas

* **Unauthenticated Requests:** Exception handling is explicitly configured in `bootstrap/app.php` using `$exceptions->render()` for `AuthenticationException` on `api/*` paths to force JSON `{ "message": "Unauthenticated." }` (401 status) instead of redirecting to a web `login` route.
* **Database Drivers:** PostgreSQL enabled via `pdo_pgsql` and `pgsql` extensions in `php.ini`.
* **Testing / Database Seeding:**
  * Clean reset & seed: `php artisan migrate:fresh --seed --class=SpaTestDataSeeder`
  * Default test credentials: `admin@yospa.test` / `password123` (`spa_id: 1`).

