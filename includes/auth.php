<?php

require_once __DIR__ . '/functions.php';

// Start the secure session as soon as the authentication helpers are loaded.
start_secure_session();

/**
 * Check whether a user is stored in the current session.
 */
function is_logged_in(): bool
{
    return isset($_SESSION['user'])
        && is_array($_SESSION['user'])
        && isset($_SESSION['user']['id']);
}

/**
 * Return the current session user, or null when no user is authenticated.
 */
function current_user(): ?array
{
    return is_logged_in() ? $_SESSION['user'] : null;
}

/**
 * Redirect unauthenticated visitors to the login page.
 */
function require_login(): void
{
    if (!is_logged_in()) {
        set_flash('error', 'Please log in to continue.');
        redirect(BASE_URL . '/auth/login.php');
    }
}

/**
 * Require the signed-in user to have the requested role.
 */
function require_role(string $role): void
{
    require_login();

    if (($_SESSION['user']['role'] ?? null) !== $role) {
        set_flash('error', 'You do not have permission to access that page.');
        redirect(BASE_URL . '/index.php');
    }
}