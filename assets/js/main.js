document.addEventListener("submit", (event) => {
    if (event.defaultPrevented) {
        return;
    }

    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.hasAttribute("data-loading-state")) {
        return;
    }

    const loadingIndicator = form.querySelector("[data-loading-indicator]");
    if (loadingIndicator) {
        loadingIndicator.hidden = false;
        form.setAttribute("aria-busy", "true");
    }
});

document.querySelectorAll("[data-print-page]").forEach((button) => {
    button.addEventListener("click", () => window.print());
});

const passwordToggleIcons = `
    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" data-password-toggle-show hidden>
        <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/>
        <circle cx="12" cy="12" r="3"/>
    </svg>
    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" data-password-toggle-hide>
        <path d="M3 3 21 21M10.6 5.2A10.7 10.7 0 0 1 12 5c6.4 0 10 7 10 7a15 15 0 0 1-3.1 3.8M6.2 6.2C3.5 8.1 2 12 2 12s3.6 7 10 7a10.7 10.7 0 0 0 3-.4"/>
        <path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/>
    </svg>`;

let generatedPasswordId = 0;
document.querySelectorAll('input[type="password"]').forEach((input) => {
    const existingWrapper = input.closest(".password-field");
    const hasMatchingToggle = existingWrapper
        && [...existingWrapper.querySelectorAll("[data-password-toggle]")]
            .some((toggle) => toggle.getAttribute("aria-controls") === input.id);
    if (hasMatchingToggle) {
        return;
    }

    const wrapper = existingWrapper ?? document.createElement("div");
    if (!existingWrapper) {
        wrapper.className = "password-field";
        input.before(wrapper);
        wrapper.append(input);
    }

    if (!input.id) {
        do {
            generatedPasswordId += 1;
            input.id = `password-field-${generatedPasswordId}`;
        } while (document.getElementById(input.id) !== input);
    }

    const toggle = document.createElement("button");
    toggle.className = "password-toggle";
    toggle.type = "button";
    toggle.setAttribute("aria-controls", input.id);
    toggle.setAttribute("aria-label", "Show password");
    toggle.setAttribute("aria-pressed", "false");
    toggle.setAttribute("data-password-toggle", "");
    toggle.innerHTML = passwordToggleIcons;
    wrapper.append(toggle);
});

document.querySelectorAll("[data-password-toggle]").forEach((toggle) => {
    const inputId = toggle.getAttribute("aria-controls");
    const input = inputId ? document.getElementById(inputId) : null;
    if (!(input instanceof HTMLInputElement)) {
        return;
    }

    const showIcon = toggle.querySelector("[data-password-toggle-show]");
    const hideIcon = toggle.querySelector("[data-password-toggle-hide]");

    toggle.addEventListener("click", () => {
        const showPassword = input.type === "password";
        input.type = showPassword ? "text" : "password";
        toggle.setAttribute("aria-pressed", String(showPassword));
        toggle.setAttribute("aria-label", showPassword ? "Hide password" : "Show password");
        if (showIcon) {
            showIcon.hidden = !showPassword;
        }
        if (hideIcon) {
            hideIcon.hidden = showPassword;
        }
    });
});

if (document.body.dataset.voterHeartbeat) {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute("content");
    const heartbeatUrl = document.body.dataset.voterHeartbeat;
    const sessionIndicator = document.querySelector("[data-session-indicator]");
    const setSessionIndicator = (state, message) => {
        if (sessionIndicator) {
            sessionIndicator.dataset.state = state;
            sessionIndicator.textContent = message;
        }
    };

    if (csrfToken) {
        const sendVoterHeartbeat = async () => {
            try {
                const response = await fetch(heartbeatUrl, {
                    method: "POST",
                    credentials: "same-origin",
                    cache: "no-store",
                    headers: { "Content-Type": "application/x-www-form-urlencoded" },
                    body: new URLSearchParams({ csrf_token: csrfToken }),
                });
                if (response.status !== 204) {
                    setSessionIndicator("reconnecting", "Session connection issue");
                    console.error("Voter activity heartbeat was not accepted.", response.status);
                    return;
                }
                setSessionIndicator("protected", "Session Protected");
            } catch (error) {
                setSessionIndicator("reconnecting", "Session connection issue");
                console.error("Unable to update voter activity.", error);
            }
        };

        sendVoterHeartbeat();
        window.setInterval(sendVoterHeartbeat, 60_000);
    } else {
        setSessionIndicator("unavailable", "Session protection unavailable");
        console.error("Voter activity heartbeat is missing its CSRF token.");
    }
}

