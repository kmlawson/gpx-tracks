<?php
/**
 * Shared library: paths, security headers, auth, rate limiting,
 * GPX validation/sanitisation and the file-based track store.
 *
 * Everything user-supplied is treated as hostile. Uploaded GPX files are never
 * stored as received: they are parsed, whitelisted element by element, and then
 * re-serialised from scratch, so nothing from the original bytes survives.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}
error_reporting(E_ALL);

const APP_ROOT   = __DIR__ . '/..';
const DATA_DIR   = APP_ROOT . '/data';
const GPX_DIR    = DATA_DIR . '/gpx';
const META_DIR   = DATA_DIR . '/meta';
const AUTH_DIR   = DATA_DIR . '/auth';
/**
 * Drop folder. Files copied in here by hand (SFTP, rsync) are picked up and put
 * through the same pipeline as an upload. It is never web-readable: until a file
 * has been parsed and rebuilt it is untrusted input, and serving it would defeat
 * the point of rebuilding uploads at all.
 */
const INCOMING_DIR = DATA_DIR . '/incoming';
/**
 * The single site password lives, as one salted hash, in a PHP file that
 * refuses to render when requested over HTTP, so it stays private even on a
 * server that ignores .htaccess. It is written by the CLI tool only — the web
 * server never needs write access to it, and never writes to it.
 */
const PASSWD_FILE = __DIR__ . '/credentials.php';
const INDEX_FILE = DATA_DIR . '/index.json';
const SESSION_DIR = DATA_DIR . '/sessions';
const LOCK_FILE  = DATA_DIR . '/.store.lock';

/* ------------------------------------------------------------------ *
 * Deployment settings — review these two before going live.
 * ------------------------------------------------------------------ */

/**
 * Set true when the site is only ever reachable over TLS. PHP cannot detect
 * HTTPS behind a TLS-terminating proxy (nginx/Caddy/Cloudflare -> FPM), and
 * guessing wrong means the session cookie is issued without the Secure flag.
 */
const FORCE_HTTPS = false;

/**
 * Addresses of reverse proxies allowed to declare the scheme via
 * X-Forwarded-Proto. Empty means "trust nothing", which is the safe default.
 */
const TRUSTED_PROXIES = [];

/** Expected public host (e.g. 'gpx.example.com'). Empty falls back to the
 *  request's Host header, which is wrong behind a proxy that rewrites it. */
const SITE_HOST = '';

/* ------------------------------------------------------------------ *
 * Hard limits
 * ------------------------------------------------------------------ */

const MAX_UPLOAD_BYTES   = 25 * 1024 * 1024;   // 25 MB of XML in
const MAX_STORED_BYTES   = 25 * 1024 * 1024;   // and 25 MB out
const MAX_POINTS         = 200000;             // points per file (memory bound)
const MAX_WAYPOINTS      = 2000;
const MAX_TRACK_ELEMENTS = 2000;               // <trk>/<rte> per file
const MAX_SEGMENTS       = 5000;               // <trkseg> per file
const MAX_ELEMENTS       = 2000000;            // whitelisted elements parsed
const MAX_TEXT_LEN       = 200;                // any name/desc field
const MAX_TRACKS_STORED  = 5000;
const LOGIN_MAX_FAILS    = 8;                  // failures per IP prefix per window
const LOGIN_MAX_FAILS_ALL = 24;                // failures site-wide per window
const LOGIN_WINDOW       = 900;                // 15 minutes
const UPLOAD_MAX_PER_HOUR = 60;
const SESSION_IDLE       = 7200;               // 2 hours
const SESSION_ABSOLUTE   = 86400;              // 24 hours
const UPLOAD_LOG_MAX     = 1048576;            // rotate the private log at 1 MB
/** Timestamps outside this range are treated as absent, not as data. */
const TIME_MIN = 631152000;                    // 1990-01-01

/* ------------------------------------------------------------------ *
 * Output helpers
 * ------------------------------------------------------------------ */

