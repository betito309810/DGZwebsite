// Track order page interactions
// ------------------------------
// This script handles the lightweight order tracking flow:
// 1. Capture the submitted tracking code.
// 2. Send it to the PHP endpoint for validation + lookup.
// 3. Render the status or show an error message.

(() => {
    // Cache DOM references once the browser has parsed the markup.
    const form = document.getElementById('orderTrackerForm');
    const trackingCodeInput = document.getElementById('trackingCodeInput');
    const feedbackContainer = document.getElementById('trackerFeedback');
    const statusPanel = document.getElementById('trackerStatusPanel');
    const statusPill = document.getElementById('trackerStatusPill');
    const statusMessage = document.getElementById('trackerStatusMessage');
    const statusDetails = document.getElementById('trackerStatusDetails');

    if (!form || !trackingCodeInput) {
        return; // Bail out if critical elements are missing.
    }

    // Helper to show feedback messages (success and error) in a single place.
    const showFeedback = (message, type = 'info') => {
        if (!feedbackContainer) {
            return;
        }

        feedbackContainer.textContent = message;
        feedbackContainer.classList.remove('error', 'success');

        if (type === 'error') {
            feedbackContainer.classList.add('error');
        } else if (type === 'success') {
            feedbackContainer.classList.add('success');
        }

        feedbackContainer.hidden = false;
    };

    // Helper to hide the feedback area entirely.
    const hideFeedback = () => {
        if (feedbackContainer) {
            feedbackContainer.hidden = true;
            feedbackContainer.textContent = '';
            feedbackContainer.classList.remove('error', 'success');
        }
    };

    // Helper to toggle the status panel visibility and populate its content.
    const showStatus = (order) => {
        if (!statusPanel || !statusPill || !statusMessage || !statusDetails) {
            return;
        }

        // Update the pill styling + label based on the status from the API.
        const normalizedStatus = (order.status || 'pending').toLowerCase();
        statusPill.dataset.status = normalizedStatus;
        statusPill.textContent = normalizedStatus.replace(/_/g, ' ');

        // Show a simple friendly status line.
        statusMessage.textContent = order.statusMessage || 'We found your order. Stay tuned for updates!';

        // Populate the breakdown grid using template literals for clarity.
        const detailRows = [
            `
                <div class="status-detail">
                    <span>Tracking Code</span>
                    <strong>${order.trackingCode}</strong>
                </div>
            `,
            `
                <div class="status-detail">
                    <span>Placed On</span>
                    <strong>${order.createdAt}</strong>
                </div>
            `,
            `
                <div class="status-detail">
                    <span>Customer</span>
                    <strong>${order.customerName}</strong>
                </div>
            `,
            `
                <div class="status-detail">
                    <span>Payment Method</span>
                    <strong>${order.paymentMethod}</strong>
                </div>
            `,
            `
                <div class="status-detail">
                    <span>Order Total</span>
                    <strong>${order.total}</strong>
                </div>
            `,
        ];

        if (order.internalId) {
            detailRows.unshift(`
                <div class="status-detail">
                    <span>Order #</span>
                    <strong>#${order.internalId}</strong>
                </div>
            `);
        }

        statusDetails.innerHTML = detailRows.join('');

        statusPanel.hidden = false;
    };

    // Hide the status panel entirely (used when showing errors or prior to search).
    const hideStatus = () => {
        if (statusPanel) {
            statusPanel.hidden = true;
            statusDetails.innerHTML = '';
        }
    };

    // Attach a submit handler so the page never performs a full reload.
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        hideFeedback();
        hideStatus();

        const rawTrackingCode = trackingCodeInput.value.trim();
        if (rawTrackingCode === '') {
            showFeedback('Please enter your tracking code to continue.', 'error');
            trackingCodeInput.focus();
            return;
        }

        // Provide immediate feedback by disabling the button while the request is active.
        const submitButton = form.querySelector('button[type="submit"]');
        if (submitButton) {
            submitButton.disabled = true;
            submitButton.textContent = 'Checking...';
        }

        try {
            const response = await fetch('order_status.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    trackingCode: rawTrackingCode,
                }),
            });

            const data = await response.json();

            if (!response.ok || !data.success) {
                const errorMessage = data && data.message ? data.message : 'Unable to locate that tracking code.';
                showFeedback(errorMessage, 'error');
                return;
            }

            showFeedback('Order found! See the latest update below.', 'success');
            showStatus(data.order);
        } catch (error) {
            console.error('Order lookup failed:', error);
            showFeedback('Something went wrong while checking your order. Please try again shortly.', 'error');
        } finally {
            if (submitButton) {
                submitButton.disabled = false;
                submitButton.textContent = 'Track Order';
            }
        }
    });
})();
