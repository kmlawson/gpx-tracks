# Build report — GPX Tracks

What was built, how it was verified, what went wrong along the way, and what is
knowingly left imperfect. Written 15–16 August 2026. For what the site *does*,
see [README.md](README.md).

---

## 1. The brief

A map site for a GPX collection: Leaflet, switchable satellite and terrain base
layers that work at every zoom without errors, mobile-friendly with collapsible
panels, a track list that drives the map and the URL, a trip-info panel with
statistics and an elevation profile, sorting by date/distance/elevation and a
keyword filter — plus a hardened PHP upload endpoint behind a salted password
file, storing validated GPX in a flat-file store that anyone may read and only
the password holder may write.

Later in the same session: three sub-agent security audits of the upload
mechanism, and direct download of any GPX from the browser.

Starting point: an empty directory with two real GPX files in it (a 1.0 MB
Pocket Earth export and a 4.3 MB browser-recorded track, ~10 000 points each).
They became the first two tracks in the library and the fixtures for most of the
testing.

## 2. Shape of the solution

| Decision | Why |
|---|---|
| Flat files, no database | The brief allowed it, and it makes the library portable: copy `data/` and you have moved everything. One JSON record per track, plus `data/index.json` as the served catalogue. |
| Leaflet vendored locally | Removes a third-party origin from the page entirely, which in turn allows a strict `script-src 'self'` CSP with no exceptions. |
| No framework, no build step | The whole front end is one HTML file, one CSS file and one JS file that a person can read. |
| Statistics computed at ingest | The browser never has to parse a 4 MB file to sort a list; sorting and filtering work on ~1 KB records. |
| Uploads **rebuilt**, not scrubbed | The decisive choice. Rather than trying to spot everything dangerous in an uploaded document, the parser reads a small whitelist into plain PHP values and `XMLWriter` writes a fresh document from those values. Anything not on the whitelist — `<link>`, `<extensions>`, comments, processing instructions, stray namespaces — cannot survive, because nothing copies it across. |
| Credential in a self-guarding PHP file | `api/credentials.php` starts with `if (PHP_SAPI !== 'cli' && !defined('GPX_LIB')) { http_response_code(403); exit; }`, so the hash stays private even on a server that ignores `.htaccess`. Written by the CLI tool only — the web server needs no write access to `api/`. |
| Static fallback | Everything except uploading works with no PHP at all, via `data/index.json` and the static `.gpx` files. |

### Base layers

Terrain (OpenTopoMap), Satellite (Esri World Imagery), OSM standard, Carto
Light. Every layer declares `maxNativeZoom`, which is what makes "no errors at
various zoom levels" true rather than hopeful: past a provider's deepest level
Leaflet upscales the last real tile instead of requesting a 404.

## 3. How it was verified

Little of this was taken on trust.

- **Tile availability** — `curl` against each provider at z5/z12/z16/z17/z18/z19
  before writing any map code, checking status and content type.
- **Headless Chrome** (puppeteer-core driving the installed Chrome) for the front
  end: desktop and mobile viewports, console errors and failed requests captured,
  a zoom sweep z5→z19 on each of the four base layers, panel behaviour, search
  and every sort key, deep links (valid and stale), the full login/upload flow,
  and real file downloads written to disk and size-checked.
- **A real Apache 2.4** with `AllowOverride All` to test the `.htaccess` rules,
  rather than reasoning about them. This immediately proved its worth (§5).
- **PHP's built-in server** for the API: auth, CSRF, same-origin, rate limits,
  size limits, and a corpus of malicious GPX payloads.
- **A malicious-payload corpus** built up as the work went on: XXE, billion
  laughs, `<script>` in CDATA, URLs in names, PHP in a `.gpx`, coordinates out of
  range, truncated XML, invalid UTF-8, embedded NULs, 450 000 points, 33 MB
  bodies, 50 000-level nesting, 5 MB text nodes, bidi-override characters,
  GPS-glitch jumps, and concurrent identical uploads.

## 4. Bugs found during the build

Found by testing, before any audit:

1. **Catalogue served from cache after upload** — the new track was stored but
   not selected, because the refetch of `api/list.php` came from the browser
   cache. Fixed with `cache: 'no-store'` on that fetch.
2. **Distance under-counted on sparse tracks** — the first implementation
   discarded any leg over 2 km as a GPS glitch, which also discarded legitimate
   route points. A two-point 12.8 km route measured 0.00 km. Replaced with a
   plausibility test: with timestamps, reject over 250 km/h; without them, only
   reject a jump over 100 km. Verified both ways — a synthetic 55 km glitch in
   the middle of a 1 km walk is still excluded, and the sparse route now
   measures 12.77 km.
