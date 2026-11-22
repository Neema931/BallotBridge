<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$pdoConfigPath = __DIR__ . '/../config/database.php';
require_once $pdoConfigPath;

$adminQuery = $pdo->prepare(
    "SELECT id, email FROM users
     WHERE role = 'admin'
     ORDER BY id
     LIMIT 1"
);
$adminQuery->execute();
$admin = $adminQuery->fetch();

if (!$admin) {
    fwrite(STDERR, "The seeded administrator was not found. Import database/schema.sql first.\n");
    exit(1);
}

fwrite(STDOUT, 'Administrator email [' . $admin['email'] . ']: ');
$emailInput = fgets(STDIN);
if ($emailInput === false) {
    fwrite(STDERR, "Unable to read the administrator email.\n");
    exit(1);
}
$adminEmail = strtolower(trim($emailInput));
if ($adminEmail === '') {
    $adminEmail = $admin['email'];
}

if (strlen($adminEmail) > 254 || filter_var($adminEmail, FILTER_VALIDATE_EMAIL) === false) {
    fwrite(STDERR, "Enter a valid administrator email address.\n");
    exit(1);
}

$duplicateEmailQuery = $pdo->prepare(
    'SELECT id FROM users WHERE email = :email AND id <> :admin_id LIMIT 1'
);
$duplicateEmailQuery->execute([
    'email' => $adminEmail,
    'admin_id' => $admin['id'],
]);
if ($duplicateEmailQuery->fetchColumn() !== false) {
    fwrite(STDERR, "That email address is already assigned to another account.\n");
    exit(1);
}

fwrite(STDOUT, "Enter a password (12-72 bytes): ");
$password = fgets(STDIN);
fwrite(STDOUT, "Confirm password: ");
$confirmation = fgets(STDIN);

if ($password === false || $confirmation === false) {
    fwrite(STDERR, "Unable to read the password from standard input.\n");
    exit(1);
}

$password = rtrim($password, "\r\n");
$confirmation = rtrim($confirmation, "\r\n");

if (strlen($password) < 12 || strlen($password) > 72) {
    fwrite(STDERR, "Choose a password between 12 and 72 bytes.\n");
    exit(1);
}

if (!hash_equals($password, $confirmation)) {
    fwrite(STDERR, "The passwords do not match.\n");
    exit(1);
}

$passwordHash = password_hash($password, PASSWORD_DEFAULT);
if (!is_string($passwordHash)) {
    fwrite(STDERR, "Unable to hash the administrator password.\n");
    exit(1);
}

$updateAdmin = $pdo->prepare(
    "UPDATE users
     SET email = :email, password_hash = :password_hash
     WHERE id = :admin_id AND role = 'admin'"
);
$updateAdmin->execute([
    'email' => $adminEmail,
    'password_hash' => $passwordHash,
    'admin_id' => $admin['id'],
]);

fwrite(STDOUT, "Administrator login updated for {$adminEmail}.\n");
