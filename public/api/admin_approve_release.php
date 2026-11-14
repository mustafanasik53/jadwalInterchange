<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

$admin = require_admin();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: /admin/index.php');
    exit;
}

require_csrf();

$releaseRequestId = trim((string) ($_POST['release_request_id'] ?? ''));
if ($releaseRequestId === '') {
    header('Location: /admin/index.php?error=' . rawurlencode('Missing release_request_id.'));
    exit;
}

try {
    $client = SupabaseClient::fromEnv();

    $existing = $client->getOne('release_requests', [
        'select' => 'id,status',
        'id' => 'eq.' . $releaseRequestId,
    ]);

    if ($existing === null || $existing['status'] !== 'pending') {
        header('Location: /admin/index.php?error=' . rawurlencode('This release request is no longer pending.'));
        exit;
    }

    $client->patch('release_requests', ['id' => 'eq.' . $releaseRequestId], [
        'status' => 'open',
        'decided_by' => $admin['id'],
        'decided_at' => gmdate('c'),
    ]);

    if (is_json_request()) {
        json_response(['ok' => true]);
    }
    header('Location: /admin/index.php?notice=' . rawurlencode('Release request approved and is now open for teachers to take.'));
    exit;
} catch (SupabaseException $e) {
    if (is_json_request()) {
        json_response(['ok' => false, 'error' => $e->getMessage()], 502);
    }
    header('Location: /admin/index.php?error=' . rawurlencode('Could not approve: ' . $e->getMessage()));
    exit;
}
