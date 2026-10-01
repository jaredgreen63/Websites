<?php
declare(strict_types=1);

/**
 * Lead storage.
 *
 * Two paths create a lead: the public booking form (api/submit.php) and the
 * admin's own "add a lead" form. Both land here, so one function owns the
 * column list and a field added to one path cannot be forgotten in the other.
 */

/**
 * Every column a lead carries, with its empty default.
 *
 * This list is also what create_lead() builds its INSERT from, so the column
 * names are always these literals and never anything a visitor supplied.
 */
function lead_fields(): array
{
    return [
        'name' => '',
        'phone' => '',
        'email' => '',
        'vehicle_id' => '',
        'vehicle_label' => '',
        'preferred_day' => '',
        'preferred_day_label' => '',
        'preferred_time' => '',
        'trade_in' => '',
        'message' => '',
        'vehicle_location' => '',
        'submitted_from' => '',
        'source_ip' => '',
        'user_agent' => '',
        'admin_notes' => '',
    ];
}

/** Where a lead came from. 'web' is the booking form, 'manual' is typed in. */
function lead_sources(): array
{
    return ['web', 'manual'];
}

function lead_source_label(?string $source): string
{
    return $source === 'manual' ? 'Added by hand' : 'From the website';
}

/**
 * Insert a lead and return its id.
 *
 * $data is matched against lead_fields(): unknown keys are dropped and missing
 * ones default to empty, so a caller can pass only what it has. Values must be
 * cleaned by the caller — this function escapes nothing, it parameterises.
 */
function create_lead(PDO $pdo, array $data, string $source = 'web', string $status = 'new'): int
{
    if (!in_array($source, lead_sources(), true)) {
        $source = 'web';
    }
    if (!in_array($status, statuses(), true)) {
        $status = 'new';
    }

    $row = array_intersect_key($data, lead_fields()) + lead_fields();
    $columns = array_keys($row);

    $sql = sprintf(
        'INSERT INTO appointments (created_at, status, source, %s) VALUES (?, ?, ?, %s)',
        implode(', ', $columns),
        implode(', ', array_fill(0, count($columns), '?')),
    );

    $pdo->prepare($sql)->execute(array_merge(
        [(new DateTimeImmutable())->format('Y-m-d H:i:s'), $status, $source],
        array_values($row),
    ));

    return (int) $pdo->lastInsertId();
}

/**
 * Push a new lead to the configured webhook.
 *
 * Only the booking form calls this: a lead typed into the admin by hand needs
 * no text message about itself.
 *
 * Delivery failure is logged, never surfaced. The lead is already stored, and
 * telling the visitor their request failed would be false.
 */
function notify_new_lead(int $id, array $row): void
{
    $config = config();
    $url = (string) ($config['notify_url'] ?? '');
    if ($url === '') {
        return;
    }

    $payload = json_encode([
        'id' => $id,
        'name' => $row['name'] ?? '',
        'phone' => $row['phone'] ?? '',
        'email' => $row['email'] ?? '',
        'vehicle' => $row['vehicle_label'] ?? '',
        'preferred' => trim(($row['preferred_day_label'] ?? '') . ' ' . ($row['preferred_time'] ?? '')),
        'admin_url' => rtrim((string) ($config['site_url'] ?? ''), '/') . '/admin/?id=' . $id,
    ], JSON_UNESCAPED_SLASHES);

    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\n",
        'content' => $payload,
        'timeout' => 5,
        'ignore_errors' => true,
    ]]);

    if (@file_get_contents($url, false, $context) === false) {
        error_log("[appointments] notify failed for #{$id}");
    }
}
