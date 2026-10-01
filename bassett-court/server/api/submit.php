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

$statement = $pdo->prepare('
    INSERT INTO appointments
        (created_at, status, name, phone, email, vehicle_id, vehicle_label,
         preferred_day, preferred_day_label, preferred_time, trade_in, message,
         vehicle_location, submitted_from, source_ip, user_agent)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
');

$statement->execute([
    (new DateTimeImmutable())->format('Y-m-d H:i:s'),
    'new',
    $name,
    $phone,
    $email,
    clean($input['vehicleId'] ?? '', 40),
    clean($input['vehicleLabel'] ?? '', 200),
    clean($input['preferredDay'] ?? '', 20),
    clean($input['preferredDayLabel'] ?? '', 40),
    clean($input['preferredTime'] ?? '', 20),
    clean($input['tradeIn'] ?? '', 60),
    clean($input['message'] ?? '', 4000),
    clean($input['vehicleLocation'] ?? '', 200),
    clean($input['submittedFrom'] ?? '', 400),
    $ip,
    clean($_SERVER['HTTP_USER_AGENT'] ?? '', 300),
]);

$id = (int) $pdo->lastInsertId();

/*
 * Notification hook. Set notify_url in config.php to a Make/Zapier webhook or
 * an SMS service and each new request is pushed to it, carrying a link
 * straight to the record in the admin.
 *
 * Delivery failure is logged, never surfaced: the request is already saved,
 * and telling the visitor it failed would be false.
 */
$config = config();
$notify = $config['notify_url'] ?? '';
if ($notify !== '') {
    $payload = json_encode([
        'id' => $id,
        'name' => $name,
        'phone' => $phone,
        'email' => $email,
        'vehicle' => clean($input['vehicleLabel'] ?? '', 200),
        'preferred' => trim(clean($input['preferredDayLabel'] ?? '', 40) . ' ' . clean($input['preferredTime'] ?? '', 20)),
        'admin_url' => rtrim($config['site_url'] ?? '', '/') . '/admin/?id=' . $id,
    ], JSON_UNESCAPED_SLASHES);

    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\n",
        'content' => $payload,
        'timeout' => 5,
        'ignore_errors' => true,
    ]]);

    if (@file_get_contents($notify, false, $context) === false) {
        error_log("[appointments] notify failed for #{$id}");
    }
}

ok(['stored' => true, 'id' => $id]);
