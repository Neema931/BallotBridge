<?php

require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../config/database.php';

if (!is_post()) {
    redirect(BASE_URL . '/voter/register.php');
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
$phone = isset($_POST['phone']) && is_string($_POST['phone'])
    ? trim($_POST['phone'])
    : '';
$password = isset($_POST['password']) && is_string($_POST['password'])
    ? $_POST['password']
    : '';
$confirmPassword = isset($_POST['confirm_password']) && is_string($_POST['confirm_password'])
    ? $_POST['confirm_password']
    : '';
$eligibilityConfirmed = isset($_POST['eligibility_confirmation'])
    && is_string($_POST['eligibility_confirmation'])
    && $_POST['eligibility_confirmation'] === '1';

$nameLength = preg_match_all('/./us', $fullName, $nameCharacters);
$passwordLength = preg_match_all('/./us', $password, $passwordCharacters);
$phoneDigits = preg_replace('/\D/', '', $phone);
if (
    $nameLength === false
    || $nameLength < 1
    || $nameLength > 150
    || filter_var($email, FILTER_VALIDATE_EMAIL) === false
    || strlen($email) > 254
    || $regNumber === ''
    || strlen($regNumber) > 100
    || strlen($phone) > 32
    || preg_match('/\A\+?[0-9()\s.-]+\z/u', $phone) !== 1
    || !is_string($phoneDigits)
    || strlen($phoneDigits) < 7
    || strlen($phoneDigits) > 15
    || $passwordLength === false
    || $passwordLength < 8
    || strlen($password) > 72
    || $password !== $confirmPassword
    || !$eligibilityConfirmed
) {
    set_flash('error', 'Please check the registration details, password confirmation, and eligibility declaration.');
    redirect(BASE_URL . '/voter/register.php');
}

$passwordHash = password_hash($password, PASSWORD_DEFAULT);
if (!is_string($passwordHash)) {
    throw new RuntimeException('Unable to hash the voter registration password.');
}

try {
    $pdo->beginTransaction();

    $duplicateQuery = $pdo->prepare(
        'SELECT id FROM users
         WHERE email = :email OR reg_number = :reg_number
         LIMIT 1
         FOR UPDATE'
    );
    $duplicateQuery->execute([
        'email' => $email,
        'reg_number' => $regNumber,
    ]);

    if ($duplicateQuery->fetchColumn() !== false) {
        $pdo->rollBack();
        set_flash('error', 'An account already exists for that email address or identity number.');
        redirect(BASE_URL . '/voter/register.php');
    }

    $insertUser = $pdo->prepare(
        "INSERT INTO users (full_name, email, reg_number, password_hash, role, status)
         VALUES (:full_name, :email, :reg_number, :password_hash, 'voter', 'pending')"
    );
    $insertUser->execute([
        'full_name' => $fullName,
        'email' => $email,
        'reg_number' => $regNumber,
        'password_hash' => $passwordHash,
    ]);

    $insertVoter = $pdo->prepare(
        "INSERT INTO voters (user_id, reg_number, phone, review_email_status)
         VALUES (:user_id, :reg_number, :phone, 'pending')"
    );
    $insertVoter->execute([
        'user_id' => (int) $pdo->lastInsertId(),
        'reg_number' => $regNumber,
        'phone' => $phone,
    ]);

    $pdo->commit();
} catch (PDOException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $isDuplicate = $exception->getCode() === '23000'
        && isset($exception->errorInfo[1])
        && (int) $exception->errorInfo[1] === 1062;

    if ($isDuplicate) {
        set_flash('error', 'An account already exists for that email address or identity number.');
        redirect(BASE_URL . '/voter/register.php');
    }

    throw $exception;
}

set_flash(
    'voter_registration_confirmation',
    'Your registration has been received. Watch your email for an approval or rejection update.'
);
set_flash('voter_login_notice', 'registered');
redirect(BASE_URL . '/voter/register.php?submitted=1');
