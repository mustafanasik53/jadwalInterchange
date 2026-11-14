<?php
/**
 * Session-based auth helpers. The session (not the Supabase JWT) is the
 * source of truth for the rest of the app after login — we look up the
 * matching `profiles` row once at login time and never keep the Supabase
 * access token around afterwards.
 */

declare(strict_types=1);

function start_session_if_needed(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

/**
 * @return array{id:string,email:string,full_name:string,role:string}|null
 */
function current_user(): ?array
{
    start_session_if_needed();

    if (empty($_SESSION['user_id'])) {
        return null;
    }

    return [
        'id' => (string) $_SESSION['user_id'],
        'email' => (string) ($_SESSION['email'] ?? ''),
        'full_name' => (string) ($_SESSION['full_name'] ?? ''),
        'role' => (string) ($_SESSION['role'] ?? 'teacher'),
    ];
}

/**
 * Redirect to the login page unless a user is logged in. Call at the top of
 * any page/endpoint that requires an authenticated session.
 */
function require_login(): array
{
    $user = current_user();
    if ($user === null) {
        $isApi = str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/');
        if ($isApi) {
            json_response(['ok' => false, 'error' => 'Not logged in.'], 401);
        }
        header('Location: /login.php');
        exit;
    }
    return $user;
}

/**
 * Redirect/deny unless the logged-in user is an admin.
 */
function require_admin(): array
{
    $user = require_login();
    if ($user['role'] !== 'admin') {
        $isApi = str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/');
        if ($isApi) {
            json_response(['ok' => false, 'error' => 'Admin access required.'], 403);
        }
        http_response_code(403);
        echo 'Admins only.';
        exit;
    }
    return $user;
}

/**
 * Perform the Supabase password-grant login, then look up (or lazily create)
 * the matching profiles row, and populate the PHP session.
 *
 * Returns the session user array on success. Throws SupabaseException on any
 * failure (bad credentials, network error, etc.) — callers should catch and
 * show a friendly message.
 */
function login_with_password(SupabaseClient $client, string $email, string $password): array
{
    $authResult = $client->signInWithPassword($email, $password);

    $authUser = $authResult['user'] ?? null;
    if (!is_array($authUser) || empty($authUser['id'])) {
        throw new SupabaseException('Login succeeded but no user was returned.');
    }

    $userId = (string) $authUser['id'];
    $userEmail = (string) ($authUser['email'] ?? $email);

    $profile = $client->getOne('profiles', [
        'select' => '*',
        'id' => 'eq.' . $userId,
    ]);

    if ($profile === null) {
        // First login for this auth user — create a default teacher profile.
        $created = $client->post('profiles', [
            'id' => $userId,
            'email' => $userEmail,
            'full_name' => $userEmail,
            'role' => 'teacher',
        ]);
        $profile = $created[0] ?? null;
    }

    if ($profile === null) {
        throw new SupabaseException('Could not load or create a profile for this user.');
    }

    start_session_if_needed();
    session_regenerate_id(true);

    $_SESSION['user_id'] = (string) $profile['id'];
    $_SESSION['email'] = (string) $profile['email'];
    $_SESSION['full_name'] = (string) $profile['full_name'];
    $_SESSION['role'] = (string) $profile['role'];

    claim_timetable_entries_by_its_id($client, $profile);

    return current_user();
}

/**
 * The source Jadwal has no teacher emails, only an ITS ID embedded in each
 * row (timetable_entries.teacher_its_id). The first time a teacher with a
 * matching profiles.its_id logs in, "claim" every one of their raw-imported
 * rows that isn't linked to a real account yet by setting teacher_id — after
 * that one-time link, the rest of the app (which filters everything by
 * teacher_id) just works. Safe/cheap to call on every login: once a row is
 * claimed, the `teacher_id is null` filter excludes it from then on.
 *
 * @param array<string,mixed> $profile
 */
function claim_timetable_entries_by_its_id(SupabaseClient $client, array $profile): void
{
    $itsId = $profile['its_id'] ?? null;
    if ($itsId === null || $itsId === '') {
        return;
    }

    try {
        $client->patch('timetable_entries', [
            'teacher_its_id' => 'eq.' . (string) $itsId,
            'teacher_id' => 'is.null',
        ], [
            'teacher_id' => (string) $profile['id'],
        ]);
    } catch (SupabaseException $e) {
        // Non-fatal: worst case the teacher's periods stay unclaimed until
        // the next login attempt. Never block sign-in over this.
    }
}

function logout(): void
{
    start_session_if_needed();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}
