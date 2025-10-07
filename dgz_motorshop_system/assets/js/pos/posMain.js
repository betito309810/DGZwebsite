// file 2 start – POS page behavior bundle (safe to extract)
// Begin POS main interaction script
const { productCatalog = [], initialActiveTab = 'walkin', checkoutReceipt = null } = window.dgzPosData || {};

document.addEventListener('DOMContentLoaded', () => {
            const posStateKey = 'posTable';
            const tabStateKey = 'posActiveTab';
            let hasShownCheckoutAlert = false;

            const sidebar = document.getElementById('sidebar');
            const mobileToggle = document.querySelector('.mobile-toggle');
            const userMenu = document.querySelector('.user-menu');
            const userAvatar = document.querySelector('.user-avatar');
            const userDropdown = document.getElementById('userDropdown');

            const posForm = document.getElementById('posForm');
            const posTableBody = document.getElementById('posTableBody');
            const posEmptyState = document.getElementById('posEmptyState');
            const amountReceivedInput = document.getElementById('amountReceived');
            const settlePaymentButton = document.getElementById('settlePaymentButton');
            const profileButton = document.getElementById('profileTrigger');
            const profileModal = document.getElementById('profileModal');
            const profileModalClose = document.getElementById('profileModalClose');

            const totals = {
                sales: document.getElementById('salesTotalAmount'),
                discount: document.getElementById('discountAmount'),
                vatable: document.getElementById('vatableAmount'),
                vat: document.getElementById('vatAmount'),
                topTotal: document.getElementById('topTotalAmountSimple'),
                change: document.getElementById('changeAmount'),
            };

            const clearPosTableButton = document.getElementById('clearPosTable');
            const openProductModalButton = document.getElementById('openProductModal');
            const productModal = document.getElementById('productModal');
            const closeProductModalButton = document.getElementById('closeProductModal');
            const productSearchInput = document.getElementById('productSearchInput');
            const productSearchTableBody = document.getElementById('productSearchTableBody');
            const addSelectedProductsButton = document.getElementById('addSelectedProducts');

            const receiptModal = document.getElementById('receiptModal');
            const closeReceiptModalButton = document.getElementById('closeReceiptModal');
            const printReceiptButton = document.getElementById('printReceiptButton');
            const receiptItemsBody = document.getElementById('receiptItemsBody');

            const proofModal = document.getElementById('proofModal');
            const proofImage = document.getElementById('proofImage');
            const proofReferenceValue = document.getElementById('proofReferenceValue');
            const proofCustomerName = document.getElementById('proofCustomerName');
            const proofNoImage = document.getElementById('proofNoImage');
            const closeProofModalButton = document.getElementById('closeProofModal');

            const onlineOrderModal = document.getElementById('onlineOrderModal');
            const closeOnlineOrderModalButton = document.getElementById('closeOnlineOrderModal');
            const onlineOrderCustomer = document.getElementById('onlineOrderCustomer');
            const onlineOrderInvoice = document.getElementById('onlineOrderInvoice');
            const onlineOrderDate = document.getElementById('onlineOrderDate');
            const onlineOrderStatus = document.getElementById('onlineOrderStatus');
            const onlineOrderPayment = document.getElementById('onlineOrderPayment');
            const onlineOrderEmail = document.getElementById('onlineOrderEmail');
            const onlineOrderPhone = document.getElementById('onlineOrderPhone');
            const onlineOrderNoteContainer = document.getElementById('onlineOrderNoteContainer');
            const onlineOrderNote = document.getElementById('onlineOrderNote');
            const onlineOrderReferenceWrapper = document.getElementById('onlineOrderReferenceWrapper');
            const onlineOrderReference = document.getElementById('onlineOrderReference');
            const onlineOrderItemsBody = document.getElementById('onlineOrderItemsBody');
            const onlineOrderTotal = document.getElementById('onlineOrderTotal');

            const tabButtons = document.querySelectorAll('.pos-tab-button');
            const tabPanels = {
                walkin: document.getElementById('walkinTab'),
                online: document.getElementById('onlineTab'),
            };

            // Begin POS profile modal opener (inline)
            const openProfileModal = () => {
                if (!profileModal) {
                    return;
                }

                profileModal.classList.add('show');
                profileModal.setAttribute('aria-hidden', 'false');
                document.body.classList.add('modal-open');
            };
            // End POS profile modal opener (inline)

            // Begin POS profile modal closer (inline)
            const closeProfileModal = () => {
                if (!profileModal) {
                    return;
                }

                profileModal.classList.remove('show');
                profileModal.setAttribute('aria-hidden', 'true');
                document.body.classList.remove('modal-open');
            };
            // End POS profile modal closer (inline)

            // ===== Online order details modal functionality =====
            // Begin POS online order modal opener
            const openOnlineOrderModalOverlay = () => {
                if (!onlineOrderModal) {
                    return;
                }

                onlineOrderModal.style.display = 'flex';
                document.body.classList.add('modal-open');
            };
            // End POS online order modal opener

            // Begin POS online order modal closer
            const closeOnlineOrderModalOverlay = () => {
                if (!onlineOrderModal) {
                    return;
                }

                onlineOrderModal.style.display = 'none';
                document.body.classList.remove('modal-open');
            };
            // End POS online order modal closer

            // Begin POS online order modal populator
            const statusLabels = {
                pending: 'Pending',
                payment_verification: 'Payment Verification',
                approved: 'Approved',
                completed: 'Completed',
                disapproved: 'Disapproved',
            };

            const populateOnlineOrderModal = (order, items) => {
                if (!onlineOrderModal) {
                    return;
                }

                const safeCustomer = (order.customer_name || 'Customer').toString();
                const safeInvoice = (order.invoice_number || 'N/A').toString();
                const safeStatus = (order.status || 'pending').toString().toLowerCase();
                const safePayment = (order.payment_method || 'N/A').toString();
                const referenceNumber = (order.reference_number || '').toString();

                onlineOrderCustomer.textContent = safeCustomer;
                onlineOrderInvoice.textContent = safeInvoice !== '' ? safeInvoice : 'N/A';

                if (order.created_at) {
                    const createdDate = new Date(order.created_at);
                    onlineOrderDate.textContent = Number.isNaN(createdDate.getTime())
                        ? order.created_at
                        : createdDate.toLocaleString();
                } else {
                    onlineOrderDate.textContent = 'N/A';
                }

                const statusLabel = statusLabels[safeStatus] || safeStatus.replace(/_/g, ' ').replace(/\b\w/g, (ch) => ch.toUpperCase());
                onlineOrderStatus.textContent = statusLabel;
                onlineOrderPayment.textContent = safePayment !== '' ? safePayment : 'N/A';
                onlineOrderEmail.textContent = (order.email || '').toString();
                onlineOrderPhone.textContent = (order.phone || '').toString();

                if (onlineOrderNoteContainer && onlineOrderNote) {
                    const noteText = ((order.customer_note ?? order.notes) || '').toString().trim();
                    if (noteText !== '') {
                        onlineOrderNote.textContent = noteText; // Added cashier note so staff can review special instructions
                        onlineOrderNoteContainer.style.display = 'flex';
                    } else {
                        onlineOrderNote.textContent = '';
                        onlineOrderNoteContainer.style.display = 'none';
                    }
                }

                if (referenceNumber && safePayment.toLowerCase() === 'gcash') {
                    onlineOrderReferenceWrapper.style.display = 'flex';
                    onlineOrderReference.textContent = referenceNumber;
                } else {
                    onlineOrderReferenceWrapper.style.display = 'none';
                    onlineOrderReference.textContent = '';
                }

                while (onlineOrderItemsBody.firstChild) {
                    onlineOrderItemsBody.removeChild(onlineOrderItemsBody.firstChild);
                }

                if (!Array.isArray(items) || items.length === 0) {
                    const emptyRow = document.createElement('tr');
                    const emptyCell = document.createElement('td');
                    emptyCell.colSpan = 4;
                    emptyCell.textContent = 'No items found for this order.';
                    emptyCell.style.textAlign = 'center';
                    emptyCell.style.color = '#6b7280';
                    emptyCell.style.padding = '12px';
                    emptyRow.appendChild(emptyCell);
                    onlineOrderItemsBody.appendChild(emptyRow);
                } else {
                    items.forEach((item) => {
                        const qty = Number(item.qty) || 0;
                        const price = Number(item.price) || 0;
                        const subtotal = qty * price;

                        const row = document.createElement('tr');

                        const nameCell = document.createElement('td');
                        nameCell.textContent = (item.name || `Item #${item.product_id || ''}`).toString();
                        row.appendChild(nameCell);

                        const qtyCell = document.createElement('td');
                        qtyCell.textContent = qty.toString();
                        row.appendChild(qtyCell);

                        const priceCell = document.createElement('td');
                        priceCell.textContent = formatPeso(price);
                        row.appendChild(priceCell);

                        const subtotalCell = document.createElement('td');
                        subtotalCell.textContent = formatPeso(subtotal);
                        row.appendChild(subtotalCell);

                        onlineOrderItemsBody.appendChild(row);
                    });
                }

                const totalAmount = Number(order.total) || 0;
                onlineOrderTotal.textContent = formatPeso(totalAmount);
            };
            // End POS online order modal populator

            // Begin POS currency formatter
            function formatPeso(value) {
                const amount = Number(value) || 0;
                return '₱' + amount.toLocaleString('en-PH', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2,
                });
            }
            // End POS currency formatter

            // Begin POS table empty-state toggler
            function updateEmptyState() {
                const hasRows = posTableBody.querySelector('tr') !== null;
                posEmptyState.style.display = hasRows ? 'none' : 'flex';
            }
            // End POS table empty-state toggler

            // Begin POS cart total calculator
            function getSalesTotal() {
                let subtotal = 0;
                posTableBody.querySelectorAll('tr').forEach((row) => {
                    const price = parseFloat(row.querySelector('.pos-price').dataset.rawPrice || '0');
                    const qty = parseInt(row.querySelector('.pos-qty').value, 10) || 0;
                    subtotal += price * qty;
                });
                return subtotal;
            }
            // End POS cart total calculator

            // Begin POS settle button enabler
            function updateSettleButtonState() {
                if (!settlePaymentButton) {
                    return;
                }

                const hasRows = posTableBody.querySelector('tr') !== null;
                const amountReceived = parseFloat(amountReceivedInput?.value || '0');
                const salesTotal = getSalesTotal();
                
                // Only enable if there are items and payment is sufficient
                const shouldEnable = hasRows && amountReceived >= salesTotal && salesTotal > 0;

                settlePaymentButton.disabled = !shouldEnable;
            }
            // End POS settle button enabler

            // Begin POS totals recalculator
            function recalcTotals() {
                const salesTotal = getSalesTotal();
                const discount = 0;
                const vatable = salesTotal / 1.12;
                const vat = salesTotal - vatable;
                const amountReceived = parseFloat(amountReceivedInput.value || '0');
                const change = amountReceived - salesTotal;

                totals.sales.textContent = formatPeso(salesTotal);
                totals.discount.textContent = formatPeso(discount);
                totals.vatable.textContent = formatPeso(vatable);
                totals.vat.textContent = formatPeso(vat);
                totals.topTotal.textContent = formatPeso(salesTotal);
                
                // Only show positive change amount, show 0.00 for insufficient payment
                totals.change.textContent = formatPeso(Math.max(0, change));

                if (salesTotal > 0 && amountReceived < salesTotal) {
                    totals.change.style.color = '#e74c3c';
                    totals.change.title = 'Insufficient payment';
                    if (settlePaymentButton) {
                        settlePaymentButton.disabled = true;
                    }
                } else if (salesTotal > 0) {
                    totals.change.style.color = '#27ae60';
                    totals.change.title = '';
                    if (settlePaymentButton) {
                        settlePaymentButton.disabled = false;
                    }
                } else {
                    totals.change.style.color = '';
                    totals.change.title = '';
                    if (settlePaymentButton) {
                        settlePaymentButton.disabled = true;
                    }
                }

                updateSettleButtonState();
            }
            // End POS totals recalculator

            // Begin POS cart state persister
            function persistTableState() {
                const rows = [];
                posTableBody.querySelectorAll('tr').forEach((row) => {
                    rows.push({
                        id: row.dataset.productId,
                        name: row.querySelector('.pos-name').textContent,
                        price: parseFloat(row.querySelector('.pos-price').dataset.rawPrice || '0'),
                        available: parseInt(row.querySelector('.pos-available').textContent, 10) || 0,
                        qty: parseInt(row.querySelector('.pos-qty').value, 10) || 1,
                    });
                });

                try {
                    if (rows.length > 0) {
                        localStorage.setItem(posStateKey, JSON.stringify(rows));
                    } else {
                        localStorage.removeItem(posStateKey);
                    }
                } catch (error) {
                    console.error('Unable to persist POS table state.', error);
                }
            }
            // End POS cart state persister

            // Begin POS cart clearer
            function clearTable() {
                posTableBody.innerHTML = '';
                updateEmptyState();
                if (amountReceivedInput) {
                    amountReceivedInput.value = '';
                }
                recalcTotals();
                try {
                    localStorage.removeItem(posStateKey);
                } catch (error) {
                    console.error('Unable to clear POS state.', error);
                }
            }
            // End POS cart clearer

            // Begin POS cart row creator
            function createRow(item) {
                if (posTableBody.querySelector(`[data-product-id="${item.id}"]`)) {
                    return;
                }

                const tr = document.createElement('tr');
                tr.dataset.productId = String(item.id);

                const nameCell = document.createElement('td');
                nameCell.className = 'pos-name';
                nameCell.textContent = item.name;
                tr.appendChild(nameCell);

                const priceCell = document.createElement('td');
                priceCell.className = 'pos-price';
                priceCell.dataset.rawPrice = String(item.price);
                priceCell.textContent = formatPeso(item.price);
                tr.appendChild(priceCell);

                const availableCell = document.createElement('td');
                availableCell.className = 'pos-available';
                availableCell.textContent = Number.isFinite(item.available) ? item.available : 0;
                tr.appendChild(availableCell);

                const qtyCell = document.createElement('td');
                qtyCell.className = 'pos-actions';

                const productInput = document.createElement('input');
                productInput.type = 'hidden';
                productInput.name = 'product_id[]';
                productInput.value = item.id;
                qtyCell.appendChild(productInput);

                const qtyInput = document.createElement('input');
                qtyInput.type = 'number';
                qtyInput.className = 'pos-qty';
                qtyInput.name = 'qty[]';
                qtyInput.min = '1';
                if (Number.isFinite(item.max) && item.max > 0) {
                    qtyInput.max = String(item.max);
                }
                qtyInput.value = Math.max(1, item.qty || 1);
                qtyCell.appendChild(qtyInput);

                const removeButton = document.createElement('button');
                removeButton.type = 'button';
                removeButton.className = 'remove-btn';
                removeButton.setAttribute('aria-label', 'Remove item');
                removeButton.innerHTML = "<i class='fas fa-times'></i>";
                qtyCell.appendChild(removeButton);

                tr.appendChild(qtyCell);
                posTableBody.appendChild(tr);
            }
            // End POS cart row creator

            // Begin POS add-product helper
            function addProductById(productId) {
                const product = productCatalog.find((item) => String(item.id) === String(productId));
                if (!product) {
                    return;
                }

                const availableQty = Number(product.quantity) || 0;
                if (availableQty <= 0) {
                    alert(`${product.name} is out of stock and cannot be added.`);
                    return;
                }

                const existingRow = posTableBody.querySelector(`[data-product-id="${product.id}"]`);
                if (existingRow) {
                    const qtyInput = existingRow.querySelector('.pos-qty');
                    const currentQty = parseInt(qtyInput.value, 10) || 0;
                    const maxQty = Math.max(parseInt(qtyInput.max, 10) || 0, availableQty);
                    const newQty = Math.min(currentQty + 1, maxQty);
                    qtyInput.max = String(maxQty);
                    existingRow.querySelector('.pos-available').textContent = availableQty;
                    qtyInput.value = newQty;
                } else {
                    createRow({
                        id: product.id,
                        name: product.name,
                        price: Number(product.price) || 0,
                        available: availableQty,
                        qty: 1,
                        max: availableQty,
                    });
                }

                updateEmptyState();
                recalcTotals();
                persistTableState();
            }
            // End POS add-product helper

            // Begin POS cart state restorer
            function restoreTableState() {
                let data = [];
                try {
                    data = JSON.parse(localStorage.getItem(posStateKey) || '[]');
                } catch (error) {
                    data = [];
                }

                data.forEach((item) => {
                    const product = productCatalog.find((productItem) => String(productItem.id) === String(item.id));
                    const availableQty = product ? Number(product.quantity) : Number(item.available);
                    createRow({
                        id: item.id,
                        name: product ? product.name : item.name,
                        price: product ? Number(product.price) : Number(item.price),
                        available: Number.isFinite(availableQty) ? availableQty : 0,
                        qty: Number(item.qty) || 1,
                        max: Math.max(Number(item.qty) || 1, Number.isFinite(availableQty) ? availableQty : 0),
                    });
                });

                updateEmptyState();
                recalcTotals();
            }
            // End POS cart state restorer

            // Begin POS product picker opener
            function openProductModal() {
                productModal.style.display = 'flex';
                productSearchInput.value = '';
                renderProductTable();
                productSearchInput.focus();
            }
            // End POS product picker opener

            // Begin POS product picker closer
            function closeProductModal() {
                productModal.style.display = 'none';
            }
            // End POS product picker closer

            // Begin POS product table renderer
            function renderProductTable(filter = '') {
                if (!productSearchTableBody) {
                    return;
                }

                // Get selected category and brand filter values
                const selectedCategory = document.getElementById('categoryFilter')?.value || '';
                const selectedBrand = document.getElementById('brandFilter')?.value || '';

                const normalisedFilter = filter.toLowerCase();

                // Filter products by name, category, and brand
                const filteredProducts = productCatalog.filter((item) => {
                    const matchesName = normalisedFilter === '' || item.name.toLowerCase().includes(normalisedFilter);
                    const matchesCategory = selectedCategory === '' || (item.category && item.category === selectedCategory);
                    const matchesBrand = selectedBrand === '' || (item.brand && item.brand === selectedBrand);
                    return matchesName && matchesCategory && matchesBrand;
                });

                productSearchTableBody.innerHTML = '';

                if (filteredProducts.length === 0) {
                    const row = document.createElement('tr');
                    const cell = document.createElement('td');
                    cell.colSpan = 4;
                    cell.textContent = 'No products found.';
                    cell.style.textAlign = 'center';
                    cell.style.color = '#888';
                    cell.style.padding = '16px';
                    row.appendChild(cell);
                    productSearchTableBody.appendChild(row);
                    return;
                }

                filteredProducts.forEach((product) => {
                    const row = document.createElement('tr');

                    const nameCell = document.createElement('td');
                    nameCell.textContent = product.name;
                    row.appendChild(nameCell);

                    const priceCell = document.createElement('td');
                    priceCell.textContent = formatPeso(product.price);
                    priceCell.style.textAlign = 'right';
                    row.appendChild(priceCell);

                    const stockCell = document.createElement('td');
                    stockCell.textContent = product.quantity;
                    stockCell.style.textAlign = 'center';
                    row.appendChild(stockCell);

                    const actionCell = document.createElement('td');
                    actionCell.style.textAlign = 'center';

                    if (Number(product.quantity) > 0) {
                        const checkbox = document.createElement('input');
                        checkbox.type = 'checkbox';
                        checkbox.className = 'product-select-checkbox';
                        checkbox.dataset.id = product.id;
                        actionCell.appendChild(checkbox);
                    } else {
                        const span = document.createElement('span');
                        span.textContent = 'Out of Stock';
                        span.style.color = '#e74c3c';
                        span.style.fontSize = '13px';
                        actionCell.appendChild(span);
                    }

                    row.appendChild(actionCell);
                    productSearchTableBody.appendChild(row);
                });
            }
            // End POS product table renderer

            // Begin POS tab switcher
            function setActiveTab(tabName, options = {}) {
                if (!['walkin', 'online'].includes(tabName)) {
                    return;
                }

                const { skipPersistence = false } = options;

                tabButtons.forEach((button) => {
                    button.classList.toggle('active', button.dataset.tab === tabName);
                });

                Object.entries(tabPanels).forEach(([name, panel]) => {
                    panel.classList.toggle('active', name === tabName);
                });

                if (!skipPersistence) {
                    try {
                        localStorage.setItem(tabStateKey, tabName);
                    } catch (error) {
                        console.error('Unable to persist POS tab state.', error);
                    }

                    const url = new URL(window.location.href);
                    url.searchParams.set('tab', tabName);
                    window.history.replaceState({}, document.title, url.toString());
                }
            }
            // End POS tab switcher

            // Begin POS tab initialiser
            function initialiseActiveTab() {
                const url = new URL(window.location.href);
                const paramTab = url.searchParams.get('tab');
                if (['walkin', 'online'].includes(paramTab)) {
                    setActiveTab(paramTab);
                    return;
                }

                let storedTab = null;
                try {
                    storedTab = localStorage.getItem(tabStateKey);
                } catch (error) {
                    storedTab = null;
                }

                if (['walkin', 'online'].includes(storedTab)) {
                    setActiveTab(storedTab, { skipPersistence: true });
                } else {
                    setActiveTab(initialActiveTab, { skipPersistence: true });
                }
            }
            // End POS tab initialiser

            // Begin POS success param cleaner
            function cleanupSuccessParams() {
                const url = new URL(window.location.href);
                ['ok', 'order_id', 'amount_paid', 'change', 'invoice_number'].forEach((param) => url.searchParams.delete(param));
                window.history.replaceState({}, document.title, url.toString());
            }
            // End POS success param cleaner

            // Begin POS receipt generator from transaction query
            function generateReceiptFromTransaction() {
                const params = new URLSearchParams(window.location.search);
                if (params.get('ok') !== '1') {
                    return;
                }

                const hasServerReceipt = Boolean(
                    checkoutReceipt &&
                    Array.isArray(checkoutReceipt.items) &&
                    checkoutReceipt.items.length > 0
                );

                let items = [];
                let salesTotal = 0;
                let discount = 0;
                let vatable = 0;
                let vat = 0;
                let amountPaid = 0;
                let change = 0;
                let cashierName = 'Admin';
                let createdAt = new Date();
                let orderId = params.get('order_id') || '';
                let invoiceNumber = params.get('invoice_number') || '';

                if (hasServerReceipt) {
                    items = checkoutReceipt.items.map((item) => {
                        const price = Number(item.price) || 0;
                        const qty = Number(item.qty) || 0;
                        const total = Number(item.total);
                        return {
                            name: String(item.name || 'Item'),
                            price,
                            qty,
                            total: Number.isFinite(total) ? total : price * qty,
                        };
                    });

                    salesTotal = Number(checkoutReceipt.sales_total) || items.reduce((sum, item) => sum + item.total, 0);
                    discount = Number(checkoutReceipt.discount) || 0;
                    vatable = Number(checkoutReceipt.vatable);
                    vat = Number(checkoutReceipt.vat);
                    amountPaid = Number(checkoutReceipt.amount_paid) || parseFloat(params.get('amount_paid') || '0');
                    change = Number(checkoutReceipt.change) || parseFloat(params.get('change') || '0');
                    cashierName = checkoutReceipt.cashier || cashierName;

                    if (checkoutReceipt.order_id) {
                        orderId = checkoutReceipt.order_id;
                    }

                     if (checkoutReceipt.invoice_number) {
                        invoiceNumber = checkoutReceipt.invoice_number;
                    }

                    if (checkoutReceipt.created_at) {
                        const parsedDate = new Date(checkoutReceipt.created_at);
                        if (!Number.isNaN(parsedDate.getTime())) {
                            createdAt = parsedDate;
                        }
                    }

                    if (!Number.isFinite(vatable) || vatable <= 0) {
                        vatable = salesTotal / 1.12;
                    }

                    if (!Number.isFinite(vat) || vat < 0) {
                        vat = salesTotal - vatable;
                    }
                } else {
                    let savedRows = [];
                    try {
                        savedRows = JSON.parse(localStorage.getItem(posStateKey) || '[]');
                    } catch (error) {
                        savedRows = [];
                    }

                    if (savedRows.length === 0) {
                        cleanupSuccessParams();
                        return;
                    }

                    items = savedRows.map((item) => {
                        const price = Number(item.price) || 0;
                        const qty = Number(item.qty) || 0;
                        return {
                            name: String(item.name || 'Item'),
                            price,
                            qty,
                            total: price * qty,
                        };
                    });

                    salesTotal = items.reduce((sum, item) => sum + item.total, 0);
                    amountPaid = parseFloat(params.get('amount_paid') || '0');
                    change = parseFloat(params.get('change') || '0');
                    vatable = salesTotal / 1.12;
                    vat = salesTotal - vatable;
                    cashierName = 'Admin';
                    createdAt = new Date();
                }

                receiptItemsBody.innerHTML = '';
                items.forEach((item) => {
                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td style="text-align:left;">${item.name}</td>
                        <td style="text-align:right;">${item.qty}</td>
                        <td style="text-align:right;">${formatPeso(item.price)}</td>
                        <td style="text-align:right;">${formatPeso(item.total)}</td>
                    `;
                    receiptItemsBody.appendChild(row);
                });

                if (!invoiceNumber) {
                    invoiceNumber = orderId ? `INV-${orderId}` : `INV-${Date.now()}`;
                }

                document.getElementById('receiptNumber').textContent = invoiceNumber;
                document.getElementById('receiptDate').textContent = Number.isNaN(createdAt.getTime()) ? new Date().toLocaleString() : createdAt.toLocaleString();
                document.getElementById('receiptCashier').textContent = cashierName || 'Admin';
                document.getElementById('receiptSalesTotal').textContent = formatPeso(salesTotal);
                document.getElementById('receiptDiscount').textContent = formatPeso(discount);
                document.getElementById('receiptVatable').textContent = formatPeso(vatable);
                document.getElementById('receiptVat').textContent = formatPeso(vat);
                document.getElementById('receiptAmountPaid').textContent = formatPeso(amountPaid);
                document.getElementById('receiptChange').textContent = formatPeso(change);

                receiptModal.style.display = 'flex';

                if (!hasShownCheckoutAlert) {
                    alert('Payment settled successfully.');
                    hasShownCheckoutAlert = true;
                }

                clearTable();
                cleanupSuccessParams();
            }
            // End POS receipt generator from transaction query

            // Begin POS receipt modal closer
            function closeReceiptModal() {
                receiptModal.style.display = 'none';
            }
            // End POS receipt modal closer

            // Begin POS receipt printer helper
            function printReceipt() {
                const receiptContentElement = document.getElementById('receiptContent');
                if (!receiptContentElement) {
                    return;
                }

                const w = window.open('', '_blank');
                if (!w) {
                    alert('Unable to open the receipt print preview. Please allow pop-ups for this site.');
                    return;
                }

                w.document.write(`
                    <html>
                        <head>
                            <title>Print Receipt</title>
                            <style>
                                body { font-family: 'Courier New', monospace; font-size: 14px; }
                                @media print {
                                    @page { margin: 0; }
                                    body { margin: 1cm; }
                                }
                            </style>
                        </head>
                        <body>${receiptContentElement.innerHTML}</body>
                    </html>
                `);
                w.document.close();

                const triggerPrint = () => {
                    w.focus();
                    if ('onafterprint' in w) {
                        w.addEventListener('afterprint', () => w.close(), { once: true });
                    } else {
                        setTimeout(() => {
                            try {
                                w.close();
                            } catch (error) {
                                console.error('Unable to close print preview window.', error);
                            }
                        }, 500);
                    }
                    w.print();
                };

                if (w.document.readyState === 'complete') {
                    triggerPrint();
                } else {
                    w.addEventListener('load', triggerPrint, { once: true });
                }
            }
            // End POS receipt printer helper

            // Begin POS proof modal closer
            function closeProofModal() {
                proofModal.classList.remove('show');
                proofModal.setAttribute('aria-hidden', 'true');
                proofImage.removeAttribute('src');
                proofImage.style.display = 'none';
                proofNoImage.style.display = 'none';
            }
            // End POS proof modal closer

            // Event bindings
            mobileToggle?.addEventListener('click', () => {
                sidebar?.classList.toggle('mobile-open');
            });

            // Add event listeners for category and brand filters to re-render product table on change
            const categoryFilterSelect = document.getElementById('categoryFilter');
            const brandFilterSelect = document.getElementById('brandFilter');

            categoryFilterSelect?.addEventListener('change', () => {
                renderProductTable(productSearchInput.value.trim());
            });

            brandFilterSelect?.addEventListener('change', () => {
                renderProductTable(productSearchInput.value.trim());
            });

            document.addEventListener('click', (event) => {
                if (window.innerWidth <= 768 && sidebar && mobileToggle) {
                    if (!sidebar.contains(event.target) && !mobileToggle.contains(event.target)) {
                        sidebar.classList.remove('mobile-open');
                    }
                }
            });

            userAvatar?.addEventListener('click', () => {
                if (userDropdown) {
                    userDropdown.classList.toggle('show');
                }
            });

            document.addEventListener('click', (event) => {
                if (userMenu && userDropdown && !userMenu.contains(event.target)) {
                    userDropdown.classList.remove('show');
                }
            });

            profileButton?.addEventListener('click', (event) => {
                event.preventDefault();
                userDropdown?.classList.remove('show');
                openProfileModal();
            });

            profileModalClose?.addEventListener('click', () => {
                closeProfileModal();
            });

            profileModal?.addEventListener('click', (event) => {
                if (event.target === profileModal) {
                    closeProfileModal();
                }
            });

            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') {
                    if (profileModal?.classList.contains('show')) {
                        closeProfileModal();
                    }

                    if (onlineOrderModal && onlineOrderModal.style.display !== 'none') {
                        closeOnlineOrderModalOverlay();
                    }
                }
            });

            openProductModalButton?.addEventListener('click', openProductModal);
            closeProductModalButton?.addEventListener('click', closeProductModal);
            productModal?.addEventListener('click', (event) => {
                if (event.target === productModal) {
                    closeProductModal();
                }
            });

            productSearchInput?.addEventListener('input', (event) => {
                renderProductTable(event.target.value.trim());
            });

            addSelectedProductsButton?.addEventListener('click', () => {
                const selected = productModal.querySelectorAll('.product-select-checkbox:checked');
                if (selected.length === 0) {
                    alert('Please select at least one product to add.');
                    return;
                }

                selected.forEach((checkbox) => {
                    addProductById(checkbox.dataset.id);
                    checkbox.checked = false;
                });

                closeProductModal();
            });

            posTableBody.addEventListener('click', (event) => {
                const removeButton = event.target.closest('.remove-btn');
                if (removeButton) {
                    event.preventDefault();
                    const row = removeButton.closest('tr');
                    if (row) {
                        row.remove();
                        updateEmptyState();
                        recalcTotals();
                        persistTableState();
                    }
                }
            });

            posTableBody.addEventListener('focusin', (event) => {
                if (!event.target.classList.contains('pos-qty')) {
                    return;
                }

                const input = event.target;
                const min = parseInt(input.min, 10) || 1;
                const currentValue = parseInt(input.value, 10);
                const safeValue = Number.isFinite(currentValue) && currentValue >= min ? currentValue : min;

                input.dataset.previousValidValue = String(safeValue);
            });

            posTableBody.addEventListener('input', (event) => {
                if (event.target.classList.contains('pos-qty')) {
                    const input = event.target;
                    const min = parseInt(input.min, 10) || 1;
                    const max = parseInt(input.max, 10);
                    let value = parseInt(input.value, 10);

                    if (!Number.isFinite(value) || value < min) {
                        value = min;
                        input.value = value;
                        input.dataset.previousValidValue = String(value);
                        recalcTotals();
                        persistTableState();
                        return;
                    }

                    if (Number.isFinite(max) && max > 0 && value > max) {

                         alert(`Only ${max} stock available.`);
                        value = max;

                       
                        const previous = parseInt(input.dataset.previousValidValue || '', 10);
                        const fallback = Number.isFinite(previous) && previous >= min ? previous : min;
                        input.value = fallback;
                        input.dataset.previousValidValue = String(fallback);
                        input.focus();
                        input.select();
                        recalcTotals();
                        persistTableState();
                        return;

                    }

                    input.dataset.previousValidValue = String(value);
                    input.value = value;
                    recalcTotals();
                    persistTableState();
                }
            });

            amountReceivedInput?.addEventListener('input', () => {
                recalcTotals();
                updateSettleButtonState();
            });

            clearPosTableButton?.addEventListener('click', () => {
                clearTable();
            });

            posForm?.addEventListener('submit', (event) => {
                const rows = posTableBody.querySelectorAll('tr');
                if (rows.length === 0) {
                    event.preventDefault();
                    closeProductModal();
                    alert('No item selected in POS!');
                    return;
                }

                const salesTotal = getSalesTotal();
                const amountReceived = parseFloat(amountReceivedInput.value || '0');

                if (amountReceived <= 0) {
                    event.preventDefault();
                    alert('Please enter the amount received from the customer!');
                    amountReceivedInput.focus();
                    return;
                }

                if (amountReceived < salesTotal) {
                    event.preventDefault();
                    const shortage = salesTotal - amountReceived;
                    alert(`Insufficient payment! Need ${formatPeso(shortage)} more.`);
                    amountReceivedInput.focus();
                }
            });

            tabButtons.forEach((button) => {
                button.addEventListener('click', () => {
                    setActiveTab(button.dataset.tab);
                });
            });

            const statusAlert = document.querySelector('.status-alert');
            if (statusAlert) {
                const url = new URL(window.location.href);
                url.searchParams.delete('status_updated');
                window.history.replaceState({}, document.title, url.toString());
            }

            document.querySelectorAll('.view-proof-btn').forEach((button) => {
                button.addEventListener('click', () => {
                    const image = button.dataset.image;
                    const reference = button.dataset.reference || '';
                    const customer = button.dataset.customer || 'Customer';

                    proofReferenceValue.textContent = reference !== '' ? reference : 'Not provided';
                    proofCustomerName.textContent = customer;

                    if (image) {
                        proofImage.src = image;
                        proofImage.style.display = 'block';
                        proofNoImage.style.display = 'none';
                    } else {
                        proofImage.removeAttribute('src');
                        proofImage.style.display = 'none';
                        proofNoImage.style.display = 'flex';
                    }

                    proofModal.classList.add('show');
                    proofModal.setAttribute('aria-hidden', 'false');
                });
            });

            document.querySelectorAll('.online-order-row').forEach((row) => {
                row.addEventListener('click', async (event) => {
                    if (
                        event.target.closest('.status-form') ||
                        event.target.closest('.view-proof-btn') ||
                        event.target.tagName === 'BUTTON' ||
                        event.target.tagName === 'SELECT'
                    ) {
                        return;
                    }

                    const orderId = row.dataset.orderId;
                    if (!orderId) {
                        return;
                    }

                    try {
                        const response = await fetch(`get_transaction_details.php?order_id=${encodeURIComponent(orderId)}`);
                        if (!response.ok) {
                            throw new Error(`HTTP error! status: ${response.status}`);
                        }

                        const data = await response.json();
                        populateOnlineOrderModal(data.order || {}, Array.isArray(data.items) ? data.items : []);
                        openOnlineOrderModalOverlay();
                    } catch (error) {
                        console.error('Unable to load online order details.', error);
                        alert('Failed to load order details. Please try again.');
                    }
                });
            });

            closeProofModalButton?.addEventListener('click', closeProofModal);
            proofModal?.addEventListener('click', (event) => {
                if (event.target === proofModal) {
                    closeProofModal();
                }
            });

            // Status filter functionality
            const statusFilter = document.getElementById('statusFilter');
            statusFilter?.addEventListener('change', (event) => {
                const selectedStatus = event.target.value;
                const url = new URL(window.location.href);
                url.searchParams.set('tab', 'online');
                url.searchParams.set('page', '1'); // Reset to first page when filtering
                
                if (selectedStatus) {
                    url.searchParams.set('status_filter', selectedStatus);
                } else {
                    url.searchParams.delete('status_filter');
                }
                
                window.location.href = url.toString();
            });

            closeOnlineOrderModalButton?.addEventListener('click', closeOnlineOrderModalOverlay);
            onlineOrderModal?.addEventListener('click', (event) => {
                if (event.target === onlineOrderModal) {
                    closeOnlineOrderModalOverlay();
                }
            });

            closeReceiptModalButton?.addEventListener('click', closeReceiptModal);
            receiptModal?.addEventListener('click', (event) => {
                if (event.target === receiptModal) {
                    closeReceiptModal();
                }
            });

            printReceiptButton?.addEventListener('click', printReceipt);

            // Initialisation
            updateSettleButtonState();
            const urlParams = new URLSearchParams(window.location.search);
            const shouldRestoreTableState = !(
                urlParams.get('ok') === '1' &&
                checkoutReceipt &&
                Array.isArray(checkoutReceipt.items) &&
                checkoutReceipt.items.length > 0
            );

            if (shouldRestoreTableState) {
                restoreTableState();
            } else {
                try {
                    localStorage.removeItem(posStateKey);
                } catch (error) {
                    console.error('Unable to clear POS state after checkout.', error);
                }
                updateEmptyState();
                recalcTotals();
            }

            initialiseActiveTab();
            generateReceiptFromTransaction();
        });
        // End POS main interaction script
        // file 2 end
