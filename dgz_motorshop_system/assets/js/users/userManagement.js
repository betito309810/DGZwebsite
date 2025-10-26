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
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeUserManagementPage);
} else {
    initializeUserManagementPage();
}
