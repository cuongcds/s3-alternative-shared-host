# Kế hoạch xây dựng Open-S3 (CodeIgniter S3-compatible Storage)

> Tài liệu này lên plan triển khai một service lưu trữ object storage tương thích S3,
> xây trên nền CodeIgniter (project hiện có tại `open-s3/`), dùng để **thay thế AWS S3**
> cho các usecase mô tả trong [`AWS_S3_Integration_Best_Practices.md`](./AWS_S3_Integration_Best_Practices.md).

## 0. Ràng buộc & giả định

- **Single account**: không có IAM/multi-tenant. Chỉ **1 cặp Access Key / Secret Key cố định**,
  cấu hình qua ENV, dùng chung cho toàn hệ thống.
- **Multi-bucket**: vẫn phải hỗ trợ nhiều bucket dưới account đó (mỗi bucket có policy/CORS riêng).
- **Deploy**: Docker + Docker Compose, không giả định hạ tầng cloud (không Lambda thật —
  usecase "Lambda/Worker" ở usecase 3 sẽ thay bằng **worker container** dùng queue).
- Framework: CodeIgniter 3 (đã có sẵn skeleton trong repo, PHP >=5.3.7 theo `composer.json` —
  sẽ nâng lên PHP 8.x khi viết code mới, xem mục 10).
- Mục tiêu tương thích: **API càng giống S3 REST API càng tốt** để các SDK có sẵn
  (aws-sdk-php, boto3, aws-cli, minio-client...) chỉ cần đổi `endpoint_url` + credentials
  là dùng được, giảm chi phí migrate cho ứng dụng đang dùng AWS S3.

---

## 1. Kiến trúc tổng quan

```text
                        ┌──────────────────────────┐
                        │        nginx (TLS)       │
                        │  reverse proxy + gzip    │
                        └────────────┬─────────────┘
                                     │
                        ┌────────────▼─────────────┐
                        │   app (CodeIgniter/PHP-FPM)│
                        │  - Auth (SigV4-lite)       │
                        │  - Bucket/Object API       │
                        │  - Presign API             │
                        │  - Backend-upload API      │
                        └───┬───────────┬───────────┘
                            │           │
                 ┌──────────▼───┐  ┌────▼─────────┐
                 │   MySQL       │  │  Redis        │
                 │ (metadata,    │  │ (queue events,│
                 │  buckets,     │  │  rate limit,  │
                 │  objects,     │  │  presign cache)│
                 │  multipart)   │  └────┬─────────┘
                 └───────────────┘       │
                                          │
                         ┌────────────────▼─────────────┐
                         │   worker (CLI PHP, supervisord)│
                         │  - virus scan (ClamAV)         │
                         │  - resize/watermark ảnh        │
                         │  - webhook notify (S3 Event)   │
                         └────────────────┬───────────────┘
                                          │
                         ┌────────────────▼───────────────┐
                         │  Storage volume (bind/local FS) │
                         │  /data/{bucket}/{object-key}     │
                         └──────────────────────────────────┘
```

**Nguyên tắc**: `app` container chỉ làm HTTP API (nhanh, không block); mọi xử lý nặng
(scan virus, resize, watermark, OCR...) đẩy qua Redis queue cho `worker` xử lý — đúng
tinh thần "usecase 3: Hybrid Architecture" trong best-practices doc, chỉ khác là
Lambda → worker container tự host.

---

## 2. Mapping 3 usecase trong best-practices doc

| Usecase (doc) | Thiết kế trong open-s3 |
|---|---|
| **1. Client upload trực tiếp (presigned URL)** | Endpoint custom `POST /internal/presign` (không phải S3 REST) sinh presigned URL ký bằng Secret Key cố định (SigV4-lite, xem mục 5). Client PUT trực tiếp lên `PUT /{bucket}/{key}?X-Sig=...` — request này đi thẳng vào `app`, không qua backend nghiệp vụ. |
| **2. Backend upload lên S3** | Endpoint `POST /{bucket}/{key}` (multipart/form-data) qua backend — app pipeline: validate → (optional) virus scan đồng bộ nhỏ (giới hạn size) → resize/watermark → lưu file → ghi metadata. |
| **3. Hybrid (S3 Event → Lambda/Worker)** | Mọi object PUT thành công (dù qua presigned hay backend) đều insert 1 row vào bảng `events` + push Redis queue `object.created`. `worker` consume để: resize/thumbnail, virus scan bất đồng bộ (bù cho case 1 không scan được lúc upload), gọi webhook (`bucket.notification_url`) mô phỏng S3 Event Notification. |

