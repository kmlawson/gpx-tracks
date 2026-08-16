<?php
/**
 * Rewrite stored tracks in the current, smaller file format.
 *
 *   GET  api/rebuild.php            -> { ok, pending, format }
 *   POST action=recompact  (csrf)   -> { ok, id, before, after, remaining }
 *
 * Signed-in only, and one track per request — the same shape as the drop
 * folder, for the same reasons: a large library cannot outrun
 * max_execution_time, the browser can show real progress, and stopping half
 * way simply leaves the rest for next time.
 *
 * Nothing here changes what a track *is*. Only the bytes on disk change: the
 * file is re-parsed and re-serialised by the same pair of functions an upload
 * goes through, and the name, dates and statistics are left alone.
 */

declare(strict_types=1);
require __DIR__ . '/lib.php';

security_headers();
ensure_dirs();
session_boot();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    require_login();
    json_out([
        'ok'      => true,
        'pending' => count(store_stale_ids()),
        'format'  => GPX_FORMAT,
    ]);
}

if ($method !== 'POST') {
    fail('Method not allowed', 405);
}

require_same_origin();
require_login();

if (!csrf_check($_POST['csrf'] ?? null)) {
    fail('Invalid or expired CSRF token', 403);
}
if ((string)($_POST['action'] ?? '') !== 'recompact') {
    fail('Unknown action', 400);
}

// Rewriting a track costs about what ingesting one costs, so it draws on the
// same budget rather than getting an uncounted one of its own.
if (!rate_limit('upload|' . client_ip_prefix(), UPLOAD_MAX_PER_HOUR, 3600)) {
    fail('Rate limit reached. Try again later.', 429);
}

$stale = store_stale_ids();
if ($stale === []) {
    json_out(['ok' => true, 'id' => null, 'remaining' => 0, 'done' => false]);
}
$id = $stale[0];
$before = (int)@filesize(gpx_path($id));

try {
    $meta = store_recompact($id);
} catch (GpxRejected $e) {
    // Leave it alone and report why. Marking it done would hide the problem;
    // failing the whole run would let one bad file block every other track.
    fail('Could not rebuild ' . $id . ': ' . $e->getMessage(), 422);
} catch (Throwable $e) {
    error_log('gpx recompact failure: ' . $e->getMessage());
    fail('Could not rebuild ' . $id, 500);
}

upload_log('recompact', $id, (int)($meta['bytes'] ?? 0));

json_out([
    'ok'        => true,
    'done'      => true,
    'id'        => $id,
    'name'      => $meta['name'] ?? $id,
    'before'    => $before,
    'after'     => (int)($meta['bytes'] ?? 0),
    'remaining' => count(store_stale_ids()),
]);
