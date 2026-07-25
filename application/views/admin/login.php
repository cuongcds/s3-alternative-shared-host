<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Open-S3 Admin &mdash; Login</title>
<link rel="stylesheet" href="<?php echo base_url('assets/admin.css'); ?>">
</head>
<body class="h-full bg-gray-100 flex items-center justify-center">
  <div class="w-full max-w-sm bg-white rounded-lg shadow p-8">
    <div class="flex items-center gap-2 mb-6">
      <i class="material-icons text-2xl text-gray-700">lock</i>
      <h1 class="text-lg font-semibold text-gray-800">Open-S3 Admin</h1>
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
               class="w-full rounded border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-gray-800">
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1" for="password">Password</label>
        <input id="password" name="password" type="password" required
               class="w-full rounded border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-gray-800">
      </div>
      <button type="submit"
              class="w-full rounded bg-gray-900 text-white text-sm font-medium py-2 hover:bg-gray-800">
        Sign in
      </button>
    </form>
  </div>
</body>
</html>