---

## 3. Data model (MySQL)

```sql
-- Access key cố định, single-account, seed 1 dòng duy nhất qua migration/ENV
CREATE TABLE access_keys (
  id INT PRIMARY KEY AUTO_INCREMENT,
  access_key_id VARCHAR(64) UNIQUE NOT NULL,
  secret_access_key_hash VARCHAR(255) NOT NULL, -- lưu hash, secret thật chỉ trong ENV lúc seed
  is_active TINYINT(1) DEFAULT 1,
  created_at DATETIME
);

CREATE TABLE buckets (
  id INT PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(63) UNIQUE NOT NULL,        -- theo rule đặt tên bucket của S3
  region VARCHAR(32) DEFAULT 'us-east-1',  -- giữ field để SDK khỏi lỗi, không thật sự multi-region
  versioning_enabled TINYINT(1) DEFAULT 0,
  is_public TINYINT(1) DEFAULT 0,
  cors_config JSON NULL,
  notification_url VARCHAR(255) NULL,      -- webhook cho usecase 3
  max_object_size BIGINT DEFAULT 5368709120, -- 5GB giống S3 single PUT limit
  allowed_mime_types JSON NULL,
  created_at DATETIME,
  deleted_at DATETIME NULL
);

CREATE TABLE objects (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  bucket_id INT NOT NULL,
  object_key VARCHAR(1024) NOT NULL,
  version_id VARCHAR(64) NULL,             -- null nếu bucket không bật versioning
  size BIGINT NOT NULL,
  etag VARCHAR(64) NOT NULL,               -- md5 (hoặc multipart composite etag)
  content_type VARCHAR(255),
  storage_path VARCHAR(1024) NOT NULL,     -- đường dẫn thật trên volume
  metadata JSON NULL,                      -- x-amz-meta-* custom headers
  storage_class VARCHAR(32) DEFAULT 'STANDARD',
  is_deleted TINYINT(1) DEFAULT 0,         -- delete marker khi versioning on
  created_at DATETIME,
  UNIQUE KEY uniq_bucket_key_version (bucket_id, object_key, version_id)
);

CREATE TABLE multipart_uploads (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  upload_id VARCHAR(64) UNIQUE NOT NULL,
  bucket_id INT NOT NULL,
  object_key VARCHAR(1024) NOT NULL,
  status ENUM('in_progress','completed','aborted') DEFAULT 'in_progress',
  created_at DATETIME
);

CREATE TABLE multipart_parts (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  upload_id VARCHAR(64) NOT NULL,
  part_number INT NOT NULL,
  etag VARCHAR(64) NOT NULL,
  size BIGINT NOT NULL,
  storage_path VARCHAR(1024) NOT NULL,
  UNIQUE KEY uniq_upload_part (upload_id, part_number)
);

CREATE TABLE events (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  bucket_id INT NOT NULL,
  object_key VARCHAR(1024) NOT NULL,
  event_type VARCHAR(64) NOT NULL,   -- object.created / object.removed
  payload JSON NULL,
  status ENUM('pending','processing','done','failed') DEFAULT 'pending',
  attempts INT DEFAULT 0,
  created_at DATETIME
);

CREATE TABLE audit_logs (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  action VARCHAR(64),
  bucket VARCHAR(63),
  object_key VARCHAR(1024) NULL,
  ip VARCHAR(45),
  status_code INT,
  created_at DATETIME
);
```

---

## 4. Cấu trúc thư mục CodeIgniter (thêm mới trên skeleton hiện có)

