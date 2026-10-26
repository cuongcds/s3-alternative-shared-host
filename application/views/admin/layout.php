<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Open S3 Admin</title>
<link rel="icon" href="<?php echo base_url('assets/brand/favicon.ico'); ?>" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="<?php echo base_url('assets/brand/favicon-32x32.png'); ?>">
<link rel="icon" type="image/png" sizes="16x16" href="<?php echo base_url('assets/brand/favicon-16x16.png'); ?>">
<link rel="apple-touch-icon" href="<?php echo base_url('assets/brand/apple-touch-icon.png'); ?>">
<link rel="manifest" href="<?php echo base_url('assets/brand/site.webmanifest'); ?>">
<meta name="theme-color" content="#1a273d">
<link rel="stylesheet" href="<?php echo base_url('assets/brand/theme-variables.css'); ?>">
<link rel="stylesheet" href="<?php echo base_url('assets/admin.css'); ?>">
</head>
<body class="h-full bg-brand-bg text-brand-text">
<div class="flex h-full">
  <aside id="admin-sidebar" class="flex flex-col bg-secondary text-gray-100 shrink-0 transition-all duration-200">
    <div class="flex items-center justify-between px-4 h-14 bg-white border-b border-brand-border">
      <a href="<?php echo site_url('admin'); ?>" class="sidebar-label overflow-hidden" title="Open S3">
        <img src="<?php echo base_url('assets/brand/logo.png'); ?>" alt="Open S3" class="h-8 w-auto">
      </a>
      <button id="sidebar-toggle" type="button" class="p-1.5 rounded text-secondary hover:bg-brand-muted shrink-0" title="Collapse/expand sidebar">
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
        $linkClass = $isActive ? 'bg-white/10 text-white border-l-4 border-primary' : 'text-gray-300 hover:bg-white/10 hover:text-white';
        ?>
        <a href="<?php echo site_url($item['href']); ?>"
           class="flex items-center gap-3 px-4 py-2.5 text-sm <?php echo $linkClass; ?>">
          <i class="material-icons text-xl shrink-0"><?php echo $item['icon']; ?></i>
          <span class="sidebar-label whitespace-nowrap"><?php echo $item['label']; ?></span>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="sidebar-footer border-t border-white/10 p-4 text-xs text-gray-400">
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
