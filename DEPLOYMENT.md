# YoSpa Backend Deployment Guide

This guide covers deployment instructions, environment configuration, database operations, security considerations, and known operational limitations for the YoSpa multi-tenant backend (Laravel 12 / PHP 8.2+ / PostgreSQL / Sanctum).

---

## 1. Required Environment Variables

Configure these variables in your production `.env` file:

### Core Application
- `APP_NAME=YoSpa`: Application display name.
- `APP_ENV=production`: Must be set to `production` for security and performance optimizations.
- `APP_KEY=`: Application key (generate with `php artisan key:generate --show` during initial setup).
- `APP_DEBUG=false`: **Critical**: Must strictly be `false`. Never enable debug in production; doing so exposes environment variables, credentials, and stack traces.
- `APP_URL=https://api.yourdomain.com`: Full HTTPS base URL of the API.

### Database (PostgreSQL)
- `DB_CONNECTION=pgsql`: Database driver.
- `DB_HOST=127.0.0.1`: PostgreSQL host address or container service name.
- `DB_PORT=5432`: PostgreSQL port.
- `DB_DATABASE=yospa`: PostgreSQL database name.
- `DB_USERNAME=yospa_user`: Dedicated PostgreSQL application user.
- `DB_PASSWORD=your_secure_password`: Secure database password.

### Authentication & CORS
- `SANCTUM_EXPIRATION=10080`: Token lifetime in minutes (default is `10080` minutes = 7 days).
- `CORS_ALLOWED_ORIGINS=https://app.yourdomain.com`: Comma-separated list of allowed frontend origins (e.g. `https://admin.yourdomain.com,https://app.yourdomain.com`). When empty in production, all cross-origin browser requests are rejected.

### Trusted Proxies & Networking
- `TRUSTED_PROXIES=`: Comma-separated list of reverse proxy IP addresses or CIDR blocks (e.g., `10.0.0.0/8,172.16.0.0/12`).
  > [!WARNING]
  > **When to use `'*'"`**: Only set `TRUSTED_PROXIES=*` if your backend container/server sits behind a trusted reverse proxy or load balancer (e.g., AWS ALB, Cloudflare, Traefik, Nginx) inside a private VPC/network where direct external traffic to the application port is strictly prohibited. If external clients can reach the web server directly, setting `'*'` allows clients to spoof `X-Forwarded-For` headers, completely bypassing IP rate limiting.

### Logging, Queue & Cache
- `LOG_CHANNEL=stack`: Default logging channel (`single` to `storage/logs/laravel.log`, or `stderr` for Docker/container environments).
- `LOG_LEVEL=info`: Production log level (use `info` or `error`; never `debug`).
- `QUEUE_CONNECTION=sync`: Defaults to synchronous processing. Set to `database` only if a persistent queue worker daemon is running.
- `CACHE_STORE=database`: Cache store backing rate limiting and temporary caching.

---

## 2. Production Build & Deployment Commands

### Initial Setup & Creating the Superuser
The platform has a single superuser account isolated from tenant spas used for cross-tenant administration:
1. Run `php artisan yospa:create-superuser` (supports `--name` and `--email` options).
2. Prompts for a secure password with confirmation (minimum 12 characters). Passwords are never accepted via CLI flags or printed to console.
3. Enforces a single superuser system-wide (enforced by a partial unique index in PostgreSQL/SQLite).
4. In case of emergency access recovery, run `php artisan yospa:reset-owner-password <superuser-email>` to set a new password and immediately revoke all active tokens.

### Deploying Updates (Zero-Downtime Deployment Workflow)
Run these commands in order during every deployment:
```bash
# 1. Fetch latest changes
git pull origin main

# 2. Install production dependencies (no dev dependencies, optimized autoloader)
composer install --no-dev --optimize-autoloader --no-interaction

# 3. Run additive database migrations
php artisan migrate --force

# 4. Cache configuration, routes, and events for maximum performance
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# 5. Reload PHP-FPM to flush OPcache and load new code
sudo systemctl reload php8.2-fpm
```

> [!CAUTION]
> **Never run `migrate:fresh` or `db:wipe` in production.**
> These commands drop all tables and permanently destroy tenant and transactional data. Only run `php artisan migrate --force`.

---

## 3. Scheduled Tasks & Sanctum Token Cleanup

Sanctum access tokens expire after `SANCTUM_EXPIRATION` minutes (default 7 days). Expired token rows accumulate in the `personal_access_tokens` table and must be pruned periodically.

