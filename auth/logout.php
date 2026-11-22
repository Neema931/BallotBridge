<?php

require_once __DIR__ . '/../includes/csrf.php';

if (!is_post()) {
    redirect(BASE_URL . '/index.php');
}

verify_csrf();

if (
    isset($_SESSION['user'])
    && is_array($_SESSION['user'])
    && ($_SESSION['user']['role'] ?? null) === 'voter'
    && isset($_SESSION['user']['id'])
) {
    require_once __DIR__ . '/../config/database.php';
    $removeVoterSession = $pdo->prepare(
        'DELETE FROM active_voter_sessions WHERE session_hash = :session_hash'
    );
    $removeVoterSession->execute(['session_hash' => hash('sha256', session_id())]);
}

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $cookieParameters = session_get_cookie_params();
    $cookieOptions = [
        'expires' => time() - 42000,
        'path' => $cookieParameters['path'],
        'domain' => $cookieParameters['domain'],
        'secure' => $cookieParameters['secure'],
        'httponly' => $cookieParameters['httponly'],
        'samesite' => $cookieParameters['samesite'] ?? 'Lax',
    ];

    if (!setcookie(session_name(), '', $cookieOptions)) {
        throw new RuntimeException('Unable to clear the session cookie.');
    }
}

if (!session_destroy()) {
    throw new RuntimeException('Unable to destroy the session.');
}

redirect(BASE_URL . '/auth/login.php');