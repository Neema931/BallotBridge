    </main>
    <?php if (empty($hideFooter)): ?>
        <footer class="footer">
            <div class="container footer__inner">
                <p>&copy; <?= date('Y') ?> <?= sanitize(APP_NAME) ?>.</p>
                <a href="<?= BASE_URL ?>/index.php">Home</a>
            </div>
        </footer>
    <?php endif; ?>
    <script src="<?= BASE_URL ?>/assets/js/main.js?v=3" defer></script>
</body>
</html>