function security_headers(): void
{
    if (PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Cross-Origin-Resource-Policy: same-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(), interest-cohort=()');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    if (is_https()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
    // expose_php cannot be turned off per-directory, so strip it here as well.
    header_remove('X-Powered-By');
}

function json_out(array $payload, int $status = 200): never
{
    security_headers();
    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(string $message, int $status = 400): never
{
    json_out(['ok' => false, 'error' => $message], $status);
}

/* ------------------------------------------------------------------ *
 * Storage bootstrap
 * ------------------------------------------------------------------ */

function ensure_dirs(): void
{
    foreach ([DATA_DIR, GPX_DIR, META_DIR, AUTH_DIR, INCOMING_DIR] as $dir) {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create storage directory');
        }
    }
    if (!is_dir(SESSION_DIR)) {
        @mkdir(SESSION_DIR, 0700, true);
    }

    // Defence in depth for Apache: never execute anything under data/, and
    // never expose the credential store. php_flag only exists under mod_php —
    // unguarded it is a fatal "Invalid command" on FPM/CGI hosts, which would
    // turn every track file into a 500.
    $dataGuard = <<<'HTA'
        <IfModule mod_php.c>
          php_flag engine off
        </IfModule>
        <IfModule mod_php7.c>
          php_flag engine off
        </IfModule>
        RemoveHandler .php .phtml .phar .php3 .php4 .php5 .php7 .php8
        RemoveType .php .phtml .phar
        Options -ExecCGI -Indexes
        <FilesMatch "\.(php|phtml|phar|phps|inc|log|ini)$">
          Require all denied
        </FilesMatch>
        <FilesMatch "^\.">
          Require all denied
        </FilesMatch>
        HTA;

    $denyAll = "Require all denied\nDeny from all\nOptions -Indexes\n";
    $guards = [
        DATA_DIR . '/.htaccess'  => $dataGuard,
        AUTH_DIR . '/.htaccess'  => $denyAll,
        AUTH_DIR . '/index.html' => '',
        META_DIR . '/.htaccess'  => $denyAll,
        SESSION_DIR . '/.htaccess' => $denyAll,
        INCOMING_DIR . '/.htaccess'  => $denyAll,
        INCOMING_DIR . '/index.html' => '',
    ];
    foreach ($guards as $path => $body) {
        if (!file_exists($path) && is_dir(dirname($path))) {
            @file_put_contents($path, $body);
        }
    }

    sweep_stale_temp_files();
}

/**
 * A killed request (OOM, execution timeout) can leave a partly written .tmp
 * file behind; nothing else ever looks at them, so clear the old ones.
 */
function sweep_stale_temp_files(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    if (random_int(1, 20) !== 1) {      // cheap: roughly one request in twenty
        return;
    }
    $cutoff = time() - 3600;
    foreach ([GPX_DIR, META_DIR, DATA_DIR] as $dir) {
        foreach (glob($dir . '/.tmp*') ?: [] as $tmp) {
            if (is_file($tmp) && @filemtime($tmp) < $cutoff) {
                @unlink($tmp);
            }
        }
    }
}

/**
 * Exclusive lock around read-modify-write sequences on the store (duplicate
 * detection, writing a track, rebuilding the index). Returns a handle whose
 * release unlocks; null when locking is impossible.
 *
 * @return resource|null
 */
function store_lock()
{
    $fh = @fopen(LOCK_FILE, 'c');
    if ($fh === false) {
        error_log('gpx: cannot open store lock ' . LOCK_FILE . ' — proceeding unlocked');
        return null;
    }
    if (!flock($fh, LOCK_EX)) {
        fclose($fh);
        error_log('gpx: cannot acquire store lock — proceeding unlocked');
        return null;
    }
    return $fh;
}

/** @param resource|null $fh */
function store_unlock($fh): void
{
    if (is_resource($fh)) {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

/** Atomic write: temp file in the same directory, then rename. */
function atomic_write(string $path, string $contents, int $mode = 0644): bool
{
    $dir = dirname($path);
    $tmp = @tempnam($dir, '.tmp');
    if ($tmp === false) {
        return false;
    }
    if (@file_put_contents($tmp, $contents, LOCK_EX) !== strlen($contents)) {
        @unlink($tmp);
        return false;
    }
    @chmod($tmp, $mode);
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

/* ------------------------------------------------------------------ *
 * Sessions & CSRF
 * ------------------------------------------------------------------ */

function is_https(): bool
{
    if (FORCE_HTTPS) {
        return true;
    }
    if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443')) {
        return true;
    }
    // X-Forwarded-Proto is client-controllable unless the hop is a proxy we
    // have been told to trust.
    return ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
        && TRUSTED_PROXIES !== []
        && in_array($_SERVER['REMOTE_ADDR'] ?? '', TRUSTED_PROXIES, true);
}

function session_boot(): void
{
    if (PHP_SAPI === 'cli' || session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    // Keep session files inside the app rather than a world-shared /tmp, where
    // a co-tenant process running as the same user could read or forge them.
    if (is_dir(SESSION_DIR) && is_writable(SESSION_DIR)) {
        session_save_path(SESSION_DIR);
    }
    // The default gc_maxlifetime (24 min) would reap sessions long before the
    // idle timeout this app advertises.
    ini_set('session.gc_maxlifetime', (string)SESSION_IDLE);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\') ?: '/',
        'domain'   => '',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_name('gpxsid');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    session_start();

    // Bind the session to the client's User-Agent only. Binding to the address
    // as well sounds stronger but is not: an attacker close enough to steal the
    // cookie usually shares the prefix, while legitimate clients change address
    // constantly (IPv6 privacy extensions, CGNAT, cell handover, dual-stack) —
    // and a session dropped mid-upload loses the whole transfer.
    $fp = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '');
    if (isset($_SESSION['fp']) && !hash_equals($_SESSION['fp'], $fp)) {
        session_destroy_hard();
        session_start();
    }
    $_SESSION['fp'] = $fp;

    $now = time();
    $idle = isset($_SESSION['seen']) && ($now - (int)$_SESSION['seen']) > SESSION_IDLE;
    $aged = isset($_SESSION['auth_at']) && ($now - (int)$_SESSION['auth_at']) > SESSION_ABSOLUTE;
    if ($idle || $aged) {
        session_destroy_hard();
        session_start();
        $_SESSION['fp'] = $fp;
    }
    $_SESSION['seen'] = $now;
}

function session_destroy_hard(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies') && !headers_sent()) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }
    @session_destroy();
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_check(?string $given): bool
{
    return is_string($given)
        && !empty($_SESSION['csrf'])
        && hash_equals((string)$_SESSION['csrf'], $given);
}

function is_logged_in(): bool
{
    return !empty($_SESSION['auth_at']);
}

function require_login(): void
{
    if (!is_logged_in()) {
        fail('Not authenticated', 401);
    }
}

/**
 * Reject cross-site form posts even from browsers that ignore SameSite.
 * Requests are only accepted when Origin/Referer match the request host.
 */
function require_same_origin(): void
{
    $expected = normalise_host(SITE_HOST !== '' ? SITE_HOST : ($_SERVER['HTTP_HOST'] ?? ''));
    $src = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '';

    // No Origin and no Referer: every browser sends one on a cross-origin POST,
    // so a request with neither is not a browser doing a state change.
    if ($src === '') {
        fail('Missing Origin/Referer', 403);
    }

    $parsed = parse_url($src);
    if ($parsed === false || empty($parsed['host'])) {
        fail('Cross-origin request rejected', 403);
    }
    $srcHost = $parsed['host'];
    $scheme  = strtolower($parsed['scheme'] ?? '');
    $port    = $parsed['port'] ?? null;
    // Ignore a port that is the scheme's default: browsers omit it in Host but
    // may include it in Referer.
    if ($port !== null && !(($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80))) {
        $srcHost .= ':' . $port;
    }

    if ($expected === '' || strcasecmp(normalise_host($srcHost), $expected) !== 0) {
        fail('Cross-origin request rejected', 403);
    }
}

/** Lower-cased host with any default port removed. */
function normalise_host(string $host): string
{
    $host = strtolower(trim($host));
    return preg_replace('/:(80|443)$/', '', $host) ?? $host;
}

/* ------------------------------------------------------------------ *
 * Rate limiting (file based, per client)
 * ------------------------------------------------------------------ */

function client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

/**
 * IPv4 /24 or IPv6 /64 — the unit rate limiting is applied to. Splitting an
 * IPv6 address on ':' does not work: a compressed address such as 2001:db8::1
 * has fewer than four groups and would yield the full /128, handing an attacker
 * 2^64 fresh buckets from one allocation.
 */
function client_ip_prefix(): string
{
    $ip = client_ip();
    if (!str_contains($ip, ':')) {
        return implode('.', array_slice(explode('.', $ip), 0, 3));
    }
    $bin = @inet_pton($ip);
    return $bin === false ? $ip : bin2hex(substr($bin, 0, 8));
}

/**
 * Returns true when the action is allowed. Counters live in one JSON file
 * guarded by an exclusive lock.
 */
function rate_limit(string $bucket, int $max, int $window, bool $count = true): bool
{
    ensure_dirs();
    $file = AUTH_DIR . '/ratelimit.json';
    $fh = @fopen($file, 'c+');
    if ($fh === false) {
        // Fail open rather than lock the owner out of their own site — but say
        // so loudly, and slow the caller down so it is not a free bypass.
        error_log('gpx: rate-limit store unavailable at ' . $file . ' — limits NOT enforced');
        usleep(random_int(400000, 900000));
        return true;
    }
    $allowed = true;
    if (flock($fh, LOCK_EX)) {
        $raw  = stream_get_contents($fh) ?: '';
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $data = [];
        }
        $now = time();
        foreach ($data as $k => $entry) {          // expire old buckets
            if (!is_array($entry) || ($entry['exp'] ?? 0) < $now) {
                unset($data[$k]);
            }
        }
        $key = hash('sha256', $bucket);
        $cur = $data[$key] ?? ['n' => 0, 'exp' => $now + $window, 'max' => $max];
        $cur['max'] = $max;
        if ($cur['n'] >= $max) {
            $allowed = false;
            $data[$key] = $cur;
        } elseif ($count) {
            $cur['n']++;
            $data[$key] = $cur;
        }
        // Eviction must never be a way to clear a lockout. Buckets that have
        // reached their limit are kept in preference to everything else;
        // whatever room is left goes to the busiest of the rest. Dropping by
        // insertion order or by age (the obvious implementations) would discard
        // exactly the buckets that are holding back an active attack.
        if (count($data) > 5000) {
            $saturated = array_filter(
                $data,
                static fn(array $e): bool => ($e['n'] ?? 0) >= ($e['max'] ?? PHP_INT_MAX)
            );
            $byLoad = static fn(array $a, array $b): int =>
                [$a['n'] ?? 0, $a['exp'] ?? 0] <=> [$b['n'] ?? 0, $b['exp'] ?? 0];

            if (count($saturated) > 4000) {
                uasort($saturated, static fn(array $a, array $b): int => ($a['exp'] ?? 0) <=> ($b['exp'] ?? 0));
                $data = array_slice($saturated, -4000, null, true);
            } else {
                $rest = array_diff_key($data, $saturated);
                uasort($rest, $byLoad);
                $data = $saturated + array_slice($rest, -(4000 - count($saturated)), null, true);
            }
        }
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($data));
        fflush($fh);
        flock($fh, LOCK_UN);
    } else {
        error_log('gpx: rate-limit lock failed — limits NOT enforced for this request');
        usleep(random_int(400000, 900000));
    }
    fclose($fh);
    return $allowed;
}

/** Check a bucket without incrementing it. */
function rate_limit_peek(string $bucket, int $max, int $window): bool
{
    return rate_limit($bucket, $max, $window, false);
}

function rate_limit_clear(string $bucket): void
{
    $file = AUTH_DIR . '/ratelimit.json';
    $fh = @fopen($file, 'c+');
    if ($fh === false) {
        return;
    }
    if (flock($fh, LOCK_EX)) {
        $data = json_decode(stream_get_contents($fh) ?: '', true);
        if (is_array($data)) {
            unset($data[hash('sha256', $bucket)]);
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, json_encode($data));
            fflush($fh);
        }
        flock($fh, LOCK_UN);
    }
    fclose($fh);
}

/* ------------------------------------------------------------------ *
 * Password file
 *
 * One site, one password, no usernames. The hash is generated on a machine
 * you trust with the CLI tool and the resulting credentials.php is uploaded;
 * nothing on the server ever writes it.
 * ------------------------------------------------------------------ */

/** @return string|null the salted password hash, or null if none is configured */
function passwd_hash_load(): ?string
{
    if (!is_readable(PASSWD_FILE)) {
        return null;
    }
    if (!defined('GPX_LIB')) {
        define('GPX_LIB', true);
    }
    $hash = @include PASSWD_FILE;
    // Validated even though only the CLI writes this file: a hash that is not a
    // hash would otherwise reach password_verify() as an unpredictable value.
    if (!is_string($hash) || !preg_match('/^\$2[aby]\$\d{2}\$[.\/A-Za-z0-9]{53}$/', $hash)) {
        return null;
    }
    return $hash;
}

function passwd_configured(): bool
{
    return passwd_hash_load() !== null;
}

/**
 * bcrypt silently truncates at 72 bytes, so hash a digest of the password
 * rather than the password itself. Base64 of a raw SHA-256 is 44 bytes and
 * contains no NUL, which bcrypt would also truncate at.
 */
function passwd_prehash(string $plain): string
{
    return base64_encode(hash('sha256', $plain, true));
}

function passwd_set(string $plain): bool
{
    if (strlen($plain) < 12 || strlen($plain) > 4096) {
        throw new InvalidArgumentException('Password must be 12-4096 characters');
    }
    // PASSWORD_BCRYPT, not PASSWORD_DEFAULT: the dummy hash used for timing
    // equalisation in passwd_verify() must stay the same algorithm and cost,
    // and PASSWORD_DEFAULT is explicitly allowed to change between releases.
    $hash = password_hash(passwd_prehash($plain), PASSWORD_BCRYPT, ['cost' => 12]);

    // var_export, never hand-quoting: this file is PHP source, and a value that
    // slipped through validation must not be able to become code.
    $body = "<?php\n"
        . "// GPX site password — one salted bcrypt hash, nothing else.\n"
        . "// Generated with: php tools/gpxadmin.php passwd\n"
        . "// Refuses to render if requested over HTTP.\n"
        . "if (PHP_SAPI !== 'cli' && !defined('GPX_LIB')) { http_response_code(403); exit; }\n"
        . 'return ' . var_export($hash, true) . ";\n";

    return atomic_write(PASSWD_FILE, $body, 0600);
}

/**
 * Verify with constant-ish timing: always run exactly one bcrypt comparison,
 * against a dummy of the same algorithm and cost when no password is
 * configured, so response time never reveals whether the site has one.
 */
function passwd_verify(string $plain): bool
{
    $dummy = '$2y$12$C6UzMDM.H6dfI/f/IKcEe.iEwjZ0iBnrjNn5bJQ2Vh2pRQAO7sTCe';
    $hash  = passwd_hash_load();
    $ok    = password_verify(passwd_prehash($plain), $hash ?? $dummy);
    return $ok && $hash !== null;
}

/* ------------------------------------------------------------------ *
 * Track store
 * ------------------------------------------------------------------ */

function valid_id(string $id): bool
{
    return (bool)preg_match('/^[a-z0-9]{8,32}(?:-[a-z0-9-]{1,80})?$/', $id);
}

function make_id(string $name): string
{
    $slug = strtolower($name);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim((string)$slug, '-');
    $slug = substr($slug, 0, 60);
    $rand = bin2hex(random_bytes(6));
    return $slug === '' ? $rand : $rand . '-' . $slug;
}

function meta_path(string $id): string
{
    return META_DIR . '/' . $id . '.json';
}

function gpx_path(string $id): string
{
    return GPX_DIR . '/' . $id . '.gpx';
}

/** Fields the catalogue publishes. Anything else in a metadata file — the
 *  uploader's name in records written by older versions, for instance —
 *  stays private. */
const PUBLIC_META_FIELDS = [
    'id', 'name', 'file', 'uploaded_at', 'date', 'date_manual', 'activity',
    'country', 'tags', 'bytes', 'sha256',
    'distance_m', 'points', 'start_time', 'end_time', 'duration_s', 'moving_s',
    'ele_min', 'ele_max', 'ele_spread', 'ele_gain', 'ele_loss', 'bounds',
];

/* ------------------------------------------------------------------ *
 * Dates carried in a track's name
 *
 * The same rule as assets/app.js, so a filename never disagrees with what the
 * page shows. Kept here rather than in each caller because three of them need
 * it: the download endpoint, the catalogue and the one-off date repair.
 * ------------------------------------------------------------------ */

/** @return array{y:int,m:int,d:int,text:string,pos:int}|null */
function meta_title_date(string $name): ?array
{
    if (!preg_match_all('/(\d{4})\.(\d{1,2})\.(\d{1,2})/', $name, $all, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
        return null;
    }
    foreach ($all as $m) {
        $pos    = $m[0][1];
        $text   = $m[0][0];
        $before = $pos === 0 ? '' : substr($name, $pos - 1, 1);
        $after  = substr($name, $pos + strlen($text), 1);
        // Must stand alone: 1.2024.2.18 or 2024.2.18.4 is a version, not a date.
        if ($before !== '' && (ctype_digit($before) || $before === '.')) {
            continue;
        }
        if ($after !== '' && (ctype_digit($after) || $after === '.')) {
            continue;
        }
        $y = (int)$m[1][0]; $mo = (int)$m[2][0]; $d = (int)$m[3][0];
        if (!checkdate($mo, $d, $y) || $y < 1990 || $y > 2100) {
            continue;   // rejects 2024.2.31 rather than rolling it into March
        }
        return ['y' => $y, 'm' => $mo, 'd' => $d, 'text' => $text, 'pos' => $pos];
    }
    return null;
}

/** The name as shown: any yyyy.mm.dd removed, leftover punctuation tidied. */
function meta_display_name(array $meta): string
{
    $name = (string)($meta['name'] ?? ($meta['id'] ?? ''));
    $t = meta_title_date($name);
    if ($t === null) {
        return $name;
    }
    $out = substr($name, 0, $t['pos']) . substr($name, $t['pos'] + strlen($t['text']));
    $out = preg_replace('/\s+/u', ' ', $out) ?? $out;
    $out = trim($out, " \t\n\r\0\x0B-–—_.,:");
    return $out !== '' ? $out : $name;
}

/**
 * The date to show for a track, as YYYY-MM-DD, or null when it has none.
 * A date set by hand wins; then one carried in the name; then the file's own.
 */
function meta_effective_date(array $meta): ?string
{
    $raw = (string)($meta['date'] ?? '');
    if (!empty($meta['date_manual'])) {
        return $raw !== '' ? substr($raw, 0, 10) : null;
    }
    $t = meta_title_date((string)($meta['name'] ?? ''));
    if ($t !== null) {
        return sprintf('%04d-%02d-%02d', $t['y'], $t['m'], $t['d']);
    }
    return $raw !== '' ? substr($raw, 0, 10) : null;
}

/**
 * Download filename, without the extension:
 *
 *     2024.02.18 - Abbey St Bathans - 10.9km
 *
 * Any part the track does not have is dropped along with its separator, so a
 * track with no date and no distance is just its name.
 */
function download_basename(array $meta): string
{
    $parts = [];

    $date = meta_effective_date($meta);
    if ($date !== null && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) {
        $parts[] = $m[1] . '.' . $m[2] . '.' . $m[3];
    }

    $name = meta_display_name($meta);
    if ($name !== '') {
        $parts[] = $name;
    }

    $metres = $meta['distance_m'] ?? null;
    if (is_numeric($metres) && $metres > 0) {
        $km = $metres / 1000;
        // Matches how the site prints distances, minus the space, which reads
        // badly in a filename next to the surrounding " - " separators.
        $parts[] = ($km < 10 ? number_format($km, 2, '.', '') : number_format($km, 1, '.', '')) . 'km';
    }

    if ($parts === []) {
        return (string)($meta['id'] ?? 'track');
    }
    return implode(' - ', $parts);
}

function public_meta(array $meta): array
{
    return array_intersect_key($meta, array_flip(PUBLIC_META_FIELDS));
}

function store_list(): array
{
    ensure_dirs();
    $tracks = [];
    foreach (glob(META_DIR . '/*.json') ?: [] as $file) {
        $id = basename($file, '.json');
        if (!valid_id($id) || !is_file(gpx_path($id))) {
            continue;
        }
        $meta = json_decode((string)file_get_contents($file), true);
        if (is_array($meta) && ($meta['id'] ?? '') === $id) {
            $tracks[] = $meta;
        }
    }
    usort($tracks, static fn($a, $b) => strcmp((string)($b['date'] ?? ''), (string)($a['date'] ?? '')));
    return $tracks;
}

/**
 * Rebuild the static catalogue so the site also works without list.php.
 * Takes the store lock unless the caller already holds it — without it two
 * concurrent uploads each write the list they read, and the loser's track
 * silently disappears from the catalogue.
 */
function store_reindex(bool $locked = false): array
{
    $lock = $locked ? null : store_lock();
    try {
        $tracks = array_map('public_meta', store_list());
        $json = json_encode(
            ['generated' => gmdate('c'), 'count' => count($tracks), 'tracks' => $tracks],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if ($json === false || !atomic_write(INDEX_FILE, $json)) {
            error_log('gpx: failed to write ' . INDEX_FILE);
        }
        return $tracks;
    } finally {
        store_unlock($lock);
    }
}

/* ------------------------------------------------------------------ *
 * GPX parsing / sanitising
 * ------------------------------------------------------------------ */

class GpxRejected extends RuntimeException {}

/** Strip control characters, collapse whitespace, cap length. */
function clean_text(?string $s): string
{
    if ($s === null) {
        return '';
    }
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
    // Strip zero-width, soft-hyphen and bidirectional-override characters: they
    // are invisible but can reorder or disguise how a track name renders.
    $s = preg_replace('/[\x{00AD}\x{061C}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{2069}\x{FEFF}]/u', '', $s) ?? '';
    $s = preg_replace('/\s+/u', ' ', $s) ?? '';
    $s = trim($s);
    if ($s !== '' && !mb_check_encoding($s, 'UTF-8')) {
        throw new GpxRejected('Text field is not valid UTF-8');
    }
    if (mb_strlen($s, 'UTF-8') > MAX_TEXT_LEN) {
        $s = mb_substr($s, 0, MAX_TEXT_LEN, 'UTF-8');
    }
    return $s;
}

/**
 * Remove anything link-like from a text field, then refuse the file outright if
 * markup survives. Real exports from Garmin, Strava and Komoot routinely carry a
 * product URL in a name or description, and rejecting the whole upload for that
 * is useless to the user — the requirement is that no URL is *stored*.
 * Markup characters are a different matter and remain fatal.
 */
function sanitise_text(string $s, string $where): string
{
    if ($s === '') {
        return '';
    }
    // Strip URLs, bare domains, and scheme-like tokens.
    $s = preg_replace('~\b[a-z][a-z0-9+.-]*://\S*~i', '', $s) ?? $s;
    $s = preg_replace('~\b(?:javascript|data|vbscript|file|mailto)\s*:\S*~i', '', $s) ?? $s;
    $s = preg_replace('~\bwww\.\S*~i', '', $s) ?? $s;
    $s = preg_replace('~\b[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.(?:com|net|org|io|co|uk|ru|cn|de|fr|nl|xyz|top|info|biz|link|app|dev|me|ly)\b\S*~i', '', $s) ?? $s;
    $s = preg_replace('~\S+@\S+\.\S+~', '', $s) ?? $s;          // e-mail addresses
    $s = trim(preg_replace('/\s+/u', ' ', $s) ?? $s);

    // Markup, entity refs or attribute syntax in a field we keep means the file
    // is not a plain track: refuse it rather than quietly patch it up.
    $probe = strtolower($s);
    foreach (['<', '>', '&#', 'href', 'src=', '\\\\'] as $needle) {
        if (str_contains($probe, $needle)) {
            throw new GpxRejected("Markup is not allowed in $where");
        }
    }
    return $s;
}

/**
 * ISO-8601 to a UTC timestamp, or null. Dates outside a plausible range are
 * treated as missing: a track stamped 9999-12-31 would otherwise pin itself to
 * the top of the list for ever. Built by hand rather than with DateTimeImmutable
 * — a file can hold hundreds of thousands of these, and the object construction
 * dominated parse time.
 */
function parse_time(?string $s): ?int
{
    $s = trim((string)$s);
    if ($s === '' || strlen($s) > 40) {
        return null;
    }
    if (!preg_match(
        '/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2}):(\d{2})(?:\.\d{1,9})?(Z|z|[+-]\d{2}:?\d{2})?$/',
        $s,
        $m
    )) {
        return null;
    }
    [, $y, $mo, $d, $h, $mi, $sec] = $m;
    $y = (int)$y; $mo = (int)$mo; $d = (int)$d;
    $h = (int)$h; $mi = (int)$mi; $sec = (int)$sec;

    // Reject impossible dates instead of letting them roll over (2020-02-30).
    if ($mo < 1 || $mo > 12 || $d < 1 || $h > 23 || $mi > 59 || $sec > 60
        || !checkdate($mo, $d, $y)) {
        return null;
    }

    $ts = gmmktime($h, $mi, min($sec, 59), $mo, $d, $y);
    if ($ts === false) {
        return null;
    }
    $offset = $m[7] ?? 'Z';
    if ($offset !== '' && $offset !== 'Z' && $offset !== 'z') {
        $sign = $offset[0] === '-' ? 1 : -1;
        $digits = str_replace(':', '', substr($offset, 1));
        $ts += $sign * (((int)substr($digits, 0, 2)) * 3600 + ((int)substr($digits, 2, 2)) * 60);
    }
    if ($ts < TIME_MIN || $ts > time() + 86400) {
        return null;
    }
    return $ts;
}

/**
 * Parse a GPX document into a plain data structure, accepting only a small
 * whitelist of elements/attributes. Streaming (XMLReader) so a big file never
 * blows up memory, no DTD, no entity substitution, no network access.
 *
 * @return array{name:string,type:string,tracks:array,waypoints:array,metaTime:?int}
 */
function gpx_parse(string $xml, ?int $maxBytes = null): array
{
    if ($xml === '') {
        throw new GpxRejected('Empty file');
    }
    if (strlen($xml) > ($maxBytes ?? MAX_UPLOAD_BYTES)) {
        throw new GpxRejected('File too large');
    }
    if (str_contains($xml, "\0")) {
        throw new GpxRejected('File contains null bytes');
    }
    if (!mb_check_encoding($xml, 'UTF-8')) {
        throw new GpxRejected('File is not valid UTF-8');
    }
    if (stripos($xml, '<!ENTITY') !== false || stripos($xml, '<!DOCTYPE') !== false) {
        throw new GpxRejected('Document type declarations and entities are not allowed');
    }
    // Only PHP open tags are worth a raw-byte scan: they are the one thing a
    // misconfigured server might execute. Scanning for '<script' and friends
    // rejected innocent files (a <scriptum> extension element, a description
    // mentioning an iframe) for no gain — the element whitelist and the
    // XMLWriter rebuild already decide what can reach the stored file.
    $lower = strtolower($xml);
    foreach (['<?php', '<?='] as $needle) {
        if (str_contains($lower, $needle)) {
            throw new GpxRejected('File contains executable content');
        }
    }
    if (!preg_match('/<gpx[\s>]/i', $xml)) {
        throw new GpxRejected('Not a GPX file (no <gpx> element)');
    }

    $prev = libxml_use_internal_errors(true);
    libxml_clear_errors();

    $reader = new XMLReader();
    // The 'UTF-8' argument is load-bearing, not cosmetic: with null, libxml
    // honours an encoding declaration such as UTF-7, in which "<!DOCTYPE" is
    // spelled "+ADwAIQ-DOCTYPE" and slips past the byte checks above. Forcing
    // UTF-8 makes such a document fail to parse at all. Never make it null.
    $opened = $reader->XML($xml, 'UTF-8', LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT);
    if (!$opened) {
        libxml_use_internal_errors($prev);
        throw new GpxRejected('File is not well-formed XML');
    }
    // Belt and braces: never load or substitute entities.
    @$reader->setParserProperty(XMLReader::LOADDTD, false);
    @$reader->setParserProperty(XMLReader::SUBST_ENTITIES, false);
    @$reader->setParserProperty(XMLReader::VALIDATE, false);

    $result = ['name' => '', 'type' => '', 'tracks' => [], 'waypoints' => [], 'metaTime' => null];
    $inMetadata = false;
    $inTrk = false;
    $curTrack = null;
    $curSeg = null;
    $curPt = null;          // ['lat','lon','ele','time'] while inside trkpt
    $ptContext = null;      // 'trkpt' | 'wpt' | 'rtept'
    $curWpt = null;
    $depthOfPt = 0;
    $pointCount = 0;
    $trackCount = 0;
    $segCount = 0;
    $elementCount = 0;
    $rteAsTrack = null;

    $readText = static function (XMLReader $r): string {
        // Read the element's text content without descending into children.
        if ($r->isEmptyElement) {
            return '';
        }
        $depth = $r->depth;
        $text = '';
        while ($r->read()) {
            if (($r->nodeType === XMLReader::END_ELEMENT) && $r->depth === $depth) {
                break;
            }
            if ($r->nodeType === XMLReader::TEXT || $r->nodeType === XMLReader::CDATA
                || $r->nodeType === XMLReader::SIGNIFICANT_WHITESPACE) {
                $text .= $r->value;
                if (strlen($text) > 4096) {
                    $text = substr($text, 0, 4096);
                }
            }
        }
        return $text;
    };

    try {
        while (@$reader->read()) {
            if ($reader->nodeType === XMLReader::DOC_TYPE) {
                throw new GpxRejected('Document type declarations are not allowed');
            }
            if ($reader->nodeType === XMLReader::ENTITY_REF) {
                throw new GpxRejected('Entity references are not allowed');
            }
            if ($reader->nodeType === XMLReader::PI) {
                continue;
            }
            $local = $reader->localName;

            if ($reader->nodeType === XMLReader::ELEMENT) {
                // One budget over every element, so a file made of millions of
                // cheap-but-not-free elements cannot burn CPU indefinitely.
                if (++$elementCount > MAX_ELEMENTS) {
                    throw new GpxRejected('Document has too many elements');
                }
                switch ($local) {
                    case 'metadata':
                        $inMetadata = !$reader->isEmptyElement;
                        break;

                    case 'name':
                        $txt = sanitise_text(clean_text($readText($reader)), 'name');
                        if ($curWpt !== null && $curTrack === null) {
                            $curWpt['name'] = $txt;
                        } elseif ($curTrack !== null && ($curTrack['name'] ?? '') === '') {
                            $curTrack['name'] = $txt;
                        } elseif ($inMetadata && $result['name'] === '') {
                            $result['name'] = $txt;
                        }
                        break;

                    case 'desc':
                    case 'cmt':
                        // Only a waypoint description is kept, so only that one
                        // is validated: a track-level <desc> is discarded, and
                        // failing the upload over its contents helps nobody.
                        if ($curWpt !== null && ($curWpt['desc'] ?? '') === '') {
                            $curWpt['desc'] = sanitise_text(clean_text($readText($reader)), 'description');
                        } else {
                            $readText($reader);
                        }
                        break;

                    case 'type':
                        $txt = clean_text($readText($reader));
                        if ($txt !== '' && $result['type'] === ''
                            && preg_match('/^[\p{L}\p{N} _-]{1,40}$/u', $txt)) {
                            $result['type'] = $txt;    // pattern already excludes URLs and markup
                        }
                        break;

                    case 'time':
                        $ts = parse_time($readText($reader));
                        if ($curPt !== null) {
                            $curPt['time'] = $ts;
                        } elseif ($curWpt !== null) {
                            $curWpt['time'] = $ts;
                        } elseif ($inMetadata && $result['metaTime'] === null) {
                            $result['metaTime'] = $ts;
                        }
                        break;

                    case 'ele':
                        $raw = trim($readText($reader));
                        $ele = is_numeric($raw) ? (float)$raw : null;
                        if ($ele !== null && ($ele < -12000 || $ele > 12000 || !is_finite($ele))) {
                            $ele = null;
                        }
                        if ($curPt !== null) {
                            $curPt['ele'] = $ele;
                        } elseif ($curWpt !== null) {
                            $curWpt['ele'] = $ele;
                        }
                        break;

                    case 'trk':
                    case 'rte':
                        if (++$trackCount > MAX_TRACK_ELEMENTS) {
                            throw new GpxRejected('Too many tracks in one file (limit ' . MAX_TRACK_ELEMENTS . ')');
                        }
                        $inTrk = true;
                        $curTrack = ['name' => '', 'segments' => []];
                        if ($local === 'rte') {
                            $curSeg = [];
                            $rteAsTrack = true;
                        }
                        break;

                    case 'trkseg':
                        if (++$segCount > MAX_SEGMENTS) {
                            throw new GpxRejected('Too many track segments (limit ' . MAX_SEGMENTS . ')');
                        }
                        $curSeg = [];
                        break;

                    case 'trkpt':
                    case 'rtept':
                    case 'wpt':
                        $lat = $reader->getAttribute('lat');
                        $lon = $reader->getAttribute('lon');
                        if (!is_numeric($lat) || !is_numeric($lon)) {
                            throw new GpxRejected('Point without valid lat/lon');
                        }
                        $lat = (float)$lat;
                        $lon = (float)$lon;
                        if (!is_finite($lat) || !is_finite($lon)
                            || $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
                            throw new GpxRejected('Coordinates out of range');
                        }
                        $node = ['lat' => $lat, 'lon' => $lon, 'ele' => null, 'time' => null];
                        if ($local === 'wpt') {
                            $node['name'] = '';
                            $node['desc'] = '';
                            $curWpt = $node;
                            $ptContext = 'wpt';
                            if ($reader->isEmptyElement) {
                                if (count($result['waypoints']) < MAX_WAYPOINTS) {
                                    $result['waypoints'][] = $curWpt;
                                }
                                $curWpt = null;
                                $ptContext = null;
                            }
                        } else {
                            $curPt = $node;
                            $ptContext = 'trkpt';
                            $depthOfPt = $reader->depth;
                            if (++$pointCount > MAX_POINTS) {
                                throw new GpxRejected('Too many track points (limit ' . MAX_POINTS . ')');
                            }
                            if ($reader->isEmptyElement) {
                                if ($curSeg === null) {
                                    $curSeg = [];
                                }
                                $curSeg[] = $curPt;
                                $curPt = null;
                                $ptContext = null;
                            }
                        }
                        break;

                    default:
                        // Everything else (extensions, links, sat, hdop, …) is skipped.
                        break;
                }
                continue;
            }

            if ($reader->nodeType === XMLReader::END_ELEMENT) {
                switch ($local) {
                    case 'metadata':
                        $inMetadata = false;
                        break;
                    case 'trkpt':
                    case 'rtept':
                        if ($curPt !== null) {
                            if ($curSeg === null) {
                                $curSeg = [];
                            }
                            $curSeg[] = $curPt;
                            $curPt = null;
                            $ptContext = null;
                        }
                        break;
                    case 'wpt':
                        if ($curWpt !== null) {
                            if (count($result['waypoints']) < MAX_WAYPOINTS) {
                                $result['waypoints'][] = $curWpt;
                            }
                            $curWpt = null;
                            $ptContext = null;
                        }
                        break;
                    case 'trkseg':
                        if ($curTrack !== null && !empty($curSeg)) {
                            $curTrack['segments'][] = $curSeg;
                        }
                        $curSeg = null;
                        break;
                    case 'trk':
                    case 'rte':
                        if ($curTrack !== null) {
                            if (!empty($curSeg)) {
                                $curTrack['segments'][] = $curSeg;
                                $curSeg = null;
                            }
                            if (!empty($curTrack['segments'])) {
                                $result['tracks'][] = $curTrack;
                            }
                        }
                        $curTrack = null;
                        $inTrk = false;
                        break;
                }
            }
        }
    } finally {
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $reader->close();
    }

    foreach ($errors as $err) {
        if ($err->level === LIBXML_ERR_FATAL) {
            throw new GpxRejected('Malformed XML: ' . trim($err->message));
        }
    }

    if (empty($result['tracks'])) {
        throw new GpxRejected('No track or route points found');
    }
    $total = 0;
    foreach ($result['tracks'] as $t) {
        foreach ($t['segments'] as $s) {
            $total += count($s);
        }
    }
    if ($total < 2) {
        throw new GpxRejected('Track needs at least 2 points');
    }
    unset($ptContext, $depthOfPt, $inTrk, $rteAsTrack);
    return $result;
}

/** Re-serialise the whitelisted data as a minimal, clean GPX 1.1 document. */
function gpx_rebuild(array $data, string $name, ?int $timestamp): string
{
    $w = new XMLWriter();
    $w->openMemory();
    // No indentation. It is roughly 30 bytes per point of leading whitespace in
    // a file that is read by software, and it is a fifth of the stored size.
    $w->setIndent(false);
    $w->startDocument('1.0', 'UTF-8');
    $w->startElement('gpx');
    $w->writeAttribute('version', '1.1');
    $w->writeAttribute('creator', 'gpx-map');
    $w->writeAttribute('xmlns', 'http://www.topografix.com/GPX/1/1');

    $w->startElement('metadata');
    if ($name !== '') {
        $w->writeElement('name', $name);
    }
    $w->writeElement('time', gmdate('Y-m-d\TH:i:s\Z', $timestamp ?? time()));
    $w->endElement();

    foreach ($data['waypoints'] as $wp) {
        $w->startElement('wpt');
        $w->writeAttribute('lat', fmt_coord($wp['lat']));
        $w->writeAttribute('lon', fmt_coord($wp['lon']));
        if ($wp['ele'] !== null) {
            $w->writeElement('ele', fmt_ele($wp['ele']));
        }
        if ($wp['time'] !== null) {
            $w->writeElement('time', gmdate('Y-m-d\TH:i:s\Z', $wp['time']));
        }
        if (($wp['name'] ?? '') !== '') {
            $w->writeElement('name', $wp['name']);
        }
        if (($wp['desc'] ?? '') !== '') {
            $w->writeElement('desc', $wp['desc']);
        }
        $w->endElement();
    }

    $first = true;
    foreach ($data['tracks'] as $trk) {
        $w->startElement('trk');
        // Only fall back to the document name for the first track: repeating a
        // 200-character name (and the type) in every one of thousands of <trk>
        // elements is how a small upload turns into a huge stored file.
        $tname = $trk['name'] !== '' ? $trk['name'] : ($first ? $name : '');
        if ($tname !== '') {
            $w->writeElement('name', $tname);
        }
        if ($first && ($data['type'] ?? '') !== '') {
            $w->writeElement('type', $data['type']);
        }
        $first = false;
        foreach ($trk['segments'] as $seg) {
            $w->startElement('trkseg');
            foreach ($seg as $pt) {
                $w->startElement('trkpt');
                $w->writeAttribute('lat', fmt_coord($pt['lat']));
                $w->writeAttribute('lon', fmt_coord($pt['lon']));
                if ($pt['ele'] !== null) {
                    $w->writeElement('ele', fmt_ele($pt['ele']));
                }
                if ($pt['time'] !== null) {
                    $w->writeElement('time', gmdate('Y-m-d\TH:i:s\Z', $pt['time']));
                }
                $w->endElement();
            }
            $w->endElement();
        }
        $w->endElement();
    }

    $w->endElement();
    $w->endDocument();
    return $w->outputMemory();
}

/**
 * Six decimal places: about 11 cm, which is far finer than any GPS receiver.
 *
 * Five was tried and is wrong. Its grid is 1.1 m, but a track recorded at 1 Hz
 * on foot has points about 0.85 m apart — below the grid — so rounding turns a
 * smooth line into a staircase and *inflates* the measured distance by 7% on a
 * real 8.8 km walk. Six costs 0.17%, which is 15 m over the same track.
 */
function fmt_coord(float $v): string
{
    return rtrim(rtrim(number_format($v, 6, '.', ''), '0'), '.');
}

/** One decimal. GPS altitude is not accurate to the centimetre. */
function fmt_ele(float $v): string
{
    return rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');
}

/* ------------------------------------------------------------------ *
 * Statistics
 * ------------------------------------------------------------------ */

function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $r = 6371008.8;
    $p1 = deg2rad($lat1);
    $p2 = deg2rad($lat2);
    $dp = deg2rad($lat2 - $lat1);
    $dl = deg2rad($lon2 - $lon1);
    $a = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;
    return 2 * $r * asin(min(1.0, sqrt($a)));
}

/**
 * Distance, moving/elapsed time, elevation spread and gain.
 * Elevation gain uses a 5 m hysteresis so GPS noise is not counted as climb.
 */
function gpx_stats(array $data): array
{
    $dist = 0.0;
    $points = 0;
    $tMin = null;
    $tMax = null;
    $eleMin = null;
    $eleMax = null;
    $gain = 0.0;
    $loss = 0.0;
    $ref = null;
    $moving = 0;
    $latMin = 90.0;
    $latMax = -90.0;
    $lonMin = 180.0;
    $lonMax = -180.0;

    foreach ($data['tracks'] as $trk) {
        foreach ($trk['segments'] as $seg) {
            $prev = null;
            foreach ($seg as $pt) {
                $points++;
                $latMin = min($latMin, $pt['lat']);
                $latMax = max($latMax, $pt['lat']);
                $lonMin = min($lonMin, $pt['lon']);
                $lonMax = max($lonMax, $pt['lon']);

                if ($pt['ele'] !== null) {
                    $eleMin = $eleMin === null ? $pt['ele'] : min($eleMin, $pt['ele']);
                    $eleMax = $eleMax === null ? $pt['ele'] : max($eleMax, $pt['ele']);
                    if ($ref === null) {
                        $ref = $pt['ele'];
                    } elseif ($pt['ele'] - $ref >= 5.0) {
                        $gain += $pt['ele'] - $ref;
                        $ref = $pt['ele'];
                    } elseif ($ref - $pt['ele'] >= 5.0) {
                        $loss += $ref - $pt['ele'];
                        $ref = $pt['ele'];
                    }
                }
                if ($pt['time'] !== null) {
                    $tMin = $tMin === null ? $pt['time'] : min($tMin, $pt['time']);
                    $tMax = $tMax === null ? $pt['time'] : max($tMax, $pt['time']);
                }
                if ($prev !== null) {
                    $d  = haversine($prev['lat'], $prev['lon'], $pt['lat'], $pt['lon']);
                    $dt = ($prev['time'] !== null && $pt['time'] !== null) ? $pt['time'] - $prev['time'] : null;

                    // A leg only counts if it is physically plausible. With
                    // timestamps that means under 250 km/h; without them (routes
                    // are legitimately sparse) only a wild jump is dropped.
                    $plausible = ($dt !== null && $dt > 0)
                        ? ($d <= 500 || ($d / $dt) <= 70)
                        : ($d <= 100000);

                    if ($plausible) {
                        $dist += $d;
                        if ($dt !== null && $dt > 0 && $dt <= 120 && $d >= 0.7) {
                            $moving += $dt;
                        }
                    }
                }
                $prev = $pt;
            }
        }
    }

    return [
        'distance_m'  => round($dist, 1),
        'points'      => $points,
        'start_time'  => $tMin !== null ? gmdate('c', $tMin) : null,
        'end_time'    => $tMax !== null ? gmdate('c', $tMax) : null,
        'duration_s'  => ($tMin !== null && $tMax !== null) ? max(0, $tMax - $tMin) : null,
        'moving_s'    => $moving > 0 ? $moving : null,
        'ele_min'     => $eleMin !== null ? round($eleMin, 1) : null,
        'ele_max'     => $eleMax !== null ? round($eleMax, 1) : null,
        'ele_spread'  => ($eleMin !== null && $eleMax !== null) ? round($eleMax - $eleMin, 1) : null,
        'ele_gain'    => $eleMin !== null ? round($gain) : null,
        'ele_loss'    => $eleMin !== null ? round($loss) : null,
        'bounds'      => $points > 0 ? [[$latMin, $lonMin], [$latMax, $lonMax]] : null,
    ];
}

/**
 * Full ingest pipeline: raw bytes -> stored track. Returns the metadata record.
 *
 * $source is 'web' or 'cli' and is recorded in the private upload log only.
 *
 * @throws GpxRejected on any validation failure
 */
function ingest_gpx(string $raw, string $originalName, string $source): array
{
    ensure_dirs();

    $data  = gpx_parse($raw);
    $stats = gpx_stats($data);

    // Title: GPX metadata name, else first track name, else the file name.
    $name = $data['name'];
    if ($name === '' && !empty($data['tracks'][0]['name'])) {
        $name = $data['tracks'][0]['name'];
    }
    if ($name === '') {
        $base = preg_replace('/\.gpx$/i', '', basename($originalName));
        $name = clean_text(is_string($base) ? $base : '');
    }
    if ($name === '') {
        $name = 'Track ' . gmdate('Y-m-d H:i');
    }
    $name = sanitise_text($name, 'track name');
    if ($name === '') {
        $name = 'Track ' . gmdate('Y-m-d H:i');
    }

    $ts    = $stats['start_time'] !== null ? strtotime($stats['start_time']) : ($data['metaTime'] ?? time());
    $activity = $data['type'] ?? '';
    $clean = gpx_rebuild($data, $name, $ts ?: time());

    // The parsed structure costs roughly 400 bytes per point; releasing it here
    // halves peak memory, which is what previously turned a large-but-legal
    // upload into an uncatchable OOM part way through verification.
    unset($data);

    if (strlen($clean) > MAX_STORED_BYTES) {
        throw new GpxRejected('Track expands to more than '
            . (int)(MAX_STORED_BYTES / 1048576) . ' MB when normalised');
    }

    // The document about to be stored must itself parse, and must contain
    // nothing but the whitelist. Verified by a streaming pass that keeps no
    // data, so it costs a few kilobytes rather than a second copy of the track.
    gpx_verify_clean($clean);

    $digest = hash('sha256', $clean);

    // Everything from here — duplicate check, both writes, the catalogue —
    // happens under one lock. Without it, concurrent uploads store duplicates
    // and the index rebuild loses whichever track was written last.
    $lock = store_lock();
    try {
        if (count(glob(META_DIR . '/*.json') ?: []) >= MAX_TRACKS_STORED) {
            throw new GpxRejected('Track library is full');
        }
        foreach (store_list() as $existing) {
            if (hash_equals((string)($existing['sha256'] ?? ''), $digest)) {
                throw new GpxRejected('This track is already in the collection ("'
                    . ($existing['name'] ?? $existing['id']) . '")');
            }
        }

        $id = make_id($name);
        while (file_exists(gpx_path($id)) || file_exists(meta_path($id))) {
            $id = make_id($name);
        }

        if (!atomic_write(gpx_path($id), $clean, 0644)) {
            throw new RuntimeException('Could not store GPX file');
        }

        // The metadata is world-readable, so it deliberately records nothing
        // about the upload itself — that goes in the private upload log.
        $meta = [
            'id'          => $id,
            'name'        => $name,
            'file'        => 'data/gpx/' . $id . '.gpx',
            'uploaded_at' => gmdate('c'),
            'date'        => $stats['start_time'] ?? gmdate('c', $ts ?: time()),
            'activity'    => $activity,
            'bytes'       => strlen($clean),
            'sha256'      => $digest,
            // Stamp what wrote it, so a later format change can find the
            // tracks that predate it — and, just as importantly, leave alone
            // the ones that do not.
            'fmt'         => GPX_FORMAT,
        ] + $stats;

        $json = json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false || !atomic_write(meta_path($id), $json)) {
            @unlink(gpx_path($id));
            throw new RuntimeException('Could not store track metadata');
        }

        upload_log($source, $id, strlen($clean));
        store_reindex(true);
    } finally {
        store_unlock($lock);
    }

    return $meta;
}

/* ------------------------------------------------------------------ *
 * Editing the library
 * ------------------------------------------------------------------ */

/**
 * Delete a track: both its files, then the catalogue. Under the store lock, so
 * it cannot interleave with an upload's write-then-reindex and leave the
 * catalogue describing a file that is no longer there.
 */
function store_delete(string $id): bool
{
    if (!valid_id($id)) {
        throw new InvalidArgumentException('Invalid track id');
    }
    $lock = store_lock();
    try {
        $gone = false;
        foreach ([gpx_path($id), meta_path($id)] as $p) {
            if (is_file($p) && @unlink($p)) {
                $gone = true;
            }
        }
        store_reindex(true);
        return $gone;
    } finally {
        store_unlock($lock);
    }
}

/**
 * The stored-file format. Bumped when gpx_rebuild() starts producing different
 * bytes for the same input, so existing tracks can be brought up to date
 * without guessing which ones need it.
 *
 *   1  indented, 7-decimal coordinates, 2-decimal elevation
 *   2  no indentation, 6 decimals, 1-decimal elevation  (about 27% smaller)
 */
const GPX_FORMAT = 2;

/** @return array<string> ids of tracks stored in an older format */
function store_stale_ids(): array
{
    ensure_dirs();
    $out = [];
    foreach (glob(META_DIR . '/*.json') ?: [] as $file) {
        $id = basename($file, '.json');
        if (!valid_id($id) || !is_file(gpx_path($id))) {
            continue;
        }
        $meta = json_decode((string)file_get_contents($file), true);
        if (!is_array($meta)) {
            continue;
        }
        if ((int)($meta['fmt'] ?? 1) < GPX_FORMAT) {
            $out[] = $id;
        }
    }
    return $out;
}

/**
 * Rewrite one stored track in the current format.
 *
 * The file is re-parsed and re-serialised by the same pair of functions an
 * upload goes through, so this cannot introduce anything a fresh upload would
 * have rejected. Only the bytes change: the name, the dates and every computed
 * statistic are left exactly as they are, because they were derived from the
 * original full-precision data and re-deriving them now would be a downgrade.
 *
 * @throws GpxRejected
 */
function store_recompact(string $id): array
{
    if (!valid_id($id)) {
        throw new InvalidArgumentException('Invalid track id');
    }
    $lock = store_lock();
    try {
        $metaFile = meta_path($id);
        $gpxFile  = gpx_path($id);
        if (!is_file($metaFile) || !is_file($gpxFile)) {
            throw new GpxRejected('No such track');
        }
        $meta = json_decode((string)file_get_contents($metaFile), true);
        if (!is_array($meta) || ($meta['id'] ?? '') !== $id) {
            throw new GpxRejected('Track metadata is unreadable');
        }

        $raw = @file_get_contents($gpxFile, false, null, 0, MAX_STORED_BYTES + 1);
        if ($raw === false || strlen($raw) > MAX_STORED_BYTES) {
            throw new GpxRejected('Could not read the stored track');
        }
        $data = gpx_parse($raw);
        $name = (string)($meta['name'] ?? '');
        if ($name !== '' && isset($data['tracks'][0]) && is_array($data['tracks'][0])) {
            $data['tracks'][0]['name'] = $name;
        }
        $ts = $data['metaTime'] ?? null;
        if ($ts === null && !empty($meta['date'])) {
            $ts = strtotime((string)$meta['date']) ?: null;
        }
        $clean = gpx_rebuild($data, $name, $ts);
        unset($data, $raw);

        if (strlen($clean) > MAX_STORED_BYTES) {
            throw new GpxRejected('Rebuilt track is too large to store');
        }
        gpx_verify_clean($clean);

        if (!atomic_write($gpxFile, $clean, 0644)) {
            throw new RuntimeException('Could not rewrite the track file');
        }
        $meta['bytes']  = strlen($clean);
        $meta['sha256'] = hash('sha256', $clean);
        $meta['fmt']    = GPX_FORMAT;

        $json = json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false || !atomic_write($metaFile, $json)) {
            throw new RuntimeException('Could not write the track metadata');
        }
        store_reindex(true);
        return $meta;
    } finally {
        store_unlock($lock);
    }
}

