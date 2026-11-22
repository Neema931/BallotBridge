<?php

require_once __DIR__ . '/errors.php';

define('APP_NAME', 'Ballot Bridge');

// The app is served from /BallotBridge in the default XAMPP setup.
define('BASE_URL', '/BallotBridge');

// Default and opt-in remembered session lifetimes, in seconds.
define('SESSION_LIFETIME', 1800);
define('REMEMBERED_SESSION_LIFETIME', 2592000);
define('REMEMBER_ME_COOKIE', 'ballot_bridge_remember');
