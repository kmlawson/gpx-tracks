<?php
/**
 * Session endpoint.
 *   GET  api/session.php            -> { ok, authenticated, csrf, configured }
 *   POST action=login   (pass)
 *   POST action=logout  (csrf)
 *
 * There are no accounts: the site has one password and that is the whole of it.
 */

declare(strict_types=1);
require __DIR__ . '/lib.php';

security_headers();
ensure_dirs();
session_boot();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    // A CSRF token is only minted for an authenticated caller: nothing consumes
    // a pre-login token, and issuing one to every anonymous visitor means every
    // visitor gets a session file written for them.
    json_out([
        'ok'            => true,
        'authenticated' => is_logged_in(),
        'csrf'          => is_logged_in() ? csrf_token() : null,
        'configured'    => passwd_configured(),
    ]);
}

if ($method !== 'POST') {
    fail('Method not allowed', 405);
}

require_same_origin();
$action = (string)($_POST['action'] ?? '');

if ($action === 'logout') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        fail('Invalid CSRF token', 403);
    }
    session_destroy_hard();
    session_boot();
    json_out(['ok' => true, 'authenticated' => false, 'csrf' => csrf_token()]);
}

if ($action !== 'login') {
    fail('Unknown action', 400);
}

$pass = (string)($_POST['pass'] ?? '');

if ($pass === '' || strlen($pass) > 4096) {
    // Still burn a little time so a malformed attempt is not visibly cheaper
    // than a real one.
    usleep(random_int(120000, 260000));
    fail('Invalid password', 401);
}

// Buckets are keyed on the /24 or /64 prefix, not the exact address: a single
// IPv6 allocation would otherwise supply 2^64 fresh counters.
$bucket = 'login|' . client_ip_prefix();

// Peek, don't count: these are *failure* counters, and they are only spent by
// a wrong password. Counting every attempt would let anyone shut the door on
// you by simply hammering the login form with anything at all.
if (!rate_limit_peek($bucket, LOGIN_MAX_FAILS, LOGIN_WINDOW)) {
    fail('Too many attempts. Try again later.', 429);
}
// A second, site-wide bucket so a distributed attack cannot spread its guesses
// across addresses and slip under the per-prefix limit. It does mean a big
// enough botnet can keep you out for 15 minutes at a time; the alternative is
// unlimited guessing, and locking the door beats leaving it open.
if (!rate_limit_peek('login|all', LOGIN_MAX_FAILS_ALL, LOGIN_WINDOW)) {
    fail('Too many attempts. Try again later.', 429);
}

if (!passwd_configured()) {
    fail('No password is configured on this server.', 503);
}

usleep(random_int(80000, 180000)); // flatten timing, slow down guessing

if (!passwd_verify($pass)) {
    rate_limit($bucket, LOGIN_MAX_FAILS, LOGIN_WINDOW);
    rate_limit('login|all', LOGIN_MAX_FAILS_ALL, LOGIN_WINDOW);
    fail('Invalid password', 401);
}

rate_limit_clear($bucket);
rate_limit_clear('login|all');
session_regenerate_id(true);
$_SESSION['auth_at'] = time();
$_SESSION['csrf']    = bin2hex(random_bytes(32));

json_out([
    'ok'            => true,
    'authenticated' => true,
    'csrf'          => csrf_token(),
]);
