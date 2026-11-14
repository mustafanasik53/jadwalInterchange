<?php
/**
 * Small shared helpers: output escaping, CSRF tokens, JSON responses.
 */

declare(strict_types=1);

/**
 * Escape a value for safe HTML output. Always use this instead of raw
 * interpolation of any database/user-supplied value into HTML.
 */
function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Generate (or reuse) the CSRF token stored in the session.
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Render a hidden <input> carrying the CSRF token, for use inside <form>s.
 */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
}

/**
 * Validate a submitted CSRF token (from POST body or an X-CSRF-Token header).
 * Returns true/false — callers decide how to respond on failure.
 */
function csrf_valid(?string $submitted): bool
{
    if (empty($_SESSION['csrf_token']) || empty($submitted)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $submitted);
}

/**
 * Stop the request with a 400 and a short message if the CSRF token in the
 * request (POST field 'csrf_token', or JSON body, or header) doesn't match.
 */
function require_csrf(): void
{
    $submitted = $_POST['csrf_token'] ?? null;

    if ($submitted === null) {
        $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        if ($headerToken !== null) {
            $submitted = $headerToken;
        }
    }

    if ($submitted === null) {
        $raw = file_get_contents('php://input');
        if ($raw !== false && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && isset($decoded['csrf_token'])) {
                $submitted = (string) $decoded['csrf_token'];
            }
        }
    }

    if (!csrf_valid($submitted)) {
        http_response_code(400);
        if (is_json_request()) {
            json_response(['ok' => false, 'error' => 'Invalid or missing CSRF token.'], 400);
        }
        echo 'Invalid or expired form submission. Please go back and try again.';
        exit;
    }
}

function is_json_request(): bool
{
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    return str_contains($accept, 'application/json') || str_contains($contentType, 'application/json');
}

/**
 * Send a JSON response and stop execution.
 *
 * @param array<string,mixed> $data
 */
function json_response(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

/**
 * Friendly error page/response for unexpected failures (e.g. Supabase being
 * unreachable). Never leak stack traces to the browser.
 */
function fail_friendly(string $message, int $status = 500): void
{
    http_response_code($status);
    if (is_json_request()) {
        json_response(['ok' => false, 'error' => $message], $status);
    }
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Error</title></head><body>';
    echo '<p>' . h($message) . '</p>';
    echo '</body></html>';
    exit;
}

/** Map a day_of_week enum value to a human label. */
function day_label(string $day): string
{
    $labels = [
        'mon' => 'Monday',
        'tue' => 'Tuesday',
        'wed' => 'Wednesday',
        'thu' => 'Thursday',
        'fri' => 'Friday',
        'sat' => 'Saturday',
    ];
    return $labels[$day] ?? ucfirst($day);
}

/** Ordered list of day codes, Monday through Saturday. */
function week_days(): array
{
    return ['mon', 'tue', 'wed', 'thu', 'fri', 'sat'];
}

/** Format a Postgres time string ('HH:MM:SS') as 'HH:MM'. */
function short_time(?string $time): string
{
    if ($time === null || $time === '') {
        return '';
    }
    $parts = explode(':', $time);
    return $parts[0] . ':' . ($parts[1] ?? '00');
}

/**
 * The app does not have a real calendar->week mapping table, so "the
 * current week" is simply the highest week_number currently present in
 * timetable_entries. This is a deliberate simplification: whoever imports
 * each week's Jadwal just inserts rows with the next week_number, and that
 * becomes "current" the moment it exists. Falls back to the ISO week number
 * of today if the table is empty.
 */
function current_week_number(SupabaseClient $client): int
{
    $row = $client->getOne('timetable_entries', [
        'select' => 'week_number',
        'order' => 'week_number.desc',
        'limit' => '1',
    ]);

    if ($row !== null && isset($row['week_number'])) {
        return (int) $row['week_number'];
    }

    return (int) date('W');
}

/**
 * Render the shared mobile-first page shell: top app bar, tab navigation,
 * main content, and script tag. Every public page calls this once.
 *
 * @param array{id:string,email:string,full_name:string,role:string}|null $user
 */
function render(string $title, string $activeNav, ?array $user, string $contentHtml): void
{
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= h(csrf_token()) ?>">
<title><?= h($title) ?> — Jadwal Interchange</title>
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<header class="appbar">
  <span class="appbar__title">Jadwal Interchange</span>
  <?php if ($user !== null): ?>
    <span class="appbar__user"><?= h($user['full_name'] !== '' ? $user['full_name'] : $user['email']) ?></span>
  <?php endif; ?>
</header>

<?php if ($user !== null): ?>
<nav class="tabbar" aria-label="Main navigation">
  <a class="tabbar__link<?= $activeNav === 'dashboard' ? ' is-active' : '' ?>" href="/dashboard.php">Dashboard</a>
  <a class="tabbar__link<?= $activeNav === 'release' ? ' is-active' : '' ?>" href="/release.php">Release Period</a>
  <a class="tabbar__link<?= $activeNav === 'take' ? ' is-active' : '' ?>" href="/take.php">Take Period</a>
  <?php if ($user['role'] === 'admin'): ?>
  <a class="tabbar__link<?= $activeNav === 'admin' ? ' is-active' : '' ?>" href="/admin/index.php">Admin</a>
  <?php endif; ?>
  <a class="tabbar__link tabbar__link--logout" href="/logout.php">Logout</a>
</nav>
<?php endif; ?>

<main class="container">
<?= $contentHtml ?>
</main>

<script src="/assets/js/app.js"></script>
</body>
</html>
<?php
}

/**
 * Build the pre-filled subject + body for the "period approved" email sent
 * to the teacher whose take_request was just approved.
 *
 * @return array{subject:string,body:string}
 */
function build_approval_email_template(
    string $teacherName,
    string $className,
    string $dayLabel,
    string $periodLabel,
    ?string $subject
): array {
    $subjectLine = 'Your period take request has been approved';

    $bodyLines = [
        'Hi ' . $teacherName . ',',
        '',
        'Your request to take over the following period has been approved:',
        '',
        '  Class:   ' . $className,
        '  Day:     ' . $dayLabel,
        '  Period:  ' . $periodLabel,
        '  Subject: ' . ($subject !== null && $subject !== '' ? $subject : '(none)'),
        '',
        'This period has been reassigned to you in the Jadwal. Please check your',
        'dashboard in Jadwal Interchange for the full updated schedule.',
        '',
        'Thank you.',
    ];

    return [
        'subject' => $subjectLine,
        'body' => implode("\n", $bodyLines),
    ];
}
