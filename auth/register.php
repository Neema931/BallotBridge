<?php

require_once __DIR__ . '/../includes/csrf.php';

$pageTitle = 'Create an account';
require_once __DIR__ . '/../includes/header.php';
?>

<section class="section">
    <div class="container">
        <div class="form-card">
            <header class="form-card__header">
                <h1>Create an account</h1>
                <p>Register as a voter or candidate to get started.</p>
            </header>

            <form class="form" data-validate data-loading-state method="post"
                action="<?= BASE_URL ?>/auth/process_register.php">
                <?= csrf_field() ?>

                <div class="form__group">
                    <label class="form__label" for="full_name">Full name</label>
                    <input class="form__control" id="full_name" name="full_name" type="text"
                        autocomplete="name" maxlength="150" required>
                </div>

                <div class="form__group">
                    <label class="form__label" for="email">Email address</label>
                    <input class="form__control" id="email" name="email" type="email"
                        autocomplete="email" maxlength="254" required>
                </div>

                <div class="form__group">
                    <label class="form__label" for="reg_number">Registration number</label>
                    <input class="form__control" id="reg_number" name="reg_number" type="text"
                        autocomplete="off" maxlength="100" required>
                </div>

                <div class="form__group">
                    <label class="form__label" for="password">Password</label>
                    <input class="form__control" id="password" name="password" type="password"
                        data-password
                        autocomplete="new-password" minlength="8" maxlength="72" required>
                    <p class="form__hint">Use at least 8 characters (maximum 72 bytes).</p>
                </div>

                <div class="form__group">
                    <label class="form__label" for="confirm_password">Confirm password</label>
                    <input class="form__control" id="confirm_password" name="confirm_password"
                        type="password" data-password-confirm autocomplete="new-password" minlength="8"
                        maxlength="72" aria-describedby="password-match-error" required>
                    <p class="form__error" id="password-match-error" hidden>
                        Passwords must match.
                    </p>
                </div>

                <div class="form__group">
                    <label class="form__label" for="role">Register as</label>
                    <select class="form__control" id="role" name="role" required>
                        <option value="" selected disabled>Choose a role</option>
                        <option value="voter">Voter</option>
                        <option value="candidate">Candidate</option>
                    </select>
                </div>

                <div class="form-card__actions">
                    <button class="btn btn--primary" type="submit">Create account</button>
                    <a href="<?= BASE_URL ?>/auth/login.php">Already registered? Log in</a>
                </div>
                <p class="loading-state" role="status" aria-live="polite"
                    data-loading-indicator hidden>
                    <span class="loading-state__spinner" aria-hidden="true"></span>
                    Creating your account…
                </p>
            </form>
        </div>
    </div>
</section>

<script src="<?= BASE_URL ?>/assets/js/validation.js" defer></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>