/** Tags and countries are free text, but short, few, and never markup. */
const MAX_TAGS     = 12;
const MAX_TAG_LEN  = 30;
const MAX_COUNTRY_LEN = 60;

/**
 * @param string|array $raw comma-separated or already a list
 * @return array<string> cleaned, de-duplicated case-insensitively, order kept
 * @throws GpxRejected
 */
function clean_tags($raw): array
{
    $items = is_array($raw) ? $raw : explode(',', (string)$raw);
    $out = [];
    $seen = [];
    foreach ($items as $item) {
        if (!is_string($item)) {
            continue;
        }
        $t = sanitise_text(clean_text($item), 'tag');
        if ($t === '') {
            continue;
        }
        if (mb_strlen($t, 'UTF-8') > MAX_TAG_LEN) {
            $t = mb_substr($t, 0, MAX_TAG_LEN, 'UTF-8');
        }
        $key = mb_strtolower($t, 'UTF-8');
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = $t;
        if (count($out) >= MAX_TAGS) {
            break;
        }
    }
    return $out;
}

/**
 * Edit a track's metadata: any of name, date, country, tags. Only the keys
 * present in $fields are touched, so a caller can change one thing without
 * having to resend the rest.
 *
 * The date is stored with a `date_manual` flag. Without it the display layer
 * would keep preferring a date parsed out of the title, and a date you set by
 * hand would appear to be ignored.
 *
 * @throws GpxRejected on unusable input
 */
