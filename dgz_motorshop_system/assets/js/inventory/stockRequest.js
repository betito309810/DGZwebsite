document.addEventListener('DOMContentLoaded', function () {
            // file 2 start – stock requests page behavior bundle
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.transition = 'opacity 0.5s';
                    alert.style.opacity = '0';
                    setTimeout(() => alert.remove(), 500);
                }, 4000);
            });

            const tabButtons = document.querySelectorAll('.tab-btn');
            const tabPanels = document.querySelectorAll('.tab-panel');

            tabButtons.forEach(button => {
                button.addEventListener('click', () => {
                    const targetId = button.getAttribute('data-target');

                    tabButtons.forEach(btn => btn.classList.toggle('active', btn === button));
                    tabPanels.forEach(panel => {
                        panel.classList.toggle('active', panel.id === targetId);
                    });
                });
            });
            // file 2 end
        });