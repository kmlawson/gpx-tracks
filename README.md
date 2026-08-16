# GPX Tracks

A small, self-hosted website for a collection of GPX tracks. Browse the list,
click a track to draw it on the map, read its trip statistics and elevation
profile, and share a link straight to it. Anyone can look and download; adding a
track needs a password.

No database, no build step, no CDN — PHP files, one HTML page, and a local copy
of Leaflet.

---

## What it does

### The map

Four base layers, switchable from the control in the top right:

| Layer | Source | Native detail |
|---|---|---|
| **Terrain** | OpenTopoMap — contours, hillshading, paths | to z17 |
| **Satellite** | Esri World Imagery | to z19 |
| **OSM standard** | openstreetmap.org | to z19 |
| **Light** | Carto Positron — a quiet backdrop | to z19 |

A tile request occasionally stalls: no error, no image, just a white square that
never resolves, because nothing fires to prompt a retry. Every tile carries a
watchdog, cancelled the moment it loads or fails, and is retried up to twice.
The retry uses a slightly different URL by necessity — while a request is
pending, re-assigning the same `src`, or removing the attribute and putting it
back, issues no new request at all. That was measured rather than assumed.

Each layer declares its deepest real tile level, so zooming further upscales the
last tile it has instead of asking for one that does not exist. Zooming from z5
to z19 on all four layers produces no failed tile requests. Your choice of layer
is remembered between visits.

The map opens centred on Edinburgh, zoomed out far enough to take in the country
— the zoom is worked out from the map's actual size, so the same view holds on a
phone and on a wide desktop. It is deliberately not the collection's own bounding
box: one track recorded abroad would otherwise zoom the opening view out to a
continent.

A selected track is drawn as an orange line with a white casing (readable over
satellite imagery), with a green start marker, a red finish marker, and blue
markers for any waypoints. The map fits the track when you select it; with
nothing selected it fits the whole collection.

