<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<div class="flex items-center justify-between mb-6">
  <h1 class="text-xl font-semibold text-gray-800">Buckets</h1>
  <a href="<?php echo site_url('admin/buckets/new'); ?>"
     class="inline-flex items-center gap-2 rounded bg-gray-900 text-white text-sm font-medium px-4 py-2 hover:bg-gray-800">
    <i class="material-icons text-lg">add</i>
    New bucket
  </a>
</div>

<div class="bg-white rounded-lg shadow overflow-hidden">
  <table class="w-full text-sm">
    <thead class="bg-gray-50 text-gray-500 text-left">
      <tr>
        <th class="px-4 py-3 font-medium">Name</th>
        <th class="px-4 py-3 font-medium">Objects</th>
        <th class="px-4 py-3 font-medium">Size</th>
        <th class="px-4 py-3 font-medium">Public</th>
        <th class="px-4 py-3 font-medium">Versioning</th>
        <th class="px-4 py-3 font-medium">Created</th>
        <th class="px-4 py-3 font-medium text-right">Actions</th>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-100">
      <?php if (empty($buckets)): ?>
        <tr>
          <td colspan="7" class="px-4 py-8 text-center text-gray-400">No buckets yet.</td>
        </tr>
      <?php endif; ?>
      <?php foreach ($buckets as $b): ?>
        <tr class="hover:bg-gray-50">
          <td class="px-4 py-3 font-medium text-gray-800">
            <a href="<?php echo site_url('admin/buckets/' . $b['name'] . '/objects'); ?>" class="hover:underline">
              <?php echo htmlspecialchars($b['name']); ?>
            </a>
          </td>
          <td class="px-4 py-3 text-gray-600"><?php echo (int) $b['object_count']; ?></td>
          <td class="px-4 py-3 text-gray-600"><?php echo s3_format_bytes($b['total_size']); ?></td>
          <td class="px-4 py-3">
            <?php if ((int) $b['is_public']): ?>
              <span class="inline-block rounded-full bg-amber-100 text-amber-800 text-xs px-2 py-0.5">public</span>
            <?php else: ?>
              <span class="inline-block rounded-full bg-gray-100 text-gray-600 text-xs px-2 py-0.5">private</span>
            <?php endif; ?>
          </td>
          <td class="px-4 py-3 text-gray-600"><?php echo ((int) $b['versioning_enabled']) ? 'On' : 'Off'; ?></td>
          <td class="px-4 py-3 text-gray-500"><?php echo htmlspecialchars($b['created_at']); ?></td>
          <td class="px-4 py-3 text-right space-x-3 whitespace-nowrap">
            <a href="<?php echo site_url('admin/buckets/' . $b['name'] . '/objects'); ?>"
               class="text-gray-500 hover:text-gray-900" title="Browse objects">
              <i class="material-icons text-lg align-middle">folder_open</i>
            </a>
            <a href="<?php echo site_url('admin/buckets/' . $b['name'] . '/edit'); ?>"
               class="text-gray-500 hover:text-gray-900" title="Edit config">
              <i class="material-icons text-lg align-middle">settings</i>
            </a>
            <form method="post" action="<?php echo site_url('admin/buckets/' . $b['name'] . '/delete'); ?>"
                  class="inline" onsubmit="return confirm('Delete bucket &quot;<?php echo htmlspecialchars($b['name']); ?>&quot;? It must be empty.');">
              <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
              <button type="submit" class="text-red-500 hover:text-red-700" title="Delete bucket">
                <i class="material-icons text-lg align-middle">delete</i>
              </button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
