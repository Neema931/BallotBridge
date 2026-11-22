<?php

require_once __DIR__ . '/../includes/auth.php';
require_role('voter');
require_once __DIR__ . '/../includes/csrf.php';

if (!is_post()) {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

verify_csrf();

http_response_code(204);
