<?php

$pageTitle = 'Secure online voting';
require_once __DIR__ . '/includes/header.php';
?>

<section class="hero" aria-labelledby="hero-title">
    <div class="container hero__inner">
        <p class="hero__eyebrow">Your voice. Your choice.</p>
        <h1 class="hero__title" id="hero-title">
            Ballot Bridge: secure, simple, transparent voting
        </h1>
        <p class="hero__description">
            Take part in elections with a clear, accessible experience from registration
            through results.
        </p>
        <div class="hero__actions">
            <a class="btn btn--black" href="<?= BASE_URL ?>/auth/register.php">Get started</a>
            <a class="hero__secondary" href="#how-it-works">How it works</a>
        </div>
    </div>
</section>

<section class="section" id="how-it-works" aria-labelledby="how-it-works-title">
    <div class="container">
        <div class="section-heading">
            <h2 id="how-it-works-title">How it works</h2>
            <p>Three straightforward steps take you from sign-up to a submitted ballot.</p>
        </div>
        <div class="grid grid--3 steps">
            <article class="card step-card">
                <span class="step-card__number" aria-hidden="true">01</span>
                <h3>Create your account</h3>
                <p>Register with your details to get started with Ballot Bridge.</p>
            </article>
            <article class="card step-card">
                <span class="step-card__number" aria-hidden="true">02</span>
                <h3>Explore the election</h3>
                <p>Review the available positions and candidate information before voting.</p>
            </article>
            <article class="card step-card">
                <span class="step-card__number" aria-hidden="true">03</span>
                <h3>Cast your vote</h3>
                <p>Make your selections and submit your ballot securely.</p>
            </article>
        </div>
    </div>
</section>

<section class="section section--roles" aria-labelledby="roles-title">
    <div class="container">
        <div class="section-heading">
            <h2 id="roles-title">A place for every role</h2>
            <p>Ballot Bridge brings voters, candidates, and election administrators together.</p>
        </div>
        <div class="grid grid--3">
            <article class="card role-card">
                <h3>Voter</h3>
                <p>Find election information, review candidates, and cast your ballot.</p>
                <a class="role-card__link" href="<?= BASE_URL ?>/auth/register.php">
                    Get started as a voter
                </a>
            </article>
            <article class="card role-card">
                <h3>Candidate</h3>
                <p>Share your profile and manifesto with voters in your election.</p>
                <a class="role-card__link" href="<?= BASE_URL ?>/auth/register.php">
                    Get started as a candidate
                </a>
            </article>
            <article class="card role-card">
                <h3>Admin</h3>
                <p>Manage elections, review candidates, and monitor results.</p>
                <a class="role-card__link" href="<?= BASE_URL ?>/auth/login.php">
                    Admin login
                </a>
            </article>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>