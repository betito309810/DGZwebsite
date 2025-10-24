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
        const saveButton = contactSection.querySelector('[data-contact-save]');
        const requiredFields = form ? Array.from(form.querySelectorAll('[data-contact-required]')) : [];
        const summaryName = summary ? summary.querySelector('[data-contact-summary-name]') : null;
        const summaryEmail = summary ? summary.querySelector('[data-contact-summary-email]') : null;
        const summaryPhone = summary ? summary.querySelector('[data-contact-summary-phone]') : null;

        const firstNameField = form ? form.querySelector('input[name="first_name"]') : null;
        const middleNameField = form ? form.querySelector('input[name="middle_name"]') : null;
        const lastNameField = form ? form.querySelector('input[name="last_name"]') : null;
        const emailField = form ? form.querySelector('input[name="email"]') : null;
        const phoneField = form ? form.querySelector('input[name="phone"]') : null;

        const buildFullName = (first, middle, last) => {
            return [first, middle, last]
                .map((value) => (typeof value === 'string' ? value.trim() : ''))
                .filter((value) => value !== '')
                .join(' ');
        };

        const setTextContent = (element, value) => {
            if (!element) {
                return;
            }
            element.textContent = value;
        };

        const syncFromSummary = () => {
            if (!summary || !form) {
                return;
            }
            if (firstNameField && summary.dataset.contactFirstName !== undefined) {
                firstNameField.value = summary.dataset.contactFirstName;
            }
            if (middleNameField && summary.dataset.contactMiddleName !== undefined) {
                middleNameField.value = summary.dataset.contactMiddleName;
            }
            if (lastNameField && summary.dataset.contactLastName !== undefined) {
                lastNameField.value = summary.dataset.contactLastName;
            }
            if (emailField && summary.dataset.contactEmail !== undefined) {
                emailField.value = summary.dataset.contactEmail;
            }
            if (phoneField && summary.dataset.contactPhone !== undefined) {
                phoneField.value = summary.dataset.contactPhone;
            }
        };

        const syncSummaryFromForm = () => {
            if (!summary || !form) {
                return;
            }
            const firstValue = firstNameField ? firstNameField.value.trim() : '';
            const middleValue = middleNameField ? middleNameField.value.trim() : '';
            const lastValue = lastNameField ? lastNameField.value.trim() : '';
            const emailValue = emailField ? emailField.value.trim() : '';
            const phoneValue = phoneField ? phoneField.value.trim() : '';

            summary.dataset.contactFirstName = firstValue;
            summary.dataset.contactMiddleName = middleValue;
            summary.dataset.contactLastName = lastValue;
            summary.dataset.contactEmail = emailValue;
            summary.dataset.contactPhone = phoneValue;

            setTextContent(summaryName, buildFullName(firstValue, middleValue, lastValue));
            setTextContent(summaryEmail, emailValue);
            setTextContent(summaryPhone, phoneValue);
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

        saveButton?.addEventListener('click', (event) => {
            event.preventDefault();
            syncSummaryFromForm();
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
        const saveButton = billingSection.querySelector('[data-billing-save]');
        const requiredFields = form ? Array.from(form.querySelectorAll('[data-billing-required]')) : [];
        const summaryAddress = summary ? summary.querySelector('[data-billing-summary-address]') : null;
        const summaryCity = summary ? summary.querySelector('[data-billing-summary-city]') : null;
        const summaryPostal = summary ? summary.querySelector('[data-billing-summary-postal]') : null;

        const addressField = form ? form.querySelector('textarea[name="address"]') : null;
        const postalField = form ? form.querySelector('input[name="postal_code"]') : null;
        const cityField = form ? form.querySelector('input[name="city"]') : null;

        const setMultilineText = (element, value) => {
            if (!element) {
                return;
            }
            const normalized = typeof value === 'string' ? value : '';
            const fragment = document.createDocumentFragment();
            normalized.split(/\r?\n/).forEach((part, index) => {
                if (index > 0) {
                    fragment.appendChild(document.createElement('br'));
                }
                fragment.appendChild(document.createTextNode(part));
            });
            element.replaceChildren(fragment);
        };

        const syncFromSummary = () => {
            if (!summary || !form) {
                return;
            }
            if (addressField && summary.dataset.billingAddress !== undefined) {
                addressField.value = summary.dataset.billingAddress;
            }
            if (postalField && summary.dataset.billingPostal !== undefined) {
                postalField.value = summary.dataset.billingPostal;
            }
            if (cityField && summary.dataset.billingCity !== undefined) {
                cityField.value = summary.dataset.billingCity;
            }
        };

        const syncSummaryFromForm = () => {
            if (!summary) {
                return;
            }
            const addressValue = addressField ? addressField.value : '';
            const postalValue = postalField ? postalField.value : '';
            const cityValue = cityField ? cityField.value : '';

            summary.dataset.billingAddress = addressValue;
            summary.dataset.billingPostal = postalValue;
            summary.dataset.billingCity = cityValue;

            setMultilineText(summaryAddress, addressValue);
            if (summaryCity) {
                summaryCity.textContent = cityValue;
            }
            if (summaryPostal) {
                summaryPostal.textContent = postalValue;
            }
        };

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
                syncFromSummary();
                requiredFields.forEach((field) => field.removeAttribute('required'));
            }
        };

        if (billingSection.dataset.billingMode === 'edit') {
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

        saveButton?.addEventListener('click', (event) => {
            event.preventDefault();
            syncSummaryFromForm();
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
