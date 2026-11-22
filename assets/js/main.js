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
            showIcon.hidden = showPassword;
        }
        if (hideIcon) {
            hideIcon.hidden = !showPassword;
        }
    });
});

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