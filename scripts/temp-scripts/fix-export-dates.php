<?php
/**
 * ONE-OFF FIX (16 August 2026).
 *
 * A bulk upload of ~70 tracks left many of them stamped with the day they were
 * exported rather than the day they were walked. For every track dated that day:
 *
 *   - if its name carries a yyyy.mm.dd date, that becomes the track's date;
 *   - otherwise the date is removed, and the track shows no date at all.
 *
 * The name is matched exactly as the site's own title-date filter matches it, so
 * a date taken from a title is the same one the list was already displaying.
 *
 * Nothing else is touched: start_time, the timestamps inside the GPX, distance
 * and the rest are left alone. Only the catalogue's `date` changes.
 *
 * Safe to re-run: once a track has been fixed it no longer carries the target
 * date, so a second pass finds nothing. Every change is recorded in a backup
 * file and can be put back with --undo.
 *
 *   php scripts/temp-scripts/fix-export-dates.php --dry-run
 *   php scripts/temp-scripts/fix-export-dates.php
 *   php scripts/temp-scripts/fix-export-dates.php --undo
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require __DIR__ . '/../../api/lib.php';

const TARGET_DEFAULT = '2026-08-16';
const BACKUP_FILE    = __DIR__ . '/fix-export-dates.backup.json';
const LOG_FILE       = __DIR__ . '/fix-export-dates.log';

$argvLocal = $argv ?? [];
$opts = [
    'dry'    => in_array('--dry-run', $argvLocal, true) || in_array('-n', $argvLocal, true),
    'undo'   => in_array('--undo', $argvLocal, true),
    'help'   => in_array('--help', $argvLocal, true) || in_array('-h', $argvLocal, true),
    'target' => TARGET_DEFAULT,
];
foreach ($argvLocal as $a) {
    if (str_starts_with($a, '--date=')) {
        $opts['target'] = substr($a, 7);
    }
}

if ($opts['help']) {
    echo <<<TXT
    Fix tracks stamped with their export date instead of the walk date.

      --dry-run, -n     report what would change, write nothing
      --undo            restore the dates saved by the last real run
      --date=YYYY-MM-DD the date to treat as the bad export date
                        (default: 2026-08-16)
      --help, -h        this text

    For each track dated the target day: if its name contains a yyyy.mm.dd date,
    that becomes the track's date; otherwise the date is removed entirely.

    Backup:  scripts/temp-scripts/fix-export-dates.backup.json
    Log:     scripts/temp-scripts/fix-export-dates.log

    TXT;
    exit(0);
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $opts['target'])) {
    fwrite(STDERR, "--date must be YYYY-MM-DD\n");
    exit(1);
}

function logline(string $level, string $msg): void
{
    @file_put_contents(
        LOG_FILE,
        sprintf("%s %-7s %s\n", gmdate('Y-m-d\TH:i:s\Z'), $level, $msg),
        FILE_APPEND | LOCK_EX
    );
}

function out(string $s): void { fwrite(STDOUT, $s . "\n"); }

/*
 * The yyyy.mm.dd rule lives in api/lib.php as meta_title_date(), so this script
 * and the site can never disagree about what counts as a date in a name.
 */

ensure_dirs();

/* ------------------------------------------------------------------ *
 * Undo
 * ------------------------------------------------------------------ */

