<?php
/**
 * Set the site version in every place that carries it.
 *
 * index.html, assets/app.js and assets/style.css only work as a set, and the
 * version appears five times across them: the meta tag the page checks against,
 * the ?v= on each of the two asset URLs, the CSS custom property and the
 * constant in the JavaScript. Editing those by hand is how they drift apart,
 * which then shows up as a stale stylesheet or a button that is not there.
 *
 * Raising the version also busts the browser cache: the asset filenames never
 * change, and mobile Safari will serve an old copy however politely the headers
 * ask it not to. A new ?v= is a new URL, which it has to fetch.
 *
 *   php scripts/bump-version.php               bump the last number
 *   php scripts/bump-version.php --set 2026.09.01.1
 *   php scripts/bump-version.php --check       report, change nothing
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$root = dirname(__DIR__);
$argvLocal = $argv ?? [];

if (in_array('--help', $argvLocal, true) || in_array('-h', $argvLocal, true)) {
    echo <<<TXT
    Set the site version everywhere it appears.

      (no arguments)      bump the last number: 2026.08.16.4 -> 2026.08.16.5
      --set <version>     use this exact version
      --check             report what each file says and change nothing
      --help, -h          this text

    Touches index.html, assets/app.js and assets/style.css. Run it whenever you
    change any of the three, then upload all three together.

    TXT;
    exit(0);
}

/** Each file, and how to find and replace the version inside it. */
$targets = [
    'index.html' => [
        ['/(<meta name="app-version" content=")([^"]+)(")/', 'the meta tag'],
        ['/(href="assets\/style\.css\?v=)([^"]+)(")/',       'the stylesheet URL'],
        ['/(src="assets\/app\.js\?v=)([^"]+)(")/',           'the script URL'],
    ],
    'assets/style.css' => [
        ['/(--app-version:\s*")([^"]+)(";)/', 'the CSS property'],
    ],
    'assets/app.js' => [
        ['/(var APP_VERSION = \')([^\']+)(\';)/', 'the JavaScript constant'],
    ],
];

$found = [];
foreach ($targets as $file => $patterns) {
    $path = $root . '/' . $file;
    if (!is_file($path)) {
        fwrite(STDERR, "Missing: $file\n");
        exit(1);
    }
    $body = (string)file_get_contents($path);
    foreach ($patterns as [$re, $what]) {
        if (!preg_match($re, $body, $m)) {
            fwrite(STDERR, "Could not find $what in $file\n");
            exit(1);
        }
        $found[] = [$file, $what, $m[2]];
    }
}

$versions = array_unique(array_column($found, 2));

if (in_array('--check', $argvLocal, true)) {
    foreach ($found as [$file, $what, $v]) {
        printf("%-18s %-22s %s\n", $file, $what, $v);
    }
    if (count($versions) === 1) {
        echo "\nAll five agree: " . $versions[0] . "\n";
        exit(0);
    }
    echo "\nThey disagree — run the script with no arguments to bring them into line.\n";
    exit(1);
}

// Work from the highest version present, so a bump after a partial edit still
// moves forwards rather than reusing a number that is already out there.
usort($versions, 'version_compare');
$current = end($versions) ?: '0.0.0.0';

$explicit = null;
foreach ($argvLocal as $i => $a) {
    if ($a === '--set') {
        $explicit = $argvLocal[$i + 1] ?? null;
    }
}

if ($explicit !== null) {
    if (!preg_match('/^[0-9]+(\.[0-9]+)*$/', $explicit)) {
        fwrite(STDERR, "--set expects a version like 2026.09.01.1\n");
        exit(1);
    }
    $next = $explicit;
} else {
    $parts = explode('.', $current);
    $parts[count($parts) - 1] = (string)((int)end($parts) + 1);
    $next = implode('.', $parts);
}

if (count($versions) > 1) {
    echo "Note: the files disagreed (" . implode(', ', $versions) . ").\n";
}

foreach ($targets as $file => $patterns) {
    $path = $root . '/' . $file;
    $body = (string)file_get_contents($path);
    foreach ($patterns as [$re]) {
        $body = preg_replace($re, '${1}' . $next . '${3}', $body, 1);
    }
    if (!file_put_contents($path, $body)) {
        fwrite(STDERR, "Could not write $file\n");
        exit(1);
    }
    echo "  updated $file\n";
}

echo "\n$current -> $next\n";
echo "Upload index.html, assets/app.js and assets/style.css together.\n";
