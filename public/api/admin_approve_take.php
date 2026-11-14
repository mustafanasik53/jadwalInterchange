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

    $takeRequest = $client->getOne('take_requests', [
        'select' => 'id,status,release_request_id,requesting_teacher_id',
        'id' => 'eq.' . $takeRequestId,
    ]);

    if ($takeRequest === null || $takeRequest['status'] !== 'pending') {
        header('Location: /admin/index.php?error=' . rawurlencode('This take request is no longer pending.'));
        exit;
    }

    $releaseRequestId = (string) $takeRequest['release_request_id'];
    $requestingTeacherId = (string) $takeRequest['requesting_teacher_id'];

    $releaseRequest = $client->getOne('release_requests', [
        'select' => 'id,status,entry_id',
        'id' => 'eq.' . $releaseRequestId,
    ]);

    if ($releaseRequest === null || $releaseRequest['status'] !== 'open') {
        header('Location: /admin/index.php?error=' . rawurlencode('The underlying release request is no longer open.'));
        exit;
    }

    $entryId = (string) $releaseRequest['entry_id'];
    $now = gmdate('c');

    // 1) Approve this take request.
    $client->patch('take_requests', ['id' => 'eq.' . $takeRequestId], [
        'status' => 'approved',
        'decided_by' => $admin['id'],
        'decided_at' => $now,
    ]);

    // 2) Close the release request (it has now been fulfilled).
    $client->patch('release_requests', ['id' => 'eq.' . $releaseRequestId], [
        'status' => 'closed',
        'decided_by' => $admin['id'],
        'decided_at' => $now,
    ]);

    // 3) The actual handover: reassign the timetable entry to the requesting teacher.
    $client->patch('timetable_entries', ['id' => 'eq.' . $entryId], [
        'teacher_id' => $requestingTeacherId,
    ]);

    // 4) Any other still-pending take_requests for the same release are now
    //    moot (the period has already been handed over) — auto-reject them.
    $client->patch('take_requests', [
        'release_request_id' => 'eq.' . $releaseRequestId,
        'status' => 'eq.pending',
        'id' => 'neq.' . $takeRequestId,
    ], [
        'status' => 'rejected',
        'decided_by' => $admin['id'],
        'decided_at' => $now,
    ]);

    if (is_json_request()) {
        json_response(['ok' => true, 'take_request_id' => $takeRequestId]);
    }
    header('Location: /admin/compose_email.php?take_request_id=' . rawurlencode($takeRequestId));
    exit;
} catch (SupabaseException $e) {
    if (is_json_request()) {
        json_response(['ok' => false, 'error' => $e->getMessage()], 502);
    }
    header('Location: /admin/index.php?error=' . rawurlencode('Could not approve take request: ' . $e->getMessage()));
    exit;
}