function store_update(string $id, array $fields): array
{
    if (!valid_id($id)) {
        throw new InvalidArgumentException('Invalid track id');
    }

    // A name change rewrites the GPX itself, so it goes through the existing
    // path first and under its own lock; the rest is metadata only.
    if (array_key_exists('name', $fields)) {
        store_rename($id, (string)$fields['name']);
    }

    $lock = store_lock();
    try {
        $metaFile = meta_path($id);
        if (!is_file($metaFile) || !is_file(gpx_path($id))) {
            throw new GpxRejected('No such track');
        }
        $meta = json_decode((string)file_get_contents($metaFile), true);
        if (!is_array($meta) || ($meta['id'] ?? '') !== $id) {
            throw new GpxRejected('Track metadata is unreadable');
        }

        if (array_key_exists('date', $fields)) {
            $d = trim((string)$fields['date']);
            if ($d === '') {
                // Cleared: fall back to whatever the file itself says.
                unset($meta['date_manual']);
                if (!empty($meta['start_time'])) {
                    $meta['date'] = $meta['start_time'];
                }
            } else {
                if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)
                    || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
                    throw new GpxRejected('Date must be a real date, as YYYY-MM-DD');
                }
                if ((int)$m[1] < 1990 || (int)$m[1] > 2100) {
                    throw new GpxRejected('Date is out of range');
                }
                // Midday UTC, so rendering in any timezone keeps the same day.
                $meta['date'] = $d . 'T12:00:00+00:00';
                $meta['date_manual'] = true;
            }
        }

        if (array_key_exists('country', $fields)) {
            $c = sanitise_text(clean_text((string)$fields['country']), 'country');
            if (mb_strlen($c, 'UTF-8') > MAX_COUNTRY_LEN) {
                $c = mb_substr($c, 0, MAX_COUNTRY_LEN, 'UTF-8');
            }
            if ($c === '') {
                unset($meta['country']);
            } else {
                $meta['country'] = $c;
            }
        }

        if (array_key_exists('tags', $fields)) {
            $tags = clean_tags($fields['tags']);
            if ($tags === []) {
                unset($meta['tags']);
            } else {
                $meta['tags'] = $tags;
            }
        }

        $json = json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false || !atomic_write($metaFile, $json)) {
            throw new RuntimeException('Could not write the track metadata');
        }
        store_reindex(true);
        return $meta;
    } finally {
        store_unlock($lock);
    }
}

