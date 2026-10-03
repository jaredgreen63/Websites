<?php
declare(strict_types=1);

/**
 * Lead notifications.
 *
 * Three independent channels, any combination switched on in config.php:
 *
 *   sms      a text straight to a phone, through Twilio
 *   email    plain email; also the free route to a text, by addressing a
 *            carrier's email-to-SMS gateway
 *   webhook  a JSON POST, for Make, Zapier or anything else
 *
 * Every channel is best-effort. The lead is already in the database before any
 * of this runs, so a text that fails, or one that is missed or deleted, never
 * loses the lead — the admin remains the record. Failures are logged, never
 * shown to the visitor, because from their side the request did succeed.
 */

/** Longest message we will send. Two SMS segments; beyond that costs more. */
const SMS_MAX = 300;

/**
 * The text of the alert. Short, and front-loaded: the name and number are what
 * get acted on, and the link is what turns the alert into the record.
 */
function compose_sms(int $id, array $row, string $siteUrl): string
{
    $lines = ['New lead — ' . trim((string) ($row['name'] ?? 'Someone'))];

    $phone = trim((string) ($row['phone'] ?? ''));
    $email = trim((string) ($row['email'] ?? ''));
    if ($phone !== '') {
        $lines[] = $phone;
    } elseif ($email !== '') {
        $lines[] = $email;
    }

    $vehicle = trim((string) ($row['vehicle_label'] ?? ''));
    if ($vehicle !== '') {
        $lines[] = mb_strimwidth($vehicle, 0, 48, '…');
    }

    $when = trim(((string) ($row['preferred_day_label'] ?? '')) . ' ' . ((string) ($row['preferred_time'] ?? '')));
    if (trim($when) !== '') {
        $lines[] = trim($when);
    }

    $lines[] = admin_link($siteUrl, $id);

    return mb_strimwidth(implode("\n", $lines), 0, SMS_MAX, '…');
}

function admin_link(string $siteUrl, int $id): string
{
    return rtrim($siteUrl, '/') . '/admin/?id=' . $id;
}

/**
 * Split a recipient setting into a list.
 *
 * One number is the normal case; a comma-separated string lets a second person
 * be copied in without a config shape change.
 */
function recipients(mixed $value): array
{
    if (is_array($value)) {
        $parts = $value;
    } else {
        $parts = explode(',', (string) $value);
    }
    return array_values(array_filter(array_map('trim', $parts), static fn ($p) => $p !== ''));
}

/**
 * Send one SMS through Twilio. Returns an error string, or '' on success.
 *
 * Twilio is the only provider wired up because it is the one a small dealer
 * can start on in ten minutes for about a cent a message. Anything else can
 * go through the webhook channel instead.
 */
function send_sms(array $sms, string $to, string $body): string
{
    $sid = trim((string) ($sms['account_sid'] ?? ''));
    $token = trim((string) ($sms['auth_token'] ?? ''));
    $from = trim((string) ($sms['from'] ?? ''));

    if ($sid === '' || $token === '' || $from === '') {
        return 'sms is missing account_sid, auth_token or from';
    }

    // Overridable so the send path can be exercised against a stand-in, and so
    // a Twilio-compatible gateway or a regional endpoint can be used instead.
    $base = rtrim((string) ($sms['api_base'] ?? 'https://api.twilio.com'), '/');
    $url = $base . '/2010-04-01/Accounts/' . rawurlencode($sid) . '/Messages.json';
    $post = http_build_query(['From' => $from, 'To' => $to, 'Body' => $body]);

    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $post,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_USERPWD => $sid . ':' . $token,
    ]);
    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    if ($response === false) {
        return 'could not reach Twilio: ' . $error;
    }
    if ($status < 200 || $status >= 300) {
        // Twilio explains itself in the body; that message is what makes a bad
        // number or an unverified sender diagnosable.
        $detail = json_decode((string) $response, true);
        $message = is_array($detail) ? (string) ($detail['message'] ?? '') : '';
        return "Twilio returned {$status}" . ($message !== '' ? ": {$message}" : '');
    }
    return '';
}

