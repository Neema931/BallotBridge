(() => {
    const lock = document.querySelector("[data-results-lock]");
    if (!(lock instanceof HTMLElement)) {
        return;
    }

    const countdown = lock.querySelector("[data-results-countdown]");
    const refreshStatus = lock.querySelector("[data-results-refresh]");
    const closeAt = countdown instanceof HTMLTimeElement
        ? Number(countdown.dataset.closeAt) * 1000
        : Number.NaN;
    const updateCountdown = () => {
        if (!(countdown instanceof HTMLTimeElement)) {
            return;
        }
        if (!Number.isFinite(closeAt) || closeAt <= 0) {
            countdown.textContent = "Unavailable";
            return;
        }

        const remaining = Math.max(0, Math.floor((closeAt - Date.now()) / 1000));
        if (remaining === 0) {
            countdown.textContent = "Poll close time reached — waiting for official closure.";
            return;
        }
        const days = Math.floor(remaining / 86400);
        const hours = Math.floor((remaining % 86400) / 3600);
        const minutes = Math.floor((remaining % 3600) / 60);
        const seconds = remaining % 60;
        countdown.textContent = days > 0
            ? `${days}d ${hours}h ${minutes}m`
            : `${hours}h ${minutes}m ${seconds}s`;
    };

    const checkElectionStatus = async () => {
        const electionId = lock.dataset.electionId;
        const statusUrl = lock.dataset.statusUrl;
        if (!electionId || !statusUrl) {
            console.error("Results privacy lock is missing its status endpoint or election ID.");
            if (refreshStatus) {
                refreshStatus.textContent = "Automatic status refresh is unavailable; reload this page after polls close.";
            }
            return;
        }

        try {
            const url = new URL(statusUrl, window.location.href);
            url.searchParams.set("election_id", electionId);
            const response = await fetch(url, {
                credentials: "same-origin",
                cache: "no-store",
                headers: { Accept: "application/json" },
            });
            if (!response.ok) {
                throw new Error(`Election status check returned ${response.status}.`);
            }
            if (!(response.headers.get("content-type") || "").includes("application/json")) {
                throw new Error("Election status check returned an unexpected response type.");
            }

            const status = await response.json();
            if (status.closed || status.status !== lock.dataset.currentStatus) {
                window.location.reload();
            }
        } catch (error) {
            console.error("Unable to refresh the results privacy lock state.", error);
            if (refreshStatus) {
                refreshStatus.textContent = "Live status refresh delayed; results remain sealed until this page is refreshed.";
            }
        }
    };

    updateCountdown();
    window.setInterval(updateCountdown, 1000);
    checkElectionStatus();
    window.setInterval(checkElectionStatus, 15000);
})();
