<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

$admin = require_admin();

$takeRequestId = trim((string) ($_GET['take_request_id'] ?? ''));
$errorMessage = '';
$subjectPrefill = '';
$bodyPrefill = '';
$recipientEmail = '';
$recipientName = '';

if ($takeRequestId === '') {
    $errorMessage = 'Missing take_request_id.';
} else {
    try {
        $client = SupabaseClient::fromEnv();

        $takeRequest = $client->getOne('take_requests', [
            'select' => 'id,status,requesting_teacher_id,'
                . 'profiles!requesting_teacher_id(full_name,email),'
                . 'release_requests(entry_id,'
                . 'timetable_entries(day,subject,week_number,'
                . 'classes(name),'
                . 'period_slots(period_label)))',
            'id' => 'eq.' . $takeRequestId,
        ]);

        if ($takeRequest === null) {
            $errorMessage = 'Take request not found.';
        } elseif ($takeRequest['status'] !== 'approved') {
            $errorMessage = 'This take request has not been approved yet.';
        } else {
            $teacher = $takeRequest['profiles'] ?? [];
            $entry = $takeRequest['release_requests']['timetable_entries'] ?? [];
            $slot = $entry['period_slots'] ?? [];

            $recipientEmail = (string) ($teacher['email'] ?? '');
            $recipientName = (string) ($teacher['full_name'] ?? $recipientEmail);

            $template = build_approval_email_template(
                $recipientName,
                (string) ($entry['classes']['name'] ?? ''),
                day_label((string) ($entry['day'] ?? '')),
                (string) ($slot['period_label'] ?? ''),
                $entry['subject'] ?? null
            );

            $subjectPrefill = $template['subject'];
            $bodyPrefill = $template['body'];
        }
    } catch (SupabaseException $e) {
        $errorMessage = 'Could not load this take request: ' . $e->getMessage();
    }
}

ob_start();
?>
<h1>Compose approval email</h1>

<?php if ($errorMessage !== ''): ?>
  <p class="alert alert--error"><?= h($errorMessage) ?></p>
  <p><a href="/admin/index.php">Back to requests queue</a></p>
<?php else: ?>

  <?php if (!empty($_GET['notice'])): ?>
    <p class="alert alert--success"><?= h((string) $_GET['notice']) ?></p>
  <?php endif; ?>

  <p class="muted">To: <strong><?= h($recipientName) ?></strong> &lt;<?= h($recipientEmail) ?>&gt;</p>

  <form method="post" action="/api/admin_send_email.php" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="take_request_id" value="<?= h($takeRequestId) ?>">

    <label for="subject">Subject</label>
    <input type="text" id="subject" name="subject" value="<?= h($subjectPrefill) ?>" required>

    <label for="body">Message</label>
    <textarea id="body" name="body" rows="10" required><?= h($bodyPrefill) ?></textarea>

    <button type="submit" class="btn btn--primary btn--block">Send</button>
  </form>

<?php endif; ?>
<?php
$content = ob_get_clean();
render('Compose email', 'admin', $admin, $content);
