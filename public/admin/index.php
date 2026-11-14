<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

$user = require_admin();

$errorMessage = '';
$pendingReleases = [];
$pendingTakes = [];

try {
    $client = SupabaseClient::fromEnv();

    $pendingReleases = $client->get('release_requests', [
        'select' => 'id,reason,created_at,teacher_id,'
            . 'profiles!teacher_id(full_name,email),'
            . 'timetable_entries(day,subject,book,week_number,'
            . 'classes(name),'
            . 'period_slots(period_label,sort_order))',
        'status' => 'eq.pending',
        'order' => 'created_at.asc',
    ]);

    $pendingTakes = $client->get('take_requests', [
        'select' => 'id,created_at,requesting_teacher_id,'
            . 'profiles!requesting_teacher_id(full_name,email),'
            . 'release_requests(id,reason,teacher_id,'
            . 'timetable_entries(day,subject,week_number,'
            . 'classes(name),'
            . 'period_slots(period_label,sort_order)))',
        'status' => 'eq.pending',
        'order' => 'created_at.asc',
    ]);
} catch (SupabaseException $e) {
    $errorMessage = 'Could not load the admin queues right now: ' . $e->getMessage();
}

ob_start();
?>
<h1>Admin — Requests Queue</h1>

<?php if (!empty($_GET['notice'])): ?>
  <p class="alert alert--success"><?= h((string) $_GET['notice']) ?></p>
<?php endif; ?>
<?php if (!empty($_GET['error'])): ?>
  <p class="alert alert--error"><?= h((string) $_GET['error']) ?></p>
<?php endif; ?>

<?php if ($errorMessage !== ''): ?>
  <p class="alert alert--error"><?= h($errorMessage) ?></p>
<?php else: ?>

  <h2 class="day-heading">Pending release requests</h2>
  <?php if ($pendingReleases === []): ?>
    <p class="muted">Nothing pending.</p>
  <?php else: ?>
    <ul class="period-list">
      <?php foreach ($pendingReleases as $row): ?>
        <?php
          $entry = $row['timetable_entries'] ?? [];
          $slot = $entry['period_slots'] ?? [];
          $teacher = $row['profiles'] ?? [];
        ?>
        <li class="period-card period-card--stacked">
          <div class="period-card__info">
            <strong><?= h($teacher['full_name'] ?? 'Unknown teacher') ?></strong>
            <span><?= h(day_label((string) ($entry['day'] ?? ''))) ?> &middot; <?= h($slot['period_label'] ?? '') ?></span>
            <span><?= h($entry['classes']['name'] ?? '') ?> &middot; <?= h($entry['subject'] ?? '') ?></span>
            <span class="muted">Reason: <?= h($row['reason'] ?? '') ?></span>
          </div>
          <div class="period-card__actions">
            <form method="post" action="/api/admin_approve_release.php">
              <?= csrf_field() ?>
              <input type="hidden" name="release_request_id" value="<?= h((string) $row['id']) ?>">
              <button type="submit" class="btn btn--small btn--primary">Approve</button>
            </form>
            <form method="post" action="/api/admin_reject_release.php">
              <?= csrf_field() ?>
              <input type="hidden" name="release_request_id" value="<?= h((string) $row['id']) ?>">
              <button type="submit" class="btn btn--small btn--ghost">Reject</button>
            </form>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <h2 class="day-heading">Pending take requests</h2>
  <?php if ($pendingTakes === []): ?>
    <p class="muted">Nothing pending.</p>
  <?php else: ?>
    <ul class="period-list">
      <?php foreach ($pendingTakes as $row): ?>
        <?php
          $release = $row['release_requests'] ?? [];
          $entry = $release['timetable_entries'] ?? [];
          $slot = $entry['period_slots'] ?? [];
          $requestingTeacher = $row['profiles'] ?? [];
        ?>
        <li class="period-card period-card--stacked">
          <div class="period-card__info">
            <strong><?= h($requestingTeacher['full_name'] ?? 'Unknown teacher') ?></strong> wants to take:
            <span><?= h(day_label((string) ($entry['day'] ?? ''))) ?> &middot; <?= h($slot['period_label'] ?? '') ?></span>
            <span><?= h($entry['classes']['name'] ?? '') ?> &middot; <?= h($entry['subject'] ?? '') ?></span>
          </div>
          <div class="period-card__actions">
            <form method="post" action="/api/admin_approve_take.php">
              <?= csrf_field() ?>
              <input type="hidden" name="take_request_id" value="<?= h((string) $row['id']) ?>">
              <button type="submit" class="btn btn--small btn--primary">Approve</button>
            </form>
            <form method="post" action="/api/admin_reject_take.php">
              <?= csrf_field() ?>
              <input type="hidden" name="take_request_id" value="<?= h((string) $row['id']) ?>">
              <button type="submit" class="btn btn--small btn--ghost">Reject</button>
            </form>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

<?php endif; ?>
<?php
$content = ob_get_clean();
render('Admin', 'admin', $user, $content);
