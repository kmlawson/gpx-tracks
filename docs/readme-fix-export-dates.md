# fix-export-dates.php

One-off repair for a bulk upload on **16 August 2026**. Around 70 tracks went in
that day and many were stamped with the date they were *exported* rather than the
date they were walked, so the whole collection appeared to have happened at once.

`scripts/temp-scripts/fix-export-dates.php`

## What it does

For every track whose date is the target day (default `2026-08-16`, any time of
day):

| Case | Result |
|---|---|
| The name contains a `yyyy.mm.dd` date | That becomes the track's date, flagged `date_manual` so nothing re-derives it |
| The name contains no such date | The date is **removed** — the track shows no date at all |

Tracks dated any other day are not touched.

Nothing else changes. `start_time`, the timestamps inside the GPX file,
distance, duration and elevation are all left exactly as they were; only the
catalogue's `date` field moves. The GPX files themselves are never rewritten.

The name is matched by the same rule `assets/app.js` uses to strip dates out of
titles, so a date taken from a name is the one the list was already showing.
`1.2026.08.16.3` is read as a version and ignored; `2024.2.31` is rejected rather
than rolled forward into March.

## Running it

```
php scripts/temp-scripts/fix-export-dates.php --dry-run   # report only
php scripts/temp-scripts/fix-export-dates.php             # apply
php scripts/temp-scripts/fix-export-dates.php --undo      # put the dates back
```

| Flag | |
|---|---|
| `--dry-run`, `-n` | List what would change and write nothing |
| `--undo` | Restore the dates saved by the last run that changed something |
| `--date=YYYY-MM-DD` | Treat a different day as the bad export date |
| `--help`, `-h` | Usage |

Run it where the data is. If `data/` lives on the server, run it there over SSH;
if you keep the library locally and sync it up, run it locally and upload
`data/meta/` and `data/index.json` afterwards. It rebuilds `data/index.json`
itself, so there is no separate `reindex` step.

## Safety

- **Backup.** Every changed track's previous date is written to
  `scripts/temp-scripts/fix-export-dates.backup.json` before anything is
  rebuilt. `--undo` restores from it.
- **Idempotent.** After a run the affected tracks no longer carry the target
  date, so running it again finds nothing and reports `0 track(s)`. A no-op run
  deliberately does *not* overwrite the backup, so an accidental second run
  cannot destroy your ability to undo.
- **Log.** One line per action, with a UTC timestamp, in
  `scripts/temp-scripts/fix-export-dates.log`.

## The one thing to know before running it

A walk that genuinely happened on 16 August 2026 and has no date in its name
**will lose its date too** — the script cannot tell it apart from a bad export
stamp. That is the intended behaviour, but check the `--dry-run` output for
anything you recognise as a real walk from that day before applying. If one slips
through, either `--undo` everything or set that track's date by hand afterwards
(Option-click it in the list).

## Verification

Tested against a fixture library of 7 tracks covering: two names carrying dates,
a name with none, a version-like number that must not be read as a date, a walk
genuinely dated that day, and two tracks dated other days that must not move.
25 checks covering the dry run, the apply, idempotence and undo.
