document.addEventListener("DOMContentLoaded", () => {
    document.querySelectorAll("[data-copy-receipt]").forEach((button) => {
        button.addEventListener("click", async () => {
            const card = button.closest("[data-receipt-card]");
            const code = card?.querySelector("[data-receipt-code]");
            const status = card?.querySelector("[data-copy-status]");
            if (!code || !status) {
                console.error("Receipt copy controls are missing their code or status elements.");
                return;
            }

            try {
                if (!navigator.clipboard?.writeText) {
                    throw new Error("Clipboard access is unavailable.");
                }
                await navigator.clipboard.writeText(code.textContent.trim());
                status.textContent = "Receipt code copied.";
                button.textContent = "Copied";
                window.setTimeout(() => {
                    button.textContent = "Copy Code";
                }, 1800);
            } catch (error) {
                status.textContent = "Copy failed. Select the receipt code and copy it manually.";
                console.error("Unable to copy the ballot receipt code.", error);
            }
        });
    });

    document.querySelectorAll("[data-print-receipt]").forEach((button) => {
        button.addEventListener("click", () => {
            const receiptId = button.dataset.receiptTarget;
            const receipt = receiptId ? document.getElementById(receiptId) : null;
            if (!(receipt instanceof HTMLElement)) {
                console.error("Unable to find the receipt selected for printing.");
                return;
            }

            receipt.classList.add("is-printing");
            document.body.classList.add("printing-receipt");
            const clearPrintState = () => {
                receipt.classList.remove("is-printing");
                document.body.classList.remove("printing-receipt");
            };
            window.addEventListener("afterprint", clearPrintState, { once: true });
            window.print();
        });
    });
});