3. **Bidi-override characters survived in track names**, letting a name render
   differently from its stored text. Added to the invisible-character strip.
4. **A misleading "File too large" rejection** on a 21 MB upload — the message
   came from validating the *rebuilt* document against the input limit. Made the
   two limits separate and explicit.
5. **`post_max_size` overflow answered as a CSRF error** — when a body exceeds
   PHP's limit, `$_POST` arrives empty, so the CSRF check failed first and
   reported the wrong cause. That check now runs before the token check.

## 5. The audit round

Three sub-agents reviewed the code in parallel, each with a different lens:
authentication/session/CSRF/abuse; the GPX parser and storage; the serving
surface and browser-side code. They were read-only, and were asked to separate
findings they had reproduced from findings they had reasoned about. Between them
they produced 12 confirmed defects worth fixing, several genuinely surprising.

### Authentication and abuse

- **The login limiter could be dismantled.** The per-IP bucket keyed on the full
  address, so one IPv6 /64 supplies 2^64 fresh counters; and eviction kept the
  most recently *created* buckets, so an active lockout was the first thing
  discarded — demonstrated by clearing a lockout with 5200 throwaway buckets.
  Now keyed on the /24 or /64 prefix (`inet_pton`, because splitting a compressed
  IPv6 address on `:` yields the whole address), and eviction keeps saturated
  counters in preference to everything else.
- **Anyone could lock out a known username.** The per-user bucket counted *all*
  attempts including successes and was never cleared, so ~25 requests per 15
  minutes held a lockout — with the correct password being refused. Both counters
  now count failures only and clear on success.
- **Latent code injection into the credential file.** The legacy `passwd` loader
  did not validate hash format, and `passwd_set()` concatenated values into a PHP
  literal; the agent wrote a working payload that executed on every request. Now
  `var_export`, with format validation on load. (The legacy loader has since been
  removed altogether — see the addendum.)
- Session cookie lost `Secure` behind a TLS-terminating proxy; anonymous requests
  minted a session file each; `session.save_path` was the shared system default;
  the advertised 2-hour idle timeout could never fire because PHP's default
  `gc_maxlifetime` is 24 minutes. All fixed, sessions now live in
  `data/sessions/`.
- The IP component of the session fingerprint was **hurting**: compressed IPv6
  addresses bound a session to a full /128, so ordinary privacy-extension
  rotation killed sessions — including mid-upload, after the whole body had
  transferred. Now bound to the User-Agent only.
- The CLI accepted the password as `argv[1]`, leaking it into `ps` and shell
  history. Refused outright; prompt, pipe or environment variable instead.

### Parser and storage

- **A small, entirely legal upload could OOM the worker.** The rebuilt document
  was verified by a second full parse while the first parse tree was still live —
  roughly 2× peak memory. 300 000 points (a 7 MB file, under every limit) died
  with an uncatchable fatal error at 256 MB, and so would a real 250 000-point
  ride. Now the parse tree is released first and verification is a streaming
  whitelist check that keeps no data; the point limit is 200 000, which measures
  176 MB peak and 1.7 s.
- **6.75× output amplification.** The document name and type were written into
  *every* `<trk>`, so a 1.5 MB upload became a 10 MB stored file with no limit on
  track count. Now the fallback name is written once, with caps on tracks,
  segments, elements and output size. The same payload now expands ~1.3×.
- **Two races.** Duplicate detection was read-then-write, and the index rebuild
  was read-all-then-write-all: six concurrent imports of one file stored six
  copies, and three tracks vanished from the catalogue entirely. Both now run
  under one exclusive lock — eight concurrent identical imports store exactly
  one, and the catalogue stays consistent.
- **Ordinary exports were being rejected.** A URL in a `<desc>` failed the whole
  upload — even though that field is discarded — and a raw byte scan for
  `<script` rejected an innocent `<scriptum>` extension element. Validation now
  applies only to fields that are kept, URLs are stripped rather than fatal, and
  the byte scan is down to PHP open tags.
- Timestamps were accepted from year 0 to 9999 (a far-future date pins itself to
  the top of the list forever) and rolled impossible dates over silently. Now
  clamped to 1990…tomorrow with real date validation — and rewritten without
  `DateTimeImmutable`, which cost 4.6 s on a file of 700 000 `<time>` elements.
- Also fixed: no element budget outside track points, `.tmp*` files leaked by a
  killed request, an unbounded private upload log.