```text
application/
  config/
    s3.php                    # ENV mapping: ACCESS_KEY_ID, SECRET_ACCESS_KEY, STORAGE_ROOT...
  controllers/
    api/
      Bucket.php               # PUT/DELETE/GET /{bucket}
      Object.php                # PUT/GET/HEAD/DELETE /{bucket}/{key}, Range support
      Multipart.php             # POST ?uploads, PUT ?partNumber, POST ?complete
      Presign.php                # POST /internal/presign  (usecase 1)
      Upload.php                 # POST /{bucket}/{key} multipart/form-data (usecase 2)
      Health.php
  core/
    MY_Controller.php           # base controller: xác thực SigV4-lite chung
  libraries/
    Sigv4/
      Signer.php                 # ký & verify request (dùng chung presign + header auth)
    Storage/
      Filesystem_driver.php      # đọc/viết object trên volume, chống path traversal
    Pipeline/
      Validator.php              # check mime/size/allowed_mime_types
      Virus_scanner.php           # gọi ClamAV qua clamd socket
      Image_processor.php         # resize/watermark (GD hoặc Imagick)
      Encryptor.php                # AES-256 envelope encryption (optional, theo bucket)
    Xml_response.php              # build S3-style XML response (ListBucketResult, Error...)
  models/
    Bucket_model.php
    Object_model.php
    Multipart_model.php
    Event_model.php
  helpers/
    s3_helper.php                 # canonical request builder, etag helper
cli/
  worker.php                      # entrypoint chạy trong container worker (loop Redis queue)
docker/
  app/Dockerfile
  worker/Dockerfile
  nginx/default.conf
docker-compose.yml
.env.example
```

---

## 5. Xác thực (Auth) — 2 scheme song song cho single account

Server chấp nhận **2 scheme ký request độc lập**, tự nhận diện theo request đang dùng cái nào
(`MY_Controller::require_auth()`):

1. **`OS3-HMAC-SHA256`** (`Sigv4_signer`) — scheme tự định nghĩa, đơn giản hơn SigV4 thật
   (không double-encode, không chunked payload...), dùng bởi `/internal/presign` và Postman
   collection của chính project. Header: `Authorization: OS3-HMAC-SHA256 Credential=<AK>,
   SignedHeaders=host;x-os3-date, Signature=<hex>` + header `X-Os3-Date`. Presigned URL:
   query `X-Os3-Credential/Date/Expires/SignedHeaders/Signature`.
2. **`AWS4-HMAC-SHA256`** (`Aws_sigv4_signer`) — **implement đúng chuẩn AWS SigV4 thật**
   (canonical request, credential scope `date/region/service/aws4_request`, signing-key
   chain 4 vòng HMAC), để SDK AWS chuẩn (`aws-sdk-js`, `boto3`, `aws-cli`, MinIO client...)
   dùng thẳng server này chỉ bằng cách đổi `endpoint` + set Access Key/Secret Key — không
   cần biết gì về `/internal/presign`. Hỗ trợ cả header auth và presigned URL (query-string).
   Region/service trong credential scope (`us-east-1`/`s3` mặc định của SDK) **không bị
   validate** — chỉ được dùng lại để tự derive signing key ở phía server, nên tự nhất quán
   dù client cấu hình region gì cũng được, chỉ ai biết đúng secret key mới ký được đúng.
   Đã cross-check canonical request byte-for-byte với `@aws-sdk/client-s3` +
   `@aws-sdk/s3-request-presigner` + `@aws-sdk/signature-v4` (presigned PUT, presigned GET,
   header-signed PUT nhiều signed headers) và integration test full round-trip (Create/Put/
   Get/List/Delete Bucket qua header auth, upload/download qua presigned URL bằng raw HTTP
   không cần SDK) — khớp 100%.

Cả 2 scheme dùng chung 1 Access Key ID/Secret Key (ENV `ACCESS_KEY_ID`/`SECRET_ACCESS_KEY`),
so sánh bằng `hash_equals`; không cần bảng permission phức tạp, nhưng vẫn giữ bảng
`access_keys` để dễ **rotate key** sau này (generate key mới, migrate, revoke key cũ) mà
không sửa code.

> Chưa làm: rate-limit theo IP qua Redis (chống brute-force chữ ký) — xem Phase 9.

---

## 6. API Endpoints

### 6.1 S3-compatible (SDK/CLI dùng được trực tiếp)

