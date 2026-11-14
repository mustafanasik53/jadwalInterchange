<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

$user = require_login();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
}

require_csrf();

$input = $_POST;
if ($input === []) {
    $raw = file_get_contents('php://input');
    $decoded = ($raw !== false && $raw !== '') ? json_decode($raw, true) : null;
    if (is_array($decoded)) {
        $input = $decoded;
    }
}

$entryId = trim((string) ($input['entry_id'] ?? ''));
$reason = trim((string) ($input['reason'] ?? ''));
if ($reason === '') {
    $reason = 'Nisaab tamam';
}

if ($entryId === '') {
    json_response(['ok' => false, 'error' => 'Missing entry_id.'], 400);
}

try {
    $client = SupabaseClient::fromEnv();

    // Authorization: a teacher may only release their OWN timetable entry.
    // Never trust the client for ownership — re-check against the DB here.
    $entry = $client->getOne('timetable_entries', [
        'select' => 'id,teacher_id',
        'id' => 'eq.' . $entryId,
    ]);

    if ($entry === null) {
        json_response(['ok' => false, 'error' => 'Timetable entry not found.'], 404);
    }

    if ((string) ($entry['teacher_id'] ?? '') !== $user['id']) {
        json_response(['ok' => false, 'error' => 'You can only release your own periods.'], 403);
    }

    $created = $client->post('release_requests', [
        'entry_id' => $entryId,
        'teacher_id' => $user['id'],
        'reason' => $reason,
        'status' => 'pending',
    ]);

    $row = $created[0] ?? null;
    json_response(['ok' => true, 'release_request' => $row]);
} catch (SupabaseException $e) {
    // Most likely cause: a unique-constraint conflict because this entry
    // already has an active (pending/open) release request.
    json_response(['ok' => false, 'error' => 'Could not submit release request: ' . $e->getMessage()], 409);
}