const emptyElectionDashboard = document.querySelector("[data-admin-dashboard]:not([data-election-id])");
if (emptyElectionDashboard) {
    const dataUrl = emptyElectionDashboard.dataset.dataUrl;
    const refreshStatus = emptyElectionDashboard.querySelector("[data-empty-refresh-status]");
    const previousClosedId = emptyElectionDashboard.dataset.lastClosedId || "";

    const refreshEmptyDashboard = async () => {
        try {
            const response = await fetch(dataUrl, {
                credentials: "same-origin",
                cache: "no-store",
                headers: { Accept: "application/json" },
            });
            if (response.redirected && new URL(response.url).pathname.endsWith("/auth/login.php")) {
                window.location.assign(response.url);
                return;
            }
            if (!response.ok) {
                throw new Error(`Dashboard refresh returned ${response.status}.`);
            }
            if (!(response.headers.get("content-type") || "").includes("application/json")) {
                throw new Error("Dashboard refresh returned an unexpected response type.");
            }

            const data = await response.json();
            const currentClosedId = data.last_closed ? String(data.last_closed.id) : "";
            if (data.election || currentClosedId !== previousClosedId) {
                window.location.reload();
                return;
            }
            if (refreshStatus) {
                refreshStatus.textContent = `Updated ${new Date().toLocaleTimeString()}`;
            }
        } catch (error) {
            console.error("Unable to refresh empty admin dashboard state.", error);
            if (refreshStatus) {
                refreshStatus.textContent = "Live updates delayed.";
            }
        }
    };

    window.setInterval(refreshEmptyDashboard, 30_000);
}

