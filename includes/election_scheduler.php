<?php

require_once __DIR__ . '/election_time.php';

function activate_due_auto_elections(PDO $pdo): int
{
    $query = $pdo->prepare(
        "SELECT id, status, start_datetime, end_datetime, timezone, auto_launch
         FROM elections
         WHERE status IN ('draft', 'open', 'paused')
           AND (auto_launch = 1 OR status IN ('open', 'paused'))"
    );
    $query->execute();

    $activated = 0;
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $openElection = $pdo->prepare(
        "UPDATE elections SET status = 'open'
         WHERE id = :election_id AND status = 'draft' AND auto_launch = 1"
    );
    $closeElection = $pdo->prepare(
        "UPDATE elections
         SET status = 'closed',
             closed_at = UTC_TIMESTAMP(),
             eligible_voter_count = (
                 SELECT COUNT(*) FROM users
                 WHERE role = 'voter' AND status = 'active'
             )
         WHERE id = :election_id AND status = :previous_status"
    );
    $positionCount = $pdo->prepare(
        'SELECT COUNT(*) FROM positions WHERE election_id = :election_id'
    );
    $auditLog = $pdo->prepare(
        'INSERT INTO audit_logs
            (actor_name_snapshot, actor_role_snapshot, action, entity_type, entity_id, summary, before_json, after_json)
         VALUES
            (\'Automated system\', \'system\', :action, \'election\', :election_id, :summary,
             JSON_OBJECT(\'status\', :previous_status),
             JSON_OBJECT(\'status\', :new_status, \'eligible_voter_count\', :eligible_voter_count))'
    );
    $eligibleVoterSnapshot = $pdo->prepare(
        'SELECT eligible_voter_count FROM elections WHERE id = :election_id'
    );

    foreach ($query->fetchAll() as $election) {
        $start = parse_stored_election_time($election['start_datetime'], $election['timezone']);
        $end = parse_stored_election_time($election['end_datetime'], $election['timezone']);
        if ($start === null || $end === null) {
            throw new RuntimeException('An automatic election has an invalid schedule or timezone.');
        }

        if (
            $election['status'] !== 'closed'
            && $end < $now
            && ($election['status'] !== 'draft' || (bool) $election['auto_launch'])
        ) {
            $pdo->beginTransaction();
            try {
                $closeElection->execute([
                    'election_id' => $election['id'],
                    'previous_status' => $election['status'],
                ]);
                if ($closeElection->rowCount() === 1) {
                    $eligibleVoterSnapshot->execute(['election_id' => $election['id']]);
                    $auditLog->execute([
                        'action' => 'election.auto_closed',
                        'election_id' => $election['id'],
                        'summary' => 'Automatically closed an election after its scheduled closing time.',
                        'previous_status' => $election['status'],
                        'new_status' => 'closed',
                        'eligible_voter_count' => (int) $eligibleVoterSnapshot->fetchColumn(),
                    ]);
                }
                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }
            continue;
        }

        if ($election['status'] !== 'draft' || !(bool) $election['auto_launch'] || $start > $now) {
            continue;
        }

        $positionCount->execute(['election_id' => $election['id']]);
        if ((int) $positionCount->fetchColumn() === 0) {
            continue;
        }

        $pdo->beginTransaction();
        try {
            $openElection->execute(['election_id' => $election['id']]);
            if ($openElection->rowCount() === 1) {
                $auditLog->execute([
                    'action' => 'election.auto_opened',
                    'election_id' => $election['id'],
                    'summary' => 'Automatically opened an election at its scheduled start.',
                    'previous_status' => 'draft',
                    'new_status' => 'open',
                    'eligible_voter_count' => null,
                ]);
                $activated++;
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    return $activated;
}
