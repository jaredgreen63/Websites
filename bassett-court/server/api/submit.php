<?php
declare(strict_types=1);

/**
 * Appointment intake.
 *
 * The booking form posts here from the visitor's browser. Stores the request
 * and nothing else — notifications read from the same table, so a text that is
 * missed or deleted never loses the lead.
 */

require __DIR__ . '/../lib/util.php';
require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/leads.php';

header('Vary: Origin');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    fail('Method not allowed.', 405);
}

$raw = file_get_contents('php://input') ?: '';
if (strlen($raw) > 20000) {
    fail('That request is too large.', 413);
}

$input = json_decode($raw, true);
if (!is_array($input)) {
    // Fall back to a normal form post, so the endpoint still works without JS.
    $input = $_POST;
}
if (!is_array($input) || $input === []) {
    fail('Malformed request.');
}

// Honeypot. Answer 200 so a bot learns nothing, but store nothing.
if (clean($input['company'] ?? '') !== '') {
    ok(['stored' => false]);
}

$name = clean($input['name'] ?? '', 120);
$phone = clean($input['phone'] ?? '', 40);
$email = clean($input['email'] ?? '', 160);

if ($name === '') {
    fail('Please include your name.');
}
if ($phone === '' && $email === '') {
    fail('Please include a phone number or an email address so we can reach you.');
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fail('That email address does not look right.');
}

$pdo = db();
$ip = client_ip();

// Rate limit by address. A person books once; a script does not.
if ($ip !== '') {
    $since = (new DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s');
    $recent = $pdo->prepare(
        'SELECT COUNT(*) FROM appointments WHERE source_ip = ? AND created_at > ?'
    );
    $recent->execute([$ip, $since]);
    if ((int) $recent->fetchColumn() >= 8) {
        fail('Too many requests from this connection. Please call us instead.', 429);
    }
}

$id = create_lead($pdo, [
    'name' => $name,
    'phone' => $phone,
    'email' => $email,
    'vehicle_id' => clean($input['vehicleId'] ?? '', 40),
    'vehicle_label' => clean($input['vehicleLabel'] ?? '', 200),
    'preferred_day' => clean($input['preferredDay'] ?? '', 20),
    'preferred_day_label' => clean($input['preferredDayLabel'] ?? '', 40),
    'preferred_time' => clean($input['preferredTime'] ?? '', 20),
    'trade_in' => clean($input['tradeIn'] ?? '', 60),
    'message' => clean($input['message'] ?? '', 4000),
    'vehicle_location' => clean($input['vehicleLocation'] ?? '', 200),
    'submitted_from' => clean($input['submittedFrom'] ?? '', 400),
    'source_ip' => $ip,
    'user_agent' => clean($_SERVER['HTTP_USER_AGENT'] ?? '', 300),
], 'web');

notify_new_lead($id, [
    'name' => $name,
    'phone' => $phone,
    'email' => $email,
    'vehicle_label' => clean($input['vehicleLabel'] ?? '', 200),
    'preferred_day_label' => clean($input['preferredDayLabel'] ?? '', 40),
    'preferred_time' => clean($input['preferredTime'] ?? '', 20),
]);

ok(['stored' => true, 'id' => $id]);