if ($opts['undo']) {
    if (!is_file(BACKUP_FILE)) {
        fwrite(STDERR, "No backup at " . BACKUP_FILE . "\n");
        exit(1);
    }
    $saved = json_decode((string)file_get_contents(BACKUP_FILE), true);
    if (!is_array($saved) || !$saved) {
        fwrite(STDERR, "Backup is empty or unreadable\n");
        exit(1);
    }
    $back = 0;
    foreach ($saved as $id => $old) {
        $path = meta_path((string)$id);
        if (!is_file($path)) {
            out("SKIP  $id (gone)");
            continue;
        }
        $meta = json_decode((string)file_get_contents($path), true);
        if (!is_array($meta)) {
            out("SKIP  $id (unreadable)");
            continue;
        }
        if (isset($old['date'])) {
            $meta['date'] = $old['date'];
        } else {
            unset($meta['date']);
        }
        if (!empty($old['date_manual'])) {
            $meta['date_manual'] = true;
        } else {
            unset($meta['date_manual']);
        }
        atomic_write($path, (string)json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $back++;
        out("BACK  $id -> " . ($old['date'] ?? '(no date)'));
    }
    store_reindex();
    logline('INFO', "undo restored $back track(s)");
    out("\nRestored $back track(s). Catalogue rebuilt.");
    exit(0);
}

/* ------------------------------------------------------------------ *
 * The fix
 * ------------------------------------------------------------------ */

$files = glob(META_DIR . '/*.json') ?: [];
out(sprintf("Scanning %d track(s) for date %s%s\n", count($files), $opts['target'],
    $opts['dry'] ? '   [DRY RUN — nothing will be written]' : ''));
logline('INFO', sprintf('start target=%s dry=%s tracks=%d',
    $opts['target'], $opts['dry'] ? 'yes' : 'no', count($files)));

$matched = 0; $fromTitle = 0; $cleared = 0; $skipped = 0;
$backup = [];

foreach ($files as $path) {
    $id = basename($path, '.json');
    if (!valid_id($id)) {
        continue;
    }
    $meta = json_decode((string)file_get_contents($path), true);
    if (!is_array($meta) || ($meta['id'] ?? '') !== $id) {
        out("SKIP  $id (metadata unreadable)");
        logline('WARNING', "unreadable metadata: $id");
        $skipped++;
        continue;
    }

    $date = (string)($meta['date'] ?? '');
    if (substr($date, 0, 10) !== $opts['target']) {
        continue;
    }
    $matched++;
    $name = (string)($meta['name'] ?? '');
    $backup[$id] = ['date' => $meta['date'] ?? null, 'date_manual' => !empty($meta['date_manual'])];

    $t = meta_title_date($name);
    if ($t !== null) {
        $iso = sprintf('%04d-%02d-%02dT12:00:00+00:00', $t['y'], $t['m'], $t['d']);
        $meta['date'] = $iso;
        $meta['date_manual'] = true;   // stop anything re-deriving it later
        $fromTitle++;
        out(sprintf("DATE  %-44s %s  (from title)", mb_strimwidth($name, 0, 44, '…'), substr($iso, 0, 10)));
        logline('INFO', "$id date from title: " . substr($iso, 0, 10) . " — $name");
    } else {
        unset($meta['date'], $meta['date_manual']);
        $cleared++;
        out(sprintf("CLEAR %-44s (no date in name)", mb_strimwidth($name, 0, 44, '…')));
        logline('INFO', "$id date cleared — $name");
    }

    if (!$opts['dry']) {
        $json = json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false || !atomic_write($path, $json)) {
            out("FAIL  $id (could not write)");
            logline('ERROR', "could not write $id");
            $skipped++;
        }
    }
}

out('');
out(sprintf('%d track(s) dated %s', $matched, $opts['target']));
out(sprintf('  %d given the date from their name', $fromTitle));
out(sprintf('  %d left with no date', $cleared));
if ($skipped) {
    out(sprintf('  %d skipped (see the log)', $skipped));
}

if ($opts['dry']) {
    out("\nDry run — nothing was written. Re-run without --dry-run to apply.");
    logline('INFO', "dry run finished matched=$matched title=$fromTitle cleared=$cleared");
    exit(0);
}

if ($matched > 0) {
    atomic_write(BACKUP_FILE, (string)json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), 0600);
    out("\nPrevious dates saved to " . BACKUP_FILE);
    out('Undo with:  php ' . basename(__FILE__) . ' --undo');
}

$tracks = store_reindex();
out('Rebuilt the catalogue (' . count($tracks) . ' tracks).');
logline('INFO', "finished matched=$matched title=$fromTitle cleared=$cleared reindexed=" . count($tracks));
