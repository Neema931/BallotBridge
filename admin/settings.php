<?php

require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

$pageTitle = 'Settings';
require_once __DIR__ . '/../includes/header.php';
?>

<section class="section">
    <div class="container">
        <header class="dashboard__header">
            <div>
                <h1>Settings</h1>
                <p>Customize how the admin area looks in this browser.</p>
            </div>
        </header>

        <section class="card settings-card" aria-labelledby="appearance-title">
            <h2 id="appearance-title">Appearance</h2>
            <p>Choose between the light and dark themes.</p>
            <label class="theme-setting" for="admin-dark-theme">
                <input id="admin-dark-theme" type="checkbox" data-admin-theme-toggle>
                <span>Use dark theme</span>
            </label>
            <p class="form__hint" role="status" aria-live="polite" data-theme-status>
                Light theme is active.
            </p>
        </section>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
