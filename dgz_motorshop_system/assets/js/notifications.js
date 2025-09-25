(function() {
    function markNotificationsRead(bell, panel) {
        fetch('markNotificationsRead.php', {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        }).then(function(response) {
            if (!response.ok) {
                throw new Error('Failed request');
            }
            var badge = bell.querySelector('.badge');
            if (badge) {
                badge.remove();
            }
            panel.querySelectorAll('.notif-item.unread').forEach(function(item) {
                item.classList.remove('unread');
            });
        }).catch(function() {
            // Intentionally silent; badge stays if update fails.
        });
    }

    function initNotificationMenu() {
        var bell = document.getElementById('notifBell');
        var panel = document.getElementById('notifDropdown');
        if (!bell || !panel) {
            return;
        }

        var dropdown = document.getElementById('userDropdown');
        var notificationsMarkedRead = false;

        bell.addEventListener('click', function(event) {
            event.preventDefault();
            event.stopPropagation();

            if (dropdown) {
                dropdown.classList.remove('show');
            }

            var isOpening = !panel.classList.contains('show');
            panel.classList.toggle('show');

            if (isOpening && !notificationsMarkedRead) {
                notificationsMarkedRead = true;
                markNotificationsRead(bell, panel);
            }
        });

        document.addEventListener('click', function(event) {
            if (!panel.contains(event.target) && !bell.contains(event.target)) {
                panel.classList.remove('show');
            }
        });

        panel.addEventListener('click', function(event) {
            event.stopPropagation();
        });
    }

    document.addEventListener('DOMContentLoaded', initNotificationMenu);
})();
