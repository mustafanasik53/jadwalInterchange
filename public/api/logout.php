<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

// logout.php (public) already handles the session-destroy + redirect flow.
// This API endpoint exists for symmetry / fetch()-based logout buttons.
logout();

if (is_json_request()) {
    json_response(['ok' => true]);
}

header('Location: /login.php');
exit;
