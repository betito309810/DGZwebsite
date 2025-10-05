// Handles add-user form toggling and back navigation behaviour for the user management page.
function initializeUserManagementPage() {
    const toggleBtn = document.getElementById('toggleAddUser');
    const cancelBtn = document.getElementById('cancelAddUser');
    const section = document.getElementById('addUserSection');
    const backButton = document.getElementById('backButton');

    const toggleSection = function () {
        if (!section) {
            return;
        }
        section.classList.toggle('hidden');
    };

    if (toggleBtn) {
        toggleBtn.addEventListener('click', toggleSection);
    }

    if (cancelBtn) {
        cancelBtn.addEventListener('click', toggleSection);
    }

    if (backButton) {
        backButton.addEventListener('click', function () {
            window.history.back();
        });
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeUserManagementPage);
} else {
    initializeUserManagementPage();
}
