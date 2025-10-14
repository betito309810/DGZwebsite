(function() {
    function markNotificationsRead(bell, modal, button, onComplete) {
        if (!button) {
            return;
        }

        var badge = bell ? bell.querySelector('.badge') : null;
        var defaultLabel = button.dataset.defaultLabel || button.textContent;
        var loadingLabel = button.dataset.loadingLabel || 'Marking...';
        var emptyLabel = button.dataset.emptyLabel || 'All caught up';

        button.disabled = true;
        button.classList.add('is-loading');
        button.setAttribute('aria-disabled', 'true');
        button.textContent = loadingLabel;

        fetch('markNotificationsRead.php', {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        }).then(function(response) {
            if (!response.ok) {
                throw new Error('Failed request');
            }

            if (badge) {
                badge.remove();
            }

            if (modal) {
                modal.querySelectorAll('.notif-item.unread').forEach(function(item) {
                    item.classList.remove('unread');
                });
            }

            button.classList.remove('is-loading');
            button.textContent = emptyLabel;

            if (typeof onComplete === 'function') {
                onComplete();
            }
        }).catch(function() {
            button.disabled = false;
            button.classList.remove('is-loading');
            button.setAttribute('aria-disabled', 'false');
            button.textContent = defaultLabel;
        });
    }

    function initNotificationMenu() {
        var bell = document.getElementById('notifBell');
        var modal = document.getElementById('notifModal');
        if (!bell || !modal) {
            return;
        }

        var closeButton = document.getElementById('notifModalClose');
        var overlay = modal.querySelector('[data-modal-close]');
        var markAllButton = document.getElementById('notifMarkAll');
        var dropdown = document.getElementById('userDropdown');
        var defaultMarkLabel = markAllButton ? (markAllButton.dataset.defaultLabel = markAllButton.textContent.trim()) : '';

        function safeFocus(element) {
            if (!element || typeof element.focus !== 'function') {
                return;
            }

            try {
                element.focus({ preventScroll: true });
            } catch (err) {
                element.focus();
            }
        }

        function updateMarkAllState() {
            if (!markAllButton) {
                return;
            }

            var hasUnread = Boolean(modal.querySelector('.notif-item.unread'));
            markAllButton.disabled = !hasUnread;
            markAllButton.classList.toggle('is-disabled', !hasUnread);
            markAllButton.setAttribute('aria-disabled', hasUnread ? 'false' : 'true');

            if (!hasUnread) {
                markAllButton.textContent = markAllButton.dataset.emptyLabel || 'All caught up';
            } else {
                markAllButton.textContent = markAllButton.dataset.defaultLabel || defaultMarkLabel || 'Mark all as read';
            }
        }

        function openModal() {
            if (dropdown) {
                dropdown.classList.remove('show');
            }

            modal.classList.add('show');
            modal.setAttribute('aria-hidden', 'false');
            bell.setAttribute('aria-expanded', 'true');
            document.body.classList.add('notif-modal-open');
            safeFocus(closeButton);
            updateMarkAllState();
        }

        function closeModal() {
            if (!modal.classList.contains('show')) {
                return;
            }

            modal.classList.remove('show');
            modal.setAttribute('aria-hidden', 'true');
            bell.setAttribute('aria-expanded', 'false');
            document.body.classList.remove('notif-modal-open');
            safeFocus(bell);
        }

        bell.addEventListener('click', function(event) {
            event.preventDefault();
            if (modal.classList.contains('show')) {
                closeModal();
            } else {
                openModal();
            }
        });

        if (overlay) {
            overlay.addEventListener('click', closeModal);
        }

        if (closeButton) {
            closeButton.addEventListener('click', closeModal);
        }

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape' && modal.classList.contains('show')) {
                closeModal();
            }
        });

        modal.addEventListener('click', function(event) {
            event.stopPropagation();
        });

        if (markAllButton) {
            markAllButton.addEventListener('click', function() {
                if (markAllButton.disabled || markAllButton.classList.contains('is-loading')) {
                    return;
                }

                markNotificationsRead(bell, modal, markAllButton, updateMarkAllState);
            });

            updateMarkAllState();
        }
    }

    document.addEventListener('DOMContentLoaded', initNotificationMenu);
})();
