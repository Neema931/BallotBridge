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
$currentPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
$isAdmin = ($loggedInUser['role'] ?? null) === 'admin';
$isCandidate = ($loggedInUser['role'] ?? null) === 'candidate';
$isVoter = ($loggedInUser['role'] ?? null) === 'voter';
$isCandidatePortal = in_array($currentPage, [
    'login.php',
    'register.php',
    'forgot_password.php',
], true) && str_contains(str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? ''), '/candidate/');
$isVoterPortal = (in_array($currentPage, [
    'login.php',
    'register.php',
    'forgot_password.php',
], true) && str_contains(str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? ''), '/voter/'))
    || $currentPage === 'set_voter_password.php';
$adminNavigationLinks = [
    ['label' => 'Dashboard', 'href' => '/admin/dashboard.php'],
    ['label' => 'Manage elections', 'href' => '/admin/manage_elections.php'],
    ['label' => 'Manage voters', 'href' => '/admin/manage_voters.php'],
    ['label' => 'Manage candidates', 'href' => '/admin/manage_candidates.php'],
    ['label' => 'See results', 'href' => '/admin/results.php'],
    ['label' => 'Audit logs', 'href' => '/admin/audit_logs.php'],
    ['label' => 'Settings', 'href' => '/admin/settings.php'],
];

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
    <meta name="theme-color" content="#0096ff">
    <?php if ($isVoter): ?>
        <meta name="csrf-token" content="<?= sanitize(csrf_token()) ?>">
    <?php endif; ?>
    <title><?= sanitize($pageTitle) ?> | <?= sanitize(APP_NAME) ?></title>
    <link rel="icon" href="<?= BASE_URL ?>/assets/img/favicon.svg?v=2" type="image/svg+xml">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/variables.css?v=2">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/base.css?v=6">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/layout.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/components.css?v=3">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/forms.css?v=9">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/dashboard.css?v=33">
    <?php if ($isAdmin): ?>
        <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/admin.css?v=4">
    <?php endif; ?>
    <?php if (!empty($candidateDashboardStylesheet)): ?>
        <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/candidate-dashboard.css?v=1">
    <?php endif; ?>
    <?php if ($isCandidate || $isCandidatePortal): ?>
        <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/candidate.css?v=2">
    <?php endif; ?>
    <?php if ($isVoter || $isVoterPortal): ?>
        <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/voter.css?v=2">
    <?php endif; ?>
</head>
<body class="site-body<?= $isAdmin ? ' site-body--admin' : '' ?><?= $isCandidate ? ' site-body--candidate' : '' ?><?= $isCandidatePortal ? ' site-body--candidate-portal' : '' ?><?= $isVoter ? ' site-body--voter' : '' ?><?= $isVoterPortal ? ' site-body--voter-portal' : '' ?>"
    <?= $isAdmin ? 'data-theme-scope="admin" data-theme="light"' : '' ?>
    <?= $isVoter ? 'data-voter-heartbeat="' . BASE_URL . '/voter/session_heartbeat.php"' : '' ?>>
    <a class="skip-link" href="#main-content">Skip to content</a>
    <?php if ($isAdmin): ?>
        <header class="admin-sidebar">
            <a class="admin-sidebar__brand" href="<?= BASE_URL ?>/admin/dashboard.php">
                <span class="admin-sidebar__brand-mark" aria-hidden="true">BB</span>
                <span><?= sanitize(APP_NAME) ?></span>
            </a>
            <nav class="admin-sidebar__nav" aria-label="Admin navigation">
                <?php foreach ($adminNavigationLinks as $link): ?>
                    <a href="<?= BASE_URL . $link['href'] ?>"
                        <?= $currentPage === basename($link['href']) ? 'aria-current="page"' : '' ?>>
                        <span class="admin-sidebar__nav-dot" aria-hidden="true"></span>
                        <?= sanitize($link['label']) ?>
                    </a>
                <?php endforeach; ?>
            </nav>
            <div class="admin-sidebar__footer">
                <div class="admin-sidebar__account">
                    <span class="admin-sidebar__account-avatar" aria-hidden="true">A</span>
                    <span>
                        <strong>Administrator</strong>
                        <small>Election officer</small>
                    </span>
                </div>
                <form class="admin-sidebar__logout" method="post" action="<?= BASE_URL ?>/auth/logout.php">
                    <?= csrf_field() ?>
                    <button type="submit">Logout</button>
                </form>
            </div>
        </header>
    <?php elseif ($isCandidate): ?>
        <header class="candidate-sidebar">
            <a class="candidate-sidebar__brand" href="<?= BASE_URL ?>/candidate/dashboard.php">
                <span class="candidate-sidebar__brand-mark" aria-hidden="true">BB</span>
                <span><?= sanitize(APP_NAME) ?></span>
            </a>
            <nav class="candidate-sidebar__nav" aria-label="Candidate navigation">
                <?php foreach ($navigationLinks as $link): ?>
                    <a href="<?= BASE_URL . $link['href'] ?>"
                        <?= $currentPage === basename($link['href']) ? 'aria-current="page"' : '' ?>>
                        <span class="candidate-sidebar__nav-dot" aria-hidden="true"></span>
                        <?= sanitize($link['label']) ?>
                    </a>
                <?php endforeach; ?>
            </nav>
            <div class="candidate-sidebar__footer">
                <div class="candidate-sidebar__account">
                    <span class="candidate-sidebar__account-avatar" aria-hidden="true">C</span>
                    <span>
                        <strong>Candidate</strong>
                        <small>Campaign workspace</small>
                    </span>
                </div>
                <form class="candidate-sidebar__logout" method="post" action="<?= BASE_URL ?>/auth/logout.php">
                    <?= csrf_field() ?>
                    <button type="submit">Logout</button>
                </form>
            </div>
        </header>
    <?php elseif ($isVoter): ?>
        <header class="voter-sidebar">
            <a class="voter-sidebar__brand" href="<?= BASE_URL ?>/voter/dashboard.php">
                <span class="voter-sidebar__brand-mark" aria-hidden="true">BB</span>
                <span><?= sanitize(APP_NAME) ?></span>
            </a>
            <nav class="voter-sidebar__nav" aria-label="Voter navigation">
                <?php foreach ($navigationLinks as $link): ?>
                    <a href="<?= BASE_URL . $link['href'] ?>"
                        <?= $currentPage === basename($link['href']) ? 'aria-current="page"' : '' ?>>
                        <span class="voter-sidebar__nav-dot" aria-hidden="true"></span>
                        <?= sanitize($link['label']) ?>
                    </a>
                <?php endforeach; ?>
            </nav>
            <div class="voter-sidebar__footer">
                <div class="voter-sidebar__account">
                    <span class="voter-sidebar__account-avatar" aria-hidden="true">V</span>
                    <span>
                        <strong>Voter</strong>
                        <small>Secure voting account</small>
                    </span>
                </div>
                <form class="voter-sidebar__logout" method="post" action="<?= BASE_URL ?>/auth/logout.php">
                    <?= csrf_field() ?>
                    <button type="submit">Logout</button>
                </form>
            </div>
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