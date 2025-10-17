(function () {
    'use strict';

    var sidebar = document.getElementById('sidebar');
    if (!sidebar) {
        return;
    }

    var feedUrl = sidebar.getAttribute('data-sidebar-counts-feed');
    if (!feedUrl) {
        return;
    }

    if (typeof window.Promise !== 'function') {
        return;
    }

    var POLL_INTERVAL_MS = 15000;
    var BACKGROUND_POLL_INTERVAL_MS = 45000;
    var pollTimeoutId = null;
    var isPolling = false;
    var lastPayloadSignature = '';
    var resolvedFeedUrl = resolveFeedUrl(feedUrl);
    var previousCounts = null;
    var audioContext = null;
    var audioUnlockBound = false;

    /**
     * Convert the provided feed path into an absolute URL.
     *
     * @param {string} rawUrl Original feed path from the sidebar dataset.
     * @returns {string} Fully qualified URL used for polling.
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
     * Cancel any scheduled poll timer so we do not trigger duplicate requests.
     */
    function cancelScheduledPoll() {
        if (pollTimeoutId) {
            clearTimeout(pollTimeoutId);
            pollTimeoutId = null;
        }
    }

    /**
     * Queue the next poll tick while respecting the page visibility state.
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
     * Perform a JSON request with a Fetch fallback for legacy browsers.
     *
     * @param {string} url Endpoint that returns a JSON payload.
     * @returns {Promise<object>} Resolves with the parsed JSON data.
     */
    function requestJson(url) {
        if (typeof window.fetch === 'function') {
            return window.fetch(url, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
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
                reject(new Error('Network error while loading sidebar counts.'));
            };
            xhr.send();
        });
    }

    /**
     * Update the sidebar badge text for the supplied selector.
     *
     * @param {string} selector Attribute selector that locates the badge span.
     * @param {number} count Latest count to display.
     */
    function updateBadge(selector, count) {
        var badge = sidebar.querySelector(selector);
        if (!badge) {
            return;
        }

        var value = typeof count === 'number' && count > 0 ? String(count) : '';

        if (!value) {
            badge.textContent = '';
            badge.setAttribute('hidden', 'hidden');
            return;
        }

        badge.textContent = value;
        badge.removeAttribute('hidden');
    }

    /**
     * Update the notification bell badge so staff can see unread counts anywhere.
     *
     * @param {number} count Latest unread notification count.
     */
    function updateNotificationBell(count) {
        var bell = document.getElementById('notifBell');
        if (!bell) {
            return;
        }

        var badge = bell.querySelector('.badge');
        var value = typeof count === 'number' && count > 0 ? String(count) : '';

        if (!badge && value) {
            badge = document.createElement('span');
            badge.className = 'badge';
            badge.setAttribute('aria-hidden', 'true');
            bell.appendChild(badge);
        }

        if (!badge) {
            return;
        }

        if (!value) {
            badge.remove();
            return;
        }

        badge.textContent = value;
    }

    /**
     * Apply the latest counts to the sidebar and notification bell.
     *
     * @param {{online_orders:number, restock_requests:number, inventory_notifications:number}} counts
     */
    function renderCounts(counts) {
        if (!counts || typeof counts !== 'object') {
            throw new Error('Sidebar counts payload is invalid.');
        }

        var signature = JSON.stringify(counts);
        var hasIncrease = previousCounts && hasCountIncrease(previousCounts, counts);
        if (signature === lastPayloadSignature) {
            return;
        }

        lastPayloadSignature = signature;
        previousCounts = {
            online_orders: sanitizeCount(counts.online_orders),
            restock_requests: sanitizeCount(counts.restock_requests),
            inventory_notifications: sanitizeCount(counts.inventory_notifications)
        };

        updateBadge('[data-sidebar-pos-count]', counts.online_orders || 0);
        updateBadge('[data-sidebar-stock-count]', counts.restock_requests || 0);
        updateNotificationBell(counts.inventory_notifications || 0);

        if (hasIncrease) {
            playNotificationSound();
        }
    }

    /**
     * Ensure the provided count resolves to a safe number for comparisons.
     *
     * @param {number} value Raw count from the feed payload.
     * @returns {number} Sanitized numeric count.
     */
    function sanitizeCount(value) {
        return typeof value === 'number' && isFinite(value) ? value : 0;
    }

    /**
     * Determine whether any of the badge counts increased versus the previous poll.
     *
     * @param {{online_orders:number, restock_requests:number, inventory_notifications:number}} previous Previous feed snapshot.
     * @param {{online_orders:number, restock_requests:number, inventory_notifications:number}} next Latest feed snapshot.
     * @returns {boolean} True when any tracked count has increased.
     */
    function hasCountIncrease(previous, next) {
        if (!previous || !next) {
            return false;
        }

        var nextOrders = sanitizeCount(next.online_orders);
        var nextRestock = sanitizeCount(next.restock_requests);
        var nextInventory = sanitizeCount(next.inventory_notifications);

        return nextOrders > sanitizeCount(previous.online_orders)
            || nextRestock > sanitizeCount(previous.restock_requests)
            || nextInventory > sanitizeCount(previous.inventory_notifications);
    }

    /**
     * Play a short audible cue whenever the notification counts increase.
     */
    function playNotificationSound() {
        var context = getAudioContext();
        if (!context) {
            return;
        }

        if (typeof context.state === 'string' && context.state === 'suspended' && typeof context.resume === 'function') {
            context.resume().then(function () {
                triggerChime(context);
            }).catch(function () {
                // Ignore resume errors; the browser may still be locked until user interaction.
            });
            return;
        }

        triggerChime(context);
    }

    /**
     * Lazily create or return the shared AudioContext used for the notification chime.
     *
     * @returns {AudioContext|null} Active audio context instance when supported.
     */
    function getAudioContext() {
        var AudioContextConstructor = window.AudioContext || window.webkitAudioContext;
        if (!AudioContextConstructor) {
            return null;
        }

        if (!audioContext) {
            audioContext = new AudioContextConstructor();
            bindAudioUnlockHandlers();
        }

        return audioContext;
    }

    /**
     * Bind minimal input listeners that unlock the AudioContext on the first user interaction.
     */
    function bindAudioUnlockHandlers() {
        if (audioUnlockBound || !audioContext) {
            return;
        }

        audioUnlockBound = true;

        var unlock = function () {
            if (!audioContext) {
                return;
            }

            if (typeof audioContext.resume === 'function' && audioContext.state === 'suspended') {
                audioContext.resume();
            }

            if (audioContext.state === 'running') {
                document.removeEventListener('click', unlock, true);
                document.removeEventListener('keydown', unlock, true);
                document.removeEventListener('touchend', unlock, true);
            }
        };

        document.addEventListener('click', unlock, true);
        document.addEventListener('keydown', unlock, true);
        document.addEventListener('touchend', unlock, true);
    }

    /**
     * Generate a short sine wave tone to serve as the notification alert.
     *
     * @param {AudioContext} context Active audio context used for playback.
     */
    function triggerChime(context) {
        try {
            var oscillator = context.createOscillator();
            var gain = context.createGain();

            oscillator.type = 'sine';
            oscillator.frequency.setValueAtTime(880, context.currentTime);

            gain.gain.setValueAtTime(0.0001, context.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.05, context.currentTime + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.0001, context.currentTime + 0.5);

            oscillator.connect(gain);
            gain.connect(context.destination);

            oscillator.start(context.currentTime);
            oscillator.stop(context.currentTime + 0.5);
        } catch (error) {
            console.error(error);
        }
    }

    /**
     * Poll the server for updated counts and refresh the UI when they change.
     *
     * @param {boolean} isManual Indicates whether the poll was manually triggered.
     */
    function poll(isManual) {
        if (isPolling) {
            return;
        }

        if (isManual) {
            cancelScheduledPoll();
        }

        isPolling = true;

        requestJson(resolvedFeedUrl)
            .then(function (payload) {
                if (!payload || payload.success !== true || typeof payload.data !== 'object') {
                    throw new Error('Sidebar counts feed returned an unexpected payload.');
                }

                renderCounts(payload.data);
            })
            .catch(function (error) {
                if (error && error.status === 401) {
                    cancelScheduledPoll();
                    document.removeEventListener('visibilitychange', handleVisibilityChange);
                    window.removeEventListener('focus', handleWindowFocus);
                    return;
                }

                console.error(error);
            })
            .then(function () {
                isPolling = false;
                scheduleNextPoll();
            });
    }

    /**
     * Adjust polling cadence when the page visibility changes and trigger fast refreshes on return.
     */
    function handleVisibilityChange() {
        if (typeof document !== 'undefined' && document.hidden) {
            scheduleNextPoll();
            return;
        }

        poll(true);
    }

    /**
     * Trigger an immediate refresh when the window regains focus.
     */
    function handleWindowFocus() {
        if (typeof document !== 'undefined' && document.hidden) {
            return;
        }

        poll(true);
    }

    document.addEventListener('visibilitychange', handleVisibilityChange);
    window.addEventListener('focus', handleWindowFocus);

    poll(true);
})();
