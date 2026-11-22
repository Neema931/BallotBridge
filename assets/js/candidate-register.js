(() => {
    const form = document.querySelector('[data-candidate-register]');
    if (!form) {
        return;
    }

    const manifesto = form.querySelector('[data-registration-manifesto]');
    const manifestoCounter = form.querySelector('[data-registration-manifesto-counter]');
    if (manifesto instanceof HTMLTextAreaElement && manifestoCounter) {
        const updateManifestoCount = () => {
            const count = Array.from(manifesto.value).length;
            manifestoCounter.textContent = `${count.toLocaleString()} / 65,535 characters`;
        };
        manifesto.addEventListener('input', updateManifestoCount);
        updateManifestoCount();
    }

    const photoInput = form.querySelector('[data-registration-photo]');
    const photoPreview = form.querySelector('[data-registration-photo-preview]');
    const photoPlaceholder = form.querySelector('[data-registration-photo-placeholder]');
    const photoError = form.querySelector('[data-registration-photo-error]');
    let previewUrl = null;

    if (
        photoInput instanceof HTMLInputElement
        && photoPreview instanceof HTMLImageElement
        && photoPlaceholder instanceof HTMLElement
        && photoError
    ) {
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
                photoPreview.removeAttribute('src');
                photoPreview.hidden = true;
                photoPlaceholder.hidden = false;
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
            photoPlaceholder.hidden = true;
        });
    }
})();