| Method | Path | Ý nghĩa |
|---|---|---|
| GET | `/` | List buckets |
| PUT | `/{bucket}` | Create bucket |
| DELETE | `/{bucket}` | Delete bucket (chỉ khi rỗng) |
| GET | `/{bucket}` | List objects (ListObjectsV2, hỗ trợ prefix/delimiter/paging) |
| HEAD | `/{bucket}` | Check bucket exists |
| PUT | `/{bucket}/{key}` | Upload object (dùng cho cả presigned PUT & SDK PutObject) |
| GET | `/{bucket}/{key}` | Download (hỗ trợ `Range` header) |
| HEAD | `/{bucket}/{key}` | Lấy metadata (size, etag, content-type) |
| DELETE | `/{bucket}/{key}` | Xoá object (hoặc tạo delete-marker nếu versioning) |
| POST | `/{bucket}/{key}?uploads` | Initiate multipart upload |
| PUT | `/{bucket}/{key}?partNumber=N&uploadId=...` | Upload part |
| POST | `/{bucket}/{key}?uploadId=...` (complete) | Complete multipart |
| DELETE | `/{bucket}/{key}?uploadId=...` | Abort multipart |

### 6.2 Custom convenience endpoints (không thuộc S3 REST, hỗ trợ 2 usecase còn lại)

| Method | Path | Ý nghĩa |
|---|---|---|
| POST | `/internal/presign` | Body: `{bucket, key, method, expiresIn}` → trả presigned URL (usecase 1) |
| POST | `/internal/uploads/{bucket}/{key}` | Backend upload multipart/form-data, chạy pipeline validate/scan/resize (usecase 2) |
| PUT | `/internal/buckets/{bucket}/policy` | Set CORS / notification_url / allowed_mime_types / max_object_size |
| GET | `/internal/events?status=pending` | Debug queue (admin only) |
| GET | `/healthz` | Liveness/readiness cho docker healthcheck |

---

## 7. Storage engine

- Lưu file trên volume local: `STORAGE_ROOT/{bucket}/{sha256(object_key)[:2]}/{sha256(object_key)[:4]}/{object_key-encoded}`
  (băm 2 cấp thư mục để tránh quá nhiều file trong 1 dir; không dùng trực tiếp `object_key`
  làm path để tránh path traversal — luôn `realpath()` check nằm trong `STORAGE_ROOT`).
- ETag = MD5 cho object thường; với multipart = MD5 của chuỗi các MD5-part nối lại + `-<số phần>`
  giống hành vi thật của S3.
- Ghi file: `tmp` file trước, `rename()` khi hoàn tất (atomic), tránh client đọc file dở.
- Versioning: nếu bucket bật, giữ toàn bộ version cũ (không xoá vật lý ngay), object mới nhất
  đánh dấu trong bảng `objects` bằng `version_id` mới nhất; xoá thật (`is_deleted`) chỉ khi
  gọi `DELETE ?versionId=...`.

---

## 8. Queue & Worker (usecase 3 — hybrid)

- Redis list/queue đơn giản (`RPUSH events_queue`, worker `BLPOP`) — không cần Kafka/RabbitMQ
  ở quy mô single-account.
- Worker (`cli/worker.php`, chạy loop qua `supervisord` trong container riêng) xử lý theo
  `event_type`:
  - `object.created` + ảnh (content-type `image/*`) → generate thumbnail, lưu object phụ
    `{key}.thumb.jpg`.
  - `object.created` bất kỳ + bucket có `notification_url` → POST webhook JSON payload
    (mô phỏng S3 Event Notification) với HMAC signature header để bên nhận verify nguồn.
  - `object.created` mà upload qua presigned (chưa scan) → chạy virus scan bất đồng bộ,
    nếu nhiễm → xoá object + ghi audit log + gọi webhook `object.quarantined`.
- Retry: `attempts` tăng dần, backoff, sau N lần chuyển `status=failed` để alert riêng
  (không tự động retry vô hạn).

---

## 9. Docker & Docker Compose

**MySQL và Redis KHÔNG chạy trong `open-s3/docker-compose.yml`** — chúng là 2 stack
độc lập, dùng chung cho mọi project trong workspace, sống tại
`../services/mysql` và `../services/redis` (mỗi thư mục có `docker-compose.yml` +
`.env.example` + `README.md` riêng, publish port ra `localhost`). Lý do: nhiều
project trong `freemium/` (ví dụ `s3-example` dùng chung MinIO ở `../services/minio`)
có thể cần một MySQL/Redis chạy sẵn, thay vì mỗi project tự mọc một instance riêng.

```bash
(cd ../services/mysql && docker compose up -d)
(cd ../services/redis && docker compose up -d)
```

