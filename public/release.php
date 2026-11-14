<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

$user = require_login();

$errorMessage = '';
$entriesByDay = [];
$week = 0;

try {
    $client = SupabaseClient::fromEnv();
    $week = current_week_number($client);

    $rows = $client->get('timetable_entries', [
        'select' => 'id,day,subject,book,week_number,'
            . 'classes(name),'
            . 'period_slots(period_label,sort_order,start_time_weekday,end_time_weekday,start_time_saturday,end_time_saturday)',
        'teacher_id' => 'eq.' . $user['id'],
        'week_number' => 'eq.' . $week,
        'order' => 'day.asc',
    ]);

    $entryIds = array_map(static fn(array $r): string => (string) $r['id'], $rows);

    // Find any currently-active (pending/open) release request for these entries,
    // so we know whether to show a "Release" button or a status badge.
    $activeByEntry = [];
    if ($entryIds !== []) {
        $idList = implode(',', array_map(static fn(string $id): string => '"' . $id . '"', $entryIds));
        $releaseRows = $client->get('release_requests', [
            'select' => 'id,entry_id,status,reason',
            'entry_id' => 'in.(' . $idList . ')',
            'status' => 'in.(pending,open)',
        ]);
        foreach ($releaseRows as $rr) {
            $activeByEntry[(string) $rr['entry_id']] = $rr;
        }
    }

    foreach (week_days() as $day) {
        $entriesByDay[$day] = [];
    }

    foreach ($rows as $row) {
        $day = (string) ($row['day'] ?? '');
        if (!isset($entriesByDay[$day])) {
            continue;
        }
        $row['_active_release'] = $activeByEntry[(string) $row['id']] ?? null;
        $entriesByDay[$day][] = $row;
    }

    foreach ($entriesByDay as $day => $dayRows) {
        usort($dayRows, static function (array $a, array $b): int {
            return (int) ($a['period_slots']['sort_order'] ?? 0) <=> (int) ($b['period_slots']['sort_order'] ?? 0);
        });
        $entriesByDay[$day] = $dayRows;
    }
} catch (SupabaseException $e) {
    $errorMessage = 'Could not load your periods right now: ' . $e->getMessage();
}

ob_start();
?>
<h1>Release Period<?= $week > 0 ? ' — Week ' . h((string) $week) : '' ?></h1>
<p class="muted">Tap a period below to send it to admin for approval to release.</p>

<?php if ($errorMessage !== ''): ?>
  <p class="alert alert--error"><?= h($errorMessage) ?></p>
<?php else: ?>

  <?php $anyEntries = false; foreach ($entriesByDay as $dayRows) { if ($dayRows !== []) { $anyEntries = true; break; } } ?>
  <?php if (!$anyEntries): ?>
    <p class="muted">You have no periods scheduled this week.</p>
  <?php endif; ?>

  <?php foreach (week_days() as $day): ?>
    <?php if (empty($entriesByDay[$day])) continue; ?>
    <h2 class="day-heading"><?= h(day_label($day)) ?></h2>
    <ul class="period-list">
      <?php foreach ($entriesByDay[$day] as $row): ?>
        <?php
          $slot = $row['period_slots'] ?? [];
          $isSaturday = $day === 'sat';
          $start = $isSaturday ? ($slot['start_time_saturday'] ?? $slot['start_time_weekday'] ?? null) : ($slot['start_time_weekday'] ?? null);
          $end = $isSaturday ? ($slot['end_time_saturday'] ?? $slot['end_time_weekday'] ?? null) : ($slot['end_time_weekday'] ?? null);
          $className = $row['classes']['name'] ?? '';
          $active = $row['_active_release'];
        ?>
        <li class="period-card">
          <div class="period-card__info">
            <strong><?= h($slot['period_label'] ?? '') ?></strong>
            <span><?= h(short_time($start)) ?>&ndash;<?= h(short_time($end)) ?></span>
            <span><?= h($className) ?></span>
            <span><?= h($row['subject'] ?? '') ?><?= !empty($row['book']) ? ' / ' . h($row['book']) : '' ?></span>
          </div>
          <?php if ($active !== null): ?>
            <span class="badge badge--<?= h((string) $active['status']) ?>"><?= h(ucfirst((string) $active['status'])) ?></span>
          <?php else: ?>
            <button type="button"
              class="btn btn--small js-open-release-modal"
              data-entry-id="<?= h((string) $row['id']) ?>"
              data-day="<?= h(day_label($day)) ?>"
              data-period="<?= h($slot['period_label'] ?? '') ?>"
              data-class="<?= h($className) ?>"
              data-subject="<?= h($row['subject'] ?? '') ?>">
              Release
            </button>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endforeach; ?>

  <dialog id="release-modal" class="modal">
    <form method="post" id="release-form" class="form">
      <h2>Release this period?</h2>
      <p class="modal__summary" id="release-modal-summary"></p>
      <input type="hidden" name="entry_id" id="release-entry-id" value="">
      <label for="release-reason">Reason</label>
      <textarea id="release-reason" name="reason" rows="3" required>Nisaab tamam</textarea>
      <div class="modal__actions">
        <button type="button" class="btn btn--ghost" id="release-modal-cancel">Cancel</button>
        <button type="submit" class="btn btn--primary">Send</button>
      </div>
      <p class="form__status" id="release-form-status" role="status"></p>
    </form>
  </dialog>

<?php endif; ?>
<?php
$content = ob_get_clean();
render('Release Period', 'release', $user, $content);
