(() => {
    const formatRemaining = (seconds) => {
        const days = Math.floor(seconds / 86400);
        const hours = Math.floor((seconds % 86400) / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        const remainingSeconds = seconds % 60;
        const time = [hours, minutes, remainingSeconds]
            .map((part) => String(part).padStart(2, '0'))
            .join(':');

        return days > 0 ? `${days}d ${time}` : time;
    };

    document.querySelectorAll('[data-countdown]').forEach((countdown) => {
        const target = Number(countdown.dataset.countdownUnix);
        const output = countdown.querySelector('[data-countdown-value]');
        if (!Number.isFinite(target) || !output) {
            return;
        }

        const update = () => {
            const remaining = Math.max(0, target - Math.floor(Date.now() / 1000));
            output.textContent = remaining === 0
                ? 'Time reached'
                : formatRemaining(remaining);
        };

        update();
        window.setInterval(update, 1000);
    });
})();
