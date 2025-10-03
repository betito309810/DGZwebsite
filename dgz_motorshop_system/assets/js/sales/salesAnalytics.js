(function (window, document) {
    const apiEndpoint = 'sales_api.php';

    document.addEventListener('DOMContentLoaded', initAnalyticsWidget);

    function initAnalyticsWidget() {
        if (!window.SalesPeriodFilters) {
            console.error('SalesPeriodFilters helper is missing.');
            return;
        }

        const periodSelect = document.getElementById('analyticsPeriod');
        const valueInput = document.getElementById('analyticsPicker');
        const labelElement = document.getElementById('analyticsPickerLabel');
        const hintElement = document.getElementById('analyticsRangeHint');
        const totalSalesEl = document.getElementById('totalSales');
        const totalOrdersEl = document.getElementById('totalOrders');
        const widget = document.querySelector('.sales-widget');

        if (!periodSelect || !valueInput || !labelElement || !totalSalesEl || !totalOrdersEl || !widget) {
            return;
        }

        const filters = window.SalesPeriodFilters.create({
            periodSelect,
            valueInput,
            labelElement,
            hintElement,
            onChange: (state) => fetchAnalytics(state, filters, { widget, totalSalesEl, totalOrdersEl }),
        });

        fetchAnalytics(filters.getState(), filters, { widget, totalSalesEl, totalOrdersEl });
    }

    async function fetchAnalytics(state, filters, elements) {
        const { period, value } = state;
        const { widget, totalSalesEl, totalOrdersEl } = elements;

        toggleLoading(widget, true);

        try {
            const params = new URLSearchParams();
            params.set('period', period);
            if (value) {
                params.set('value', value);
            }

            const response = await fetch(`${apiEndpoint}?${params.toString()}`, {
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error(`Request failed: ${response.status}`);
            }

            const payload = await response.json();

            const totalSales = Number.parseFloat(payload.totalSales ?? '0');
            const totalOrders = Number.parseInt(payload.totalOrders ?? '0', 10);
            totalSalesEl.textContent = formatCurrency(isNaN(totalSales) ? 0 : totalSales);
            totalOrdersEl.textContent = Number.isFinite(totalOrders) ? totalOrders.toLocaleString('en-PH') : '0';

            const rangeText = window.SalesPeriodFilters.formatRangeText(payload.range);
            filters.setRangeHint(rangeText);
            widget.dataset.range = rangeText || '';
            widget.dataset.label = payload.label || '';
        } catch (error) {
            console.error('Failed to load sales analytics', error);
            totalSalesEl.textContent = '₱0.00';
            totalOrdersEl.textContent = '0';
            filters.setRangeHint('Unable to load data');
        } finally {
            toggleLoading(widget, false);
        }
    }

    function toggleLoading(widget, isLoading) {
        if (!widget) {
            return;
        }
        widget.classList.toggle('loading', isLoading);
    }

    function formatCurrency(amount) {
        return new Intl.NumberFormat('en-PH', {
            style: 'currency',
            currency: 'PHP',
            minimumFractionDigits: 2,
        }).format(amount || 0);
    }
})(window, document);
