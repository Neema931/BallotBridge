<?php

require_once __DIR__ . '/../includes/auth.php';
require_role('candidate');
require_once __DIR__ . '/../includes/csrf.php';

if (is_post()) {
    verify_csrf();
    set_flash('info', 'Candidate profiles are now managed from My Profile. Review your details and save them there.');
}

$rawCandidateId = $_GET['candidate_id'] ?? $_POST['candidate_id'] ?? '';
$candidateId = is_string($rawCandidateId)
    ? filter_var($rawCandidateId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : false;
$url = BASE_URL . '/candidate/myprofile.php';
if ($candidateId !== false) {
    $url .= '?candidate_id=' . (int) $candidateId;
}

redirect($url);
