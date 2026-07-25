# Kế hoạch v2: Admin Panel cho Open-S3

> Tiếp nối [`plans.md`](./plans.md) (kiến trúc S3-compatible API gốc, giữ nguyên không đổi).
> Doc này chỉ scope phần **thêm mới**: một admin panel web (session-based, không phải S3 API)
> để vận hành open-s3 qua UI thay vì gọi thẳng REST API/Postman collection.

## 0. Phạm vi & ràng buộc

- **Chỉ 1 role**: `admin`, không có multi-role/permission phức tạp (giống tinh thần
  single-account của v1) — nhiều admin user có thể tồn tại (bảng riêng), nhưng quyền hạn như nhau.
- **Auth khác hẳn v1**: admin panel dùng **session (cookie) + email/password**,
  KHÔNG dùng SigV4/OS3-HMAC. Route `/admin/*` và route S3 API (`/{bucket}/{key}`, `/internal/*`)
  là 2 vùng auth độc lập, không share code auth (`MY_Controller` giữ nguyên cho API,
  panel dùng `Admin_Controller` riêng — xem mục 4).
- **Không đổi behavior S3 API hiện có** — admin panel chỉ đọc/ghi cùng bảng DB
  (`buckets`, `objects`, `events`...) qua model có sẵn, tái sử dụng `Bucket_model`/`Object_model`/
  `Event_model`, không viết lại storage engine.
- **Từ nay mọi thay đổi schema DB bắt buộc đi qua CI Migration** (mục 2), không sửa
  tay `db/schema.sql` nữa — xem mục 2 cho lộ trình chuyển đổi.

---

## 1. Kiến trúc bổ sung

```text
                        ┌──────────────────────────┐
                        │        nginx (TLS)       │
                        └────────────┬─────────────┘
                                     │
                        ┌────────────▼─────────────┐
                        │   app (CodeIgniter/PHP-FPM)│
                        │  ┌───────────────────────┐ │
                        │  │  S3 API (v1, giữ nguyên)│ │  <- SigV4/OS3-HMAC, stateless
                        │  └───────────────────────┘ │
                        │  ┌───────────────────────┐ │
                        │  │  Admin Panel (v2, mới) │ │  <- Session cookie, CSRF
                        │  │  /admin/*              │ │
                        │  └───────────────────────┘ │
                        └───┬───────────┬───────────┘
                            │           │
                       MySQL         Redis (session driver, tuỳ chọn)
```

Admin panel là **server-rendered CI views** (không SPA riêng, không thêm build step JS
frontend) — đủ cho use-case vận hành nội bộ, tránh kéo thêm toolchain (webpack/vite) vào
một project CodeIgniter 3 thuần PHP. Có dùng chút JS thuần (fetch + vanilla DOM) cho phần
tree-view object (mục 7.3) để mở/đóng folder không cần reload trang.

---

## 2. Migration hoá schema (bắt buộc từ v2 trở đi)

`db/schema.sql` (toàn bộ `CREATE TABLE IF NOT EXISTS`, áp bằng `cli/migrate.php` — xem
plans.md mục 9) **được giữ lại nguyên trạng làm baseline cho môi trường mới/shared-hosting**,
nhưng không sửa tay nữa. Từ v2 trở đi:

- Bật `$config['migration_enabled'] = TRUE;` trong `application/config/migration.php`
  (hiện đang `FALSE`), giữ `migration_type = 'timestamp'`.
- Tạo `application/migrations/` (chưa tồn tại) với **1 migration baseline**
  (`20260101000000_baseline_schema.php`) — `up()` gọi lại đúng nội dung `db/schema.sql`
  hiện có (6 bảng: `access_keys`, `buckets`, `objects`, `multipart_uploads`,
  `multipart_parts`, `events`, `audit_logs`) bằng `$this->db->query(...)`, `down()` DROP
  theo thứ tự ngược (tôn trọng FK). Baseline này KHÔNG thay đổi schema — mục đích chỉ để
  bảng `migrations` (CI tự tạo) có mốc bắt đầu khớp với DB đã tồn tại ở các môi trường
  đang chạy (không migrate lại từ đầu, chỉ cần insert đúng version vào bảng `migrations`
  cho DB cũ — ghi rõ bước này trong README, không tự động).
