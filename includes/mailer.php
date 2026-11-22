<?php

require_once __DIR__ . '/../config/mail.php';

/**
 * Send a voter review decision using the host's configured PHP mail transport.
 */
function send_voter_review_email(
    string $email,
    string $fullName,
    string $status,
    string $rejectionReason = 'invalid details'
): bool
{
    if (!in_array($status, ['active', 'rejected'], true)) {
        error_log('Unable to send voter review email because the review status is invalid.');
        return false;
    }

    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        error_log('Unable to send voter review email because the recipient address is invalid.');
        return false;
    }

    if (filter_var(MAIL_FROM_ADDRESS, FILTER_VALIDATE_EMAIL) === false || !function_exists('mail')) {
        error_log('Unable to send voter review email because the mail configuration is invalid.');
        return false;
    }

    $approved = $status === 'active';
    $subject = $approved
        ? 'Ballot Bridge voter registration approved'
        : 'Ballot Bridge voter registration rejected';
    $greetingName = preg_replace('/[\r\n\x00-\x1F\x7F]+/u', ' ', $fullName);
    if (!is_string($greetingName)) {
        throw new RuntimeException('Unable to format the voter name for email.');
    }

    $rejectionReason = trim(preg_replace('/[\r\n\x00-\x1F\x7F]+/u', ' ', $rejectionReason) ?? '');
    if ($rejectionReason === '') {
        $rejectionReason = 'invalid details';
    }
    $decisionMessage = $approved
        ? 'Your voter registration has been successfully approved. You can now sign in to Ballot Bridge.'
        : 'Your voter registration was rejected. Reason: ' . $rejectionReason
            . '. Please contact the administrator if you believe this is an error.';
    $message = "Hello {$greetingName},\n\n{$decisionMessage}\n\nBallot Bridge";
    $headers = [
        'From: Ballot Bridge <' . MAIL_FROM_ADDRESS . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
    ];

    if (!mail($email, $subject, $message, implode("\r\n", $headers))) {
        error_log('The PHP mail transport rejected a voter review email.');
        return false;
    }

    return true;
}

function send_voter_password_setup_email(string $email, string $fullName, string $token): bool
{
    if (
        filter_var($email, FILTER_VALIDATE_EMAIL) === false
        || filter_var(MAIL_FROM_ADDRESS, FILTER_VALIDATE_EMAIL) === false
        || !function_exists('mail')
        || preg_match('/\A[a-f0-9]{64}\z/', $token) !== 1
    ) {
        error_log('Unable to send a voter password setup link because the request or mail configuration is invalid.');
        return false;
    }

    $greetingName = preg_replace('/[\r\n\x00-\x1F\x7F]+/u', ' ', $fullName);
    if (!is_string($greetingName)) {
        throw new RuntimeException('Unable to format the voter name for email.');
    }

    $link = rtrim(BASE_URL, '/') . '/auth/set_voter_password.php?token=' . rawurlencode($token);
    $subject = 'Ballot Bridge voter sign-in setup';
    $message = "Hello {$greetingName},\n\n"
        . "Use this one-time link to set or reset your Ballot Bridge password:\n{$link}\n\n"
        . "The link expires in 30 minutes and can only be used once. "
        . "If you did not request this link, you can ignore this message.\n\n"
        . 'Ballot Bridge';
    $headers = [
        'From: Ballot Bridge <' . MAIL_FROM_ADDRESS . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
    ];

    if (!mail($email, $subject, $message, implode("\r\n", $headers))) {
        error_log('The PHP mail transport rejected a voter password setup email.');
        return false;
    }

    return true;
}

function send_election_email_token(string $email, string $fullName, string $token, string $electionTitle): bool
{
    if (
        filter_var($email, FILTER_VALIDATE_EMAIL) === false
        || filter_var(MAIL_FROM_ADDRESS, FILTER_VALIDATE_EMAIL) === false
        || !function_exists('mail')
    ) {
        error_log('Unable to send an election verification token because the mail configuration is invalid.');
        return false;
    }

    $greetingName = preg_replace('/[\r\n\x00-\x1F\x7F]+/u', ' ', $fullName);
    if (!is_string($greetingName)) {
        throw new RuntimeException('Unable to format the voter name for email.');
    }

    $subject = 'Ballot Bridge election verification code';
    $message = "Hello {$greetingName},\n\n"
        . "Your verification code for {$electionTitle} is {$token}.\n"
        . "The code expires in 10 minutes. If you did not request it, you can ignore this message.\n\n"
        . 'Ballot Bridge';
    $headers = [
        'From: Ballot Bridge <' . MAIL_FROM_ADDRESS . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
    ];

    if (!mail($email, $subject, $message, implode("\r\n", $headers))) {
        error_log('The PHP mail transport rejected an election verification email.');
        return false;
    }

    return true;
}
