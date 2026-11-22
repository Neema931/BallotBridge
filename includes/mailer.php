<?php

require_once __DIR__ . '/../config/mail.php';

/**
 * Send a voter review decision using the host's configured PHP mail transport.
 */
function send_voter_review_email(string $email, string $fullName, string $status): bool
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

    $decisionMessage = $approved
        ? 'Your voter registration has been successfully approved. You can now sign in to Ballot Bridge.'
        : 'Your voter registration was rejected because the submitted details could not be verified. Please contact the administrator if you believe this is an error.';
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