- Migration thứ 2 trở đi (bắt đầu từ admin panel — mục 3) là nơi **duy nhất** được thêm/sửa
  bảng. `cli/migrate.php` (script apply `schema.sql`, dùng cho Docker option 1/2 và
  shared-hosting option 3) **giữ nguyên không đổi** cho 6 bảng baseline, nhưng gọi thêm
  CI's `Migration` library (`$this->load->library('migration'); $this->migration->latest();`)
  ngay sau bước áp `schema.sql`, để mọi bảng mới (từ v2 trở đi) tự áp khi container khởi động —
  cùng cơ chế idempotent như `schema.sql` (`CREATE TABLE IF NOT EXISTS` tương đương qua
  `migration_auto_latest` chỉ chạy phần chưa áp).
- Đổi tên: bảng CI tự quản là `migrations` — trùng tên với khái niệm chung, không đụng bảng
  nào hiện có trong schema v1.

---

## 3. Data model bổ sung (qua migration, xem mục 2)

```sql
-- Migration: 20260101000100_create_admins_table.php
CREATE TABLE IF NOT EXISTS admins (
  id INT PRIMARY KEY AUTO_INCREMENT,
  email VARCHAR(255) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,   -- password_hash() (bcrypt/argon2id)
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at DATETIME NULL,
  failed_login_attempts INT NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,             -- throttle brute-force, xem mục 5.3
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

- Seed admin đầu tiên **không** hard-code trong migration (tránh commit password thật).
  Thêm script `cli/create_admin.php email password` (dùng `password_hash()`, insert/`ON
  DUPLICATE KEY UPDATE` theo email) — chạy tay sau khi migrate, giống tinh thần
  "seed access key qua ENV" của v1 nhưng cho admin thì cần nhập password tương tác/argv
  thay vì ENV cố định (nhiều admin, đổi password được).
- Không cần bảng `admin_sessions` riêng — dùng CI's `Session` library với driver `database`
  (bảng `ci_sessions`, CI tự tạo qua migration chuẩn của thư viện Session, xem mục 5.1) để
  session sống được qua nhiều PHP-FPM worker/container restart, không mất login khi deploy lại.

---

## 4. Cấu trúc thư mục bổ sung

```text
application/
  core/
    Admin_Controller.php       # base controller cho mọi trang /admin/* (session guard + CSRF)
  controllers/
    admin/
      Auth.php                  # GET/POST /admin/login, POST /admin/logout
      Dashboard.php              # GET /admin (tổng quan: số bucket, object, event pending/failed)
      Buckets.php                # CRUD bucket + edit config
      Objects.php                # tree browse + preview + delete
      Events.php                 # list + filter theo status + manual redispatch
  models/
    Admin_model.php              # findByEmail, verifyPassword, recordLoginAttempt, lockout
  views/
    admin/
      layout.php                  # header/sidebar/footer dùng chung (PHP include, không framework CSS ngoài — xem mục 9)
      login.php
      dashboard.php
      buckets/
        list.php
        form.php                  # create + edit dùng chung 1 view (mode create/edit)
      objects/
        tree.php                   # khung trang; nội dung cây load qua fetch JSON (xem 7.3)
        preview.php                 # modal/partial preview nội dung object
      events/
        list.php
  migrations/
    20260101000000_baseline_schema.php
    20260101000100_create_admins_table.php
cli/
  create_admin.php               # seed/reset 1 admin qua CLI
assets/
  admin.src.css                   # @tailwind base/components/utilities (input cho Tailwind CLI)
  admin.css                       # output đã build + minify, commit hoặc build lúc Docker build
  admin_tree.js                   # object tree lazy-load (mục 7.3)
  admin_sidebar.js                 # collapse/expand + localStorage (mục 9)
  fonts/                           # icon font self-host (vd Font Awesome webfont + css)
