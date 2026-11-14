<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

$user = require_login();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    if (is_json_request()) {
        json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
    }
    header('Location: /take.php');
    exit;
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

$releaseRequestIds = $input['release_request_ids'] ?? [];
if (!is_array($releaseRequestIds)) {
    $releaseRequestIds = [$releaseRequestIds];
}
$releaseRequestIds = array_values(array_unique(array_filter(array_map('strval', $releaseRequestIds), static fn(string $v): bool => $v !== '')));

$created = 0;
$skipped = [];

if ($releaseRequestIds !== []) {
    try {
        $client = SupabaseClient::fromEnv();

        foreach ($releaseRequestIds as $releaseRequestId) {
            // Authorization / sanity check: re-verify this is still an OPEN
            // release and not the current teacher's own period, server-side,
            // regardless of what the client submitted.
            $rr = $client->getOne('release_requests', [
                'select' => 'id,teacher_id,status',
                'id' => 'eq.' . $releaseRequestId,
            ]);

            if ($rr === null || $rr['status'] !== 'open' || (string) $rr['teacher_id'] === $user['id']) {
                $skipped[] = $releaseRequestId;
                continue;
            }

            try {
                $client->post('take_requests', [
                    'release_request_id' => $releaseRequestId,
                    'requesting_teacher_id' => $user['id'],
                    'status' => 'pending',
                ]);
                $created++;
            } catch (SupabaseException $e) {
                // Likely a duplicate pending take_request from this teacher already.
                $skipped[] = $releaseRequestId;
            }

            unset($_SESSION['take_cart'][$releaseRequestId]);
        }
    } catch (SupabaseException $e) {
        if (is_json_request()) {
            json_response(['ok' => false, 'error' => $e->getMessage()], 502);
        }
        header('Location: /take.php?error=' . rawurlencode('Could not submit requests: ' . $e->getMessage()));
        exit;
    }
}

if (is_json_request()) {
    json_response(['ok' => true, 'created' => $created, 'skipped' => $skipped]);
}

header('Location: /take.php?notice=' . rawurlencode($created . ' request(s) sent for approval.'));
exit;