`open-s3` kết nối tới 2 stack đó qua `host.docker.internal` (từ trong container) —
cần `extra_hosts: ["host.docker.internal:host-gateway"]` trên mỗi service để chạy
đúng trên Linux (Docker Desktop macOS/Windows đã hỗ trợ sẵn). Vì MySQL dùng chung
không biết gì về schema của `open-s3`, entrypoint của `app` tự tạo database +
áp schema (`cli/migrate.php` chạy `db/schema.sql`, toàn bộ `CREATE TABLE IF NOT
EXISTS` nên an toàn khi chạy lại) trước khi seed access key.

```yaml
# open-s3/docker-compose.yml (rút gọn)
services:
  nginx:
    image: nginx:1.27-alpine
    ports: ["8080:80"]
    depends_on: [app]
    volumes: ["./docker/nginx/default.conf:/etc/nginx/conf.d/default.conf:ro"]

  app:
    build: { context: ., dockerfile: docker/app/Dockerfile }
    env_file: .env
    volumes:
      - object_data:/data
      - ./application:/var/www/html/application
      - ./cli:/var/www/html/cli
    extra_hosts: ["host.docker.internal:host-gateway"]

  worker:
    build: { context: ., dockerfile: docker/worker/Dockerfile }
    env_file: .env
    volumes:
      - object_data:/data
      - ./application:/var/www/html/application
      - ./cli:/var/www/html/cli
    extra_hosts: ["host.docker.internal:host-gateway"]

  # clamav:   # optional — comment out mặc định, xem mục 8/11
  #   image: clamav/clamav:stable
  #   volumes: ["clamav_data:/var/lib/clamav"]

volumes:
  object_data:
  # clamav_data:
```

- `.env.example` chứa: `ACCESS_KEY_ID`, `SECRET_ACCESS_KEY` (sinh 1 lần bằng script
  `openssl rand -hex 20`, không commit thật vào git), `STORAGE_ROOT=/data`,
  `DB_HOST=host.docker.internal` + `DB_PASSWORD` (phải khớp `MYSQL_ROOT_PASSWORD`
  của `services/mysql/.env`), `REDIS_HOST=host.docker.internal` + `REDIS_PASSWORD`
  (phải khớp `REDIS_PASSWORD` của `services/redis/.env` — Redis dùng chung bắt
  buộc auth qua `requirepass`), `MAX_UPLOAD_SIZE`.
- `services/mysql` có thêm service `phpmyadmin` (port 8081) để xem/sửa dữ liệu qua
  web UI, đăng nhập bằng `root` / `MYSQL_ROOT_PASSWORD`.
- ClamAV (virus scan) mặc định **tắt và comment sẵn** trong `docker-compose.yml` —
  bật bằng cách uncomment service đó + set `ENABLE_VIRUS_SCAN=true`.
- Nâng PHP runtime lên 8.1+ trong Dockerfile (`docker/app/Dockerfile`), dù skeleton cũ
  khai `php: >=5.3.7` — cần bump `composer.json` khi thêm code mới (SigV4, JSON, PSR
  libraries đều cần PHP hiện đại).

### 9.1 Deploy option 2 — Apache + cron worker

`docker-compose.apache.yml` là phương án deploy thứ 2 (giữ nguyên option 1 phía trên
không đổi), thay nginx+PHP-FPM bằng 1 container Apache (mod_php), và thay `worker`
daemon (BLPOP vô hạn) bằng cron job (`docker/worker-cron`, chạy `cli/worker_cron.php`
mỗi phút — drain hết queue rồi exit, thay vì block chờ). 2 phương án dùng chung logic xử
lý event (`cli/worker_lib.php`), chỉ khác entrypoint mỏng bên ngoài.

Lưu ý triển khai (phát hiện lúc test thật, không phải lý thuyết):
- **Apache không tự forward header `Authorization` vào `$_SERVER`** (dành riêng cho
  module auth của Apache) — phải thêm `RewriteRule` copy `%{HTTP:Authorization}` vào
  `HTTP_AUTHORIZATION` (`docker/apache/000-default.conf`), nếu không mọi request có ký
  đều 403 `MissingAuthorizationHeader`.