### Crontab Entry (Laravel Scheduler)
Add the Laravel scheduler to your server crontab (`crontab -e`):

```cron
* * * * * cd /path/to/backend && php artisan schedule:run >> /dev/null 2>&1
```

### Direct Prune Cron Job (Alternative)
If not using the global scheduler daemon, run the Sanctum pruning command daily at 03:00 UTC:

```cron
0 3 * * * cd /path/to/backend && php artisan sanctum:prune-expired --hours=168 >> /dev/null 2>&1
```
*(Here `--hours=168` prunes tokens that expired more than 7 days ago).*

---

## 4. Queue, Cache, and Session Architecture

- **Stateless API**: All routes under `/api/*` use Sanctum Bearer tokens and are completely stateless. HTTP sessions and cookies are not used for API authentication.
- **Queue Worker**: If `QUEUE_CONNECTION` is switched from `sync` to `database`, you must run a persistent worker managed by Supervisor or Systemd:
  ```bash
  php artisan queue:work --sleep=3 --tries=3 --max-time=3600
  ```
- **Rate Limiting**: Backed by `CACHE_STORE=database` (`cache` table):
  - `POST /api/login`: Dual throttled at 5 requests/min per email+IP and 20 requests/min per IP (returns clean JSON 429 with `Retry-After`).
  - Authenticated API routes: Throttled at 120 requests/min per user.

---

## 5. HTTPS and Reverse Proxy Setup

The API should always run behind a reverse proxy (Nginx, Caddy, AWS ALB, Cloudflare) that terminates TLS.

### Recommended Nginx Location Block

```nginx
server {
    listen 443 ssl http2;
    server_name api.yourdomain.com;

    ssl_certificate /etc/letsencrypt/live/api.yourdomain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/api.yourdomain.com/privkey.pem;

    root /var/www/yospa/backend/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;

        # Forward real client IP and protocol
        fastcgi_param HTTP_X_FORWARDED_FOR $proxy_add_x_forwarded_for;
        fastcgi_param HTTP_X_FORWARDED_PROTO $scheme;
    }
}
```

Ensure `TRUSTED_PROXIES` in `.env` matches the proxy IP so `$request->ip()` and URL generation accurately reflect the client and HTTPS scheme.

---

## 6. PostgreSQL Backup Advice

YoSpa is a multi-tenant single-database system. Regular automated backups are essential:

### 1. Automated Logical Backup (`pg_dump`)
Run a daily backup using custom binary compressed format:

```bash
pg_dump -Fc -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USERNAME" "$DB_DATABASE" > "/var/backups/yospa_$(date +\%Y\%m\%d_\%H\%M\%S).dump"
```

### 2. Off-Site Storage
Ship dump files immediately to an external, encrypted object store (e.g., AWS S3, Google Cloud Storage, or Backblaze B2) with a 30-day retention policy:

```bash
aws s3 cp /var/backups/yospa_*.dump s3://your-company-backups/yospa/ --sse AES256
```

### 3. Restore Verification
Periodically test database restoration into a staging environment:

```bash
pg_restore -C -d postgres -h "$DB_HOST" -U "$DB_USERNAME" /var/backups/yospa_YYYYMMDD_HHMMSS.dump
```

---

## 7. Known Operational Limitations

1. **Unpaginated Endpoints**:
   - `GET /api/reservations`, `GET /api/clients`, `GET /api/staff`, and `GET /api/therapists` currently return unpaginated datasets (`->get()`).
   - For spas with thousands of records, memory usage and response latency will increase. Date-range filters or pagination should be added as data volume grows.
2. **Middleware-Only Role Enforcement**:
   - Roles (`owner`, `admin`, `staff`) are enforced strictly at the route middleware level (`CheckRole`), rather than through Laravel Model Policies or fine-grained domain gates.
3. **Data Visibility Across Roles**:
   - Service prices, therapist details, and client phone/email data are visible to users with the `staff` role within the same spa.
4. **Tenant-Level Audit Trail**:
   - Platform operations (spa creation, modification, deletion, and cross-spa user provisioning) are comprehensively logged in `platform_audit_logs`. Individual day-to-day tenant transactional mutations (e.g. updating a client note or reservation status within a spa) do not currently write audit logs.
5. **Partial Updates on Reservations**:
   - When updating reservation `end_time` alone via `PATCH`, validation requires `start_time` to confirm the sequence (`after:start_time`). API clients should send both `start_time` and `end_time` together.
