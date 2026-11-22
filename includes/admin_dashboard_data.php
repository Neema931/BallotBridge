<?php

require_once __DIR__ . '/election_time.php';
require_once __DIR__ . '/election_scheduler.php';

function load_admin_dashboard_data(PDO $pdo): array
{
    activate_due_auto_elections($pdo);

    $electionQuery = $pdo->prepare(
        "SELECT id, title, status, start_datetime, end_datetime, timezone,
                auto_launch, voting_system, verification_methods, allow_abstentions,
                organization_logo_path
         FROM elections
         WHERE status IN ('draft', 'open', 'paused')
         ORDER BY id DESC"
    );
    $electionQuery->execute();
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $currentElections = [];

    foreach ($electionQuery->fetchAll() as $candidateElection) {
        $start = parse_stored_election_time($candidateElection['start_datetime'], $candidateElection['timezone']);
        $end = parse_stored_election_time($candidateElection['end_datetime'], $candidateElection['timezone']);
        if ($start === null || $end === null) {
            throw new RuntimeException('An election has an invalid schedule or timezone.');
        }

        if ($candidateElection['status'] === 'paused') {
            $candidateElection['display_status'] = 'paused';
            $candidateElection['priority'] = 1;
        } elseif ($candidateElection['status'] === 'open' && $now >= $start && $now <= $end) {
            $candidateElection['display_status'] = 'live';
            $candidateElection['priority'] = 0;
        } elseif (
            in_array($candidateElection['status'], ['draft', 'open'], true)
            && $start > $now
            && $end > $now
        ) {
            $candidateElection['display_status'] = $candidateElection['status'] === 'open'
                || (bool) $candidateElection['auto_launch']
                    ? 'scheduled'
                    : 'draft';
            $candidateElection['priority'] = 2;
        } else {
            continue;
        }

        $candidateElection['start_epoch'] = $start->getTimestamp();
        $candidateElection['end_epoch'] = $end->getTimestamp();
        $currentElections[] = $candidateElection;
    }

    usort($currentElections, static function (array $left, array $right): int {
        return ($left['priority'] <=> $right['priority'])
            ?: ($left['start_epoch'] <=> $right['start_epoch'])
            ?: ($left['id'] <=> $right['id']);
    });
    $election = $currentElections[0] ?? null;

    if ($election === null) {
        $pastElectionQuery = $pdo->prepare(
            "SELECT id, title, end_datetime, timezone, status
             FROM elections
             WHERE status IN ('closed', 'open', 'paused')
             ORDER BY id DESC"
        );
        $pastElectionQuery->execute();
        $pastElections = [];
        foreach ($pastElectionQuery->fetchAll() as $pastElection) {
            $end = parse_stored_election_time($pastElection['end_datetime'], $pastElection['timezone']);
            if ($end === null) {
                throw new RuntimeException('An election has an invalid closing time or timezone.');
            }
            if ($pastElection['status'] === 'closed' || $end < $now) {
                $pastElection['end_epoch'] = $end->getTimestamp();
                $pastElections[] = $pastElection;
            }
        }
        usort(
            $pastElections,
            static fn (array $left, array $right): int => $right['end_epoch'] <=> $left['end_epoch']
        );

        return [
            'election' => null,
            'last_closed' => $pastElections === [] ? null : $pastElections[0],
        ];
    }

    $eligibleVoterQuery = $pdo->prepare(
        "SELECT COUNT(*) FROM users
         WHERE role = 'voter' AND status = 'active'"
    );
    $eligibleVoterQuery->execute();
    $eligibleVoters = (int) $eligibleVoterQuery->fetchColumn();

    $ballotQuery = $pdo->prepare(
        'SELECT COUNT(DISTINCT voter_id)
         FROM ballot_submissions WHERE election_id = :election_id'
    );
    $ballotQuery->execute(['election_id' => $election['id']]);
    $ballotsCast = (int) $ballotQuery->fetchColumn();

    $sessionQuery = $pdo->prepare(
        "SELECT COUNT(DISTINCT s.session_hash)
         FROM active_voter_sessions s
         INNER JOIN users u ON u.id = s.user_id
         WHERE u.role = 'voter'
           AND u.status = 'active'
           AND s.last_seen_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)"
    );
    $sessionQuery->execute();
    $activeVoterSessions = (int) $sessionQuery->fetchColumn();

    $groupQuery = $pdo->prepare(
        "SELECT status, COUNT(*) AS total
         FROM users
         WHERE role = 'voter'
         GROUP BY status"
    );
    $groupQuery->execute();
    $voterGroups = [
        'active' => 0,
        'pending' => 0,
        'suspended' => 0,
        'rejected' => 0,
    ];
    foreach ($groupQuery->fetchAll() as $group) {
        $voterGroups[$group['status']] = (int) $group['total'];
    }

    $clockQuery = $pdo->prepare('SELECT UNIX_TIMESTAMP(NOW())');
    $clockQuery->execute();
    $currentHour = intdiv((int) $clockQuery->fetchColumn(), 3600) * 3600;
    $chartStart = $currentHour - (23 * 3600);
    $chartEnd = $currentHour + 3600;
    $previousVotesQuery = $pdo->prepare(
        'SELECT COUNT(*) FROM (
            SELECT voter_id, MIN(submitted_at) AS first_submission
            FROM ballot_submissions
            WHERE election_id = :election_id
            GROUP BY voter_id
         ) first_ballots
         WHERE first_submission < FROM_UNIXTIME(:chart_start)'
    );
    $previousVotesQuery->execute([
        'election_id' => $election['id'],
        'chart_start' => $chartStart,
    ]);
    $cumulativeVotes = (int) $previousVotesQuery->fetchColumn();

    $hourlyVotesQuery = $pdo->prepare(
        "SELECT UNIX_TIMESTAMP(DATE_FORMAT(first_submission, '%Y-%m-%d %H:00:00')) AS bucket,
                COUNT(*) AS ballots
         FROM (
            SELECT voter_id, MIN(submitted_at) AS first_submission
            FROM ballot_submissions
            WHERE election_id = :election_id
            GROUP BY voter_id
         ) first_ballots
         WHERE first_submission >= FROM_UNIXTIME(:chart_start)
           AND first_submission < FROM_UNIXTIME(:chart_end)
         GROUP BY bucket
         ORDER BY bucket"
    );
    $hourlyVotesQuery->execute([
        'election_id' => $election['id'],
        'chart_start' => $chartStart,
        'chart_end' => $chartEnd,
    ]);
    $hourlyVotes = [];
    foreach ($hourlyVotesQuery->fetchAll() as $hour) {
        $hourlyVotes[(int) $hour['bucket']] = (int) $hour['ballots'];
    }

    $voteTimeline = [];
    $electionTimezone = new DateTimeZone($election['timezone']);
    for ($hour = 0; $hour < 24; $hour++) {
        $timestamp = $chartStart + ($hour * 3600);
        $cumulativeVotes += $hourlyVotes[$timestamp] ?? 0;
        $voteTimeline[] = [
            'timestamp' => $timestamp,
            'label' => (new DateTimeImmutable('@' . $timestamp))
                ->setTimezone($electionTimezone)
                ->format('H:i'),
            'votes' => $cumulativeVotes,
        ];
    }

    $auditQuery = $pdo->prepare(
        'SELECT a.action, a.entity_type, a.entity_id, a.summary, a.created_at,
                u.full_name AS actor_name
         FROM audit_logs a
         LEFT JOIN users u ON u.id = a.actor_user_id
         ORDER BY a.id DESC
         LIMIT 5'
    );
    $auditQuery->execute();
    $auditLogs = $auditQuery->fetchAll();

    return [
        'election' => [
            'id' => (int) $election['id'],
            'title' => $election['title'],
            'status' => $election['display_status'],
            'start_datetime' => $election['start_datetime'],
            'end_datetime' => $election['end_datetime'],
            'start_timestamp' => $election['start_epoch'],
            'end_timestamp' => $election['end_epoch'],
            'timezone' => $election['timezone'],
            'auto_launch' => (bool) $election['auto_launch'],
            'voting_system' => $election['voting_system'],
            'verification_methods' => $election['verification_methods'],
            'allow_abstentions' => (bool) $election['allow_abstentions'],
            'organization_logo_path' => $election['organization_logo_path'],
            'can_resume' => $election['status'] === 'paused' && $election['end_epoch'] >= $now->getTimestamp(),
        ],
        'last_closed' => null,
        'kpis' => [
            'eligible_voters' => $eligibleVoters,
            'ballots_cast' => $ballotsCast,
            'turnout' => $eligibleVoters > 0 ? ($ballotsCast / $eligibleVoters) * 100 : 0.0,
            'active_voter_sessions' => $activeVoterSessions,
        ],
        'voter_groups' => $voterGroups,
        'vote_timeline' => $voteTimeline,
        'audit_logs' => $auditLogs,
    ];
}
