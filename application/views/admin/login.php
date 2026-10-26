<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Open S3 Admin &mdash; Login</title>
<link rel="icon" href="<?php echo base_url('assets/brand/favicon.ico'); ?>" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="<?php echo base_url('assets/brand/favicon-32x32.png'); ?>">
<link rel="icon" type="image/png" sizes="16x16" href="<?php echo base_url('assets/brand/favicon-16x16.png'); ?>">
<link rel="apple-touch-icon" href="<?php echo base_url('assets/brand/apple-touch-icon.png'); ?>">
<link rel="manifest" href="<?php echo base_url('assets/brand/site.webmanifest'); ?>">
<meta name="theme-color" content="#1a273d">
<link rel="stylesheet" href="<?php echo base_url('assets/brand/theme-variables.css'); ?>">
<link rel="stylesheet" href="<?php echo base_url('assets/admin.css'); ?>">
</head>
<body class="h-full bg-brand-bg flex items-center justify-center">
  <div class="w-full max-w-sm bg-white rounded-lg shadow p-8">
    <div class="flex flex-col items-center gap-2 mb-6">
      <img src="<?php echo base_url('assets/brand/logo.png'); ?>" alt="Open S3" class="h-10 w-auto">
      <h1 class="text-sm font-medium text-brand-muted-fg">Admin</h1>
    </div>

    <?php if (!empty($flash_error)): ?>
      <div class="mb-4 rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
        <?php echo htmlspecialchars($flash_error); ?>
      </div>
    <?php endif; ?>

    <form method="post" action="<?php echo site_url('admin/login'); ?>" class="space-y-4">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1" for="email">Email</label>
        <input id="email" name="email" type="email" required autofocus
               class="w-full rounded border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary">
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1" for="password">Password</label>
        <input id="password" name="password" type="password" required
               class="w-full rounded border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary">
      </div>
      <button type="submit"
              class="w-full rounded bg-primary text-white text-sm font-medium py-2 hover:opacity-90">
        Sign in
      </button>
    </form>
  </div>
</body>
</html>
