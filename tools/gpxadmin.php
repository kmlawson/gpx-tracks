<?php
/**
 * Command line administration (CLI only — refuses to run over HTTP).
 *
 * Run it as `php tools/gpxadmin.php ...`. There is deliberately no shebang: a
 * shebang line is only stripped by the CLI SAPI, so over HTTP it would push
 * `declare(strict_types=1)` off the first statement, the file would fail to
 * compile, and the CLI guard below would never run — leaking the absolute path
 * and PHP version in the fatal error instead.
 *
 *   php tools/gpxadmin.php passwd                  write api/credentials.php
 *   php tools/gpxadmin.php import <file.gpx> [...] ingest files through the same
 *                                                  validation pipeline as uploads
 *   php tools/gpxadmin.php list                    show the library
 *   php tools/gpxadmin.php remove <id>             delete a track
 *   php tools/gpxadmin.php reindex                 rebuild data/index.json
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require __DIR__ . '/../api/lib.php';

$argvLocal = $argv ?? [];
$cmd = $argvLocal[1] ?? '';
$args = array_slice($argvLocal, 2);

function out(string $s): void { fwrite(STDOUT, $s . "\n"); }
function err(string $s): never { fwrite(STDERR, $s . "\n"); exit(1); }

ensure_dirs();

switch ($cmd) {
    case 'passwd':
        // Never accept the password as an argument: it would be visible in
        // `ps`, in /proc/*/cmdline and in the shell history of every user on
        // the machine. Type it, pipe it, or pass it in the environment.
        if ($args) {
            err("passwd takes no arguments — there is one password and no username.\n"
                . "Never put the password itself on the command line; it is visible in `ps`.\n"
                . "Type it when prompted, pipe it in (echo … | php tools/gpxadmin.php passwd),\n"
                . "or set GPX_PASSWORD in the environment.");
        }

        $envPass = getenv('GPX_PASSWORD');
        if ($envPass !== false && $envPass !== '') {
            $pass = $envPass;
        } elseif (stream_isatty(STDIN)) {
            echo "Password (min 12 chars): ";
            $restore = static function (): void { @shell_exec('stty echo 2>/dev/null'); };
            register_shutdown_function($restore);          // never leave the terminal mute
            pcntl_async_signals(true);
            if (function_exists('pcntl_signal')) {
                pcntl_signal(SIGINT, static function () use ($restore) { $restore(); exit(1); });
            }
            @shell_exec('stty -echo 2>/dev/null');
            $pass = (string)fgets(STDIN);
            $restore();
            echo "\n";
        } else {
            $pass = (string)fgets(STDIN);
        }
        // Trim the line ending only: trim() would silently drop a leading or
        // trailing space that is part of the passphrase, and the password would
        // then never verify.
        $pass = rtrim((string)$pass, "\r\n");
        try {
            if (!passwd_set((string)$pass)) {
                err('Could not write ' . PASSWD_FILE);
            }
        } catch (InvalidArgumentException $e) {
            err($e->getMessage());
        }
        out('Wrote the salted hash to ' . PASSWD_FILE);
        out('Upload that file to the server to set or change the password.');
        break;

    case 'import':
        if (!$args) {
            err('Usage: gpxadmin.php import <file.gpx> [...]');
        }
        $ok = 0;
        foreach ($args as $path) {
            if (!is_file($path) || !is_readable($path)) {
                out("SKIP  $path (unreadable)");
                continue;
            }
            if (filesize($path) > MAX_UPLOAD_BYTES) {
                out("SKIP  $path (too large)");
                continue;
            }
            try {
                $meta = ingest_gpx((string)file_get_contents($path), basename($path), 'cli');
                out(sprintf(
                    "OK    %s -> %s  (%.2f km, %s pts)",
                    basename($path),
                    $meta['id'],
                    $meta['distance_m'] / 1000,
                    $meta['points']
                ));
                $ok++;
            } catch (Throwable $e) {
                out('FAIL  ' . basename($path) . ': ' . $e->getMessage());
            }
        }
        out("Imported $ok file(s).");
        break;

    case 'list':
        foreach (store_list() as $t) {
            out(sprintf(
                "%-28s %-10s %7.2f km  %s",
                $t['id'],
                substr((string)($t['date'] ?? ''), 0, 10),
                ($t['distance_m'] ?? 0) / 1000,
                $t['name'] ?? ''
            ));
        }
        break;

    case 'remove':
        $id = $args[0] ?? '';
        if (!valid_id($id)) {
            err('Invalid id');
        }
        $gone = false;
        foreach ([gpx_path($id), meta_path($id)] as $p) {
            if (is_file($p)) {
                unlink($p);
                $gone = true;
            }
        }
        store_reindex();
        out($gone ? "Removed $id" : "Nothing to remove for $id");
        break;

    case 'reindex':
        // Backfill checksums for tracks stored before duplicate detection existed.
        foreach (store_list() as $t0) {
            if (!empty($t0['sha256']) || !is_file(gpx_path($t0['id']))) {
                continue;
            }
            $t0['sha256'] = hash_file('sha256', gpx_path($t0['id']));
            atomic_write(meta_path($t0['id']), json_encode($t0, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
        $t = store_reindex();
        out('Reindexed ' . count($t) . ' track(s) into ' . INDEX_FILE);
        break;

    default:
        out(<<<TXT
        Usage:
          php tools/gpxadmin.php passwd                     write api/credentials.php
                                                            (typed, piped, or \$GPX_PASSWORD)
          php tools/gpxadmin.php import <file.gpx> [...]    ingest local files
          php tools/gpxadmin.php list                       list stored tracks
          php tools/gpxadmin.php remove <id>                delete a track
          php tools/gpxadmin.php reindex                    rebuild data/index.json
        TXT);
        exit($cmd === '' ? 0 : 1);
}
