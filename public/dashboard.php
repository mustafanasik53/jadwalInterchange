<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

$user = require_login();

$errorMessage = '';
$entriesByDay = [];

try {
    $client = SupabaseClient::fromEnv();
    $week = current_week_number($client);

    $rows = $client->get('timetable_entries', [
        'select' => 'id,day,subject,book,week_number,entry_date,'
            . 'classes(name),'
            . 'period_slots(period_label,sort_order,start_time_weekday,end_time_weekday,start_time_saturday,end_time_saturday)',
        'teacher_id' => 'eq.' . $user['id'],
        'week_number' => 'eq.' . $week,
    ]);

    foreach (week_days() as $day) {
        $entriesByDay[$day] = [];
    }

    foreach ($rows as $row) {
        $day = (string) ($row['day'] ?? '');
        if (!isset($entriesByDay[$day])) {
            continue;
        }
        $entriesByDay[$day][] = $row;
    }

    foreach ($entriesByDay as $day => $dayRows) {
        usort($dayRows, static function (array $a, array $b): int {
            $sortA = (int) ($a['period_slots']['sort_order'] ?? 0);
            $sortB = (int) ($b['period_slots']['sort_order'] ?? 0);
            return $sortA <=> $sortB;
        });
        $entriesByDay[$day] = $dayRows;
    }
} catch (SupabaseException $e) {
    $errorMessage = 'Could not load your timetable right now: ' . $e->getMessage();
    $week = 0;
}

ob_start();
?>
<h1>My Timetable<?= isset($week) && $week > 0 ? ' — Week ' . h((string) $week) : '' ?></h1>

<?php if ($errorMessage !== ''): ?>
  <p class="alert alert--error"><?= h($errorMessage) ?></p>
<?php else: ?>

  <div class="day-chips" role="tablist" aria-label="Day of week">
    <?php foreach (week_days() as $i => $day): ?>
      <button type="button" class="day-chip<?= $i === 0 ? ' is-active' : '' ?>" data-day-target="<?= h($day) ?>" role="tab" aria-selected="<?= $i === 0 ? 'true' : 'false' ?>">
        <?= h(day_label($day)) ?>
      </button>
    <?php endforeach; ?>
  </div>

  <?php foreach (week_days() as $i => $day): ?>
    <section class="day-panel<?= $i === 0 ? ' is-active' : '' ?>" data-day-panel="<?= h($day) ?>">
      <?php if (empty($entriesByDay[$day])): ?>
        <p class="muted">No periods scheduled for <?= h(day_label($day)) ?>.</p>
      <?php else: ?>
        <table class="timetable-table">
          <thead>
            <tr>
              <th>Time</th>
              <th>Period</th>
              <th>Class</th>
              <th>Subject</th>
              <th>Book</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($entriesByDay[$day] as $row): ?>
              <?php
                $slot = $row['period_slots'] ?? [];
                $isSaturday = $day === 'sat';
                $start = $isSaturday ? ($slot['start_time_saturday'] ?? $slot['start_time_weekday'] ?? null) : ($slot['start_time_weekday'] ?? null);
                $end = $isSaturday ? ($slot['end_time_saturday'] ?? $slot['end_time_weekday'] ?? null) : ($slot['end_time_weekday'] ?? null);
                $className = $row['classes']['name'] ?? '';
              ?>
              <tr>
                <td data-label="Time"><?= h(short_time($start)) ?>&ndash;<?= h(short_time($end)) ?></td>
                <td data-label="Period"><?= h($slot['period_label'] ?? '') ?></td>
                <td data-label="Class"><?= h($className) ?></td>
                <td data-label="Subject"><?= h($row['subject'] ?? '') ?></td>
                <td data-label="Book"><?= h($row['book'] ?? '') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </section>
  <?php endforeach; ?>

<?php endif; ?>
<?php
$content = ob_get_clean();
render('Dashboard', 'dashboard', $user, $content);
