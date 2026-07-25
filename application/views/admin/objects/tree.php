<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<div class="flex items-center gap-3 mb-2">
  <a href="<?php echo site_url('admin/buckets'); ?>" class="text-gray-400 hover:text-gray-700">
    <i class="material-icons align-middle">arrow_back</i>
  </a>
  <h1 class="text-xl font-semibold text-gray-800"><?php echo htmlspecialchars($bucket['name']); ?></h1>
</div>

<nav id="tree-breadcrumb" class="text-sm text-gray-500 mb-4 flex items-center gap-1 flex-wrap"></nav>

<div class="bg-white rounded-lg shadow overflow-hidden">
  <table class="w-full text-sm">
    <thead class="bg-gray-50 text-gray-500 text-left">
      <tr>
        <th class="px-4 py-3 font-medium">Name</th>
        <th class="px-4 py-3 font-medium">Size</th>
        <th class="px-4 py-3 font-medium">Content-Type</th>
        <th class="px-4 py-3 font-medium">Modified</th>
        <th class="px-4 py-3 font-medium text-right">Actions</th>
      </tr>
    </thead>
    <tbody id="tree-body">
      <tr><td colspan="5" class="px-4 py-8 text-center text-gray-400">Loading&hellip;</td></tr>
    </tbody>
  </table>
</div>
<p id="tree-truncated-note" class="mt-3 text-xs text-amber-600 hidden">
  Showing a partial listing under this prefix &mdash; open a folder to narrow it down.
</p>

<div id="tree-config"
     class="hidden"
     data-tree-url="<?php echo site_url('admin/buckets/' . rawurlencode($bucket['name']) . '/objects/tree'); ?>"
     data-preview-url="<?php echo site_url('admin/buckets/' . rawurlencode($bucket['name']) . '/objects/preview'); ?>"
     data-download-url="<?php echo site_url('admin/buckets/' . rawurlencode($bucket['name']) . '/objects/download'); ?>"
></div>

<script src="<?php echo base_url('assets/admin_tree.js'); ?>"></script>