if (document.body.dataset.themeScope === "admin") {
    const themeStorageKey = "ballot-bridge-admin-theme";
    const themeToggle = document.querySelector("[data-admin-theme-toggle]");
    const themeStatus = document.querySelector("[data-theme-status]");
    let theme = "light";

    try {
        const savedTheme = localStorage.getItem(themeStorageKey);
        if (savedTheme === "light" || savedTheme === "dark") {
            theme = savedTheme;
        }
    } catch (error) {
        console.error("Unable to read the saved admin theme preference.", error);
        if (themeStatus) {
            themeStatus.textContent = "The saved theme preference could not be loaded.";
        }
    }

    const adminDashboard = document.querySelector("[data-admin-dashboard]");
    if (adminDashboard && adminDashboard.dataset.electionId) {
        const electionId = adminDashboard.dataset.electionId;
        const dataUrl = adminDashboard.dataset.dataUrl;
        const countdown = adminDashboard.querySelector("[data-countdown]");
        const refreshStatus = adminDashboard.querySelector("[data-refresh-status]");
        const healthStatus = adminDashboard.querySelector("[data-health-status]");
        const voteChart = adminDashboard.querySelector("[data-vote-chart]");
        const voteLine = adminDashboard.querySelector("[data-vote-line]");
        const votePoints = adminDashboard.querySelector("[data-vote-points]");
        const chartEmpty = adminDashboard.querySelector("[data-chart-empty]");
        const auditPreview = adminDashboard.querySelector("[data-audit-preview]");

        const updateCountdown = () => {
            if (!(countdown instanceof HTMLTimeElement)) {
                return;
            }

            const closeAt = Number(countdown.dataset.closeAt) * 1000;
            if (!Number.isFinite(closeAt) || closeAt <= 0) {
                countdown.textContent = "Unavailable";
                return;
            }

            const remaining = Math.max(0, Math.floor((closeAt - Date.now()) / 1000));
            if (remaining === 0) {
                countdown.textContent = "Closing time reached";
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

        const renderVoteChart = (timeline) => {
            if (!(voteChart instanceof SVGSVGElement) || !(voteLine instanceof SVGPolylineElement)
                || !(votePoints instanceof SVGGElement) || !Array.isArray(timeline) || timeline.length === 0) {
                return;
            }

            const maximum = Math.max(1, ...timeline.map((point) => Number(point.votes) || 0));
            const coordinates = timeline.map((point, index) => {
                const x = 40 + (index * (420 / Math.max(1, timeline.length - 1)));
                const votes = Math.max(0, Number(point.votes) || 0);
                const y = 180 - ((votes / maximum) * 145);
                return { x, y };
            });

            voteLine.setAttribute("points", coordinates.map((point) => `${point.x},${point.y}`).join(" "));
            votePoints.replaceChildren(...coordinates.map((point) => {
                const circle = document.createElementNS("http://www.w3.org/2000/svg", "circle");
                circle.setAttribute("cx", String(point.x));
                circle.setAttribute("cy", String(point.y));
                circle.setAttribute("r", "3");
                return circle;
            }));

            const labels = adminDashboard.querySelectorAll(".turnout-chart__labels text");
            if (labels.length >= 3) {
                labels[0].textContent = timeline[0].label || "";
                labels[1].textContent = timeline[Math.floor(timeline.length / 2)].label || "";
                labels[2].textContent = timeline[timeline.length - 1].label || "";
            }
            if (chartEmpty) {
                chartEmpty.hidden = timeline.every((point) => Number(point.votes) === 0);
            }
        };

        const renderGroups = (groups) => {
            const total = Object.values(groups).reduce((sum, count) => sum + Number(count || 0), 0);
            adminDashboard.querySelectorAll("[data-voter-group]").forEach((group) => {
                const key = group.dataset.voterGroup;
                const count = Number(groups[key] || 0);
                const percent = total > 0 ? Math.round((count / total) * 100) : 0;
                const countLabel = group.querySelector("[data-group-count]");
                const percentLabel = group.querySelector("[data-group-percent]");
                const progress = group.querySelector("progress");

                if (countLabel) {
                    countLabel.textContent = String(count);
                }
                if (percentLabel) {
                    percentLabel.textContent = String(percent);
                }
                if (progress instanceof HTMLProgressElement) {
                    progress.value = percent;
                }
            });
        };

        const renderAuditPreview = (entries) => {
            if (!(auditPreview instanceof HTMLOListElement)) {
                return;
            }

            auditPreview.replaceChildren();
            if (!Array.isArray(entries) || entries.length === 0) {
                const empty = document.createElement("li");
                empty.className = "security-feed__empty";
                empty.textContent = "No recent administrator activity.";
                auditPreview.append(empty);
                return;
            }

            entries.forEach((entry) => {
                const item = document.createElement("li");
                const summary = document.createElement("strong");
                const actor = document.createElement("span");
                const time = document.createElement("time");
                summary.textContent = entry.summary || "Administrator action";
                actor.textContent = entry.actor_name || "Deleted administrator";
                time.textContent = entry.created_at || "";
                item.append(summary, actor, time);
                auditPreview.append(item);
            });
        };

        const updateMetric = (key, value) => {
            const metric = adminDashboard.querySelector(`[data-kpi="${key}"]`);
            if (metric) {
                metric.textContent = key === "turnout" ? Number(value).toFixed(1) : String(value);
            }
        };

        const updateHealthStatus = (status) => {
            if (healthStatus) {
                healthStatus.textContent = status;
                healthStatus.dataset.status = status.toLowerCase();
            }
        };

        const refreshDashboard = async () => {
            try {
                const response = await fetch(dataUrl, {
                    credentials: "same-origin",
                    cache: "no-store",
                    headers: { Accept: "application/json" },
                });
                if (response.redirected && new URL(response.url).pathname.endsWith("/auth/login.php")) {
                    window.location.assign(response.url);
                    return;
                }
                if (!response.ok) {
                    throw new Error(`Dashboard refresh returned ${response.status}.`);
                }
                if (!(response.headers.get("content-type") || "").includes("application/json")) {
                    throw new Error("Dashboard refresh returned an unexpected response type.");
                }

                const data = await response.json();
                if (!data.election || String(data.election.id) !== electionId) {
                    window.location.reload();
                    return;
                }
                if (data.election.status !== adminDashboard.querySelector("[data-election-status]")?.textContent.toLowerCase()) {
                    window.location.reload();
                    return;
                }

                const title = adminDashboard.querySelector("[data-election-title]");
                if (title) {
                    title.textContent = data.election.title;
                }
                if (countdown) {
                    countdown.dataset.closeAt = String(data.election.end_timestamp);
                    countdown.setAttribute("datetime", data.election.end_datetime);
                }
                Object.entries(data.kpis).forEach(([key, value]) => updateMetric(key, value));
                renderGroups(data.voter_groups);
                renderVoteChart(data.vote_timeline);
                renderAuditPreview(data.audit_logs);
                if (refreshStatus) {
                    refreshStatus.textContent = `Updated ${new Date().toLocaleTimeString()}`;
                }
                updateHealthStatus("Operational");
            } catch (error) {
                console.error("Unable to refresh admin dashboard data.", error);
                if (refreshStatus) {
                    refreshStatus.textContent = "Live updates delayed.";
                }
                updateHealthStatus("Degraded");
            }
        };

        updateCountdown();
        window.setInterval(updateCountdown, 1000);
        window.setInterval(refreshDashboard, 30_000);
    }

    document.body.dataset.theme = theme;
    if (themeToggle instanceof HTMLInputElement) {
        themeToggle.checked = theme === "dark";
        themeToggle.addEventListener("change", () => {
            theme = themeToggle.checked ? "dark" : "light";
            document.body.dataset.theme = theme;

            try {
                localStorage.setItem(themeStorageKey, theme);
                if (themeStatus) {
                    themeStatus.textContent = `${theme === "dark" ? "Dark" : "Light"} theme is active.`;
                }
            } catch (error) {
                console.error("Unable to save the admin theme preference.", error);
                if (themeStatus) {
                    themeStatus.textContent = `Theme changed for this page, but the preference could not be saved.`;
                }
            }
        });
    }
}