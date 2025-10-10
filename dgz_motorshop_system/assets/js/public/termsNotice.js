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

    document.addEventListener('DOMContentLoaded', function () {
        const overlay = document.getElementById('termsOverlay');
        const acceptButton = document.getElementById('termsAcceptButton');

        if (!overlay || !acceptButton) {
            return;
        }

        if (window.localStorage && window.localStorage.getItem(storageKey) === 'accepted') {
            return;
        }

        showOverlay(overlay);

        acceptButton.addEventListener('click', function () {
            try {
                window.localStorage && window.localStorage.setItem(storageKey, 'accepted');
            } catch (error) {
                // If localStorage is not available, fail silently and just hide the modal.
            }
            hideOverlay(overlay);
        });
    });
})();
