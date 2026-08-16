<?php
/**
 * Drop-folder endpoint. Adopts files left in data/incoming/ by hand.
 *
 *   GET  api/incoming.php               -> { ok, pending, files }
 *   POST action=adopt   (csrf)          -> { ok, name, id?, error?, remaining }
 *
 * Both require a signed-in session: a visitor is told nothing, and cannot make
 * the server parse a 25 MB document by asking. One file is adopted per request,
 * which keeps each request inside max_execution_time however large the queue
 * is, lets the browser show honest progress, and makes the whole thing
 * resumable — closing the tab half way just leaves the rest pending.
 */

declare(strict_types=1);
require __DIR__ . '/lib.php';

security_headers();
ensure_dirs();
session_boot();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    require_login();
    $files = incoming_list();
    json_out(['ok' => true, 'pending' => count($files), 'files' => $files]);
}

if ($method !== 'POST') {
    fail('Method not allowed', 405);
}

require_same_origin();
require_login();

if (!csrf_check($_POST['csrf'] ?? null)) {
    fail('Invalid or expired CSRF token', 403);
}
if ((string)($_POST['action'] ?? '') !== 'adopt') {
    fail('Unknown action', 400);
}

// The maintenance budget, not the upload one. Dropping eighty files into the
// folder and pressing the button is one intended action; the upload limit is
// for a stranger pushing files at the endpoint, and applying it here simply
// stopped a large batch part way through.
if (!rate_limit('maint|' . client_ip_prefix(), MAINTENANCE_MAX_PER_HOUR, 3600)) {
    fail('Rate limit reached. Try again later.', 429);
}

$before = incoming_list();
if ($before === []) {
    json_out(['ok' => true, 'pending' => 0, 'remaining' => 0, 'name' => null, 'done' => false]);
}
$name = $before[0];

try {
    $meta = incoming_adopt_next();
} catch (GpxRejected $e) {
    // A refused file has been moved aside, so the queue has still advanced and
    // the client should keep going rather than stop on the first bad file.
    json_out([
        'ok'        => true,
        'done'      => false,
        'name'      => $name,
        'error'     => $e->getMessage(),
        'remaining' => incoming_count(),
    ]);
} catch (Throwable $e) {
    error_log('gpx adopt failure: ' . $e->getMessage());
    fail('Server could not index ' . $name, 500);
}

json_out([
    'ok'        => true,
    'done'      => true,
    'name'      => $name,
    'track'     => $meta,
    'remaining' => incoming_count(),
]);
