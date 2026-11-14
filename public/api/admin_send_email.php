<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/Mailer.php';

$admin = require_admin();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: /admin/index.php');
    exit;
}

require_csrf();

$takeRequestId = trim((string) ($_POST['take_request_id'] ?? ''));
$subject = trim((string) ($_POST['subject'] ?? ''));
$body = (string) ($_POST['body'] ?? '');

if ($takeRequestId === '' || $subject === '' || trim($body) === '') {
    header('Location: /admin/compose_email.php?take_request_id=' . rawurlencode($takeRequestId)
        . '&error=' . rawurlencode('Subject and message are required.'));
    exit;
}

try {
    $client = SupabaseClient::fromEnv();

    // Never trust a client-submitted recipient address — re-derive it
    // server-side from the take request's requesting teacher every time.
    $takeRequest = $client->getOne('take_requests', [
        'select' => 'id,status,requesting_teacher_id,profiles!requesting_teacher_id(email,full_name)',
        'id' => 'eq.' . $takeRequestId,
    ]);

    if ($takeRequest === null || $takeRequest['status'] !== 'approved') {
        header('Location: /admin/index.php?error=' . rawurlencode('This take request is not in an approved state.'));
        exit;
    }

    $recipientEmail = (string) ($takeRequest['profiles']['email'] ?? '');
    if ($recipientEmail === '') {
        header('Location: /admin/index.php?error=' . rawurlencode('The requesting teacher has no email on file.'));
        exit;
    }

    $mailer = new Mailer();
    $sent = $mailer->send($recipientEmail, $subject, $body);

    // Log the attempt either way — email_log is a record of what was sent
    // (or attempted), not a guarantee of inbox delivery (see Mailer stub note).
    $client->post('email_log', [
        'take_request_id' => $takeRequestId,
        'recipient_email' => $recipientEmail,
        'subject' => $subject,
        'body' => $body,
        'sent_by' => $admin['id'],
    ]);

    if (is_json_request()) {
        json_response(['ok' => true, 'mail_sent' => $sent]);
    }

    if ($sent) {
        header('Location: /admin/index.php?notice=' . rawurlencode('Email sent to ' . $recipientEmail . '.'));
    } else {
        header('Location: /admin/index.php?notice='
            . rawurlencode('Email logged, but the local mail server could not send it (see Mailer stub limitation).'));
    }
    exit;
} catch (SupabaseException $e) {
    if (is_json_request()) {
        json_response(['ok' => false, 'error' => $e->getMessage()], 502);
    }
    header('Location: /admin/index.php?error=' . rawurlencode('Could not send/log email: ' . $e->getMessage()));
    exit;
}
