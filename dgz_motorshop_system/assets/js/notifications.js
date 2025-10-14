(function() {
    function markNotificationsRead(bell, container, button, onComplete) {
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

            if (container) {
                container.querySelectorAll('.notif-item.unread').forEach(function(item) {
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
        var dropdown = document.getElementById('notifDropdown');
        if (!bell || !dropdown) {
            return;
        }

        var dropdownClose = document.getElementById('notifDropdownClose');
        var markAllButton = document.getElementById('notifMarkAll');
        var accountDropdown = document.getElementById('userDropdown');
        var detailModal = document.getElementById('notifDetailModal');
        var detailClose = document.getElementById('notifDetailClose');
        var detailOverlay = detailModal ? detailModal.querySelector('[data-detail-close]') : null;
        var defaultMarkLabel = markAllButton ? (markAllButton.dataset.defaultLabel = markAllButton.textContent.trim()) : '';
        var lastFocusedElement = null;

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

            var hasUnread = Boolean(dropdown.querySelector('.notif-item.unread'));
            markAllButton.disabled = !hasUnread;
            markAllButton.classList.toggle('is-disabled', !hasUnread);
            markAllButton.setAttribute('aria-disabled', hasUnread ? 'false' : 'true');

            if (!hasUnread) {
                markAllButton.textContent = markAllButton.dataset.emptyLabel || 'All caught up';
            } else {
                markAllButton.textContent = markAllButton.dataset.defaultLabel || defaultMarkLabel || 'Mark all as read';
            }
        }

        function openDropdown() {
            if (accountDropdown) {
                accountDropdown.classList.remove('show');
            }

            dropdown.classList.add('show');
            dropdown.setAttribute('aria-hidden', 'false');
            bell.setAttribute('aria-expanded', 'true');
            updateMarkAllState();

            var firstItem = dropdown.querySelector('.notif-item');
            if (firstItem) {
                safeFocus(firstItem);
            } else if (dropdownClose) {
                safeFocus(dropdownClose);
            }
        }

        function closeDropdown() {
            if (!dropdown.classList.contains('show')) {
                return;
            }

            dropdown.classList.remove('show');
            dropdown.setAttribute('aria-hidden', 'true');
            bell.setAttribute('aria-expanded', 'false');
            safeFocus(bell);
        }

        function formatStatus(value) {
            if (!value) {
                return '';
            }

            var normalized = String(value).trim();
            if (!normalized) {
                return '';
            }

            return normalized
                .split(/[_\s]+/)
                .map(function(part) {
                    return part.charAt(0).toUpperCase() + part.slice(1);
                })
                .join(' ');
        }

        function openDetailModal(item) {
            if (!detailModal || !item) {
                return;
            }

            var titleEl = document.getElementById('notifDetailTitle');
            var statusEl = document.getElementById('notifDetailStatus');
            var timeEl = document.getElementById('notifDetailTime');
            var messageEl = document.getElementById('notifDetailMessage');
            var productEl = document.getElementById('notifDetailProduct');

            if (titleEl) {
                titleEl.textContent = item.dataset.noteTitle || 'Notification';
            }

            if (statusEl) {
                var rawStatus = item.dataset.noteStatus || '';
                var statusText = formatStatus(rawStatus);
                statusEl.textContent = statusText;
                statusEl.classList.toggle('is-resolved', rawStatus.toLowerCase() === 'resolved');
            }

            if (timeEl) {
                timeEl.textContent = item.dataset.noteTime || '';
            }

            if (messageEl) {
                messageEl.textContent = item.dataset.noteMessage || '';
            }

            if (productEl) {
                var productName = item.dataset.noteProduct || '';
                productEl.textContent = productName ? 'Product: ' + productName : '';
            }

            item.classList.remove('unread');
            updateMarkAllState();

            lastFocusedElement = item;

            detailModal.classList.add('show');
            detailModal.setAttribute('aria-hidden', 'false');
            document.body.classList.add('notif-detail-open');
            safeFocus(detailClose);
        }

        function closeDetailModal() {
            if (!detailModal || !detailModal.classList.contains('show')) {
                return;
            }

            detailModal.classList.remove('show');
            detailModal.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('notif-detail-open');
            safeFocus(lastFocusedElement || bell);
            lastFocusedElement = null;
        }

        bell.addEventListener('click', function(event) {
            event.preventDefault();
            if (dropdown.classList.contains('show')) {
                closeDropdown();
            } else {
                openDropdown();
            }
        });

        if (dropdownClose) {
            dropdownClose.addEventListener('click', function(event) {
                event.preventDefault();
                closeDropdown();
            });
        }

        document.addEventListener('click', function(event) {
            if (!dropdown.classList.contains('show')) {
                return;
            }

            if (detailModal && detailModal.classList.contains('show')) {
                return;
            }

            if (dropdown.contains(event.target) || bell.contains(event.target)) {
                return;
            }

            closeDropdown();
        });

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                if (detailModal && detailModal.classList.contains('show')) {
                    closeDetailModal();
                    return;
                }

                if (dropdown.classList.contains('show')) {
                    closeDropdown();
                }
            }
        });

        dropdown.addEventListener('click', function(event) {
            var item = event.target.closest('.notif-item');
            if (!item) {
                return;
            }

            event.preventDefault();
            openDetailModal(item);
        });

        dropdown.addEventListener('keydown', function(event) {
            var item = event.target.closest('.notif-item');
            if (!item) {
                return;
            }

            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                openDetailModal(item);
            }
        });

        if (detailOverlay) {
            detailOverlay.addEventListener('click', function(event) {
                event.stopPropagation();
                closeDetailModal();
            });
        }

        if (detailClose) {
            detailClose.addEventListener('click', function(event) {
                event.preventDefault();
                closeDetailModal();
            });
        }

        if (detailModal) {
            detailModal.addEventListener('click', function(event) {
                event.stopPropagation();
            });
        }

        if (markAllButton) {
            markAllButton.addEventListener('click', function() {
                if (markAllButton.disabled || markAllButton.classList.contains('is-loading')) {
                    return;
                }

                markNotificationsRead(bell, dropdown, markAllButton, updateMarkAllState);
            });

            updateMarkAllState();
        }
    }

    document.addEventListener('DOMContentLoaded', initNotificationMenu);
})();
