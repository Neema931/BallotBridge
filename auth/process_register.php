<?php

require_once __DIR__ . '/../includes/csrf.php';

if (!is_post()) {
    redirect(BASE_URL . '/auth/register.php');
}

verify_csrf();

$fullName = isset($_POST['full_name']) && is_string($_POST['full_name'])
    ? trim($_POST['full_name'])
    : '';
$email = isset($_POST['email']) && is_string($_POST['email'])
    ? strtolower(trim($_POST['email']))
    : '';
$regNumber = isset($_POST['reg_number']) && is_string($_POST['reg_number'])
    ? trim($_POST['reg_number'])
    : '';
$password = isset($_POST['password']) && is_string($_POST['password'])
    ? $_POST['password']
    : '';
$confirmPassword = isset($_POST['confirm_password']) && is_string($_POST['confirm_password'])
    ? $_POST['confirm_password']
    : '';
$role = isset($_POST['role']) && is_string($_POST['role'])
    ? $_POST['role']
    : '';

$nameLength = preg_match_all('/./us', $fullName, $nameCharacters);
$passwordLength = preg_match_all('/./us', $password, $passwordCharacters);
if (
    $nameLength === false
    || $nameLength < 1
    || $nameLength > 150
    || filter_var($email, FILTER_VALIDATE_EMAIL) === false
    || strlen($email) > 254
    || $regNumber === ''
    || strlen($regNumber) > 100
    || !in_array($role, ['voter', 'candidate'], true)
    || $passwordLength === false
    || $passwordLength < 8
    || strlen($password) > 72
    || $password !== $confirmPassword
) {
    set_flash('error', 'Please check the registration details and try again.');
    redirect(BASE_URL . '/auth/register.php');
}

$status = 'pending';
$passwordHash = password_hash($password, PASSWORD_DEFAULT);

if (!is_string($passwordHash)) {
    throw new RuntimeException('Unable to hash the registration password.');
}

require_once __DIR__ . '/../config/database.php';

$duplicateQuery = $pdo->prepare(
    'SELECT id FROM users WHERE email = :email OR reg_number = :reg_number LIMIT 1'
);
$duplicateQuery->execute([
    'email' => $email,
    'reg_number' => $regNumber,
]);

if ($duplicateQuery->fetchColumn() !== false) {
    set_flash('error', 'That email address or registration number is already registered.');
    redirect(BASE_URL . '/auth/register.php');
}

$pdo->beginTransaction();

try {
    $insertUser = $pdo->prepare(
        'INSERT INTO users (full_name, email, reg_number, password_hash, role, status)
         VALUES (:full_name, :email, :reg_number, :password_hash, :role, :status)'
    );
    $insertUser->execute([
        'full_name' => $fullName,
        'email' => $email,
        'reg_number' => $regNumber,
        'password_hash' => $passwordHash,
        'role' => $role,
        'status' => $status,
    ]);

    $userId = (int) $pdo->lastInsertId();

    if ($role === 'voter') {
        $insertVoter = $pdo->prepare(
            "INSERT INTO voters (user_id, reg_number, review_email_status)
             VALUES (:user_id, :reg_number, 'pending')"
        );
        $insertVoter->execute([
            'user_id' => $userId,
            'reg_number' => $regNumber,
        ]);
    }

    $pdo->commit();
} catch (PDOException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $isDuplicate = $exception->getCode() === '23000'
        && isset($exception->errorInfo[1])
        && (int) $exception->errorInfo[1] === 1062;

    if ($isDuplicate) {
        set_flash('error', 'That email address or registration number is already registered.');
        redirect(BASE_URL . '/auth/register.php');
    }

    throw $exception;
}

if ($status === 'pending') {
    set_flash('success', 'Registration received. Your account is pending approval.');
} else {
    set_flash('success', 'Registration successful. You can now log in.');
}

redirect(BASE_URL . '/auth/login.php');
