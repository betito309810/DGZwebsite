<?php
// Pie Chart Widget Page
// This file renders a pie chart with a period selector (daily/weekly/monthly)
// and a legend listing item names with representative colors.

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Most Bought Items – Pie Chart</title>
    <link rel="stylesheet" href="../assets/piechart.css"/>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <div class="chart-card">
        <div class="chart-header">
            <h2>Most Bought Items</h2>
            <select id="timeFilter" aria-label="Select time period">
                <option value="daily">Daily</option>
                <option value="weekly">Weekly</option>
                <option value="monthly">Monthly</option>
            </select>
        </div>
        <div class="chart-canvas-wrap">
            <canvas id="salesPieChart"></canvas>
        </div>
        <div class="chart-legend" id="chartLegend" aria-live="polite"></div>
    </div>

<script>
(function(){
    const ctx = document.getElementById('salesPieChart').getContext('2d');
    let salesChart = null;

    function renderChart(payload) {
        const labels = payload.map(item => item.product_name);
        const values = payload.map(item => Number(item.total_qty));
        const colors = payload.map(item => item.color);

        if (salesChart) {
            salesChart.destroy();
        }

        salesChart = new Chart(ctx, {
            type: 'pie',
            data: {
                labels,
                datasets: [{
                    data: values,
                    backgroundColor: colors,
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (context) => {
                                const label = context.label || "";
                                const value = context.parsed || 0;
                                return `${label}: ${value}`;
                            }
                        }
                    }
                }
            }
        });

        // Render custom legend below the chart
        const legend = document.getElementById('chartLegend');
        legend.innerHTML = "";
        if (!payload.length) {
            legend.innerHTML = "<div class='legend-empty'>No data for this period.</div>";
            return;
        }
        payload.forEach((item) => {
            const row = document.createElement('div');
            row.className = 'legend-item';
            row.innerHTML = `
                <span class="legend-swatch" style="background:${item.color}"></span>
                <span class="legend-name">${item.product_name}</span>
                <span class="legend-count">${item.total_qty}</span>
            `;
            legend.appendChild(row);
        });
    }

    async function loadChartData(period='daily') {
        try {
            const res = await fetch(`chart_data.php?period=${encodeURIComponent(period)}`, { cache: 'no-store' });
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            const data = await res.json();
            renderChart(Array.isArray(data) ? data : []);
        } catch (e) {
            console.error(e);
            renderChart([]);
        }
    }

    document.getElementById('timeFilter').addEventListener('change', function() {
        loadChartData(this.value);
    });

    // Initial load
    loadChartData('daily');
})();
</script>
</body>
</html>
