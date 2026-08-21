---

name: upload-to-s3-presigned
description: Upload files to S3-compatible storage using a presigned URL.

---

# Upload File to S3 Storage

Use this skill to upload a local file to S3-compatible storage through a presigned URL.

## Prerequisites

Before starting, make sure you have:

* `shorterm_token` — short-term authentication token.
* `presign_host` — host of the presign service.
* `file_path` — path to the file to upload.
* `file_name` — storage key/name for the file.
* `content_type` — MIME type of the file.

If `shorterm_token` or `presign_host` is missing, ask the user to provide it before proceeding.

## Step 1: Generate a Presigned URL

Call the presign API to generate a presigned URL.

```bash
curl --location "${presign_host}/internal/presign-with-token" \
  --header "Content-Type: application/json" \
  --header "X-Os3-Token: ${shorterm_token}" \
  --data '{
    "key": "'"${file_name}"'",
    "method": "PUT",
    "expiresIn": 300
  }'
```

The presigned URL is returned in:

```text
body.url
```

Store this value as `presigned_url`.

## Step 2: Upload the File

Upload the file using the presigned URL.

```bash
curl --location --request PUT "${presigned_url}" \
  --header "Content-Type: ${content_type}" \
  --data-binary "@${file_path}"
```

Use `--data-binary` to preserve the file contents exactly.

## Step 3: Get the Public URL

After the upload succeeds, derive the public URL from `presigned_url` by removing **all query parameters**.

For example:

```text
Presigned URL:
https://storage.example.com/my-file.pdf?X-Amz-Algorithm=...&X-Amz-Signature=...

Public URL:
https://storage.example.com/my-file.pdf
```

The resulting URL is the public/object URL.

## Notes

* The presigned URL expires after `expiresIn` seconds.
* The default expiration in this skill is `300` seconds.
* The HTTP method used to generate the presigned URL must match the upload method (`PUT`).
* The `Content-Type` used during upload should match the expected file MIME type.
* Never expose or log `shorterm_token`.
