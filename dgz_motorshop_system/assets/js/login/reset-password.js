// Toggle password visibility controls for the reset password form.
document.addEventListener('DOMContentLoaded', () => {
    const toggleButtons = document.querySelectorAll('.toggle-password');

    toggleButtons.forEach((button) => {
        const targetId = button.dataset.toggleTarget;
        const input = document.getElementById(targetId);

        if (!input) {
            button.disabled = true;
            return;
        }

        button.addEventListener('click', () => {
            const currentlyHidden = input.type === 'password';
            input.type = currentlyHidden ? 'text' : 'password';
            button.textContent = currentlyHidden ? 'Hide' : 'Show';
        });
    });
});
