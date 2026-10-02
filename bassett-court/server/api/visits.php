<?php
declare(strict_types=1);

/**
 * Visit counter.
 *
 * Every page asks for the number; only a visitor who has not been seen in the
 * last half hour adds to it. That is what "visits" normally means — one person
 * browsing twenty vehicles is one visit, not twenty.
 *
 * The number shown is the real count plus a starting offset (see visit_offset
 * in config.php). The database stores only the real count, so the two can
 * always be told apart and the offset can be changed or dropped later.
 */

require __DIR__ . '/../lib/util.php';
require __DIR__ . '/../lib/db.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// POST only. A GET would be followed by link prefetchers and crawlers, each
// one counting as a visitor who was never here.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    fail('Method not allowed.', 405);
}

const VISIT_COOKIE = 'bch_v';
const VISIT_WINDOW = 1800;     // 30 minutes, the usual length of a session
const COUNTER = 'site_visits';

/** Crawlers are not visitors. This misses some; it costs nothing to try. */
function looks_automated(string $agent): bool
{
    if ($agent === '') {
        return true;
    }
    return (bool) preg_match(
        '/bot|crawl|spider|slurp|archiver|facebookexternalhit|preview|headless|lighthouse|monitor|curl|wget|python-requests/i',
        $agent,
    );
}

$config = config();
$offset = (int) ($config['visit_offset'] ?? 5000);

try {
    $pdo = db();

    $returning = isset($_COOKIE[VISIT_COOKIE]);
    $automated = looks_automated((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));

    if (!$returning && !$automated) {
        $total = bump_counter($pdo, COUNTER);

        setcookie(VISIT_COOKIE, '1', [
            'expires' => time() + VISIT_WINDOW,
            'path' => '/',
            'httponly' => true,     // the page never reads it; only this endpoint does
            'samesite' => 'Lax',
            'secure' => (($_SERVER['HTTPS'] ?? '') !== '')
                || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),
        ]);
    } else {
        $total = read_counter($pdo, COUNTER);
    }
} catch (Throwable) {
    // A counter is decoration. If the database is unreachable, say so quietly
    // and let the page hide the number rather than show an error over it.
    fail('Unavailable.', 503);
}

// Never cached: the whole point is that it changes.
header('Cache-Control: no-store');
ok(['visits' => $total + $offset]);
