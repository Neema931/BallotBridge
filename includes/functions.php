<?php

require_once __DIR__ . '/../config/constants.php';

/**
 * Start the application session with secure cookie settings and an inactivity timeout.
 */
function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    if (session_status() === PHP_SESSION_DISABLED) {
        throw new RuntimeException('PHP sessions are disabled.');
    }

    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.gc_maxlifetime', (string) SESSION_LIFETIME);

    session_name('ballot_bridge_session');
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path' => rtrim(BASE_URL, '/') . '/',
        'secure' => !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();

    $now = time();
    if (array_key_exists('last_activity', $_SESSION)) {
        $lastActivity = $_SESSION['last_activity'];
        if (
            !is_int($lastActivity)
            || $lastActivity > $now
            || $now - $lastActivity >= SESSION_LIFETIME
        ) {
            $_SESSION = [];
            if (!session_regenerate_id(true)) {
                throw new RuntimeException('Unable to renew an expired session.');
            }
        }
    }

    $_SESSION['last_activity'] = $now;
}

/**
 * Escape a value for safe insertion into HTML text or an attribute.
 */
function sanitize(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Send a Location header and stop processing the current request.
 */
function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

/**
 * Store a one-time message in the current session.
 */
function set_flash(string $key, string $message): void
{
    start_secure_session();
    $_SESSION['flash'][$key] = $message;
}

/**
 * Return and remove a one-time session message, or null if none exists.
 */
function get_flash(string $key): ?string
{
    start_secure_session();

    if (!isset($_SESSION['flash'][$key])) {
        return null;
    }

    $message = $_SESSION['flash'][$key];
    unset($_SESSION['flash'][$key]);

    if ($_SESSION['flash'] === []) {
        unset($_SESSION['flash']);
    }

    return $message;
}

/**
 * Check whether the current request uses the POST method.
 */
function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
}