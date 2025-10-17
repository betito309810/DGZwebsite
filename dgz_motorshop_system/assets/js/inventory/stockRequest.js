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

            const pendingEmpty = document.querySelector('[data-restock-pending-empty]');
            const pendingTableWrapper = document.querySelector('[data-restock-pending-table]');
            const pendingTableBody = document.querySelector('[data-restock-pending-body]');
            const historyEmpty = document.querySelector('[data-restock-history-empty]');
            const historyTableWrapper = document.querySelector('[data-restock-history-table]');
            const historyTableBody = document.querySelector('[data-restock-history-body]');

            let isRefreshing = false;

            async function refreshRestockRequests() {
                if (isRefreshing) {
                    return;
                }

                if (!pendingTableBody && !historyTableBody) {
                    return;
                }

                isRefreshing = true;
                try {
                    const response = await fetch('api/restock_requests_feed.php', {
                        headers: { Accept: 'application/json' },
                    });

                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}`);
                    }

                    const payload = await response.json();
                    if (!payload || payload.success !== true) {
                        return;
                    }

                    const pendingCount = Number(payload.pending_count) || 0;
                    const historyCount = Number(payload.history_count) || 0;

                    if (pendingTableBody) {
                        pendingTableBody.innerHTML = payload.pending_html || '';
                    }

                    if (pendingTableWrapper) {
                        pendingTableWrapper.style.display = pendingCount > 0 ? 'block' : 'none';
                    }

                    if (pendingEmpty) {
                        pendingEmpty.style.display = pendingCount > 0 ? 'none' : '';
                    }

                    if (historyTableBody) {
                        historyTableBody.innerHTML = payload.history_html || '';
                    }

                    if (historyTableWrapper) {
                        historyTableWrapper.style.display = historyCount > 0 ? 'block' : 'none';
                    }

                    if (historyEmpty) {
                        historyEmpty.style.display = historyCount > 0 ? 'none' : '';
                    }
                } catch (error) {
                    console.error('Unable to refresh restock requests.', error);
                } finally {
                    isRefreshing = false;
                }
            }

            window.addEventListener('dgz:stock-requests-refresh', refreshRestockRequests);
        });