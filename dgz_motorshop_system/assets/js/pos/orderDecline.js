/**
 * POS online order disapproval workflow.
 * Rewritten to submit directly to the new disapproval endpoint and enforce
 * reason selection with clear error handling. // Fix: new fully rewritten flow.
 */
(function () {
    if (typeof window === 'undefined') {
        return;
    }

    const api = {
        disapprove: 'orderDisapprove.php',
        reasons: 'declineReasonsApi.php',
    };

    const initialDeclineReasons = window.dgzPosData
        && Array.isArray(window.dgzPosData.declineReasons)
            ? window.dgzPosData.declineReasons
            : [];

    const state = {
        reasons: normalizeReasonsList(initialDeclineReasons),
        currentForm: null,
        currentRow: null,
    };

    const elements = {};

    document.addEventListener('DOMContentLoaded', () => {
        cacheDom();
        renderReasonSelect();
        renderReasonsManager();
        wireStatusForms();
        wireModalButtons();
    });

    function normalizeReasonsList(rawReasons) {
        if (!Array.isArray(rawReasons)) {
            return [];
        }

        const seenLabels = new Set();
        const cleaned = [];

        rawReasons.forEach((reason) => {
            if (!reason) {
                return;
            }

            const id = Number(reason.id || 0);
            const label = typeof reason.label === 'string' ? reason.label.trim() : '';
            if (label === '') {
                return;
            }

            const labelKey = label.toLowerCase();
            if (seenLabels.has(labelKey)) {
                return;
            }

            seenLabels.add(labelKey);
            cleaned.push({
                id: id > 0 ? id : cleaned.length + 1,
                label,
            });
        });

        return cleaned;
    }

    function cacheDom() {
        elements.declineModal = document.getElementById('declineOrderModal');
        elements.reasonSelect = document.getElementById('declineReasonSelect');
        elements.noteField = document.getElementById('declineReasonNote');
        // Added: cache the optional attachment input so we can read/clear files.
        elements.attachmentField = document.getElementById('declineAttachment');
        elements.errorBox = document.getElementById('declineModalError');
        elements.confirmButton = document.getElementById('confirmDeclineOrder');
        elements.cancelButton = document.getElementById('cancelDeclineOrder');
        elements.closeButton = document.getElementById('closeDeclineOrderModal');
        elements.manageButton = document.getElementById('openManageDeclineReasons');

        elements.manageModal = document.getElementById('manageDeclineReasonsModal');
        elements.manageClose = document.getElementById('closeManageDeclineReasons');
        elements.manageList = document.getElementById('declineReasonsList');
        elements.manageError = document.getElementById('manageDeclineError');
        elements.manageForm = document.getElementById('addDeclineReasonForm');
        elements.manageInput = document.getElementById('newDeclineReasonInput');
    }

    function renderReasonSelect() {
        const select = elements.reasonSelect;
        if (!select) {
            return;
        }

        select.innerHTML = '';

        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.disabled = true;
        placeholder.selected = true;
        placeholder.textContent = state.reasons.length
            ? 'Select a disapproval reason'
            : 'No disapproval reasons yet';
        select.appendChild(placeholder);

        state.reasons
            .slice()
            .sort((a, b) => (a.label || '').localeCompare(b.label || ''))
            .forEach((reason) => {
                const option = document.createElement('option');
                option.value = String(reason.id);
                option.textContent = reason.label;
                select.appendChild(option);
            });

        const disabled = state.reasons.length === 0;
        select.disabled = disabled;
        if (elements.confirmButton) {
            elements.confirmButton.disabled = disabled;
        }
    }

    function renderReasonsManager() {
        const list = elements.manageList;
        if (!list) {
            return;
        }

        list.innerHTML = '';

        if (state.reasons.length === 0) {
            const empty = document.createElement('p');
            empty.className = 'muted';
            empty.textContent = 'No disapproval reasons yet. Add one below to get started.';
            list.appendChild(empty);
            return;
        }

        state.reasons
            .slice()
            .sort((a, b) => (a.label || '').localeCompare(b.label || ''))
            .forEach((reason) => {
                const wrapper = document.createElement('div');
                wrapper.className = 'decline-reason-item';
                wrapper.dataset.reasonId = String(reason.id);

                const input = document.createElement('input');
                input.type = 'text';
                input.value = reason.label;
                input.maxLength = 255;
                wrapper.appendChild(input);

                const saveBtn = document.createElement('button');
                saveBtn.type = 'button';
                saveBtn.className = 'decline-reason-save';
                saveBtn.textContent = 'Save';
                wrapper.appendChild(saveBtn);

                const removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.className = 'decline-reason-remove';
                removeBtn.textContent = 'Remove';
                wrapper.appendChild(removeBtn);

                list.appendChild(wrapper);
            });
    }

    function wireStatusForms() {
        document.addEventListener('submit', (event) => {
            const form = event.target.closest('.status-form');
            if (!form) {
                return;
            }

            const select = form.querySelector('select[name="new_status"]');
            const nextStatus = select ? select.value : '';
            if (nextStatus === 'disapproved') {
                event.preventDefault();
                openDeclineModal(form);
            }
        });
    }

    function wireModalButtons() {
        if (elements.cancelButton) {
            elements.cancelButton.addEventListener('click', closeDeclineModal);
        }
        if (elements.closeButton) {
            elements.closeButton.addEventListener('click', closeDeclineModal);
        }
        if (elements.confirmButton) {
            elements.confirmButton.addEventListener('click', submitDisapproval);
        }
        if (elements.manageButton) {
            elements.manageButton.addEventListener('click', openManageModal);
        }
        if (elements.manageClose) {
            elements.manageClose.addEventListener('click', closeManageModal);
        }
        if (elements.manageForm) {
            elements.manageForm.addEventListener('submit', handleAddReason);
        }
        if (elements.manageList) {
            elements.manageList.addEventListener('click', handleManageListClick);
        }
    }

    function getDataAttribute(element, attribute) {
        if (!element) {
            return '';
        }
        const value = element.getAttribute('data-' + attribute);
        return value !== null ? value : '';
    }

    function openDeclineModal(form) {
        state.currentForm = form;
        state.currentRow = form.closest('.online-order-row') || null;

        if (elements.errorBox) {
            elements.errorBox.textContent = '';
        }
        if (elements.noteField) {
            const noteValue = getDataAttribute(state.currentRow, 'decline-reason-note');
            elements.noteField.value = noteValue;
        }

        const currentReasonId = getDataAttribute(state.currentRow, 'decline-reason-id');
        if (elements.reasonSelect) {
            renderReasonSelect();
            elements.reasonSelect.value = currentReasonId && currentReasonId !== '0'
                ? currentReasonId
                : '';
        }

        if (elements.declineModal) {
            elements.declineModal.style.display = 'flex';
        }
    }

    function closeDeclineModal() {
        if (elements.declineModal) {
            elements.declineModal.style.display = 'none';
        }
        if (elements.errorBox) {
            elements.errorBox.textContent = '';
        }
        if (elements.reasonSelect) {
            elements.reasonSelect.selectedIndex = 0;
        }
        if (elements.noteField) {
            elements.noteField.value = '';
        }
        if (elements.attachmentField) {
            elements.attachmentField.value = '';
        }
        state.currentForm = null;
        state.currentRow = null;
    }

    function openManageModal() {
        if (elements.manageError) {
            elements.manageError.textContent = '';
        }
        renderReasonsManager();
        if (elements.manageModal) {
            elements.manageModal.style.display = 'flex';
        }
        if (elements.manageInput) {
            elements.manageInput.value = '';
            elements.manageInput.focus();
        }
    }

    function closeManageModal() {
        if (elements.manageModal) {
            elements.manageModal.style.display = 'none';
        }
        if (elements.manageError) {
            elements.manageError.textContent = '';
        }
    }

    function setReasons(reasons) {
        state.reasons = normalizeReasonsList(reasons);
        renderReasonSelect();
        renderReasonsManager();
    }

    async function submitDisapproval() {
        if (!state.currentForm || !elements.reasonSelect) {
            return;
        }

        const orderId = Number(state.currentForm.querySelector('input[name="order_id"]').value || 0);
        const selectedReasonId = Number(elements.reasonSelect.value || 0);
        const selectedOption = elements.reasonSelect.options[elements.reasonSelect.selectedIndex];
        const selectedLabel = selectedOption ? selectedOption.textContent.trim() : '';
        const note = elements.noteField ? elements.noteField.value.trim() : '';
        const attachmentInput = elements.attachmentField;
        let attachmentFile = null;
        if (
            attachmentInput
            && attachmentInput.files
            && attachmentInput.files.length > 0
        ) {
            attachmentFile = attachmentInput.files[0];
        }

        if (attachmentFile) {
            const allowedTypes = ['application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            const maxSize = 5 * 1024 * 1024; // 5MB limit for uploads.
            if (allowedTypes.indexOf(attachmentFile.type) === -1) {
                if (elements.errorBox) {
                    elements.errorBox.textContent = 'Only PDF or image files are allowed for attachments.';
                }
                attachmentInput.value = '';
                return;
            }
            if (attachmentFile.size > maxSize) {
                if (elements.errorBox) {
                    elements.errorBox.textContent = 'Attachment is too large. Please keep files under 5MB.';
                }
                return;
            }
        }

        if (!selectedReasonId) {
            if (elements.errorBox) {
                elements.errorBox.textContent = 'Please choose a disapproval reason before submitting.';
            }
            elements.reasonSelect.focus();
            return;
        }

        if (!orderId) {
            if (elements.errorBox) {
                elements.errorBox.textContent = 'Invalid order. Please refresh and try again.';
            }
            return;
        }

        if (elements.errorBox) {
            elements.errorBox.textContent = '';
        }

        if (elements.confirmButton) {
            elements.confirmButton.disabled = true;
            elements.confirmButton.textContent = 'Disapproving...';
        }

        try {
            // Build FormData payload so we can include the optional attachment.
            const payload = new FormData();
            payload.append('orderId', String(orderId));
            payload.append('reasonId', String(selectedReasonId));
            payload.append('reasonLabel', selectedLabel);
            payload.append('note', note);
            if (attachmentFile) {
                payload.append('declineAttachment', attachmentFile);
            }

            const response = await fetch(api.disapprove, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: payload,
            });

            const result = await response.json().catch(() => null);
            if (!response.ok || !result || result.success !== true) {
                throw new Error(result && result.message ? result.message : 'Unable to disapprove order.');
            }

            closeDeclineModal();
            redirectWithMessage('disapproved_success', '1');
        } catch (error) {
            if (elements.errorBox) {
                elements.errorBox.textContent = error.message || 'Unable to disapprove order.';
            }
        } finally {
            if (elements.confirmButton) {
                elements.confirmButton.disabled = false;
                elements.confirmButton.textContent = 'Disapprove Order';
            }
        }
    }

    function redirectWithMessage(param, value) {
        const url = new URL(window.location.href);
        url.searchParams.set('tab', 'online');
        url.searchParams.set(param, value);
        window.location.href = url.toString();
    }

    async function handleAddReason(event) {
        event.preventDefault();
        if (!elements.manageInput) {
            return;
        }

        const label = elements.manageInput.value.trim();
        if (label === '') {
            if (elements.manageError) {
                elements.manageError.textContent = 'Reason label cannot be empty.';
            }
            return;
        }

        try {
            if (elements.manageError) {
                elements.manageError.textContent = '';
            }
            const response = await fetch(api.reasons, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify({ action: 'create', label }),
            });
            const result = await response.json();
            if (!response.ok || !result || result.success !== true) {
                throw new Error(result && result.message ? result.message : 'Failed to add reason.');
            }
            setReasons(result.reasons || []);
            elements.manageInput.value = '';
            elements.manageInput.focus();
        } catch (error) {
            if (elements.manageError) {
                elements.manageError.textContent = error.message || 'Failed to add reason.';
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
            const newLabel = input.value.trim();
            if (newLabel === '') {
                if (elements.manageError) {
                    elements.manageError.textContent = 'Reason label cannot be empty.';
                }
                return;
            }
            try {
                if (elements.manageError) {
                    elements.manageError.textContent = '';
                }
                const response = await fetch(api.reasons, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ action: 'update', id: reasonId, label: newLabel }),
                });
                const result = await response.json();
                if (!response.ok || !result || result.success !== true) {
                    throw new Error(result && result.message ? result.message : 'Failed to update reason.');
                }
                setReasons(result.reasons || []);
            } catch (error) {
                if (elements.manageError) {
                    elements.manageError.textContent = error.message || 'Failed to update reason.';
                }
            }
            return;
        }

        if (target.classList.contains('decline-reason-remove')) {
            const confirmed = window.confirm('Delete this reason? Existing orders will no longer display it.');
            if (!confirmed) {
                return;
            }
            try {
                if (elements.manageError) {
                    elements.manageError.textContent = '';
                }
                const response = await fetch(api.reasons, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ action: 'delete', id: reasonId }),
                });
                const result = await response.json();
                if (!response.ok || !result || result.success !== true) {
                    throw new Error(result && result.message ? result.message : 'Failed to delete reason.');
                }
                setReasons(result.reasons || []);
            } catch (error) {
                if (elements.manageError) {
                    elements.manageError.textContent = error.message || 'Failed to delete reason.';
                }
            }
        }
    }
})();
