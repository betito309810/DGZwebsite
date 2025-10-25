(function () {
    if (typeof window === 'undefined') {
        return;
    }

    const queue = [];
    let current = null;
    let elements = null;
    let stylesInjected = false;
    let lastFocused = null;

    function fallbackAlert(message) {
        window.alert(String(message != null ? message : ''));
        return Promise.resolve();
    }

    function ensureStyles() {
        if (stylesInjected || typeof document === 'undefined') {
            return;
        }

        const style = document.createElement('style');
        style.id = 'dgz-alert-styles';
        style.textContent = `
            .dgz-alert-overlay {
                position: fixed;
                inset: 0;
                display: flex;
                align-items: center;
                justify-content: center;
                background: rgba(15, 23, 42, 0.55);
                backdrop-filter: blur(2px);
                padding: 16px;
                z-index: 2147483646;
                opacity: 0;
                pointer-events: none;
                transition: opacity 0.2s ease;
            }

            .dgz-alert-overlay[data-visible="true"] {
                opacity: 1;
                pointer-events: auto;
            }

            .dgz-alert-dialog {
                width: min(420px, 100%);
                background: #ffffff;
                border-radius: 16px;
                box-shadow: 0 20px 60px rgba(15, 23, 42, 0.25);
                padding: 24px;
                display: flex;
                flex-direction: column;
                gap: 16px;
                font-family: 'Inter', 'Segoe UI', system-ui, -apple-system, BlinkMacSystemFont, sans-serif;
            }

            .dgz-alert-title {
                margin: 0;
                font-size: 1.1rem;
                font-weight: 700;
                color: #0f172a;
            }

            .dgz-alert-message {
                margin: 0;
                font-size: 0.95rem;
                line-height: 1.5;
                color: #1e293b;
                white-space: pre-line;
            }

            .dgz-alert-actions {
                display: flex;
                justify-content: flex-end;
                gap: 12px;
            }

            .dgz-alert-button {
                appearance: none;
                border: none;
                border-radius: 999px;
                padding: 10px 22px;
                font-weight: 600;
                font-size: 0.95rem;
                cursor: pointer;
                color: #ffffff;
                background: linear-gradient(135deg, #2563eb, #1d4ed8);
                box-shadow: 0 6px 14px rgba(37, 99, 235, 0.25);
                transition: transform 0.15s ease, box-shadow 0.15s ease;
            }

            .dgz-alert-button:focus-visible {
                outline: 3px solid rgba(37, 99, 235, 0.35);
                outline-offset: 2px;
            }

            .dgz-alert-button:hover {
                transform: translateY(-1px);
                box-shadow: 0 10px 22px rgba(37, 99, 235, 0.32);
            }

            .dgz-alert-button:active {
                transform: translateY(0);
                box-shadow: 0 4px 12px rgba(37, 99, 235, 0.28);
            }
        `;

        document.head.appendChild(style);
        stylesInjected = true;
    }

    function ensureElements() {
        if (elements || typeof document === 'undefined') {
            return elements;
        }

        const overlay = document.createElement('div');
        overlay.className = 'dgz-alert-overlay';
        overlay.setAttribute('hidden', 'hidden');
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.tabIndex = -1;

        const dialog = document.createElement('div');
        dialog.className = 'dgz-alert-dialog';

        const titleEl = document.createElement('h2');
        titleEl.className = 'dgz-alert-title';
        titleEl.id = 'dgz-alert-title';
        titleEl.setAttribute('hidden', 'hidden');

        const messageEl = document.createElement('p');
        messageEl.className = 'dgz-alert-message';
        messageEl.id = 'dgz-alert-message';

        const actions = document.createElement('div');
        actions.className = 'dgz-alert-actions';

        const okButton = document.createElement('button');
        okButton.type = 'button';
        okButton.className = 'dgz-alert-button';
        okButton.textContent = 'OK';

        actions.appendChild(okButton);
        dialog.appendChild(titleEl);
        dialog.appendChild(messageEl);
        dialog.appendChild(actions);
        overlay.appendChild(dialog);
        document.body.appendChild(overlay);

        overlay.setAttribute('aria-labelledby', titleEl.id);
        overlay.setAttribute('aria-describedby', messageEl.id);

        elements = {
            overlay,
            dialog,
            titleEl,
            messageEl,
            okButton,
        };

        return elements;
    }

    function hideOverlay(instance) {
        const { overlay, okButton, keyHandler } = instance;
        overlay.dataset.visible = 'false';
        overlay.setAttribute('hidden', 'hidden');
        overlay.removeEventListener('click', instance.overlayClick);
        document.removeEventListener('keydown', keyHandler, true);
        okButton.removeEventListener('click', instance.confirm);
        if (lastFocused && typeof lastFocused.focus === 'function') {
            try {
                lastFocused.focus({ preventScroll: true });
            } catch (err) {
                lastFocused.focus();
            }
        }
        lastFocused = null;
    }

    function displayNext() {
        if (current || queue.length === 0) {
            return;
        }

        ensureStyles();
        const refs = ensureElements();
        if (!refs) {
            const next = queue.shift();
            fallbackAlert(next.message);
            next.resolve();
            current = null;
            displayNext();
            return;
        }

        const { overlay, titleEl, messageEl, okButton } = refs;
        const next = queue.shift();
        current = {
            resolve: next.resolve,
            overlay,
            okButton,
            keyHandler: null,
            overlayClick: null,
            confirm: null,
        };

        const { message, options } = next;
        const title = options && typeof options.title === 'string' ? options.title.trim() : '';
        const okLabel = options && typeof options.okLabel === 'string' && options.okLabel.trim() !== ''
            ? options.okLabel.trim()
            : 'OK';

        if (title) {
            titleEl.textContent = title;
            titleEl.removeAttribute('hidden');
        } else {
            titleEl.textContent = '';
            titleEl.setAttribute('hidden', 'hidden');
        }

        messageEl.textContent = String(message != null ? message : '');
        okButton.textContent = okLabel;

        const confirm = () => {
            hideOverlay(current);
            const resolve = current.resolve;
            current = null;
            resolve();
            displayNext();
        };

        const keyHandler = (event) => {
            if (event.defaultPrevented) {
                return;
            }

            if (event.key === 'Escape' || event.key === 'Esc' || event.keyCode === 27) {
                event.preventDefault();
                confirm();
            } else if (event.key === 'Enter' || event.keyCode === 13) {
                event.preventDefault();
                confirm();
            }
        };

        const overlayClick = (event) => {
            if (event.target === overlay) {
                event.preventDefault();
                confirm();
            }
        };

        current.keyHandler = keyHandler;
        current.overlayClick = overlayClick;
        current.confirm = confirm;

        okButton.addEventListener('click', confirm);
        document.addEventListener('keydown', keyHandler, true);
        overlay.addEventListener('click', overlayClick);

        lastFocused = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        overlay.removeAttribute('hidden');
        requestAnimationFrame(() => {
            overlay.dataset.visible = 'true';
            try {
                okButton.focus({ preventScroll: true });
            } catch (err) {
                okButton.focus();
            }
        });
    }

    function enqueueAlert(message, options) {
        return new Promise((resolve) => {
            queue.push({ message, options: options || {}, resolve });
            if (!current) {
                displayNext();
            }
        });
    }

    function showAlert(message, options) {
        try {
            if (typeof document === 'undefined' || !document.body) {
                return fallbackAlert(message);
            }

            return enqueueAlert(message, options);
        } catch (error) {
            return fallbackAlert(message);
        }
    }

    window.dgzAlert = function dgzAlert(message, options) {
        return showAlert(message, options);
    };

    window.dgzAlert.notify = function notify(message, options) {
        return showAlert(message, options);
    };

    window.dgzAlert.fallback = fallbackAlert;
})();
