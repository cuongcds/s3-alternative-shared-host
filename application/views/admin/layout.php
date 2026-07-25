<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Open-S3 Admin</title>
<link rel="stylesheet" href="<?php echo base_url('assets/admin.css'); ?>">
</head>
<body class="h-full bg-gray-50 text-gray-900">
<div class="flex h-full">
  <aside id="admin-sidebar" class="flex flex-col bg-gray-900 text-gray-100 shrink-0 transition-all duration-200">
    <div class="flex items-center justify-between px-4 h-14 border-b border-gray-800">
      <span class="sidebar-label font-semibold text-sm tracking-wide whitespace-nowrap overflow-hidden">Open-S3 Admin</span>
      <button id="sidebar-toggle" type="button" class="p-1.5 rounded hover:bg-gray-800 shrink-0" title="Collapse/expand sidebar">
        <i class="material-icons text-lg">menu</i>
      </button>
    </div>
    <nav class="flex-1 py-2 space-y-1">
      <?php
      $navItems = array(
          array('href' => 'admin', 'icon' => 'dashboard', 'label' => 'Dashboard', 'match' => 'admin'),
          array('href' => 'admin/buckets', 'icon' => 'inventory_2', 'label' => 'Buckets', 'match' => 'admin/buckets'),
          array('href' => 'admin/events', 'icon' => 'bolt', 'label' => 'Events', 'match' => 'admin/events'),
      );
      ?>
      <?php foreach ($navItems as $item): ?>
        <?php
        $isActive = ($current_uri === $item['match']) || (strpos($current_uri, $item['match'] . '/') === 0);
        $linkClass = $isActive ? 'bg-gray-800 text-white' : 'text-gray-300 hover:bg-gray-800 hover:text-white';
        ?>
        <a href="<?php echo site_url($item['href']); ?>"
           class="flex items-center gap-3 px-4 py-2.5 text-sm <?php echo $linkClass; ?>">
          <i class="material-icons text-xl shrink-0"><?php echo $item['icon']; ?></i>
          <span class="sidebar-label whitespace-nowrap"><?php echo $item['label']; ?></span>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="sidebar-footer border-t border-gray-800 p-4 text-xs text-gray-400">
      <div class="sidebar-email sidebar-label truncate mb-2" title="<?php echo htmlspecialchars($admin_email); ?>">
        <?php echo htmlspecialchars($admin_email); ?>
      </div>
      <form method="post" action="<?php echo site_url('admin/logout'); ?>">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
        <button type="submit" class="flex items-center gap-2 text-gray-300 hover:text-white">
          <i class="material-icons text-lg shrink-0">logout</i>
          <span class="sidebar-label whitespace-nowrap">Logout</span>
        </button>
      </form>
    </div>
  </aside>
  <main class="flex-1 overflow-y-auto">
    <div class="max-w-6xl mx-auto p-6">
      <?php if (!empty($flash_success)): ?>
        <div class="mb-4 rounded border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
          <?php echo htmlspecialchars($flash_success); ?>
        </div>
      <?php endif; ?>
      <?php if (!empty($flash_error)): ?>
        <div class="mb-4 rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
          <?php echo htmlspecialchars($flash_error); ?>
        </div>
      <?php endif; ?>
      <?php echo $content; ?>
    </div>
  </main>
</div>
<script src="<?php echo base_url('assets/admin_sidebar.js'); ?>"></script>
</body>
</html>
