# Open-S3

**[View the project page →](https://cuongcds.github.io/s3-alternative-shared-host/)**

A self-hosted, S3-compatible object storage service built on CodeIgniter 3 — a drop-in
replacement for AWS S3 for a **single fixed-credential account** with **multi-bucket**
support. Full design/roadmap in [`docs/plans.md`](docs/plans.md), admin panel design in
[`docs/plans_v2.md`](docs/plans_v2.md), upload usecases in
[`docs/AWS_S3_Integration_Best_Practices.md`](docs/AWS_S3_Integration_Best_Practices.md).

## Features

- **S3-compatible REST API** — bucket/object CRUD, `ListObjectsV2`-style listing, Range
  requests, versioning.
- **Two auth schemes, auto-detected per request**: this project's own
  `OS3-HMAC-SHA256` (header or presigned-URL query auth), and the **real AWS SigV4
  spec** (`AWS4-HMAC-SHA256`) — so unmodified AWS SDKs (`@aws-sdk/client-s3`, `boto3`,
  `aws-cli`, MinIO clients...) work by just pointing `endpoint` at this server, no
  custom integration.
- **Presigned URLs** for direct browser upload/download.
- **Backend upload pipeline** — size/MIME validation, optional ClamAV virus scan,
  automatic image thumbnailing.
- **Event-driven hooks** — every object write/delete queues a Redis event the `worker`
  processes async (thumbnailing, virus scan, S3-Event-shaped webhook notifications).
- **Per-bucket CORS** and **public-read buckets** — see [Configuring bucket
  settings](#configuring-bucket-settings).
- **Postman collection** ([`docs/OpenS3.postman_collection.json`](docs/OpenS3.postman_collection.json))
  documenting every endpoint, auto-signing requests for you.
- **Admin panel** (`/admin`) — session-based login separate from the S3 API's own auth,
  bucket management, an object tree browser with inline preview, event/dispatch status
  with manual redispatch. See [Admin panel](#admin-panel).

## Architecture

```text
nginx (8080) → app (PHP-FPM, CodeIgniter 3) ──┐
                                                ├─→ MySQL
             → worker (CLI, Redis consumer) ───┤
                                                └─→ Redis
```

MySQL and Redis are **not** provisioned by `docker-compose.yml` — external dependencies
this project connects to via `.env`.

Object files live on a local Docker volume (`object_data`), hashed into subdirectories
per bucket — see `Filesystem_driver`.

## Deploy options

Three ways to run this — same app code, same database, different web server and worker
model. Pick one; don't run the Docker-based options together — they share a Compose
project name and would collide on containers/volumes.

| | **Option 1 — nginx**<br>(`docker-compose.yml`) | **Option 2 — Apache**<br>(`docker-compose.apache.yml`) | **Option 3 — shared hosting**<br>(no Docker; simulated by `docker-compose.shared-hosting.yml`) |
|---|---|---|---|
| Web server | nginx + PHP-FPM (2 containers) | Apache + mod_php (1 container) | Apache + mod_php, whatever your host runs — no control over it |
| Boot automation | entrypoint: wait-for-mysql, migrate, seed | same | **none** — shared hosts give no hook to run anything on boot; schema import / `.env` are manual (see below) |
| Event worker | `worker` — long-running daemon, BLPOP's Redis (near-instant) | `worker-cron` — real cron daemon runs `cli/worker_cron.php` every minute (up to ~1 min latency) | `Cronjobs.php` — cPanel-style cron can only `wget`/`curl` a URL, not run a CLI script or daemon, so the drain is exposed over HTTP instead (up to ~1 min latency, same as option 2) |
| Redis | required | required | **not available at all** — events still go into the `events` table (the durable source of truth for every option); `Cronjobs.php` polls it directly instead of reading a queue id |
| Object storage | Docker volume (`object_data`) | Docker volume (`object_data`) | plain folder — [`storage/private/`](storage/private) bind-mounted, no Docker volume at all (a real host has no such concept, just a directory) |
| Port | 8080 | 8090 | none published — routed through the shared `nginx-proxy` (`webproxy` network) via `VIRTUAL_HOST`/`VIRTUAL_PORT`, since this is the option actually exposed for real (files.techx.ai) |
| When to prefer | Default — lowest event latency, standard PHP-FPM ops model | Simpler single-process web container | You only have shared/cPanel-style hosting — no Docker, no SSH daemon, no Redis |

All three share the same `cli/worker_lib.php` event-processing logic (thumbnailing,
virus scan, webhook notifications) — `worker.php` (daemon), `worker_cron.php`
(drain-and-exit CLI), and `Cronjobs.php` (drain-and-exit over HTTP) are thin entrypoints
around it.

## Setup

### 1. Configure

```bash
cp .env.example .env
```

Edit `.env`:

- `ACCESS_KEY_ID` / `SECRET_ACCESS_KEY` — generate with `openssl rand -hex 20`. The
  single account's credentials, used for **both** auth schemes.
- `DB_HOST`/`DB_PORT`/`DB_USERNAME`/`DB_PASSWORD`/`DB_DATABASE` — your MySQL server;
  `DB_DATABASE` is created automatically on startup if missing.
- `REDIS_HOST`/`REDIS_PORT`/`REDIS_PASSWORD` — your Redis server.
- `PUBLIC_BASE_URL` — the URL clients actually connect to (`http://localhost:8080`, or
  `:8090` for Apache). Must match what the client sends, or presigned URLs won't verify.

### 2. Build and start

Option 1 (nginx, default):

```bash
docker compose up -d --build
curl http://localhost:8080/healthz   # {"status":"ok"}
```

Option 2 (Apache + cron worker):

```bash
docker compose -f docker-compose.apache.yml up -d --build
curl http://localhost:8090/healthz   # {"status":"ok"}
```

On startup, the entrypoint (identical for both options) waits for MySQL, creates the
database and applies `db/schema.sql` plus any pending CI migrations under
[`application/migrations/`](application/migrations/) (`cli/migrate.php`, idempotent),
then seeds the `access_keys` table from `.env` (`cli/seed.php`).

Option 3 (shared-hosting simulation — try this before deploying to a real host):

No port is published — it's routed through the shared `nginx-proxy` container instead.
Set `VIRTUAL_HOST` and `LETSENCRYPT_EMAIL` in `.env` first (see `.env.example`).

```bash
docker compose -f docker-compose.shared-hosting.yml up -d --build
# No entrypoint automation here on purpose — import the schema once yourself,
# same as you'd do via phpMyAdmin on a real host:
docker exec -i <your-mysql-container> mysql -u<user> -p'<password>' <database> < db/schema.sql
curl https://<your VIRTUAL_HOST>/healthz   # {"status":"ok"}
```

See **[Option 3: deploying to real shared hosting](#option-3-deploying-to-real-shared-hosting)**
below for the actual (non-Docker) runbook.

## Option 3: deploying to real shared hosting

No Docker, no SSH/CLI access assumed, no Redis. Just PHP files copied to a document
root and whatever cPanel-style control panel your host gives you.

### 1. Upload the code

Copy the entire project (FTP/SFTP/cPanel File Manager/git) to your host, e.g.
`public_html/` or a subdomain's document root.

### 2. Get `vendor/` onto the host

No SSH/Composer access on your host? Run it **locally** and upload the resulting
`vendor/` folder too:

```bash
composer install --no-dev --optimize-autoloader
```

### 3. Create the database and import the schema

In cPanel's **MySQL Databases** page, create a database + user (usually prefixed with
your account name, e.g. `cpaneluser_opens3`), then **phpMyAdmin** → select it →
**Import** → upload [`db/schema.sql`](db/schema.sql).

Using the [admin panel](#admin-panel) too? Its two tables aren't in `db/schema.sql`
(added later via `application/migrations/`) — run this once in phpMyAdmin's **SQL** tab:

```sql
CREATE TABLE IF NOT EXISTS admins (
  id INT PRIMARY KEY AUTO_INCREMENT,
  email VARCHAR(255) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at DATETIME NULL,
  failed_login_attempts INT NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ci_sessions (
  id VARCHAR(128) NOT NULL,
  ip_address VARCHAR(45) NOT NULL,
  timestamp INT UNSIGNED NOT NULL DEFAULT 0,
  data BLOB NOT NULL,
  PRIMARY KEY (id),
  KEY ci_sessions_timestamp (timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

(without it, the admin panel fails with "Invalid or missing CSRF token" — no session
persists between login and submit.)

### 4. Configure `.env`

```bash
cp .env.example .env
```

- `ACCESS_KEY_ID` / `SECRET_ACCESS_KEY` — generate with `openssl rand -hex 20`.
- `DB_HOST` / `DB_PORT` / `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` — from step 3
  (`DB_HOST` is usually `localhost`).
- **Leave `REDIS_HOST` empty** — no Redis on this option; the app detects and skips it.
- `CRON_SECRET` — generate with `openssl rand -hex 20`; required for step 6.
- `PUBLIC_BASE_URL` — your real domain, e.g. `https://s3.your-domain.com`.
- `STORAGE_ROOT` — absolute path to [`storage/private/`](storage/private), e.g.
  `/home/cpaneluser/public_html/open-s3/storage/private`. Already blocked from direct
  web access by [`storage/.htaccess`](storage/.htaccess); just make sure it's writable.

(`access_keys` doesn't need DB seeding — auth reads credentials straight from `.env`.)

### 5. Point Apache at `index.php`

The included [`.htaccess`](.htaccess) handles front-controller routing and forwarding
the `Authorization` header (Apache doesn't do this by default) — works with no further
config as long as `mod_rewrite` is enabled. Document root is the project root (where
`index.php` lives), not a `public/` subfolder.

### 6. Configure the cron job

In cPanel's **Cron Jobs** page, add a job (every minute) running:

```bash
wget -q -O /dev/null "https://your-domain.com/cronjobs/process?token=YOUR_CRON_SECRET"
```

Drains up to `CRON_BATCH_LIMIT` (default 20) pending events per tick. Visit the URL
once to confirm `{"processed":0,...}` instead of `403 Forbidden` or a PHP error.

### 7. Verify

```bash
curl https://your-domain.com/healthz   # {"status":"ok"}
```

Then run the Postman collection (or `aws-cli`) against your real domain.

## Using the API

Examples below use port 8080 (Option 1) — swap in 8090 (Option 2) or your `VIRTUAL_HOST`
domain (Option 3) as appropriate.

Import [`docs/OpenS3.postman_collection.json`](docs/OpenS3.postman_collection.json) into
Postman — set `access_key_id`/`secret_access_key` from your `.env` and every request
signs itself. Covers every endpoint (bucket/object CRUD, presigned URLs, backend upload,
bucket policy, CORS, event debugging).

Quick example with the AWS CLI (real SigV4 — no extra setup beyond credentials):

```bash
aws configure set aws_access_key_id     <ACCESS_KEY_ID>
aws configure set aws_secret_access_key <SECRET_ACCESS_KEY>
aws configure set default.s3.addressing_style path   # this server only supports path-style URLs

aws --endpoint-url http://localhost:8080 s3 mb s3://my-bucket
aws --endpoint-url http://localhost:8080 s3 cp ./photo.jpg s3://my-bucket/photo.jpg
aws --endpoint-url http://localhost:8080 s3 ls s3://my-bucket
```

Any AWS SDK works the same way — set `endpoint`/`forcePathStyle: true` and use the same
credentials (integration examples in `docs/plans.md` section 5).

### Known gap

Multipart upload (`?uploads`/`?uploadId=`) is **not implemented yet** — those requests
return `501 NotImplemented`. Single `PUT` handles objects up to `MAX_UPLOAD_SIZE` (5 GB
default) just fine. See `docs/plans.md` Phase 7.

## Admin panel

A small server-rendered web UI at `/admin`, entirely separate from the S3 API's
SigV4/OS3-HMAC auth — see [`docs/plans_v2.md`](docs/plans_v2.md) for the full design.

### Create an admin account

No signup form. Requires the `admins`/`ci_sessions` tables (auto-created on Options 1/2,
manual SQL for [Option 3](#3-create-the-database-and-import-the-schema)).

**With SSH/CLI access** — seed or reset a password:

```bash
docker compose exec app php cli/create_admin.php admin@example.com 'a-strong-password'
```

**Without SSH/CLI access** — same action over HTTP, gated by `SECRET_ACCESS_KEY`:

```bash
curl "https://your-domain.com/setup/create-admin?secret=<SECRET_ACCESS_KEY>&email=admin@example.com&password=a-strong-password"
```

Both are idempotent by email. Consider removing the `setup/create-admin` route
(`application/config/routes.php`) once you've created the accounts you need.

### Using it

Visit `http://localhost:8080/admin` (swap the port per [deploy option](#deploy-options))
and sign in. From there:

- **Dashboard** — bucket/object/size totals, event status counts, recent audit log.
- **Buckets** — create, delete (only if empty), edit per-bucket config (versioning,
  public-read, CORS, notification URL, size/MIME limits) — same fields as
  [Configuring bucket settings](#configuring-bucket-settings), via a form.
- **Object browser** — folder-tree view with inline preview and download links.
- **Events** — the webhook/thumbnailing event log, filterable by status, with a
  **redispatch** button for failed events.

### Notes

- Login is session-based, independent from the API's request-signing auth — panel
  access grants no S3 API access and vice versa.
- 5 failed logins locks the account for 15 minutes.
- CSS is Tailwind, built at Docker image time (no Node/npm at runtime). After editing
  `application/views/admin/` or `assets/*.js`, rebuild locally with:
  ```bash
  npx tailwindcss@3 -i assets/admin.src.css -o assets/admin.css --minify
  ```

## Configuring bucket settings

Buckets have no special config by default (private, no CORS, no size/mime limits). All
per-bucket configuration goes through one endpoint — any subset of fields can be sent;
omitted fields are left unchanged:

```
PUT /internal/buckets/{bucket}/policy
Content-Type: application/json
Authorization: <signed, same as any other request>
```

```json
{
  "versioning_enabled": true,
  "is_public": true,
  "allow_short_term_tokens": true,
  "cors_config": {
    "allowed_origins": ["https://example.com"],
    "allowed_methods": ["GET", "PUT", "HEAD"],
    "allowed_headers": ["*"],
    "expose_headers": ["ETag"],
    "max_age": 3600
  },
  "notification_url": "https://example.com/webhooks/open-s3",
  "max_object_size": 104857600,
  "allowed_mime_types": ["image/png", "image/jpeg", "text/plain"]
}
```

| Field | Type | Default | Effect |
|---|---|---|---|
| `versioning_enabled` | bool | `false` | Keeps every version instead of overwriting; `DELETE` without `?versionId=` inserts a delete-marker. |
| `is_public` | bool | `false` | Object `GET`/`HEAD` needs no signature. Listing and every write still require a valid signature. |
| `allow_short_term_tokens` | bool | `false` | Lets the admin panel mint short-term (~3h) tokens for this bucket — see [Short-term tokens](#short-term-tokens-delegated-presign). |
| `cors_config` | object or `null` | `null` (no CORS) | No `cors_config` = no `Access-Control-*` headers, so cross-origin calls are blocked. Also auto-answers `OPTIONS` preflight. |
| `notification_url` | string or `null` | `null` | Webhook the `worker` POSTs to after every write/delete — see [Event webhooks](#event-webhooks). |
| `max_object_size` | int (bytes) | `5368709120` (5 GB) | Per-object cap; over this (or global `MAX_UPLOAD_SIZE`) gets `400 EntityTooLarge`. |
| `allowed_mime_types` | array or `null` | `null` (any type) | Whitelist of accepted `Content-Type` values. |

### `cors_config` shape

| Field | Type | Notes |
|---|---|---|
| `allowed_origins` | array of strings | `"*"` matches any origin. No wildcard subdomain matching — list exact origins otherwise. |
| `allowed_methods` | array of strings | Sent back as `Access-Control-Allow-Methods` on preflight. Defaults to `GET,PUT,POST,DELETE,HEAD` if omitted. |
| `allowed_headers` | array of strings | Sent back as `Access-Control-Allow-Headers`. Defaults to `*` if omitted. |
| `expose_headers` | array of strings | Sent as `Access-Control-Expose-Headers` — headers browser JS can read via `response.headers.get(...)` (e.g. `ETag`), otherwise hidden from JS even on a successful cross-origin response. |
| `max_age` | int (seconds) | How long the browser caches the preflight result. Defaults to `3600`. |

Example — allow a web app on `https://app.example.com` to presigned-upload directly:

```bash
curl -X PUT http://localhost:8080/internal/buckets/my-bucket/policy \
  -H "Authorization: ..." -H "Content-Type: application/json" \
  -d '{"cors_config": {"allowed_origins": ["https://app.example.com"], "allowed_methods": ["GET","PUT"], "allowed_headers": ["*"], "expose_headers": ["ETag"], "max_age": 3600}}'
```

(Use the Postman collection's `Bucket Policy` folder instead of hand-rolling `curl` +
signature — it signs this for you.)

### Short-term tokens (delegated presign)

For a client you don't want to hand the real credentials to, a bucket with
`allow_short_term_tokens` enabled can issue tokens scoped to presigned URLs on that one
bucket only — no policy changes, no listing other buckets, no direct API calls.

1. Enable it on the bucket's edit page (`/admin/buckets/{bucket}/edit`), then click
   "Generate token" — shown once, valid for `SHORTTERM_TOKEN_TTL` seconds (default
   10800 = 3h), revocable early.
2. The holder calls `POST /internal/presign-with-token` with it instead of a signed request:

```bash
curl -X POST http://localhost:8080/internal/presign-with-token \
  -H "X-Os3-Token: <token from step 1>" -H "Content-Type: application/json" \
  -d '{"key": "uploads/photo.jpg", "method": "PUT", "expiresIn": 300}'
```

Response matches `/internal/presign` plus `bucket` (always the token's own bucket). The
presigned URL still has the normal 15-minute `s3_presign_max_ttl` ceiling.

### Event webhooks

When `notification_url` is set, the `worker` POSTs a JSON body after every write/delete,
signed with `X-Os3-Signature: hex(hmac_sha256(body, SECRET_ACCESS_KEY))` — verify it to
confirm the notification came from this server.

A successful upload matches the real
[S3 Event Notifications](https://docs.aws.amazon.com/AmazonS3/latest/userguide/notification-content-structure.html)
shape, so an existing S3/MinIO event consumer works unchanged:

```json
{
  "Records": [
    {
      "eventVersion": "2.1",
      "eventSource": "openS3",
      "eventName": "ObjectCreated:Put",
      "eventTime": "2026-07-24T17:00:00.000Z",
      "s3": { "bucket": { "name": "my-bucket" }, "object": { "key": "hello.txt" } }
    }
  ]
}
```

Deletion and virus-quarantine events currently use a simpler, ad-hoc shape instead
(`cli/worker_lib.php`'s `process_event()`) — not yet normalized into the same `Records` form:

```json
{ "event": "object.removed", "bucket": "my-bucket", "key": "hello.txt" }
```
```json
{ "event": "object.quarantined", "bucket": "my-bucket", "key": "hello.txt", "signature": "Eicar-Test-Signature" }
```

## Project layout

```text
application/
  controllers/S3.php          S3-compatible REST API (buckets/objects)
  controllers/Internal.php    presign, backend upload, bucket policy, event debug
  controllers/Health.php      /healthz
  controllers/Cronjobs.php    Option 3: HTTP-triggered event drain
  controllers/Setup.php       Option 3: HTTP-triggered admin creation
  controllers/Cli_migrate.php CLI-only: runs CI migrations to latest
  controllers/admin/          Admin panel controllers (Auth, Dashboard, Buckets, Objects, Events)
  core/MY_Controller.php      S3/Internal API auth dispatch (both schemes) + CORS + audit log
  core/Admin_Controller.php   Admin panel base controller — session auth + CSRF
  libraries/Sigv4_signer.php      OS3-HMAC-SHA256 signer/verifier
  libraries/Aws_sigv4_signer.php  real AWS SigV4 signer/verifier
  libraries/Filesystem_driver.php object storage on disk
  libraries/Image_processor.php   thumbnailing (GD)
  libraries/Virus_scanner.php     ClamAV client
  libraries/Redis_queue.php       event queue (Predis) — unused for Option 3
  models/                     Bucket_model, Object_model, Event_model, Admin_model, Access_token_model
  migrations/                 CI migrations — schema changes since the admin panel go here
  views/admin/                Admin panel views (Tailwind-styled)
cli/
  migrate.php       creates DB + applies db/schema.sql, then runs CI migrations
  seed.php          seeds the access_keys table from .env
  create_admin.php  seeds/resets one admin panel login
  worker_lib.php    shared event-processing logic (used by all 3 options)
  worker.php        Option 1: long-running daemon entrypoint (BLPOP loop)
  worker_cron.php   Option 2: drain-and-exit entrypoint, invoked by cron
.htaccess         Front-controller routing + Authorization-header fix for plain Apache
storage/private/  Option 3's object storage — a plain folder, blocked by storage/.htaccess
db/schema.sql     MySQL schema baseline — frozen; later changes go through migrations/
assets/           Admin panel static assets (Tailwind CSS, JS, icon font)
docker/
  app/, nginx/, worker/       Option 1 images/config
  apache/, worker-cron/       Option 2 images/config
  shared-hosting/             Option 3 simulation images (no entrypoint, wget-only cron)
docker-compose*.yml           Options 1/2/3
docs/
  plans.md                             full design doc + roadmap (tiếng Việt)
  plans_v2.md                          admin panel design doc (tiếng Việt)
  OpenS3.postman_collection.json       API reference / Postman collection
  AWS_S3_Integration_Best_Practices.md the 3 upload usecases this implements
```

## Development notes

- `application/` and `cli/` are bind-mounted — editing PHP on the host takes effect
  immediately. Rebuild (`docker compose up -d --build`) only after changing a
  `Dockerfile`, vhost/crontab config, or `composer.json`.
- `docker compose logs -f app worker` for live logs (swap service names per option).
- **Schema changes go through CI migrations, not `db/schema.sql`** (frozen as the
  pre-admin-panel baseline). Add a new timestamped file under
  `application/migrations/`; `php index.php cli_migrate run` applies pending ones
  locally the same way `cli/migrate.php` does on container start.
- Gotchas this project already works around:
  - **Apache doesn't forward `Authorization` into `$_SERVER` by default** — fixed via
    a `RewriteRule` in `.htaccess` (every signed request depends on reading it).
  - **`Content-Type`/`Content-Length` have no `HTTP_` prefix in `$_SERVER`** (CGI spec)
    — the SigV4 signers fall back to `$_SERVER['CONTENT_TYPE']`/`CONTENT_LENGTH`.
  - **Don't place a file in both `/etc/cron.d/` and the user's `crontab`** — the format
    differs (leading username field), so one of them fails silently every tick.
  - **`STDOUT`/`STDERR` aren't defined outside the CLI SAPI** — `worker_lib.php` runs
    inside web requests too (`Cronjobs.php`), so logging goes through `worker_log()`,
    which falls back to `error_log()` instead of fataling.
  - **`ENVIRONMENT` was silently always `'development'`** — PHP's default
    `variables_order` doesn't populate `$_SERVER` from process env vars, so
    `$_SERVER['CI_ENV']` never resolved. Fixed by also checking `getenv('CI_ENV')`,
    plus a small dependency-free `.env` loader in `index.php` for hosts with no
    env-var mechanism at all (plain shared hosting).
- ClamAV is optional, commented out by default — uncomment the `clamav` service/volume
  and set `ENABLE_VIRUS_SCAN=true` (Options 1/2 only; host it separately for Option 3).
