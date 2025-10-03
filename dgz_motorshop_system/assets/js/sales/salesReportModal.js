document.addEventListener('DOMContentLoaded', () => {
    const modal = document.getElementById('salesReportModal');
    if (!modal) {
        return;
    }

    const openTrigger = document.getElementById('openSalesReport');
    const closeTrigger = document.getElementById('closeModal');

    const openModal = () => {
        modal.style.display = 'flex';
    };

    const closeModal = () => {
        modal.style.display = 'none';
    };

    openTrigger?.addEventListener('click', openModal);
    closeTrigger?.addEventListener('click', closeModal);

    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            closeModal();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && modal.style.display === 'flex') {
            closeModal();
        }
    });
});
