<?php
/**
 * Public download endpoint: serves a stored GPX as a file attachment with a
 * readable filename. Read access is deliberately open — the same bytes are
 * already reachable at data/gpx/<id>.gpx.
 *
 *   GET api/download.php?id=<id>          -> attachment
 *   GET api/download.php?id=<id>&view=1   -> inline (still non-executable XML)
 */

declare(strict_types=1);
require __DIR__ . '/lib.php';

if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
    fail('Method not allowed', 405);
}

$id = (string)($_GET['id'] ?? '');
if ($id === '' || strlen($id) > 128 || !valid_id($id)) {
    fail('Invalid track id', 400);
}

// Each download occupies a PHP worker for the length of the transfer, so put a
// generous ceiling on it — well above any human use, low enough that one client
// cannot exhaust the pool. The same bytes stay available as a static file.
if (!rate_limit('dl|' . client_ip_prefix(), 600, 3600)) {
    fail('Too many downloads. The files are also available under data/gpx/.', 429);
}

$path = gpx_path($id);
$real = realpath($path);
$base = realpath(GPX_DIR);

// The id regex already excludes traversal; this confirms the resolved file
// really sits inside the track directory (symlinks included).
if ($real === false || $base === false
    || strncmp($real, $base . DIRECTORY_SEPARATOR, strlen($base) + 1) !== 0
    || !is_file($real) || strtolower(substr($real, -4)) !== '.gpx') {
    fail('Track not found', 404);
}

// A readable filename, taken from the stored metadata rather than the request:
// "2024.02.18 - Abbey St Bathans - 10.9km.gpx".
$name = $id;
$metaFile = meta_path($id);
if (is_file($metaFile)) {
    $meta = json_decode((string)file_get_contents($metaFile), true);
    if (is_array($meta) && !empty($meta['name']) && is_string($meta['name'])) {
        $name = download_basename($meta);
    }
}
$ascii = preg_replace('/[^A-Za-z0-9 ._-]+/', '_', $name) ?? $id;
$ascii = trim(substr($ascii, 0, 80), ' ._-');
if ($ascii === '') {
    $ascii = $id;
}
$utf8 = rawurlencode(mb_substr($name, 0, 80, 'UTF-8'));

$inline = isset($_GET['view']) && $_GET['view'] === '1';
$size = filesize($real);

header_remove('X-Powered-By');
header('Content-Type: application/gpx+xml; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: default-src \'none\'; sandbox');
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
    . '; filename="' . $ascii . '.gpx"'
    . '; filename*=UTF-8\'\'' . $utf8 . '.gpx');
header('Cache-Control: public, max-age=86400');
header('Content-Length: ' . ($size === false ? 0 : $size));

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
    exit;
}
readfile($real);
