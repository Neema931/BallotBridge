(() => {
    const positionDialog = document.querySelector('#position-dialog');
    const entryDialog = document.querySelector('#entry-dialog');
    const optionDialog = document.querySelector('#option-dialog');
    const previewDialog = document.querySelector('#preview-dialog');

    if (!positionDialog || !entryDialog || !optionDialog || !previewDialog) {
        return;
    }

    const show = (dialog) => {
        if (!dialog.open) {
            dialog.showModal();
        }
    };

    const setValue = (form, name, value) => {
        const field = form.elements.namedItem(name);
        if (field) {
            field.value = value;
        }
    };

    document.querySelectorAll('[data-open-position]').forEach((button) => {
        button.addEventListener('click', () => {
            const form = positionDialog.querySelector('form');
            form.reset();
            setValue(form, 'position_id', '');
            positionDialog.querySelector('#position-dialog-title').textContent = 'Add a contest';
            show(positionDialog);
        });
    });

    document.querySelectorAll('[data-edit-position]').forEach((button) => {
        button.addEventListener('click', () => {
            const form = positionDialog.querySelector('form');
            setValue(form, 'position_id', button.dataset.positionId || '');
            setValue(form, 'title', button.dataset.title || '');
            setValue(form, 'contest_type', button.dataset.type || 'candidate');
            setValue(form, 'max_votes', button.dataset.maxVotes || '1');
            positionDialog.querySelector('#position-dialog-title').textContent = 'Edit contest';
            show(positionDialog);
        });
    });

    const openCandidateForm = (button, editing) => {
        const form = entryDialog.querySelector('form');
        form.reset();
        setValue(form, 'position_id', button.dataset.positionId || '');
        setValue(form, 'candidate_id', editing ? button.dataset.entryId || '' : '');
        setValue(form, 'action', 'save_candidate');
        setValue(form, 'affiliation', editing ? button.dataset.affiliation || '' : '');
        setValue(form, 'manifesto', editing ? button.dataset.manifesto || '' : '');
        const accountField = entryDialog.querySelector('[data-account-field]');
        const accountSelect = form.elements.namedItem('user_id');
        accountField.hidden = editing;
        accountSelect.required = !editing;
        accountSelect.disabled = editing;
        entryDialog.querySelector('#entry-dialog-title').textContent = editing ? 'Edit candidate profile' : 'Add candidate';
        entryDialog.querySelector('[data-entry-subtitle]').textContent = editing
            ? button.dataset.name || 'Registered candidate account'
            : `Add candidate to ${button.dataset.positionTitle || 'position'}`;
        show(entryDialog);
    };

    document.querySelectorAll('[data-add-candidate]').forEach((button) => {
        button.addEventListener('click', () => openCandidateForm(button, false));
    });
    document.querySelectorAll('[data-edit-candidate]').forEach((button) => {
        button.addEventListener('click', () => openCandidateForm(button, true));
    });

    const openOptionForm = (button, editing) => {
        const form = optionDialog.querySelector('form');
        form.reset();
        setValue(form, 'position_id', button.dataset.positionId || '');
        setValue(form, 'candidate_id', editing ? button.dataset.entryId || '' : '');
        setValue(form, 'display_name', editing ? button.dataset.name || '' : '');
        setValue(form, 'manifesto', editing ? button.dataset.manifesto || '' : '');
        optionDialog.querySelector('#option-dialog-title').textContent = editing ? 'Edit option' : 'Add option';
        show(optionDialog);
    };

    document.querySelectorAll('[data-add-option]').forEach((button) => {
        button.addEventListener('click', () => openOptionForm(button, false));
    });
    document.querySelectorAll('[data-edit-option]').forEach((button) => {
        button.addEventListener('click', () => openOptionForm(button, true));
    });

    document.querySelectorAll('[data-open-preview]').forEach((button) => {
        button.addEventListener('click', () => show(previewDialog));
    });
    document.querySelectorAll('[data-close-dialog]').forEach((button) => {
        button.addEventListener('click', () => button.closest('dialog').close());
    });
    document.querySelectorAll('dialog.ballot-builder__dialog').forEach((dialog) => {
        dialog.addEventListener('click', (event) => {
            if (event.target === dialog) {
                dialog.close();
            }
        });
    });
})();
