<?php
declare(strict_types=1);

function config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $path = __DIR__ . '/../config.php';
    if (!is_file($path)) {
        fail('Not configured. Copy config.sample.php to config.php and fill it in.', 500);
    }

    $config = require $path;
    return $config;
}

/** Stop with a JSON error. Never echo the underlying exception to a visitor. */
function fail(string $message, int $status = 400): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'message' => $message], JSON_UNESCAPED_SLASHES);
    exit;
}

function ok(array $payload = []): never
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true] + $payload, JSON_UNESCAPED_SLASHES);
    exit;
}

/** Trim, collapse whitespace and cap length. */
function clean(mixed $value, int $max = 2000): string
{
    if (!is_scalar($value)) {
        return '';
    }
    $text = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');
    return mb_substr($text, 0, $max);
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function client_ip(): string
{
    // Cloudflare sits in front, so REMOTE_ADDR is Cloudflare's edge.
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
        $value = $_SERVER[$key] ?? '';
        if ($value !== '') {
            $first = trim(explode(',', $value)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }
    }
    return '';
}

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => (($_SERVER['HTTPS'] ?? '') !== '')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),
        'path' => '/',
    ]);
    session_name('bch_admin');
    session_start();
}

function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function check_csrf(?string $token): void
{
    start_session();
    // hash_equals, not ==, so a wrong token cannot be guessed by timing.
    if (!is_string($token) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) {
        http_response_code(400);
        exit('Bad request.');
    }
}
