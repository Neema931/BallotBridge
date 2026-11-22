document.addEventListener("DOMContentLoaded", () => {
    const countdown = document.querySelector("[data-poll-countdown]");
    if (countdown) {
        const targetSeconds = Number(countdown.dataset.countdownAt);
        if (Number.isFinite(targetSeconds) && targetSeconds > 0) {
            const renderCountdown = () => {
                const remainingSeconds = Math.max(0, targetSeconds - Math.floor(Date.now() / 1000));
                const days = Math.floor(remainingSeconds / 86400);
                const hours = Math.floor((remainingSeconds % 86400) / 3600);
                const minutes = Math.floor((remainingSeconds % 3600) / 60);
                const seconds = remainingSeconds % 60;
                const parts = [];

                if (days > 0) {
                    parts.push(`${days}d`);
                }
                parts.push(`${String(hours).padStart(2, "0")}h`);
                parts.push(`${String(minutes).padStart(2, "0")}m`);
                parts.push(`${String(seconds).padStart(2, "0")}s`);
                countdown.textContent = parts.join(" ");
            };

            renderCountdown();
            window.setInterval(renderCountdown, 1000);
        }
    }

    document.addEventListener("click", (event) => {
        if (!(event.target instanceof Element)) {
            return;
        }

        const trigger = event.target.closest("[data-open-manifesto]");
        if (trigger instanceof HTMLButtonElement) {
            const dialog = document.getElementById(trigger.dataset.openManifesto);
            if (dialog instanceof HTMLDialogElement) {
                dialog.showModal();
            }
            return;
        }

        const closeButton = event.target.closest("[data-close-manifesto]");
        if (closeButton instanceof HTMLButtonElement) {
            const dialog = closeButton.closest("dialog");
            if (dialog instanceof HTMLDialogElement) {
                dialog.close();
            }
            return;
        }

        if (event.target instanceof HTMLDialogElement && event.target.open) {
            event.target.close();
        }
    });

    const form = document.querySelector("[data-ballot-form]");
    if (!(form instanceof HTMLFormElement)) {
        return;
    }

    const reviewButton = form.querySelector("[data-review-ballot]");
    const modal = form.querySelector("[data-ballot-review]");
    const dialog = modal.querySelector('[role="dialog"]');
    const summary = modal.querySelector("[data-ballot-summary]");
    const confirmButton = modal.querySelector("[data-confirm-ballot]");
    const closeButton = modal.querySelector("[data-close-review]");
    const votingSystem = form.dataset.votingSystem;
    const positions = Array.from(form.querySelectorAll("[data-ballot-position]"));
    let returnFocus = null;

    const clearPositionError = (position) => {
        const error = position.querySelector("[data-position-error]");
        if (error) {
            error.hidden = true;
            error.textContent = "";
        }
    };

    const reportPositionError = (position, message) => {
        const error = position.querySelector("[data-position-error]");
        if (error) {
            error.textContent = message;
            error.hidden = false;
        }
        position.scrollIntoView({ behavior: "smooth", block: "center" });
        const firstControl = position.querySelector("input, select");
        if (firstControl) {
            firstControl.focus();
        }
        return false;
    };

    const getPositionSelection = (position) => {
        const abstain = position.querySelector("[data-abstain]");
        const abstained = abstain instanceof HTMLInputElement && abstain.checked;

        if (votingSystem === "fptp") {
            const selected = position.querySelector('input[type="radio"]:checked');
            if (abstained && selected && selected.value !== "abstain") {
                return { valid: false, error: "Choose a candidate or abstain, not both." };
            }
            if (!selected) {
                return { valid: false, error: "Choose one candidate or an allowed abstention." };
            }
            if (selected.value === "abstain") {
                return { valid: true, abstained: true, names: [] };
            }
            const name = selected.closest("[data-candidate-option]")?.querySelector(".candidate-option__name");
            return { valid: true, abstained: false, names: name ? [name.textContent.trim()] : [] };
        }

        if (votingSystem === "multiple_selection") {
            const checked = Array.from(position.querySelectorAll('input[type="checkbox"][name^="votes"]:checked'));
            if (abstained && checked.length > 0) {
                return { valid: false, error: "Choose candidates or abstain, not both." };
            }
            if (abstained) {
                return { valid: true, abstained: true, names: [] };
            }
            const limit = Number(position.dataset.maxVotes);
            if (checked.length === 0) {
                return { valid: false, error: "Choose at least one candidate or an allowed abstention." };
            }
            if (!Number.isInteger(limit) || checked.length > limit) {
                return { valid: false, error: `Choose no more than ${limit} candidate(s).` };
            }
            const names = checked.map((input) =>
                input.closest("[data-candidate-option]")?.querySelector(".candidate-option__name")?.textContent.trim()
            ).filter(Boolean);
            return { valid: true, abstained: false, names };
        }

        const ranked = Array.from(position.querySelectorAll("[data-rank-choice]"))
            .map((select) => ({
                rank: Number(select.value),
                name: select.closest(".ranked-candidate")?.querySelector(".candidate-option__name")?.textContent.trim() || "",
            }))
            .filter((entry) => entry.rank > 0)
            .sort((left, right) => left.rank - right.rank);
        if (abstained && ranked.length > 0) {
            return { valid: false, error: "Choose candidate rankings or abstain, not both." };
        }
        if (abstained) {
            return { valid: true, abstained: true, names: [] };
        }
        const ranks = new Set(ranked.map((entry) => entry.rank));
        if (ranks.size !== ranked.length) {
            return { valid: false, error: "Each rank can be assigned to only one candidate." };
        }
        if (ranked.length === 0) {
            return { valid: false, error: "Rank at least one candidate or an allowed abstention." };
        }
        return {
            valid: true,
            abstained: false,
            names: ranked.map((entry) => `${entry.rank}. ${entry.name}`),
        };
    };

    const validateBallot = () => {
        for (const position of positions) {
            clearPositionError(position);
            if (!position.querySelector("[data-ballot-choice]")) {
                continue;
            }
            const result = getPositionSelection(position);
            if (!result.valid) {
                return reportPositionError(position, result.error);
            }
        }
        return true;
    };

    const openReview = () => {
        if (!form.reportValidity() || !validateBallot()) {
            return;
        }

        summary.replaceChildren();
        for (const position of positions) {
            if (!position.querySelector("[data-ballot-choice]")) {
                if (position.dataset.submittedPosition === "true") {
                    const item = document.createElement("li");
                    const title = position.querySelector("h2")?.textContent.trim() || "Position";
                    item.textContent = `${title} — Already submitted`;
                    summary.appendChild(item);
                }
                continue;
            }
            const selection = getPositionSelection(position);
            const item = document.createElement("li");
            const title = position.querySelector("h2")?.textContent.trim() || "Position";
            item.textContent = selection.abstained
                ? `${title} — Abstain`
                : `${title} — ${selection.names.join(", ")}`;
            summary.appendChild(item);
        }

        returnFocus = document.activeElement;
        modal.classList.add("is-open");
        modal.setAttribute("aria-hidden", "false");
        confirmButton.focus();
    };

    const closeReview = () => {
        modal.classList.remove("is-open");
        modal.setAttribute("aria-hidden", "true");
        if (returnFocus instanceof HTMLElement) {
            returnFocus.focus();
        }
    };

    reviewButton.addEventListener("click", openReview);
    closeButton.addEventListener("click", closeReview);
    modal.addEventListener("click", (event) => {
        if (event.target === modal) {
            closeReview();
        }
    });

    form.addEventListener("change", (event) => {
        const position = event.target.closest("[data-ballot-position]");
        if (position) {
            clearPositionError(position);
        }
    });

    form.addEventListener("submit", (event) => {
        if (form.dataset.confirmed !== "true") {
            event.preventDefault();
            openReview();
            return;
        }
        form.dataset.confirmed = "false";
        const indicator = form.querySelector("[data-loading-indicator]");
        if (indicator) {
            indicator.hidden = false;
        }
        confirmButton.disabled = true;
        reviewButton.disabled = true;
    });

    confirmButton.addEventListener("click", () => {
        form.dataset.confirmed = "true";
    });

    dialog.addEventListener("keydown", (event) => {
        if (event.key === "Escape") {
            event.preventDefault();
            closeReview();
            return;
        }

        if (event.key === "Tab") {
            const focusable = Array.from(dialog.querySelectorAll(
                'button:not([disabled]), a[href], input:not([disabled]), [tabindex]:not([tabindex="-1"])'
            ));
            if (focusable.length === 0) {
                return;
            }

            const first = focusable[0];
            const last = focusable[focusable.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        }
    });
});