The agent also confirmed several things *not* broken, which is worth recording:
XXE is blocked at the parser and not merely by string matching (a listening
socket received nothing, while the same payload with DTD loading enabled fetched
immediately); UTF-16 dies on the NUL check; XInclude is skipped; malformed
documents always surface as fatal errors rather than being silently truncated;
`atomic_write` replaces a symlink at the destination rather than following it;
and the numeric parsing accepts `1e-5` but rejects `0x10`, `INF` and fullwidth
digits, and is locale-independent.

### Serving surface

- **`php_flag engine off` in `data/.htaccess` was unguarded.** That directive
  only exists under mod_php; on a PHP-FPM host it is a fatal "Invalid command"
  and would have returned **500 for every track file, every metadata file and the
  catalogue** — the entire read path — on a mainstream modern deployment. Worse,
  `ensure_dirs()` recreated the file if an admin deleted it. Now wrapped in
  `<IfModule>`, in both the file and the generator.
- **The CLI tool's "CLI only" guard never ran.** A `#!/usr/bin/env php` shebang
  is only stripped by the CLI SAPI, so over HTTP `declare(strict_types=1)` was no
  longer the first statement, the file failed to compile, and the guard beneath
  it never executed — leaking the absolute path and PHP version in the fatal
  error. Shebang removed, `tools/.htaccess` added.
- **The uploader username was still being published.** Removing the field from
  new records did not clean the two existing ones, and `list.php` echoed metadata
  verbatim. Now an explicit field whitelist, and the old records were stripped.
- CSP existed only as a `<meta>` tag on one page, so `frame-ancestors` was
  ignored and nothing else was covered; the deny list missed `uploads.log`,
  `credentials.php`, `.user.ini` and dotfiles; the `RedirectMatch` rules were
  case-sensitive on a case-insensitive filesystem; `.DS_Store` files were served
  and leaked the directory layout; `expose_php = Off` in `.user.ini` does nothing
  (it is `PHP_INI_SYSTEM`). All fixed or documented.
- A clean bill on the browser-side code: every sink that touches attacker
  data uses `textContent`, `innerHTML` appears zero times, and `?track=` is only
  ever matched against ids already whitelisted. The one latent risk — Leaflet's
  `bindPopup(string)` assigning `innerHTML` — now gets a text node instead.

### Verification of the fixes

| Fix | Evidence |
|---|---|
| Failure-only counters | 30 consecutive successful logins do not consume the budget; 8 failures still lock |
| Eviction cannot clear a lockout | Survives 6000 cheap buckets and 3000 saturated ones (previously cleared by 5200) |
| IPv6 prefixes | `2001:db8::1` and its expanded form now map to the same /64 bucket |
| OOM | 200 000 points at the ceiling: 176 MB peak, 1.7 s, under a 256 MB limit |
| Amplification | The 6.75× payload now stores at ~1.3×, hard-capped at 25 MB |
| Upload races | 8 concurrent identical imports → 1 stored, `index.json` consistent |
| Same-origin | Missing both headers, and a foreign `Origin`, both refused; a legitimate `Referer` with an explicit `:80` accepted |
| Apache rules | Legit files 200; `.htaccess`, `credentials.php`, `lib.php`, `uploads.log`, `data/meta/`, `tools/`, docs, case-varied paths all denied; headers and sandboxed GPX confirmed on the wire |
| Parser corpus | 20-payload suite re-run: every malicious case rejected, every benign case accepted, sanitised output inspected |
| Front end | Downloads, upload, deep links, mobile panels: no console errors, no failed requests |

## 6. Challenges

**Verifying server configuration without a server.** The most consequential bug
in the whole session — `php_flag` breaking every track file on PHP-FPM — lived in
a file that neither PHP linting nor the built-in dev server can evaluate. It was
only caught because macOS ships Apache 2.4, so the `.htaccess` could be run for
real. Once that was set up, a stray `.htaccess` in a parent directory of the home
folder made every request 500 until the test config was scoped with an explicit
`<Directory />`. The nginx configuration remains untested — there is no nginx
here — and is documented as such.

**The dev server hides real behaviour.** `php -S` ignores `.htaccess`, which is
why the credential file was moved into a self-guarding PHP file: the protection
had to hold without any web-server cooperation. That change turned a "denied by
config" claim into one verifiable in the environment at hand.

**Limits interact.** Nearly every resource bug came from limits that were
individually reasonable: 400 000 points was fine until multiplied by a second
parse; 25 MB in was fine until the rebuild expanded it 6.75×; a byte limit did
not bound CPU, because 700 000 `<time>` elements fit easily inside it. Bounding
input size is not the same as bounding work, and the fix in each case was to
measure rather than reason.

