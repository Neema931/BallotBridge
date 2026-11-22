(() => {
    const settingsForm = document.querySelector("#election-settings-form");
    const saveBar = document.querySelector("[data-save-bar]");

    if (settingsForm instanceof HTMLFormElement && saveBar instanceof HTMLElement) {
        const fields = [...settingsForm.elements].filter((field) =>
            field instanceof HTMLInputElement
            || field instanceof HTMLSelectElement
            || field instanceof HTMLTextAreaElement
        ).filter((field) => field.hasAttribute("data-setting-field"));

        const initialValues = new Map(fields.map((field) => [
            field,
            field instanceof HTMLInputElement && (field.type === "checkbox" || field.type === "radio")
                ? field.checked
                : field.value,
        ]));

        const updateSaveBar = () => {
            const hasChanges = fields.some((field) => {
                const initialValue = initialValues.get(field);
                const currentValue = field instanceof HTMLInputElement
                    && (field.type === "checkbox" || field.type === "radio")
                    ? field.checked
                    : field.value;
                return currentValue !== initialValue;
            });
            saveBar.hidden = !hasChanges;
        };

        fields.forEach((field) => {
            field.addEventListener("input", updateSaveBar);
            field.addEventListener("change", updateSaveBar);
        });
        updateSaveBar();
    }

    const confirmDialog = document.querySelector("[data-settings-confirm-dialog]");
    if (!(confirmDialog instanceof HTMLDialogElement)) {
        return;
    }

    const title = confirmDialog.querySelector("[data-confirm-heading]");
    const message = confirmDialog.querySelector("[data-confirm-message]");
    const inputWrap = confirmDialog.querySelector("[data-confirm-input-wrap]");
    const inputLabel = confirmDialog.querySelector("[data-confirm-prompt]");
    const input = confirmDialog.querySelector("[data-confirm-input]");
    const acceptButton = confirmDialog.querySelector("[data-confirm-accept]");
    let pendingForm = null;
    let pendingButton = null;
    let requiredValue = "";

    const setAcceptState = () => {
        acceptButton.disabled = requiredValue !== "" && input.value !== requiredValue;
    };

    document.querySelectorAll("form[data-confirm-form]").forEach((form) => {
        form.addEventListener("submit", (event) => {
            if (form.dataset.confirmed === "true") {
                return;
            }
            event.preventDefault();
            pendingForm = form;
            pendingButton = event.submitter instanceof HTMLElement ? event.submitter : null;
            requiredValue = form.dataset.confirmValue || "";
            title.textContent = form.dataset.confirmTitle || "Confirm action";
            message.textContent = form.dataset.confirmMessage || "This action cannot be undone.";
            inputWrap.hidden = requiredValue === "";
            inputLabel.textContent = form.dataset.confirmPrompt || "Type the confirmation phrase.";
            input.value = "";
            setAcceptState();
            confirmDialog.showModal();
            if (requiredValue !== "") {
                input.focus();
            } else {
                acceptButton.focus();
            }
        });
    });

    input.addEventListener("input", setAcceptState);

    confirmDialog.querySelectorAll("[data-confirm-cancel]").forEach((button) => {
        button.addEventListener("click", () => confirmDialog.close());
    });

    confirmDialog.addEventListener("cancel", () => {
        pendingForm = null;
    });

    confirmDialog.addEventListener("close", () => {
        const trigger = pendingButton;
        pendingForm = null;
        pendingButton = null;
        if (trigger instanceof HTMLElement) {
            trigger.focus();
        }
    });

    confirmDialog.addEventListener("click", (event) => {
        if (event.target === confirmDialog) {
            confirmDialog.close();
        }
    });

    acceptButton.addEventListener("click", () => {
        if (!(pendingForm instanceof HTMLFormElement) || acceptButton.disabled) {
            return;
        }

        const confirmation = pendingForm.elements.namedItem("confirmation");
        if (confirmation instanceof HTMLInputElement) {
            confirmation.value = "yes";
        }
        const confirmationPhrase = pendingForm.elements.namedItem("confirmation_phrase");
        if (confirmationPhrase instanceof HTMLInputElement) {
            confirmationPhrase.value = requiredValue === "" ? "yes" : input.value;
        }

        const form = pendingForm;
        form.dataset.confirmed = "true";
        confirmDialog.close();
        form.requestSubmit();
    });
})();
