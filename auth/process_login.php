<?php

require_once __DIR__ . '/../includes/csrf.php';

if (!is_post()) {
    redirect(BASE_URL . '/auth/login.php');
}

verify_csrf();

$candidatePortal = isset($_POST['portal'])
    && is_string($_POST['portal'])
    && $_POST['portal'] === 'candidate';
$voterPortal = isset($_POST['portal'])
    && is_string($_POST['portal'])
    && $_POST['portal'] === 'voter';
$loginUrl = $candidatePortal
    ? BASE_URL . '/candidate/login.php'
    : ($voterPortal ? BASE_URL . '/voter/login.php' : BASE_URL . '/auth/login.php');

if ($voterPortal && isset($_SESSION['flash']) && is_array($_SESSION['flash'])) {
    unset($_SESSION['flash']['voter_login_notice']);
    if ($_SESSION['flash'] === []) {
        unset($_SESSION['flash']);
    }
}

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
    redirect($loginUrl);
}

if ($windowStartedAt <= 0 || $now - $windowStartedAt >= $windowSeconds || $lockedUntil > 0) {
    $throttle = [
        'count' => 0,
        'window_started_at' => $now,
        'locked_until' => 0,
    ];
}

$identifierField = $candidatePortal || $voterPortal ? 'identifier' : 'email';
$identifier = isset($_POST[$identifierField]) && is_string($_POST[$identifierField])
    ? trim($_POST[$identifierField])
    : '';
if (!$candidatePortal && filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false) {
    $identifier = strtolower($identifier);
}
$password = isset($_POST['password']) && is_string($_POST['password'])
    ? $_POST['password']
    : '';

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/audit.php';

$user = false;
if ($candidatePortal && $identifier !== '' && strlen($identifier) <= 254
    && $password !== '' && strlen($password) <= 72) {
    $userQuery = $pdo->prepare(
        "SELECT id, password_hash, role, status
         FROM users
         WHERE (email = :email OR reg_number = :reg_number)
           AND role = 'candidate'
         LIMIT 2"
    );
    $userQuery->execute([
        'email' => $identifier,
        'reg_number' => $identifier,
    ]);
    $matchingUsers = $userQuery->fetchAll();
    $user = count($matchingUsers) === 1 ? $matchingUsers[0] : false;
} elseif (
    $voterPortal
    && $identifier !== ''
    && strlen($identifier) <= 254
    && $password !== ''
    && strlen($password) <= 72
) {
    $userQuery = $pdo->prepare(
        "SELECT u.id, u.password_hash, u.role, u.status, v.id AS voter_profile_id
         FROM users u
         INNER JOIN voters v ON v.user_id = u.id
         WHERE (u.email = :email OR v.reg_number = :reg_number)
           AND u.role = 'voter'
         LIMIT 2"
    );
    $userQuery->execute([
        'email' => $identifier,
        'reg_number' => $identifier,
    ]);
    $matchingUsers = $userQuery->fetchAll();
    $user = count($matchingUsers) === 1 ? $matchingUsers[0] : false;
} elseif (
    !$candidatePortal
    && !$voterPortal
    && filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false
    && $password !== ''
    && strlen($password) <= 72
) {
    $userQuery = $pdo->prepare(
        'SELECT u.id, u.password_hash, u.role, u.status, v.id AS voter_profile_id
         FROM users u
         LEFT JOIN voters v ON v.user_id = u.id
         WHERE u.email = :email
         LIMIT 1'
    );
    $userQuery->execute(['email' => $identifier]);
    $user = $userQuery->fetch();
}

$credentialsValid = is_array($user)
    && (!$candidatePortal || $user['role'] === 'candidate')
    && (!$voterPortal || $user['role'] === 'voter')
    && ($user['role'] !== 'voter' || isset($user['voter_profile_id']))
    && password_verify($password, $user['password_hash']);

if (
    $credentialsValid
    && $voterPortal
    && in_array($user['status'], ['pending', 'rejected'], true)
) {
    unset($_SESSION['login_throttle']);
    set_flash('voter_login_notice', $user['status']);
    redirect($loginUrl);
}

if (!$credentialsValid || $user['status'] !== 'active') {
    $throttle['count'] = (int) ($throttle['count'] ?? 0) + 1;

    if ($throttle['count'] >= $maxAttempts) {
        $throttle['locked_until'] = $now + $lockoutSeconds;
    }

    $_SESSION['login_throttle'] = $throttle;
    log_security_event(
        $pdo,
        'security.login_failed',
        'Failed sign-in attempt; credentials were invalid or the account was inactive.',
        null,
        ['attempt_number' => $throttle['count']]
    );
    if ($throttle['count'] === $maxAttempts) {
        log_security_event(
            $pdo,
            'security.login_throttled',
            'Sign-in attempts were throttled after repeated invalid credentials.',
            null,
            ['lockout_seconds' => $lockoutSeconds]
        );
    }
    set_flash(
        'error',
        $candidatePortal
            ? 'Invalid email or candidate ID, or the account is not active.'
            : ($voterPortal
                ? 'Invalid email, voter ID, or password.'
                : 'Invalid email or password, or the account is not active.')
    );
    redirect($loginUrl);
}

$roleDestinations = [
    'admin' => '/admin/dashboard.php',
    'candidate' => '/candidate/dashboard.php',
    'voter' => '/voter/dashboard.php',
];

if (!isset($roleDestinations[$user['role']])) {
    throw new UnexpectedValueException('The account has an unsupported role.');
}

$rememberMe = ($candidatePortal || $voterPortal)
    && isset($_POST['remember_me'])
    && is_string($_POST['remember_me'])
    && $_POST['remember_me'] === '1';
$sessionCookieParams = session_get_cookie_params();
session_set_cookie_params([
    'lifetime' => $rememberMe ? REMEMBERED_SESSION_LIFETIME : SESSION_LIFETIME,
    'path' => $sessionCookieParams['path'],
    'domain' => $sessionCookieParams['domain'],
    'secure' => $sessionCookieParams['secure'],
    'httponly' => true,
    'samesite' => $sessionCookieParams['samesite'] ?? 'Lax',
]);

if (!session_regenerate_id(true)) {
    throw new RuntimeException('Unable to regenerate the login session ID.');
}

unset($_SESSION['login_throttle'], $_SESSION['csrf_token']);
$_SESSION['user'] = [
    'id' => (int) $user['id'],
    'role' => $user['role'],
];
unset($_SESSION['candidate_id'], $_SESSION['voter_id']);
if ($user['role'] === 'candidate') {
    $_SESSION['candidate_id'] = (int) $user['id'];
} elseif ($user['role'] === 'voter') {
    $_SESSION['voter_id'] = (int) $user['voter_profile_id'];
}
if ($rememberMe) {
    $_SESSION['remember_me'] = true;
} else {
    unset($_SESSION['remember_me']);
}

$rememberCookieOptions = [
    'expires' => $rememberMe ? $now + REMEMBERED_SESSION_LIFETIME : $now - 42000,
    'path' => $sessionCookieParams['path'],
    'domain' => $sessionCookieParams['domain'],
    'secure' => $sessionCookieParams['secure'],
    'httponly' => true,
    'samesite' => $sessionCookieParams['samesite'] ?? 'Lax',
];
if (!setcookie(REMEMBER_ME_COOKIE, $rememberMe ? '1' : '', $rememberCookieOptions)) {
    throw new RuntimeException('Unable to save the remembered-session preference.');
}

redirect(BASE_URL . $roleDestinations[$user['role']]);