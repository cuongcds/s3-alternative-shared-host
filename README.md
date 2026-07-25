# Open-S3

A self-hosted, S3-compatible object storage service built on CodeIgniter 3 — a drop-in
replacement for AWS S3 for a **single fixed-credential account** with **multi-bucket**
support. See [`docs/plans.md`](docs/plans.md) for the full design/roadmap,
[`docs/plans_v2.md`](docs/plans_v2.md) for the admin panel, and
[`docs/AWS_S3_Integration_Best_Practices.md`](docs/AWS_S3_Integration_Best_Practices.md)
for the three upload usecases this implements.

## Features

- **S3-compatible REST API** — bucket/object CRUD, `ListObjectsV2`-style listing,
  Range requests, versioning.
- **Two auth schemes, auto-detected per request**:
  - `OS3-HMAC-SHA256` — this project's own scheme (header or presigned-URL query auth).
  - `AWS4-HMAC-SHA256` — the **real AWS SigV4 spec**, so unmodified AWS SDKs
    (`@aws-sdk/client-s3`, `boto3`, `aws-cli`, MinIO clients...) work by just pointing
    `endpoint` at this server with the fixed Access Key/Secret Key — no custom
    integration needed.
- **Presigned URLs** for direct browser upload/download (usecase 1).
- **Backend upload pipeline** — size/mime validation, optional ClamAV virus scan,
  automatic image thumbnailing (usecase 2).
- **Event-driven hooks** — every object write/delete queues a Redis event the `worker`
  processes async (thumbnailing, virus scan, S3-Event-shaped webhook notifications)
  (usecase 3).
