document.addEventListener("DOMContentLoaded", () => {
    const form = document.querySelector("[data-election-wizard]");
    if (!(form instanceof HTMLFormElement)) {
        return;
    }

    const steps = Array.from(form.querySelectorAll("[data-wizard-step]"));
    const navigationButtons = Array.from(document.querySelectorAll("[data-wizard-nav]"));
    const errorMessage = form.querySelector("[data-wizard-error]");
    const editor = form.querySelector("[data-rich-editor]");
    const richContent = form.querySelector("[data-rich-content]");
    let currentStep = 0;

    const updateRichContent = () => {
        if (editor && richContent instanceof HTMLTextAreaElement) {
            richContent.value = editor.innerHTML;
        }
    };

    const showStep = (index) => {
        currentStep = index;
        steps.forEach((step, stepIndex) => {
            step.hidden = stepIndex !== index;
        });
        navigationButtons.forEach((button, buttonIndex) => {
            if (buttonIndex === index) {
                button.setAttribute("aria-current", "step");
            } else {
                button.removeAttribute("aria-current");
            }
            button.classList.toggle("is-complete", buttonIndex < index);
        });
        if (errorMessage) {
            errorMessage.hidden = true;
        }
    };

    const validateStep = (index) => {
        const step = steps[index];
        const requiredControls = Array.from(step.querySelectorAll("input[required], select[required], textarea[required]"));
        for (const control of requiredControls) {
            if (!control.checkValidity()) {
                showStep(index);
                control.reportValidity();
                if (errorMessage) {
                    errorMessage.hidden = false;
                }
                return false;
            }
        }

        if (index === 1) {
            const start = form.querySelector('[name="start_datetime"]');
            const end = form.querySelector('[name="end_datetime"]');
            if (start instanceof HTMLInputElement && end instanceof HTMLInputElement
                && start.value !== "" && end.value !== "" && start.value >= end.value) {
                end.setCustomValidity("Poll closing must be later than poll opening.");
                end.reportValidity();
                end.addEventListener("input", () => end.setCustomValidity(""), { once: true });
                showStep(index);
                if (errorMessage) {
                    errorMessage.hidden = false;
                }
                return false;
            }
        }

        return true;
    };

    form.querySelectorAll("[data-wizard-next]").forEach((button) => {
        button.addEventListener("click", () => {
            if (validateStep(currentStep) && currentStep < steps.length - 1) {
                showStep(currentStep + 1);
                steps[currentStep].querySelector("h3")?.focus();
            }
        });
    });

    form.querySelectorAll("[data-wizard-back]").forEach((button) => {
        button.addEventListener("click", () => {
            showStep(Math.max(0, currentStep - 1));
            steps[currentStep].querySelector("h3")?.focus();
        });
    });

    navigationButtons.forEach((button) => {
        button.addEventListener("click", () => {
            const requestedStep = Number(button.dataset.wizardNav);
            if (!Number.isInteger(requestedStep) || requestedStep < 0 || requestedStep >= steps.length) {
                return;
            }
            if (requestedStep <= currentStep) {
                showStep(requestedStep);
                return;
            }
            for (let index = currentStep; index < requestedStep; index += 1) {
                if (!validateStep(index)) {
                    return;
                }
            }
            showStep(requestedStep);
        });
    });

    form.querySelectorAll("[data-rich-command]").forEach((button) => {
        button.addEventListener("click", () => {
            const command = button.dataset.richCommand;
            if (!command || !editor) {
                return;
            }
            editor.focus();
            document.execCommand(command, false);
            updateRichContent();
        });
    });

    if (editor) {
        editor.addEventListener("input", updateRichContent);
        editor.addEventListener("blur", updateRichContent);
    }

    form.addEventListener("submit", (event) => {
        updateRichContent();
        for (let index = 0; index < steps.length; index += 1) {
            if (!validateStep(index)) {
                event.preventDefault();
                return;
            }
        }
    });

    showStep(0);
});
