<?php

function issue_voter_password_token(PDO $pdo, int $userId): string
{
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);

    $invalidate = $pdo->prepare(
        'UPDATE voter_password_tokens
         SET used_at = UTC_TIMESTAMP()
         WHERE user_id = :user_id AND used_at IS NULL'
    );
    $invalidate->execute(['user_id' => $userId]);

    $insert = $pdo->prepare(
        'INSERT INTO voter_password_tokens (user_id, token_hash, expires_at)
         VALUES (:user_id, :token_hash, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 MINUTE))'
    );
    $insert->execute([
        'user_id' => $userId,
        'token_hash' => $tokenHash,
    ]);

    return $token;
}
