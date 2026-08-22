---

name: upload-to-s3-presigned
description: Upload files to S3-compatible storage using a presigned URL.

---

# Upload File to S3 Storage

Use this skill to upload a file to S3-compatible storage through a presigned URL. The
content to upload can come from either an existing file on disk, or raw binary/base64
content you already have in hand (no file needs to exist beforehand).

## Prerequisites

Before starting, make sure you have:

* `shorterm_token` — short-term authentication token.
* `presign_host` — host of the presign service.
* `file_name` — storage key/name for the file.
* `content_type` — MIME type of the file.
* One of:
  * `file_path` — path to an existing local file to upload, **or**
  * `file_content` — the raw content to upload directly, as either:
    * a base64-encoded string (most reliable for binary data), or
    * a plain-text string (for text content only, e.g. `.txt`/`.json`/`.md`).

If `shorterm_token` or `presign_host` is missing, ask the user to provide it before proceeding.

The presign service itself guards against overwriting an existing object: when you ask for
a `PUT` presign and `file_name` already has an object, the service transparently swaps in a
different, free key (a random UUID with the same extension) instead of signing a URL for
the name you asked for. **Always read the actual key back from the response** (see Step 1)
— it may not match the `file_name` you sent.

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

The response contains:

```text
body.url    — the presigned URL to upload to
body.key    — the key that will actually be used (may differ from the file_name you sent,
              if that name already existed)
body.bucket — the bucket the object will land in
```

Store `body.url` as `presigned_url` and `body.key` as the resolved key — you'll need the
resolved key (not your original `file_name`) to build the public URL in Step 3.

## Step 2: Upload the Content

How to upload depends on which input you were given.

### Option A — `file_path` (existing file on disk)

```bash
curl --location --request PUT "${presigned_url}" \
  --header "Content-Type: ${content_type}" \
  --data-binary "@${file_path}"
```

Use `--data-binary` to preserve the file contents exactly.

### Option B — `file_content` as base64

Decode to a temporary file first, so binary bytes are transferred exactly — do not pipe
base64 text straight into `--data-binary`, that would upload the base64 text itself
instead of the decoded bytes:

```bash
tmp_file=$(mktemp)
printf '%s' "${file_content}" | base64 -d > "${tmp_file}"

curl --location --request PUT "${presigned_url}" \
  --header "Content-Type: ${content_type}" \
  --data-binary "@${tmp_file}"

rm -f "${tmp_file}"
```

### Option C — `file_content` as plain text (text content only)

```bash
curl --location --request PUT "${presigned_url}" \
  --header "Content-Type: ${content_type}" \
  --data-binary "${file_content}"
```

Only use this for genuinely textual content. If the content is or might be binary
(images, PDFs, archives, etc.), always go through Option B instead — passing raw bytes
through shell variables/arguments risks corruption (e.g. embedded NUL bytes, encoding
conversion) that base64 avoids.

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
* The presign service checks existence for you on every `PUT` request (via a self-signed
  `HEAD`, the same mechanism an AWS SDK's `HeadObjectCommand` uses) and silently swaps in a
  fresh random key if `file_name` is already taken or existence couldn't be confirmed — you
  never need to check this yourself, just read `body.key` from the presign response.
* When given `file_content`, prefer base64 (Option B) over plain text (Option C) unless
  you are certain the content is text — this is what makes it possible to upload content
  that only exists in memory (e.g. generated data, pasted/attached content) without ever
  needing it to already be a file on disk.