- **`Content-Type`/`Content-Length` không có prefix `HTTP_`** trong `$_SERVER` theo
  chuẩn CGI (đúng cho cả php-fpm/nginx và mod_php/Apache) — bug thật trong
  `Sigv4_signer`/`Aws_sigv4_signer::getHeader()`, đã sửa để fallback sang
  `$_SERVER['CONTENT_TYPE']`/`CONTENT_LENGTH`. Cần thiết vì AWS SDK thường ký cả
  `content-type` trong `SignedHeaders`.
- Cron trong container cần dump lại ENV (`docker/worker-cron/entrypoint.sh`, dùng
  `escapeshellarg()` qua PHP) vào 1 file rồi source trong crontab — cron tự strip
  environment của container theo mặc định.
- **Không vừa để file trong `/etc/cron.d/` vừa chạy `crontab` trên chính file đó** —
  cron daemon tự đọc `/etc/cron.d/*` (format có field user ở đầu), còn `crontab <file>`
  cài file đó thành crontab riêng của user hiện tại (format KHÔNG có field user) — cùng
  1 file bị đọc theo 2 format khác nhau, bản `crontab` bị lỗi `root: not found` mỗi tick
  (chạy dư, không phải lỗi chức năng nhưng log rác). Chỉ dùng 1 trong 2 cơ chế.

### 9.2 Deploy option 3 — Shared hosting (không Docker, không Redis)

`docker-compose.shared-hosting.yml` **mô phỏng** shared hosting để test local:
- `docker/shared-hosting/apache/Dockerfile` — image Apache **riêng**, không dùng lại
  `docker/apache/Dockerfile` của option 2 (chỉ tái sử dụng vhost/php.ini của nó) — vì
  option 3 **không có entrypoint nào cả** (không wait-mysql/migrate/seed), khác hẳn ý
  nghĩa của việc chỉ override entrypoint ở compose level.
- Storage là **1 folder thật trong project** (`storage/private/`, bind-mount qua
  `STORAGE_ROOT=/var/www/html/storage/private`), không phải Docker volume — vì shared
  hosting thật không có khái niệm "volume", chỉ có folder trên đĩa. Có `storage/.htaccess`
  (`Require all denied`, giống pattern `application/.htaccess` có sẵn) để chặn truy cập
  web trực tiếp vào file object thô — đã test xác nhận trả `403`.
- `docker/shared-hosting/cron-wget/` — container cron chỉ có `wget`, không có PHP.

Deploy thật (không Docker) xem README mục "Option 3: deploying to real shared hosting".

Thay đổi cốt lõi để hỗ trợ option này:
- **`application/controllers/Cronjobs.php`** (mới) — endpoint `GET /cronjobs/process?token=...`
  thay cho CLI worker: cPanel cron chỉ gọi được URL, không chạy được script CLI/daemon.
  Bảo vệ bằng shared secret (`CRON_SECRET`) so sánh `hash_equals`, không dùng scheme ký
  OS3/AWS (wget không tự ký được).
- **`cli/worker_lib.php` dùng lại được cả trong request CI3 đang chạy** (không chỉ CLI
  độc lập): guard `define('BASEPATH', ...)`, đổi `require` → `require_once`. Cronjobs.php
  tái sử dụng `$this->db->conn_id` (property `mysqli` thật của driver mysqli trong CI3)
  làm tham số `mysqli $db` cho các function có sẵn — không cần viết lại logic xử lý event.
- **`Event_model::push()` không còn bắt buộc Redis** — `redis_enabled` (suy ra từ
  `REDIS_HOST` có set hay không, `config/s3.php`) quyết định có push Redis hay không;
  luôn insert vào bảng `events` trước (nguồn sự thật cho cả 3 option). Nếu Redis lỗi
  giữa chừng (Option 1/2), bọc try/catch — không làm fail request PUT/DELETE object.
- **`STDOUT`/`STDERR` không tồn tại ngoài CLI SAPI** — `worker_lib.php` chạy được cả
  trong web request (từ Cronjobs.php) nên mọi log dùng `worker_log()` (fallback
  `error_log()`) thay vì `fwrite(STDOUT/STDERR, ...)` trực tiếp (sẽ fatal nếu không).
- **`.htaccess` ở root project** (mới) — vì shared hosting không cho sửa vhost, phải
  đặt rewrite rule (front-controller + fix header `Authorization`) trực tiếp trong
  `.htaccess`.
- Bảng `access_keys` **không cần seed** ở option này — auth đọc trực tiếp từ
  `ACCESS_KEY_ID`/`SECRET_ACCESS_KEY` trong `.env`, không đọc từ DB.

