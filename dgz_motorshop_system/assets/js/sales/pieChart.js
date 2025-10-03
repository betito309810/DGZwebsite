(function (window, document) {
    const apiEndpoint = 'chart_data.php';
    const chartId = 'salesPieChart';

    document.addEventListener('DOMContentLoaded', initSalesTrend);

    let pieChart = null;

    function initSalesTrend() {
        if (!window.SalesPeriodFilters) {
            console.error('SalesPeriodFilters helper is missing.');
            return;
        }

        const canvas = document.getElementById(chartId);
        const legendContainer = document.getElementById('chartLegend');
        const periodSelect = document.getElementById('trendPeriod');
        const valueInput = document.getElementById('trendPicker');
        const labelElement = document.getElementById('trendPickerLabel');
        const hintElement = document.getElementById('trendRangeHint');

        if (!canvas || !legendContainer || !periodSelect || !valueInput || !labelElement) {
            return;
        }

        const ctx = canvas.getContext('2d');
        const filters = window.SalesPeriodFilters.create({
            periodSelect,
            valueInput,
            labelElement,
            hintElement,
            onChange: (state) => fetchTrendData(state, filters, { ctx, legendContainer }),
        });

        fetchTrendData(filters.getState(), filters, { ctx, legendContainer });
    }

    async function fetchTrendData(state, filters, refs) {
        const { period, value } = state;
        const { ctx, legendContainer } = refs;

        try {
            legendContainer.innerHTML = '<div class="legend-empty">Loading...</div>';

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
            const items = Array.isArray(payload.items) ? payload.items : [];
            updateChart(ctx, items);
            renderLegend(legendContainer, items);

            const rangeText = window.SalesPeriodFilters.formatRangeText(payload.range);
            filters.setRangeHint(rangeText);
        } catch (error) {
            console.error('Failed to load sales trend data', error);
            renderLegend(legendContainer, []);
            filters.setRangeHint('Unable to load data');
        }
    }

    function updateChart(ctx, items) {
        const labels = items.map((item) => item.product_name);
        const data = items.map((item) => item.total_qty);
        const colors = items.map((item) => item.color);

        if (!pieChart) {
            pieChart = new window.Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels,
                    datasets: [
                        {
                            data,
                            backgroundColor: colors,
                            borderColor: '#ffffff',
                            borderWidth: 2,
                        },
                    ],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '55%',
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label(context) {
                                    const total = context.raw;
                                    const label = context.label || '';
                                    return `${label}: ${total}`;
                                },
                            },
                        },
                    },
                },
            });
        } else {
            pieChart.data.labels = labels;
            pieChart.data.datasets[0].data = data;
            pieChart.data.datasets[0].backgroundColor = colors;
            pieChart.update();
        }
    }

    function renderLegend(container, items) {
        container.innerHTML = '';

        if (!items.length) {
            container.innerHTML = '<div class="legend-empty">No sales data for the selected period</div>';
            return;
        }

        items.forEach((item) => {
            const row = document.createElement('div');
            row.className = 'legend-item';

            const swatch = document.createElement('span');
            swatch.className = 'legend-swatch';
            swatch.style.backgroundColor = item.color;

            const name = document.createElement('span');
            name.className = 'legend-name';
            name.textContent = item.product_name;

            const count = document.createElement('span');
            count.className = 'legend-count';
            count.textContent = `${item.total_qty} sold`;

            row.appendChild(swatch);
            row.appendChild(name);
            row.appendChild(count);

            container.appendChild(row);
        });
    }
})(window, document);
