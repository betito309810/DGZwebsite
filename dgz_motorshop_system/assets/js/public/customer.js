(function () {
    const body = document.body;
    if (!body) {
        return;
    }

    const sessionState = (body.dataset.customerSession || 'guest').toLowerCase();
    const firstName = body.dataset.customerFirstName || null;

    window.customerSession = Object.freeze({
        isAuthenticated: sessionState === 'authenticated',
        firstName: firstName && firstName.trim() !== '' ? firstName.trim() : null,
    });

    // Account menu dropdown
    const accountMenu = document.querySelector('[data-account-menu]');
    const accountTrigger = document.querySelector('[data-account-trigger]');
    const accountDropdown = document.querySelector('[data-account-dropdown]');

    if (accountMenu && accountTrigger && accountDropdown) {
        const closeDropdown = () => {
            accountDropdown.setAttribute('hidden', '');
            accountTrigger.setAttribute('aria-expanded', 'false');
        };

        const openDropdown = () => {
            accountDropdown.removeAttribute('hidden');
            accountTrigger.setAttribute('aria-expanded', 'true');
        };

        accountTrigger.addEventListener('click', (event) => {
            event.preventDefault();
            const isOpen = accountDropdown.hasAttribute('hidden') === false;
            if (isOpen) {
                closeDropdown();
            } else {
                openDropdown();
            }
        });

        document.addEventListener('click', (event) => {
            if (!accountMenu.contains(event.target)) {
                closeDropdown();
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                closeDropdown();
            }
        });
    }

    // Password visibility toggles
    document.querySelectorAll('[data-toggle-target]').forEach((button) => {
        button.addEventListener('click', () => {
            const targetId = button.getAttribute('data-toggle-target');
            const input = targetId ? document.getElementById(targetId) : null;
            if (!input) {
                return;
            }
            const nextType = input.type === 'password' ? 'text' : 'password';
            input.type = nextType;
            const icon = button.querySelector('i');
            if (icon) {
                icon.classList.toggle('fa-eye');
                icon.classList.toggle('fa-eye-slash');
            }
            button.setAttribute('aria-label', nextType === 'password' ? 'Show password' : 'Hide password');
        });
    });

    // Auth gate modal handling
    const authGate = document.querySelector('[data-auth-gate]');
    const authRequired = body.dataset.authRequired || '';

    function openAuthGate() {
        if (!authGate) {
            return;
        }
        authGate.removeAttribute('hidden');
        body.classList.add('auth-gate-open');
    }

    function closeAuthGate() {
        if (!authGate) {
            return;
        }
        authGate.setAttribute('hidden', '');
        body.classList.remove('auth-gate-open');
    }

    window.customerAuth = {
        openGate: openAuthGate,
        closeGate: closeAuthGate,
    };

    if (authGate) {
        authGate.addEventListener('click', (event) => {
            if (event.target.matches('[data-auth-gate-close]')) {
                closeAuthGate();
            }
        });
    }

    if (authRequired === 'checkout' && sessionState !== 'authenticated') {
        // Show modal immediately for guests
        openAuthGate();

        const checkoutForm = document.querySelector('[data-auth-required="checkout"] .checkout-form form');
        if (checkoutForm) {
            checkoutForm.addEventListener('submit', (event) => {
                if (sessionState !== 'authenticated') {
                    event.preventDefault();
                    openAuthGate();
                }
            });
        }
    }

    // Utility to expose acceptance state
    document.addEventListener('terms:reset', () => {
        try {
            window.localStorage && window.localStorage.removeItem('dgz_terms_ack_v1');
        } catch (error) {
            // ignore
        }
    });
})();
