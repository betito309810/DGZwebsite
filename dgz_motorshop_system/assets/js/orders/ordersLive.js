(function () {
    const POLL_INTERVAL_MS = 10000;
    const tableBody = document.querySelector('[data-orders-table-body]');
    const table = document.querySelector('[data-orders-table]');
    if (!tableBody || !table) {
        return;
    }

    const feedUrl = table.dataset.ordersFeedUrl;
    if (!feedUrl) {
        return;
    }

    let lastPayloadSignature = '';

    /**
     * Request the latest order and restock data from the server.
     * Converts the relative feed URL to an absolute URL before calling fetch.
     */
    async function fetchOrders() {
        const url = new URL(feedUrl, window.location.href).toString();
        const response = await fetch(url, {
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json'
            }
        });

        if (!response.ok) {
            throw new Error('Failed to retrieve orders feed');
        }

        const payload = await response.json();
        if (!payload.success || !Array.isArray(payload.data)) {
            throw new Error('Orders feed returned an unexpected payload');
        }

        return payload.data;
    }

    /**
     * Convert a single order row into the HTML snippet that will populate the table.
     *
     * @param {Object} order The order record from the feed.
     * @returns {string} HTML table row.
     */
    function buildRowMarkup(order) {
        const typeBadge = order.order_type === 'incoming'
            ? "<span class=\"badge badge-success\">Incoming</span>"
            : "<span class=\"badge badge-warning\">Restock</span>";

        return `
            <tr>
                <td>${order.id}</td>
                <td>${escapeHtml(order.product_name ?? '')}</td>
                <td>${order.quantity}</td>
                <td>${typeBadge}</td>
                <td>${escapeHtml(order.username ?? '')}</td>
                <td>${order.created_at}</td>
            </tr>
        `;
    }

    /**
     * Update the table body with the latest data, only re-rendering when the feed changes.
     *
     * @param {Array<Object>} orders The latest orders from the feed.
     */
    function renderOrders(orders) {
        const signature = JSON.stringify(orders);
        if (signature === lastPayloadSignature) {
            return;
        }

        lastPayloadSignature = signature;
        const rows = orders.map(buildRowMarkup).join('');
        tableBody.innerHTML = rows;
    }

    /**
     * Escape angle brackets and ampersands to keep user-provided content safe in the DOM.
     *
     * @param {string} value Arbitrary string to escape.
     * @returns {string} Escaped string for safe HTML insertion.
     */
    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = value;
        return div.innerHTML;
    }

    /**
     * Poll the server for updates and push them into the table.
     */
    async function poll() {
        try {
            const orders = await fetchOrders();
            renderOrders(orders);
        } catch (error) {
            console.error(error);
        }
    }

    poll();
    setInterval(poll, POLL_INTERVAL_MS);
})();