/**
 * Rename a track.
 *
 * The stored GPX is rewritten too, not just the metadata record: the name lives
 * inside the file as well, and leaving the two disagreeing means a download
 * whose contents contradict the page it came from. The file is re-parsed and
 * re-serialised by the same pair of functions an upload goes through, so a
 * rename cannot smuggle anything into a document that was already clean — and
 * because the bytes change, the stored checksum and size are updated with them.
 *
 * @throws GpxRejected if the new name is unusable or the rewrite fails checks
 */
function store_rename(string $id, string $name): array
{
    if (!valid_id($id)) {
        throw new InvalidArgumentException('Invalid track id');
    }
    $name = sanitise_text(clean_text($name), 'track name');
    if ($name === '') {
        throw new GpxRejected('That name is empty once cleaned up');
    }

    $lock = store_lock();
    try {
        $metaFile = meta_path($id);
        $gpxFile  = gpx_path($id);
        if (!is_file($metaFile) || !is_file($gpxFile)) {
            throw new GpxRejected('No such track');
        }
        $meta = json_decode((string)file_get_contents($metaFile), true);
        if (!is_array($meta) || ($meta['id'] ?? '') !== $id) {
            throw new GpxRejected('Track metadata is unreadable');
        }
        if (($meta['name'] ?? '') === $name) {
            return $meta;                       // nothing to do
        }

        $raw = @file_get_contents($gpxFile, false, null, 0, MAX_STORED_BYTES + 1);
        if ($raw === false || strlen($raw) > MAX_STORED_BYTES) {
            throw new GpxRejected('Could not read the stored track');
        }
        $data = gpx_parse($raw);
        // gpx_rebuild() writes the new title into <metadata><name>, but the
        // first <trk><name> comes from the parsed data and would keep the old
        // one — leaving two different names inside a single file, and the one
        // most GPS software displays would be the stale one. The title is
        // derived from metadata-name-else-first-track-name, so setting both is
        // what keeps the file self-consistent.
        if (isset($data['tracks'][0]) && is_array($data['tracks'][0])) {
            $data['tracks'][0]['name'] = $name;
        }
        $ts   = isset($meta['date']) ? strtotime((string)$meta['date']) : null;
        $clean = gpx_rebuild($data, $name, $ts ?: null);
        unset($data, $raw);

        if (strlen($clean) > MAX_STORED_BYTES) {
            throw new GpxRejected('Renamed track is too large to store');
        }
        gpx_verify_clean($clean);

        if (!atomic_write($gpxFile, $clean, 0644)) {
            throw new RuntimeException('Could not rewrite the track file');
        }
        $meta['name']   = $name;
        $meta['bytes']  = strlen($clean);
        $meta['sha256'] = hash('sha256', $clean);

        $json = json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false || !atomic_write($metaFile, $json)) {
            throw new RuntimeException('Could not write the track metadata');
        }
        store_reindex(true);
        return $meta;
    } finally {
        store_unlock($lock);
    }
}

