function escapeHtml(value = '') {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

document.addEventListener('DOMContentLoaded', () => {
    // Transaction details modal logic
    const transactionModal = document.getElementById('transactionModal');
    const modalCloseBtn = transactionModal.querySelector('.modal-close');

    // Event listener for transaction row clicks
    document.querySelectorAll('.transaction-row').forEach(row => {
        row.addEventListener('click', function() {
            const orderId = this.getAttribute('data-order-id');
            if (orderId) {
                loadTransactionDetails(orderId);
                transactionModal.style.display = 'flex';
            }
        });
    });

    // Close modal when clicking the close button
    modalCloseBtn.addEventListener('click', () => {
        transactionModal.style.display = 'none';
    });

    // Close modal when clicking outside
    window.addEventListener('click', (event) => {
        if (event.target === transactionModal) {
            transactionModal.style.display = 'none';
        }
    });
});

/**
 * Load and display transaction details in the modal.
 * @param {string} orderId - The order ID to fetch details for.
 */
async function loadTransactionDetails(orderId) {
    try {
        const response = await fetch(`get_transaction_details.php?order_id=${orderId}`);
        if (!response.ok) {
            throw new Error('Failed to fetch transaction details');
        }
        const data = await response.json();
        
        // Update modal content
        const modalBody = document.querySelector('#transactionModal .modal-body');
        const order = data.order;
        const items = data.items;

        // Format the date
        const orderDate = new Date(order.created_at).toLocaleString();
        const cashierName = (order.cashier_display_name || order.cashier_name || order.cashier_username || '').trim() || 'Unassigned';
        const customerName = (order.customer_name || '').trim() || 'N/A';
        const email = (order.email || '').toString();
        const phone = (order.phone || '').toString();
        const facebook = ((order.facebook_account ?? '') + '').trim();
        const address = ((order.address ?? '') + '').trim();
        const postal = ((order.postal_code ?? '') + '').trim();
        const city = ((order.city ?? '') + '').trim();
        
        // Debug: log items data to console
        console.log('Transaction items:', items);

        const statusKey = (order.status || '').toLowerCase();
        const reasonLabel = escapeHtml(order.decline_reason_label || '');
        const reasonNote = escapeHtml(order.decline_reason_note || '');

        const statusLabels = {
            pending: 'Pending',
            payment_verification: 'Payment Verification',
            approved: 'Approved',
            completed: 'Completed',
            disapproved: 'Disapproved',
        };

        const disapprovalHtml = statusKey === 'disapproved'
            ? `
                <div class="disapproval-section">
                    <h3>Disapproval Details</h3>
                    <p><strong>Reason:</strong> ${reasonLabel || 'Not provided'}</p>
                    ${reasonNote ? `<p><strong>Additional details:</strong> ${reasonNote.replace(/\n/g, '<br>')}</p>` : ''}
                </div>
            `
            : '';

        const statusLabel = statusLabels[statusKey] || statusKey.replace(/_/g, ' ').replace(/\b\w/g, (ch) => ch.toUpperCase());

        // Build items HTML with correct columns:
        // Product name, Quantity, Price, Subtotal (quantity * price)
        const itemsHtml = items.map(item => {
            // Parse quantity and price as numbers safely
            const qty = Number(item.qty) || 0;
            const price = Number(item.price) || 0;
            const subtotal = qty * price;

            return `
            <tr>
                <td>${item.name || 'N/A'}</td> <!-- Show product name -->
                <td>${qty}</td> <!-- Show quantity ordered -->
                <td>₱${price.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td> <!-- Show price formatted -->
                <td>₱${subtotal.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td> <!-- Show subtotal -->
            </tr>
            `;
        }).join('');

        // Update modal content with layout mirroring POS modal (cashier first)
        modalBody.innerHTML = `
            <div class="transaction-info">
                <h4>Order Information</h4>
                <div class="info-grid">
                    <div class="info-item">
                        <label>Cashier:</label>
                        <span>${escapeHtml(cashierName)}</span>
                    </div>
                    <div class="info-item">
                        <label>Customer:</label>
                        <span>${escapeHtml(customerName)}</span>
                    </div>
                    <div class="info-item">
                        <label>Invoice #:</label>
                        <span>${escapeHtml(order.invoice_number || 'N/A')}</span>
                    </div>
                    <div class="info-item">
                        <label>Date:</label>
                        <span>${orderDate}</span>
                    </div>
                    <div class="info-item">
                        <label>Status:</label>
                        <span class="status-${statusKey}">${statusLabel}</span>
                    </div>
                    <div class="info-item">
                        <label>Payment Method:</label>
                        <span>${order.payment_method}</span>
                    </div>
                    <div class="info-item">
                        <label>Email:</label>
                        <span>${email || 'N/A'}</span>
                    </div>
                    <div class="info-item">
                        <label>Phone:</label>
                        <span>${phone || 'N/A'}</span>
                    </div>
                    <div class="info-item">
                        <label>Facebook:</label>
                        <span>${facebook || 'N/A'}</span>
                    </div>
                    <div class="info-item">
                        <label>Address:</label>
                        <span>${address || 'N/A'}</span>
                    </div>
                    <div class="info-item">
                        <label>Postal code:</label>
                        <span>${postal || 'N/A'}</span>
                    </div>
                    <div class="info-item">
                        <label>City:</label>
                        <span>${city || 'N/A'}</span>
                    </div>
                    <div class="info-item">
                        <label>Reference:</label>
                        <span>${order.reference_number || 'N/A'}</span>
                    </div>
                </div>

                ${disapprovalHtml}

                ${(order.customer_note ?? '').toString().trim() !== '' ? `
                <div class="info-item note-item" style="display:block;">
                    <label>Customer Note:</label>
                    <span>${escapeHtml((order.customer_note || '').toString())}</span>
                </div>` : ''}
            </div>

            <div class="order-items">
                <h4>Order Items</h4>
                <div class="table-responsive">
                    <table class="items-table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Quantity</th>
                                <th>Price</th>
                                <th>Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${itemsHtml}
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="3"><strong>Total:</strong></td>
                                <td>₱${parseFloat(order.total).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        `;

    } catch (error) {
        console.error('Error loading transaction details:', error);
        alert('Failed to load transaction details. Please try again.');
    }
}
