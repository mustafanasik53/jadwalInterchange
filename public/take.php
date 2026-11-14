<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

$user = require_login();

if (!isset($_SESSION['take_cart']) || !is_array($_SESSION['take_cart'])) {
    $_SESSION['take_cart'] = []; // release_request_id => true
}

$errorMessage = '';
$notice = '';

try {
    $client = SupabaseClient::fromEnv();

    // Handle cart add/remove via plain POST (progressive-enhancement friendly;
    // app.js intercepts these forms with fetch() where supported, but a full
    // page reload works identically).
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        require_csrf();

        $action = (string) ($_POST['action'] ?? '');
        $classIdForRedirect = (string) ($_POST['class_id'] ?? '');
        $releaseRequestId = (string) ($_POST['release_request_id'] ?? '');

        if ($action === 'cart_add' && $releaseRequestId !== '') {
            // Re-verify server-side that this release request is still open and
            // does not belong to the current user before trusting the client.
            $rr = $client->getOne('release_requests', [
                'select' => 'id,teacher_id,status',
                'id' => 'eq.' . $releaseRequestId,
            ]);
            if ($rr !== null && $rr['status'] === 'open' && (string) $rr['teacher_id'] !== $user['id']) {
                $_SESSION['take_cart'][$releaseRequestId] = true;
            }
        } elseif ($action === 'cart_remove' && $releaseRequestId !== '') {
            unset($_SESSION['take_cart'][$releaseRequestId]);
        }

        $redirect = '/take.php';
        if ($classIdForRedirect !== '') {
            $redirect .= '?class_id=' . rawurlencode($classIdForRedirect);
        }
        header('Location: ' . $redirect);
        exit;
    }

    $classes = $client->get('classes', ['select' => 'id,name', 'order' => 'name.asc']);

    $selectedClassId = isset($_GET['class_id']) ? (int) $_GET['class_id'] : 0;

    $openPeriods = [];
    if ($selectedClassId > 0) {
        $openPeriods = $client->get('release_requests', [
            'select' => 'id,reason,created_at,'
                . 'profiles!teacher_id(full_name),'
                . 'timetable_entries!inner(day,subject,book,class_id,week_number,'
                . 'classes(name),'
                . 'period_slots(period_label,sort_order,start_time_weekday,end_time_weekday,start_time_saturday,end_time_saturday))',
            'status' => 'eq.open',
            'teacher_id' => 'neq.' . $user['id'],
            'timetable_entries.class_id' => 'eq.' . $selectedClassId,
            'order' => 'created_at.asc',
        ]);
    }

    // Load full details for whatever is currently in the cart, for display.
    $cartItems = [];
    $cartIds = array_keys($_SESSION['take_cart']);
    if ($cartIds !== []) {
        $idList = implode(',', array_map(static fn(string $id): string => '"' . $id . '"', $cartIds));
        $cartItems = $client->get('release_requests', [
            'select' => 'id,status,'
                . 'timetable_entries(day,subject,class_id,'
                . 'classes(name),'
                . 'period_slots(period_label,sort_order))',
            'id' => 'in.(' . $idList . ')',
        ]);
        // Drop anything that somehow is no longer open (closed/rejected meanwhile).
        $cartItems = array_values(array_filter($cartItems, static fn(array $r): bool => ($r['status'] ?? '') === 'open'));
    }
} catch (SupabaseException $e) {
    $errorMessage = 'Could not load Take Period data right now: ' . $e->getMessage();
    $classes = [];
    $openPeriods = [];
    $cartItems = [];
    $selectedClassId = 0;
}

ob_start();
?>
<h1>Take Period</h1>

<?php if (!empty($_GET['notice'])): ?>
  <p class="alert alert--success"><?= h((string) $_GET['notice']) ?></p>
<?php endif; ?>
<?php if (!empty($_GET['error'])): ?>
  <p class="alert alert--error"><?= h((string) $_GET['error']) ?></p>
