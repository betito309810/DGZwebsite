document.addEventListener('DOMContentLoaded', () => {
    const filterForm = document.getElementById('productsFilterForm');

    if (!filterForm) {
        return;
    }

    const pageField = filterForm.querySelector('input[name="page"]');
    const filterSelects = filterForm.querySelectorAll('select');
    const searchInput = filterForm.querySelector('input[name="search"]');
    const clearButton = filterForm.querySelector('[data-filter-clear]');

    const resetPage = () => {
        if (pageField) {
            pageField.value = '1';
        }
    };

    filterForm.addEventListener('submit', () => {
        resetPage();
    });

    filterSelects.forEach((select) => {
        select.addEventListener('change', resetPage);
    });

    const updateClearVisibility = () => {
        if (!searchInput || !clearButton) {
            return;
        }

        if (searchInput.value.trim() !== '') {
            clearButton.classList.add('is-visible');
        } else {
            clearButton.classList.remove('is-visible');
        }
    };

    if (searchInput) {
        searchInput.addEventListener('input', () => {
            resetPage();
            updateClearVisibility();
        });
        updateClearVisibility();
    }

    clearButton?.addEventListener('click', () => {
        if (!searchInput) {
            return;
        }
        searchInput.value = '';
        updateClearVisibility();
        resetPage();
        if (typeof filterForm.requestSubmit === 'function') {
            filterForm.requestSubmit();
        } else {
            filterForm.submit();
        }
        searchInput.focus();
    });
});
