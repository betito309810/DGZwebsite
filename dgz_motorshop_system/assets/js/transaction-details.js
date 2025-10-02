document.addEventListener('DOMContentLoaded', () => {
    // Transaction details modal logic
    const transactionModal = document.getElementById('transactionModal');
    const modalCloseBtn = transactionModal.querySelector('.close');

    // Event listener for transaction row clicks
    document.querySelectorAll('.transaction-row').forEach(row => {
        row.addEventListener('click', function() {
            const orderId = this.getAttribute('data-order-id');
            if (orderId) {
                loadTransactionDetails(orderId);
                transactionModal.style.display = 'block';
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
        
        // Debug: log items data to console
        console.log('Transaction items:', items);

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

        // Update modal content with new design matching POS
        modalBody.innerHTML = `
            <div class="transaction-details">
                <h2>Transaction Details</h2>
                
                <div class="order-info-grid">
                    <div class="info-group">
                        <label>Customer:</label>
                        <span>${order.customer_name}</span>
                    </div>
                    <div class="info-group">
                        <label>Invoice #:</label>
                        <span>${order.invoice_number || 'N/A'}</span>
                    </div>
                    <div class="info-group">
                        <label>Date:</label>
                        <span>${orderDate}</span>
                    </div>
                    
                    <div class="info-group">
                        <label>Status:</label>
                        <span class="status-${order.status.toLowerCase()}">${order.status}</span>
                    </div>
                    <div class="info-group">
                        <label>Payment Method:</label>
                        <span>${order.payment_method}</span>
                    </div>
                    <div class="info-group">
                        <label>Email:</label>
                        <span>${order.email || 'N/A'}</span>
                    </div>
                    
                    <div class="info-group">
                        <label>Phone:</label>
                        <span>${order.contact || 'N/A'}</span>
                    </div>
                    <div class="info-group">
                        <label>Reference:</label>
                        <span>${order.reference_number || 'N/A'}</span>
                    </div>
                </div>

                <div class="order-items-section">
                    <h3>Order Items</h3>
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
                                    <td colspan="3" class="total-label">Total:</td>
                                    <td class="total-amount">₱${parseFloat(order.total).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        `;

    } catch (error) {
        console.error('Error loading transaction details:', error);
        alert('Failed to load transaction details. Please try again.');
    }
}
