<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<?php
$key = $object['object_key'];
$downloadUrl = site_url('admin/buckets/' . rawurlencode($bucket['name']) . '/objects/download') . '?key=' . rawurlencode($key);
$treeUrl = site_url('admin/buckets/' . rawurlencode($bucket['name']) . '/objects');
?>
<div class="flex items-center gap-3 mb-2">
  <a href="<?php echo $treeUrl; ?>" class="text-gray-400 hover:text-gray-700">
    <i class="material-icons align-middle">arrow_back</i>
  </a>
  <h1 class="text-xl font-semibold text-gray-800 break-all"><?php echo htmlspecialchars($key); ?></h1>
</div>

<div class="text-sm text-gray-500 mb-4 flex flex-wrap gap-x-6 gap-y-1">
  <span>Size: <?php echo s3_format_bytes($object['size']); ?></span>
  <span>Content-Type: <?php echo htmlspecialchars((string) $object['content_type']); ?></span>
  <span>ETag: <?php echo htmlspecialchars($object['etag']); ?></span>
  <span>Modified: <?php echo htmlspecialchars($object['created_at']); ?></span>
</div>

<div class="mb-4">
  <a href="<?php echo $downloadUrl; ?>"
     class="inline-flex items-center gap-2 rounded bg-gray-900 text-white text-sm font-medium px-4 py-2 hover:bg-gray-800">
    <i class="material-icons text-lg">download</i>
    Download
  </a>
</div>

<div class="bg-white rounded-lg shadow p-6">
  <?php if ($isImage): ?>
    <img src="<?php echo $downloadUrl; ?>" alt="<?php echo htmlspecialchars($key); ?>" class="max-w-full max-h-[70vh] mx-auto rounded">
  <?php elseif ($isPdf): ?>
    <iframe src="<?php echo $downloadUrl; ?>" class="w-full h-[70vh] rounded border border-gray-200"></iframe>
  <?php elseif ($isText): ?>
    <pre class="text-xs bg-gray-50 rounded p-4 overflow-auto max-h-[70vh] whitespace-pre-wrap break-all"><?php echo htmlspecialchars((string) $textSnippet); ?></pre>
    <?php if ($textTruncated): ?>
      <p class="mt-2 text-xs text-amber-600">Preview truncated — download the file to see the rest.</p>
    <?php endif; ?>
  <?php else: ?>
    <p class="text-sm text-gray-400">No inline preview available for this content type.</p>
  <?php endif; ?>
</div>