```

---

## 5. Auth admin

### 5.1 Session

- Bật CI Session library (chưa autoload trong v1 — `application/config/autoload.php` hiện
  `$autoload['libraries'] = array();`) nhưng **chỉ load trong `Admin_Controller`**, không
  autoload toàn cục (route S3 API là stateless, không cần cookie).
- `application/config/config.php` cần set: `sess_driver = 'database'`, `sess_save_path =
  'ci_sessions'`, cookie tên riêng `s3_admin_session` (không trùng cookie mặc định `ci_session`,
  tránh đụng nếu sau này có service khác chung domain), `sess_match_ip = FALSE` (admin có thể
  đổi mạng giữa chừng), `sess_time_to_update = 300`.
- Bảng `ci_sessions` tạo qua **migration** (không phải `schema.sql`), lấy đúng DDL chuẩn
  CI3 Session-database driver documentation (`id`, `ip_address`, `timestamp`, `data BLOB`).

### 5.2 Login flow

- `GET /admin/login` — render form (email/password + CSRF token field, xem 5.4).
- `POST /admin/login` — `Admin_model::findByEmail()`, check `is_active`, check
  `locked_until` (nếu còn trong tương lai → chặn, thông báo "quá nhiều lần đăng nhập sai,
  thử lại sau"), `password_verify()`. Sai → tăng `failed_login_attempts`; đủ 5 lần trong
  15 phút → set `locked_until = now()+15min`, reset counter. Đúng → reset counter,
  update `last_login_at`, `session->set_userdata('admin_id', ...)`, redirect `/admin`.
- `Admin_Controller::__construct()` (base cho mọi controller trong `controllers/admin/`
  trừ `Auth::login`) check `session->userdata('admin_id')`, không có → redirect
  `/admin/login`. Không dùng HTTP Basic Auth (không thân thiện, khó logout thật).

### 5.3 Brute-force throttle

- Áp dụng ở tầng DB (`admins.failed_login_attempts`/`locked_until`), không cần Redis
  rate-limit riêng cho v2 (khác rate-limit theo IP cho S3 API, vẫn để ở Phase 9 của
  plans.md, chưa làm) — vì admin login theo email cụ thể, không theo IP.

### 5.4 CSRF

- **Không bật `$config['csrf_protection']` global** — CI3's CSRF filter (nếu bật ở
  `config.php`) áp dụng cho MỌI POST của toàn app, buộc phải thêm `csrf_exclude_uris`
  cho từng route S3 API POST hiện có (`internal/presign`, `internal/uploads/*`, multipart
  `POST` trên `/{bucket}/{key}`, `cronjobs/process`) — rủi ro quên 1 route là request SDK/curl
  bị chặn nhầm (các route đó ký bằng SigV4/shared-secret, không phải form nên không gửi
  được CSRF token/cookie). Thay vào đó, CSRF cho v2 làm **thủ công, chỉ trong phạm vi
  `Admin_Controller`**, hoàn toàn tách biệt khỏi cấu hình global:
  - `Admin_Controller::__construct()` (sau khi xác nhận session hợp lệ) gọi
    `$this->security->get_csrf_hash()` / tự sinh token lưu vào
    `session->userdata('admin_csrf_token')` nếu chưa có, expose ra view qua 1 biến
    dùng chung (`$csrf_token`) để mọi form trong `views/admin/*` render
    `<input type="hidden" name="csrf_token" value="...">`.
  - `Admin_Controller::verify_csrf()` — helper gọi ở đầu mỗi method xử lý POST/PUT/DELETE
    trong `controllers/admin/*` (Buckets create/edit/delete, Events redispatch, Auth logout...),
    so sánh `$this->input->post('csrf_token')` với token trong session bằng `hash_equals()`,
    sai → `403` + huỷ session. Token **không đổi mỗi request** (giữ nguyên trong session,
    reset khi login lại) để tránh vỡ khi user mở nhiều tab admin cùng lúc.
  - Vì không đụng `$config['csrf_protection']`, toàn bộ route S3 API (`S3.php`, `Internal.php`,
    `Cronjobs.php`) **không cần sửa gì, không cần exclude list** — loại bỏ hẳn rủi ro
    "quên whitelist 1 route S3 API" đã nêu ở bản trước.

---

## 6. Routes

```php
$route['admin']              = 'admin/dashboard';
$route['admin/login']        = 'admin/auth/login';
$route['admin/logout']       = 'admin/auth/logout';
$route['admin/buckets']      = 'admin/buckets/index';
$route['admin/buckets/new']  = 'admin/buckets/create';
$route['admin/buckets/(.+)/edit']    = 'admin/buckets/edit/$1';
$route['admin/buckets/(.+)/delete']  = 'admin/buckets/delete/$1';
$route['admin/buckets/(.+)/objects/tree']    = 'admin/objects/tree/$1';   // JSON, cho JS fetch
$route['admin/buckets/(.+)/objects/preview'] = 'admin/objects/preview/$1';
$route['admin/buckets/(.+)/objects']         = 'admin/objects/index/$1';
$route['admin/events']       = 'admin/events/index';
$route['admin/events/(\d+)/redispatch'] = 'admin/events/redispatch/$1';
```

Thêm các route `admin/*` này **phía trên** dòng catch-all `$route['(.*)'] = 's3/index/$1';`
hiện có trong `application/config/routes.php` (thứ tự route trong CI3 theo khai báo, catch-all
phải luôn ở cuối cùng).

---

## 7. Tính năng

### 7.1 Dashboard

Tổng số bucket, tổng object, tổng dung lượng (SUM(size) theo bucket còn current version),
số event theo từng status (đếm nhanh từ `events`), 10 audit log gần nhất (`audit_logs`,
đã có sẵn từ v1, panel chỉ đọc).

### 7.2 Bucket management

- **List**: `Bucket_model::listAll()` (đã có) + thêm cột object count/dung lượng (query
  thêm, không sửa model hiện có, viết method mới `Bucket_model::withStats()`).
- **Create**: form 1 field `name` (validate theo rule đặt tên bucket S3 — dùng lại
  validation hiện có nếu `S3.php` đã có helper regex, nếu chưa thì thêm
  `s3_helper::isValidBucketName()`), gọi `Bucket_model::create()` (đã có).
- **Edit config**: 1 form map thẳng vào `Bucket_model::updatePolicy()` (đã có, nhận
  `versioning_enabled`, `is_public`, `cors_config`, `notification_url`, `max_object_size`,
  `allowed_mime_types`) — `cors_config`/`allowed_mime_types` nhập dạng JSON textarea (validate
  `json_decode` không lỗi trước khi submit), không cần UI phức tạp hơn cho v2.
- **Delete**: chỉ cho phép khi bucket rỗng (check `Object_model::listByPrefix($id, '', 1)`
  rỗng trước, giống rule `DELETE /{bucket}` của S3 API — tái dùng luôn logic đó thay vì
  viết lại).

### 7.3 Object tree browser

Schema hiện tại lưu `object_key` là chuỗi phẳng (không có khái niệm "folder" thật, giống
S3 thật) — v1 mới hỗ trợ list theo `prefix` (LIKE, mục Object_model.php:33), **chưa có
delimiter/common-prefix grouping**. Tree-mode cần thêm logic này (chỉ cho panel, không đụng
S3 API v1):

- `Object_model::listFolder($bucketId, $prefix)` (method mới): lấy các `object_key
  LIKE '{prefix}%'`, rồi ở PHP tách phần sau `$prefix` tại dấu `/` đầu tiên — nếu có `/`
  → đó là 1 "folder" (gom nhóm, đếm số object bên trong, không query đệ quy hết cây cùng
  lúc); nếu không có `/` → đó là 1 file lá. Trả về 2 mảng: `folders` (tên + count) và
  `files` (key, size, etag, content_type, created_at) — tương tự cách S3 REST thật trả
  `CommonPrefixes` + `Contents` khi có `delimiter=/`.
- `GET /admin/buckets/{bucket}/objects/tree?prefix=...` trả JSON `{folders:[...],
  files:[...]}` — sidebar cây gọi lại endpoint này mỗi lần user click mở 1 folder (lazy-load,
  không load hết cây 1 lần — tránh chậm với bucket nhiều object).
- View `objects/tree.php` chỉ render khung + 1 file JS nhỏ (`admin_tree.js`,
  vanilla, không thêm dependency) gọi `fetch()` tới endpoint trên và build `<ul>` lồng nhau.

### 7.4 Object preview

- `GET /admin/buckets/{bucket}/objects/preview?key=...` — lấy metadata qua
  `Object_model::getCurrent()` (đã có), stream nội dung qua `Filesystem_driver` (đã có,
  method đọc object hiện dùng cho `GET /{bucket}/{key}` của S3 API — tái sử dụng, không
  viết lại storage read).
- Preview **inline** (render trực tiếp trong trang) nếu `content_type` thuộc:
  `image/*` (`<img>` trực tiếp), `text/*`, `application/json` (hiển thị dạng `<pre>`,
  giới hạn đọc N KB đầu để tránh load file text khổng lồ vào RAM), `application/pdf`
  (`<iframe>` — trình duyệt tự render, không cần lib PHP thêm).
- Các content-type khác (video, zip, binary...) → **không preview**, chỉ hiện nút
  "Download" trỏ thẳng object gốc qua signed URL tạm (dùng lại
  `Sigv4_signer::presignUrl()` nội bộ — server tự ký, không expose secret key ra
  browser) thay vì stream qua session route (tránh panel phải proxy file lớn).

### 7.5 Event & dispatch status

- `GET /admin/events` — list `events` (đã có `Event_model::list($status, $limit)`),
  filter theo `status` (pending/processing/done/failed), hiển thị `attempts`,
  `last_error`, `created_at`/`updated_at`.
- **Redispatch thủ công** (`POST /admin/events/{id}/redispatch`): cho phép admin retry
  1 event `failed` — set lại `status = 'pending'`, `attempts = 0`, rồi push lại Redis
  queue nếu `redis_enabled` (dùng lại `Event_model`/`Redis_queue` hiện có) — không cần
  thêm bảng/queue mới, chỉ thêm 1 method `Event_model::requeue($id)`.

---

## 8. Bảo mật riêng cho v2

- Password admin luôn `password_hash()` (bcrypt cost mặc định của PHP 8.1, không tự
  chọn thuật toán khác) — không bao giờ log/echo password thật ra `audit_logs`.
- Mọi request POST/PUT/DELETE trong `controllers/admin/` bắt buộc qua CSRF (mục 5.4)
  + session guard (mục 5.2) — kể cả redispatch event, delete bucket.
- Route `/admin/*` nên giới hạn thêm ở tầng nginx (mục 9 của plans.md) bằng allowlist IP
  nếu deploy public — ghi chú trong README, không bắt buộc code (tuỳ môi trường deploy).
- Object preview inline cho `text/*`/`application/json` phải `htmlspecialchars()` khi
  render trong `<pre>` (tránh XSS nếu object chứa HTML/script — nội dung do người dùng
  S3 API upload, không tin tưởng).

---

## 9. UI

- **Tailwind CSS**, nhưng build sẵn thành 1 file CSS tĩnh, KHÔNG dùng CDN script
  (`cdn.tailwindcss.com`) lúc runtime — vì project không có Node/npm toolchain sẵn (thuần
  PHP/CodeIgniter 3) và tránh phụ thuộc mạng ngoài khi request trang admin. Dùng
  **Tailwind Standalone CLI** (binary độc lập, không cần cài Node) để compile 1 lần lúc
  build/deploy:
  ```bash
  ./tailwindcss -i assets/admin.src.css -o application/views/../../assets/admin.css --minify
  ```
  `assets/admin.src.css` (`@tailwind base/components/utilities` + class riêng nếu cần)
  commit vào repo, `assets/admin.css` (output) build trong `docker/app/Dockerfile` (thêm
  1 step tải Tailwind CLI binary + chạy build khi image build, giống cách Dockerfile hiện
  đã bump PHP runtime) — panel load `assets/admin.css` qua `<link>` tương đối, hoàn toàn
  local, không gọi ra ngoài.
- **Icon**: dùng icon font self-host (vd Font Awesome Free — webfont/`.woff2` +
  `.css` copy thẳng vào `assets/fonts/`, KHÔNG load qua CDN `kit.fontawesome.com`) cho
  menu sidebar (dashboard/bucket/object/event...) — mỗi item menu là `<i class="fa
  fa-...">` + label.
- **Sidebar collapse/expand**: 1 nút toggle ở đầu sidebar, dùng Tailwind class có sẵn
  (`w-64` ↔ `w-16`, ẩn label chỉ giữ icon khi collapsed) chuyển qua lại bằng vanilla JS
  (`admin_sidebar.js`, toggle 1 class trên `<body>` + lưu trạng thái vào `localStorage`
  để giữ nguyên collapse/expand qua lần load trang sau, không cần lưu server-side).
- Layout: sidebar (Dashboard/Buckets/Events) + content area, responsive tối thiểu (chỉ
  cần chạy tốt desktop, đây là tool nội bộ cho admin) — mọi component (bảng, form, badge
  status event...) dùng thẳng utility class Tailwind, không viết thêm CSS tuỳ chỉnh ngoài
  vài dòng cho riêng sidebar transition.

---

## 10. Roadmap theo Phase (tiếp Phase 12 của plans.md)

- [ ] **Phase 13 — Migration hoá**: bật `migration_enabled`, viết migration baseline
      (mục 2), sửa `cli/migrate.php` gọi `$this->migration->latest()` sau khi áp
      `schema.sql`, cập nhật README hướng dẫn insert version baseline cho DB đang chạy.
- [ ] **Phase 14 — Admin auth nền tảng**: bảng `admins` + `ci_sessions` (migration),
      `Admin_model`, `Admin_Controller` (session guard + CSRF), `controllers/admin/Auth.php`
      (login/logout), `cli/create_admin.php`, `layout.php` + Tailwind build step (Tailwind
      Standalone CLI trong `docker/app/Dockerfile`, self-host icon font) + sidebar
      collapse/expand (`admin_sidebar.js`).
- [ ] **Phase 15 — Bucket management UI**: list/create/edit-config/delete bucket qua
      `controllers/admin/Buckets.php`, tái dùng `Bucket_model` hiện có + `withStats()` mới.
- [ ] **Phase 16 — Object tree & preview**: `Object_model::listFolder()`, tree JSON
      endpoint + `admin_tree.js`, preview endpoint (inline image/text/json/pdf + download
      link qua presigned URL).
- [ ] **Phase 17 — Events UI**: list/filter theo status, `Event_model::requeue()`,
      nút redispatch thủ công cho event `failed`.
- [ ] **Phase 18 — Hardening & docs**: rà soát toàn bộ `controllers/admin/*` để chắc mọi
      method POST/PUT/DELETE đều gọi `verify_csrf()` (không có route nào lọt), ghi hướng
      dẫn deploy `/admin/*` sau IP allowlist (nginx) vào README, thêm admin panel vào
      Postman/docs nếu cần test thủ công.

---

## 11. Rủi ro & lưu ý

- **Baseline migration không tự chạy lại schema cho DB đang có sẵn** (đã áp qua
  `schema.sql` từ trước) — phải insert tay 1 dòng vào bảng `migrations` (CI tự tạo) đánh
  dấu baseline coi như đã chạy, nếu không CI sẽ cố tạo lại 6 bảng đã tồn tại và lỗi
  duplicate table. Ghi rõ lệnh SQL cụ thể trong README lúc thực hiện Phase 13, không được
  quên bước này khi deploy lên môi trường cũ.
- **CSRF làm thủ công trong `Admin_Controller` (mục 5.4) thay vì bật global** — đổi lại,
  rủi ro chuyển thành "quên gọi `verify_csrf()` ở 1 method POST/PUT/DELETE mới nào đó
  trong `controllers/admin/*`" thay vì quên whitelist route S3 API. Vì không đụng
  `$config['csrf_protection']`, route S3 API hiện có không cần test lại/không có rủi ro
  bị chặn nhầm — nhưng đổi lại phải tự kỷ luật gọi `verify_csrf()` ở mọi controller admin
  mới thêm sau này (checklist ở Phase 18).
- **Object tree lazy-load theo prefix** tránh được vấn đề hiệu năng với bucket nhiều
  object, nhưng vẫn nên giới hạn `LIMIT` mỗi lần gọi (giống `listByPrefix` hiện có) và
  hiển thị "còn nhiều hơn, thu hẹp bằng prefix" thay vì cố load hết — không làm phân trang
  đầy đủ trong v2 đầu tiên, chỉ giới hạn hợp lý (vd 500 item/folder).
