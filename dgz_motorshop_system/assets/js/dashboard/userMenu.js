// Begin Toggle User Dropdown handler
function toggleDropdown() {
    const dropdown = document.getElementById('userDropdown');
    if (!dropdown) {
        return;
    }

    dropdown.classList.toggle('show');
}
// End Toggle User Dropdown handler

// Begin Toggle Sidebar handler
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    if (!sidebar) {
        return;
    }

    sidebar.classList.toggle('mobile-open');
}
// End Toggle Sidebar handler

// Begin Dashboard DOMContentLoaded wiring
// Sets up dropdown closing, profile modal, and responsive sidebar behaviors
// across admin pages that include the dashboard layout script.
document.addEventListener('DOMContentLoaded', function() {
    const userMenu = document.querySelector('.user-menu');
    const dropdown = document.getElementById('userDropdown');
    const profileButton = document.getElementById('profileTrigger');
    const profileModal = document.getElementById('profileModal');
    const profileModalClose = document.getElementById('profileModalClose');

    document.addEventListener('click', function(event) {
        if (userMenu && dropdown && !userMenu.contains(event.target)) {
            dropdown.classList.remove('show');
        }

        const sidebar = document.getElementById('sidebar');
        const toggle = document.querySelector('.mobile-toggle');

        if (
            window.innerWidth <= 768 &&
            sidebar && toggle &&
            !sidebar.contains(event.target) &&
            !toggle.contains(event.target)
        ) {
            sidebar.classList.remove('mobile-open');
        }
    });

    if (profileButton && profileModal) {
        const openProfileModal = function() {
            profileModal.classList.add('show');
            profileModal.setAttribute('aria-hidden', 'false');
            document.body.classList.add('modal-open');
        };

        const closeProfileModal = function() {
            profileModal.classList.remove('show');
            profileModal.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('modal-open');
        };

        profileButton.addEventListener('click', function(event) {
            event.preventDefault();
            if (dropdown) {
                dropdown.classList.remove('show');
            }
            openProfileModal();
        });

        if (profileModalClose) {
            profileModalClose.addEventListener('click', function() {
                closeProfileModal();
            });
        }

        profileModal.addEventListener('click', function(event) {
            if (event.target === profileModal) {
                closeProfileModal();
            }
        });

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape' && profileModal.classList.contains('show')) {
                closeProfileModal();
            }
        });
    }
});
// End Dashboard DOMContentLoaded wiring
