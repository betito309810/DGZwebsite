(function () {
    var POLL_INTERVAL_MS = 10000;
    var BACKGROUND_POLL_INTERVAL_MS = 30000;
    var root = document.querySelector('[data-restock-requests-root]');
    if (!root) {
        return;
    }

    if (typeof window.Promise !== 'function') {
        return;
    }

    var feedUrl = root.getAttribute('data-restock-feed-url');
    if (!feedUrl) {
        return;
    }

    var resolvedFeedUrl = resolveFeedUrl(feedUrl);
    var canManage = root.getAttribute('data-can-manage') === '1';
    var tabsSection = root.querySelector('[data-restock-tabs]');
    var allEmpty = root.querySelector('[data-restock-empty-all]');
    var pendingBody = root.querySelector('[data-restock-pending-body]');
    var pendingEmpty = root.querySelector('[data-restock-pending-empty]');
    var pendingTableWrapper = root.querySelector('[data-restock-pending-table-wrapper]');
    var historyBody = root.querySelector('[data-restock-history-body]');
    var historyEmpty = root.querySelector('[data-restock-history-empty]');
    var historyTableWrapper = root.querySelector('[data-restock-history-table-wrapper]');
    var errorBanner = root.querySelector('[data-restock-error]');
    var errorText = errorBanner ? errorBanner.querySelector('[data-restock-error-text]') : null;
    var retryLink = errorBanner ? errorBanner.querySelector('[data-restock-retry]') : null;

    if (!tabsSection || !allEmpty || !pendingBody || !pendingEmpty || !pendingTableWrapper || !historyBody || !historyEmpty || !historyTableWrapper) {
        return;
    }

    var defaultErrorMessage = errorText ? errorText.textContent : '';
    var lastPayloadSignature = '';
    var pollTimeoutId = null;
    var isPolling = false;

    if (retryLink) {
        retryLink.addEventListener('click', function (event) {
            event.preventDefault();
            poll(true);
        });
    }

    /**
     * Cancel any pending poll timer so we can safely pause polling.
     */
    function cancelScheduledPoll() {
        if (pollTimeoutId) {
            clearTimeout(pollTimeoutId);
            pollTimeoutId = null;
        }
    }

    /**
     * Normalize feed URLs so polling works when the project is hosted in a subdirectory.
     *
     * @param {string} rawUrl Data attribute pulled from the markup.
     * @returns {string} Absolute URL.
     */
    function resolveFeedUrl(rawUrl) {
        try {
            return new URL(rawUrl, window.location.href).toString();
        } catch (error) {
            var link = document.createElement('a');
            link.href = rawUrl;
            return link.href;
        }
    }

    /**
     * Perform an HTTP request that always returns JSON regardless of Fetch availability.
     *
     * @param {string} url Endpoint to call.
     * @returns {Promise<object>} Resolves with parsed JSON.
     */
    function requestJson(url) {
        if (typeof window.fetch === 'function') {
            return window.fetch(url, {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            }).then(function (response) {
                if (!response.ok) {
                    var error = new Error('HTTP ' + response.status);
                    error.status = response.status;
                    throw error;
                }

                return response.json();
            });
        }

        return new Promise(function (resolve, reject) {
            if (typeof window.XMLHttpRequest !== 'function') {
                reject(new Error('Browser does not support AJAX.'));
                return;
            }

            var xhr = new XMLHttpRequest();
            xhr.open('GET', url, true);
            xhr.withCredentials = true;
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.onreadystatechange = function () {
                if (xhr.readyState !== XMLHttpRequest.DONE) {
                    return;
                }

                if (xhr.status >= 200 && xhr.status < 300) {
                    try {
                        resolve(JSON.parse(xhr.responseText));
                    } catch (parseError) {
                        reject(parseError);
                    }
                } else {
                    reject(new Error('HTTP ' + xhr.status));
                }
            };
            xhr.onerror = function () {
                reject(new Error('Network error while loading restock requests.'));
            };
            xhr.send();
        });
    }

    /**
     * Hide the error banner whenever a poll succeeds.
     */
    function hideError() {
        if (!errorBanner) {
            return;
        }

        if (errorText) {
            errorText.textContent = defaultErrorMessage || 'Live updates are temporarily unavailable.';
        }

        errorBanner.hidden = true;
    }

    /**
     * Display the inline error banner with an optional custom message.
     *
     * @param {string} message Human-readable explanation of the failure.
     */
    function showError(message) {
        if (!errorBanner) {
            return;
        }

        if (errorText) {
            errorText.textContent = message || defaultErrorMessage || 'Live updates are temporarily unavailable.';
        }

        errorBanner.hidden = false;
    }

    /**
     * Retrieve the latest restock request payload from the server.
     *
     * @returns {Promise<object>} Resolves with the pending/history data structure.
     */
    function fetchRestockData() {
        return requestJson(resolvedFeedUrl).then(function (payload) {
            if (!payload || payload.success !== true || typeof payload.data !== 'object') {
                throw new Error('Restock feed returned an unexpected payload.');
            }

            return payload.data;
        });
    }

    /**
     * Escape arbitrary strings for safe HTML insertion.
     *
     * @param {string} value Text pulled from the server response.
     * @returns {string} HTML-safe version of the string.
     */
    function escapeHtml(value) {
        var div = document.createElement('div');
        div.textContent = value || '';
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
        var sections = ['<span class="product-name">' + escapeHtml(row && row.product_name ? row.product_name : 'Product removed') + '</span>'];

        if (row && row.variant_label) {
            sections.push('<span class="product-variant">Variant: ' + escapeHtml(row.variant_label) + '</span>');
        }

        if (row && row.product_code) {
            sections.push('<span class="product-code">Code: ' + escapeHtml(row.product_code) + '</span>');
        }

        return '<div class="product-cell">' + sections.join('') + '</div>';
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

        var idValue = row && typeof row.id !== 'undefined' ? String(row.id) : '';

        return [
            '<td class="action-cell">',
            '    <form method="post" class="inline-form">',
            '        <input type="hidden" name="request_id" value="' + idValue + '">',
            '        <input type="hidden" name="request_action" value="approve">',
            '        <button type="submit" class="btn-approve">',
            '            <i class="fas fa-check"></i> Approve',
            '        </button>',
            '    </form>',
            '    <form method="post" class="inline-form">',
            '        <input type="hidden" name="request_id" value="' + idValue + '">',
            '        <input type="hidden" name="request_action" value="decline">',
            '        <button type="submit" class="btn-decline">',
            '            <i class="fas fa-times"></i> Decline',
            '        </button>',
            '    </form>',
            '</td>'
        ].join('');
    }

    /**
     * Parse a numeric value from the feed, falling back to zero when invalid.
     *
     * @param {*} value Potential numeric value.
     * @returns {number} Safe integer representation.
     */
    function toQuantity(value) {
        var number = Number(value);
        if (!isFinite(number)) {
            return 0;
        }

        return number;
    }

    /**
     * Convert a pending row into the HTML structure expected by the table body.
     */
    function buildPendingRow(row) {
        var cells = [
            '<td>' + escapeHtml(row && row.created_at_display ? row.created_at_display : 'N/A') + '</td>',
            '<td>' + buildProductCell(row) + '</td>',
            '<td>' + escapeHtml(row && row.category ? row.category : '') + '</td>',
            '<td>' + escapeHtml(row && row.brand ? row.brand : '') + '</td>',
            '<td>' + escapeHtml(row && row.supplier ? row.supplier : '') + '</td>',
            '<td>' + toQuantity(row && row.quantity_requested) + '</td>',
            '<td><span class="priority-badge ' + escapeHtml(row && row.priority_class ? row.priority_class : '') + '">' + escapeHtml(row && row.priority_label ? row.priority_label : '') + '</span></td>',
            '<td>' + escapeHtml(row && row.requester_name ? row.requester_name : 'Unknown') + '</td>',
            '<td class="notes-cell">' + buildNotesCell(row && row.notes ? row.notes : '') + '</td>'
        ];

        var actionCell = buildActionCell(row);
        if (actionCell) {
            cells.push(actionCell);
        }

        return '<tr>' + cells.join('') + '</tr>';
    }

    /**
     * Convert a history entry into the HTML structure expected by the history table body.
     */
    function buildHistoryRow(row) {
        var cells = [
            '<td>' + escapeHtml(row && row.created_at_display ? row.created_at_display : 'N/A') + '</td>',
            '<td>' + buildProductCell(row) + '</td>',
            '<td>' + escapeHtml(row && row.category ? row.category : '') + '</td>',
            '<td>' + escapeHtml(row && row.brand ? row.brand : '') + '</td>',
            '<td>' + escapeHtml(row && row.supplier ? row.supplier : '') + '</td>',
            '<td>' + toQuantity(row && row.quantity_requested) + '</td>',
            '<td><span class="priority-badge ' + escapeHtml(row && row.priority_class ? row.priority_class : '') + '">' + escapeHtml(row && row.priority_label ? row.priority_label : '') + '</span></td>',
            '<td>' + escapeHtml(row && row.requester_name ? row.requester_name : 'Unknown') + '</td>',
            '<td><span class="status-badge ' + escapeHtml(row && row.status_class ? row.status_class : '') + '">' + escapeHtml(row && row.status_label ? row.status_label : '') + '</span></td>',
            '<td>' + escapeHtml(row && row.status_user_name ? row.status_user_name : 'System') + '</td>',
            '<td>' + escapeHtml(row && row.reviewer_name ? row.reviewer_name : '') + '</td>'
        ];

        return '<tr>' + cells.join('') + '</tr>';
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
        if (!data || !Array.isArray(data.pending) || !Array.isArray(data.history)) {
            throw new Error('Restock feed data is incomplete.');
        }

        var signature = JSON.stringify({ pending: data.pending, history: data.history });
        if (signature === lastPayloadSignature) {
            return;
        }

        lastPayloadSignature = signature;

        pendingBody.innerHTML = data.pending.map(buildPendingRow).join('');
        historyBody.innerHTML = data.history.map(buildHistoryRow).join('');

        var hasAnyRequests = data.pending.length > 0 || data.history.length > 0;
        toggleSections(hasAnyRequests, data.pending.length, data.history.length);

        var badge = document.querySelector('[data-sidebar-stock-count]');
        if (badge) {
            var count = typeof data.badge_count !== 'undefined' ? data.badge_count : data.pending.length;
            badge.textContent = String(count);
            badge.hidden = !badge.textContent || badge.textContent === '0';
        }
    }

    /**
     * Schedule the next poll tick to avoid overlapping requests.
     */
    function scheduleNextPoll() {
        cancelScheduledPoll();

        var interval = POLL_INTERVAL_MS;
        if (typeof document !== 'undefined' && document.hidden) {
            interval = BACKGROUND_POLL_INTERVAL_MS;
        }

        pollTimeoutId = window.setTimeout(function () {
            poll(false);
        }, interval);
    }

    /**
     * Poll the server for updates and render the response.
     *
     * @param {boolean} isManual Whether the poll came from the retry link.
     */
    function poll(isManual) {
        if (isPolling) {
            return;
        }

        if (isManual && pollTimeoutId) {
            clearTimeout(pollTimeoutId);
            pollTimeoutId = null;
        }

        isPolling = true;

        fetchRestockData()
            .then(function (data) {
                hideError();
                renderRestockData(data);
            })
            .catch(function (error) {
                console.error(error);
                showError('Live updates are temporarily unavailable. ' + (error && error.message ? '(' + error.message + ')' : ''));
            })
            .then(function () {
                isPolling = false;
                scheduleNextPoll();
            });
    }

    /**
     * Slow down polling in hidden tabs and trigger an immediate refresh once the page returns.
     */
    function handleVisibilityChange() {
        if (typeof document !== 'undefined' && document.hidden) {
            scheduleNextPoll();
            return;
        }

        poll(true);
    }

    /**
     * Trigger a refresh when the window regains focus, covering older browsers.
     */
    function handleWindowFocus() {
        if (typeof document !== 'undefined' && document.hidden) {
            return;
        }

        poll(true);
    }

    document.addEventListener('visibilitychange', handleVisibilityChange);
    window.addEventListener('focus', handleWindowFocus);
    window.addEventListener('beforeunload', cancelScheduledPoll);

    poll(true);
})();
