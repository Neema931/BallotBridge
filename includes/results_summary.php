<?php

function resolve_position_winners(
    string $votingSystem,
    array $candidates,
    int $maxVotes,
    ?array $rankedResult = null
): array {
    if ($votingSystem === 'ranked_choice') {
        $winnerId = $rankedResult['winner_id'] ?? null;
        $tiedIds = $rankedResult['tied_candidate_ids'] ?? [];
        return [
            'winner_ids' => $winnerId === null ? [] : [(int) $winnerId],
            'tied_ids' => array_map('intval', $tiedIds),
            'outcome' => $winnerId !== null ? 'winner' : ($tiedIds !== [] ? 'tie' : 'no_votes'),
        ];
    }

    $positiveCounts = [];
    foreach ($candidates as $candidateId => $candidate) {
        $count = (int) ($candidate['votes'] ?? 0);
        if ($count > 0) {
            $positiveCounts[(int) $candidateId] = $count;
        }
    }
    if ($positiveCounts === []) {
        return ['winner_ids' => [], 'tied_ids' => [], 'outcome' => 'no_votes'];
    }

    arsort($positiveCounts, SORT_NUMERIC);
    if ($votingSystem !== 'multiple_selection') {
        $highestCount = reset($positiveCounts);
        $leaders = array_keys(array_filter(
            $positiveCounts,
            static fn (int $count): bool => $count === $highestCount
        ));
        return [
            'winner_ids' => count($leaders) === 1 ? [(int) $leaders[0]] : [],
            'tied_ids' => count($leaders) > 1 ? array_map('intval', $leaders) : [],
            'outcome' => count($leaders) === 1 ? 'winner' : 'tie',
        ];
    }

    $seatCount = max(1, $maxVotes);
    if (count($positiveCounts) <= $seatCount) {
        return [
            'winner_ids' => array_map('intval', array_keys($positiveCounts)),
            'tied_ids' => [],
            'outcome' => 'winner',
        ];
    }

    $threshold = array_values($positiveCounts)[$seatCount - 1];
    $winnerIds = [];
    $tiedIds = [];
    foreach ($positiveCounts as $candidateId => $count) {
        if ($count > $threshold) {
            $winnerIds[] = (int) $candidateId;
        } elseif ($count === $threshold) {
            $tiedIds[] = (int) $candidateId;
        }
    }

    $remainingSeats = $seatCount - count($winnerIds);
    if (count($tiedIds) <= $remainingSeats) {
        $winnerIds = array_merge($winnerIds, $tiedIds);
        $tiedIds = [];
    }

    return [
        'winner_ids' => $winnerIds,
        'tied_ids' => $tiedIds,
        'outcome' => $tiedIds === []
            ? 'winner'
            : ($winnerIds === [] ? 'tie' : 'partial_tie'),
    ];
}
