<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<?php $isEdit = ($mode === 'edit'); ?>
<div class="flex items-center gap-3 mb-6">
  <a href="<?php echo site_url('admin/buckets'); ?>" class="text-gray-400 hover:text-gray-700">
    <i class="material-icons align-middle">arrow_back</i>
  </a>
  <h1 class="text-xl font-semibold text-gray-800">
    <?php echo $isEdit ? 'Edit bucket &ldquo;' . htmlspecialchars($bucket['name']) . '&rdquo;' : 'New bucket'; ?>
  </h1>
</div>

<div class="bg-white rounded-lg shadow p-6 max-w-2xl">
  <form method="post"
        action="<?php echo $isEdit ? site_url('admin/buckets/' . $bucket['name'] . '/edit') : site_url('admin/buckets/new'); ?>"
        class="space-y-5">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

    <?php if (!$isEdit): ?>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1" for="name">Bucket name</label>
        <input id="name" name="name" type="text" required pattern="[a-z0-9][a-z0-9\-\.]{1,61}[a-z0-9]"
               placeholder="my-bucket"
               class="w-full rounded border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-gray-800">
        <p class="mt-1 text-xs text-gray-400">Lowercase letters, digits, "-" and ".", 3-63 characters.</p>
      </div>
    <?php else: ?>
      <div class="grid grid-cols-2 gap-4">
        <label class="flex items-center gap-2 text-sm text-gray-700">
          <input type="checkbox" name="versioning_enabled" value="1" <?php echo ((int) $bucket['versioning_enabled']) ? 'checked' : ''; ?>
                 class="rounded border-gray-300">
          Versioning enabled
        </label>
        <label class="flex items-center gap-2 text-sm text-gray-700">
          <input type="checkbox" name="is_public" value="1" <?php echo ((int) $bucket['is_public']) ? 'checked' : ''; ?>
                 class="rounded border-gray-300">
          Public read (anonymous GET/HEAD object)
        </label>
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1" for="max_object_size">Max object size (bytes)</label>
        <input id="max_object_size" name="max_object_size" type="number" min="1"
               value="<?php echo (int) $bucket['max_object_size']; ?>"
               class="w-full rounded border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-gray-800">
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1" for="notification_url">Notification webhook URL</label>
        <input id="notification_url" name="notification_url" type="url"
               value="<?php echo htmlspecialchars((string) $bucket['notification_url']); ?>"
               placeholder="https://example.com/webhooks/open-s3"
               class="w-full rounded border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-gray-800">
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1" for="cors_config">CORS config (JSON, empty = disabled)</label>
        <textarea id="cors_config" name="cors_config" rows="4"
                  placeholder='{"allowed_origins":["*"],"allowed_methods":["GET","PUT"]}'
                  class="w-full rounded border border-gray-300 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-gray-800"><?php
                    echo htmlspecialchars((string) $bucket['cors_config']);
                  ?></textarea>
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1" for="allowed_mime_types">Allowed MIME types (JSON array, empty = any)</label>
        <textarea id="allowed_mime_types" name="allowed_mime_types" rows="2"
                  placeholder='["image/png","image/jpeg"]'
                  class="w-full rounded border border-gray-300 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-gray-800"><?php
                    echo htmlspecialchars((string) $bucket['allowed_mime_types']);
                  ?></textarea>
      </div>
    <?php endif; ?>

    <div class="flex gap-3">
      <button type="submit" class="rounded bg-gray-900 text-white text-sm font-medium px-4 py-2 hover:bg-gray-800">
        <?php echo $isEdit ? 'Save changes' : 'Create bucket'; ?>
      </button>
      <a href="<?php echo site_url('admin/buckets'); ?>" class="rounded border border-gray-300 text-sm font-medium px-4 py-2 text-gray-700 hover:bg-gray-50">
        Cancel
      </a>
    </div>
  </form>
</div>
