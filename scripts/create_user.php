<?php
/**
 * CLI script: create a Supabase Auth user + matching profiles row.
 *
 * Usage:
 *   php scripts/create_user.php email password "Full Name" teacher|admin
 *
 * Uses the Supabase service_role key (via admin create-user + the profiles
 * table), so this must only ever be run from a trusted machine/CLI — never
 * exposed over HTTP.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "This script may only be run from the command line.\n";
    exit(1);
}

require_once __DIR__ . '/../src/env.php';
require_once __DIR__ . '/../src/SupabaseClient.php';

function usage_and_exit(): void
{
    fwrite(STDERR, "Usage: php scripts/create_user.php <email> <password> \"<Full Name>\" <teacher|admin>\n");
    exit(1);
}

$args = $argv ?? [];
if (count($args) < 5) {
    usage_and_exit();
}

$email = trim((string) $args[1]);
$password = (string) $args[2];
$fullName = trim((string) $args[3]);
$role = strtolower(trim((string) $args[4]));

if ($email === '' || $password === '' || $fullName === '') {
    usage_and_exit();
}

if (!in_array($role, ['teacher', 'admin'], true)) {
    fwrite(STDERR, "Role must be exactly 'teacher' or 'admin'.\n");
    exit(1);
}

try {
    $client = SupabaseClient::fromEnv();

    echo "Creating Supabase auth user for {$email}...\n";
    $authUser = $client->adminCreateUser($email, $password, true);

    $userId = (string) ($authUser['id'] ?? '');
    if ($userId === '') {
        fwrite(STDERR, "Auth user was created but no id was returned. Response: " . json_encode($authUser) . "\n");
        exit(1);
    }

    echo "Auth user created with id {$userId}. Creating profiles row...\n";

    $client->post('profiles', [
        'id' => $userId,
        'email' => $email,
        'full_name' => $fullName,
        'role' => $role,
    ]);

    echo "Done. {$email} can now log in as a {$role}.\n";
    exit(0);
} catch (SupabaseException $e) {
    fwrite(STDERR, 'Failed: ' . $e->getMessage() . "\n");
    exit(1);
}
