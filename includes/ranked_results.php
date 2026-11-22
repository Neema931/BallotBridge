<?php

function calculate_instant_runoff(array $candidates, array $ballots): array
{
    $remaining = array_fill_keys(array_keys($candidates), true);
    $rounds = [];
    $winnerId = null;
    $tiedCandidateIds = [];

    while ($remaining !== []) {
        $counts = array_fill_keys(array_keys($remaining), 0);
        $activeBallots = 0;
        foreach ($ballots as $ranking) {
            $ranked = $ranking;
            asort($ranked, SORT_NUMERIC);
            foreach ($ranked as $candidateId => $_rank) {
                if (isset($remaining[$candidateId])) {
                    $counts[$candidateId]++;
                    $activeBallots++;
                    break;
                }
            }
        }

        $round = [
            'number' => count($rounds) + 1,
            'counts' => $counts,
            'active_ballots' => $activeBallots,
            'exhausted_ballots' => count($ballots) - $activeBallots,
            'eliminated' => [],
            'winner_id' => null,
            'tie' => [],
        ];

        if ($activeBallots === 0) {
            $rounds[] = $round;
            break;
        }

        foreach ($counts as $candidateId => $count) {
            if ($count > $activeBallots / 2) {
                $winnerId = (int) $candidateId;
                $round['winner_id'] = $winnerId;
                $rounds[] = $round;
                break 2;
            }
        }

        if (count($remaining) === 1) {
            $candidateId = (int) array_key_first($remaining);
            if ($counts[$candidateId] > 0) {
                $winnerId = $candidateId;
                $round['winner_id'] = $winnerId;
            }
            $rounds[] = $round;
            break;
        }

        $lowestCount = min($counts);
        $eliminated = [];
        foreach ($counts as $candidateId => $count) {
            if ($count === $lowestCount) {
                $eliminated[] = (int) $candidateId;
            }
        }
        if (count($eliminated) === count($remaining)) {
            $tiedCandidateIds = $eliminated;
            $round['tie'] = $eliminated;
            $rounds[] = $round;
            break;
        }

        $round['eliminated'] = $eliminated;
        $rounds[] = $round;
        foreach ($eliminated as $candidateId) {
            unset($remaining[$candidateId]);
        }
    }

    return [
        'rounds' => $rounds,
        'winner_id' => $winnerId,
        'tied_candidate_ids' => $tiedCandidateIds,
    ];
}
