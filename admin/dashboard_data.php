<?php

require_once __DIR__ . '/../includes/auth.php';
require_role('admin');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/admin_dashboard_data.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private');

$response = load_admin_dashboard_data($pdo);
$encodedResponse = json_encode(
    $response,
    JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
echo $encodedResponse;