Shift-drag on the map to draw a box and zoom to it (Leaflet's own box zoom).
Not available on touch, which is fine — pinch does the same job there.

Every track in the list also gets a dot on the map, at the centre of its
bounding box, so the collection is visible geographically and not only as a list.
Hovering names it; clicking one selects that track and opens its trip info. The
dots follow the list, so a search or a country filter narrows them too, and the
selected track drops its own dot because its line is already drawn. It is a
locator rather than a waypoint — on a C-shaped route the centre of the box can
fall off the path itself, which is why clicking draws the real line.

Tapping the track itself opens the trip info for it — useful on a phone, where
selecting from the list closes the sheets to get them out of the way. The tap
target is wider than the drawn line, so it does not need a precise finger.

### The track list

The left panel lists every track with its country (when it has one), distance
and date.

- **Search** — type in the box to filter by name, activity, or date. Multiple
  words all have to match.
- **Sort** — newest first by default; also by **Added** (when it went into the
  collection, which differs from the walk date whenever an old export is
  uploaded), distance, elevation spread, duration or name, with a button to flip
  the direction. Dates are shown as `yyyy.mm.dd`, matching how tracks are usually
  named.
- **Download** — the ⤓ button on each row downloads that track's GPX
  immediately, without selecting it or leaving the page.
- **Show all** — appears in the panel header while a track is selected. Clears
  the selection and returns the map to the opening view.

### Trip information

A small **GitHub** link sits in the bottom-right corner of this panel, pointing
at the source repository.

With nothing selected the panel says so and shows nothing else — no empty
elevation profile, and no Download GPX or *view raw* links pointing at a track
that is not there.

Selecting a track opens the info panel:

- Distance, duration, lowest / highest point, total ascent / descent, and the
  date — five figures, deliberately not twenty
- **Activity**, but only when it tells you something: when the track is not an
  ordinary walk, or when it is over 25 km, where how the ground was covered is
  the interesting part. An unlabelled track over 45 km is assumed to be a ride.
  A short recorded walk shows no activity box at all
- An **elevation profile**. Hovering it (or dragging on a touch screen) shows the
  distance, altitude and time at that point, and drops a marker on the map at the
  matching position.
- **Download GPX**, and a *view raw* link that opens the file in the browser.

Downloads are named `2024.02.18 - Abbey St Bathans - 10.9km.gpx` — date, name,
distance. Any part a track does not have is dropped along with its separator, so
an undated track is just `Name - 10.9km.gpx`. A date carried in the track's own
name is used for the prefix and not repeated in the middle. The server sets this
through `Content-Disposition`, which is what browsers honour; the page computes
the identical name for the static fallback when there is no PHP.

### Keeping the stored files small

Tracks are stored without indentation, with coordinates to six decimal places
(about 11 cm) and elevation to one — roughly 27% smaller than the original
format once compressed.

Five decimal places was tried and rejected: its 1.1 m grid is *coarser* than the
0.85 m spacing of a track recorded at 1 Hz on foot, which turns a smooth line
into a staircase and inflates the measured distance by 7% over a real 8.8 km
walk. Six costs 0.17%, or 15 m over the same track.

Each stored file records the format that wrote it. When the format changes, the
Upload dialog offers to **rebuild** the tracks that predate it — one per request,
with progress, so a large library cannot time the server out. Rebuilding
re-parses and re-serialises through the same pipeline an upload uses and updates
the size and checksum; the name, dates and every computed statistic are left
alone, because those were derived from the original full-precision data.

Until a track has been rebuilt its stored checksum belongs to the old format, so
re-uploading that exact track will not be recognised as a duplicate. Rebuilding
puts that right.

### Managing tracks

Signed in, **Option-click** (Alt-click) any track in the list to open its editor:

| Field | |
|---|---|
| **Name** | The stored name, dates and all — not the tidied version shown in the list, so editing never silently drops something |
| **Date** | Overrides what the file says. Useful when an export is stamped with the day it was written rather than the day you walked |
| **Country** | Free text, offered as a datalist of the countries already in use so they stay consistent |
| **Tags** | Comma-separated. Up to 12 per track, 30 characters each, de-duplicated case-insensitively |

The editor also has a **Delete track** button at the bottom, set apart by a rule
so it is not the button you press on the way out, and needing two clicks. The
same editor opens from **List & edit tracks** in the Upload dialog, which offers
**Delete** on each row as well. Renaming rewrites the stored GPX as well as
the catalogue record, so the name inside the file never contradicts the name on
the page.

Option-click does nothing for a visitor — it selects the track like any other
click, so the shortcut is invisible unless you are signed in.

### Filtering by country or tag

A track's country and tags appear as buttons under its elevation profile.
Clicking one shows only tracks carrying it; clicking it again clears the filter,
so the button you narrowed with is also the way back out.

A dropdown under the sort control lists the **countries** in use. Tags are
deliberately not listed there — with a few dozen of them the menu stops being
usable, and tags are reachable from any track that has one. A tag that is
currently filtering does get a temporary entry, so the menu can always clear it.

Either way the count shows `(shown/total)` and the control is highlighted — a
filter that quietly hides tracks is otherwise indistinguishable from tracks that
are not there.

The free-text search matches countries and tags too, so you can type `norway`
instead of hunting for it.

### Editing several tracks at once

Signed in, **shift-click** tracks in the list to pick them out, then
**right-click** any one of them to edit the selection together. Only country and
tags can be set in bulk: renaming twenty tracks to the same thing is never what
anyone means, and one date across a selection is rarely right either.

An empty box leaves that field alone rather than clearing it, and tags are
**added** to each track rather than replacing what is there. There is no Delete
in this mode — removing twenty tracks behind one button is not something to
offer. Escape, or the `clear` link, drops the selection.

### Dates in track names

Exports are often named like `2024.2.18 Abbey St Bathans`. The date is shown in
its own column already, so it is stripped from the displayed title — and where it
disagrees with the timestamp inside the file, the name wins, because it says when
the walk happened while the file's timestamp may be whenever it was exported or
re-saved. The stored name is never changed by this, only what is displayed, and
searching still matches the date either way.

### Links

Selecting a track puts it in the address bar as `?track=<id>`, so the URL you are
looking at is always shareable, and opening such a URL loads that track directly.
If the track has since been removed, the site says so and falls back to the full
collection.

`track` is the **only** query parameter the site keeps. Anything else is stripped
from the address bar as the page loads — Facebook's `fbclid`, Google's `gclid`,
`utm_*`, `igshid` and whatever the next one turns out to be. It is a list of what
to keep rather than a list of what to block, so it needs no maintaining, and a
link copied out of the address bar never passes someone else's tracking id on.
A malformed `track` value is dropped rather than echoed back, and the fragment
(`#…`) is left alone.

### Uploading

The **Upload** button is hidden until you hold **Option/Alt**, so the bar stays
quiet for anyone just looking at the map. On a phone there is no Option key, so
it is always there, styled like the other buttons rather than in accent orange.

It opens a dialog: sign in once with the collection password,
then choose a `.gpx` file. There is a progress bar for large files, and the new
track is selected on the map as soon as it lands — and the list scrolls to it,
which matters once the collection is long enough that a new track can sort well
off the bottom of the visible rows. Re-uploading a track that is
already in the collection is refused rather than duplicated.

Once signed in you can also **drag `.gpx` files straight onto the track list**. The
drag is accepted whether or not the page currently thinks you are signed in, and
the question is settled when you drop: refusing the drag outright meant a stale
idea of the session made dropping do nothing at all, with no way to tell that
apart from the feature being broken. Dropped while signed out, it says so.

The panel highlights while you drag, and files upload one after another with a
progress strip along the bottom. Drop several at once and they queue; anything
that is not a `.gpx`, or that the validator refuses, is reported and skipped
without stopping the rest. Both routes run the same code, so a dropped file gets
exactly the checks a dialog upload gets. Dropping a file anywhere else on the
page is ignored rather than letting the browser navigate away from the site.

### The header

**My Tracks**, top left, is a link back to the collection with no track loaded.
The selected track's name sits centred over the map — genuinely centred on the
page rather than in whatever space the buttons leave over, and hidden on very
narrow screens where the panel header already names it.

### On a phone

The layout collapses to the map plus a header. **Tracks** and **Trip info** open
as bottom sheets, one at a time, and close again with the ✕, the Escape key, or
by opening the other one. Selecting a track closes the list so you can see the
map. Tap targets are finger-sized, text inputs are 16px so iOS does not zoom on
focus, and the layout respects the notch/home-indicator safe areas.

Light and dark colour schemes follow the device setting.

Selecting a track clears the screen for the map: the track list, the site name,
the Tracks button and the Upload button all go, leaving a bar with just **Show
all** and **Trip info**. Show all moves into that bar, because the panel header
it normally lives in is no longer on screen.

Tap targets are finger-sized throughout (44px for the close buttons, and no
control under 32px), the layout never scrolls sideways down to a 320px screen,
and dialogs scroll inside themselves rather than running off the bottom. The
desktop-only shortcuts — Option-click, shift-click and box zoom — simply do
nothing on touch rather than getting in the way.

---

## Licence

MIT — see [LICENSE](LICENSE).

Leaflet is vendored under `assets/leaflet/` and carries its own BSD-2-Clause
licence. Map tiles come from OpenStreetMap, OpenTopoMap, Carto and Esri, each
with their own terms and usage policies.

---

## Requirements

PHP 8.1 or newer (`XMLReader`, `XMLWriter`, `mbstring`, `libxml` — all standard)
on any web server that can run PHP. Tested on PHP 8.5 with Apache 2.4.

Without PHP the site still works read-only: the page falls back to the static
`data/index.json`, downloads fall back to the static files, and the Upload dialog
reports that the API is unavailable.

## Setting it up

1. **Copy the site into your web root.** Keep your working copy and the deployed
   copy separate — anything left in the directory is a candidate for being
   served, including original unsanitised `.gpx` files.

2. **Set the password** — see [below](#setting-the-password). Do this in your
   working copy before you upload; the server never needs to know the password,
   only the hash.

3. **Import any tracks you already have** — same validation as an upload:

   ```
   php tools/gpxadmin.php import ~/tracks/*.gpx
   ```

4. **Make `data/` writable by the web server**, e.g. `chown -R www-data data`.
   The web server never needs write access to `api/` or `tools/`.

Then open the site, press **Upload**, sign in, and add a file.

### What to upload

Your working copy contains things the server should not have. Upload this:

```
index.html
.htaccess
assets/                 app.js, style.css, leaflet/
api/                    all seven .php files, plus .htaccess and .user.ini
api/credentials.php     the hash you generated (see below)
data/gpx/               your tracks, if you already have some
data/meta/              their metadata records, one per track
data/index.json         the catalogue
```

Leave behind:

| Not uploaded | Why |
|---|---|
| `tools/` | Only needed to run CLI commands **on the server** — see below. Nothing the site serves ever loads it |
| `README.md`, `report.md` | Documentation. Denied by `.htaccess`, but there is no reason to put them there |
| Loose `*.gpx` in the project root | Your original, unsanitised source files. The library copies live in `data/gpx/` |
| `data/sessions/`, `data/auth/`, `data/incoming/` | Runtime state and the drop folder. All created on demand |
| `.DS_Store` and other dotfiles | Leak the directory layout. Denied by `.htaccess`, but do not upload them |

`data/` and its subdirectories are created automatically on the first request if
they are missing, `.htaccess` guards and all — so a first deploy with no tracks
yet needs nothing under `data/` at all.

#### Adding tracks over SFTP — the drop folder

A `.gpx` is only half a track: the catalogue is built from `data/meta/<id>.json`,
which holds the distance, duration, ascent, bounding box and checksum computed
when the file is ingested. Copying a bare `.gpx` into `data/gpx/` therefore does
nothing — nothing reads it, and it never appears on the site.

Put it in **`data/incoming/`** instead:

```
sftp> put ~/tracks/*.gpx /var/www/gpx/data/incoming/
```

Then open the site and press **Upload**. Once you sign in, the dialog says how
many files are waiting and offers to index them, with a progress bar. Each file
is parsed, rebuilt and stored exactly as if you had uploaded it through the
browser — same whitelist, same limits, same duplicate detection — and the
original is deleted once its sanitised copy is stored.

This matters more than it looks: a hand-placed file has not been through the
rebuild that every upload gets, so it is untrusted input. `data/incoming/` is
denied by `.htaccess` for exactly that reason — nothing in it is ever served.
Dropping files into the public `data/gpx/` instead would leave unvetted XML
downloadable by anyone who guessed the URL.

Files are indexed one per request, so a large backlog cannot hit
`max_execution_time`, the progress bar reflects real work, and closing the tab
half way just leaves the rest pending. A file that cannot be read is renamed to
`<name>.rejected` and reported with its reason, so one bad file never stalls the
queue.

Visitors are told none of this: both the count and the indexing require a
signed-in session.

#### Do you need `tools/` on the server?

Not for anything the site does: signing in, uploading, browsing and downloading
all work with `tools/` absent. Upload it only if you want to run these **on** the
server rather than in your working copy:

- `import` — bulk-ingest files already sitting on the server
- `remove` — delete a track
- `reindex` — rebuild `data/index.json`

Adding tracks by hand does not need it — that is what the drop folder above is
for. Deleting still works without it: remove the track's two files and then
delete the catalogue, which is rebuilt on the next request.

```
rm data/gpx/<id>.gpx data/meta/<id>.json
rm data/index.json
```

Deleting `data/index.json` is the part people forget. `api/list.php` serves that
file as-is and only rebuilds it when it is missing, so removing the track files
alone leaves the deleted track listed on the site until something rewrites it.

If you do upload `tools/`, it carries its own `.htaccess` denying the whole
directory, and the script refuses to run over HTTP regardless.

### Setting the password

There are no accounts and no usernames: the site has one password. It is set by
putting a file on the server, not through any web page, so this is done before
you upload — in your working copy, on a machine you trust.

```
php tools/gpxadmin.php passwd
```

It prompts, with the terminal echo off. Minimum 12 characters; a passphrase is
not truncated, however long it is. The command writes **`api/credentials.php`**,
which contains one salted bcrypt hash and nothing else:

```php
<?php
// GPX site password — one salted bcrypt hash, nothing else.
// Generated with: php tools/gpxadmin.php passwd
// Refuses to render if requested over HTTP.
if (PHP_SAPI !== 'cli' && !defined('GPX_LIB')) { http_response_code(403); exit; }
return '$2y$12$....';
```

Upload that file with the rest of the site and the password is live. There is
nothing else to configure and nothing to restart.

It is written `0600`, readable only by you. **PHP has to be able to read it too.**
On shared hosting PHP usually runs as the account that owns the files, so this
just works; on a VPS where PHP-FPM runs as `www-data`, a `0600` file owned by
your deploy user is invisible to it and the site will report that no password is
configured. If that happens:

```
chgrp www-data api/credentials.php && chmod 640 api/credentials.php
```

Group-readable, still not world-readable — and the file refuses to render over
HTTP regardless, so this is belt and braces either way.

The password itself is never accepted as a command-line argument — that would
put it in `ps` output and your shell history. If you cannot type it interactively
(a script, a CI job), pipe it in or pass it in the environment instead:

```
echo 'a long passphrase' | php tools/gpxadmin.php passwd
GPX_PASSWORD='a long passphrase' php tools/gpxadmin.php passwd
```

**No PHP on your machine?** The tool needs PHP 8.1 or newer. If you have Docker
or OrbStack, run it in a throwaway container from the project directory instead
of installing anything — the file lands in your working copy as you:

```
docker run --rm -it -v "$PWD":/app -w /app php:8.3-cli php tools/gpxadmin.php passwd
```

(`-it` gives the prompt a terminal so the password is not echoed. To pipe
instead, drop the `t`: `printf '%s\n' 'a long passphrase' | docker run --rm -i …`.)

**To change the password**, run the command again and upload the new
`api/credentials.php` over the old one. Sessions already signed in stay signed in
until they expire; sign out to end one immediately.

**To disable uploading entirely**, delete `api/credentials.php` from the server.
The site stays fully readable and the Upload dialog says no password is
configured.

Nothing on the server ever writes this file — the CLI tool is the only thing that
does — so `api/` can stay read-only to the web server, and no request can change
the password.

### Apache

The bundled `.htaccess` files do the work, but they need `AllowOverride All` (or
at least `AuthConfig FileInfo Options Indexes`). With `AllowOverride None` they
are ignored silently and nothing is enforced.

### nginx

`.htaccess` does nothing on nginx. Use the equivalent — and put these blocks
**before** your `location ~ \.php$` block, because nginx takes the first matching
regex location in file order:

```nginx
client_max_body_size 27m;

location ~ /\.                   { deny all; }   # .htaccess, .user.ini, .DS_Store
location = /README.md            { deny all; }
location = /report.md            { deny all; }
location ~* ^/[^/]+\.gpx$        { deny all; }   # loose files in the site root
location ^~ /data/auth/          { deny all; }
location ^~ /data/meta/          { deny all; }
location ^~ /data/sessions/      { deny all; }
location ^~ /data/incoming/      { deny all; }
location ^~ /tools/              { deny all; }
location ~ ^/api/(lib\.php|credentials\.php) { deny all; }

location ^~ /data/ {
    autoindex off;
    location ~ \.php$ { deny all; }   # never execute anything under data/
    location ~* \.gpx$ {
        default_type application/gpx+xml;
        add_header X-Content-Type-Options nosniff always;
        add_header Content-Security-Policy "default-src 'none'; sandbox" always;
    }
}

add_header X-Content-Type-Options nosniff always;
add_header X-Frame-Options DENY always;
add_header Referrer-Policy no-referrer always;
add_header Content-Security-Policy "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data: blob: https://tile.openstreetmap.org https://*.tile.openstreetmap.org https://*.tile.opentopomap.org https://server.arcgisonline.com https://services.arcgisonline.com https://*.basemaps.cartocdn.com; connect-src 'self'; form-action 'self'; base-uri 'none'; object-src 'none'; frame-ancestors 'none'" always;

# GPX is XML and compresses about ten to one. This is the single biggest thing
# you can do for how quickly a track appears.
gzip              on;
gzip_vary         on;
gzip_comp_level   6;
gzip_min_length   1024;
gzip_proxied      any;
gzip_types        application/gpx+xml application/json application/javascript
                  text/css text/plain text/xml image/svg+xml;

# No build step, so app.js keeps its name: revalidate rather than serve a stale
# copy after an upload.
location ~* \.(js|css|html)$ { add_header Cache-Control "no-cache" always; }

location ~ \.php$ {
    try_files $uri =404;              # with cgi.fix_pathinfo=0
    fastcgi_pass unix:/run/php/php-fpm.sock;
    include fastcgi_params;
}
```

On any server it is better still to move `data/` outside the document root and
point the `DATA_DIR` constant at the top of `api/lib.php` at the new location.

### Behind a reverse proxy

If nginx/Caddy/Cloudflare terminates TLS and forwards to PHP over plain HTTP, set
`FORCE_HTTPS` to `true` at the top of `api/lib.php`. Otherwise PHP cannot tell
the connection is encrypted and the session cookie goes out without `Secure`.

`REMOTE_ADDR` is then the proxy's address for every visitor, which collapses the
per-address rate limiting into one shared bucket. Either pass the real address
through (`fastcgi_param REMOTE_ADDR …` from a trusted proxy) or accept a global
limiter. `TRUSTED_PROXIES` and `SITE_HOST` in the same block cover the other two
proxy-related settings.

---

## Layout

```
index.html              the site
assets/style.css        styling, including the mobile layout
assets/app.js           map, list, stats, elevation profile, upload dialog
assets/leaflet/         Leaflet 1.9.4, vendored — no CDN, no third-party requests

api/lib.php             shared library: auth, rate limits, GPX validation, storage
api/session.php         login / logout / CSRF token
api/list.php            public JSON catalogue
api/download.php        public download endpoint (attachment, real filename)
api/upload.php          authenticated upload endpoint
api/incoming.php        drop-folder listing and indexing (authenticated)
api/manage.php          edit metadata and delete (authenticated)
api/credentials.php     the one salted password hash — written by the CLI tool
api/.user.ini           upload limits for PHP-FPM/CGI (.htaccess covers mod_php)

tools/gpxadmin.php      command line administration — optional on the server
scripts/temp-scripts/   one-off data fixes, kept for the record (see docs/)

data/gpx/<id>.gpx       the tracks — public, static, sanitised
data/meta/<id>.json     one metadata record per track: the "database"
data/index.json         the whole catalogue as one flat file
data/incoming/          drop folder for files added by hand — never served
data/auth/              rate-limit counters, private upload log
data/sessions/          PHP session files
```

### How a track is stored

Each track is two files: the sanitised GPX and a JSON metadata record holding the
name, dates, distance, duration, elevation figures, bounding box, size and
SHA-256. `data/index.json` is the concatenation of those records and is what the
site reads. Nothing else is needed to move the library — copy `data/` and you
have moved the whole collection.

Track ids are a random hex prefix plus a slug of the name, e.g.
`4f2a91c7d3e8-glen-affric`. They never come from the uploaded filename.

---

## HTTP API

| Endpoint | Method | Auth | Purpose |
|---|---|---|---|
| `api/list.php` | GET | no | Catalogue as JSON, with ETag/304 support |
| `api/download.php?id=<id>` | GET/HEAD | no | GPX as an attachment; `&view=1` for inline |
| `api/session.php` | GET | no | `{authenticated, csrf, configured}` |
| `api/session.php` | POST | — | `action=login` (pass) / `action=logout` (csrf) |
| `api/upload.php` | POST | yes | multipart `gpx` + `csrf` |
| `api/incoming.php` | GET | yes | `{pending, files}` waiting in the drop folder |
| `api/incoming.php` | POST | yes | `action=adopt` + `csrf` — indexes one file, returns `{name, remaining}` |
| `api/manage.php` | POST | yes | `action=update` (id + any of name, date, country, tags) / `action=rename` (id, name) / `action=delete` (id), all + `csrf` |

Static equivalents, always available: `data/gpx/<id>.gpx` and `data/index.json`.

## Command line

```
php tools/gpxadmin.php passwd                  write api/credentials.php
php tools/gpxadmin.php import <file.gpx> ...   ingest local files
php tools/gpxadmin.php list                    list stored tracks
php tools/gpxadmin.php remove <id>             delete a track (file + metadata)
php tools/gpxadmin.php reindex                 rebuild data/index.json
```

`passwd` takes no arguments — there is one password and no username. See
[Setting the password](#setting-the-password) for the full workflow, including
how to run it with no PHP installed.

`reindex` also backfills checksums for tracks stored before duplicate detection
existed.

All of these are CLI-only by design: there is no web endpoint that sets a
password or deletes a track.

---

## How the statistics are worked out

Everything is computed once, server-side, when the file is ingested; the browser
only formats it.

- **Distance** — haversine between consecutive points, skipping legs that are
  physically impossible: over 250 km/h between timestamped points, or a jump over
  100 km where there are no timestamps. A GPS glitch therefore does not inflate
  the total, while a sparsely-sampled route still measures correctly.
- **Duration** — first to last timestamp. **Moving time** counts only gaps under
  two minutes that cover real ground.
- **Ascent / descent** — accumulated with a 5 m hysteresis, so GPS altitude noise
  is not counted as climbing. **Altitude spread** is simply highest minus lowest.
- **Elevation profile** — drawn in the browser from the stored file, downsampled
  to about one point per pixel, using the same distance rule so its x-axis agrees
  with the stated distance.

Tracks with no elevation data show "No elevation data in this track" instead of a
profile; tracks with no timestamps simply have no duration or moving time.

---

## Security summary

Anyone may read and download. Only someone with the password may add a track.
The full reasoning, the audit that was run against this code, and the residual
tradeoffs are in [report.md](report.md).

- **The password** — one for the whole site, no usernames — is salted bcrypt
  (cost 12, algorithm pinned), pre-hashed with SHA-256 so a long passphrase is
  not silently truncated at 72 bytes, and kept in a PHP file that exits when
  fetched over HTTP. Only the CLI tool writes that file, so the web server needs
  no write access to `api/` and no request can ever change the password.
- **Sessions** are `HttpOnly`, `SameSite=Strict`, `Secure` over HTTPS, with ID
  regeneration on login, a 2-hour idle and 24-hour absolute lifetime, stored
  inside the app rather than a shared temp directory.
- **Brute force** — *failed* sign-ins are limited per IPv4 /24 or IPv6 /64 (8 per
  15 min) and site-wide (24 per 15 min), so guesses spread across many addresses
  are capped too. Only wrong passwords are counted and both counters clear on
  success. With a single password the wider limit does mean a determined
  distributed attack can keep you out for 15 minutes at a stretch; delete
  `data/auth/ratelimit.json` to clear it immediately.
- **Renaming and deleting** from the browser need the same signed-in session,
  CSRF token and same-origin check as an upload, are rate limited separately,
  and are recorded in the private upload log. Deleting is a two-click confirm.
  A rename re-parses and re-serialises the stored file through the same pipeline
  an upload uses, so the name inside the file matches the catalogue and nothing
  can be smuggled in by renaming.
- **Uploads** need POST, a valid CSRF token and a matching `Origin`/`Referer`,
  and are rate limited (60/hour authenticated, 20/hour before authentication).
- **The drop folder** (`data/incoming/`) is never served, and both reading its
  contents and indexing them need a signed-in session, so a visitor can neither
  see what is waiting nor make the server parse it. Files are adopted one per
  request through the same pipeline as an upload, and the caller never names the
  file — it asks for "the next one" and the server chooses, so no filename
  crosses the trust boundary. A symlink pointing out of the folder is refused.
- **The stored file is never the file you sent.** Every upload is parsed against
  a small element whitelist and re-serialised from scratch with `XMLWriter`, so
  `<link>`, `<extensions>`, processing instructions, comments and anything else
  simply do not survive. DTDs and entities are refused outright (XXE,
  billion-laughs), parsing runs with `LIBXML_NONET` and a forced UTF-8 encoding,
  and the rebuilt document is verified again before it is written.
- **Text fields** are stripped of control, zero-width and bidi-override
  characters, capped at 200 characters, and have URLs, bare domains and e-mail
  addresses removed; markup in a field that is kept fails the upload. Ordinary
  exports from Garmin, Strava or Komoot that mention a URL are accepted — the URL
  just never reaches the stored file.
- **Limits**: 25 MB in and out, 200 000 points, 2000 waypoints, 2000 tracks, 5000
  segments. Writes are atomic and serialised under a lock, so concurrent uploads
  cannot duplicate a track or lose one from the catalogue.
- **Serving** — `.htaccess` (and the nginx equivalent above) disables PHP
  execution under `data/`, denies dotfiles, `data/auth/`, `data/meta/`,
  `data/sessions/`, `tools/`, the docs and loose root `.gpx` files, and sets
  `nosniff`, `X-Frame-Options`, `Referrer-Policy`, HSTS and a strict CSP. Stored
  GPX files are served as `application/gpx+xml` under `default-src 'none';
  sandbox`.

If you lock yourself out, delete `data/auth/ratelimit.json` — and leave the
directory writable afterwards, or rate limiting stops being enforced (it fails
open deliberately, and logs when it does).

---

## Notes

- The tile services are public and have usage policies. A busy site should use
  its own tiles or a keyed provider; the layer URLs are at the top of
  `assets/app.js` and the matching CSP hosts are in `.htaccess` and `index.html`.
- The site makes no third-party requests other than map tiles: Leaflet is
  vendored, there is no analytics, no fonts, no frameworks.
- Track files are world-readable by design. Do not upload a track whose start
  point you would not publish.
