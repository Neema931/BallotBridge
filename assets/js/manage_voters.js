document.querySelectorAll("[data-open-dialog]").forEach((button) => {
    button.addEventListener("click", () => {
        const dialogId = button.getAttribute("data-open-dialog");
        const dialog = dialogId ? document.getElementById(dialogId) : null;
        if (dialog instanceof HTMLDialogElement) {
            dialog.showModal();
        }
    });
});

document.querySelectorAll("[data-close-dialog]").forEach((button) => {
    button.addEventListener("click", () => {
        const dialog = button.closest("dialog");
        if (dialog instanceof HTMLDialogElement) {
            dialog.close();
        }
    });
});

document.querySelectorAll("[data-edit-voter]").forEach((button) => {
    button.addEventListener("click", () => {
        const dialog = document.getElementById("voter-edit-dialog");
        const form = dialog?.querySelector("[data-edit-voter-form]");
        if (!(dialog instanceof HTMLDialogElement) || !(form instanceof HTMLFormElement)) {
            return;
        }

        const values = {
            user_id: button.dataset.userId || "",
            full_name: button.dataset.fullName || "",
            email: button.dataset.email || "",
            reg_number: button.dataset.regNumber || "",
            phone: button.dataset.phone || "",
        };
        Object.entries(values).forEach(([name, value]) => {
            const field = form.elements.namedItem(name);
            if (field instanceof HTMLInputElement) {
                field.value = value;
            }
        });
        dialog.showModal();
    });
});

document.querySelectorAll("[data-reject-voter]").forEach((button) => {
    button.addEventListener("click", () => {
        const dialog = document.getElementById("voter-reject-dialog");
        const form = dialog?.querySelector("[data-reject-form]");
        if (!(dialog instanceof HTMLDialogElement) || !(form instanceof HTMLFormElement)) {
            return;
        }
        const userId = form.elements.namedItem("user_id");
        const applicant = dialog.querySelector("[data-reject-applicant]");
        if (userId instanceof HTMLInputElement) {
            userId.value = button.dataset.rejectVoter || "";
        }
        if (applicant) {
            applicant.textContent = button.dataset.rejectName || "this applicant";
        }
        dialog.showModal();
    });
});

document.querySelectorAll(".voter-dialog").forEach((dialog) => {
    dialog.addEventListener("click", (event) => {
        if (event.target === dialog) {
            dialog.close();
        }
    });
});

document.querySelectorAll("[data-confirm-submit]").forEach((form) => {
    form.addEventListener("submit", (event) => {
        if (!window.confirm(form.dataset.confirmSubmit || "Continue with this action?")) {
            event.preventDefault();
        }
    });
});
