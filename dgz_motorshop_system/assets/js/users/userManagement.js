// Handles add-user form toggling and back navigation behaviour for the user management page.
function initializeUserManagementPage() {
    const toggleBtn = document.getElementById('toggleAddUser');
    const cancelBtn = document.getElementById('cancelAddUser');
    const section = document.getElementById('addUserSection');
    const backButton = document.getElementById('backButton');

    const createToast = (() => {
        let container = null;

        const resolveContainer = () => {
            if (container) {
                return container;
            }

            container = document.createElement('div');
            container.className = 'user-toast-container';
            container.setAttribute('role', 'status');
            container.setAttribute('aria-live', 'polite');
            document.body.appendChild(container);
            return container;
        };

        return (message, type = 'info') => {
            const trimmed = (message || '').toString().trim();
            if (trimmed === '') {
                return;
            }

            const normalizedType = ['success', 'error', 'info'].includes(type) ? type : 'info';
            const toast = document.createElement('div');
            toast.className = `user-toast user-toast--${normalizedType}`;
            toast.textContent = trimmed;

            const region = resolveContainer();
            region.appendChild(toast);

            // Force layout before applying the visible state so transitions fire reliably.
            requestAnimationFrame(() => {
                toast.classList.add('is-visible');
            });

            const dismiss = () => {
                toast.classList.remove('is-visible');
                setTimeout(() => {
                    toast.remove();
                    if (region.children.length === 0) {
                        region.remove();
                        container = null;
                    }
                }, 250);
            };

            const lifetime = normalizedType === 'error' ? 6000 : 4200;
            const timeoutId = window.setTimeout(dismiss, lifetime);

            toast.addEventListener('click', () => {
                window.clearTimeout(timeoutId);
                dismiss();
            });
        };
    })();

    const confirmStatusAction = (() => {
        const defaultOptions = {
            title: 'Confirm action',
            message: 'Are you sure you want to continue?',
            confirmLabel: 'Confirm',
            cancelLabel: 'Cancel',
        };

        const getFocusableElements = (container) => {
            if (!container) {
                return [];
            }

            const focusableSelectors = [
                'button:not([disabled])',
                '[href]',
                'input:not([disabled])',
                'select:not([disabled])',
                'textarea:not([disabled])',
                '[tabindex]:not([tabindex="-1"])',
            ];

            return Array.from(container.querySelectorAll(focusableSelectors.join(',')));
        };

        let overlay = null;
        let dialog = null;
        let titleNode = null;
        let messageNode = null;
        let confirmButton = null;
        let cancelButton = null;
        let activeResolver = null;
        let previousFocus = null;

        const close = (accepted) => {
            if (!overlay) {
                return;
            }

            overlay.classList.remove('is-visible');
            overlay.setAttribute('hidden', '');
            document.body.classList.remove('user-status-confirm-open');

            overlay.removeEventListener('keydown', handleKeydown, true);

            if (typeof activeResolver === 'function') {
                activeResolver(accepted === true);
            }

            activeResolver = null;

            window.setTimeout(() => {
                if (previousFocus && typeof previousFocus.focus === 'function') {
                    try {
                        previousFocus.focus();
                    } catch (focusError) {
                        // Ignore focus errors.
                    }
                }
                previousFocus = null;
            }, 20);
        };

        const handleBackdropClick = (event) => {
            const target = event.target;
            if (!target || !(target instanceof Element)) {
                return;
            }

            if (target.hasAttribute('data-user-confirm-dismiss') || target === overlay) {
                event.preventDefault();
                close(false);
            }
        };

        const handleKeydown = (event) => {
            if (!dialog) {
                return;
            }

            if (event.key === 'Escape') {
                event.preventDefault();
                close(false);
                return;
            }

            if (event.key === 'Tab') {
                const focusable = getFocusableElements(dialog);
                if (focusable.length === 0) {
                    event.preventDefault();
                    return;
                }

                const currentIndex = focusable.indexOf(document.activeElement);
                let nextIndex = currentIndex;

                if (event.shiftKey) {
                    nextIndex = currentIndex <= 0 ? focusable.length - 1 : currentIndex - 1;
                } else {
                    nextIndex = currentIndex === focusable.length - 1 ? 0 : currentIndex + 1;
                }

                if (nextIndex !== currentIndex) {
                    event.preventDefault();
                    focusable[nextIndex].focus();
                }
            }
        };

        const ensureElements = () => {
            if (overlay) {
                return true;
            }

            overlay = document.querySelector('[data-user-confirm-overlay]');
            if (!overlay) {
                return false;
            }

            dialog = overlay.querySelector('.user-status-confirm__dialog');
            titleNode = overlay.querySelector('[data-user-confirm-title]');
            messageNode = overlay.querySelector('[data-user-confirm-message]');
            confirmButton = overlay.querySelector('[data-user-confirm-accept]');
            cancelButton = overlay.querySelector('[data-user-confirm-cancel]');

            if (confirmButton) {
                confirmButton.addEventListener('click', () => {
                    close(true);
                });
            }

            if (cancelButton) {
                cancelButton.addEventListener('click', () => {
                    close(false);
                });
            }

            overlay.addEventListener('click', handleBackdropClick);

            return true;
        };

        return (options = {}) => new Promise((resolve) => {
            if (!ensureElements()) {
                resolve(true);
                return;
            }

            const config = { ...defaultOptions, ...options };

            if (titleNode) {
                titleNode.textContent = config.title;
            }

            if (messageNode) {
                messageNode.textContent = config.message;
            }

            if (confirmButton) {
                confirmButton.textContent = config.confirmLabel;
            }

            if (cancelButton) {
                cancelButton.textContent = config.cancelLabel;
            }

            activeResolver = resolve;
            previousFocus = document.activeElement;

            overlay.removeAttribute('hidden');

            // Force layout for transition.
            requestAnimationFrame(() => {
                overlay.classList.add('is-visible');
            });

            document.body.classList.add('user-status-confirm-open');

            overlay.addEventListener('keydown', handleKeydown, true);

            const focusTargets = getFocusableElements(dialog);
            if (focusTargets.length > 0) {
                focusTargets[0].focus();
            }
        });
    })();

    const bootFlashMessages = () => {
        const flashWrapper = document.querySelector('[data-user-management-flash]');
        if (!flashWrapper) {
            return;
        }

        const flashMessages = flashWrapper.querySelectorAll('[data-user-toast-message]');
        flashMessages.forEach((node, index) => {
            const message = node.getAttribute('data-user-toast-message') || '';
            const type = node.getAttribute('data-user-toast-type') || 'info';
            window.setTimeout(() => {
                createToast(message, type);
            }, index * 200);
        });

        flashWrapper.remove();
    };

    const toggleSection = function () {
        if (!section) {
            return;
        }
        section.classList.toggle('hidden');
    };

    if (toggleBtn) {
        toggleBtn.addEventListener('click', toggleSection);
    }

    if (cancelBtn) {
        cancelBtn.addEventListener('click', toggleSection);
    }

    if (backButton) {
        backButton.addEventListener('click', function () {
            window.history.back();
        });
    }

    bootFlashMessages();

    const statusForms = document.querySelectorAll('[data-user-status-form]');
    if (statusForms.length > 0) {
        statusForms.forEach((form) => {
            form.addEventListener('submit', (event) => {
                if (form.dataset.userStatusConfirming === 'true') {
                    delete form.dataset.userStatusConfirming;
                    return;
                }

                event.preventDefault();

                const action = (form.getAttribute('data-user-status-action') || 'deactivate').toLowerCase();
                const name = form.getAttribute('data-user-status-name') || 'this staff member';

                const isActivation = action === 'activate';

                const options = {
                    title: isActivation ? 'Activate staff account' : 'Deactivate staff account',
                    message: isActivation
                        ? `Reactivate ${name}? They will regain access immediately.`
                        : `Deactivate ${name}? They will lose access until reactivated.`,
                    confirmLabel: isActivation ? 'Activate' : 'Deactivate',
                    cancelLabel: 'Cancel',
                };

                confirmStatusAction(options).then((accepted) => {
                    if (!accepted) {
                        return;
                    }

                    form.dataset.userStatusConfirming = 'true';
                    form.submit();
                });
            });
        });
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeUserManagementPage);
} else {
    initializeUserManagementPage();
}
