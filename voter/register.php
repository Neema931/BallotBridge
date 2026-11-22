<?php

require_once __DIR__ . '/../includes/csrf.php';

$registrationConfirmation = null;
if (isset($_GET['submitted']) && is_string($_GET['submitted']) && $_GET['submitted'] === '1') {
    $registrationConfirmation = get_flash('voter_registration_confirmation');
}
$showConfirmation = is_string($registrationConfirmation);

$pageTitle = 'Voter registration';
require_once __DIR__ . '/../includes/header.php';
?>

<section class="section voter-registration">
    <div class="container">
        <div class="form-card voter-registration-card">
            <header class="voter-registration__header">
                <a class="voter-registration__brand" href="<?= BASE_URL ?>/index.php">
                    <?= sanitize(APP_NAME) ?>
                </a>
                <h1>Voter Registration</h1>
                <?php if (!$showConfirmation): ?>
                    <p>Register to become an eligible voter in Ballot Bridge.</p>
                <?php endif; ?>
            </header>

            <?php if ($showConfirmation): ?>
                <section class="voter-registration__confirmation" role="status">
                    <span class="voter-registration__confirmation-mark" aria-hidden="true">✓</span>
                    <div>
                        <h2>Registration submitted</h2>
                        <p><?= sanitize($registrationConfirmation) ?></p>
                        <p>Your account remains pending until an administrator completes the review.</p>
                    </div>
                    <a class="btn btn--primary" href="<?= BASE_URL ?>/voter/login.php">Sign in</a>
                    <p class="voter-registration__signin">
                        Already registered?
                    <a href="<?= BASE_URL ?>/voter/login.php">Sign in here</a>
                    </p>
                </section>
            <?php else: ?>
                <aside class="voter-registration__notice">
                    <strong>Administrator verification required</strong>
                    <p>
                        Every self-registration is reviewed before voting credentials are activated.
                        Approval and rejection updates are sent to your email; the phone number is
                        collected for election contact when needed.
                    </p>
                </aside>

                <form class="form voter-registration__form" data-validate data-loading-state
                    method="post" action="<?= BASE_URL ?>/voter/process_register.php">
                    <?= csrf_field() ?>

                    <section class="voter-registration__group" aria-labelledby="voter-identity-title">
                        <h2 id="voter-identity-title">Personal &amp; Identity Details</h2>
                        <div class="form__group">
                            <label for="voter-full-name">Full legal name</label>
                            <input class="form__control" id="voter-full-name" name="full_name"
                                type="text" autocomplete="name" maxlength="150" required>
                        </div>
                        <div class="form__group">
                            <label for="voter-reg-number">National ID / Student ID / Member Number</label>
                            <input class="form__control" id="voter-reg-number" name="reg_number"
                                type="text" autocomplete="off" maxlength="100" required
                                aria-describedby="voter-id-hint">
                            <p class="form__hint" id="voter-id-hint">
                                Each identity number can be used for one account. Duplicates are checked when you submit.
                            </p>
                        </div>
                    </section>

                    <section class="voter-registration__group" aria-labelledby="voter-contact-title">
                        <h2 id="voter-contact-title">Contact Information</h2>
                        <div class="form__group">
                            <label for="voter-email">Official email address</label>
                            <input class="form__control" id="voter-email" name="email" type="email"
                                autocomplete="email" maxlength="254" required>
                            <p class="form__hint">Approval and rejection updates will be sent to this address.</p>
                        </div>
                        <div class="form__group">
                            <label for="voter-phone">Phone number</label>
                            <input class="form__control" id="voter-phone" name="phone" type="tel"
                                autocomplete="tel" inputmode="tel" maxlength="32"
                                pattern="[+0-9(). -]{7,32}" required>
                        </div>
                    </section>

                    <section class="voter-registration__group" aria-labelledby="voter-security-title">
                        <h2 id="voter-security-title">Account Security</h2>
                        <div class="form__group">
                            <label for="voter-password">Password</label>
                            <div class="password-field">
                                <input class="form__control" id="voter-password" name="password"
                                    type="password" data-password autocomplete="new-password"
                                    minlength="8" maxlength="72" required
                                    data-password-strength-input
                                    aria-describedby="voter-password-hint voter-password-strength-label">
                                <button class="password-toggle" type="button"
                                    aria-controls="voter-password" aria-label="Show password"
                                    aria-pressed="false" data-password-toggle>
                                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"
                                        data-password-toggle-show hidden>
                                        <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/>
                                        <circle cx="12" cy="12" r="3"/>
                                    </svg>
                                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"
                                        data-password-toggle-hide>
                                        <path d="M3 3 21 21M10.6 5.2A10.7 10.7 0 0 1 12 5c6.4 0 10 7 10 7a15 15 0 0 1-3.1 3.8M6.2 6.2C3.5 8.1 2 12 2 12s3.6 7 10 7a10.7 10.7 0 0 0 3-.4"/>
                                        <path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/>
                                    </svg>
                                </button>
                            </div>
                            <p class="form__hint" id="voter-password-hint">
                                Use at least 8 characters. A longer, unique passphrase is stronger.
                            </p>
                            <div class="voter-registration__strength">
                                <label id="voter-password-strength-label" for="voter-password-strength">
                                    Password strength
                                </label>
                                <meter id="voter-password-strength" min="0" max="4" low="2"
                                    high="3" optimum="4" value="0" data-password-strength></meter>
                                <output data-password-strength-output aria-live="polite">Not entered</output>
                            </div>
                        </div>
                        <div class="form__group">
                            <label for="voter-confirm-password">Confirm password</label>
                            <div class="password-field">
                                <input class="form__control" id="voter-confirm-password"
                                    name="confirm_password" type="password" data-password-confirm
                                    autocomplete="new-password" minlength="8" maxlength="72"
                                    aria-describedby="voter-password-match-error" required>
                                <button class="password-toggle" type="button"
                                    aria-controls="voter-confirm-password" aria-label="Show password"
                                    aria-pressed="false" data-password-toggle>
                                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"
                                        data-password-toggle-show hidden>
                                        <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/>
                                        <circle cx="12" cy="12" r="3"/>
                                    </svg>
                                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"
                                        data-password-toggle-hide>
                                        <path d="M3 3 21 21M10.6 5.2A10.7 10.7 0 0 1 12 5c6.4 0 10 7 10 7a15 15 0 0 1-3.1 3.8M6.2 6.2C3.5 8.1 2 12 2 12s3.6 7 10 7a10.7 10.7 0 0 0 3-.4"/>
                                        <path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/>
                                    </svg>
                                </button>
                            </div>
                            <p class="form__error" id="voter-password-match-error" hidden>
                                Passwords must match.
                            </p>
                        </div>
                    </section>

                    <label class="voter-registration__eligibility">
                        <input type="checkbox" name="eligibility_confirmation" value="1" required>
                        <span>I confirm that I am eligible to register and that the information above is accurate.</span>
                    </label>

                    <div class="form-card__actions">
                        <button class="btn btn--primary" type="submit">Submit Registration for Approval</button>
                    </div>
                    <p class="loading-state" role="status" aria-live="polite"
                        data-loading-indicator hidden>
                        <span class="loading-state__spinner" aria-hidden="true"></span>
                        Submitting your registration…
                    </p>
                    <p class="voter-registration__signin">
                        Already registered?
                        <a href="<?= BASE_URL ?>/voter/login.php">Sign in here</a>
                    </p>
                </form>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php if (!$showConfirmation): ?>
    <script src="<?= BASE_URL ?>/assets/js/validation.js?v=2" defer></script>
    <script src="<?= BASE_URL ?>/assets/js/voter-registration.js?v=1" defer></script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
