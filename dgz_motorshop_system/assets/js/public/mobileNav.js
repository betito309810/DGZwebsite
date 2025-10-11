(function () {
    const toggle = document.getElementById('mobileNavToggle');
    const nav = document.getElementById('primaryNav');
    const backdrop = document.getElementById('navBackdrop');
    const body = document.body;
    const focusableSelector = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
    const mobileMediaQuery = window.matchMedia('(max-width: 768px)');
    let lastFocusedElement = null;

    if (!toggle || !nav) {
        return;
    }

    function setAriaHidden(hidden) {
        if (mobileMediaQuery.matches) {
            nav.setAttribute('aria-hidden', hidden ? 'true' : 'false');
        } else {
            nav.removeAttribute('aria-hidden');
        }
    }

    function trapFocus(event) {
        if (event.key !== 'Tab') {
            return;
        }

        const focusableItems = Array.from(nav.querySelectorAll(focusableSelector)).filter(el => el.offsetParent !== null || getComputedStyle(el).position === 'fixed');
        if (!focusableItems.length) {
            return;
        }

        const firstItem = focusableItems[0];
        const lastItem = focusableItems[focusableItems.length - 1];

        if (event.shiftKey) {
            if (document.activeElement === firstItem) {
                event.preventDefault();
                lastItem.focus();
            }
        } else if (document.activeElement === lastItem) {
            event.preventDefault();
            firstItem.focus();
        }
    }

    function onDocumentKeydown(event) {
        if (event.key === 'Escape') {
            closeNav();
        }
    }

    function openNav() {
        if (nav.classList.contains('is-open')) {
            return;
        }

        lastFocusedElement = document.activeElement instanceof HTMLElement ? document.activeElement : null;

        nav.classList.add('is-open');
        toggle.setAttribute('aria-expanded', 'true');
        setAriaHidden(false);

        if (backdrop) {
            backdrop.hidden = false;
            backdrop.classList.add('is-active');
        }

        body.classList.add('nav-open');
        document.addEventListener('keydown', onDocumentKeydown);
        nav.addEventListener('keydown', trapFocus);

        const firstFocusable = nav.querySelector(focusableSelector);
        if (firstFocusable instanceof HTMLElement) {
            firstFocusable.focus();
        }
    }

    function closeNav(options) {
        const { restoreFocus = true } = options || {};

        if (!nav.classList.contains('is-open')) {
            setAriaHidden(true);
            return;
        }

        nav.classList.remove('is-open');
        toggle.setAttribute('aria-expanded', 'false');
        setAriaHidden(true);

        if (backdrop) {
            backdrop.classList.remove('is-active');
            backdrop.hidden = true;
        }

        body.classList.remove('nav-open');
        document.removeEventListener('keydown', onDocumentKeydown);
        nav.removeEventListener('keydown', trapFocus);

        if (restoreFocus && lastFocusedElement instanceof HTMLElement) {
            lastFocusedElement.focus();
        }
    }

    toggle.addEventListener('click', function () {
        if (nav.classList.contains('is-open')) {
            closeNav();
        } else {
            openNav();
        }
    });

    backdrop?.addEventListener('click', function () {
        closeNav();
    });

    nav.querySelectorAll('.nav-link').forEach(function (link) {
        link.addEventListener('click', function () {
            closeNav({ restoreFocus: false });
        });
    });

    mobileMediaQuery.addEventListener('change', function (event) {
        if (!event.matches) {
            closeNav({ restoreFocus: false });
            nav.removeAttribute('aria-hidden');
            toggle.setAttribute('aria-expanded', 'false');
            body.classList.remove('nav-open');
            if (backdrop) {
                backdrop.classList.remove('is-active');
                backdrop.hidden = true;
            }
        } else {
            setAriaHidden(nav.classList.contains('is-open') ? false : true);
        }
    });

    setAriaHidden(nav.classList.contains('is-open') ? false : true);
})();
