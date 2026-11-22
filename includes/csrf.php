<?php

require_once __DIR__ . '/functions.php';

/**
 * Return the session's CSRF token, creating one if necessary.
 */
function csrf_token(): string
{
    start_secure_session();

    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/**
 * Return a hidden form control containing the current CSRF token.
 */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . sanitize(csrf_token()) . '">';
}

/**
 * Stop the request with HTTP 403 when its submitted CSRF token is invalid.
 */
function verify_csrf(): void
{
    $submittedToken = $_POST['csrf_token'] ?? null;

    if (!is_string($submittedToken) || !hash_equals(csrf_token(), $submittedToken)) {
        http_response_code(403);
        require __DIR__ . '/../errors/403.php';
        exit;
    }
}