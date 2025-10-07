(function (window, document) {
    document.addEventListener('DOMContentLoaded', initTopSellingFilters);

    function initTopSellingFilters() {
        if (!window.SalesPeriodFilters) {
            return;
        }

        const form = document.getElementById('topSellingFilters');
        const periodSelect = document.getElementById('topSellingPeriod');
        const valueInput = document.getElementById('topSellingPicker');
        const labelElement = document.getElementById('topSellingPickerLabel');
        const hintElement = document.getElementById('topSellingRangeHint');

        if (!form || !periodSelect || !valueInput || !labelElement) {
            return;
        }

        const initialPeriod = form.dataset.period || periodSelect.value || 'daily';
        const initialValue = form.dataset.value || valueInput.value || '';
        const initialHint = form.dataset.range || '';

        const filters = window.SalesPeriodFilters.create({
            periodSelect,
            valueInput,
            labelElement,
            hintElement,
            onChange: (state) => {
                periodSelect.value = state.period;
                valueInput.value = state.value;
                form.submit();
            },
        });

        filters.setPeriod(initialPeriod, initialValue);

        if (hintElement) {
            hintElement.textContent = initialHint;
        }
    }
})(window, document);
