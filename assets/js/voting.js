document.addEventListener("DOMContentLoaded", () => {
    const form = document.querySelector("[data-ballot-form]");
    if (!form) {
        return;
    }

    const reviewButton = form.querySelector("[data-review-ballot]");
    const modal = form.querySelector("[data-ballot-review]");
    const dialog = modal.querySelector('[role="dialog"]');
    const summary = modal.querySelector("[data-ballot-summary]");
    const confirmButton = modal.querySelector("[data-confirm-ballot]");
    const closeButton = modal.querySelector("[data-close-review]");
    let returnFocus = null;

    const openReview = () => {
        if (!form.reportValidity()) {
            return;
        }

        const selected = form.querySelectorAll('input[type="radio"]:checked');
        if (selected.length === 0) {
            return;
        }

        summary.replaceChildren();
        selected.forEach((radio) => {
            const position = radio.closest(".ballot-position");
            const candidate = radio.closest("[data-candidate-option]");
            const name = candidate.querySelector(".candidate-option__name");
            const item = document.createElement("li");
            item.textContent = `${position.querySelector("h2").textContent.trim()} - ${name.textContent.trim()}`;
            summary.appendChild(item);
        });

        returnFocus = document.activeElement;
        modal.classList.add("is-open");
        modal.setAttribute("aria-hidden", "false");
        confirmButton.focus();
    };

    const closeReview = () => {
        modal.classList.remove("is-open");
        modal.setAttribute("aria-hidden", "true");
        if (returnFocus) {
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

    form.addEventListener("submit", (event) => {
        if (form.dataset.confirmed !== "true") {
            event.preventDefault();
            openReview();
            return;
        }
        form.dataset.confirmed = "false";
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
