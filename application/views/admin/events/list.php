<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<?php
$statusBadge = array(
    'pending' => 'bg-gray-100 text-gray-700',
    'processing' => 'bg-blue-100 text-blue-800',
    'done' => 'bg-green-100 text-green-800',
    'failed' => 'bg-red-100 text-red-800',
);
$statuses = array('pending', 'processing', 'done', 'failed');
?>
<h1 class="text-xl font-semibold text-gray-800 mb-6">Events</h1>

<div class="flex items-center gap-2 mb-6">
  <a href="<?php echo site_url('admin/events'); ?>"
     class="rounded-full px-3 py-1 text-xs font-medium <?php echo $status === '' ? 'bg-gray-900 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'; ?>">
    All
  </a>
  <?php foreach ($statuses as $s): ?>
    <a href="<?php echo site_url('admin/events') . '?status=' . $s; ?>"
       class="rounded-full px-3 py-1 text-xs font-medium <?php echo $status === $s ? 'bg-gray-900 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'; ?>">
      <?php echo ucfirst($s); ?> (<?php echo (int) $counts[$s]; ?>)
    </a>
  <?php endforeach; ?>
</div>

<div class="bg-white rounded-lg shadow overflow-hidden">
  <table class="w-full text-sm">
    <thead class="bg-gray-50 text-gray-500 text-left">
      <tr>
        <th class="px-4 py-3 font-medium">Bucket / Key</th>
        <th class="px-4 py-3 font-medium">Event</th>
        <th class="px-4 py-3 font-medium">Status</th>
        <th class="px-4 py-3 font-medium">Attempts</th>
        <th class="px-4 py-3 font-medium">Last error</th>
        <th class="px-4 py-3 font-medium">Created</th>
        <th class="px-4 py-3 font-medium text-right">Actions</th>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-100">
      <?php if (empty($events)): ?>
        <tr>
          <td colspan="7" class="px-4 py-8 text-center text-gray-400">No events.</td>
        </tr>
      <?php endif; ?>
      <?php foreach ($events as $e): ?>
        <tr class="hover:bg-gray-50">
          <td class="px-4 py-3">
            <div class="font-medium text-gray-800"><?php echo htmlspecialchars($e['bucket_name']); ?></div>
            <div class="text-gray-400 text-xs break-all"><?php echo htmlspecialchars($e['object_key']); ?></div>
          </td>
          <td class="px-4 py-3 text-gray-600"><?php echo htmlspecialchars($e['event_type']); ?></td>
          <td class="px-4 py-3">
            <span class="inline-block rounded-full text-xs px-2 py-0.5 <?php echo $statusBadge[$e['status']] ?? 'bg-gray-100 text-gray-700'; ?>">
              <?php echo htmlspecialchars($e['status']); ?>
            </span>
          </td>
          <td class="px-4 py-3 text-gray-600"><?php echo (int) $e['attempts']; ?></td>
          <td class="px-4 py-3 text-gray-400 text-xs max-w-xs truncate" title="<?php echo htmlspecialchars((string) $e['last_error']); ?>">
            <?php echo htmlspecialchars((string) $e['last_error']); ?>
          </td>
          <td class="px-4 py-3 text-gray-500"><?php echo htmlspecialchars($e['created_at']); ?></td>
          <td class="px-4 py-3 text-right">
            <?php if ($e['status'] === 'failed'): ?>
              <form method="post" action="<?php echo site_url('admin/events/' . (int) $e['id'] . '/redispatch'); ?>" class="inline">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <button type="submit" class="text-gray-500 hover:text-gray-900" title="Redispatch">
                  <i class="material-icons text-lg align-middle">replay</i>
                </button>
              </form>
            <?php else: ?>
              <span class="text-gray-300">—</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
