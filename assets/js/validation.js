document.addEventListener("DOMContentLoaded", () => {
    document.querySelectorAll("form[data-validate]").forEach((form) => {
        const password = form.querySelector("[data-password]");
        const confirmation = form.querySelector("[data-password-confirm]");
        const mismatchMessage = confirmation
            ? document.getElementById(confirmation.getAttribute("aria-describedby"))
            : null;

        const checkPasswordMatch = () => {
            if (!password || !confirmation) {
                return true;
            }

            const matches = password.value === confirmation.value;
            const valid = confirmation.value === "" || matches;
            confirmation.setCustomValidity(valid ? "" : "Passwords must match.");
            if (valid) {
                confirmation.removeAttribute("aria-invalid");
            } else {
                confirmation.setAttribute("aria-invalid", "true");
            }

            if (mismatchMessage) {
                mismatchMessage.hidden = valid;
            }

            return valid;
        };

        if (password && confirmation) {
            password.addEventListener("input", checkPasswordMatch);
            confirmation.addEventListener("input", checkPasswordMatch);
        }

        form.addEventListener("submit", (event) => {
            checkPasswordMatch();

            if (!form.checkValidity()) {
                event.preventDefault();
                form.reportValidity();
            }
        });
    });
});