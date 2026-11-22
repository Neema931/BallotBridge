(() => {
    const form = document.querySelector('[data-myprofile-form]');
    if (!form) {
        return;
    }

    const manifesto = form.querySelector('[data-manifesto-input]');
    const manifestoCounter = form.querySelector('[data-manifesto-counter]');
    if (manifesto instanceof HTMLTextAreaElement && manifestoCounter) {
        const updateManifestoCount = () => {
            const count = Array.from(manifesto.value).length;
            manifestoCounter.textContent = `${count.toLocaleString()} / 65,535 characters`;
        };
        manifesto.addEventListener('input', updateManifestoCount);
        updateManifestoCount();
    }

    const photoInput = form.querySelector('[data-headshot-input]');
    const photoPreview = form.querySelector('[data-headshot-preview]');
    const photoPlaceholder = form.querySelector('[data-headshot-placeholder]');
    const photoError = form.querySelector('[data-headshot-error]');
    let previewUrl = null;

    if (photoInput instanceof HTMLInputElement && photoPreview instanceof HTMLImageElement && photoError) {
        const originalPreviewSource = photoPreview.getAttribute('src');
        const originalPreviewHidden = photoPreview.hidden;
        const originalPlaceholderHidden = photoPlaceholder ? photoPlaceholder.hidden : true;

        photoInput.addEventListener('change', () => {
            const file = photoInput.files && photoInput.files[0];
            photoError.hidden = true;
            photoError.textContent = '';
            if (!file) {
                return;
            }

            if (!['image/jpeg', 'image/png'].includes(file.type) || file.size < 1 || file.size > 2 * 1024 * 1024) {
                photoInput.value = '';
                if (previewUrl) {
                    URL.revokeObjectURL(previewUrl);
                    previewUrl = null;
                }
                if (originalPreviewSource === null) {
                    photoPreview.removeAttribute('src');
                } else {
                    photoPreview.src = originalPreviewSource;
                }
                photoPreview.hidden = originalPreviewHidden;
                if (photoPlaceholder) {
                    photoPlaceholder.hidden = originalPlaceholderHidden;
                }
                photoError.textContent = 'Choose a JPG or PNG headshot no larger than 2 MB.';
                photoError.hidden = false;
                photoInput.focus();
                return;
            }

            if (previewUrl) {
                URL.revokeObjectURL(previewUrl);
            }
            previewUrl = URL.createObjectURL(file);
            photoPreview.src = previewUrl;
            photoPreview.hidden = false;
            if (photoPlaceholder) {
                photoPlaceholder.hidden = true;
            }
        });
    }

    const email = form.querySelector('#candidate-email');
    const currentPassword = form.querySelector('[data-current-password]');
    const newPassword = form.querySelector('[data-new-password]');
    const confirmPassword = form.querySelector('[data-confirm-password]');
    const passwordMatchError = form.querySelector('[data-password-match-error]');

    if (
        email instanceof HTMLInputElement
        && currentPassword instanceof HTMLInputElement
        && newPassword instanceof HTMLInputElement
        && confirmPassword instanceof HTMLInputElement
    ) {
        const updateCredentialRequirements = () => {
            const emailChanged = email.value.trim().toLowerCase() !== email.dataset.originalEmail;
            const passwordProvided = newPassword.value !== '';
            currentPassword.required = emailChanged || passwordProvided;
            confirmPassword.required = passwordProvided;

            const passwordsMatch = confirmPassword.value === ''
                || confirmPassword.value === newPassword.value;
            confirmPassword.setCustomValidity(passwordsMatch ? '' : 'New passwords must match.');
            if (passwordMatchError) {
                passwordMatchError.hidden = passwordsMatch;
            }
        };

        email.addEventListener('input', updateCredentialRequirements);
        newPassword.addEventListener('input', updateCredentialRequirements);
        confirmPassword.addEventListener('input', updateCredentialRequirements);
        updateCredentialRequirements();
    }
})();
