(function () {
    const storageKey = 'dgz_terms_ack_v1';

    function showOverlay(overlay) {
        overlay.removeAttribute('hidden');
        document.body.classList.add('terms-overlay-active');
        var focusTarget = overlay.querySelector('button, [href], input, select, textarea');
        if (focusTarget && typeof focusTarget.focus === 'function') {
            try {
                focusTarget.focus({ preventScroll: true });
            } catch (error) {
                focusTarget.focus();
            }
        }
    }

    function hideOverlay(overlay) {
        overlay.setAttribute('hidden', '');
        document.body.classList.remove('terms-overlay-active');
    }

    function enableAccept(button) {
        if (!button) {
            return;
        }
        button.disabled = false;
        button.classList.remove('is-disabled');
    }

    function disableAccept(button) {
        if (!button) {
            return;
        }
        button.disabled = true;
        button.classList.add('is-disabled');
    }

    document.addEventListener('DOMContentLoaded', function () {
        const overlay = document.getElementById('termsOverlay');
        const acceptButton = document.getElementById('termsAcceptButton');
        const scrollRegion = document.querySelector('[data-terms-scroll]');

        if (!overlay || !acceptButton || !scrollRegion) {
            return;
        }

        var hasAcceptedTerms = false;

        try {
            if (window.localStorage) {
                hasAcceptedTerms = window.localStorage.getItem(storageKey) === 'accepted';
            }
        } catch (error) {
            hasAcceptedTerms = false;
        }

        if (hasAcceptedTerms) {
            return;
        }

        disableAccept(acceptButton);
        showOverlay(overlay);

        function handleScroll() {
            const reachedBottom = scrollRegion.scrollTop + scrollRegion.clientHeight >= scrollRegion.scrollHeight - 4;
            if (reachedBottom) {
                enableAccept(acceptButton);
                scrollRegion.removeEventListener('scroll', handleScroll);
            }
        }

        scrollRegion.addEventListener('scroll', handleScroll);
        // Trigger check in case content fits without scrolling
        handleScroll();

        acceptButton.addEventListener('click', function () {
            try {
                window.localStorage && window.localStorage.setItem(storageKey, 'accepted');
            } catch (error) {
            }
            hideOverlay(overlay);
        });
    });
})();