/* ------------------------------------------------------------------ *
 * Drop folder
 *
 * Files put into data/incoming/ by hand are adopted one per request: each is
 * parsed, rebuilt and stored by exactly the same ingest_gpx() an upload uses,
 * so a hand-placed file gets no more trust than one that arrived over HTTP.
 *
 * The caller never names the file it wants. It asks for "the next one" and the
 * server chooses, so no filename ever crosses the trust boundary and there is
 * nothing to escape from.
 * ------------------------------------------------------------------ */

/** Pending files, oldest name first. Capped: this is a status display. */
function incoming_list(int $limit = 500): array
{
    ensure_dirs();
    $out = [];
    foreach (glob(INCOMING_DIR . '/*.gpx') ?: [] as $path) {
        if (!is_file($path)) {
            continue;
        }
        $out[] = basename($path);
        if (count($out) >= $limit) {
            break;
        }
    }
    sort($out, SORT_STRING);
    return $out;
}

function incoming_count(): int
{
    return count(incoming_list());
}

/**
 * Adopt the next pending file. Returns the stored metadata on success.
 *
 * A file that cannot be ingested is renamed to <name>.rejected rather than left
 * in place: the queue is drained by repeated calls, so a file that fails every
 * time would otherwise be picked forever and the loop would never finish. The
 * suffix also takes it out of the *.gpx glob, so it is skipped from then on.
 *
 * @throws GpxRejected with the reason a file was refused
 * @throws RuntimeException if there is nothing to do
 */
