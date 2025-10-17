(function () {
    var POLL_INTERVAL_MS = 10000;
    var tableBody = document.querySelector('[data-orders-table-body]');
    var table = document.querySelector('[data-orders-table]');
    var errorBanner = document.querySelector('[data-orders-error]');
    var errorText = errorBanner ? errorBanner.querySelector('[data-orders-error-text]') : null;
    var retryLink = errorBanner ? errorBanner.querySelector('[data-orders-retry]') : null;

    if (!tableBody || !table) {
        return;
    }

    if (typeof window.Promise !== 'function') {
        return;
    }

    var feedUrl = table.dataset.ordersFeedUrl;
    if (!feedUrl) {
        return;
    }

    var resolvedFeedUrl = resolveFeedUrl(feedUrl);
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
     * Cancel any scheduled poll so we do not keep timers alive unnecessarily.
     */
    function cancelScheduledPoll() {
        if (pollTimeoutId) {
            clearTimeout(pollTimeoutId);
            pollTimeoutId = null;
        }
    }

    /**
     * Normalize the feed URL so it works across localhost installs and hosted deployments.
     *
     * @param {string} rawUrl Original data attribute from the table element.
     * @returns {string} Absolute URL used for AJAX requests.
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
     * Perform an HTTP request that always returns JSON regardless of the browser's Fetch support.
     *
     * @param {string} url Fully qualified endpoint to contact.
     * @returns {Promise<object>} Resolves with the parsed JSON payload.
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
                reject(new Error('Network error while loading orders feed.'));
            };
            xhr.send();
        });
    }

    /**
     * Hide the inline banner that alerts staff about polling issues.
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
     * Surface an inline banner that explains why the table failed to refresh.
     *
     * @param {string} message Human-readable explanation for the failure.
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
     * Retrieve the latest order and restock data from the server.
     *
     * @returns {Promise<Array<object>>} Resolves with an array of order rows.
     */
    function fetchOrders() {
        return requestJson(resolvedFeedUrl).then(function (payload) {
            if (!payload || payload.success !== true || !Array.isArray(payload.data)) {
                throw new Error('Orders feed returned an unexpected payload.');
            }

            return payload.data;
        });
    }

    /**
     * Convert a single order row into the HTML snippet that will populate the table.
     *
     * @param {Object} order The order record from the feed.
     * @returns {string} HTML table row.
     */
    function buildRowMarkup(order) {
        var orderType = typeof order.order_type === 'string' ? order.order_type.toLowerCase() : '';
        var typeBadge = orderType === 'incoming'
            ? "<span class=\"badge badge-success\">Incoming</span>"
            : "<span class=\"badge badge-warning\">Restock</span>";
        var productName = order && order.product_name ? order.product_name : '';
        var requester = order && order.username ? order.username : '';
        var createdAt = order && order.created_at ? order.created_at : '';
        var quantity = order && typeof order.quantity !== 'undefined' ? order.quantity : '';

        return [
            '<tr>',
            '    <td>' + String(order && order.id ? order.id : '') + '</td>',
            '    <td>' + escapeHtml(productName) + '</td>',
            '    <td>' + String(quantity) + '</td>',
            '    <td>' + typeBadge + '</td>',
            '    <td>' + escapeHtml(requester) + '</td>',
            '    <td>' + escapeHtml(createdAt) + '</td>',
            '</tr>'
        ].join('');
    }

    /**
     * Update the table body with the latest data, only re-rendering when the feed changes.
     *
     * @param {Array<Object>} orders The latest orders from the feed.
     */
    function renderOrders(orders) {
        var signature = JSON.stringify(orders);
        if (signature === lastPayloadSignature) {
            return;
        }

        lastPayloadSignature = signature;
        var rows = orders.map(buildRowMarkup).join('');
        tableBody.innerHTML = rows;
    }

    /**
     * Escape angle brackets and ampersands to keep user-provided content safe in the DOM.
     *
     * @param {string} value Arbitrary string to escape.
     * @returns {string} Escaped string for safe HTML insertion.
     */
    function escapeHtml(value) {
        var div = document.createElement('div');
        div.textContent = value || '';
        return div.innerHTML;
    }

    /**
     * Schedule the next poll tick to avoid overlapping requests.
     */
    function scheduleNextPoll() {
        cancelScheduledPoll();

        if (typeof document !== 'undefined' && document.hidden) {
            return;
        }

        pollTimeoutId = window.setTimeout(function () {
            poll(false);
        }, POLL_INTERVAL_MS);
    }

    /**
     * Poll the server for updates and push them into the table.
     *
     * @param {boolean} isManual Whether the poll was triggered by the retry link.
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

        fetchOrders()
            .then(function (orders) {
                hideError();
                renderOrders(orders);
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
     * When the document becomes visible again, immediately refresh the data.
     */
    function handleVisibilityChange() {
        if (typeof document !== 'undefined' && document.hidden) {
            cancelScheduledPoll();
            return;
        }

        poll(true);
    }

    /**
     * Ensure a focus event also triggers a refresh for browsers that skip visibility events.
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
