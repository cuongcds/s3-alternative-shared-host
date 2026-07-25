# AWS S3 Integration Best Practices

## 1. Client Upload trực tiếp lên S3 (Khuyến nghị ⭐⭐⭐⭐⭐)

``` text
Browser/Mobile
   │
Request upload URL
   ▼
Backend API
   │
Generate Presigned URL
   ▼
Amazon S3
   ▲
Upload trực tiếp
   │
Browser/Mobile
```

**Flow** 1. Client gọi API backend. 2. Backend xác thực user. 3. Backend
tạo Presigned URL (5--15 phút). 4. Client upload trực tiếp lên S3. 5.
Backend lưu metadata vào DB.

**Ưu điểm** - Không tốn bandwidth server. - Scale tốt. - Backend không
xử lý file. - Chi phí thấp.

------------------------------------------------------------------------

## 2. Backend Upload lên S3

-   Dùng khi cần:
    -   Scan virus
    -   Resize ảnh
    -   Watermark
    -   Validate
    -   Encrypt

Nhược điểm: - Server chịu tải. - Tăng bandwidth. - Chậm hơn.

------------------------------------------------------------------------

## 3. Hybrid Architecture

``` text
Client
  │
Backend (Auth + Presigned URL)
  │
Client Upload
  ▼
S3
  │
S3 Event
  ▼
Lambda / Worker
```

Lambda có thể: - Resize - OCR - AI - Thumbnail - Virus scan

------------------------------------------------------------------------
