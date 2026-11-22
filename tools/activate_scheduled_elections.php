<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/election_scheduler.php';

$activated = activate_due_auto_elections($pdo);
fwrite(STDOUT, sprintf("Processed scheduled elections; activated %d election(s).\n", $activated));