**Security measures that harm.** Two of the audit findings were things that
looked like hardening and were not: the IP component of the session fingerprint
(which killed real sessions mid-upload while barely inconveniencing an attacker
on the same prefix), and blanket URL rejection (which refused ordinary Garmin and
Strava exports over a field that was being discarded anyway). Both were replaced
with something narrower, and the reasoning is recorded in the code so it does not
get "improved" back.

**Eviction is subtle.** The first attempt at fixing the rate-limiter eviction
sorted by expiry — which, since every bucket in a window expires at the same
offset from creation, still discarded the oldest, i.e. exactly the attacked
bucket. The test caught it. Keeping *saturated* buckets in preference to
everything else is the property that actually matters.

**Concurrency in flat files.** File-per-track storage is simple until two writers
arrive at once. Both races were invisible to sequential testing and obvious under
eight parallel imports.

**Agents work against a moving target.** The three audits ran while the download
feature was being written, so one agent reviewed files that changed underneath
it. Its report flagged the timestamps, which made the overlap easy to account
for; the audited paths themselves were untouched.

## 7. Known limits and deliberate tradeoffs

- **The rate limiter fails open** if `data/auth/` becomes unwritable, so a
  permissions accident cannot lock the owner out of their own site. It logs
  loudly and sleeps when it happens.
- **Roughly 7500 saturated buckets from distinct prefixes** could still displace
  an old lockout entry. That is 60 000 requests from 7500 separate /64s, each of
  which locks the attacker's own prefix — an acceptable bound for a flat-file
  limiter. A real store (SQLite, Redis) would remove the ceiling.
- **Behind a reverse proxy, `REMOTE_ADDR` is the proxy**, so all per-address
  limits collapse into one bucket unless the real address is passed through.
  Documented in the README.
- **Limits are sized for a 256 MB PHP** (200 000 points ≈ 55 hours at 1 Hz).
  Larger tracks need a larger `memory_limit` and a matching `MAX_POINTS`.
- **Track files are world-readable by design**, including start points.
- **`data/` inside the document root** is convenient and defended in depth, but
  moving it out is strictly better and is a one-constant change.
- **The nginx configuration is unverified**, as is the reverse-proxy path.
- **Deletion is no longer CLI-only.** `api/manage.php` adds rename and delete to
  the authenticated write surface, which is larger than the original design's
  "upload and nothing else". Both need the session, the CSRF token and a
  same-origin request, both are logged, and deleting takes two clicks — but the
  honest summary is that a stolen session can now destroy tracks, where before it
  could only add them. The CLI remains the only way to delete without a browser.
- **No password is shipped.** Run `php tools/gpxadmin.php passwd` before the
  first upload and put the resulting `api/credentials.php` on the server; the
  site says so in the dialog if you forget.
- **One password, site-wide, and no usernames.** There is nothing to enumerate
  and no account to lock, but there is also no way to revoke access for one
  person without changing it for everyone — which for a single-owner collection
  is the point. The site-wide failure limiter that replaces the old per-username
  one (24 failures per 15 minutes) can be spent by a distributed attack, which
  keeps the owner out until the window rolls or `data/auth/ratelimit.json` is
  deleted.

---

## Addendum — user management removed (16 August 2026)

The multi-user machinery was never used and was removed rather than maintained.
The site now has **one password and no usernames**.

- `api/credentials.php` holds a single bcrypt string, not a `username => hash`
  map. It is still generated only by `php tools/gpxadmin.php passwd`, still
  refuses to render over HTTP, and is now genuinely write-once: the login path no
  longer rewrites it, so `api/` can be read-only to the web server.
- `passwd_load()` became `passwd_hash_load()`; `users` and `deluser` are gone
  from the CLI; `passwd` takes no arguments.
- The legacy `data/auth/passwd` flat-file loader and the on-login hash migration
  were deleted with it. Both existed to carry old accounts forward and there are
  no old accounts.
- `$_SESSION['uid']` is gone; a session is authenticated by `auth_at` alone, and
  `require_login()` returns nothing.
- The per-username failure bucket became a site-wide one at the same 24-per-15-
  minutes limit. The upload limiter is now keyed on the address prefix only,
  which is what it effectively was with one account.
- The upload log's second column is now the source (`web` or `cli`) rather than a
  username. It never held anything else in practice.

What did not change: bcrypt cost 12 with SHA-256 pre-hashing, the CSRF and
same-origin checks, session hardening, and the whole GPX validation pipeline.
