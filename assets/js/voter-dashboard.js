document.addEventListener('DOMContentLoaded', () => {
    const electionPicker = document.querySelector('[data-election-picker]');
    if (electionPicker) {
        electionPicker.addEventListener('change', () => {
            if (electionPicker.form) {
                electionPicker.form.submit();
            }
        });
    }

    const countdown = document.querySelector('[data-voter-countdown]');
    if (!countdown) {
        return;
    }

    const targetSeconds = Number(countdown.dataset.countdownAt);
    if (!Number.isFinite(targetSeconds) || targetSeconds <= 0) {
        return;
    }

    let refreshStarted = false;
    const renderCountdown = () => {
        const remainingSeconds = targetSeconds - Math.floor(Date.now() / 1000);
        if (remainingSeconds <= 0) {
            countdown.textContent = 'Updating election status…';
            if (!refreshStarted) {
                refreshStarted = true;
                window.setTimeout(() => window.location.reload(), 1500);
            }
            return;
        }

        const days = Math.floor(remainingSeconds / 86400);
        const hours = Math.floor((remainingSeconds % 86400) / 3600);
        const minutes = Math.floor((remainingSeconds % 3600) / 60);
        const seconds = remainingSeconds % 60;
        const timeParts = [];

        if (days > 0) {
            timeParts.push(`${days}d`);
        }
        timeParts.push(`${String(hours).padStart(2, '0')}h`);
        timeParts.push(`${String(minutes).padStart(2, '0')}m`);
        timeParts.push(`${String(seconds).padStart(2, '0')}s`);
        countdown.textContent = timeParts.join(' ');
    };

    renderCountdown();
    window.setInterval(renderCountdown, 1000);
});
