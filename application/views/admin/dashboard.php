<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<h1 class="text-xl font-semibold text-gray-800 mb-6">Dashboard</h1>

<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
  <div class="bg-white rounded-lg shadow p-5">
    <div class="text-xs text-gray-400 mb-1">Buckets</div>
    <div class="text-2xl font-semibold text-gray-800"><?php echo (int) $bucketCount; ?></div>
  </div>
  <div class="bg-white rounded-lg shadow p-5">
    <div class="text-xs text-gray-400 mb-1">Objects</div>
    <div class="text-2xl font-semibold text-gray-800"><?php echo (int) $totalObjects; ?></div>
  </div>
  <div class="bg-white rounded-lg shadow p-5">
    <div class="text-xs text-gray-400 mb-1">Total size</div>
    <div class="text-2xl font-semibold text-gray-800"><?php echo s3_format_bytes($totalSize); ?></div>
  </div>
  <div class="bg-white rounded-lg shadow p-5">
    <div class="text-xs text-gray-400 mb-1">Events pending/failed</div>
    <div class="text-2xl font-semibold text-gray-800">
      <?php echo (int) $eventCounts['pending']; ?> / <?php echo (int) $eventCounts['failed']; ?>
    </div>
  </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
  <div class="bg-white rounded-lg shadow p-5">
    <h2 class="text-sm font-semibold text-gray-700 mb-4">Event status</h2>
    <ul class="space-y-2 text-sm">
      <?php foreach ($eventCounts as $status => $count): ?>
        <li class="flex items-center justify-between">
          <span class="text-gray-600"><?php echo ucfirst($status); ?></span>
          <span class="font-medium text-gray-800"><?php echo (int) $count; ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
    <a href="<?php echo site_url('admin/events'); ?>" class="mt-4 inline-block text-sm text-gray-500 hover:underline">
      View all events &rarr;
    </a>
  </div>

  <div class="bg-white rounded-lg shadow p-5">
    <h2 class="text-sm font-semibold text-gray-700 mb-4">Recent activity</h2>
    <ul class="space-y-2 text-sm">
      <?php if (empty($recentAudit)): ?>
        <li class="text-gray-400">No activity yet.</li>
      <?php endif; ?>
      <?php foreach ($recentAudit as $a): ?>
        <li class="flex items-center justify-between gap-4">
          <span class="text-gray-600 truncate">
            <?php echo htmlspecialchars($a['action']); ?>
            <?php if ($a['bucket']): ?>
              <span class="text-gray-400">&middot; <?php echo htmlspecialchars($a['bucket']); ?></span>
            <?php endif; ?>
          </span>
          <span class="text-gray-400 text-xs shrink-0"><?php echo htmlspecialchars($a['created_at']); ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
</div>
