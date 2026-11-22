<?php

require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/auth.php';

$pageTitle = isset($pageTitle) && is_string($pageTitle) ? $pageTitle : APP_NAME;
$loggedInUser = current_user();
$roleLinks = [
    'admin' => [
        ['label' => 'Dashboard', 'href' => '/admin/dashboard.php'],
        ['label' => 'Elections', 'href' => '/admin/manage_elections.php'],
        ['label' => 'Candidates', 'href' => '/admin/manage_candidates.php'],
        ['label' => 'Voters', 'href' => '/admin/manage_voters.php'],
        ['label' => 'Results', 'href' => '/admin/results.php'],
    ],
    'candidate' => [
        ['label' => 'Dashboard', 'href' => '/candidate/dashboard.php'],
        ['label' => 'My Profile', 'href' => '/candidate/myprofile.php'],
    ],
    'voter' => [
        ['label' => 'Dashboard', 'href' => '/voter/dashboard.php'],
        ['label' => 'Vote', 'href' => '/voter/vote.php'],
        ['label' => 'My vote', 'href' => '/voter/my_vote.php'],
    ],
];

$navigationLinks = $loggedInUser === null
    ? []
    : ($roleLinks[$loggedInUser['role'] ?? ''] ?? []);
$isAdmin = ($loggedInUser['role'] ?? null) === 'admin';
$isVoter = ($loggedInUser['role'] ?? null) === 'voter';
$adminNavigationLinks = [
    ['label' => 'Dashboard', 'href' => '/admin/dashboard.php'],
    ['label' => 'Manage elections', 'href' => '/admin/manage_elections.php'],
    ['label' => 'Manage voters', 'href' => '/admin/manage_voters.php'],
    ['label' => 'Manage candidates', 'href' => '/admin/manage_candidates.php'],
    ['label' => 'See results', 'href' => '/admin/results.php'],
    ['label' => 'Audit logs', 'href' => '/admin/audit_logs.php'],
    ['label' => 'Settings', 'href' => '/admin/settings.php'],
];
$currentPage = basename($_SERVER['SCRIPT_NAME'] ?? '');

$flashMessages = [
    ['key' => 'success', 'class' => 'alert--success', 'role' => 'status'],
    ['key' => 'error', 'class' => 'alert--error', 'role' => 'alert'],
    ['key' => 'info', 'class' => 'alert--info', 'role' => 'status'],
];

$flashMessagesToDisplay = [];
foreach ($flashMessages as $flash) {
    $message = get_flash($flash['key']);
    if ($message !== null) {
        $flash['message'] = $message;
        $flashMessagesToDisplay[] = $flash;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0000ff">
    <?php if ($isVoter): ?>
        <meta name="csrf-token" content="<?= sanitize(csrf_token()) ?>">
    <?php endif; ?>
    <title><?= sanitize($pageTitle) ?> | <?= sanitize(APP_NAME) ?></title>
    <link rel="icon" href="<?= BASE_URL ?>/assets/img/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/variables.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/base.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/layout.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/components.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/forms.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/dashboard.css?v=20">
    <?php if (!empty($candidateDashboardStylesheet)): ?>
        <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/candidate-dashboard.css?v=1">
    <?php endif; ?>
</head>
<body class="site-body<?= $isAdmin ? ' site-body--admin' : '' ?>"
    <?= $isAdmin ? 'data-theme-scope="admin" data-theme="light"' : '' ?>
    <?= $isVoter ? 'data-voter-heartbeat="' . BASE_URL . '/voter/session_heartbeat.php"' : '' ?>>
    <a class="skip-link" href="#main-content">Skip to content</a>
    <?php if ($isAdmin): ?>
        <header class="admin-sidebar">
            <a class="admin-sidebar__brand" href="<?= BASE_URL ?>/admin/dashboard.php">
                <?= sanitize(APP_NAME) ?>
            </a>
            <nav class="admin-sidebar__nav" aria-label="Admin navigation">
                <?php foreach ($adminNavigationLinks as $link): ?>
                    <a href="<?= BASE_URL . $link['href'] ?>"
                        <?= $currentPage === basename($link['href']) ? 'aria-current="page"' : '' ?>>
                        <?= sanitize($link['label']) ?>
                    </a>
                <?php endforeach; ?>
            </nav>
            <form class="admin-sidebar__logout" method="post" action="<?= BASE_URL ?>/auth/logout.php">
                <?= csrf_field() ?>
                <button type="submit">Logout</button>
            </form>
        </header>
    <?php elseif (empty($hideSiteNavigation)): ?>
        <header>
            <nav class="navbar" aria-label="Primary navigation">
                <div class="container navbar__inner">
                    <a class="navbar__brand" href="<?= BASE_URL ?>/index.php">
                        <?= sanitize(APP_NAME) ?>
                    </a>
                    <div class="navbar__links">
                        <?php if ($loggedInUser === null): ?>
                            <?php if (empty($hideGuestAuthLinks)): ?>
                                <a href="<?= BASE_URL ?>/auth/login.php">Login</a>
                                <a href="<?= BASE_URL ?>/auth/register.php">Register</a>
                            <?php endif; ?>
                        <?php else: ?>
                            <?php foreach ($navigationLinks as $link): ?>
                                <a href="<?= BASE_URL . $link['href'] ?>">
                                    <?= sanitize($link['label']) ?>
                                </a>
                            <?php endforeach; ?>
                            <form class="navbar__logout" method="post" action="<?= BASE_URL ?>/auth/logout.php">
                                <?= csrf_field() ?>
                                <button type="submit">Logout</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </nav>
        </header>
    <?php endif; ?>
    <main id="main-content" class="site-main<?= $isAdmin ? ' site-main--admin' : '' ?>">
        <?php if ($flashMessagesToDisplay !== [] && empty($hideFlashStack)): ?>
            <div class="container flash-stack">
                <?php foreach ($flashMessagesToDisplay as $flash): ?>
                    <div class="alert <?= $flash['class'] ?>" role="<?= $flash['role'] ?>">
                        <?= sanitize($flash['message']) ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>