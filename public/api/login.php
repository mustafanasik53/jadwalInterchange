<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: /login.php');
    exit;
}

require_csrf();

$email = trim((string) ($_POST['email'] ?? ''));
$password = (string) ($_POST['password'] ?? '');

if ($email === '' || $password === '') {
    header('Location: /login.php?error=' . rawurlencode('Please enter your email and password.'));
    exit;
}

try {
    $client = SupabaseClient::fromEnv();
    login_with_password($client, $email, $password);
} catch (SupabaseException $e) {
    header('Location: /login.php?error=' . rawurlencode('Could not sign in: ' . $e->getMessage()));
    exit;
} catch (Throwable $e) {
    // Never leak internals to the browser.
    header('Location: /login.php?error=' . rawurlencode('An unexpected error occurred. Please try again.'));
    exit;
}

header('Location: /dashboard.php');
exit;
