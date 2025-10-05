/**
 * Disapprove order workflow: forces staff to pick a reusable disapproval reason and
 * keeps the reason catalogue in sync without reloading the page.
 */
(function () {
    if (typeof window === 'undefined') {
        return;
    }

    const data = window.dgzPosData || {};
    const declineModal = document.getElementById('declineOrderModal');
    const manageModal = document.getElementById('manageDeclineReasonsModal');

    if (!declineModal || !manageModal) {
        return;
    }

    const reasonSelect = document.getElementById('declineReasonSelect');
    const reasonNoteField = document.getElementById('declineReasonNote');
    const declineError = document.getElementById('declineModalError');
    const manageError = document.getElementById('manageDeclineError');
    const manageList = document.getElementById('declineReasonsList');
    const addReasonForm = document.getElementById('addDeclineReasonForm');
    const newReasonInput = document.getElementById('newDeclineReasonInput');
    const confirmDeclineBtn = document.getElementById('confirmDeclineOrder');
    const cancelDeclineBtn = document.getElementById('cancelDeclineOrder');
    const closeDeclineBtn = document.getElementById('closeDeclineOrderModal');
    const openManageBtn = document.getElementById('openManageDeclineReasons');
    const closeManageBtn = document.getElementById('closeManageDeclineReasons');

    const state = {
        reasons: Array.isArray(data.declineReasons) ? data.declineReasons.slice() : [],
        currentForm: null,
        currentRow: null,
    };

    const apiUrl = 'declineReasonsApi.php';

    function sortReasons(reasons) {
        return reasons.slice().sort((a, b) => {
            const activeDiff = (Number(b.is_active ?? 1) - Number(a.is_active ?? 1));
            if (activeDiff !== 0) {
                return activeDiff;
            }

            const labelA = (a.label || '').toLowerCase();
            const labelB = (b.label || '').toLowerCase();
            if (labelA < labelB) { return -1; }
            if (labelA > labelB) { return 1; }
            return 0;
        });
    }

    function getActiveReasons() {
        return state.reasons.filter((reason) => Number(reason.is_active ?? 1) !== 0);
    }

    function setReasons(reasons) {
        state.reasons = sortReasons(reasons);
        renderReasonSelect();
        renderManageReasonsList();
    }

    function renderReasonSelect() {
        if (!reasonSelect) {
            return;
        }

        const activeReasons = getActiveReasons();
        reasonSelect.innerHTML = '';

        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.disabled = true;
        placeholder.selected = true;
        placeholder.textContent = activeReasons.length
            ? 'Select a disapproval reason'
            : 'No reasons available yet';
        reasonSelect.appendChild(placeholder);

        activeReasons.forEach((reason) => {
            const option = document.createElement('option');
            option.value = String(reason.id);
            option.textContent = reason.label;
            reasonSelect.appendChild(option);
        });

        const disabled = activeReasons.length === 0;
        reasonSelect.disabled = disabled;
        if (confirmDeclineBtn) {
            confirmDeclineBtn.disabled = disabled;
        }
    }

    function renderManageReasonsList() {
        if (!manageList) {
            return;
        }

        manageList.innerHTML = '';

        if (state.reasons.length === 0) {
            const empty = document.createElement('p');
            empty.className = 'muted';
            empty.textContent = 'No disapproval reasons yet. Add your first reason below.';
            manageList.appendChild(empty);
            return;
        }

        state.reasons.forEach((reason) => {
            const wrapper = document.createElement('div');
            wrapper.className = 'decline-reason-item';
            wrapper.dataset.reasonId = String(reason.id);
            const isActive = Number(reason.is_active ?? 1) !== 0;
            if (!isActive) {
                wrapper.classList.add('inactive');
            }

            const input = document.createElement('input');
            input.type = 'text';
            input.value = reason.label;
            input.maxLength = 255;
            input.dataset.originalValue = reason.label;
            input.disabled = !isActive;
            wrapper.appendChild(input);

            const saveBtn = document.createElement('button');
            saveBtn.type = 'button';
            saveBtn.textContent = 'Save';
            saveBtn.className = 'decline-reason-save';
            saveBtn.disabled = !isActive;
            wrapper.appendChild(saveBtn);

            const toggleBtn = document.createElement('button');
            toggleBtn.type = 'button';
            toggleBtn.className = 'decline-reason-toggle ' + (isActive ? 'remove' : 'restore');
            toggleBtn.textContent = isActive ? 'Remove' : 'Restore';
            wrapper.appendChild(toggleBtn);

            manageList.appendChild(wrapper);
        });
    }

    function setModalVisible(modal, visible) {
        modal.style.display = visible ? 'flex' : 'none';
    }

    function resetDeclineModalErrors() {
        if (declineError) {
            declineError.textContent = '';
        }
    }

    function resetManageError() {
        if (manageError) {
            manageError.textContent = '';
        }
    }

    function openDeclineModal(form, row) {
        state.currentForm = form;
        state.currentRow = row;

        resetDeclineModalErrors();
        renderReasonSelect();

        const activeReasons = getActiveReasons();

        if (activeReasons.length === 0) {
            if (declineError) {
                declineError.textContent = 'Add at least one disapproval reason before disapproving an order.';
            }
            openManageModal();
            setModalVisible(declineModal, true);
            return;
        }

        const existingReasonId = row ? Number(row.dataset.declineReasonId || 0) : 0;
        const existingNote = row ? (row.dataset.declineReasonNote || '') : '';

        if (reasonSelect) {
            reasonSelect.value = existingReasonId ? String(existingReasonId) : '';
        }

        if (reasonNoteField) {
            reasonNoteField.value = existingNote;
        }

        setModalVisible(declineModal, true);
        if (reasonSelect && !reasonSelect.disabled) {
            reasonSelect.focus();
        }
    }

    function closeDeclineModal() {
        setModalVisible(declineModal, false);
        state.currentForm = null;
        state.currentRow = null;
        if (reasonSelect) {
            reasonSelect.selectedIndex = 0;
        }
        if (reasonNoteField) {
            reasonNoteField.value = '';
        }
        resetDeclineModalErrors();
    }

    function openManageModal() {
        resetManageError();
        renderManageReasonsList();
        setModalVisible(manageModal, true);
        if (newReasonInput) {
            newReasonInput.value = '';
            newReasonInput.focus();
        }
    }

    function closeManageModal() {
        setModalVisible(manageModal, false);
        resetManageError();
    }

    function clearReasonHiddenFields(form) {
        const reasonIdInput = form.querySelector('input[name="decline_reason_id"]');
        const reasonNoteInput = form.querySelector('input[name="decline_reason_note"]');
        if (reasonIdInput) {
            reasonIdInput.value = '';
        }
        if (reasonNoteInput) {
            reasonNoteInput.value = '';
        }
    }

    function handleStatusFormSubmit(event) {
        const form = event.target;
        const statusSelect = form.querySelector('select[name="new_status"]');
        const nextStatus = statusSelect ? statusSelect.value : '';

        if (nextStatus === 'disapproved') {
            event.preventDefault();
            const row = form.closest('.online-order-row');
            openDeclineModal(form, row);
        } else {
            clearReasonHiddenFields(form);
        }
    }

    function syncRowDataset(reasonId, reasonLabel, note) {
        if (!state.currentRow) {
            return;
        }
        state.currentRow.dataset.declineReasonId = reasonId ? String(reasonId) : '0';
        state.currentRow.dataset.declineReasonLabel = reasonLabel || '';
        state.currentRow.dataset.declineReasonNote = note || '';
    }

    function handleConfirmDecline() {
        if (!state.currentForm || !reasonSelect) {
            return;
        }

        const chosenReasonId = Number(reasonSelect.value || 0);
        if (!chosenReasonId) {
            if (declineError) {
                declineError.textContent = 'Select a disapproval reason to continue.';
            }
            return;
        }

        const selectedReason = state.reasons.find((reason) => Number(reason.id) === chosenReasonId);
        const noteValue = reasonNoteField ? reasonNoteField.value.trim() : '';

        const reasonIdInput = state.currentForm.querySelector('input[name="decline_reason_id"]');
        const reasonNoteInput = state.currentForm.querySelector('input[name="decline_reason_note"]');

        if (reasonIdInput) {
            reasonIdInput.value = String(chosenReasonId);
        }

        if (reasonNoteInput) {
            reasonNoteInput.value = noteValue;
        }

        syncRowDataset(chosenReasonId, selectedReason ? selectedReason.label : '', noteValue);
        const formToSubmit = state.currentForm;
        closeDeclineModal();
        if (formToSubmit) {
            formToSubmit.submit();
        }
    }

    async function postJson(payload) {
        const response = await fetch(apiUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: JSON.stringify(payload),
        });

        if (!response.ok) {
            throw new Error('Unable to reach disapproval reason service.');
        }

        const result = await response.json();
        if (!result || !result.success) {
            const message = result && result.message ? result.message : 'Action failed.';
            throw new Error(message);
        }

        if (Array.isArray(result.reasons)) {
            setReasons(result.reasons);
        }

        return result;
    }

    async function handleAddReason(event) {
        event.preventDefault();
        if (!newReasonInput) {
            return;
        }

        const label = newReasonInput.value.trim();
        if (label === '') {
            if (manageError) {
                manageError.textContent = 'Reason label cannot be empty.';
            }
            return;
        }

        try {
            resetManageError();
            const response = await postJson({ action: 'create', label });
            newReasonInput.value = '';
            newReasonInput.focus();

            if (response && response.reason && reasonSelect) {
                reasonSelect.value = String(response.reason.id);
            }
        } catch (err) {
            if (manageError) {
                manageError.textContent = err.message;
            }
        }
    }

    async function handleManageListClick(event) {
        const target = event.target;
        if (!(target instanceof HTMLElement)) {
            return;
        }

        const wrapper = target.closest('.decline-reason-item');
        if (!wrapper) {
            return;
        }

        const reasonId = Number(wrapper.dataset.reasonId || 0);
        const input = wrapper.querySelector('input');

        if (!reasonId || !(input instanceof HTMLInputElement)) {
            return;
        }

        if (target.classList.contains('decline-reason-save')) {
            const newValue = input.value.trim();
            if (newValue === '') {
                if (manageError) {
                    manageError.textContent = 'Reason label cannot be empty.';
                }
                return;
            }

            if (newValue === input.dataset.originalValue) {
                return;
            }

            try {
                resetManageError();
                await postJson({ action: 'update', id: reasonId, label: newValue });
            } catch (err) {
                if (manageError) {
                    manageError.textContent = err.message;
                }
            }
            return;
        }

        if (target.classList.contains('decline-reason-toggle')) {
            const shouldActivate = target.classList.contains('restore');
            if (!shouldActivate) {
                const confirmed = window.confirm('Remove this reason from the selectable list? Existing orders keep their label.');
                if (!confirmed) {
                    return;
                }
            }

            try {
                resetManageError();
                await postJson({ action: 'toggle', id: reasonId, active: shouldActivate });
            } catch (err) {
                if (manageError) {
                    manageError.textContent = err.message;
                }
            }
        }
    }

    function wireUpStatusForms() {
        const forms = document.querySelectorAll('.status-form');
        forms.forEach((form) => {
            form.addEventListener('submit', handleStatusFormSubmit);
        });
    }

    function registerModalEvents() {
        if (cancelDeclineBtn) {
            cancelDeclineBtn.addEventListener('click', closeDeclineModal);
        }
        if (closeDeclineBtn) {
            closeDeclineBtn.addEventListener('click', closeDeclineModal);
        }
        if (confirmDeclineBtn) {
            confirmDeclineBtn.addEventListener('click', handleConfirmDecline);
        }
        if (openManageBtn) {
            openManageBtn.addEventListener('click', openManageModal);
        }
        if (closeManageBtn) {
            closeManageBtn.addEventListener('click', closeManageModal);
        }
        if (addReasonForm) {
            addReasonForm.addEventListener('submit', handleAddReason);
        }
        if (manageList) {
            manageList.addEventListener('click', handleManageListClick);
        }
    }

    renderReasonSelect();
    renderManageReasonsList();
    wireUpStatusForms();
    registerModalEvents();
})();
