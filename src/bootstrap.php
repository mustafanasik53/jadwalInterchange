<?php
/**
 * Common bootstrap included by every public entry point: loads env config,
 * helper functions, the Supabase client class, and the auth helpers, and
 * starts the PHP session. Not part of the originally specified file list,
 * but factored out here so every public/*.php and public/api/*.php file
 * requires exactly one thing and can't forget a dependency.
 */

declare(strict_types=1);

require_once __DIR__ . '/env.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/SupabaseClient.php';
require_once __DIR__ . '/Auth.php';

start_session_if_needed();
