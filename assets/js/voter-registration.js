document.addEventListener("DOMContentLoaded", () => {
    const password = document.querySelector("[data-password-strength-input]");
    const meter = document.querySelector("[data-password-strength]");
    const strengthOutput = document.querySelector("[data-password-strength-output]");

    if (
        !(password instanceof HTMLInputElement)
        || !(meter instanceof HTMLMeterElement)
        || !(strengthOutput instanceof HTMLOutputElement)
    ) {
        return;
    }

    const updateStrength = () => {
        const value = password.value;
        if (value === "") {
            meter.value = 0;
            strengthOutput.textContent = "Not entered";
            return;
        }

        let score = 0;
        if (value.length >= 8) score += 1;
        if (value.length >= 12) score += 1;
        if (/[a-z]/.test(value) && /[A-Z]/.test(value)) score += 1;
        if (/[0-9]/.test(value) || /[^A-Za-z0-9]/.test(value)) score += 1;

        meter.value = score;
        strengthOutput.textContent = ["Weak", "Weak", "Fair", "Good", "Strong"][score];
    };

    password.addEventListener("input", updateStrength);
    updateStrength();
});
