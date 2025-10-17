(function () {
    const POLL_INTERVAL_MS = 10000;
    const root = document.querySelector('[data-restock-requests-root]');
    if (!root) {
        return;
    }

    const feedUrl = root.dataset.restockFeedUrl;
    if (!feedUrl) {
        return;
    }

    const canManage = root.dataset.canManage === '1';
    const tabsSection = root.querySelector('[data-restock-tabs]');
    const allEmpty = root.querySelector('[data-restock-empty-all]');
    const pendingBody = root.querySelector('[data-restock-pending-body]');
    const pendingEmpty = root.querySelector('[data-restock-pending-empty]');
    const pendingTableWrapper = root.querySelector('[data-restock-pending-table-wrapper]');
    const historyBody = root.querySelector('[data-restock-history-body]');
    const historyEmpty = root.querySelector('[data-restock-history-empty]');
    const historyTableWrapper = root.querySelector('[data-restock-history-table-wrapper]');

    if (!tabsSection || !allEmpty || !pendingBody || !pendingEmpty || !pendingTableWrapper || !historyBody || !historyEmpty || !historyTableWrapper) {
        return;
    }

    let lastPayloadSignature = '';

    /**
     * Retrieve the latest restock request data from the server.
     * Converts the feed URL to an absolute path so the script works on every route.
     */
    async function fetchRestockData() {
        const url = new URL(feedUrl, window.location.href).toString();
        const response = await fetch(url, {
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json'
            }
        });

        if (!response.ok) {
            throw new Error('Failed to load restock requests feed.');
        }

        const payload = await response.json();
        if (!payload.success || typeof payload.data !== 'object') {
            throw new Error('Restock feed returned an unexpected payload.');
        }

        return payload.data;
    }

    /**
     * Escape arbitrary strings for safe HTML insertion.
     *
     * @param {string} value Text pulled from the server response.
     * @returns {string} HTML-safe version of the string.
     */
    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = value;
        return div.innerHTML;
    }

    /**
     * Convert the notes field into HTML, substituting a placeholder when blank.
     *
     * @param {string} notes Raw notes text from the feed.
     * @returns {string} HTML snippet for the table cell.
     */
    function buildNotesCell(notes) {
        if (!notes) {
            return '<span class="muted">No notes</span>';
        }

        return escapeHtml(notes).replace(/\n/g, '<br>');
    }

    /**
     * Build the shared product details cell, including optional variant and code labels.
     *
     * @param {object} row Feed row containing product metadata.
     * @returns {string} HTML markup representing the product cell.
     */
    function buildProductCell(row) {
        const sections = [`<span class="product-name">${escapeHtml(row.product_name || 'Product removed')}</span>`];

        if (row.variant_label) {
            sections.push(`<span class="product-variant">Variant: ${escapeHtml(row.variant_label)}</span>`);
        }

        if (row.product_code) {
            sections.push(`<span class="product-code">Code: ${escapeHtml(row.product_code)}</span>`);
        }

        return `<div class="product-cell">${sections.join('')}</div>`;
    }

    /**
     * Generate the action buttons column when the viewer has admin permissions.
     *
     * @param {object} row Pending request row from the feed.
     * @returns {string} HTML for the action cell or an empty string when actions are hidden.
     */
    function buildActionCell(row) {
        if (!canManage) {
            return '';
        }

        const idValue = String(row.id ?? '');
        return `
            <td class="action-cell">
                <form method="post" class="inline-form">
                    <input type="hidden" name="request_id" value="${idValue}">
                    <input type="hidden" name="request_action" value="approve">
                    <button type="submit" class="btn-approve">
                        <i class="fas fa-check"></i> Approve
                    </button>
                </form>
                <form method="post" class="inline-form">
                    <input type="hidden" name="request_id" value="${idValue}">
                    <input type="hidden" name="request_action" value="decline">
                    <button type="submit" class="btn-decline">
                        <i class="fas fa-times"></i> Decline
                    </button>
                </form>
            </td>
        `;
    }

    /**
     * Convert a pending row into the HTML structure expected by the table body.
     */
    function buildPendingRow(row) {
        const cells = [
            `<td>${escapeHtml(row.created_at_display || 'N/A')}</td>`,
            `<td>${buildProductCell(row)}</td>`,
            `<td>${escapeHtml(row.category || '')}</td>`,
            `<td>${escapeHtml(row.brand || '')}</td>`,
            `<td>${escapeHtml(row.supplier || '')}</td>`,
            `<td>${Number.isFinite(row.quantity_requested) ? row.quantity_requested : 0}</td>`,
            `<td><span class="priority-badge ${escapeHtml(row.priority_class || '')}">${escapeHtml(row.priority_label || '')}</span></td>`,
            `<td>${escapeHtml(row.requester_name || 'Unknown')}</td>`,
            `<td class="notes-cell">${buildNotesCell(row.notes || '')}</td>`
        ];

        const actionCell = buildActionCell(row);
        if (actionCell) {
            cells.push(actionCell);
        }

        return `<tr>${cells.join('')}</tr>`;
    }

    /**
     * Convert a history entry into the HTML structure expected by the history table body.
     */
    function buildHistoryRow(row) {
        const cells = [
            `<td>${escapeHtml(row.created_at_display || 'N/A')}</td>`,
            `<td>${buildProductCell(row)}</td>`,
            `<td>${escapeHtml(row.category || '')}</td>`,
            `<td>${escapeHtml(row.brand || '')}</td>`,
            `<td>${escapeHtml(row.supplier || '')}</td>`,
            `<td>${Number.isFinite(row.quantity_requested) ? row.quantity_requested : 0}</td>`,
            `<td><span class="priority-badge ${escapeHtml(row.priority_class || '')}">${escapeHtml(row.priority_label || '')}</span></td>`,
            `<td>${escapeHtml(row.requester_name || 'Unknown')}</td>`,
            `<td><span class="status-badge ${escapeHtml(row.status_class || '')}">${escapeHtml(row.status_label || '')}</span></td>`,
            `<td>${escapeHtml(row.status_user_name || 'System')}</td>`,
            `<td>${escapeHtml(row.reviewer_name || '')}</td>`
        ];

        return `<tr>${cells.join('')}</tr>`;
    }

    /**
     * Update visibility for the empty states and tables based on the incoming payload.
     */
    function toggleSections(hasAnyRequests, pendingCount, historyCount) {
        allEmpty.hidden = hasAnyRequests;
        tabsSection.hidden = !hasAnyRequests;

        pendingEmpty.hidden = pendingCount > 0;
        pendingTableWrapper.hidden = pendingCount === 0;

        historyEmpty.hidden = historyCount > 0;
        historyTableWrapper.hidden = historyCount === 0;
    }

    /**
     * Push the server data into the DOM when the payload changes.
     */
    function renderRestockData(data) {
        if (!Array.isArray(data.pending) || !Array.isArray(data.history)) {
            throw new Error('Restock feed data is incomplete.');
        }

        const signature = JSON.stringify({ pending: data.pending, history: data.history });
        if (signature === lastPayloadSignature) {
            return;
        }

        lastPayloadSignature = signature;

        const pendingRows = data.pending.map(buildPendingRow).join('');
        pendingBody.innerHTML = pendingRows;

        const historyRows = data.history.map(buildHistoryRow).join('');
        historyBody.innerHTML = historyRows;

        const hasAnyRequests = data.pending.length > 0 || data.history.length > 0;
        toggleSections(hasAnyRequests, data.pending.length, data.history.length);

        const badge = document.querySelector('[data-sidebar-stock-count]');
        if (badge) {
            badge.textContent = String(data.badge_count ?? data.pending.length ?? 0);
            badge.hidden = !badge.textContent || badge.textContent === '0';
        }
    }

    /**
     * Poll the server for updates and render the response.
     */
    async function poll() {
        try {
            const data = await fetchRestockData();
            renderRestockData(data);
        } catch (error) {
            console.error(error);
        }
    }

    poll();
    setInterval(poll, POLL_INTERVAL_MS);
})();
