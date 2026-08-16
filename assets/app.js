/* GPX track browser — Leaflet map, track list, trip stats, elevation profile.
   No third-party code beyond the locally vendored Leaflet build. */
(function () {
  'use strict';

  /**
   * These three files are uploaded together and only work together. Getting a
   * new index.html with an old app.js or style.css has produced three separate
   * "it is broken" reports whose cause was invisible, so each carries the same
   * stamp and the page says plainly when they disagree.
   */
  var APP_VERSION = '2026.08.16.8';

  var API_LIST = 'api/list.php';
  var FALLBACK_LIST = 'data/index.json';
  var MOBILE = function () { return window.matchMedia('(max-width: 800px)').matches; };

  var $ = function (id) { return document.getElementById(id); };

  /* ------------------------------------------------------------------ *
   * Map and base layers
   * ------------------------------------------------------------------ */

  var OSM_ATTR = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors';

  /**
   * Opening view: Edinburgh, far enough out to take in the country.
   *
   * The box is built symmetrically around Edinburgh rather than around Scotland
   * itself, because the view is centred on the city and Edinburgh sits well to
   * the south-east — a box drawn round the country would need half of it
   * off-screen. 2.85° north of Edinburgh reaches past Cape Wrath and Dunnet
   * Head; 3.2° west reaches the Ardnamurchan coast and most of the Hebrides.
   */
  var EDINBURGH = [55.9533, -3.1883];
  var HOME_BOUNDS = [
    [EDINBURGH[0] - 2.85, EDINBURGH[1] - 3.2],
    [EDINBURGH[0] + 2.85, EDINBURGH[1] + 3.2]
  ];

  /**
   * How far outside a shape a tap still counts as hitting it.
   *
   * A track pin is a 5px circle: about a 12px target, which is fine with a
   * mouse and hopeless with a finger. Leaflet adds this tolerance to every
   * canvas hit test, so the dot stays small while its hit zone becomes roughly
   * 44px across — without drawing anything bigger.
   */
  var TAP_TOLERANCE = 16;

  var vectorRenderer = L.canvas({ tolerance: MOBILE() ? TAP_TOLERANCE : 0 });

  var map = L.map('map', {
    zoomControl: true,
    minZoom: 2,
    maxZoom: 19,
    worldCopyJump: true,
    preferCanvas: true,
    // Ours rather than Leaflet's implicit one, so the tolerance can be changed
    // when the layout crosses the breakpoint. Path._clickTolerance() reads it
    // on every hit test, so updating it takes effect immediately.
    renderer: vectorRenderer,
    // Half steps for fitted views only. Whole levels are a blunt instrument for
    // fitting a country: the opening view needs a little more than zoom 7 shows
    // and would otherwise drop to 6, which is the whole British Isles and most
    // of the North Sea. zoomDelta keeps the +/- buttons stepping by one.
    zoomSnap: 0.5,
    zoomDelta: 1
  }).setView(EDINBURGH, 6.5);

  /**
   * Derived from the map's real size rather than hard-coded, so the same view
   * holds on a phone and on a wide desktop with both panels open. getBoundsZoom
   * returns the closest zoom at which the box still fits, so this is as close in
   * as it can be without losing the north of the country.
   */
  function homeView() {
    map.setView(EDINBURGH, map.getBoundsZoom(L.latLngBounds(HOME_BOUNDS)), { animate: false });
  }

  // Straight away, so the opening view is right on the first paint rather than
  // settling into place once the catalogue has been fetched.
  homeView();

  // maxNativeZoom lets Leaflet upscale instead of requesting tiles a server
  // does not have — that is what otherwise produces 404s when you zoom right in.
  var layers = {
    'Terrain (OpenTopoMap)': L.tileLayer('https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png', {
      maxZoom: 19, maxNativeZoom: 17, subdomains: 'abc',
      attribution: OSM_ATTR + ' | <a href="https://opentopomap.org">OpenTopoMap</a> (CC-BY-SA)'
    }),
    'Satellite (Esri)': L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
      maxZoom: 19, maxNativeZoom: 19,
      attribution: 'Imagery &copy; Esri, Maxar, Earthstar Geographics'
    }),
    'OSM standard': L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19, maxNativeZoom: 19, attribution: OSM_ATTR
    }),
    'Light (Carto)': L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
      maxZoom: 19, maxNativeZoom: 19, subdomains: 'abcd',
      attribution: OSM_ATTR + ' &copy; <a href="https://carto.com/attributions">CARTO</a>'
    })
  };

  window.gpxMap = map;        // handy for debugging / automated checks
  window.gpxLayers = layers;

  /* ------------------------------------------------------------------ *
   * Tiles that never arrive
   *
   * A tile request occasionally stalls: no error, no image, just a white
   * square that stays white however long you wait, because nothing will ever
   * fire to tell the map to try again. Each tile therefore gets a watchdog,
   * cancelled the moment it loads or fails, and a bounded number of retries.
   * ------------------------------------------------------------------ */

  var TILE_TIMEOUT = 9000;   // a stalled request looks exactly like a slow one
  var TILE_RETRIES = 2;

  function retryTile(tile) {
    // Panned or zoomed away: Leaflet has already discarded it.
    if (!tile || !tile.parentNode || !tile.src) return;
    var n = tile._gpxRetries || 0;
    if (n >= TILE_RETRIES) return;
    tile._gpxRetries = n + 1;

    var src = tile._gpxSrc || tile.src;
    tile._gpxSrc = src;
    setTimeout(function () {
      if (!tile.parentNode) return;
      // The URL has to differ. Re-assigning the same src does nothing at all
      // while a request for it is still pending — measured, not assumed — and
      // removing the attribute first does not help either, so the stalled
      // request would simply carry on being stalled. A retry counter is enough
      // to make it a new URL, and retries are rare enough not to matter to the
      // tile provider's cache.
      tile.src = src + (src.indexOf('?') === -1 ? '?' : '&') + 'r=' + (n + 1);
    }, 500 * (n + 1));
  }

  function watchTiles(layer) {
    layer.on('tileloadstart', function (e) {
      clearTimeout(e.tile._gpxTimer);
      e.tile._gpxTimer = setTimeout(function () { retryTile(e.tile); }, TILE_TIMEOUT);
    });
    layer.on('tileload', function (e) { clearTimeout(e.tile._gpxTimer); });
    layer.on('tileunload', function (e) { clearTimeout(e.tile._gpxTimer); });
    layer.on('tileerror', function (e) {
      clearTimeout(e.tile._gpxTimer);
      retryTile(e.tile);
    });
  }

  // Only complain if a layer is clearly failing wholesale, and never spam.
  var tileErrors = 0, tileWarned = false;
  Object.keys(layers).forEach(function (name) {
    watchTiles(layers[name]);
    layers[name].on('tileerror', function () {
      tileErrors++;
      if (tileErrors > 24 && !tileWarned) {
        tileWarned = true;
        toast('Map tiles are not loading — check your connection or try another base layer.');
      }
    });
    layers[name].on('tileload', function () { tileErrors = Math.max(0, tileErrors - 1); });
  });

  var saved = null;
  try { saved = localStorage.getItem('gpx.layer'); } catch (e) { /* private mode */ }
  var initial = (saved && layers[saved]) ? saved : 'Terrain (OpenTopoMap)';
  layers[initial].addTo(map);
  L.control.layers(layers, {}, { position: 'topright' }).addTo(map);

  /**
   * A download shortcut under the layer switcher, on phones only.
   *
   * On a desktop the track list and the whole trip panel are on screen at once,
   * so the download is already a click away. On a phone, with a track selected,
   * everything but the map is hidden and reaching it means opening the trip
   * sheet and scrolling past the profile.
   */
  var mapDownload = L.control({ position: 'topright' });
  mapDownload.onAdd = function () {
    var wrap = L.DomUtil.create('div', 'leaflet-bar map-dl');
    var a = L.DomUtil.create('a', '', wrap);
    a.href = '#';
    a.setAttribute('download', '');
    a.title = 'Download this track';
    a.setAttribute('aria-label', 'Download this track');
    a.textContent = '⤓';
    // Without this the click pans the map instead of following the link.
    L.DomEvent.disableClickPropagation(wrap);
    this._link = a;
    return wrap;
  };
  var mapDownloadOn = false;

  /** Shown only on a phone, and only with a track to download. */
  function syncMapDownload() {
    var want = MOBILE() && !!current;
    if (want && !mapDownloadOn) { mapDownload.addTo(map); mapDownloadOn = true; }
    else if (!want && mapDownloadOn) { map.removeControl(mapDownload); mapDownloadOn = false; }
    if (want && mapDownload._link && current) {
      mapDownload._link.href = fileUrl(current.id);
      mapDownload._link.setAttribute('download', downloadName(current));
    }
  }
  L.control.scale({ imperial: false, position: 'bottomleft' }).addTo(map);

  map.on('baselayerchange', function (e) {
    try { localStorage.setItem('gpx.layer', e.name); } catch (err) { /* ignore */ }
  });

  /* ------------------------------------------------------------------ *
   * State
   * ------------------------------------------------------------------ */

  var tracks = [];
  var current = null;      // metadata of the selected track
  var trackLayer = null;   // L.LayerGroup for the drawn track
  var hoverMarker = null;
  var samples = [];        // [{d, ele, lat, lon, t}] for the profile
  var sortKey = 'date';
  var sortDir = -1;        // -1 newest/largest first
  var csrf = null;
  var signedIn = false;
  var apiAvailable = true; // false when hosted without PHP (static fallback)

  /** Where to fetch a track's file from — the PHP endpoint gives it a proper
   *  filename and Content-Disposition; the static path is the fallback. */
  function fileUrl(id) {
    return apiAvailable
      ? 'api/download.php?id=' + encodeURIComponent(id)
      : 'data/gpx/' + encodeURIComponent(id) + '.gpx';
  }
  /**
   * "2024.02.18 - Abbey St Bathans - 10.9km.gpx". Any part the track does not
   * have is dropped with its separator.
   *
   * Only used for the static fallback: when the API is available the server's
   * Content-Disposition sets the name, and takes precedence over a download
   * attribute. api/lib.php builds the identical string.
   */
  function downloadName(meta) {
    var parts = [];
    var d = displayDate(meta);
    if (d.iso) {
      var dt = new Date(d.iso);
      if (!isNaN(dt)) {
        var p2 = function (n) { return String(n).padStart(2, '0'); };
        parts.push(dt.getFullYear() + '.' + p2(dt.getMonth() + 1) + '.' + p2(dt.getDate()));
      }
    }
    var name = displayName(meta);
    if (name) parts.push(name);
    var m = meta.distance_m;
    if (typeof m === 'number' && m > 0) {
      parts.push((m / 1000).toFixed(m < 10000 ? 2 : 1) + 'km');
    }
    var base = parts.join(' - ') || meta.id;
    return (base.replace(/[^\w .-]+/g, '_').slice(0, 120) || meta.id) + '.gpx';
  }

  /* ------------------------------------------------------------------ *
   * Formatting helpers
   * ------------------------------------------------------------------ */

  function fmtKm(m) {
    if (m == null) return '–';
    return (m / 1000).toFixed(m < 10000 ? 2 : 1) + ' km';
  }
  function fmtDur(s) {
    if (s == null) return '–';
    s = Math.round(s);
    var h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60);
    if (h) return h + 'h ' + String(m).padStart(2, '0') + 'm';
    if (m) return m + 'm ' + String(s % 60).padStart(2, '0') + 's';
    return s + 's';
  }
  function fmtDate(iso, withTime) {
    if (!iso) return '–';
    var d = new Date(iso);
    if (isNaN(d)) return '–';
    var opt = { year: 'numeric', month: 'short', day: 'numeric' };
    if (withTime) { opt.hour = '2-digit'; opt.minute = '2-digit'; }
    return d.toLocaleDateString(undefined, opt);
  }
  function fmtEle(v) { return v == null ? '–' : Math.round(v) + ' m'; }

  /** Compact date for the list: 2026.03.15, matching how tracks are often named. */
  function fmtDateDot(iso) {
    if (!iso) return '–';
    var d = new Date(iso);
    if (isNaN(d)) return '–';
    var p2 = function (n) { return String(n).padStart(2, '0'); };
    return d.getFullYear() + '.' + p2(d.getMonth() + 1) + '.' + p2(d.getDate());
  }

  /**
   * A hint that this browser has signed in before.
   *
   * The session cookie is HttpOnly, so the page cannot see it and has no way to
   * tell a returning owner from a first-time visitor. Asking session.php on
   * every load would answer that, but session_start() writes a session file for
   * whoever asks — which is exactly what the endpoint was built to avoid. So we
   * remember locally that a sign-in happened and only ask on that basis; the
   * answer clears the hint when the session has since expired.
   */
  function sessionHint(on) {
    try {
      if (on) localStorage.setItem('gpx.signedin', '1');
      else localStorage.removeItem('gpx.signedin');
    } catch (e) { /* private mode */ }
  }
  function hasSessionHint() {
    try { return localStorage.getItem('gpx.signedin') === '1'; } catch (e) { return false; }
  }

  /* ------------------------------------------------------------------ *
   * Activity
   *
   * Most tracks are walks, so saying "Walking" on every one of them is a box
   * that never tells you anything. It is worth showing when the activity is
   * something else, or when the distance is long enough that how it was covered
   * is the interesting part.
   * ------------------------------------------------------------------ */

  var ACTIVITY_KM = 25;        // above this, show the activity whatever it is
  var ASSUME_CYCLING_KM = 45;  // above this, an unlabelled track is assumed a ride
  var ON_FOOT = /walk|hik|foot|trek|ramble|stroll|backpack/i;

  /** @returns {?string} the activity to show, or null to leave the box out. */
  function activityLabel(meta) {
    var a = String((meta && meta.activity) || '').trim();
    var km = ((meta && meta.distance_m) || 0) / 1000;

    if (a) {
      // Recorded: show it unless it is an ordinary walk of ordinary length.
      if (ON_FOOT.test(a) && km <= ACTIVITY_KM) return null;
    } else {
      // Nothing recorded: only a genuinely long track is worth a guess, and
      // between the two thresholds there is simply nothing to say.
      if (km <= ASSUME_CYCLING_KM) return null;
      a = 'Cycling';
    }
    a = a.charAt(0).toUpperCase() + a.slice(1);
    return a.length > 24 ? a.slice(0, 24) + '…' : a;
  }

  /* ------------------------------------------------------------------ *
   * Dates carried in the track name
   *
   * Exports are routinely named "2024.2.18 Abbey St Bathans". The date is shown
   * in its own column already, so repeating it in the title is noise — and
   * where the two disagree the name is the one to trust: it says when the walk
   * happened, while the timestamp inside the file can be whenever the track was
   * exported, copied or re-saved.
   * ------------------------------------------------------------------ */

  function titleDate(name) {
    var s = String(name || '');
    // No lookbehind: it is still missing from older Safari, and this file has
    // no build step to compile it away.
    var re = /(\d{4})\.(\d{1,2})\.(\d{1,2})/g;
    var m;
    while ((m = re.exec(s))) {
      var before = m.index === 0 ? '' : s.charAt(m.index - 1);
      var after = s.charAt(m.index + m[0].length);
      // Must stand alone: "1.2024.2.18" or "2024.2.18.4" is a version, not a date.
      if (/[0-9.]/.test(before) || /[0-9.]/.test(after)) continue;
      var y = +m[1], mo = +m[2], d = +m[3];
      var probe = new Date(y, mo - 1, d);
      // Rejects 2024.2.31, which Date would silently roll into March.
      if (probe.getFullYear() !== y || probe.getMonth() !== mo - 1 || probe.getDate() !== d) continue;
      return { y: y, m: mo, d: d, text: m[0], index: m.index };
    }
    return null;
  }

  /** The name with any such date removed, and the leftover punctuation tidied. */
  function displayName(meta) {
    var s = String((meta && (meta.name || meta.id)) || '');
    var t = titleDate(s);
    if (!t) return s;
    var out = (s.slice(0, t.index) + s.slice(t.index + t.text.length))
      .replace(/\s+/g, ' ')
      .replace(/^[\s\-–—_.,:]+/, '')
      .replace(/[\s\-–—_.,:]+$/, '');
    // Never blank the row: a track named only by its date keeps that name.
    return out || s;
  }

  /**
   * @returns {{iso: string, time: boolean}} the date to show, and whether it
   * carries a meaningful time of day (a date lifted from a title does not).
   */
  function displayDate(meta) {
    var raw = (meta && meta.date) || '';
    // A date set by hand is the final word: it was chosen with both the title
    // and the file in view, so nothing downstream should second-guess it. It
    // carries no meaningful time of day, so none is shown.
    if (meta && meta.date_manual) return { iso: raw, time: false };
    var t = titleDate(meta && meta.name);
    if (!t) return { iso: raw, time: true };
    var d = raw ? new Date(raw) : null;
    // Compared in local time because that is how the date is rendered.
    if (d && !isNaN(d) && d.getFullYear() === t.y && (d.getMonth() + 1) === t.m && d.getDate() === t.d) {
      return { iso: raw, time: true };          // they agree — keep the real timestamp
    }
    var p2 = function (n) { return String(n).padStart(2, '0'); };
    // No timezone suffix, so it parses as local midnight rather than shifting a
    // day in any zone behind UTC.
    return { iso: t.y + '-' + p2(t.m) + '-' + p2(t.d) + 'T00:00:00', time: false };
  }

  /** Sort key: the effective date, so a corrected one orders where it belongs. */
  function sortDate(t) {
    var d = displayDate(t);
    return d.iso ? String(d.iso).slice(0, 10) : '';
  }

  function toast(msg, ms) {
    var t = $('toast');
    t.textContent = msg;
    t.hidden = false;
    clearTimeout(toast._t);
    toast._t = setTimeout(function () { t.hidden = true; }, ms || 4200);
  }

  /**
   * Progress indicator for work with no measurable percentage — fetching and
   * parsing a track, which is a few milliseconds from cache and several seconds
   * for a 4 MB file on a phone.
   *
   * Showing it is deliberately delayed: an indicator that appears and vanishes
   * inside 100 ms reads as a glitch rather than as feedback, and a cached track
   * is drawn well inside that. Hiding is always immediate.
   */
  var loadingTimer = null;
  function showLoading(on, label) {
    var el = $('loading');
    clearTimeout(loadingTimer);
    if (!on) { el.hidden = true; return; }
    // Already on screen: swap the label in place rather than restarting the
    // delay, which would blink it off and on between two chained loads (the
    // catalogue arriving, then the deep-linked track it points at).
    if (!el.hidden) { $('loading-text').textContent = label || 'Loading…'; return; }
    loadingTimer = setTimeout(function () {
      $('loading-text').textContent = label || 'Loading…';
      el.hidden = false;
    }, 180);
  }

  /* ------------------------------------------------------------------ *
   * Panels
   * ------------------------------------------------------------------ */

  function panelOpen(id, open) {
    var el = $(id);
    var btn = id === 'panel-tracks' ? $('btn-tracks') : $('btn-info');
    if (MOBILE()) {
      el.classList.remove('collapsed');
      el.classList.toggle('open', open);
      if (open) {
        var other = id === 'panel-tracks' ? 'panel-info' : 'panel-tracks';
        $(other).classList.remove('open');
        (other === 'panel-tracks' ? $('btn-tracks') : $('btn-info')).setAttribute('aria-expanded', 'false');
      }
    } else {
      el.classList.remove('open');
      el.classList.toggle('collapsed', !open);
      setTimeout(function () { map.invalidateSize(); }, 210);
    }
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
  }
  function panelIsOpen(id) {
    var el = $(id);
    return MOBILE() ? el.classList.contains('open') : !el.classList.contains('collapsed');
  }

  $('btn-tracks').addEventListener('click', function () { panelOpen('panel-tracks', !panelIsOpen('panel-tracks')); });
  $('btn-info').addEventListener('click', function () { panelOpen('panel-info', !panelIsOpen('panel-info')); });
  Array.prototype.forEach.call(document.querySelectorAll('[data-close]'), function (b) {
    b.addEventListener('click', function () { panelOpen(b.getAttribute('data-close'), false); });
  });

  function layoutDefaults() {
    if (MOBILE()) {
      panelOpen('panel-tracks', false);
      panelOpen('panel-info', false);
    } else {
      panelOpen('panel-tracks', true);
      panelOpen('panel-info', !!current);
    }
  }
  var lastMobile = MOBILE();
  window.addEventListener('resize', function () {
    if (MOBILE() !== lastMobile) {
      lastMobile = MOBILE();
      layoutDefaults();
      showUploadBtn(altHeld);   // the rule differs either side of the breakpoint
      applyMobileChrome();
      vectorRenderer.options.tolerance = MOBILE() ? TAP_TOLERANCE : 0;
    }
    map.invalidateSize();
    if (current) drawProfile();
  });

  /* ------------------------------------------------------------------ *
   * Track list
   * ------------------------------------------------------------------ */

  /* ------------------------------------------------------------------ *
   * Countries and tags
   * ------------------------------------------------------------------ */

  function tagsOf(t) {
    return Array.isArray(t && t.tags) ? t.tags.filter(function (x) { return typeof x === 'string' && x; }) : [];
  }
  function countryOf(t) {
    return (t && typeof t.country === 'string') ? t.country : '';
  }

  /** Case-insensitive match against the country or any tag. */
  function hasLabel(t, label) {
    var want = label.toLowerCase();
    if (countryOf(t).toLowerCase() === want) return true;
    return tagsOf(t).some(function (x) { return x.toLowerCase() === want; });
  }

  function haystack(t) {
    // The stored name is searched as well as the displayed one, so a date typed
    // into a title still finds its track even though it is no longer shown.
    var d = displayDate(t);
    return [t.name, displayName(t), t.activity, countryOf(t), tagsOf(t).join(' '),
      (t.date || '').slice(0, 10),
      (d.iso || '').slice(0, 10), fmtDate(d.iso)].join(' ').toLowerCase();
  }

  function sortValue(t) {
    switch (sortKey) {
      // When it was added to the collection, not when it was walked — the two
      // diverge whenever an old export is uploaded.
      case 'added': return t.uploaded_at || '';
      case 'distance': return t.distance_m || 0;
      case 'elevation': return t.ele_spread == null ? -1 : t.ele_spread;
      case 'duration': return t.duration_s || 0;
      case 'name': return displayName(t).toLowerCase();
      default: return sortDate(t);
    }
  }

  /**
   * Bring a track's row into view. After an upload the new track is selected,
   * but with a long collection it can be sorted anywhere — including well off
   * the bottom of the list — so selecting it alone leaves you looking at a
   * highlighted row you cannot see.
   */
  function scrollListTo(id) {
    var rows = $('track-list').querySelectorAll('button.track');
    for (var i = 0; i < rows.length; i++) {
      if (rows[i].dataset.id === id) {
        if (rows[i].scrollIntoView) {
          rows[i].scrollIntoView({ block: 'center', behavior: 'smooth' });
        }
        return true;
      }
    }
    return false;   // filtered out of the current view
  }

  function renderList() {
    var q = $('search').value.trim().toLowerCase();
    var terms = q ? q.split(/\s+/) : [];
    var label = $('filter-tag').value;
    // A live filter is worth signalling: tracks missing from the list because
    // of it look identical to tracks that are not there at all.
    $('filter-tag').classList.toggle('active', !!label);
    var shown = tracks.filter(function (t) {
      if (label && !hasLabel(t, label)) return false;
      if (!terms.length) return true;
      var h = haystack(t);
      return terms.every(function (term) { return h.indexOf(term) !== -1; });
    });

    shown.sort(function (a, b) {
      var va = sortValue(a), vb = sortValue(b);
      if (va < vb) return -1 * sortDir;
      if (va > vb) return 1 * sortDir;
      return displayName(a).localeCompare(displayName(b));
    });

    var ul = $('track-list');
    ul.textContent = '';
    shown.forEach(function (t) {
      var li = document.createElement('li');
      li.className = 'trackrow';
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'track' + (current && current.id === t.id ? ' active' : '');
      b.dataset.id = t.id;

      var n = document.createElement('span');
      n.className = 'tname';
      n.textContent = displayName(t);

      var m = document.createElement('span');
      m.className = 'tmeta';
      // Country (when there is one), distance, date. The " · " between them is
      // a CSS ::before on each span after the first, so order is all that
      // matters here and a missing country leaves no stray separator.
      var bits = [];
      var country = countryOf(t);
      if (country) bits.push(country);
      bits.push(fmtKm(t.distance_m));
      bits.push(fmtDateDot(displayDate(t).iso));
      bits.forEach(function (piece) {
        var s = document.createElement('span');
        s.textContent = piece;
        m.appendChild(s);
      });

      b.appendChild(n);
      b.appendChild(m);
      if (picked[t.id]) b.classList.add('picked');

      b.addEventListener('click', function (e) {
        // Signed out, both shortcuts are ordinary clicks, so a visitor cannot
        // tell they exist.
        if (signedIn && e.shiftKey) {
          e.preventDefault();
          togglePicked(t.id);
          return;
        }
        if (signedIn && e.altKey) {
          e.preventDefault();
          openEditor(t);
          return;
        }
        if (anyPicked()) clearPicked();
        selectTrack(t.id, true);
      });

      // Right-click anywhere in the selection edits the whole selection.
      b.addEventListener('contextmenu', function (e) {
        if (!signedIn || !picked[t.id]) return;   // otherwise the browser menu
        e.preventDefault();
        openBulkEditor();
      });

      // Every track can be downloaded straight from the list, selected or not.
      var dl = document.createElement('a');
      dl.className = 'dlbtn';
      dl.href = fileUrl(t.id);
      dl.setAttribute('download', downloadName(t));
      dl.title = 'Download ' + (t.name || t.id) + ' as GPX';
      dl.setAttribute('aria-label', dl.title);
      dl.textContent = '⤓';
      dl.addEventListener('click', function (ev) { ev.stopPropagation(); });

      li.appendChild(b);
      li.appendChild(dl);
      ul.appendChild(li);
    });

    // Pins follow the list: a dot for a track the filter has hidden would be a
    // way to select something you were told is not there.
    renderPins(shown);

    $('list-empty').hidden = shown.length !== 0;
    $('btn-showall').hidden = !current;
    applyMobileChrome();

    var n = pickedIds().length;
    $('track-count').textContent = n
      ? '(' + n + ' selected)'
      : ((terms.length || label)
        ? '(' + shown.length + '/' + tracks.length + ')'
        : '(' + tracks.length + ')');
    $('pick-hint').hidden = !n;
  }

  $('search').addEventListener('input', renderList);
  $('sort').addEventListener('change', function () {
    sortKey = this.value;
    sortDir = (sortKey === 'name') ? 1 : -1;
    $('sort-dir').textContent = sortDir < 0 ? '↓' : '↑';
    renderList();
  });
  $('sort-dir').addEventListener('click', function () {
    sortDir = -sortDir;
    this.textContent = sortDir < 0 ? '↓' : '↑';
    renderList();
  });

  /* ------------------------------------------------------------------ *
   * Loading the catalogue
   * ------------------------------------------------------------------ */

  function loadCatalogue() {
    // no-store: after an upload the list must not come back from the cache.
    return fetch(API_LIST, {
      credentials: 'same-origin', cache: 'no-store', headers: { 'Accept': 'application/json' }
    })
      .then(function (r) { if (!r.ok) throw new Error('list ' + r.status); return r.json(); })
      .then(function (data) {
        if (!data || !Array.isArray(data.tracks)) throw new Error('bad catalogue');
        apiAvailable = true;
        return data;
      })
      .catch(function () {
        apiAvailable = false;      // no PHP: fall back to the flat catalogue
        return fetch(FALLBACK_LIST, { cache: 'no-store' }).then(function (r) {
          if (!r.ok) throw new Error('no catalogue');
          return r.json();
        });
      })
      .then(function (data) {
        tracks = Array.isArray(data.tracks) ? data.tracks.filter(function (t) {
          return t && typeof t.id === 'string' && /^[a-z0-9-]{1,120}$/.test(t.id);
        }) : [];
        rebuildTagFilter();
        renderList();
        return tracks;
      });
  }

  /* ------------------------------------------------------------------ *
   * Selecting and drawing a track
   * ------------------------------------------------------------------ */

  function parseGpx(text) {
    var doc = new DOMParser().parseFromString(text, 'application/xml');
    if (doc.getElementsByTagName('parsererror').length) throw new Error('Malformed GPX');
    var pts = doc.getElementsByTagName('trkpt');
    if (!pts.length) pts = doc.getElementsByTagName('rtept');
    var segs = [], cur = [], lastParent = null, out = [];
    for (var i = 0; i < pts.length; i++) {
      var p = pts[i];
      var lat = parseFloat(p.getAttribute('lat'));
      var lon = parseFloat(p.getAttribute('lon'));
      if (!isFinite(lat) || !isFinite(lon)) continue;
      var eleEl = p.getElementsByTagName('ele')[0];
      var timeEl = p.getElementsByTagName('time')[0];
      var ele = eleEl ? parseFloat(eleEl.textContent) : null;
      var t = timeEl ? Date.parse(timeEl.textContent) : NaN;
      var parent = p.parentNode;
      if (parent !== lastParent) {
        if (cur.length) segs.push(cur);
        cur = [];
        lastParent = parent;
      }
      var rec = { lat: lat, lon: lon, ele: isFinite(ele) ? ele : null, t: isFinite(t) ? t : null };
      cur.push(rec);
      out.push(rec);
    }
    if (cur.length) segs.push(cur);

    var wpts = [];
    var w = doc.getElementsByTagName('wpt');
    for (var j = 0; j < w.length && j < 500; j++) {
      var wlat = parseFloat(w[j].getAttribute('lat')), wlon = parseFloat(w[j].getAttribute('lon'));
      if (!isFinite(wlat) || !isFinite(wlon)) continue;
      var nm = w[j].getElementsByTagName('name')[0];
      wpts.push({ lat: wlat, lon: wlon, name: nm ? nm.textContent : '' });
    }
    var nameEl = doc.getElementsByTagName('name')[0];
    return { segments: segs, points: out, waypoints: wpts, name: nameEl ? nameEl.textContent : '' };
  }

  function haversine(a, b) {
    var R = 6371008.8, rad = Math.PI / 180;
    var dLat = (b.lat - a.lat) * rad, dLon = (b.lon - a.lon) * rad;
    var la1 = a.lat * rad, la2 = b.lat * rad;
    var h = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
            Math.cos(la1) * Math.cos(la2) * Math.sin(dLon / 2) * Math.sin(dLon / 2);
    return 2 * R * Math.asin(Math.min(1, Math.sqrt(h)));
  }

  /** Cumulative distance for every point; used by the elevation profile. */
  function buildSamples(parsed) {
    var acc = 0, prev = null, arr = [];
    parsed.segments.forEach(function (seg) {
      prev = null;
      seg.forEach(function (p) {
        if (prev) {
          // Same plausibility rule the server uses, so the profile's x-axis
          // matches the stored distance.
          var d = haversine(prev, p);
          var dt = (prev.t != null && p.t != null) ? (p.t - prev.t) / 1000 : null;
          var ok = (dt != null && dt > 0) ? (d <= 500 || (d / dt) <= 70) : (d <= 100000);
          if (ok) acc += d;
        }
        arr.push({ d: acc, ele: p.ele, lat: p.lat, lon: p.lon, t: p.t });
        prev = p;
      });
    });
    return arr;
  }

  /* ------------------------------------------------------------------ *
   * Track pins
   *
   * One dot per track so the collection is visible on the map rather than only
   * in the list. The point is the centre of the track's bounding box, which is
   * already in the catalogue — no re-ingest, and it works for tracks stored
   * before this existed. It is a locator, not a waypoint: on a C-shaped route
   * the centre of the box can sit off the path itself, which is fine for "the
   * walk is over there" and is why clicking draws the real line.
   * ------------------------------------------------------------------ */

  // Past this many the map is a wall of dots and tells you nothing, so the list
  // does the work instead. Filter or search to bring them back.
  var MAX_PINS = 500;

  var pinLayer = null;

  /* ------------------------------------------------------------------ *
   * Multiple selection (signed in): shift-click to pick, right-click to edit
   * ------------------------------------------------------------------ */

  var picked = {};

  function anyPicked() {
    for (var k in picked) { if (Object.prototype.hasOwnProperty.call(picked, k)) return true; }
    return false;
  }
  function pickedIds() {
    return Object.keys(picked).filter(function (id) {
      return tracks.some(function (t) { return t.id === id; });
    });
  }
  function togglePicked(id) {
    if (picked[id]) delete picked[id]; else picked[id] = true;
    renderList();
  }
  function clearPicked() {
    if (!anyPicked()) return;
    picked = {};
    renderList();
  }

  function pinPoint(t) {
    var b = t && t.bounds;
    if (!b || !b[0] || !b[1]) return null;
    var lat = (b[0][0] + b[1][0]) / 2;
    var lon = (b[0][1] + b[1][1]) / 2;
    return (isFinite(lat) && isFinite(lon)) ? [lat, lon] : null;
  }

  /** @param list the tracks currently shown in the list, so pins and list agree. */
  function renderPins(list) {
    if (pinLayer) { map.removeLayer(pinLayer); pinLayer = null; }
    if (list.length > MAX_PINS) return;

    var group = L.layerGroup();
    var any = false;
    list.forEach(function (t) {
      // The selected track has its own line and end markers; a dot in the
      // middle of it would just be one more thing to click by accident.
      if (current && current.id === t.id) return;
      var p = pinPoint(t);
      if (!p) return;

      var mk = L.circleMarker(p, {
        radius: 5, weight: 2, color: '#ffffff',
        fillColor: '#334155', fillOpacity: 1
      });
      // An element, not a string: Leaflet assigns tooltip content with
      // innerHTML, and a track name is user input.
      var label = document.createElement('span');
      label.textContent = displayName(t) + ' · ' + fmtKm(t.distance_m);
      mk.bindTooltip(label, { direction: 'top', offset: [0, -6] });

      mk.on('click', function (e) {
        L.DomEvent.stopPropagation(e);
        selectTrack(t.id, true).then(function () { panelOpen('panel-info', true); });
      });
      mk.addTo(group);
      any = true;
    });
    if (any) pinLayer = group.addTo(map);
  }

  /**
   * Back to the whole collection: drop the selection, forget it in the URL, and
   * return to the opening view. The counterpart to selecting a track, which
   * otherwise leaves you zoomed into one walk with no obvious way back out.
   */
  function showAll() {
    clearTrack();
    current = null;
    setUrl(null);
    // "Everything" means everything: a country or tag still filtering, or a
    // search still typed, would leave most of the collection hidden behind a
    // button that says it is showing all of it.
    $('search').value = '';
    $('filter-tag').value = '';
    rebuildTagFilter();
    $('btn-info').disabled = true;
    clearInfoPanel();
    if (MOBILE()) panelOpen('panel-info', false);
    renderList();
    homeView();
  }

  /** Everything in the trip panel that only makes sense with a track selected. */
  function clearInfoPanel() {
    $('info-name').textContent = 'Trip';
    $('track-title').textContent = '';
    document.title = 'My Tracks';
    $('stats').textContent = '';
    $('profile').textContent = '';
    $('profile-wrap').hidden = true;
    $('tag-row').hidden = true;        // the download rides along with it
    $('info-empty').hidden = false;
  }

  function clearTrack() {
    if (trackLayer) { map.removeLayer(trackLayer); trackLayer = null; }
    if (hoverMarker) { map.removeLayer(hoverMarker); hoverMarker = null; }
    samples = [];
  }

  function drawTrack(parsed) {
    clearTrack();
    var group = L.layerGroup();
    var latlngs = parsed.segments.map(function (seg) {
      return seg.map(function (p) { return [p.lat, p.lon]; });
    }).filter(function (s) { return s.length > 1; });

    // An invisible fat line under the visible ones: a 3.5px stroke is a hopeless
    // tap target on a phone. Canvas hit-testing goes by geometry and weight
    // rather than painted pixels, so a transparent line still takes the tap.
    var hit = L.polyline(latlngs, {
      color: '#000', weight: 24, opacity: 0, lineCap: 'round', lineJoin: 'round', smoothFactor: 1.2
    }).addTo(group);

    // Casing underneath, coloured line on top — readable over satellite imagery.
    var casing = L.polyline(latlngs, { color: '#ffffff', weight: 7, opacity: 0.85, lineJoin: 'round', smoothFactor: 1.2 }).addTo(group);
    var line = L.polyline(latlngs, { color: '#c2410c', weight: 3.5, opacity: 1, lineJoin: 'round', smoothFactor: 1.2 }).addTo(group);

    // Tapping the track opens the trip info for it. Harmless when the panel is
    // already showing, and on a phone it also closes the track list.
    [hit, casing, line].forEach(function (l) {
      l.on('click', function () { panelOpen('panel-info', true); });
    });

    var first = parsed.points[0], last = parsed.points[parsed.points.length - 1];
    if (first) {
      L.circleMarker([first.lat, first.lon], { radius: 6, color: '#fff', weight: 2, fillColor: '#16a34a', fillOpacity: 1 })
        .bindPopup('Start').addTo(group);
    }
    if (last) {
      L.circleMarker([last.lat, last.lon], { radius: 6, color: '#fff', weight: 2, fillColor: '#dc2626', fillOpacity: 1 })
        .bindPopup('Finish').addTo(group);
    }
    parsed.waypoints.forEach(function (w) {
      var mk = L.circleMarker([w.lat, w.lon], { radius: 5, color: '#fff', weight: 2, fillColor: '#2563eb', fillOpacity: 1 });
      if (w.name) {
        // Leaflet assigns string popup content with innerHTML, so pass a node.
        var pop = document.createElement('span');
        pop.textContent = w.name;
        mk.bindPopup(pop);
      }
      mk.addTo(group);
    });

    group.addTo(map);
    trackLayer = group;
    map.fitBounds(line.getBounds(), { padding: [30, 30] });
  }

  function renderStats(meta) {
    var shownName = displayName(meta);
    $('info-name').textContent = shownName;
    $('track-title').textContent = shownName;
    document.title = (shownName ? shownName + ' \u00b7 ' : '') + 'My Tracks';

    // The panel describes a track, so none of it belongs on screen without one.
    $('info-empty').hidden = true;
    $('profile-wrap').hidden = false;

    var dl = $('dl-link');
    dl.href = fileUrl(meta.id);
    dl.setAttribute('download', downloadName(meta));
    var view = $('view-link');
    if (view) {
      view.href = apiAvailable
        ? 'api/download.php?view=1&id=' + encodeURIComponent(meta.id)
        : 'data/gpx/' + encodeURIComponent(meta.id) + '.gpx';
    }

    var m = function (v) { return v == null ? '–' : Math.round(v); };
    var rows = [
      ['Distance', fmtKm(meta.distance_m)],
      ['Duration', fmtDur(meta.duration_s)],
      ['Low / high', m(meta.ele_min) + ' / ' + m(meta.ele_max) + ' m'],
      ['Up / down', m(meta.ele_gain) + ' / ' + m(meta.ele_loss) + ' m'],
      ['Date', (function () { var d = displayDate(meta); return fmtDate(d.iso, d.time); })()]
    ];
    var activity = activityLabel(meta);
    if (activity) rows.push(['Activity', activity]);

    renderTagRow(meta);

    var dlist = $('stats');
    dlist.textContent = '';
    rows.forEach(function (r) {
      var wrap = document.createElement('div');
      var dt = document.createElement('dt');
      dt.textContent = r[0];
      var dd = document.createElement('dd');
      dd.textContent = r[1];
      wrap.appendChild(dt);
      wrap.appendChild(dd);
      dlist.appendChild(wrap);
    });
  }

  /**
   * This track's tags, each one a filter. Shown here rather than in the filter
   * menu because tags belong to a track: you find one by looking at a walk that
   * has it, not by reading a list of every tag in the collection.
   */
  function renderTagRow(meta) {
    var row = $('tag-row');
    // Held before the clear, which detaches it: the download lives inside this
    // row so it flows after the last tag instead of taking a line of its own.
    var dl = $('dl-row');
    row.textContent = '';
    row.hidden = false;
    var tags = tagsOf(meta);
    var country = countryOf(meta);

    var active = $('filter-tag').value.toLowerCase();
    var add = function (label, isCountry) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'tagbtn' + (isCountry ? ' country' : '')
        + (label.toLowerCase() === active ? ' on' : '');
      b.textContent = label;
      b.setAttribute('aria-pressed', label.toLowerCase() === active ? 'true' : 'false');
      b.title = label.toLowerCase() === active
        ? 'Showing only ' + label + ' — click to show everything'
        : 'Show only tracks tagged ' + label;
      b.addEventListener('click', function () {
        // Clicking the tag that is already filtering clears it, so the button
        // you used to narrow the list is also the way back out.
        setLabelFilter(label.toLowerCase() === active ? '' : label);
      });
      row.appendChild(b);
    };
    if (country) add(country, true);
    tags.forEach(function (t) { add(t, false); });
    row.appendChild(dl);          // always last, however many tags there are
  }

  /**
   * The one filter control. A tag has no permanent entry in the menu, so one is
   * added on the fly when a tag is doing the filtering.
   */
  function setLabelFilter(label) {
    var sel = $('filter-tag');
    // Rebuild from scratch first: otherwise the entry added for a tag stays in
    // the menu after the filter is cleared, and they pile up one per tag used.
    sel.value = '';
    rebuildTagFilter();
    if (label && !Array.prototype.some.call(sel.options, function (o) { return o.value === label; })) {
      var g = document.createElement('optgroup');
      g.label = 'Tag';
      var o = document.createElement('option');
      o.value = label;
      o.textContent = label;
      g.appendChild(o);
      sel.appendChild(g);
    }
    sel.value = label;
    if (label) sel.hidden = false;
    renderList();
    if (current) renderTagRow(current);

    // On a phone the list is off screen, so filtering it silently looks like
    // nothing happened. Swap the trip sheet for the list it just narrowed.
    if (MOBILE() && label) {
      panelOpen('panel-info', false);
      panelOpen('panel-tracks', true);
    }
  }

  /* ------------------------------------------------------------------ *
   * Elevation profile (hand-drawn SVG, hover-linked to the map)
   * ------------------------------------------------------------------ */

  var SVGNS = 'http://www.w3.org/2000/svg';
  function svgEl(name, attrs) {
    var e = document.createElementNS(SVGNS, name);
    for (var k in attrs) if (Object.prototype.hasOwnProperty.call(attrs, k)) e.setAttribute(k, attrs[k]);
    return e;
  }

  function drawProfile() {
    var host = $('profile');
    host.textContent = '';
    $('profile-readout').textContent = ' ';

    var withEle = samples.filter(function (s) { return s.ele != null; });
    if (withEle.length < 2) {
      var p = document.createElement('p');
      p.className = 'readout';
      p.textContent = 'No elevation data in this track.';
      host.appendChild(p);
      return;
    }

    var W = Math.max(240, host.clientWidth || 300), H = host.clientHeight || 150;
    var padL = 38, padR = 8, padT = 8, padB = 20;
    var innerW = W - padL - padR, innerH = H - padT - padB;

    var totalD = samples[samples.length - 1].d || 1;
    var eleMin = Infinity, eleMax = -Infinity;
    withEle.forEach(function (s) { if (s.ele < eleMin) eleMin = s.ele; if (s.ele > eleMax) eleMax = s.ele; });
    if (eleMax - eleMin < 10) { eleMax = eleMin + 10; }
    var padEle = (eleMax - eleMin) * 0.08;
    var yMin = eleMin - padEle, yMax = eleMax + padEle;

    var X = function (d) { return padL + (d / totalD) * innerW; };
    var Y = function (e) { return padT + innerH - ((e - yMin) / (yMax - yMin)) * innerH; };

    // Downsample to roughly one point per horizontal pixel.
    var step = Math.max(1, Math.floor(withEle.length / Math.max(120, innerW)));
    var pts = [];
    for (var i = 0; i < withEle.length; i += step) pts.push(withEle[i]);
    if (pts[pts.length - 1] !== withEle[withEle.length - 1]) pts.push(withEle[withEle.length - 1]);

    var svg = svgEl('svg', { viewBox: '0 0 ' + W + ' ' + H, preserveAspectRatio: 'none', role: 'img' });
    svg.setAttribute('aria-label', 'Elevation profile');

    // Horizontal grid + labels
    for (var g = 0; g <= 3; g++) {
      var ev = yMin + (yMax - yMin) * (g / 3);
      var yy = Y(ev);
      svg.appendChild(svgEl('line', { class: 'grid', x1: padL, y1: yy, x2: W - padR, y2: yy }));
      var lab = svgEl('text', { class: 'axis', x: 4, y: yy + 3.5 });
      lab.textContent = Math.round(ev) + 'm';
      svg.appendChild(lab);
    }

    var d = 'M' + pts.map(function (s) { return X(s.d).toFixed(1) + ' ' + Y(s.ele).toFixed(1); }).join(' L');
    svg.appendChild(svgEl('path', { class: 'area', d: d + ' L' + X(pts[pts.length - 1].d).toFixed(1) + ' ' + (padT + innerH) + ' L' + X(pts[0].d).toFixed(1) + ' ' + (padT + innerH) + ' Z' }));
    svg.appendChild(svgEl('path', { class: 'line', d: d }));

    // Distance axis labels
    [0, 0.5, 1].forEach(function (f) {
      var tx = svgEl('text', { class: 'axis', x: X(totalD * f), y: H - 6, 'text-anchor': f === 0 ? 'start' : (f === 1 ? 'end' : 'middle') });
      tx.textContent = (totalD * f / 1000).toFixed(1) + ' km';
      svg.appendChild(tx);
    });

    var cursor = svgEl('line', { class: 'cursor', x1: 0, y1: padT, x2: 0, y2: padT + innerH, opacity: 0 });
    var dot = svgEl('circle', { class: 'dot', r: 4, cx: 0, cy: 0, opacity: 0 });
    svg.appendChild(cursor);
    svg.appendChild(dot);
    host.appendChild(svg);

    function moveTo(clientX) {
      var rect = svg.getBoundingClientRect();
      var rel = (clientX - rect.left) / rect.width * W;
      var dist = Math.min(totalD, Math.max(0, (rel - padL) / innerW * totalD));
      // binary search the nearest sample by distance
      var lo = 0, hi = withEle.length - 1;
      while (lo < hi) {
        var mid = (lo + hi) >> 1;
        if (withEle[mid].d < dist) lo = mid + 1; else hi = mid;
      }
      var s = withEle[lo];
      cursor.setAttribute('x1', X(s.d)); cursor.setAttribute('x2', X(s.d));
      cursor.setAttribute('opacity', 1);
      dot.setAttribute('cx', X(s.d)); dot.setAttribute('cy', Y(s.ele));
      dot.setAttribute('opacity', 1);
      $('profile-readout').textContent = (s.d / 1000).toFixed(2) + ' km · ' + Math.round(s.ele) + ' m' +
        (s.t ? ' · ' + new Date(s.t).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' }) : '');
      if (!hoverMarker) {
        hoverMarker = L.circleMarker([s.lat, s.lon], {
          radius: 6, color: '#fff', weight: 2, fillColor: '#c2410c', fillOpacity: 1,
          className: 'hover-dot', interactive: false
        }).addTo(map);
      } else {
        hoverMarker.setLatLng([s.lat, s.lon]);
      }
    }

    function leave() {
      cursor.setAttribute('opacity', 0);
      dot.setAttribute('opacity', 0);
      $('profile-readout').textContent = ' ';
      if (hoverMarker) { map.removeLayer(hoverMarker); hoverMarker = null; }
    }

    svg.addEventListener('mousemove', function (e) { moveTo(e.clientX); });
    svg.addEventListener('mouseleave', leave);
    svg.addEventListener('touchstart', function (e) { moveTo(e.touches[0].clientX); }, { passive: true });
    svg.addEventListener('touchmove', function (e) { moveTo(e.touches[0].clientX); }, { passive: true });
    svg.addEventListener('touchend', leave);
  }

  /* ------------------------------------------------------------------ *
   * Selection
   * ------------------------------------------------------------------ */

  /**
   * The only query parameter this site uses.
   *
   * Everything else is dropped rather than blocked by name: Facebook adds
   * fbclid, Google gclid, ad campaigns utm_*, Instagram igshid, and there will
   * be another one next year. Keeping what is ours and discarding the rest
   * needs no list to maintain, and means a link copied out of the address bar
   * never carries someone else's tracking id onwards.
   */
  var OWN_PARAMS = ['track'];
  var TRACK_ID = /^[a-z0-9-]{1,120}$/;

  function setUrl(id) {
    var url = new URL(window.location.href);
    var keep = new URLSearchParams();
    OWN_PARAMS.forEach(function (k) {
      var v = url.searchParams.get(k);
      if (v !== null) keep.set(k, v);
    });
    if (id) keep.set('track', id); else keep.delete('track');
    // Never echo a malformed id back into the address bar.
    var t = keep.get('track');
    if (t !== null && !TRACK_ID.test(t)) keep.delete('track');
    url.search = keep.toString();
    history.replaceState(null, '', url.toString());
  }

  /**
   * Strip the URL immediately, before anything is fetched, so it is clean even
   * if the catalogue never loads — and so nothing else has a chance to read a
   * parameter that should not be there.
   */
  function cleanUrl() {
    setUrl(new URLSearchParams(window.location.search).get('track'));
  }

  var loadToken = 0;
  function selectTrack(id, userInitiated) {
    var meta = tracks.filter(function (t) { return t.id === id; })[0];
    if (!meta) { toast('Track not found: ' + id); setUrl(null); return Promise.resolve(); }

    var token = ++loadToken;
    current = meta;
    renderList();
    setUrl(id);
    $('btn-info').disabled = false;
    renderStats(meta);

    showLoading(true, 'Loading ' + (meta.name || 'track') + '…');

    return fetch('data/gpx/' + encodeURIComponent(id) + '.gpx', { cache: 'force-cache' })
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
      .then(function (text) {
        // A newer selection owns the indicator now, so leave it alone: hiding it
        // here would clear the spinner for the track still loading.
        if (token !== loadToken) return;           // a newer selection won
        var parsed = parseGpx(text);
        if (!parsed.points.length) throw new Error('no points');
        drawTrack(parsed);
        samples = buildSamples(parsed);
        renderStats(meta);
        drawProfile();
        if (MOBILE()) {
          if (userInitiated) panelOpen('panel-tracks', false);
        } else {
          panelOpen('panel-info', true);
        }
        showLoading(false);
      })
      .catch(function (err) {
        if (token !== loadToken) return;
        showLoading(false);
        toast('Could not load track: ' + err.message);
        clearTrack();
      });
  }

  /* ------------------------------------------------------------------ *
   * Upload dialog
   * ------------------------------------------------------------------ */

  var modal = $('modal');

  function showModal(show) {
    modal.hidden = !show;
    if (!show) showUploadBtn(altHeld);
    if (show) {
      refreshSession().then(function () {
        var target = $('login-form').hidden ? $('file') : $('login-pass');
        if (target) target.focus();
      });
    }
  }
  /* ------------------------------------------------------------------ *
   * The Upload button, revealed by holding Option/Alt
   * ------------------------------------------------------------------ */

  var altHeld = false;

  function showUploadBtn(on) {
    // A phone has no Option key, so hiding it there would hide the only way
    // in to signing in, uploading and editing. It stays, toned down in CSS to
    // the same weight as the other buttons.
    if (MOBILE()) {
      // …unless a track is selected, where the bar is deliberately bare.
      $('btn-upload').hidden = !!current;
      return;
    }
    // Never take it away while the dialog it opened is still on screen.
    if (!on && !$('modal').hidden) return;
    $('btn-upload').hidden = !on;
  }

  document.addEventListener('keydown', function (e) {
    if (e.altKey || e.key === 'Alt') { altHeld = true; showUploadBtn(true); }
  });
  document.addEventListener('keyup', function (e) {
    if (!e.altKey || e.key === 'Alt') { altHeld = false; showUploadBtn(false); }
  });
  // Alt-Tabbing away releases the key somewhere else, so the keyup never
  // arrives and the button would stay visible for good.
  function forgetAlt() { altHeld = false; showUploadBtn(false); }
  window.addEventListener('blur', forgetAlt);
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) forgetAlt();
  });

  $('btn-upload').addEventListener('click', function () { showModal(true); });
  $('modal-close').addEventListener('click', function () { showModal(false); });
  modal.addEventListener('click', function (e) { if (e.target === modal) showModal(false); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      if (!modal.hidden) showModal(false);
      else if (MOBILE()) { panelOpen('panel-tracks', false); panelOpen('panel-info', false); }
    }
  });

  function setAuthUi(authed) {
    signedIn = !!authed;
    $('login-form').hidden = !!authed;
    $('upload-form').hidden = !authed;
    if (!authed) {
      $('incoming').hidden = true;
      $('recompact').hidden = true;
      $('manage').hidden = true;
      editorOpen(false);
      $('btn-manage').setAttribute('aria-expanded', 'false');
    } else {
      checkIncoming();
      checkRecompact();
    }
  }

  /* ------------------------------------------------------------------ *
   * Drop folder (data/incoming/)
   * ------------------------------------------------------------------ */

  // Only ever called for a signed-in session: the endpoint 401s otherwise, and
  // a visitor is told nothing about what is waiting.
  function checkIncoming() {
    return fetch('api/incoming.php', {
      credentials: 'same-origin', cache: 'no-store', headers: { 'Accept': 'application/json' }
    })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        var n = d && d.ok ? d.pending : 0;
        $('incoming').hidden = !n;
        if (n) {
          $('incoming-hint').textContent = n === 1
            ? '1 file is waiting in data/incoming/.'
            : n + ' files are waiting in data/incoming/.';
          $('incoming-go').disabled = false;
          $('incoming-go').textContent = n === 1 ? 'Index it' : 'Index them';
        }
        return n;
      })
      .catch(function () { $('incoming').hidden = true; return 0; });
  }

  /**
   * Adopt one file per request until the queue is empty, so a large backlog
   * cannot hit max_execution_time and progress is real rather than guessed.
   * Each response reports what remains, which is what drives the bar.
   */
  function runIncoming() {
    var go = $('incoming-go');
    var bar = $('incoming-bar');
    var status = $('incoming-status');
    var errs = $('incoming-errs');
    var total = 0, done = 0, added = 0;
    var failures = [];

    go.disabled = true;
    errs.hidden = true;
    errs.textContent = '';
    status.hidden = false;
    $('incoming-progress').hidden = false;
    bar.style.width = '0%';

    function step() {
      var body = new FormData();
      body.append('action', 'adopt');
      body.append('csrf', csrf || '');
      return fetch('api/incoming.php', { method: 'POST', body: body, credentials: 'same-origin' })
        .then(function (r) {
          return r.json().then(function (d) {
            if (!r.ok || !d.ok) throw new Error(d.error || ('HTTP ' + r.status));
            return d;
          });
        })
        .then(function (d) {
          if (d.name === null || d.name === undefined) { return false; }
          done++;
          if (total === 0) total = done + d.remaining;
          if (d.done) added++; else failures.push({ name: d.name, error: d.error });
          bar.style.width = Math.round((done / Math.max(total, 1)) * 100) + '%';
          status.textContent = 'Indexed ' + done + ' of ' + total + ' — ' + d.name;
          return d.remaining > 0;
        });
    }

    function loop() {
      return step().then(function (more) { return more ? loop() : null; });
    }

    return loop()
      .then(function () {
        bar.style.width = '100%';
        status.textContent = added + ' track' + (added === 1 ? '' : 's') + ' added'
          + (failures.length ? ', ' + failures.length + ' skipped' : '') + '.';
        if (failures.length) {
          errs.hidden = false;
          failures.forEach(function (f) {
            var li = document.createElement('li');
            var b = document.createElement('strong');
            b.textContent = f.name;
            li.appendChild(b);
            li.appendChild(document.createTextNode(' — ' + (f.error || 'refused')));
            errs.appendChild(li);
          });
        }
        return loadCatalogue();
      })
      .then(function () {
        if (added) toast(added + ' track' + (added === 1 ? '' : 's') + ' added from the drop folder.');
        return checkIncoming();
      })
      .catch(function (e) {
        status.textContent = 'Stopped: ' + e.message;
        go.disabled = false;
      });
  }

  $('incoming-go').addEventListener('click', runIncoming);

  /* ------------------------------------------------------------------ *
   * Rebuilding stored tracks in the current file format
   * ------------------------------------------------------------------ */

  function checkRecompact() {
    return fetch('api/rebuild.php', {
      credentials: 'same-origin', cache: 'no-store', headers: { 'Accept': 'application/json' }
    })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        var n = d && d.ok ? d.pending : 0;
        $('recompact').hidden = !n;
        if (n) {
          $('recompact-hint').textContent = n === 1
            ? '1 track is stored in the older, larger format.'
            : n + ' tracks are stored in the older, larger format.';
          $('recompact-go').disabled = false;
          $('recompact-go').textContent = n === 1 ? 'Rebuild it' : 'Rebuild them';
        }
        return n;
      })
      .catch(function () { $('recompact').hidden = true; return 0; });
  }

  /** One track per request, so a big library cannot time the server out. */
  function runRecompact() {
    var go = $('recompact-go');
    var bar = $('recompact-bar');
    var status = $('recompact-status');
    var total = 0, done = 0, saved = 0;

    go.disabled = true;
    status.hidden = false;
    $('recompact-progress').hidden = false;
    bar.style.width = '0%';

    function step() {
      var body = new FormData();
      body.append('action', 'recompact');
      body.append('csrf', csrf || '');
      return fetch('api/rebuild.php', { method: 'POST', body: body, credentials: 'same-origin' })
        .then(function (r) {
          return r.json().then(function (d) {
            if (!r.ok || !d.ok) throw new Error(d.error || ('HTTP ' + r.status));
            return d;
          });
        })
        .then(function (d) {
          if (!d.id) return false;
          done++;
          saved += Math.max(0, (d.before || 0) - (d.after || 0));
          if (total === 0) total = done + d.remaining;
          bar.style.width = Math.round((done / Math.max(total, 1)) * 100) + '%';
          status.textContent = 'Rebuilt ' + done + ' of ' + total + ' — ' + (d.name || d.id);
          return d.remaining > 0;
        });
    }

    function loop() {
      return step().then(function (more) { return more ? loop() : null; });
    }

    return loop()
      .then(function () { return loadCatalogue(); })
      .then(function () {
        var mb = saved / 1048576;
        status.textContent = done + ' track' + (done === 1 ? '' : 's') + ' rebuilt, '
          + (mb >= 0.1 ? mb.toFixed(1) + ' MB' : Math.round(saved / 1024) + ' KB') + ' saved.';
        bar.style.width = '100%';
        return checkRecompact();
      })
      .catch(function (e) {
        status.textContent = 'Stopped: ' + e.message;
        go.disabled = false;
      });
  }

  $('recompact-go').addEventListener('click', runRecompact);

  /* ------------------------------------------------------------------ *
   * Manage: rename and delete
   * ------------------------------------------------------------------ */

  function manageOpen(show) {
    $('manage').hidden = !show;
    $('btn-manage').setAttribute('aria-expanded', show ? 'true' : 'false');
    $('manage-err').hidden = true;
    if (show) renderManage();
  }

  function manageFail(msg) {
    var e = $('manage-err');
    e.textContent = msg;
    e.hidden = false;
  }

  function manageSend(body) {
    body.append('csrf', csrf || '');
    return fetch('api/manage.php', { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) {
        return r.json().then(function (d) {
          if (!r.ok || !d.ok) throw new Error(d.error || ('HTTP ' + r.status));
          return d;
        });
      });
  }

  function renderManage() {
    var ul = $('manage-list');
    ul.textContent = '';
    $('manage-title').textContent = 'Tracks (' + tracks.length + ')';

    tracks.slice().sort(function (a, b) {
      return sortDate(b).localeCompare(sortDate(a));
    }).forEach(function (t) {
      var li = document.createElement('li');
      li.className = 'manage-row';

      var wrap = document.createElement('span');
      wrap.className = 'manage-name';
      var n = document.createElement('span');
      n.className = 'n';
      n.textContent = displayName(t);
      var d = document.createElement('span');
      d.className = 'd';
      d.textContent = fmtDate(displayDate(t).iso) + ' · ' + fmtKm(t.distance_m);
      wrap.appendChild(n);
      wrap.appendChild(d);

      var edit = document.createElement('button');
      edit.type = 'button';
      edit.className = 'rowbtn';
      edit.textContent = 'Edit';

      var del = document.createElement('button');
      del.type = 'button';
      del.className = 'rowbtn danger';
      del.textContent = 'Delete';

      // One editor, two ways in: this button and Option-clicking the track in
      // the main list. Keeping a second inline rename would mean two code paths
      // that have to agree about what a valid name is.
      edit.addEventListener('click', function () { openEditor(t); });

      // Two-step delete rather than confirm(): the dialog is already modal, and
      // deleting a track is not undoable from here.
      var armed = false;
      del.addEventListener('click', function () {
        if (!armed) {
          armed = true;
          del.classList.add('armed');
          del.textContent = 'Really delete?';
          setTimeout(function () {
            if (!armed) return;
            armed = false;
            del.classList.remove('armed');
            del.textContent = 'Delete';
          }, 4000);
          return;
        }
        del.disabled = edit.disabled = true;
        var body = new FormData();
        body.append('action', 'delete');
        body.append('id', t.id);
        manageSend(body)
          .then(function () {
            if (current && current.id === t.id) { clearTrack(); setUrl(null); }
            return loadCatalogue();
          })
          .then(function () { renderManage(); toast('Deleted.'); })
          .catch(function (err) { manageFail(err.message); renderManage(); });
      });

      li.appendChild(wrap);
      li.appendChild(edit);
      li.appendChild(del);
      ul.appendChild(li);
    });
  }

  $('btn-manage').addEventListener('click', function () { manageOpen($('manage').hidden); });
  $('manage-close').addEventListener('click', function () { manageOpen(false); });

  /* ------------------------------------------------------------------ *
   * Track editor — name, date, country, tags
   * ------------------------------------------------------------------ */

  var editing = null;      // the single track being edited
  var bulkIds = null;      // or the ids being edited together

  function editorOpen(show) {
    $('editor').hidden = !show;
    if (!show) {
      editing = null; bulkIds = null;
      $('ed-progress').hidden = true;
      resetEditorDelete();
    }
  }

  /**
   * Country and tags only. Renaming twenty tracks to the same thing is never
   * what anyone means, and one date across a selection is rarely right either.
   */
  function openBulkEditor() {
    var ids = pickedIds();
    if (!signedIn || ids.length === 0) return;
    editing = null;
    bulkIds = ids;

    $('ed-name-field').hidden = true;
    $('ed-date-field').hidden = true;
    // Deleting twenty tracks behind a single button is not a thing to offer.
    $('ed-delete-row').hidden = true;
    $('ed-err').hidden = true;
    $('ed-progress').hidden = true;
    $('ed-country').value = '';
    $('ed-tags').value = '';
    $('editor-title').textContent = 'Edit ' + ids.length + ' tracks';
    $('ed-tag-hint').textContent =
      'Tags are added to each track; existing tags are kept. Leave a box empty to leave it unchanged.';
    fillCountryList();
    editorOpen(true);
    $('ed-country').focus();
  }

  function fillCountryList() {
    var dl = $('ed-country-list');
    dl.textContent = '';
    allLabels().countries.forEach(function (c) {
      var o = document.createElement('option');
      o.value = c;
      dl.appendChild(o);
    });
  }

  function openEditor(t) {
    if (!signedIn) return;
    editing = t;
    bulkIds = null;
    $('ed-name-field').hidden = false;
    $('ed-date-field').hidden = false;
    $('ed-delete-row').hidden = false;
    resetEditorDelete();
    $('ed-progress').hidden = true;
    $('ed-tag-hint').textContent = 'Separate tags with commas.';
    $('ed-err').hidden = true;
    // The stored name, not the displayed one: editing must not silently drop a
    // date the title still carries.
    $('ed-name').value = t.name || '';
    // type="date" only accepts YYYY-MM-DD, and wants the effective date so the
    // field agrees with the row you clicked.
    var iso = displayDate(t).iso || '';
    $('ed-date').value = /^\d{4}-\d{2}-\d{2}/.test(iso) ? iso.slice(0, 10) : '';
    $('ed-country').value = countryOf(t);
    $('ed-tags').value = tagsOf(t).join(', ');
    $('editor-title').textContent = 'Edit ' + displayName(t);

    fillCountryList();
    editorOpen(true);
    $('ed-name').focus();
    $('ed-name').select();
  }

  /* Two clicks, like the one in the track list: deleting is not undoable. */
  var edDeleteArmed = false;

  function resetEditorDelete() {
    edDeleteArmed = false;
    var b = $('ed-delete');
    b.classList.remove('armed');
    b.textContent = 'Delete track';
    b.disabled = false;
  }

  $('ed-delete').addEventListener('click', function () {
    if (!editing) return;
    var t = editing;
    var b = $('ed-delete');
    if (!edDeleteArmed) {
      edDeleteArmed = true;
      b.classList.add('armed');
      b.textContent = 'Really delete this track?';
      setTimeout(function () { if (edDeleteArmed) resetEditorDelete(); }, 4000);
      return;
    }
    b.disabled = true;
    $('ed-save').disabled = true;
    var body = new FormData();
    body.append('action', 'delete');
    body.append('id', t.id);
    manageSend(body)
      .then(function () {
        if (current && current.id === t.id) showAll();
        return loadCatalogue();
      })
      .then(function () {
        if (!$('manage').hidden) renderManage();
        editorOpen(false);
        toast('Deleted \u201c' + displayName(t) + '\u201d');
      })
      .catch(function (err) {
        $('ed-err').textContent = err.message;
        $('ed-err').hidden = false;
        resetEditorDelete();
      })
      .then(function () { $('ed-save').disabled = false; });
  });

  $('editor-close').addEventListener('click', function () { editorOpen(false); });
  $('editor').addEventListener('click', function (e) {
    if (e.target === $('editor')) editorOpen(false);
  });

  $('editor-form').addEventListener('submit', function (e) {
    e.preventDefault();
    var err = $('ed-err');
    err.hidden = true;
    var btn = $('ed-save');
    var country = $('ed-country').value.trim();
    var tagText = $('ed-tags').value;

    if (bulkIds) {
      // Empty means "leave alone", not "clear": opening this on twenty tracks
      // and pressing Save must not wipe what is already on them.
      if (!country && !tagText.trim()) {
        err.textContent = 'Fill in a country or some tags first.';
        err.hidden = false;
        return;
      }
      var ids = bulkIds.slice();
      var addTags = tagText.split(',').map(function (x) { return x.trim(); })
        .filter(function (x) { return x; });

      btn.disabled = true;
      $('ed-progress').hidden = false;
      $('ed-bar').style.width = '0%';

      var done = 0, failed = 0;
      var step = function (i) {
        if (i >= ids.length) return Promise.resolve();
        var t = tracks.filter(function (x) { return x.id === ids[i]; })[0];
        if (!t) { done++; return step(i + 1); }

        var body = new FormData();
        body.append('action', 'update');
        body.append('id', t.id);
        if (country) body.append('country', country);
        if (addTags.length) {
          // Union with what the track already has, so bulk tagging adds rather
          // than replaces — the destructive reading is never what is wanted.
          var merged = tagsOf(t).slice();
          var seen = {};
          merged.forEach(function (x) { seen[x.toLowerCase()] = true; });
          addTags.forEach(function (x) {
            if (!seen[x.toLowerCase()]) { seen[x.toLowerCase()] = true; merged.push(x); }
          });
          body.append('tags', merged.join(','));
        }
        return manageSend(body)
          .catch(function () { failed++; })
          .then(function () {
            done++;
            $('ed-bar').style.width = Math.round((done / ids.length) * 100) + '%';
            return step(i + 1);
          });
      };

      step(0)
        .then(function () { return loadCatalogue(); })
        .then(function () {
          clearPicked();
          if (current) {
            var fresh = tracks.filter(function (x) { return x.id === current.id; })[0];
            if (fresh) { current = fresh; renderStats(fresh); }
          }
          if (!$('manage').hidden) renderManage();
          editorOpen(false);
          toast(failed
            ? (ids.length - failed) + ' of ' + ids.length + ' updated, ' + failed + ' failed.'
            : ids.length + ' tracks updated.');
        })
        .catch(function (e2) { err.textContent = e2.message; err.hidden = false; })
        .then(function () { btn.disabled = false; });
      return;
    }

    if (!editing) return;
    var t = editing;
    var name = $('ed-name').value.trim();
    if (!name) { err.textContent = 'A name is required.'; err.hidden = false; return; }

    btn.disabled = true;
    var body = new FormData();
    body.append('action', 'update');
    body.append('id', t.id);
    body.append('name', name);
    body.append('date', $('ed-date').value);
    body.append('country', country);
    body.append('tags', tagText);

    manageSend(body)
      .then(function () { return loadCatalogue(); })
      .then(function () {
        var fresh = tracks.filter(function (x) { return x.id === t.id; })[0];
        if (current && current.id === t.id && fresh) { current = fresh; renderStats(fresh); }
        if (!$('manage').hidden) renderManage();
        editorOpen(false);
        toast('Saved.');
      })
      .catch(function (e2) { err.textContent = e2.message; err.hidden = false; })
      .then(function () { btn.disabled = false; });
  });

  /* ------------------------------------------------------------------ *
   * Country / tag filter
   * ------------------------------------------------------------------ */

  /** Every country and tag currently in use, each sorted, case-folded once. */
  function allLabels() {
    var countries = {}, tags = {};
    tracks.forEach(function (t) {
      var c = countryOf(t);
      if (c) countries[c.toLowerCase()] = c;
      tagsOf(t).forEach(function (x) { tags[x.toLowerCase()] = x; });
    });
    var byName = function (a, b) { return a.localeCompare(b); };
    return {
      countries: Object.keys(countries).map(function (k) { return countries[k]; }).sort(byName),
      tags: Object.keys(tags).map(function (k) { return tags[k]; }).sort(byName)
    };
  }

  function rebuildTagFilter() {
    var sel = $('filter-tag');
    var was = sel.value;
    var labels = allLabels();
    var any = labels.countries.length || labels.tags.length;

    sel.textContent = '';
    var all = document.createElement('option');
    all.value = '';
    all.textContent = 'All tracks';
    sel.appendChild(all);

    var addGroup = function (label, items) {
      if (!items.length) return;
      var g = document.createElement('optgroup');
      g.label = label;
      items.forEach(function (x) {
        var o = document.createElement('option');
        o.value = x;
        o.textContent = x;
        g.appendChild(o);
      });
      sel.appendChild(g);
    };
    addGroup('Country', labels.countries);
    // Tags are not listed here — with a few dozen of them the menu becomes
    // unusable, and they are reachable as buttons on each track instead. The
    // one exception is a tag currently doing the filtering, which needs an
    // entry so the control can represent it and "All tracks" can clear it.
    if (was && labels.countries.indexOf(was) === -1
        && labels.tags.some(function (x) { return x.toLowerCase() === was.toLowerCase(); })) {
      addGroup('Tag', [was]);
    }

    // Keep the current filter if it still exists; otherwise fall back to all,
    // so deleting the last track of a country cannot leave an empty list with
    // no obvious way back.
    sel.value = was;
    if (sel.value !== was) sel.value = '';
    sel.hidden = !any;
  }

  $('btn-showall').addEventListener('click', showAll);
  $('btn-showall-top').addEventListener('click', showAll);

  /**
   * With a track selected on a phone, the screen belongs to the map: the track
   * list, the site name and the way in to more of them are all noise until you
   * want another track, and "Show all" is the way back to wanting one.
   */
  function applyMobileChrome() {
    var focused = MOBILE() && !!current;
    document.body.classList.toggle('track-focus', focused);
    $('btn-showall-top').hidden = !focused;
    syncMapDownload();
    if (focused) panelOpen('panel-tracks', false);
    // Settle it in both directions: on the way out of focus the button has to
    // come back, and only re-running the rule knows whether it should.
    showUploadBtn(altHeld);
  }
  $('pick-clear').addEventListener('click', clearPicked);
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && anyPicked() && $('editor').hidden) clearPicked();
  });

  $('filter-tag').addEventListener('change', function () {
    renderList();
    if (current) renderTagRow(current);
  });

  function refreshSession() {
    return fetch('api/session.php', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        csrf = d.csrf || null;
        if (!d.authenticated) sessionHint(false);
        setAuthUi(!!d.authenticated);
        if (d.configured === false) {
          $('login-err').textContent = 'No password configured yet — run: php tools/gpxadmin.php passwd';
          $('login-err').hidden = false;
        }
        return d;
      })
      .catch(function () {
        setAuthUi(false);
        $('login-err').textContent = 'Upload API unavailable (needs PHP hosting).';
        $('login-err').hidden = false;
      });
  }

  $('login-form').addEventListener('submit', function (e) {
    e.preventDefault();
    var err = $('login-err');
    err.hidden = true;
    var body = new FormData();
    body.append('action', 'login');
    body.append('pass', $('login-pass').value);
    fetch('api/session.php', { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
      .then(function (res) {
        if (!res.ok || !res.d.ok) throw new Error(res.d.error || 'Sign in failed');
        csrf = res.d.csrf;
        $('login-pass').value = '';
        sessionHint(true);
        setAuthUi(true);
        $('file').focus();
      })
      .catch(function (e2) { err.textContent = e2.message; err.hidden = false; });
  });

  $('logout').addEventListener('click', function () {
    var body = new FormData();
    body.append('action', 'logout');
    body.append('csrf', csrf || '');
    fetch('api/session.php', { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) { csrf = d.csrf || null; sessionHint(false); setAuthUi(false); })
      .catch(function () { sessionHint(false); setAuthUi(false); });
  });

  /**
   * One upload. Shared by the dialog and by dropping files on the track list,
   * so both get the same validation, the same progress and the same handling of
   * an expired session.
   *
   * @param {File} file
   * @param {function(number)} onProgress 0-100
   * @returns {Promise<object>} the stored track's metadata
   */
  function uploadFile(file, onProgress) {
    return new Promise(function (resolve, reject) {
      if (!/\.gpx$/i.test(file.name)) { reject(new Error('Not a .gpx file')); return; }
      if (file.size > 25 * 1024 * 1024) { reject(new Error('Larger than 25 MB')); return; }

      var body = new FormData();
      body.append('csrf', csrf || '');
      body.append('gpx', file, file.name);

      var xhr = new XMLHttpRequest();
      xhr.open('POST', 'api/upload.php', true);
      xhr.withCredentials = true;
      if (onProgress) {
        xhr.upload.onprogress = function (ev) {
          if (ev.lengthComputable) onProgress(Math.round(ev.loaded / ev.total * 100));
        };
      }
      xhr.onreadystatechange = function () {
        if (xhr.readyState !== 4) return;
        var d = {};
        try { d = JSON.parse(xhr.responseText); } catch (e) { /* non-JSON error page */ }
        if (xhr.status === 200 && d.ok) { resolve(d.track); return; }
        // 401: session gone. 403: token rotated or expired — in both cases pull
        // a fresh session/token so the next attempt can succeed.
        if (xhr.status === 401) { setAuthUi(false); refreshSession(); }
        else if (xhr.status === 403) { refreshSession(); }
        reject(new Error(d.error || ('Upload failed (HTTP ' + xhr.status + ')')));
      };
      xhr.onerror = function () { reject(new Error('Network error')); };
      xhr.send(body);
    });
  }

  $('upload-form').addEventListener('submit', function (e) {
    e.preventDefault();
    var err = $('upload-err'), ok = $('upload-ok');
    err.hidden = true; ok.hidden = true;
    var f = $('file').files[0];
    if (!f) { err.textContent = 'Choose a .gpx file first.'; err.hidden = false; return; }

    $('upload-go').disabled = true;
    $('progress').hidden = false;
    uploadFile(f, function (pct) { $('progress-bar').style.width = pct + '%'; })
      .then(function (track) {
        ok.textContent = 'Uploaded: ' + (track.name || track.id);
        ok.hidden = false;
        $('file').value = '';
        return loadCatalogue().then(function () {
          selectTrack(track.id, true);
          scrollListTo(track.id);
          showModal(false);
          toast('Added \u201c' + (track.name || track.id) + '\u201d');
        });
      })
      .catch(function (e2) { err.textContent = e2.message; err.hidden = false; })
      .then(function () {
        $('upload-go').disabled = false;
        $('progress').hidden = true;
        $('progress-bar').style.width = '0';
      });
  });

  /* ------------------------------------------------------------------ *
   * Dropping files on the track list
   * ------------------------------------------------------------------ */

  var dragDepth = 0;    // dragenter/dragleave fire per child, so count them

  function gpxFilesFrom(dt) {
    var out = [];
    var files = (dt && dt.files) ? dt.files : [];
    for (var i = 0; i < files.length; i++) {
      if (/\.gpx$/i.test(files[i].name)) out.push(files[i]);
    }
    return out;
  }

  /** True when the drag is carrying files at all (not text or a link). */
  function dragHasFiles(e) {
    var t = e.dataTransfer && e.dataTransfer.types;
    if (!t) return false;
    return Array.prototype.indexOf.call(t, 'Files') !== -1;
  }

  function dropHint(on) {
    $('drop-hint').hidden = !on;
    $('panel-tracks').classList.toggle('dragging', !!on);
  }

  var panelTracks = $('panel-tracks');

  /*
   * The drag is accepted whether or not the page currently believes you are
   * signed in, and the question is settled on drop. Refusing the drag outright
   * meant a stale idea of the session — a cookie from before this browser
   * recorded a sign-in, say — made dropping do nothing at all, with no way to
   * tell that from the feature being broken.
   */
  panelTracks.addEventListener('dragenter', function (e) {
    if (!dragHasFiles(e)) return;
    e.preventDefault();
    dragDepth++;
    dropHint(true);
  });
  panelTracks.addEventListener('dragover', function (e) {
    if (!dragHasFiles(e)) return;
    e.preventDefault();                       // without this the drop never fires
    e.dataTransfer.dropEffect = 'copy';
  });
  panelTracks.addEventListener('dragleave', function () {
    dragDepth = Math.max(0, dragDepth - 1);
    if (!dragDepth) dropHint(false);
  });
  panelTracks.addEventListener('drop', function (e) {
    if (!dragHasFiles(e)) return;
    e.preventDefault();
    dragDepth = 0;
    dropHint(false);

    var files = gpxFilesFrom(e.dataTransfer);
    if (!files.length) { toast('Only .gpx files can be added.'); return; }

    if (signedIn) { uploadDropped(files); return; }
    // Ask the server rather than trust our own flag, then say so either way.
    refreshSession().then(function () {
      if (signedIn) uploadDropped(files);
      else toast('Sign in first to add tracks — press Upload.');
    });
  });

  /**
   * Elements that legitimately take a dropped file: the track list, and the
   * file input in the upload dialog, which handles drops itself. Cancelling the
   * default on those would break the very thing the drop is for.
   */
  function acceptsDrop(target) {
    if (!(target instanceof Element)) return false;
    if (panelTracks.contains(target)) return true;
    return !!target.closest('input[type="file"], label[for="file"]');
  }

  // Anywhere else, a dropped file would navigate away from the page and lose
  // whatever was on screen.
  ['dragover', 'drop'].forEach(function (type) {
    window.addEventListener(type, function (e) {
      if (dragHasFiles(e) && !acceptsDrop(e.target)) e.preventDefault();
    });
  });

  /** Upload dropped files one at a time, reporting as it goes. */
  function uploadDropped(files) {
    var status = $('drop-status');
    var text = $('drop-text');
    var bar = $('drop-bar');
    status.hidden = false;

    var added = [], failed = [];
    var step = function (i) {
      if (i >= files.length) return Promise.resolve();
      text.textContent = files.length > 1
        ? 'Uploading ' + (i + 1) + ' of ' + files.length + ' — ' + files[i].name
        : 'Uploading ' + files[i].name;
      bar.style.width = '0%';
      return uploadFile(files[i], function (pct) { bar.style.width = pct + '%'; })
        .then(function (track) { added.push(track); })
        .catch(function (err) { failed.push(files[i].name + ' — ' + err.message); })
        .then(function () { return step(i + 1); });
    };

    return step(0)
      .then(function () { return loadCatalogue(); })
      .then(function () {
        status.hidden = true;
        text.textContent = '\u00a0';
        bar.style.width = '0%';
        if (added.length === 1 && !failed.length) {
          selectTrack(added[0].id, true);
          scrollListTo(added[0].id);
          toast('Added \u201c' + (added[0].name || added[0].id) + '\u201d');
        } else if (added.length) {
          // Several: show where the newest of them landed.
          scrollListTo(added[added.length - 1].id);
          toast(added.length + ' tracks added'
            + (failed.length ? ', ' + failed.length + ' failed' : ''));
        }
        if (failed.length) {
          toast(failed.length === 1 ? failed[0] : failed.length + ' files were refused: ' + failed[0], 7000);
        }
      });
  }

  /* ------------------------------------------------------------------ *
   * Boot
   * ------------------------------------------------------------------ */

  /**
   * Check the three files match before anything else runs, so a mismatched
   * upload announces itself instead of surfacing later as an unexplained
   * missing button, an unstyled link or a map in the wrong place.
   */
  (function checkAssetVersions() {
    var meta = document.querySelector('meta[name="app-version"]');
    var html = meta ? meta.getAttribute('content') : null;
    var css = getComputedStyle(document.documentElement)
      .getPropertyValue('--app-version').trim().replace(/^["']|["']$/g, '');
    var stale = [];
    if (html !== APP_VERSION) stale.push('index.html (' + (html || 'no stamp') + ')');
    if (css !== APP_VERSION) stale.push('assets/style.css (' + (css || 'no stamp') + ')');
    if (!stale.length) return;

    var msg = 'Out of date: ' + stale.join(' and ')
      + ' — assets/app.js is ' + APP_VERSION + '. Upload index.html, assets/app.js'
      + ' and assets/style.css together, then reload.';
    // console for you, toast for whoever is looking at it.
    if (window.console && console.warn) console.warn(msg);
    setTimeout(function () { toast(msg, 12000); }, 1200);
  }());

  cleanUrl();
  layoutDefaults();
  showUploadBtn(false);   // hidden on a desktop, present on a phone
  // Before the catalogue, so the list is interactive with the right permissions
  // from its first render rather than only after the Upload dialog is opened.
  if (hasSessionHint()) refreshSession();
  showLoading(true, 'Loading collection…');
  loadCatalogue().then(function () {
    var want = new URLSearchParams(window.location.search).get('track');
    if (want && tracks.some(function (t) { return t.id === want; })) {
      selectTrack(want, false);      // relabels the indicator, then clears it
      if (!MOBILE()) panelOpen('panel-info', true);
    } else {
      showLoading(false);
      if (want) toast('That track is no longer in the collection.');
      setUrl(null);
      // Deliberately not the collection's own bounding box: one stray track
      // abroad would zoom the opening view out to a continent.
      homeView();
    }
  }).catch(function (e) {
    showLoading(false);
    toast('Could not load the track list: ' + e.message);
  });

  window.addEventListener('popstate', function () {
    var want = new URLSearchParams(window.location.search).get('track');
    if (want) selectTrack(want, false);
  });
})();
