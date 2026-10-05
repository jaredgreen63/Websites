<?php
declare(strict_types=1);

/**
 * Operational alerts.
 *
 * The deploy workflow calls this when a run fails. Without it a failure is
 * silent: the sync commits fresh inventory, the build dies, the upload is
 * skipped, and the site goes on serving yesterday's vehicles with nothing but
 * a red tick on a page nobody is watching.
 *
 * It reuses the same channels a new lead uses, so whatever reaches Dan for a
 * booking reaches him for a broken deploy.
 */

require __DIR__ . '/../lib/util.php';
require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/notify.php';

/** Most alerts to send in one hour, however many arrive. */
const ALERT_CAP = 6;
const ALERT_COUNTER = 'alerts_sent';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    fail('Method not allowed.', 405);
}

$config = config();
$expected = (string) ($config['alert_token'] ?? '');
$given = (string) ($_SERVER['HTTP_X_ALERT_TOKEN'] ?? '');

// hash_equals, not ==, so the token cannot be guessed a character at a time.
// An unset token means the endpoint is off rather than open to everyone.
if ($expected === '' || !hash_equals($expected, $given)) {
    fail('Not authorised.', 403);
}

$raw = file_get_contents('php://input') ?: '';
if (strlen($raw) > 8000) {
    fail('That request is too large.', 413);
}
$input = json_decode($raw, true);
if (!is_array($input)) {
    fail('Malformed request.');
}

$title = clean($input['title'] ?? 'Something failed', 90);
$detail = clean($input['detail'] ?? '', 160);
$url = clean($input['url'] ?? '', 300);
if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
    $url = '';
}

try {
    $pdo = db();

    /*
     * A cap, in case the token ever leaks: an alert endpoint that can be made
     * to send without limit is a way to run up a phone bill. Approximate is
     * fine here — this is a brake, not an accountant.
     */
    $row = $pdo->prepare('SELECT value, updated_at FROM counters WHERE name = ?');
    $row->execute([ALERT_COUNTER]);
    $current = $row->fetch();
    $thisHour = (new DateTimeImmutable())->format('Y-m-d H');
    $lastHour = $current && $current['updated_at']
        ? (new DateTimeImmutable((string) $current['updated_at']))->format('Y-m-d H')
        : '';

    if ($lastHour === $thisHour && (int) $current['value'] >= ALERT_CAP) {
        // Still a success: the caller did its job, we are simply not relaying.
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'sent' => false, 'reason' => 'hourly cap reached']);
        exit;
    }

    if ($lastHour === $thisHour) {
        bump_counter($pdo, ALERT_COUNTER);
    } else {
        $pdo->prepare('DELETE FROM counters WHERE name = ?')->execute([ALERT_COUNTER]);
        bump_counter($pdo, ALERT_COUNTER);
    }
} catch (Throwable) {
    // No database is not a reason to swallow an alert — that is the moment it
    // matters most. Carry on and send.
}

$lines = array_values(array_filter([$title, $detail, $url]));
$text = implode("\n", $lines);

$results = deliver_alert($text, $title, $config);
$failed = array_values(array_filter($results, static fn ($r) => $r['error'] !== ''));

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok' => true,
    'sent' => count($results) - count($failed),
    'failed' => count($failed),
]);
