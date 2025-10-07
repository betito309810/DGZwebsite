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
        const selectedLabel = document.getElementById('topSellingSelectedLabel');

        if (!form || !periodSelect || !valueInput || !labelElement) {
            return;
        }

        const initialPeriod = form.dataset.period || periodSelect.value || 'daily';
        const initialValue = form.dataset.value || valueInput.value || '';
        let initialHint = form.dataset.range || '';

        const filters = window.SalesPeriodFilters.create({
            periodSelect,
            valueInput,
            labelElement,
            hintElement,
            onChange: (state) => {
                periodSelect.value = state.period;
                valueInput.value = state.value;
                const description = safeDescribe(state.period, state.value);
                updateSelectedLabel(description);
                updateHint(description);
                form.submit();
            },
        });

        filters.setPeriod(initialPeriod, initialValue);

        const initialDescription = safeDescribe(initialPeriod, initialValue);
        if (initialHint) {
            updateHint(initialHint);
        } else {
            updateHint(initialDescription);
        }

        updateSelectedLabel(initialDescription);

        function safeDescribe(period, value) {
            if (!window.SalesPeriodFilters || typeof window.SalesPeriodFilters.describe !== 'function') {
                return {};
            }

            try {
                return window.SalesPeriodFilters.describe(period, value);
            } catch (error) {
                console.error('Failed to describe top selling period', error);
                return {};
            }
        }

        function updateSelectedLabel(description) {
            if (selectedLabel && description && description.label) {
                selectedLabel.textContent = description.label;
            }
        }

        function updateHint(description) {
            if (!hintElement) {
                return;
            }

            if (typeof description === 'string') {
                hintElement.textContent = description;
                initialHint = description;
                return;
            }

            if (!description || !window.SalesPeriodFilters || typeof window.SalesPeriodFilters.formatRangeText !== 'function') {
                hintElement.textContent = initialHint || '';
                return;
            }

            const rangeText = window.SalesPeriodFilters.formatRangeText({
                start: description.rangeStart,
                end: description.rangeEnd,
            });
            const nextHint = rangeText || '';
            hintElement.textContent = nextHint;
            initialHint = nextHint;
        }
    }
})(window, document);
