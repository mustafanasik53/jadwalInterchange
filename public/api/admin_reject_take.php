<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

$admin = require_admin();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: /admin/index.php');
    exit;
}

require_csrf();

$takeRequestId = trim((string) ($_POST['take_request_id'] ?? ''));
if ($takeRequestId === '') {
    header('Location: /admin/index.php?error=' . rawurlencode('Missing take_request_id.'));
    exit;
}

try {
    $client = SupabaseClient::fromEnv();

    $existing = $client->getOne('take_requests', [
        'select' => 'id,status',
        'id' => 'eq.' . $takeRequestId,
    ]);

    if ($existing === null || $existing['status'] !== 'pending') {
        header('Location: /admin/index.php?error=' . rawurlencode('This take request is no longer pending.'));
        exit;
    }

    $client->patch('take_requests', ['id' => 'eq.' . $takeRequestId], [
        'status' => 'rejected',
        'decided_by' => $admin['id'],
        'decided_at' => gmdate('c'),
    ]);

    if (is_json_request()) {
        json_response(['ok' => true]);
    }
    header('Location: /admin/index.php?notice=' . rawurlencode('Take request rejected.'));
    exit;
} catch (SupabaseException $e) {
    if (is_json_request()) {
        json_response(['ok' => false, 'error' => $e->getMessage()], 502);
    }
    header('Location: /admin/index.php?error=' . rawurlencode('Could not reject: ' . $e->getMessage()));
    exit;
}
