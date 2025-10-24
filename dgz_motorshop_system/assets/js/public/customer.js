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
    const authGateLoginBase = authGate ? (authGate.getAttribute('data-auth-gate-login-base') || '') : '';
    const authGateRegisterBase = authGate ? (authGate.getAttribute('data-auth-gate-register-base') || '') : '';
    const authGateDefaultRedirect = authGate ? (authGate.getAttribute('data-auth-gate-default-redirect') || '') : '';
    const authGateLoginLink = authGate ? authGate.querySelector('[data-auth-gate-login-link]') : null;
    const authGateRegisterLink = authGate ? authGate.querySelector('[data-auth-gate-register-link]') : null;

    const normaliseBaseUrl = (value, fallback) => {
        if (typeof value === 'string' && value.trim() !== '') {
            return value.trim();
        }
        if (typeof fallback === 'string' && fallback.trim() !== '') {
            return fallback.trim();
        }
        return '';
    };

    const composeRedirectUrl = (baseUrl, redirectTarget) => {
        const trimmedBase = normaliseBaseUrl(baseUrl);
        if (trimmedBase === '') {
            return '';
        }

        if (typeof redirectTarget !== 'string' || redirectTarget.trim() === '') {
            return trimmedBase;
        }

        const normalizedRedirect = redirectTarget.trim();

        try {
            const url = new URL(trimmedBase, window.location.origin);
            url.searchParams.set('redirect', normalizedRedirect);
            return url.toString();
        } catch (error) {
            const separator = trimmedBase.includes('?') ? '&' : '?';
            return `${trimmedBase}${separator}redirect=${encodeURIComponent(normalizedRedirect)}`;
        }
    };

    const updateAuthGateLinks = (redirectTarget) => {
        const loginBase = normaliseBaseUrl(authGateLoginBase, authGateLoginLink ? authGateLoginLink.getAttribute('href') : '');
        const registerBase = normaliseBaseUrl(authGateRegisterBase, authGateRegisterLink ? authGateRegisterLink.getAttribute('href') : '');
        const loginHref = composeRedirectUrl(loginBase, redirectTarget);
        const registerHref = composeRedirectUrl(registerBase, redirectTarget);

        if (authGateLoginLink && loginHref !== '') {
            authGateLoginLink.setAttribute('href', loginHref);
        }

        if (authGateRegisterLink && registerHref !== '') {
            authGateRegisterLink.setAttribute('href', registerHref);
        }
    };

    function openAuthGate(options = {}) {
        if (!authGate) {
            return;
        }

        const redirectOption = typeof options.redirectTo === 'string' && options.redirectTo.trim() !== ''
            ? options.redirectTo.trim()
            : authGateDefaultRedirect;

        updateAuthGateLinks(redirectOption);

        authGate.removeAttribute('hidden');
        body.classList.add('auth-gate-open');
    }

    function closeAuthGate() {
        if (!authGate) {
            return;
        }

        authGate.setAttribute('hidden', '');
        body.classList.remove('auth-gate-open');

        if (authGateDefaultRedirect) {
            updateAuthGateLinks(authGateDefaultRedirect);
        }
    }

    if (authGateDefaultRedirect) {
        updateAuthGateLinks(authGateDefaultRedirect);
    }

    window.customerAuth = {
        openGate: openAuthGate,
        closeGate: closeAuthGate,
    };

    if (authGate) {
        authGate.addEventListener('click', (event) => {
            if (event.target && event.target.closest('[data-auth-gate-close]')) {
                closeAuthGate();
            }
        });
    }

    if (authGate && authRequired === 'checkout' && sessionState !== 'authenticated') {
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

    const contactSection = document.querySelector('[data-contact-section]');
    if (contactSection) {
        const modeInput = contactSection.querySelector('[data-contact-mode-input]');
        const summary = contactSection.querySelector('[data-contact-summary]');
        const form = contactSection.querySelector('[data-contact-form]');
        const editButton = contactSection.querySelector('[data-contact-edit]');
        const cancelButton = contactSection.querySelector('[data-contact-cancel]');
        const requiredFields = form ? Array.from(form.querySelectorAll('[data-contact-required]')) : [];

        const syncFromSummary = () => {
            if (!summary || !form) {
                return;
            }
            const emailField = form.querySelector('input[name="email"]');
            const phoneField = form.querySelector('input[name="phone"]');
            const facebookField = form.querySelector('input[name="facebook_account"]');
            if (emailField && summary.dataset.contactEmail !== undefined) {
                emailField.value = summary.dataset.contactEmail;
            }
            if (phoneField && summary.dataset.contactPhone !== undefined) {
                phoneField.value = summary.dataset.contactPhone;
            }
            if (facebookField && summary.dataset.contactFacebook !== undefined) {
                facebookField.value = summary.dataset.contactFacebook;
            }
        };

        const setMode = (mode) => {
            const nextMode = mode === 'edit' ? 'edit' : 'summary';
            contactSection.dataset.contactMode = nextMode;
            if (modeInput) {
                modeInput.value = nextMode;
            }

            if (nextMode === 'edit') {
                summary?.setAttribute('hidden', '');
                if (form) {
                    form.classList.remove('is-hidden');
                    form.removeAttribute('hidden');
                }
                requiredFields.forEach((field) => field.setAttribute('required', ''));
                const firstEditable = form ? form.querySelector('input, textarea') : null;
                if (firstEditable && typeof firstEditable.focus === 'function') {
                    window.requestAnimationFrame(() => firstEditable.focus());
                }
            } else {
                summary?.removeAttribute('hidden');
                if (form) {
                    form.classList.add('is-hidden');
                    form.setAttribute('hidden', '');
                }
                syncFromSummary();
                requiredFields.forEach((field) => field.removeAttribute('required'));
            }
        };

        if (contactSection.dataset.contactMode === 'edit') {
            requiredFields.forEach((field) => field.setAttribute('required', ''));
        } else {
            requiredFields.forEach((field) => field.removeAttribute('required'));
            syncFromSummary();
        }

        editButton?.addEventListener('click', (event) => {
            event.preventDefault();
            setMode('edit');
        });

        cancelButton?.addEventListener('click', (event) => {
            event.preventDefault();
            setMode('summary');
        });
    }

    const billingSection = document.querySelector('[data-billing-section]');
    if (billingSection) {
        const modeInput = document.querySelector('[data-billing-mode-input]');
        const summary = billingSection.querySelector('[data-billing-summary]');
        const form = billingSection.querySelector('[data-billing-form]');
        const editButton = billingSection.querySelector('[data-billing-edit]');
        const cancelButton = billingSection.querySelector('[data-billing-cancel]');
        const requiredFields = form ? Array.from(form.querySelectorAll('[data-billing-required]')) : [];

        const setMode = (mode) => {
            const nextMode = mode === 'edit' ? 'edit' : 'summary';
            billingSection.dataset.billingMode = nextMode;
            if (modeInput) {
                modeInput.value = nextMode;
            }

            if (nextMode === 'edit') {
                if (summary) {
                    summary.setAttribute('hidden', '');
                }
                if (form) {
                    form.classList.remove('is-hidden');
                    form.removeAttribute('hidden');
                }
                requiredFields.forEach((field) => field.setAttribute('required', ''));
                const firstEditable = form ? form.querySelector('input, textarea') : null;
                if (firstEditable && typeof firstEditable.focus === 'function') {
                    window.requestAnimationFrame(() => firstEditable.focus());
                }
            } else {
                if (summary) {
                    summary.removeAttribute('hidden');
                }
                if (form) {
                    form.classList.add('is-hidden');
                    form.setAttribute('hidden', '');
                }
                requiredFields.forEach((field) => field.removeAttribute('required'));
            }
        };

        if (billingSection.dataset.billingMode === 'edit') {
            requiredFields.forEach((field) => field.setAttribute('required', ''));
        } else {
            requiredFields.forEach((field) => field.removeAttribute('required'));
        }

        editButton?.addEventListener('click', (event) => {
            event.preventDefault();
            setMode('edit');
        });

        cancelButton?.addEventListener('click', (event) => {
            event.preventDefault();
            setMode('summary');
        });
    }

    document.querySelectorAll('[data-order-card]').forEach((card) => {
        const toggle = card.querySelector('[data-order-toggle]');
        const details = card.querySelector('[data-order-details]');
        if (!toggle || !details) {
            return;
        }

        const label = toggle.querySelector('[data-order-toggle-label]');
        const header = card.querySelector('.customer-order-card__header');

        const setExpanded = (expanded) => {
            const isExpanded = Boolean(expanded);
            toggle.setAttribute('aria-expanded', isExpanded ? 'true' : 'false');
            if (isExpanded) {
                details.removeAttribute('hidden');
                card.classList.add('is-expanded');
                if (label) {
                    label.textContent = 'Hide order details';
                }
            } else {
                details.setAttribute('hidden', '');
                card.classList.remove('is-expanded');
                if (label) {
                    label.textContent = 'View order details';
                }
            }
        };

        toggle.addEventListener('click', (event) => {
            event.preventDefault();
            const expanded = toggle.getAttribute('aria-expanded') === 'true';
            setExpanded(!expanded);
        });

        header?.addEventListener('click', (event) => {
            if (event.target.closest('[data-order-toggle]')) {
                return;
            }
            const expanded = toggle.getAttribute('aria-expanded') === 'true';
            setExpanded(!expanded);
        });
    });

    // Utility to expose acceptance state
    document.addEventListener('terms:reset', () => {
        try {
            window.localStorage && window.localStorage.removeItem('dgz_terms_ack_v1');
        } catch (error) {
            // ignore
        }
    });
})();
