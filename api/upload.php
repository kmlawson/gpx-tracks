<?php
/**
 * Authenticated GPX upload.
 *   POST api/upload.php  (multipart/form-data: csrf, gpx)
 *
 * Requires a signed-in session (see session.php), a matching CSRF token and a
 * same-origin request. The uploaded document is parsed against a whitelist and
 * rewritten from scratch before anything touches the library.
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

// Throttle before authentication: PHP has already spooled the whole multipart
// body to disk by the time this script runs, so an unauthenticated caller can
// otherwise burn 27 MB of bandwidth and temp I/O per request, uncounted.
if (!is_logged_in() && !rate_limit('upload-anon|' . client_ip_prefix(), 20, 3600)) {
    fail('Too many requests. Try again later.', 429);
}

require_login();

// A body larger than post_max_size arrives with $_POST and $_FILES emptied, so
// this has to be answered before the CSRF check (whose field was dropped too).
if (!$_POST && !$_FILES && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    fail('File exceeds the server upload limit (raise post_max_size / upload_max_filesize)', 413);
}

if (!csrf_check($_POST['csrf'] ?? null)) {
    fail('Invalid or expired CSRF token', 403);
}

if (!rate_limit('upload|' . client_ip_prefix(), UPLOAD_MAX_PER_HOUR, 3600)) {
    fail('Upload rate limit reached. Try again later.', 429);
}

if (!isset($_FILES['gpx']) || !is_array($_FILES['gpx'])) {
    fail('No file was submitted', 400);
}
$f = $_FILES['gpx'];
if (is_array($f['name'] ?? null)) {
    fail('Only one file at a time', 400);       // reject array-style uploads
}

switch ((int)($f['error'] ?? UPLOAD_ERR_NO_FILE)) {
    case UPLOAD_ERR_OK:
        break;
    case UPLOAD_ERR_INI_SIZE:
    case UPLOAD_ERR_FORM_SIZE:
        fail('File is too large', 413);
    case UPLOAD_ERR_NO_FILE:
        fail('No file was submitted', 400);
    case UPLOAD_ERR_PARTIAL:
        fail('Upload was interrupted', 400);
    default:
        fail('Upload failed', 500);
}

$tmp = (string)($f['tmp_name'] ?? '');
if ($tmp === '' || !is_uploaded_file($tmp)) {
    fail('Invalid upload', 400);
}

$size = (int)($f['size'] ?? 0);
if ($size <= 0) {
    fail('Empty file', 400);
}
if ($size > MAX_UPLOAD_BYTES) {
    fail('File is larger than ' . (int)(MAX_UPLOAD_BYTES / 1048576) . ' MB', 413);
}

// The client-supplied name is used only for a fallback title; never for a path.
$orig = basename(str_replace('\\', '/', (string)($f['name'] ?? '')));
$orig = preg_replace('/[^\p{L}\p{N} ._-]+/u', ' ', $orig) ?? '';
$orig = trim(substr($orig, 0, 120));
if (!preg_match('/\.gpx$/i', $orig)) {
    fail('File must have a .gpx extension', 415);
}

$raw = @file_get_contents($tmp, false, null, 0, MAX_UPLOAD_BYTES + 1);
if ($raw === false) {
    fail('Could not read the uploaded file', 500);
}
if (strlen($raw) > MAX_UPLOAD_BYTES) {
    fail('File is too large', 413);
}
@unlink($tmp);

try {
    $meta = ingest_gpx($raw, $orig, 'web');
} catch (GpxRejected $e) {
    fail('Rejected: ' . $e->getMessage(), 422);
} catch (Throwable $e) {
    error_log('gpx upload failure: ' . $e->getMessage());
    fail('Server could not store the file', 500);
}

json_out(['ok' => true, 'track' => $meta]);
