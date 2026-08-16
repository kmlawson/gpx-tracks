<?php
/**
 * Library management: rename and delete. Signed-in only.
 *
 *   POST action=rename  (id, name, csrf)  -> { ok, track }
 *   POST action=delete  (id, csrf)        -> { ok, id }
 *
 * There is no GET here: the catalogue is already public through list.php, and
 * this endpoint exists purely to change things.
 */

declare(strict_types=1);
require __DIR__ . '/lib.php';

security_headers();
ensure_dirs();
session_boot();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    fail('Method not allowed', 405);
}

require_same_origin();
require_login();

if (!csrf_check($_POST['csrf'] ?? null)) {
    fail('Invalid or expired CSRF token', 403);
}

// Editing is cheap next to an upload, but it still writes and reindexes, so it
// gets a budget of its own rather than sharing the upload one.
if (!rate_limit('manage|' . client_ip_prefix(), 240, 3600)) {
    fail('Too many changes. Try again later.', 429);
}

$action = (string)($_POST['action'] ?? '');
$id     = (string)($_POST['id'] ?? '');

if (!valid_id($id)) {
    fail('Invalid track id', 400);
}

if ($action === 'delete') {
    try {
        $gone = store_delete($id);
    } catch (Throwable $e) {
        error_log('gpx delete failure: ' . $e->getMessage());
        fail('Could not delete the track', 500);
    }
    if (!$gone) {
        fail('No such track', 404);
    }
    upload_log('delete', $id, 0);
    json_out(['ok' => true, 'id' => $id]);
}

/*
 * rename and update are the same operation; rename is just the single-field
 * case. Only fields actually present in the request are touched, so the editor
 * can send everything while a plain rename sends one thing.
 */
if ($action === 'rename' || $action === 'update') {
    $fields = [];

    if ($action === 'rename' || isset($_POST['name'])) {
        $name = (string)($_POST['name'] ?? '');
        if ($name === '' || strlen($name) > 4096) {
            fail('A name is required', 400);
        }
        $fields['name'] = $name;
    }
    foreach (['date', 'country', 'tags'] as $key) {
        if (isset($_POST[$key])) {
            if (!is_string($_POST[$key]) || strlen($_POST[$key]) > 4096) {
                fail('Invalid ' . $key, 400);
            }
            $fields[$key] = $_POST[$key];
        }
    }
    if ($fields === []) {
        fail('Nothing to change', 400);
    }

    try {
        $meta = store_update($id, $fields);
    } catch (GpxRejected $e) {
        fail($e->getMessage(), 422);
    } catch (InvalidArgumentException $e) {
        fail($e->getMessage(), 400);
    } catch (Throwable $e) {
        error_log('gpx update failure: ' . $e->getMessage());
        fail('Could not update the track', 500);
    }
    upload_log($action, $id, (int)($meta['bytes'] ?? 0));
    json_out(['ok' => true, 'track' => public_meta($meta)]);
}

fail('Unknown action', 400);
