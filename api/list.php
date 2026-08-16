<?php
/**
 * Public track catalogue. Read-only, no authentication.
 *   GET api/list.php -> { ok, count, tracks: [...] }
 */

declare(strict_types=1);
require __DIR__ . '/lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    fail('Method not allowed', 405);
}

ensure_dirs();

/*
 * The catalogue is served from the pre-built data/index.json — which
 * store_reindex() writes through the field whitelist in public_meta() — rather
 * than by re-reading and re-encoding every metadata file on each request. With
 * a few thousand tracks that difference is ~150 ms of CPU and 20 MB per hit,
 * for an endpoint anyone can call as often as they like.
 */
$body = null;
$mtime = 0;
if (is_file(INDEX_FILE)) {
    $body  = file_get_contents(INDEX_FILE);
    $mtime = (int)filemtime(INDEX_FILE);
}
if ($body === false || $body === null || $body === '') {
    $tracks = store_reindex();
    $body = json_encode(
        ['generated' => gmdate('c'), 'count' => count($tracks), 'tracks' => $tracks],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    ) ?: '{"count":0,"tracks":[]}';
    $mtime = time();
}

$etag = '"' . substr(hash('sha256', (string)$mtime . '|' . strlen($body)), 0, 24) . '"';

security_headers();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=30');
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');

$ifNone = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
if ($ifNone !== '' && (str_contains($ifNone, $etag) || $ifNone === '*')) {
    http_response_code(304);
    exit;
}

echo $body;
