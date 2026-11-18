<?php
/** Included directly by require_role() on access-denied. */
if (!function_exists('e')) { require_once __DIR__ . '/includes/bootstrap.php'; }
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Access denied · <?= e(APP_NAME) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(app_url('/assets/css/style.css')) ?>?v=6">
</head>
<body>
<div class="auth-shell">
  <div class="card elevated auth-card">
    <div class="icon-tile red" style="margin:0 auto 16px;width:56px;height:56px;">
      <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="4.9" y1="4.9" x2="19.1" y2="19.1"/></svg>
    </div>
    <h1>Access denied</h1>
    <p class="lead">You don't have permission to view this page.</p>
    <a class="btn btn-primary btn-block" href="<?= e(app_url(home_path_for(current_user()))) ?>">Back to my home screen</a>
  </div>
</div>
</body>
</html>
