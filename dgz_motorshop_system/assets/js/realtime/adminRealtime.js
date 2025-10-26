(function () {
    const SSE_ENDPOINT = 'realtime.php';
    const LEADER_STORAGE_KEY = 'dgz-admin-sse-leader';
    const UPDATE_STORAGE_KEY = 'dgz-admin-sse-update';
    const BROADCAST_CHANNEL_NAME = 'dgz-admin-realtime';
    const LEADER_HEARTBEAT_MS = 4000;
    const LEADER_TIMEOUT_MS = 12000;
    const TAB_ID = `dgz-admin-${Date.now()}-${Math.random().toString(16).slice(2)}`;
    const AudioContextClass = typeof window !== 'undefined'
        ? (window.AudioContext || window.webkitAudioContext)
        : null;

    if (typeof window === 'undefined' || !('localStorage' in window) || !('EventSource' in window)) {
        return;
    }

    let isLeader = false;
    let heartbeatTimer = null;
    let eventSource = null;
    let lastAppliedSignature = null;
    let lastRealtimeSnapshot = null;
    let audioContext = null;
    let audioUnlockHandlersBound = false;

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

    function getAudioContext() {
        if (!AudioContextClass) {
            return null;
        }

        if (!audioContext) {
            try {
                audioContext = new AudioContextClass();
            } catch (error) {
                audioContext = null;
            }
        }

        return audioContext;
    }

    function handleAudioUnlock() {
        const context = getAudioContext();
        if (!context) {
            removeAudioUnlockHandlers();
            return;
        }

        if (context.state === 'suspended') {
            context.resume().catch(function () {
                // Ignore resume errors caused by autoplay policies.
            });
        }

        if (context.state === 'running') {
            removeAudioUnlockHandlers();
        }
    }

    function addAudioUnlockHandlers() {
        if (audioUnlockHandlersBound || !AudioContextClass) {
            return;
        }

        audioUnlockHandlersBound = true;
        ['pointerdown', 'keydown', 'touchstart'].forEach(function (eventName) {
            document.addEventListener(eventName, handleAudioUnlock, true);
        });
    }

    function removeAudioUnlockHandlers() {
        if (!audioUnlockHandlersBound) {
            return;
        }

        audioUnlockHandlersBound = false;
        ['pointerdown', 'keydown', 'touchstart'].forEach(function (eventName) {
            document.removeEventListener(eventName, handleAudioUnlock, true);
        });
    }

    function playNotificationSound() {
        const context = getAudioContext();
        if (!context) {
            return;
        }

        if (context.state === 'suspended') {
            context.resume().then(function () {
                playNotificationSound();
            }).catch(function () {
                // Ignore resume failures.
            });
            return;
        }

        const startTime = context.currentTime + 0.02;
        const duration = 1.35;
        const masterGain = context.createGain();
        const panner = typeof context.createStereoPanner === 'function'
            ? context.createStereoPanner()
            : null;
        const highpass = context.createBiquadFilter();
        const toneFilter = context.createBiquadFilter();

        masterGain.gain.setValueAtTime(0.00001, startTime - 0.02);
        masterGain.gain.exponentialRampToValueAtTime(0.72, startTime + 0.05);
        masterGain.gain.setTargetAtTime(0.32, startTime + 0.24, 0.32);
        masterGain.gain.setTargetAtTime(0.00001, startTime + duration - 0.28, 0.22);

        highpass.type = 'highpass';
        highpass.frequency.setValueAtTime(320, startTime);
        highpass.Q.setValueAtTime(0.7, startTime);

        toneFilter.type = 'lowpass';
        toneFilter.frequency.setValueAtTime(8200, startTime);
        toneFilter.Q.setValueAtTime(0.9, startTime);

        if (panner) {
            masterGain.connect(panner);
            panner.connect(highpass);
            panner.pan.setValueAtTime(-0.12, startTime);
            panner.pan.linearRampToValueAtTime(0.18, startTime + 0.55);
        } else {
            masterGain.connect(highpass);
        }

        highpass.connect(toneFilter);
        toneFilter.connect(context.destination);

        const delay = context.createDelay();
        const delayFilter = context.createBiquadFilter();
        const delayFeedback = context.createGain();
        const delayMix = context.createGain();

        delay.delayTime.setValueAtTime(0.18, startTime);
        delayFilter.type = 'lowpass';
        delayFilter.frequency.setValueAtTime(7200, startTime);
        delayFeedback.gain.setValueAtTime(0.24, startTime);
        delayMix.gain.setValueAtTime(0.34, startTime);
        delayFeedback.gain.setTargetAtTime(0.00001, startTime + duration, 0.4);
        delayMix.gain.setTargetAtTime(0.00001, startTime + duration + 0.1, 0.35);

        toneFilter.connect(delay);
        delay.connect(delayFilter);
        delayFilter.connect(delayFeedback);
        delayFeedback.connect(delay);
        delayFilter.connect(delayMix);
        delayMix.connect(context.destination);

        const chimeReal = new Float32Array([0, 0.85, 0.3, 0.18, 0.12, 0.08]);
        const chimeImag = new Float32Array(chimeReal.length);
        const shimmerReal = new Float32Array([0, 0.4, 0.28, 0.2, 0.16, 0.1, 0.06]);
        const shimmerImag = new Float32Array(shimmerReal.length);
        const chimeWave = context.createPeriodicWave(chimeReal, chimeImag);
        const shimmerWave = context.createPeriodicWave(shimmerReal, shimmerImag);

        function scheduleLayer(options) {
            const oscillator = context.createOscillator();
            const gainNode = context.createGain();
            const layerOffset = options.offset || 0;
            const layerStart = startTime + layerOffset;
            const layerLength = Math.max(0, (options.length || (duration - layerOffset)));
            const stopTime = layerStart + layerLength + (options.release || 0.28);
            const attack = options.attack || 0.015;
            const decay = options.decay || 0.24;
            const sustainLevel = typeof options.sustain === 'number'
                ? options.sustain
                : options.gain * 0.58;

            if (options.wave) {
                oscillator.setPeriodicWave(options.wave);
            } else {
                oscillator.type = options.type || 'triangle';
            }

            oscillator.frequency.setValueAtTime(options.frequency, layerStart);
            if (options.detune) {
                oscillator.detune.setValueAtTime(options.detune, layerStart);
            }
            if (options.pitchAmount && options.pitchDecay) {
                oscillator.frequency.setValueAtTime(options.frequency * options.pitchAmount, layerStart);
                oscillator.frequency.exponentialRampToValueAtTime(
                    options.frequency,
                    layerStart + options.pitchDecay
                );
            }

            gainNode.gain.setValueAtTime(0.00001, layerStart);
            gainNode.gain.linearRampToValueAtTime(options.gain, layerStart + attack);
            gainNode.gain.linearRampToValueAtTime(sustainLevel, layerStart + attack + decay);
            if (options.hold) {
                gainNode.gain.setValueAtTime(sustainLevel, layerStart + attack + decay + options.hold);
            }
            gainNode.gain.linearRampToValueAtTime(0.00001, stopTime);

            oscillator.connect(gainNode);
            gainNode.connect(masterGain);

            oscillator.start(layerStart);
            oscillator.stop(stopTime);
        }

        [
            {
                wave: chimeWave,
                frequency: 1174.66,
                gain: 0.42,
                attack: 0.02,
                decay: 0.25,
                sustain: 0.18,
                release: 0.5,
                pitchAmount: 1.018,
                pitchDecay: 0.22,
            },
            {
                type: 'sawtooth',
                frequency: 1567.98,
                detune: 3,
                gain: 0.3,
                offset: 0.025,
                attack: 0.018,
                decay: 0.28,
                sustain: 0.14,
                release: 0.45,
                pitchAmount: 1.012,
                pitchDecay: 0.26,
            },
            {
                wave: shimmerWave,
                frequency: 2093,
                gain: 0.22,
                offset: 0.08,
                attack: 0.015,
                decay: 0.22,
                sustain: 0.1,
                release: 0.4,
            },
            {
                type: 'triangle',
                frequency: 880,
                detune: -5,
                gain: 0.2,
                offset: 0.14,
                attack: 0.018,
                decay: 0.24,
                sustain: 0.08,
                release: 0.35,
            },
        ].forEach(scheduleLayer);

        const accent = context.createOscillator();
        const accentGain = context.createGain();
        const accentStart = startTime + 0.18;
        const accentStop = accentStart + 0.9;

        accent.type = 'square';
        accent.frequency.setValueAtTime(2637.02, accentStart);
        accent.frequency.exponentialRampToValueAtTime(2793.83, accentStart + 0.08);

        accentGain.gain.setValueAtTime(0.00001, accentStart);
        accentGain.gain.linearRampToValueAtTime(0.12, accentStart + 0.02);
        accentGain.gain.linearRampToValueAtTime(0.00001, accentStop + 0.25);

        accent.connect(accentGain);
        accentGain.connect(masterGain);
        accent.start(accentStart);
        accent.stop(accentStop + 0.3);

        const noiseSource = context.createBufferSource();
        const noiseDuration = duration + 0.3;
        const noiseBuffer = context.createBuffer(1, Math.ceil(context.sampleRate * noiseDuration), context.sampleRate);
        const noiseData = noiseBuffer.getChannelData(0);
        for (let i = 0; i < noiseData.length; i += 1) {
            const t = i / noiseData.length;
            const fade = Math.pow(1 - t, 4.2);
            noiseData[i] = (Math.random() * 2 - 1) * fade * 0.8;
        }

        const noiseFilter = context.createBiquadFilter();
        const noiseGain = context.createGain();

        noiseFilter.type = 'bandpass';
        noiseFilter.frequency.setValueAtTime(5400, startTime);
        noiseFilter.Q.setValueAtTime(5, startTime);

        noiseGain.gain.setValueAtTime(0.00001, startTime);
        noiseGain.gain.linearRampToValueAtTime(0.16, startTime + 0.05);
        noiseGain.gain.linearRampToValueAtTime(0.00001, startTime + duration - 0.35);

        noiseSource.buffer = noiseBuffer;
        noiseSource.connect(noiseFilter);
        noiseFilter.connect(noiseGain);
        noiseGain.connect(masterGain);
        noiseSource.start(startTime);
        noiseSource.stop(startTime + noiseDuration);
    }

    function sanitizeSnapshotSection(section) {
        const safeSection = section && typeof section === 'object' ? section : {};
        return {
            latestId: Number(safeSection.latestId) || 0,
            latestCreatedAt: Number(safeSection.latestCreatedAt) || 0,
            pendingCount: Number(safeSection.pendingCount) || 0,
        };
    }

    function buildRealtimeSnapshot(payload) {
        return {
            pos: sanitizeSnapshotSection(payload && payload.pos),
            stock: sanitizeSnapshotSection(payload && payload.stock),
        };
    }

    function hasNewRealtimeActivity(previous, current) {
        if (!current) {
            return false;
        }

        const metrics = ['pendingCount', 'latestId', 'latestCreatedAt'];
        const channels = ['pos', 'stock'];

        if (!previous) {
            return channels.some(function (channel) {
                const currentSection = current[channel] || {};
                return metrics.some(function (metric) {
                    return Number(currentSection[metric] || 0) > 0;
                });
            });
        }

        return channels.some(function (channel) {
            const currentSection = current[channel] || {};
            const previousSection = previous[channel] || {};

            return metrics.some(function (metric) {
                return Number(currentSection[metric] || 0) > Number(previousSection[metric] || 0);
            });
        });
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
            Number(pos.latestUpdatedAt) || 0,
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
        const snapshot = buildRealtimeSnapshot(payload);

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

        if (hasNewRealtimeActivity(lastRealtimeSnapshot, snapshot)) {
            playNotificationSound();
        }

        lastRealtimeSnapshot = snapshot;
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

    addAudioUnlockHandlers();

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