---

## 10. Roadmap theo Phase

- [x] **Phase 0 — Chuẩn bị**: bump PHP runtime + composer deps (nếu cần thêm lib ký HMAC,
      Intervention/Image cho resize), viết `docker-compose.yml` skeleton, `.env.example`.
- [x] **Phase 1 — Data & Auth nền tảng**: migration DB (mục 3), `access_keys` seed,
      `Sigv4/Signer` (ký + verify), `MY_Controller` áp auth cho mọi route trừ `/healthz`.
- [x] **Phase 2 — Storage engine**: `Filesystem_driver` (write atomic, read, delete,
      chống path traversal), `Object_model`/`Bucket_model`.
- [x] **Phase 3 — Bucket & Object API cơ bản**: PUT/GET/DELETE/HEAD bucket & object,
      response XML giống S3 (`Xml_response`), test bằng `aws-cli --endpoint-url`.
- [x] **Phase 4 — Usecase 1 (Presigned URL)**: `Presign` controller, verify query-signature
      trên endpoint object PUT/GET, test luồng browser upload trực tiếp (CORS theo bucket —
      xem `MY_Controller::apply_cors()`: bucket không set `cors_config` thì không có header
      CORS nào, giống default an toàn của S3 thật; preflight `OPTIONS` được trả lời trước
      khi check auth).
- [x] **Phase 5 — Usecase 2 (Backend upload)**: `Upload` controller + `Pipeline`
      (Validator → ImageProcessor → optional Encryptor) lưu file + metadata.
- [x] **Phase 6 — Usecase 3 (Hybrid/Event)**: bảng `events`, Redis queue, `worker`
      container, webhook notify + retry, thumbnail job.
- [ ] **Phase 7 — Multipart upload**: initiate/part/complete/abort, ghép part thành object
      cuối, tính etag composite. (Hiện trả `501 NotImplemented`.)
- [x] **Phase 8 — Versioning & bucket policy**: toggle versioning, CORS config per bucket,
      `allowed_mime_types`, `max_object_size` — tất cả qua `PUT /internal/buckets/{bucket}/policy`.
- [ ] **Phase 9 — Security hardening**: rate limit Redis, TLS qua nginx, request size limit,
      secret rotation script. (Audit log đã có — bảng `audit_logs` + `MY_Controller::audit()`.)
- [ ] **Phase 10 — Observability**: structured JSON log, basic metrics (số request, tổng
      bytes uploaded) expose qua endpoint riêng cho Prometheus scrape. (`/healthz` đã có.)
- [ ] **Phase 11 — Testing**: PHPUnit cho `Sigv4/Signer` + pipeline; integration test
      bằng `aws-cli`/`boto3` script chạy trong CI, load test upload lớn (multipart).
- [x] **Phase 12 — Docs & migration guide**: Postman collection
      (`docs/OpenS3.postman_collection.json`) — chạy `docker compose up`, hướng dẫn đổi code
      app khác từ AWS S3 sang open-s3 vẫn cần viết thành 1 guide riêng (hiện nằm rải rác
      trong doc này + description của Postman collection).

---

## 11. Rủi ro & lưu ý khi triển khai

- **Không multi-tenant** đơn giản hoá auth rất nhiều, nhưng vẫn nên giữ bảng `access_keys`
  (thay vì hardcode trong `.env` đọc trực tiếp mọi lúc) để **rotate key không cần deploy lại**.
- **Presigned upload (usecase 1) bỏ qua pipeline validate/scan đồng bộ** → bắt buộc phải có
  Phase 6 (worker scan bất đồng bộ) để không tạo lỗ hổng upload file độc hại mà không ai check.
- **Multipart upload** là phần phức tạp nhất về mặt tương thích S3 — có thể để Phase 7 làm
  sau, MVP ban đầu chỉ cần PUT/GET/DELETE object đơn (dưới 5GB) đã đủ cho phần lớn usecase.
- **PHP 8.x + CodeIgniter 3** vẫn chạy được (CI3 hỗ trợ PHP8 từ bản 3.1.11+) — không cần
  nâng lên CI4, tránh rewrite toàn bộ skeleton hiện có; chỉ cần kiểm tra tương thích khi
  thêm code mới.
