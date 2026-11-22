<?php

require_once __DIR__ . '/../includes/csrf.php';

if (!is_post()) {
    redirect(BASE_URL . '/auth/login.php');
}

verify_csrf();

$now = time();
$maxAttempts = 5;
$windowSeconds = 300;
$lockoutSeconds = 300;
$throttle = $_SESSION['login_throttle'] ?? [];

if (!is_array($throttle)) {
    $throttle = [];
}

$windowStartedAt = isset($throttle['window_started_at'])
    ? (int) $throttle['window_started_at']
    : 0;
$lockedUntil = isset($throttle['locked_until']) ? (int) $throttle['locked_until'] : 0;

if ($lockedUntil > $now) {
    set_flash('error', 'Too many login attempts. Please try again later.');
    redirect(BASE_URL . '/auth/login.php');
}

if ($windowStartedAt <= 0 || $now - $windowStartedAt >= $windowSeconds || $lockedUntil > 0) {
    $throttle = [
        'count' => 0,
        'window_started_at' => $now,
        'locked_until' => 0,
    ];
}

$email = isset($_POST['email']) && is_string($_POST['email'])
    ? strtolower(trim($_POST['email']))
    : '';
$password = isset($_POST['password']) && is_string($_POST['password'])
    ? $_POST['password']
    : '';

require_once __DIR__ . '/../config/database.php';

$user = false;
if (
    filter_var($email, FILTER_VALIDATE_EMAIL) !== false
    && $password !== ''
    && strlen($password) <= 72
) {
    $userQuery = $pdo->prepare(
        'SELECT id, password_hash, role, status FROM users WHERE email = :email LIMIT 1'
    );
    $userQuery->execute(['email' => $email]);
    $user = $userQuery->fetch();
}

$credentialsValid = is_array($user)
    && password_verify($password, $user['password_hash']);

if (!$credentialsValid || $user['status'] !== 'active') {
    $throttle['count'] = (int) ($throttle['count'] ?? 0) + 1;

    if ($throttle['count'] >= $maxAttempts) {
        $throttle['locked_until'] = $now + $lockoutSeconds;
    }

    $_SESSION['login_throttle'] = $throttle;
    set_flash('error', 'Invalid email or password, or the account is not active.');
    redirect(BASE_URL . '/auth/login.php');
}

$roleDestinations = [
    'admin' => '/admin/dashboard.php',
    'candidate' => '/candidate/dashboard.php',
    'voter' => '/voter/dashboard.php',
];

if (!isset($roleDestinations[$user['role']])) {
    throw new UnexpectedValueException('The account has an unsupported role.');
}

if (!session_regenerate_id(true)) {
    throw new RuntimeException('Unable to regenerate the login session ID.');
}

unset($_SESSION['login_throttle'], $_SESSION['csrf_token']);
$_SESSION['user'] = [
    'id' => (int) $user['id'],
    'role' => $user['role'],
];

redirect(BASE_URL . $roleDestinations[$user['role']]);