- **Per-bucket CORS** and **public-read buckets** — see [Configuring bucket
  settings](#configuring-bucket-settings) below.
- **Postman collection** ([`docs/OpenS3.postman_collection.json`](docs/OpenS3.postman_collection.json))
  documenting every endpoint, auto-signing requests for you.
- **Admin panel** (`/admin`) — session-based login (separate from the S3 API's own auth),
  bucket management, an object tree browser with inline preview, and event/dispatch
  status with manual redispatch. See [Admin panel](#admin-panel) below.

## Architecture

```text
nginx (8080) → app (PHP-FPM, CodeIgniter 3) ──┐
                                                ├─→ MySQL
             → worker (CLI, Redis consumer) ───┤
                                                └─→ Redis
```

MySQL and Redis are **not** part of this project's `docker-compose.yml` — they're
external dependencies this project connects to (configured via `.env`), not something
this stack provisions itself.

Object files live on a local Docker volume (`object_data`), hashed into subdirectories
per bucket — see `Filesystem_driver`.

## Deploy options

Three ways to run this — same app code, same database, just different web server and
worker execution model. Pick one; don't run the Docker-based ones (1 and 2, and the
simulation for 3) at the same time — they default to the same Compose project name and
would share containers/volumes in confusing ways.

| | **Option 1 — nginx**<br>(`docker-compose.yml`) | **Option 2 — Apache**<br>(`docker-compose.apache.yml`) | **Option 3 — shared hosting**<br>(no Docker; simulated by `docker-compose.shared-hosting.yml`) |
|---|---|---|---|
| Web server | nginx + PHP-FPM (2 containers) | Apache + mod_php (1 container) | Apache + mod_php, whatever your host runs — no control over it |
| Boot automation | entrypoint: wait-for-mysql, migrate, seed | same | **none** — shared hosts give no hook to run anything on boot; schema import / `.env` are manual (see below) |
| Event worker | `worker` — long-running daemon, BLPOP's Redis (near-instant) | `worker-cron` — real cron daemon runs `cli/worker_cron.php` every minute (up to ~1 min latency) | `Cronjobs.php` — cPanel-style cron can only `wget`/`curl` a URL, not run a CLI script or daemon, so the drain is exposed over HTTP instead (up to ~1 min latency, same as option 2) |
| Redis | required | required | **not available at all** — events still go into the `events` table (the durable source of truth for every option); `Cronjobs.php` polls it directly instead of reading a queue id |
| Object storage | Docker volume (`object_data`) | Docker volume (`object_data`) | plain folder — [`storage/private/`](storage/private) bind-mounted, no Docker volume at all (a real host has no such concept, just a directory) |
| Port | 8080 | 8090 | 8095 (simulation only — a real host serves on 80/443 under your domain) |
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

- `ACCESS_KEY_ID` / `SECRET_ACCESS_KEY` — generate with `openssl rand -hex 20`. This is
  the single account's credentials, used for **both** auth schemes.
- `DB_HOST`/`DB_PORT`/`DB_USERNAME`/`DB_PASSWORD`/`DB_DATABASE` — how to reach your
  MySQL server. `DB_DATABASE` is created automatically on startup if missing.
- `REDIS_HOST`/`REDIS_PORT`/`REDIS_PASSWORD` — how to reach your Redis server.
- `PUBLIC_BASE_URL` — the URL clients actually connect to (e.g. `http://localhost:8080`,
  or `:8090` for the Apache option). Must be correct for presigned URLs to verify (the
  signed `host` has to match what the client's request actually sends).

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

On startup, the web container's entrypoint automatically (identical for both options):
1. Waits for MySQL to become reachable.
2. Creates the database (if missing) and applies `db/schema.sql` (`cli/migrate.php`,
   idempotent — safe to run on every restart), then runs any pending CI migrations under
   [`application/migrations/`](application/migrations/) (schema changes since the admin
   panel was added go through these instead — see [Admin panel](#admin-panel)).
3. Seeds the `access_keys` table from `ACCESS_KEY_ID`/`SECRET_ACCESS_KEY` (`cli/seed.php`).

Option 3 (shared-hosting simulation — try this before deploying to a real host):

```bash
docker compose -f docker-compose.shared-hosting.yml up -d --build
# No entrypoint automation here on purpose — import the schema once yourself,
# same as you'd do via phpMyAdmin on a real host:
docker exec -i <your-mysql-container> mysql -u<user> -p'<password>' <database> < db/schema.sql
curl http://localhost:8095/healthz   # {"status":"ok"}
```

See **[Option 3: deploying to real shared hosting](#option-3-deploying-to-real-shared-hosting)**
below for the actual (non-Docker) runbook.

## Option 3: deploying to real shared hosting

No Docker, no SSH/CLI access assumed, no Redis. Just PHP files copied to a document
root and whatever cPanel-style control panel your host gives you.

### 1. Upload the code

Copy the entire project (via FTP/SFTP/cPanel File Manager/git) to your host, e.g. into
`public_html/` or a subdomain's document root.

### 2. Get `vendor/` onto the host

This project needs `composer install` to run once. If your host has no SSH/Composer
access (common on shared hosting), run it **locally** instead and upload the resulting
`vendor/` folder along with everything else:

```bash
composer install --no-dev --optimize-autoloader
# then upload vendor/ too, it's not something you can skip
```

### 3. Create the database and import the schema

Shared hosts almost always give you a **cPanel "MySQL Databases"** page instead of raw
MySQL root access — create a database + user there (cPanel usually prefixes both with
your account name, e.g. `cpaneluser_opens3`), note the host/port it gives you (often
just `localhost`), then open **phpMyAdmin** (also in cPanel) → select that database →
**Import** tab → upload [`db/schema.sql`](db/schema.sql) directly. That's the entire
migration step; nothing runs it for you automatically on this deploy option.

### 4. Configure `.env`

```bash
cp .env.example .env
```

- `ACCESS_KEY_ID` / `SECRET_ACCESS_KEY` — generate with `openssl rand -hex 20`.
- `DB_HOST` / `DB_PORT` / `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` — whatever cPanel
  gave you in step 3 (`DB_HOST` is usually `localhost`).
- **Leave `REDIS_HOST` empty** (or delete the line) — there's no Redis on this option;
  the app detects that and skips it automatically (`redis_enabled` in `config/s3.php`).
- `CRON_SECRET` — generate with `openssl rand -hex 20`; required to call
  `/cronjobs/process` (see step 6).
- `PUBLIC_BASE_URL` — your real domain, e.g. `https://s3.your-domain.com`.
- `STORAGE_ROOT` — the absolute path to [`storage/private/`](storage/private) inside
  the uploaded project, e.g. `/home/cpaneluser/public_html/open-s3/storage/private`.
  It's already blocked from direct web access by [`storage/.htaccess`](storage/.htaccess)
  (`Require all denied`) regardless of where your document root is, so guessing a
  file's path in a browser 403s instead of serving the raw object — no need to place it
  outside the document root. Just make sure the directory is writable by whatever user
  your host runs PHP as (usually your own account — shared hosting typically doesn't
  have the separate `www-data`-vs-owner permission split Docker does, so it should
  already be writable without extra `chmod`).

The `access_keys` DB table does **not** need seeding here — auth reads
`ACCESS_KEY_ID`/`SECRET_ACCESS_KEY` straight from `.env`, never from the database (that
table exists only to support key rotation in a future version).

### 5. Point Apache at `index.php` and enable the `Authorization` header

The included [`.htaccess`](.htaccess) at the project root handles both the
front-controller routing (CodeIgniter's `index_page` is `''`) and the fact that Apache
doesn't forward the `Authorization` header to PHP by default — as long as your host has
`mod_rewrite` enabled (it does on virtually every shared host) and doesn't strip
`.htaccess` overrides, this just works with no further config.

Set your document root to the project's root directory (where `index.php` and
`.htaccess` live) — not a `public/` subfolder, this project doesn't have one.

### 6. Configure the cron job

In cPanel's **"Cron Jobs"** page, add a job (every minute, or whatever your host's
minimum interval is) running:

```bash
wget -q -O /dev/null "https://your-domain.com/cronjobs/process?token=YOUR_CRON_SECRET"
```

(use `curl -s -o /dev/null "..."` instead if your host only offers `curl`). This drains
up to `CRON_BATCH_LIMIT` (default 20) pending events per tick — thumbnailing, virus
scan, webhook notifications (usecase 3). Visit the URL yourself once to sanity-check it
returns `{"processed":0,...}` (or a positive number if there's a backlog) instead of
`403 Forbidden` (wrong/missing token) or a PHP error.

### 7. Verify

```bash
curl https://your-domain.com/healthz   # {"status":"ok"}
```

Then run the Postman collection (or `aws-cli`) against your real domain, same as any
other deploy option.

## Using the API

Examples below use port 8080 (Option 1) — swap in 8090 (Option 2) or 8095 (Option 3
simulation) / your real domain (Option 3, real host) as appropriate.

Import [`docs/OpenS3.postman_collection.json`](docs/OpenS3.postman_collection.json) into
Postman — set `access_key_id`/`secret_access_key` collection variables from your `.env`
and every request signs itself automatically. It documents every endpoint (bucket/object
CRUD, presigned URLs, backend upload, bucket policy, CORS, event debugging).

Quick example with the AWS CLI (real AWS SigV4 — no extra setup beyond credentials):

```bash
aws configure set aws_access_key_id     <ACCESS_KEY_ID>
aws configure set aws_secret_access_key <SECRET_ACCESS_KEY>
aws configure set default.s3.addressing_style path   # this server only supports path-style URLs

aws --endpoint-url http://localhost:8080 s3 mb s3://my-bucket
aws --endpoint-url http://localhost:8080 s3 cp ./photo.jpg s3://my-bucket/photo.jpg
aws --endpoint-url http://localhost:8080 s3 ls s3://my-bucket
```

Any AWS SDK works the same way — set `endpoint`/`forcePathStyle: true` (the SDK v3
option; other SDKs have an equivalent) and use the same credentials (see the SDK-based
integration examples referenced in `docs/plans.md` section 5).

### Known gap

Multipart upload (`?uploads`/`?uploadId=`) is **not implemented yet** — those requests
return `501 NotImplemented`. Single `PUT` handles objects up to `MAX_UPLOAD_SIZE` (5 GB
default) just fine. See `docs/plans.md` Phase 7.

## Admin panel

A small server-rendered web UI at `/admin`, entirely separate from the S3 API's
SigV4/OS3-HMAC auth — see [`docs/plans_v2.md`](docs/plans_v2.md) for the full design.

### Create an admin account

There's no signup form — seed (or reset the password of) an admin from the CLI:

```bash
docker compose exec app php cli/create_admin.php admin@example.com 'a-strong-password'
# Apache option: docker compose -f docker-compose.apache.yml exec apache php cli/create_admin.php ...
```

Idempotent by email (`ON DUPLICATE KEY UPDATE`) — re-run it any time to reset a
forgotten password. Requires the DB migrations to have already run (they do
automatically on container startup, see [Setup](#setup) above).

### Using it

Visit `http://localhost:8080/admin` (swap the port per [deploy option](#deploy-options))
and sign in with the email/password from above. From there:

- **Dashboard** — bucket/object/size totals, event status counts, recent audit log.
- **Buckets** — create, delete (only if empty, same rule as the S3 API), and edit
  per-bucket config (versioning, public-read, CORS, notification URL, max object size,
  allowed MIME types) — the same fields as [Configuring bucket
  settings](#configuring-bucket-settings) below, via a form instead of hand-rolled JSON.
- **Object browser** — lazy-loaded folder tree (object keys are grouped on `/` the same
  way S3's `delimiter` parameter would, since the underlying storage has no real
  directories); inline preview for images/text/JSON/PDF, a download link (a freshly
  presigned URL, not proxied through the panel) for everything else.
- **Events** — the same `events` table the S3 API's webhook/thumbnailing pipeline uses,
  filterable by status, with a **redispatch** button for `failed` events that resets and
  re-queues them (picked up immediately if the `worker` daemon/cron is running).

### Notes

- Login is **session-based** (own cookie, own CSRF token scoped only to `/admin/*`) —
  completely independent from the API's request-signing auth; logging into the panel
  grants no S3 API access and vice versa.
- 5 failed logins locks that admin account for 15 minutes.
- CSS is Tailwind, built at Docker image build time by a pinned standalone CLI (no
  Node/npm at runtime, no CDN) — see `docker/app/Dockerfile` and `assets/admin.src.css`.
  If you change any file under `application/views/admin/` or `assets/*.js` and want the
  compiled CSS to pick up new classes without a full rebuild, run locally:
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
| `versioning_enabled` | bool | `false` | Keeps every version of an object instead of overwriting in place; `DELETE` without `?versionId=` inserts a delete-marker rather than removing prior versions. |
| `is_public` | bool | `false` | **Object** `GET`/`HEAD` needs no signature at all (e.g. a plain `<img src>` or unsigned `fetch`). Scoped narrowly: bucket-level listing (`GET /{bucket}`) and every write (`PUT`/`DELETE`) still always require a valid signature, even on a public bucket. |
| `cors_config` | object or `null` | `null` (no CORS) | Like real S3: with no `cors_config`, **no** `Access-Control-*` headers are ever sent, so cross-origin browser calls (e.g. a presigned upload from a web app on another domain) are blocked by the browser. Set it to enable CORS — see shape below. Also auto-answers the `OPTIONS` preflight (no auth required for that specific request). |
| `notification_url` | string or `null` | `null` | Webhook the `worker` POSTs to after every object write/delete on this bucket — see [Event webhooks](#event-webhooks) below. |
| `max_object_size` | int (bytes) | `5368709120` (5 GB) | Per-object upload size cap for this bucket; requests over this (or the global `MAX_UPLOAD_SIZE`, whichever is smaller) get `400 EntityTooLarge`. |
| `allowed_mime_types` | array or `null` | `null` (any type) | Whitelist of `Content-Type` values accepted for uploads to this bucket; `null`/omitted allows anything. |

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

### Event webhooks

When `notification_url` is set, the `worker` POSTs a JSON body after processing an
object write/delete. Every webhook request carries an `X-Os3-Signature` header:
`hex(hmac_sha256(body, SECRET_ACCESS_KEY))` — verify it on your receiving end to confirm
the notification actually came from this server.

A successful upload (`object.created`, no infection found) is shaped like real
[S3 Event Notifications](https://docs.aws.amazon.com/AmazonS3/latest/userguide/notification-content-structure.html) /
MinIO bucket notifications, so a consumer built against the real S3 event format works
unchanged:

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
  controllers/Cronjobs.php    Option 3: HTTP-triggered event drain (GET /cronjobs/process)
  controllers/Cli_migrate.php CLI-only: runs CI migrations to latest (cli/migrate.php calls it)
  controllers/admin/          Admin panel controllers (Auth, Dashboard, Buckets, Objects, Events)
  core/MY_Controller.php      S3/Internal API auth dispatch (both schemes) + CORS + audit log
  core/Admin_Controller.php   Admin panel base controller — session auth + CSRF, unrelated to MY_Controller
  libraries/Sigv4_signer.php      OS3-HMAC-SHA256 signer/verifier
  libraries/Aws_sigv4_signer.php  real AWS SigV4 signer/verifier
  libraries/Filesystem_driver.php object storage on disk
  libraries/Image_processor.php   thumbnailing (GD)
  libraries/Virus_scanner.php     ClamAV client
  libraries/Redis_queue.php       event queue (Predis) — unused/optional for Option 3
  models/                     Bucket_model, Object_model, Event_model, Admin_model
  migrations/                 CI migrations — schema changes since the admin panel go here,
                               not db/schema.sql (see Admin panel section above)
  views/admin/                Admin panel views (Tailwind-styled)
cli/
  migrate.php       creates DB + applies db/schema.sql, then runs CI migrations (Options 1/2 only)
  seed.php          seeds the access_keys table from .env (Options 1/2 only)
  create_admin.php  seeds/resets one admin panel login (see Admin panel section above)
  worker_lib.php    shared event-processing logic — also included directly by
                    Cronjobs.php, so all 3 options run identical processing code
  worker.php        Option 1: long-running daemon entrypoint (BLPOP loop)
  worker_cron.php   Option 2: drain-and-exit entrypoint, invoked by cron
.htaccess       Option 3 (and any plain-Apache deploy): front-controller routing +
                Authorization-header fix, since there's no vhost config to put it in
storage/private/ Option 3's object storage — a plain folder, not a Docker volume;
                  blocked from direct web access by storage/.htaccess
db/schema.sql   MySQL schema baseline (idempotent — CREATE TABLE IF NOT EXISTS); also what
                you import by hand via phpMyAdmin for Option 3. Frozen as-is — schema
                changes since the admin panel go through application/migrations/ instead.
assets/         Admin panel static assets — admin.src.css (Tailwind input), admin.css
                (built output), admin_sidebar.js, admin_tree.js, self-hosted icon font
docker/
  app/                Option 1 — PHP-FPM image + entrypoint
  nginx/              Option 1 — nginx vhost config
  worker/             Option 1 — daemon worker image
  apache/             Option 2 — Apache (mod_php) image, vhost, entrypoint, upload-size ini
                      (vhost/php.ini reused as-is by Option 3's Dockerfile)
  worker-cron/        Option 2 — cron worker image, crontab, entrypoint
  shared-hosting/apache/     Option 3 sim — Apache image with NO entrypoint at all
                             (unlike docker/apache/, on purpose — see its Dockerfile)
  shared-hosting/cron-wget/  Option 3 sim — minimal wget+cron image (no PHP at all,
                             mirrors what a real cPanel cron job can actually do)
docker-compose.yml               Option 1 (nginx + daemon worker)
docker-compose.apache.yml        Option 2 (Apache + cron worker)
docker-compose.shared-hosting.yml Option 3 simulation (Apache w/ no entrypoint + wget-only cron)
docs/
  plans.md                             full design doc + roadmap (tiếng Việt)
  plans_v2.md                          admin panel design doc (tiếng Việt)
  OpenS3.postman_collection.json       API reference / Postman collection
  AWS_S3_Integration_Best_Practices.md the 3 usecases this implements
```

## Development notes

- `application/` and `cli/` are bind-mounted into every Docker option's containers —
  editing PHP on the host takes effect immediately, no rebuild needed. Rebuild
  (`docker compose up -d --build`, or the `-f docker-compose.apache.yml` /
  `-f docker-compose.shared-hosting.yml` equivalent) only after changing a `Dockerfile`,
  vhost/crontab config, or `composer.json`.
- `docker compose logs -f app worker` (Option 1) / `... -f docker-compose.apache.yml logs
  -f apache worker-cron` (Option 2) / `... -f docker-compose.shared-hosting.yml logs -f
  apache-shared cron-wget` (Option 3 sim) for live logs.
- **Schema changes now go through CI migrations, not `db/schema.sql`.** That file is
  frozen as the pre-admin-panel baseline (`application/migrations/*_baseline_schema.php`
  recreates it verbatim so the `migrations` table has a starting point). To add a column
  or table: `php index.php cli_migrate run` applies pending migrations locally the same
  way `cli/migrate.php` does it on container start — just add a new timestamped file
  under `application/migrations/` instead of editing `db/schema.sql`.
- Gotchas this project already works around (all discovered by testing against a real
  server/SDK, not just reading docs):
  - **Apache doesn't put the `Authorization` header into `$_SERVER` by default**
    (`docker/apache/000-default.conf`, `.htaccess`) — needed a `RewriteRule` copying
    `%{HTTP:Authorization}` into `HTTP_AUTHORIZATION`, since every signed request
    depends on reading it.
  - **`Content-Type`/`Content-Length` have no `HTTP_` prefix in `$_SERVER`**, per the
    CGI spec both php-fpm/nginx and mod_php/Apache follow — `Sigv4_signer`/
    `Aws_sigv4_signer::getHeader()` fall back to `$_SERVER['CONTENT_TYPE']`/
    `CONTENT_LENGTH`. Matters because AWS SDKs commonly sign `content-type`.
  - **Don't both place a file in `/etc/cron.d/` AND run `crontab` on it** — cron picks
    up `/etc/cron.d/` entries automatically; also installing the same file as the
    current user's personal crontab (which expects a different format — no leading
    username field) makes every tick additionally fail with `<user>: not found`.
    (`docker/worker-cron/Dockerfile`, `docker/shared-hosting/cron-wget/entrypoint.sh`.)
  - **`STDOUT`/`STDERR` aren't defined outside the CLI SAPI** — `cli/worker_lib.php`
    runs inside a normal web request too (`Cronjobs.php`, Option 3), so its logging goes
    through a small `worker_log()` helper that falls back to `error_log()` there instead
    of fataling on an undefined constant.
- ClamAV is optional and commented out in every compose file by default — uncomment the
  `clamav` service and volume, and set `ENABLE_VIRUS_SCAN=true`, to turn it on. (Options
  1/2 only — no ClamAV story for Option 3, host it separately and point
  `CLAMAV_HOST`/`CLAMAV_PORT` at it if you want scanning there.)