/** Send one email. Returns an error string, or '' on success. */
function send_email(string $to, string $subject, string $body, string $from): string
{
    $headers = [
        'From: ' . $from,
        'Content-Type: text/plain; charset=utf-8',
        'X-Mailer: bassett-court',
    ];
    // Carrier gateways and some clients mangle long lines.
    $wrapped = wordwrap($body, 70, "\r\n");
    return @mail($to, $subject, $wrapped, implode("\r\n", $headers))
        ? ''
        : 'the server refused the message (check cPanel email)';
}

/** POST the lead as JSON. Returns an error string, or '' on success. */
function send_webhook(string $url, array $payload): string
{
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    if ($response === false) {
        return 'could not reach the webhook: ' . $error;
    }
    if ($status < 200 || $status >= 300) {
        return "the webhook returned {$status}";
    }
    return '';
}

/**
 * Run every configured channel for one lead.
 *
 * Returns a list of ['channel' => …, 'target' => …, 'error' => …] so the admin
 * can show exactly what happened when sending a test. Nothing here throws: one
 * dead channel must not stop the others.
 */
function deliver_lead(int $id, array $row, array $config): array
{
    $siteUrl = (string) ($config['site_url'] ?? '');
    $text = compose_sms($id, $row, $siteUrl);
    $results = [];

    $sms = is_array($config['sms'] ?? null) ? $config['sms'] : [];
    foreach (recipients($sms['to'] ?? '') as $to) {
        $results[] = ['channel' => 'sms', 'target' => $to, 'error' => send_sms($sms, $to, $text)];
    }

    $from = (string) ($config['notify_email_from'] ?? ('no-reply@' . (parse_url($siteUrl, PHP_URL_HOST) ?: 'localhost')));
    foreach (recipients($config['notify_email'] ?? '') as $to) {
        $subject = 'New lead — ' . trim((string) ($row['name'] ?? 'Someone'));
        $results[] = ['channel' => 'email', 'target' => $to, 'error' => send_email($to, $subject, $text, $from)];
    }

    $webhook = trim((string) ($config['notify_url'] ?? ''));
    if ($webhook !== '') {
        $payload = [
            'id' => $id,
            'name' => $row['name'] ?? '',
            'phone' => $row['phone'] ?? '',
            'email' => $row['email'] ?? '',
            'vehicle' => $row['vehicle_label'] ?? '',
            'preferred' => trim(((string) ($row['preferred_day_label'] ?? '')) . ' ' . ((string) ($row['preferred_time'] ?? ''))),
            'admin_url' => admin_link($siteUrl, $id),
            // Ready to drop straight into an SMS action, so a Make or Zapier
            // scenario needs no message built by hand.
            'message' => $text,
        ];
        $results[] = ['channel' => 'webhook', 'target' => $webhook, 'error' => send_webhook($webhook, $payload)];
    }

    foreach ($results as $r) {
        if ($r['error'] !== '') {
            error_log("[appointments] {$r['channel']} notify failed for #{$id}: {$r['error']}");
        }
    }

    return $results;
}

/**
 * A webhook URL with its secret part hidden.
 *
 * Anyone holding the full URL can post fabricated leads into the system, and
 * this page gets screenshotted. Host plus the last few characters is enough to
 * tell one hook from another without putting the whole thing on screen.
 */
function mask_url(string $url): string
{
    $host = parse_url($url, PHP_URL_HOST);
    if (!is_string($host) || $host === '') {
        return '…';
    }
    $scheme = parse_url($url, PHP_URL_SCHEME) ?: 'https';
    $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
    $tail = $path === '' ? '' : '/…' . mb_substr(rtrim($path, '/'), -4);
    return $scheme . '://' . $host . $tail;
}