function incoming_adopt_next(): array
{
    $pending = incoming_list(1);
    if ($pending === []) {
        throw new RuntimeException('Nothing pending');
    }
    $name = $pending[0];
    $path = INCOMING_DIR . '/' . $name;

    // The name came from our own glob, never from the request, but confirm the
    // resolved file really is inside the drop folder before reading it — a
    // symlink planted there would otherwise be followed straight out.
    $real = realpath($path);
    $base = realpath(INCOMING_DIR);
    if ($real === false || $base === false
        || strncmp($real, $base . DIRECTORY_SEPARATOR, strlen($base) + 1) !== 0
        || !is_file($real) || is_link($path)) {
        @rename($path, $path . '.rejected');
        throw new GpxRejected('Not a regular file inside the drop folder');
    }

    $size = (int)@filesize($real);
    if ($size <= 0) {
        @rename($real, $real . '.rejected');
        throw new GpxRejected('Empty file');
    }
    if ($size > MAX_UPLOAD_BYTES) {
        @rename($real, $real . '.rejected');
        throw new GpxRejected('Larger than ' . (int)(MAX_UPLOAD_BYTES / 1048576) . ' MB');
    }

    $raw = @file_get_contents($real, false, null, 0, MAX_UPLOAD_BYTES + 1);
    if ($raw === false || strlen($raw) > MAX_UPLOAD_BYTES) {
        @rename($real, $real . '.rejected');
        throw new GpxRejected('Could not read the file');
    }

    try {
        $meta = ingest_gpx($raw, $name, 'incoming');
    } catch (Throwable $e) {
        @rename($real, $real . '.rejected');
        throw $e;
    }

    // Stored successfully: the original is now redundant, and the sanitised
    // rebuild in data/gpx/ is the copy of record.
    @unlink($real);
    return $meta;
}

