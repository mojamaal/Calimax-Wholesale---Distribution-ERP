<?php
require_once __DIR__ . '/includes/bootstrap.php';

if (is_logged_in()) {
    redirect(home_path_for(current_user()));
}

$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    verify_csrf();

    $username = input($_POST, 'username');
    $password = (string) ($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Please enter both username and password.';
    } else {
        $result = attempt_login($username, $password);
        if (is_array($result)) {
            $default = home_path_for($result);
            // $_GET['redirect'] comes from require_login(), which captures the raw
            // REQUEST_URI — that's already a full site-absolute path (it includes
            // the app's base folder), unlike $default which is app-root-relative.
            // Redirecting it through app_url() would prepend the base folder twice.
            $redirectParam = $_GET['redirect'] ?? null;
            if (is_string($redirectParam) && str_starts_with($redirectParam, '/') && !str_starts_with($redirectParam, '//')) {
                header('Location: ' . $redirectParam);
                exit;
            }
            redirect($default);
        } else {
            $error = $result;
        }
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign in · <?= e(APP_NAME) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(app_url('/assets/css/style.css')) ?>?v=6">
</head>
<body>
<div class="auth-shell">
  <div class="card elevated auth-card">
    <div class="brutal-logo">
      <span class="mark">CX</span>
      <span class="word">Calimax ERP</span>
    </div>
    <div class="brand-sub" style="margin:-6px 0 14px;">Trading, Wholesale &amp; Distribution
    </div>
    <h1>Welcome back</h1>
    <p class="lead">Sign in to <?= e(setting('company_name', APP_NAME)) ?></p>

    <?php if ($error !== ''): ?>
      <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <?php $redirectParam = $_GET['redirect'] ?? null; ?>
    <form method="post" action="<?= e(app_url('/login.php' . (is_string($redirectParam) ? '?redirect=' . urlencode($redirectParam) : ''))) ?>" autocomplete="off">
      <?= csrf_field() ?>
      <div class="form-group">
        <label for="username">Username</label>
        <input class="form-control" type="text" id="username" name="username" required autofocus maxlength="50" value="<?= e($_POST['username'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label for="password">Password</label>
        <input class="form-control" type="password" id="password" name="password" required maxlength="255">
      </div>
      <button type="submit" class="btn btn-primary btn-block">Sign In</button>
    </form>
  </div>
</div>
</body>
</html>
