<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

if (current_user() !== null) {
    header('Location: /dashboard.php');
    exit;
}

$error = '';
if (isset($_GET['error'])) {
    $error = (string) $_GET['error'];
}

ob_start();
?>
<div class="auth-card">
  <h1>Sign in</h1>
  <?php if ($error !== ''): ?>
    <p class="alert alert--error"><?= h($error) ?></p>
  <?php endif; ?>
  <form method="post" action="/api/login.php" class="form">
    <?= csrf_field() ?>
    <label for="email">Email</label>
    <input type="email" id="email" name="email" autocomplete="username" required>

    <label for="password">Password</label>
    <input type="password" id="password" name="password" autocomplete="current-password" required>

    <button type="submit" class="btn btn--primary btn--block">Sign in</button>
  </form>
</div>
<?php
$content = ob_get_clean();
render('Sign in', '', null, $content);
