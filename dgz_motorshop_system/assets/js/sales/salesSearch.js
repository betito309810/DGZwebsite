document.addEventListener('DOMContentLoaded', () => {
    const searchForm = document.getElementById('salesSearchForm');
    if (!searchForm) {
        return;
    }

    const searchInput = searchForm.querySelector('[data-sales-search-input]');
    const clearButton = searchForm.querySelector('[data-sales-search-clear]');

    if (!searchInput || !clearButton) {
        return;
    }

    const updateClearVisibility = () => {
        if (searchInput.value.trim() !== '') {
            clearButton.classList.add('is-visible');
        } else {
            clearButton.classList.remove('is-visible');
        }
    };

    searchInput.addEventListener('input', () => {
        updateClearVisibility();
    });

    clearButton.addEventListener('click', () => {
        if (searchInput.value === '') {
            return;
        }

        searchInput.value = '';
        updateClearVisibility();

        if (typeof searchForm.requestSubmit === 'function') {
            searchForm.requestSubmit();
        } else {
            searchForm.submit();
        }

        searchInput.focus();
    });

    updateClearVisibility();
});