/**
 * Private, size-capped record of what was added, from where and when. Never
 * served (see the .htaccess in data/auth), and trimmed rather than grown
 * without limit.
 */
function upload_log(string $source, string $id, int $bytes): void
{
    $file = AUTH_DIR . '/uploads.log';
    if (is_file($file) && (int)@filesize($file) > UPLOAD_LOG_MAX) {
        $keep = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        atomic_write($file, implode("\n", array_slice($keep, -2000)) . "\n", 0600);
    }
    @file_put_contents(
        $file,
        sprintf("%s\t%s\t%s\t%s\t%d\n", gmdate('c'), $source, client_ip(), $id, $bytes),
        FILE_APPEND | LOCK_EX
    );
    @chmod($file, 0600);
}

/**
 * Streaming well-formedness and whitelist check for a document this code just
 * generated. Keeps nothing, so it is safe to run on a 25 MB file.
 *
 * @throws GpxRejected
 */
function gpx_verify_clean(string $xml): void
{
    $allowed = [
        'gpx' => 1, 'metadata' => 1, 'name' => 1, 'desc' => 1, 'time' => 1,
        'trk' => 1, 'trkseg' => 1, 'trkpt' => 1, 'wpt' => 1, 'ele' => 1, 'type' => 1,
    ];
    $prev = libxml_use_internal_errors(true);
    libxml_clear_errors();
    $reader = new XMLReader();
    if (!$reader->XML($xml, 'UTF-8', LIBXML_NONET | LIBXML_COMPACT)) {
        libxml_use_internal_errors($prev);
        throw new GpxRejected('Rebuilt document is not well-formed');
    }
    @$reader->setParserProperty(XMLReader::LOADDTD, false);
    @$reader->setParserProperty(XMLReader::SUBST_ENTITIES, false);
    try {
        while (@$reader->read()) {
            if ($reader->nodeType === XMLReader::DOC_TYPE
                || $reader->nodeType === XMLReader::ENTITY_REF
                || $reader->nodeType === XMLReader::PI) {
                throw new GpxRejected('Rebuilt document contains a forbidden node');
            }
            if ($reader->nodeType === XMLReader::ELEMENT && !isset($allowed[$reader->localName])) {
                throw new GpxRejected('Rebuilt document contains an unexpected element');
            }
        }
    } finally {
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $reader->close();
    }
    foreach ($errors as $err) {
        if ($err->level === LIBXML_ERR_FATAL) {
            throw new GpxRejected('Rebuilt document is malformed: ' . trim($err->message));
        }
    }
}
