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

            if (!description) {
                hintElement.textContent = initialHint || '';
                return;
            }

            const nextHint = formatRangeHint(description.rangeStart, description.rangeEnd);
            hintElement.textContent = nextHint;
            initialHint = nextHint;
        }

        function formatRangeHint(start, end) {
            const startDate = parseYMD(start);
            const endDate = parseYMD(end);

            if (!startDate && !endDate) {
                return initialHint || '';
            }

            const formattedStart = formatFriendlyDate(startDate);
            const formattedEnd = formatFriendlyDate(endDate);

            if (!formattedEnd || formattedStart === formattedEnd) {
                return formattedStart || formattedEnd || '';
            }

            if (!formattedStart) {
                return formattedEnd;
            }

            return `${formattedStart} - ${formattedEnd}`;
        }

        function parseYMD(value) {
            if (!value) {
                return null;
            }

            const parts = value.split('-').map((part) => parseInt(part, 10));
            if (parts.length !== 3 || parts.some((n) => Number.isNaN(n))) {
                return null;
            }

            return new Date(parts[0], parts[1] - 1, parts[2]);
        }

        function formatFriendlyDate(date) {
            if (!(date instanceof Date) || Number.isNaN(date.getTime())) {
                return '';
            }

            return date.toLocaleDateString('en-US', {
                month: 'long',
                day: 'numeric',
                year: 'numeric',
            });
        }
    }
})(window, document);