<?php endif; ?>

<?php if ($errorMessage !== ''): ?>
  <p class="alert alert--error"><?= h($errorMessage) ?></p>
<?php else: ?>

  <form method="get" action="/take.php" class="form form--inline" id="class-filter-form">
    <label for="class_id">Class</label>
    <select name="class_id" id="class_id">
      <option value="">— Select a class —</option>
      <?php foreach ($classes as $class): ?>
        <option value="<?= h((string) $class['id']) ?>" <?= $selectedClassId === (int) $class['id'] ? 'selected' : '' ?>>
          <?= h($class['name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <noscript><button type="submit" class="btn btn--small">View</button></noscript>
  </form>

  <?php if ($selectedClassId > 0): ?>
    <h2 class="day-heading">Open periods for this class</h2>
    <?php if ($openPeriods === []): ?>
      <p class="muted">No open released periods for this class right now.</p>
    <?php else: ?>
      <ul class="period-list">
        <?php foreach ($openPeriods as $row): ?>
          <?php
            $entry = $row['timetable_entries'] ?? [];
            $slot = $entry['period_slots'] ?? [];
            $className = $entry['classes']['name'] ?? '';
            $inCart = isset($_SESSION['take_cart'][(string) $row['id']]);
          ?>
          <li class="period-card">
            <div class="period-card__info">
              <strong><?= h($slot['period_label'] ?? '') ?></strong>
              <span><?= h(day_label((string) ($entry['day'] ?? ''))) ?></span>
              <span><?= h($className) ?></span>
              <span><?= h($entry['subject'] ?? '') ?></span>
              <span class="muted">Released by <?= h($row['profiles']['full_name'] ?? 'another teacher') ?></span>
            </div>
            <?php if ($inCart): ?>
              <span class="badge badge--open">Selected</span>
            <?php else: ?>
              <form method="post" action="/take.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="cart_add">
                <input type="hidden" name="release_request_id" value="<?= h((string) $row['id']) ?>">
                <input type="hidden" name="class_id" value="<?= h((string) $selectedClassId) ?>">
                <button type="submit" class="btn btn--small">Allot to me</button>
              </form>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  <?php endif; ?>

  <h2 class="day-heading">Your selections</h2>
  <?php if ($cartItems === []): ?>
    <p class="muted">No periods selected yet. Pick a class above, then tap "Allot to me" on any open period.</p>
  <?php else: ?>
    <ul class="period-list">
      <?php foreach ($cartItems as $row): ?>
        <?php
          $entry = $row['timetable_entries'] ?? [];
          $slot = $entry['period_slots'] ?? [];
          $className = $entry['classes']['name'] ?? '';
        ?>
        <li class="period-card">
          <div class="period-card__info">
            <strong><?= h($slot['period_label'] ?? '') ?></strong>
            <span><?= h(day_label((string) ($entry['day'] ?? ''))) ?></span>
            <span><?= h($className) ?></span>
            <span><?= h($entry['subject'] ?? '') ?></span>
          </div>
          <form method="post" action="/take.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="cart_remove">
            <input type="hidden" name="release_request_id" value="<?= h((string) $row['id']) ?>">
            <input type="hidden" name="class_id" value="<?= h((string) $selectedClassId) ?>">
            <button type="submit" class="btn btn--ghost btn--small">Remove</button>
          </form>
        </li>
      <?php endforeach; ?>
    </ul>

    <form method="post" action="/api/take_submit.php" class="form" id="take-submit-form">
      <?= csrf_field() ?>
      <?php foreach ($cartItems as $row): ?>
        <input type="hidden" name="release_request_ids[]" value="<?= h((string) $row['id']) ?>">
      <?php endforeach; ?>
      <button type="submit" class="btn btn--primary btn--block">Send for approval</button>
    </form>
  <?php endif; ?>

<?php endif; ?>
<?php
$content = ob_get_clean();
render('Take Period', 'take', $user, $content);
