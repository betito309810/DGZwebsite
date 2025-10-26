(function () {
    const SSE_ENDPOINT = 'realtime.php';
    const LEADER_STORAGE_KEY = 'dgz-admin-sse-leader';
    const UPDATE_STORAGE_KEY = 'dgz-admin-sse-update';
    const BROADCAST_CHANNEL_NAME = 'dgz-admin-realtime';
    const LEADER_HEARTBEAT_MS = 4000;
    const LEADER_TIMEOUT_MS = 12000;
    const TAB_ID = `dgz-admin-${Date.now()}-${Math.random().toString(16).slice(2)}`;

    if (typeof window === 'undefined' || !('localStorage' in window) || !('EventSource' in window)) {
        return;
    }

    let isLeader = false;
    let heartbeatTimer = null;
    let eventSource = null;
    let lastAppliedSignature = null;

    const supportsBroadcastChannel = typeof window.BroadcastChannel === 'function';
    const broadcastChannel = supportsBroadcastChannel ? new BroadcastChannel(BROADCAST_CHANNEL_NAME) : null;

    function readLeaderRecord() {
        try {
            const value = window.localStorage.getItem(LEADER_STORAGE_KEY);
            return value ? JSON.parse(value) : null;
        } catch (error) {
            return null;
        }
    }

    function writeLeaderRecord() {
        try {
            window.localStorage.setItem(
                LEADER_STORAGE_KEY,
                JSON.stringify({ id: TAB_ID, timestamp: Date.now() })
            );
        } catch (error) {
            // Ignore write failures (e.g., storage full or disabled).
        }
    }

    function clearLeaderRecord() {
        try {
            const record = readLeaderRecord();
            if (record && record.id === TAB_ID) {
                window.localStorage.removeItem(LEADER_STORAGE_KEY);
            }
        } catch (error) {
            // Ignore storage errors.
        }
    }

    function broadcastUpdate(payload) {
        if (!payload) {
            return;
        }

        if (broadcastChannel) {
            try {
                broadcastChannel.postMessage({ type: 'update', payload });
            } catch (error) {
                // Ignore broadcast errors.
            }
        } else {
            try {
                window.localStorage.setItem(
                    UPDATE_STORAGE_KEY,
                    JSON.stringify({ sender: TAB_ID, time: Date.now(), payload })
                );
            } catch (error) {
                // Ignore storage errors.
            }
        }
    }

    function getSidebarLink(href) {
        return document.querySelector(`.nav-menu .nav-link[href="${href}"]`);
    }

    function isSidebarLinkActive(href) {
        const link = getSidebarLink(href);
        return !!(link && link.classList.contains('active'));
    }

    function createSidebarBadge(href, attr, count) {
        const link = getSidebarLink(href);
        if (!link) {
            return;
        }

        const badge = document.createElement('span');
        badge.className = 'nav-badge';
        badge.setAttribute(attr, '');
        badge.textContent = String(count);
        link.appendChild(badge);
    }

    function removeSidebarBadge(selector) {
        const badge = document.querySelector(selector);
        if (badge) {
            badge.remove();
        }
    }

    function updateSidebarBadge(selector, count, create) {
        const safeCount = Number.isFinite(Number(count)) ? Math.max(0, Number(count)) : 0;
        const badge = document.querySelector(selector);

        if (badge) {
            if (safeCount > 0) {
                badge.textContent = String(safeCount);
                badge.hidden = false;
            } else {
                badge.remove();
            }
            return;
        }

        if (safeCount > 0 && typeof create === 'function') {
            create(safeCount);
        }
    }

    function makeSnapshotSignature(payload) {
        const pos = payload && typeof payload === 'object' && payload.pos ? payload.pos : {};
        const stock = payload && typeof payload === 'object' && payload.stock ? payload.stock : {};
        const parts = [
            Number(pos.latestId) || 0,
            Number(pos.pendingCount) || 0,
            Number(stock.latestId) || 0,
            Number(stock.pendingCount) || 0,
        ];
        return parts.join('|');
    }

    function applyRealtimePayload(payload) {
        if (!payload || typeof payload !== 'object') {
            return;
        }

        const signature = makeSnapshotSignature(payload);
        if (signature === lastAppliedSignature) {
            return;
        }
        lastAppliedSignature = signature;

        const pos = payload.pos || {};
        const stock = payload.stock || {};

        // Hide POS badge when the POS page is the active sidebar item
        if (isSidebarLinkActive('pos.php')) {
            removeSidebarBadge('[data-sidebar-pos-count]');
        } else {
            updateSidebarBadge('[data-sidebar-pos-count]', pos.pendingCount, function (count) {
                createSidebarBadge('pos.php', 'data-sidebar-pos-count', count);
            });
        }

        updateSidebarBadge('[data-sidebar-stock-count]', stock.pendingCount, function (count) {
            createSidebarBadge('stockRequests.php', 'data-sidebar-stock-count', count);
        });

        try {
            window.dispatchEvent(new CustomEvent('dgz:online-orders-refresh', { detail: pos }));
        } catch (error) {
            // Ignore custom event errors.
        }

        try {
            window.dispatchEvent(new CustomEvent('dgz:stock-requests-refresh', { detail: stock }));
        } catch (error) {
            // Ignore custom event errors.
        }
    }

    function startEventSource() {
        if (eventSource) {
            return;
        }

        eventSource = new EventSource(SSE_ENDPOINT);
        eventSource.addEventListener('update', function (event) {
            if (!event || !event.data) {
                return;
            }

            try {
                const payload = JSON.parse(event.data);
                broadcastUpdate(payload);
                applyRealtimePayload(payload);
            } catch (error) {
                console.error('Failed to parse SSE payload', error);
            }
        });

        eventSource.onerror = function () {
            // Let EventSource retry automatically but surface minimal info for debugging.
            console.debug('Admin SSE connection encountered an error. Retrying...');
        };
    }

    function stopEventSource() {
        if (!eventSource) {
            return;
        }

        try {
            eventSource.close();
        } catch (error) {
            // Ignore close errors.
        }
        eventSource = null;
    }

    function becomeLeader() {
        if (isLeader) {
            return;
        }

        isLeader = true;
        writeLeaderRecord();
        startEventSource();

        heartbeatTimer = window.setInterval(function () {
            writeLeaderRecord();
        }, LEADER_HEARTBEAT_MS);
    }

    function resignLeadership(removeRecord) {
        if (!isLeader) {
            if (removeRecord) {
                clearLeaderRecord();
            }
            return;
        }

        isLeader = false;
        if (heartbeatTimer) {
            window.clearInterval(heartbeatTimer);
            heartbeatTimer = null;
        }
        stopEventSource();

        if (removeRecord) {
            clearLeaderRecord();
        }
    }

    function evaluateLeadership() {
        const record = readLeaderRecord();
        const now = Date.now();

        if (!record) {
            becomeLeader();
            return;
        }

        if (record.id === TAB_ID) {
            if (!isLeader) {
                becomeLeader();
            }
            return;
        }

        if (now - Number(record.timestamp || 0) > LEADER_TIMEOUT_MS) {
            becomeLeader();
            return;
        }

        if (isLeader) {
            resignLeadership(false);
        }
    }

    function attemptLeadership() {
        const record = readLeaderRecord();
        const now = Date.now();

        if (!record || now - Number(record.timestamp || 0) > LEADER_TIMEOUT_MS) {
            writeLeaderRecord();
            becomeLeader();
        } else if (record.id === TAB_ID) {
            becomeLeader();
        }
    }

    if (broadcastChannel) {
        broadcastChannel.addEventListener('message', function (event) {
            if (!event || !event.data) {
                return;
            }

            if (event.data.type === 'update') {
                applyRealtimePayload(event.data.payload);
            }
        });
    }

    window.addEventListener('storage', function (event) {
        if (!event) {
            return;
        }

        if (event.key === LEADER_STORAGE_KEY) {
            evaluateLeadership();
            return;
        }

        if (!broadcastChannel && event.key === UPDATE_STORAGE_KEY && event.newValue) {
            try {
                const data = JSON.parse(event.newValue);
                if (data && data.sender !== TAB_ID) {
                    applyRealtimePayload(data.payload);
                }
            } catch (error) {
                // Ignore parse errors.
            }
        }
    });

    window.addEventListener('beforeunload', function () {
        resignLeadership(true);
    });

    window.addEventListener('pagehide', function (event) {
        if (event && event.persisted) {
            return;
        }
        resignLeadership(true);
    });

    attemptLeadership();

    if (!isLeader) {
        evaluateLeadership();
    }

    // One-time cleanup on load: if POS is the active page, hide its badge
    if (isSidebarLinkActive('pos.php')) {
        removeSidebarBadge('[data-sidebar-pos-count]');
    }

    window.setInterval(function () {
        if (isLeader) {
            writeLeaderRecord();
        } else {
            evaluateLeadership();
        }
    }, LEADER_HEARTBEAT_MS);
})();
