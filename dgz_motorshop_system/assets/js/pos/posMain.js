// file 2 start – POS page behavior bundle (safe to extract)
// Begin POS main interaction script
// Provide minimal polyfills for legacy browsers that do not support
// Element.matches/closest or Array.from so the POS scripts keep working.
(function ensurePosDomPolyfills() {
    if (typeof Element !== 'undefined') {
        if (!Element.prototype.matches) {
            Element.prototype.matches = Element.prototype.msMatchesSelector
                || Element.prototype.webkitMatchesSelector
                || function matches(selector) {
                    const nodeList = (this.document || this.ownerDocument).querySelectorAll(selector);
                    let i = nodeList.length;
                    while (--i >= 0 && nodeList.item(i) !== this) {
                        // continue
                    }
                    return i > -1;
                };
        }
        if (!Element.prototype.closest) {
            Element.prototype.closest = function closest(selector) {
                let element = this;
                while (element && element.nodeType === 1) {
                    if (element.matches(selector)) {
                        return element;
                    }
                    element = element.parentElement || element.parentNode;
                }
                return null;
            };
        }
    }
    if (typeof Array.from !== 'function') {
        Array.from = function from(iterable) {
            return Array.prototype.slice.call(iterable);
        };
    }
})();

const {
    productCatalog = [],
    initialActiveTab = 'walkin',
    checkoutReceipt = null,
    declineReasons: declineReasonsBootstrap = [],
    onlineOrders: onlineOrdersBootstrap = {},
} = window.dgzPosData || {};

const DELIVERY_PROOF_HELP_FALLBACK =
    typeof onlineOrdersBootstrap.deliveryProofHelp === 'string'
        ? onlineOrdersBootstrap.deliveryProofHelp
        : 'Upload a photo confirming the delivery.';

document.addEventListener('DOMContentLoaded', () => {
            const posStateKey = 'posTable';
            const tabStateKey = 'posActiveTab';
            let hasShownCheckoutAlert = false;
            let customLineCounter = 0;

            const sidebar = document.getElementById('sidebar');
            const showPosAlert = (message, options) => {
                if (window.dgzAlert && typeof window.dgzAlert === 'function') {
                    return window.dgzAlert(message, options);
                }
                window.alert(String(message != null ? message : ''));
                return Promise.resolve();
            };
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
            const addServiceButton = document.getElementById('addServiceButton');
            const serviceModal = document.getElementById('serviceModal');
            const closeServiceModalButton = document.getElementById('closeServiceModal');
            const serviceForm = document.getElementById('serviceForm');
            const serviceNameInput = document.getElementById('serviceName');
            const servicePriceInput = document.getElementById('servicePrice');
            const serviceQtyInput = document.getElementById('serviceQty');

            const variantModal = document.getElementById('variantModal');
            const closeVariantModalButton = document.getElementById('closeVariantModal');
            const variantOptionsContainer = document.getElementById('variantOptions');
            const variantModalEmpty = document.getElementById('variantModalEmpty');
            const variantModalTitle = document.getElementById('variantModalTitle');
            const variantModalSubtitle = document.getElementById('variantModalSubtitle');
            // Queue storing products that still need variant selections so we can
            // walk the user through them one at a time when multiple items are picked.
            const pendingVariantProducts = [];

            const receiptModal = document.getElementById('receiptModal');
            const closeReceiptModalButton = document.getElementById('closeReceiptModal');
            const printReceiptButton = document.getElementById('printReceiptButton');
            const receiptItemsBody = document.getElementById('receiptItemsBody');

            const proofModal = document.getElementById('proofModal');
            const proofImage = document.getElementById('proofImage');
            const proofReferenceValue = document.getElementById('proofReferenceValue');
            const proofCustomerName = document.getElementById('proofCustomerName');
            const proofTitle = proofModal ? proofModal.querySelector('.proof-title') : null;
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
            const onlineOrderAddress = document.getElementById('onlineOrderAddress');
            const onlineOrderPostal = document.getElementById('onlineOrderPostal');
            const onlineOrderCity = document.getElementById('onlineOrderCity');
            const onlineOrderNoteContainer = document.getElementById('onlineOrderNoteContainer');
            const onlineOrderNote = document.getElementById('onlineOrderNote');
            const onlineOrderReferenceWrapper = document.getElementById('onlineOrderReferenceWrapper');
            const onlineOrderReference = document.getElementById('onlineOrderReference');
            const onlineOrderItemsBody = document.getElementById('onlineOrderItemsBody');
            const onlineOrderTotal = document.getElementById('onlineOrderTotal');
            const onlineOrdersTableBody = document.querySelector('[data-online-orders-body]');
            const onlineOrdersSummary = document.querySelector('[data-online-orders-summary]');
            const onlineOrdersTabCount = document.querySelector('[data-online-orders-count]');
            let sidebarPosCount = document.querySelector('[data-sidebar-pos-count]');
            const onlineOrdersPaginationContainer = document.querySelector('[data-online-orders-pagination]');
            const onlineOrdersPaginationBody = document.querySelector('[data-online-orders-pagination-body]');
            const onlineOrdersContainer = document.querySelector('.online-orders-container');
            const statusFilterButtons = Array.prototype.slice.call(
                document.querySelectorAll('[data-status-filter-button]')
            );
            const deliveryProofNoticeBanner = document.querySelector('[data-delivery-proof-notice]');
            const deliveryProofNoticeText = document.querySelector('[data-delivery-proof-text]');

            const tabButtons = document.querySelectorAll('.pos-tab-button');
            const tabPanels = {
                walkin: document.getElementById('walkinTab'),
                online: document.getElementById('onlineTab'),
            };

            const ONLINE_ORDER_POLL_MS = 12000;
            let onlineOrdersPollTimer = null;
            let isFetchingOnlineOrders = false;

            let onlineOrdersState = {
                page: Number(onlineOrdersBootstrap.page) || 1,
                perPage: Number(onlineOrdersBootstrap.perPage) || 15,
                statusFilter: typeof onlineOrdersBootstrap.statusFilter === 'string' ? onlineOrdersBootstrap.statusFilter : '',
                totalOrders: Number(onlineOrdersBootstrap.totalOrders) || 0,
                totalPages: Number(onlineOrdersBootstrap.totalPages) || 1,
                attentionCount: Number(onlineOrdersBootstrap.attentionCount) || 0,
                badgeCount: Number(onlineOrdersBootstrap.badgeCount)
                    || Number(onlineOrdersBootstrap.attentionCount)
                    || 0,
                orders: Array.isArray(onlineOrdersBootstrap.orders) ? onlineOrdersBootstrap.orders : [],
                statusCounts: typeof onlineOrdersBootstrap.statusCounts === 'object'
                    && onlineOrdersBootstrap.statusCounts !== null
                    ? onlineOrdersBootstrap.statusCounts
                    : {},
                deliveryProofSupported: Boolean(onlineOrdersBootstrap.deliveryProofSupported),
                deliveryProofNotice: typeof onlineOrdersBootstrap.deliveryProofNotice === 'string'
                    ? onlineOrdersBootstrap.deliveryProofNotice
                    : '',
                deliveryProofHelp: typeof onlineOrdersBootstrap.deliveryProofHelp === 'string'
                    ? onlineOrdersBootstrap.deliveryProofHelp
                    : DELIVERY_PROOF_HELP_FALLBACK,
            };
            const updateDeliveryProofNotice = () => {
                if (!deliveryProofNoticeBanner) {
                    return;
                }

                const supported = Boolean(onlineOrdersState.deliveryProofSupported);
                const noticeText = typeof onlineOrdersState.deliveryProofNotice === 'string'
                    ? onlineOrdersState.deliveryProofNotice.trim()
                    : '';

                if (supported || noticeText === '') {
                    deliveryProofNoticeBanner.setAttribute('hidden', 'hidden');
                } else {
                    deliveryProofNoticeBanner.removeAttribute('hidden');
                    if (deliveryProofNoticeText) {
                        deliveryProofNoticeText.textContent = noticeText;
                    } else {
                        deliveryProofNoticeBanner.textContent = noticeText;
                    }
                }
            };
            updateDeliveryProofNotice();

            const escapeHtml = (value) => {
                if (value === null || value === undefined) {
                    return '';
                }
                return String(value)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#39;');
            };

            const escapeMultiline = (value) => escapeHtml(value).replace(/\r?\n/g, '<br>');

            const updateStatusFilterButtons = (activeStatus) => {
                const active = typeof activeStatus === 'string' ? activeStatus : '';
                statusFilterButtons.forEach((button) => {
                    const value = button && button.dataset && button.dataset.statusValue
                        ? button.dataset.statusValue
                        : '';
                    const isActive = value === active || (!value && active === '');
                    button.classList.toggle('is-active', isActive);
                    if (button.hasAttribute('aria-pressed')) {
                        button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
                    }
                    if (button.hasAttribute('aria-selected')) {
                        button.setAttribute('aria-selected', isActive ? 'true' : 'false');
                    }
                });
            };

            updateStatusFilterButtons(onlineOrdersState.statusFilter);

            const updateStatusCountBadges = (counts) => {
                const hasCounts = counts && typeof counts === 'object';

                statusFilterButtons.forEach((button) => {
                    if (!button) {
                        return;
                    }

                    const value = button.dataset && button.dataset.statusValue
                        ? button.dataset.statusValue
                        : '';
                    if (!value) {
                        return;
                    }

                    const badge = button.querySelector('[data-status-count-badge]');
                    if (!badge) {
                        return;
                    }

                    const raw = hasCounts ? counts[value] : undefined;
                    let count = typeof raw === 'number' ? raw : parseInt(raw, 10);
                    if (!Number.isFinite(count)) {
                        count = 0;
                    }

                    const formattedCount = Number(count).toLocaleString('en-PH');
                    badge.textContent = formattedCount;

                    if (count > 0) {
                        badge.removeAttribute('data-status-count-empty');
                    } else {
                        badge.setAttribute('data-status-count-empty', 'true');
                    }

                    const label = button.dataset && button.dataset.statusLabel
                        ? button.dataset.statusLabel
                        : '';
                    if (label) {
                        const ariaLabel = `${label} (${formattedCount} orders)`;
                        button.setAttribute('aria-label', ariaLabel);
                        button.setAttribute('title', ariaLabel);
                    }
                });
            };

            updateStatusCountBadges(onlineOrdersState.statusCounts);

            const updateDeliveryProofField = (form) => {
                if (!form) {
                    return;
                }

                const supportsProof = Boolean(onlineOrdersState.deliveryProofSupported);
                const select = form.querySelector('[data-status-select]');
                const proofField = form.querySelector('[data-delivery-proof-field]');
                const fileInput = proofField ? proofField.querySelector('input[type="file"]') : null;
                const help = proofField ? proofField.querySelector('.delivery-proof-help') : null;
                const selectedValue = select ? String(select.value || '') : '';
                const shouldShow = supportsProof && selectedValue === 'completed';
                let defaultHelp = onlineOrdersState.deliveryProofHelp || DELIVERY_PROOF_HELP_FALLBACK;
                if (help && help.dataset && help.dataset.deliveryProofDefault) {
                    defaultHelp = help.dataset.deliveryProofDefault;
                }

                if (proofField) {
                    if (supportsProof) {
                        proofField.classList.remove('is-disabled');
                        if (shouldShow) {
                            proofField.removeAttribute('hidden');
                        } else {
                            proofField.setAttribute('hidden', 'hidden');
                        }
                    } else {
                        proofField.classList.add('is-disabled');
                        proofField.removeAttribute('hidden');
                    }
                }

                if (fileInput) {
                    if (!supportsProof) {
                        fileInput.value = '';
                        fileInput.required = false;
                        fileInput.disabled = true;
                    } else {
                        fileInput.disabled = select ? select.disabled : false;
                        fileInput.required = shouldShow;
                        if (!shouldShow) {
                            fileInput.value = '';
                        }
                    }
                }

                if (help) {
                    if (supportsProof) {
                        help.textContent = defaultHelp;
                        help.classList.remove('delivery-proof-help--warning');
                    } else {
                        const notice = onlineOrdersState.deliveryProofNotice
                            || help.textContent
                            || DELIVERY_PROOF_HELP_FALLBACK;
                        help.textContent = notice;
                        help.classList.add('delivery-proof-help--warning');
                    }
                }
            };

            const initializeStatusForms = () => {
                document.querySelectorAll('.status-form').forEach((form) => {
                    updateDeliveryProofField(form);
                });
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
                delivery: 'Out for Delivery',
                completed: 'Completed',
                disapproved: 'Disapproved',
            };

            const populateOnlineOrderModal = (order, items) => {
                if (!onlineOrderModal) {
                    return;
                }

                const safeCustomer = (order.customer_name || order.full_name || order.name || 'Customer').toString();
                const safeInvoice = (order.invoice_number || order.invoice || order.invoice_no || 'N/A').toString();
                const safeStatus = (order.status || 'pending').toString().toLowerCase();
                const rawPayment = (order.payment_method || order.paymentType || '').toString();
                const safePayment = rawPayment.trim() !== '' ? rawPayment.trim().toUpperCase() : 'N/A';
                const referenceNumber = (order.reference_number || order.reference_no || order.reference || '').toString();

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
                onlineOrderPayment.textContent = safePayment;

                const emailValue = (order.email
                    || order.customer_email
                    || order.contact_email
                    || '').toString();
                onlineOrderEmail.textContent = emailValue !== '' ? emailValue : 'N/A';

                const phoneValue = (order.phone
                    || order.customer_phone
                    || order.contact_number
                    || order.contact
                    || order.mobile
                    || '').toString();
                onlineOrderPhone.textContent = phoneValue !== '' ? phoneValue : 'N/A';

                if (onlineOrderAddress) {
                    const addr = (order.address
                        || order.customer_address
                        || order.shipping_address
                        || '').toString().trim();
                    onlineOrderAddress.textContent = addr !== '' ? addr : 'N/A';
                }
                if (onlineOrderPostal) {
                    const pc = (order.postal_code
                        || order.postal
                        || order.zip_code
                        || order.zipcode
                        || order.zip
                        || '').toString().trim();
                    onlineOrderPostal.textContent = pc !== '' ? pc : 'N/A';
                }
                if (onlineOrderCity) {
                    const city = (order.city
                        || order.town
                        || order.municipality
                        || '').toString().trim();
                    onlineOrderCity.textContent = city !== '' ? city : 'N/A';
                }

                if (onlineOrderNoteContainer && onlineOrderNote) {
                    const noteCandidates = [order.customer_note, order.notes, order.note];
                    let noteText = '';
                    for (let i = 0; i < noteCandidates.length; i += 1) {
                        const value = noteCandidates[i];
                        if (value !== undefined && value !== null) {
                            const trimmed = String(value).trim();
                            if (trimmed !== '') {
                                noteText = trimmed;
                                break;
                            }
                        }
                    }

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

                const totalCandidates = [order.total, order.grand_total, order.amount, order.total_amount];
                let totalAmount = 0;
                for (let i = 0; i < totalCandidates.length; i += 1) {
                    const candidate = Number(totalCandidates[i]);
                    if (Number.isFinite(candidate) && candidate > 0) {
                        totalAmount = candidate;
                        break;
                    }
                }
                if (!Number.isFinite(totalAmount)) {
                    totalAmount = 0;
                }
                onlineOrderTotal.textContent = formatPeso(totalAmount);
            };
            // End POS online order modal populator

            const openProofModalFromButton = (button) => {
                if (!button || button.disabled || button.classList.contains('is-disabled')) {
                    return;
                }

                const image = button.dataset.image || '';
                const fallbackImage = button.dataset.fallbackImage || '';
                const resolvedImage = image || fallbackImage || '';
                const reference = button.dataset.reference || '';
                const customer = button.dataset.customer || 'Customer';
                const proofType = button.dataset.proofType || '';

                if (!proofModal) {
                    if (resolvedImage) {
                        window.open(resolvedImage, '_blank');
                    } else {
                        showPosAlert('No proof has been uploaded yet.');
                    }
                    return;
                }

                if (proofTitle) {
                    proofTitle.textContent = proofType === 'delivery' ? 'Delivery Proof' : 'Payment Proof';
                }

                if (proofReferenceValue) {
                    proofReferenceValue.textContent = reference !== '' ? reference : 'Not provided';
                }

                if (proofCustomerName) {
                    proofCustomerName.textContent = customer;
                }

                if (proofImage) {
                    if (resolvedImage) {
                        proofImage.src = resolvedImage;
                        proofImage.style.display = 'block';
                    } else {
                        proofImage.removeAttribute('src');
                        proofImage.style.display = 'none';
                    }
                }

                if (proofNoImage) {
                    proofNoImage.style.display = resolvedImage ? 'none' : 'flex';
                }

                proofModal.classList.add('show');
                proofModal.style.display = 'flex';
                proofModal.setAttribute('aria-hidden', 'false');
                document.body.classList.add('modal-open');
            };

            const fetchOnlineOrderDetails = async (orderId) => {
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
                    showPosAlert('Failed to load order details. Please try again.');
                }
            };

            const buildOnlineOrdersUrl = (targetPage) => {
                const params = new URLSearchParams();
                params.set('tab', 'online');
                params.set('page', String(targetPage));
                if (onlineOrdersState.statusFilter) {
                    params.set('status_filter', onlineOrdersState.statusFilter);
                }
                return `?${params.toString()}`;
            };

            const updateBadgeElement = (element, count) => {
                if (!element) {
                    return;
                }

                if (count > 0) {
                    element.textContent = String(count);
                    element.hidden = false;
                } else {
                    element.textContent = '0';
                    element.hidden = true;
                }
            };

            const updateOnlineOrderBadges = (count) => {
                if (sidebarPosCount && !sidebarPosCount.isConnected) {
                    sidebarPosCount = null;
                }

                if (!sidebarPosCount && count > 0) {
                    const sidebarLink = document.querySelector('.nav-menu .nav-link[href="pos.php"]');
                    if (sidebarLink) {
                        const badge = document.createElement('span');
                        badge.className = 'nav-badge';
                        badge.setAttribute('data-sidebar-pos-count', '');
                        badge.textContent = '0';
                        badge.hidden = true;
                        sidebarLink.appendChild(badge);
                        sidebarPosCount = badge;
                    }
                }

                updateBadgeElement(onlineOrdersTabCount, count);
                updateBadgeElement(sidebarPosCount, count);
            };

            const renderOnlineOrdersSummary = (onPage, total, meta) => {
                if (!onlineOrdersSummary) {
                    return;
                }
                const totalOrders = Number(total) || 0;
                const page = Number(meta && meta.page) || 1;
                const perPage = Number(meta && meta.perPage) || Number(onlineOrdersState.perPage) || 15;
                const start = totalOrders > 0 ? ((page - 1) * perPage + 1) : 0;
                const end = totalOrders > 0 ? Math.min(page * perPage, totalOrders) : 0;
                onlineOrdersSummary.textContent = `Showing ${start} to ${end} of ${totalOrders} entries`;
            };

            const renderOnlineOrdersPagination = (meta) => {
                if (!onlineOrdersPaginationContainer || !onlineOrdersPaginationBody) {
                    return;
                }

                const totalPages = Number(meta.totalPages) || 1;
                const currentPage = Number(meta.page) || 1;

                if (totalPages <= 1) {
                    onlineOrdersPaginationContainer.hidden = true;
                    onlineOrdersPaginationBody.innerHTML = '';
                    return;
                }

                onlineOrdersPaginationContainer.hidden = false;

                const startPage = Math.max(1, currentPage - 2);
                const endPage = Math.min(totalPages, currentPage + 2);

                const fragments = [];

                if (currentPage > 1) {
                    fragments.push(`<a href="${buildOnlineOrdersUrl(currentPage - 1)}" class="prev"><i class=\"fas fa-chevron-left\"></i> Prev</a>`);
                } else {
                    fragments.push('<span class="prev disabled"><i class="fas fa-chevron-left"></i> Prev</span>');
                }

                if (startPage > 1) {
                    fragments.push(`<a href="${buildOnlineOrdersUrl(1)}">1</a>`);
                    if (startPage > 2) {
                        fragments.push('<span>...</span>');
                    }
                }

                for (let pageIndex = startPage; pageIndex <= endPage; pageIndex += 1) {
                    if (pageIndex === currentPage) {
                        fragments.push(`<span class=\"current\">${pageIndex}</span>`);
                    } else {
                        fragments.push(`<a href="${buildOnlineOrdersUrl(pageIndex)}">${pageIndex}</a>`);
                    }
                }

                if (endPage < totalPages) {
                    if (endPage < totalPages - 1) {
                        fragments.push('<span>...</span>');
                    }
                    fragments.push(`<a href="${buildOnlineOrdersUrl(totalPages)}">${totalPages}</a>`);
                }

                if (currentPage < totalPages) {
                    fragments.push(`<a href="${buildOnlineOrdersUrl(currentPage + 1)}" class="next">Next <i class=\"fas fa-chevron-right\"></i></a>`);
                } else {
                    fragments.push('<span class="next disabled">Next <i class="fas fa-chevron-right"></i></span>');
                }

                onlineOrdersPaginationBody.innerHTML = fragments.join('');
            };

            const buildOnlineOrderRow = (order) => {
                const row = document.createElement('tr');
                row.className = 'online-order-row';

                const orderId = order && order.id !== undefined ? order.id : '';
                const statusValue = (order && order.status_value) ? String(order.status_value) : 'pending';
                const statusLabel = order && order.status_label ? String(order.status_label) : statusValue;
                const badgeClass = order && order.status_badge_class ? String(order.status_badge_class) : `status-${statusValue}`;
                const referenceNumber = order && order.reference_number ? String(order.reference_number) : '';
                const contactDisplayRaw = order && order.contact_display ? String(order.contact_display) : '';
                const contactDisplay = contactDisplayRaw.trim() !== '' ? contactDisplayRaw : '—';
                const proofImageUrl = order && order.proof_image_url ? String(order.proof_image_url) : '';
                const proofType = order && order.proof_type ? String(order.proof_type) : 'payment';
                const proofLabel = order && order.proof_button_label
                    ? String(order.proof_button_label)
                    : (proofType === 'delivery' ? 'Delivery Proof' : 'Payment Proof');
                const proofIcon = proofType === 'delivery' ? 'fa-truck' : 'fa-receipt';
                const paymentProofUrl = order && order.payment_proof_url ? String(order.payment_proof_url) : '';
                const deliveryProofUrl = order && order.delivery_proof_url ? String(order.delivery_proof_url) : '';
                const hasPaymentProof = Boolean(order && order.has_payment_proof);
                const hasDeliveryProof = Boolean(order && order.has_delivery_proof);
                const proofFallbackUrl = proofImageUrl;
                const showCompletedProofs = ['completed', 'complete'].includes(statusValue);
                const availableStatusChanges = Array.isArray(order && order.available_status_changes)
                    ? order.available_status_changes
                    : [];
                const statusFormHidden = Boolean(order && order.status_form_hidden);
                const statusFormDisabled = statusFormHidden
                    ? true
                    : (order && order.status_form_disabled ? Boolean(order.status_form_disabled) : false)
                        || availableStatusChanges.length === 0;
                const defaultNextStatus = (!statusFormDisabled && availableStatusChanges.length > 0)
                    ? String(availableStatusChanges[0].value || '')
                    : '';
                let proofSupported = onlineOrdersState.deliveryProofSupported;
                if (order && order.delivery_proof_supported !== undefined && order.delivery_proof_supported !== null) {
                    proofSupported = order.delivery_proof_supported;
                }
                proofSupported = Boolean(proofSupported);
                const proofFieldHidden = proofSupported ? defaultNextStatus !== 'completed' : false;
                const proofFieldClasses = `delivery-proof-field${proofSupported ? '' : ' is-disabled'}`;
                const proofInputDisabled = statusFormDisabled || !proofSupported;
                const proofInputRequired = proofSupported && defaultNextStatus === 'completed';
                const proofInputId = `delivery-proof-${orderId}`;
                const declineReasonLabel = order && order.decline_reason_label ? String(order.decline_reason_label) : '';
                const declineReasonNote = order && order.decline_reason_note ? String(order.decline_reason_note) : '';

                row.classList.add('online-order-row');
                row.dataset.orderId = String(orderId);
                const declineReasonId = order && order.decline_reason_id !== undefined && order.decline_reason_id !== null
                    ? order.decline_reason_id
                    : 0;
                row.dataset.declineReasonId = String(declineReasonId);
                row.dataset.declineReasonLabel = declineReasonLabel;
                row.dataset.declineReasonNote = declineReasonNote;

                const statusOptionsHtml = statusFormDisabled
                    ? `<option value="">${escapeHtml(statusLabel)}</option>`
                    : availableStatusChanges
                        .map((option) => `<option value="${escapeHtml(option.value)}">${escapeHtml(option.label)}</option>`)
                        .join('');

                const declineHtml = statusValue === 'disapproved' && declineReasonLabel !== ''
                    ? `<div class="decline-reason-display">Reason: ${escapeHtml(declineReasonLabel)}${
                        declineReasonNote !== ''
                            ? `<br><span class="decline-reason-note">Details: ${escapeMultiline(declineReasonNote)}</span>`
                            : ''
                    }</div>`
                    : '';

                const statusFormHtml = statusFormHidden
                    ? ''
                    : `
                        <form method="post" class="status-form" enctype="multipart/form-data">
                            <input type="hidden" name="order_id" value="${escapeHtml(orderId)}">
                            <input type="hidden" name="update_order_status" value="1">
                            <input type="hidden" name="decline_reason_id" value="">
                            <input type="hidden" name="decline_reason_note" value="">
                            <div class="status-action-row">
                                <select name="new_status" ${statusFormDisabled ? 'disabled' : ''} data-status-select>
                                    ${statusOptionsHtml}
                                </select>
                                <button type="submit" class="status-save" ${statusFormDisabled ? 'disabled' : ''}>Update</button>
                            </div>
                            <div class="${escapeHtml(proofFieldClasses)}" data-delivery-proof-field ${proofFieldHidden ? 'hidden' : ''}>
                                <label for="${escapeHtml(proofInputId)}">Proof of delivery</label>
                                <input type="file" name="delivery_proof" id="${escapeHtml(proofInputId)}" accept="image/*" ${proofInputDisabled ? 'disabled' : ''} ${proofInputRequired ? 'required' : ''}>
                            </div>
                        </form>
                    `;

                const buildProofButton = (type, label, icon, imageUrl, fallbackUrl, enabled) => {
                    const primary = imageUrl || '';
                    const fallback = fallbackUrl || '';
                    const isEnabled = Boolean(enabled) && (primary !== '' || fallback !== '');
                    const classes = `view-proof-btn${isEnabled ? '' : ' is-disabled'}`;
                    const disabledAttr = isEnabled ? '' : ' disabled';
                    return `
                        <button type="button" class="${classes}"
                            data-image="${escapeHtml(primary)}"
                            data-fallback-image="${escapeHtml(fallback)}"
                            data-reference="${escapeHtml(referenceNumber)}"
                            data-customer="${escapeHtml((order && order.customer_name) || 'Customer')}"
                            data-proof-type="${escapeHtml(type)}"${disabledAttr}>
                            <i class="fas ${escapeHtml(icon)}"></i> ${escapeHtml(label)}
                        </button>
                    `;
                };

                const proofButtonsHtml = showCompletedProofs
                    ? `
                        <div class="proof-button-group">
                            ${buildProofButton('payment', 'View Payment Proof', 'fa-receipt', paymentProofUrl, proofFallbackUrl, hasPaymentProof)}
                            ${buildProofButton('delivery', 'View Delivery Proof', 'fa-truck', deliveryProofUrl, deliveryProofUrl, hasDeliveryProof)}
                        </div>
                    `
                    : buildProofButton(
                        proofType,
                        `View ${proofLabel}`,
                        proofIcon,
                        proofImageUrl,
                        proofFallbackUrl,
                        proofType === 'delivery' ? hasDeliveryProof : hasPaymentProof,
                    );

                row.innerHTML = `
                    <td>#${escapeHtml(orderId)}</td>
                    <td>${escapeHtml((order && order.customer_name) || 'Customer')}</td>
                    <td>${escapeHtml(contactDisplay)}</td>
                    <td>${escapeHtml((order && order.total_formatted) || '₱0.00')}</td>
                    <td>${referenceNumber !== ''
                        ? `<span class="reference-badge">${escapeHtml(referenceNumber)}</span>`
                        : '<span class="muted">Not provided</span>'}
                    </td>
                    <td>${proofButtonsHtml}</td>
                    <td>
                        <span class="status-badge ${escapeHtml(badgeClass)}">${escapeHtml(statusLabel)}</span>
                        ${statusFormHtml}
                        ${declineHtml}
                    </td>
                    <td>${escapeHtml((order && order.created_at_formatted) || 'N/A')}</td>
                `;

                if (!statusFormHidden) {
                    updateDeliveryProofField(row.querySelector('.status-form'));
                }

                return row;
            };

            const renderOnlineOrdersTable = (orders) => {
                if (!onlineOrdersTableBody) {
                    return;
                }

                onlineOrdersTableBody.innerHTML = '';

                if (!Array.isArray(orders) || orders.length === 0) {
                    const emptyRow = document.createElement('tr');
                    const emptyCell = document.createElement('td');
                    emptyCell.colSpan = 8;
                    emptyCell.className = 'empty-cell';
                    emptyCell.innerHTML = '<i class="fas fa-inbox"></i> No online orders yet.';
                    emptyRow.appendChild(emptyCell);
                    onlineOrdersTableBody.appendChild(emptyRow);
                    return;
                }

                orders.forEach((order) => {
                    onlineOrdersTableBody.appendChild(buildOnlineOrderRow(order));
                });
            };

            const applyOnlineOrdersData = (data) => {
                if (!data) {
                    return;
                }

                onlineOrdersState = {
                    page: Number(data.page) || onlineOrdersState.page,
                    perPage: Number(data.per_page) || onlineOrdersState.perPage,
                    statusFilter: typeof data.status_filter === 'string' ? data.status_filter : onlineOrdersState.statusFilter,
                    totalOrders: Number(data.total_orders) || 0,
                    totalPages: Number(data.total_pages) || 1,
                    attentionCount: Number(data.attention_count) || 0,
                    badgeCount: Number(data.badge_count)
                        || Number(data.attention_count)
                        || onlineOrdersState.badgeCount
                        || 0,
                    orders: Array.isArray(data.orders) ? data.orders : [],
                    statusCounts: data.status_counts && typeof data.status_counts === 'object'
                        ? data.status_counts
                        : onlineOrdersState.statusCounts,
                    deliveryProofSupported: Boolean(
                        Object.prototype.hasOwnProperty.call(data, 'delivery_proof_supported')
                            ? data.delivery_proof_supported
                            : onlineOrdersState.deliveryProofSupported,
                    ),
                    deliveryProofNotice: typeof data.delivery_proof_notice === 'string'
                        ? data.delivery_proof_notice
                        : onlineOrdersState.deliveryProofNotice,
                    deliveryProofHelp: onlineOrdersState.deliveryProofHelp,
                };

                renderOnlineOrdersTable(onlineOrdersState.orders);
                renderOnlineOrdersSummary(onlineOrdersState.orders.length, onlineOrdersState.totalOrders, onlineOrdersState);
                renderOnlineOrdersPagination(onlineOrdersState);
                updateStatusFilterButtons(onlineOrdersState.statusFilter);
                updateStatusCountBadges(onlineOrdersState.statusCounts);
                updateOnlineOrderBadges(onlineOrdersState.badgeCount);
                updateDeliveryProofNotice();
                initializeStatusForms();
            };

            const fetchOnlineOrders = async () => {
                if (isFetchingOnlineOrders || !onlineOrdersTableBody) {
                    return;
                }

                isFetchingOnlineOrders = true;
                try {
                    const activeElement = document.activeElement;
                    if (activeElement && typeof activeElement.closest === 'function' && activeElement.closest('.status-form')) {
                        return;
                    }

                    const params = new URLSearchParams();
                    params.set('page', String(onlineOrdersState.page));
                    params.set('per_page', String(onlineOrdersState.perPage));
                    if (onlineOrdersState.statusFilter) {
                        params.set('status_filter', onlineOrdersState.statusFilter);
                    }

                    const response = await fetch(`onlineOrdersFeed.php?${params.toString()}`, {
                        headers: { Accept: 'application/json' },
                    });

                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}`);
                    }

                    const payload = await response.json();
                    if (!payload || payload.success !== true || !payload.data) {
                        return;
                    }

                    applyOnlineOrdersData(payload.data);
                } catch (error) {
                    console.error('Unable to refresh online orders.', error);
                } finally {
                    isFetchingOnlineOrders = false;
                }
            };

            const startOnlineOrdersPoll = () => {
                if (onlineOrdersPollTimer) {
                    window.clearInterval(onlineOrdersPollTimer);
                }

                onlineOrdersPollTimer = window.setInterval(fetchOnlineOrders, ONLINE_ORDER_POLL_MS);
            };

            window.addEventListener('dgz:online-orders-refresh', () => {
                fetchOnlineOrders();
            });

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
                const amountReceivedRaw = amountReceivedInput ? amountReceivedInput.value || '0' : '0';
                const amountReceived = parseFloat(amountReceivedRaw);
                const salesTotal = getSalesTotal();
                
                // Only enable if there are items and payment is sufficient
                const shouldEnable = hasRows && amountReceived >= salesTotal && salesTotal > 0;

                settlePaymentButton.disabled = !shouldEnable;
            }
            // End POS settle button enabler

            // Begin POS totals recalculator
            function recalcTotals() {
                const salesTotal = getSalesTotal();
                const vatable = salesTotal / 1.12;
                const vat = salesTotal - vatable;
                const amountReceived = parseFloat(amountReceivedInput.value || '0');
                const change = amountReceived - salesTotal;

                totals.sales.textContent = formatPeso(salesTotal);
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

            function generateLineId(prefix = 'line') {
                customLineCounter += 1;
                return `${prefix}-${Date.now()}-${customLineCounter}`;
            }

            // Begin POS cart state persister
            function persistTableState() {
                const rows = [];
                posTableBody.querySelectorAll('tr').forEach((row) => {
                    const type = row.dataset.itemType || 'product';
                    const priceCell = row.querySelector('.pos-price');
                    const qtyInput = row.querySelector('.pos-qty');
                    let nameValue = '';
                    let priceValue = 0;
                    let availableValue = null;
                    let productId = null;

                    if (type === 'service') {
                        const nameInput = row.querySelector('.pos-service-name');
                        const priceInput = row.querySelector('.pos-service-price');
                        nameValue = nameInput ? nameInput.value.trim() : '';
                        priceValue = priceInput ? parseFloat(priceInput.value || '0') : 0;
                    } else {
                        const nameText = row.querySelector('.pos-name-text');
                        nameValue = nameText ? nameText.textContent.trim() : '';
                        priceValue = priceCell ? parseFloat(priceCell.dataset.rawPrice || '0') : 0;
                        const availableCell = row.querySelector('.pos-available');
                        const availableText = availableCell ? availableCell.textContent || '0' : '0';
                        availableValue = parseInt(availableText, 10) || 0;
                        productId = row.dataset.productId || null;
                    }

                    rows.push({
                        lineId: row.dataset.lineId || null,
                        type,
                        productId,
                        name: nameValue,
                        price: priceValue,
                        available: availableValue,
                        qty: qtyInput ? (parseInt(qtyInput.value, 10) || 1) : 1,
                        variantId: row.dataset.variantId || null,
                        variantLabel: row.dataset.variantLabel || '',
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
                const type = item.type === 'service' ? 'service' : 'product';
                const productId = type === 'product' ? String(item.id) : null;
                const variantId = type === 'product' && item.variantId ? String(item.variantId) : null;
                const variantLabel = type === 'product' ? (item.variantLabel || '') : '';

                if (type === 'product' && productId) {
                    if (variantId) {
                        const existingVariantRow = posTableBody.querySelector(
                            `[data-product-id="${productId}"][data-variant-id="${variantId}"]`
                        );
                        if (existingVariantRow) {
                            return;
                        }
                    } else if (posTableBody.querySelector(`[data-product-id="${productId}"]:not([data-variant-id])`)) {
                        return;
                    }
                }

                const lineId = item.lineId
                    || (type === 'product' && productId
                        ? (variantId ? `product-${productId}-variant-${variantId}` : `product-${productId}`)
                        : generateLineId(type));
                const resolvedName = item.name || (type === 'service' ? '' : 'Item');
                const resolvedPrice = Number.isFinite(item.price) ? item.price : 0;
                const resolvedQty = Math.max(1, item.qty || 1);

                const tr = document.createElement('tr');
                tr.dataset.lineId = lineId;
                tr.dataset.itemType = type;
                if (type === 'product' && productId) {
                    tr.dataset.productId = productId;
                    if (variantId) {
                        tr.dataset.variantId = variantId;
                    }
                    if (variantLabel) {
                        tr.dataset.variantLabel = variantLabel;
                    } else {
                        tr.dataset.variantLabel = '';
                    }
                }

                const baseName = `line_items[${lineId}]`;

                const nameCell = document.createElement('td');
                nameCell.className = 'pos-name';
                if (type === 'service') {
                    nameCell.classList.add('service-entry');
                }

                const typeInput = document.createElement('input');
                typeInput.type = 'hidden';
                typeInput.name = `${baseName}[type]`;
                typeInput.value = type;
                nameCell.appendChild(typeInput);

                if (type === 'service') {
                    const serviceNameInput = document.createElement('input');
                    serviceNameInput.type = 'text';
                    serviceNameInput.className = 'pos-service-name';
                    serviceNameInput.name = `${baseName}[name]`;
                    serviceNameInput.placeholder = 'Service description';
                    serviceNameInput.required = true;
                    serviceNameInput.value = resolvedName;
                    nameCell.appendChild(serviceNameInput);
                } else {
                    const nameText = document.createElement('span');
                    nameText.className = 'pos-name-text';
                    nameText.textContent = resolvedName;
                    nameCell.appendChild(nameText);

                    const nameHidden = document.createElement('input');
                    nameHidden.type = 'hidden';
                    nameHidden.name = `${baseName}[name]`;
                    nameHidden.value = resolvedName;
                    nameCell.appendChild(nameHidden);

                    const productInput = document.createElement('input');
                    productInput.type = 'hidden';
                    productInput.name = `${baseName}[product_id]`;
                    productInput.value = productId || '';
                    nameCell.appendChild(productInput);

                    if (variantId) {
                        const variantIdInput = document.createElement('input');
                        variantIdInput.type = 'hidden';
                        variantIdInput.name = `${baseName}[variant_id]`;
                        variantIdInput.value = variantId;
                        nameCell.appendChild(variantIdInput);

                        const variantLabelInput = document.createElement('input');
                        variantLabelInput.type = 'hidden';
                        variantLabelInput.name = `${baseName}[variant_label]`;
                        variantLabelInput.value = variantLabel;
                        nameCell.appendChild(variantLabelInput);
                    }
                }

                const priceCell = document.createElement('td');
                priceCell.className = 'pos-price';
                if (type === 'service') {
                    priceCell.classList.add('service-entry');
                }

                if (type === 'service') {
                    const servicePriceInput = document.createElement('input');
                    servicePriceInput.type = 'number';
                    servicePriceInput.className = 'pos-service-price';
                    servicePriceInput.name = `${baseName}[price]`;
                    servicePriceInput.min = '0.01';
                    servicePriceInput.step = '0.01';
                    servicePriceInput.required = true;
                    servicePriceInput.value = resolvedPrice > 0 ? resolvedPrice.toFixed(2) : '';
                    priceCell.dataset.rawPrice = String(resolvedPrice > 0 ? resolvedPrice : 0);
                    priceCell.appendChild(servicePriceInput);
                } else {
                    priceCell.dataset.rawPrice = String(resolvedPrice);
                    const priceText = document.createElement('span');
                    priceText.className = 'pos-price-display';
                    priceText.textContent = formatPeso(resolvedPrice);
                    priceCell.appendChild(priceText);

                    const priceHidden = document.createElement('input');
                    priceHidden.type = 'hidden';
                    priceHidden.name = `${baseName}[price]`;
                    priceHidden.value = resolvedPrice;
                    priceCell.appendChild(priceHidden);
                }

                const availableCell = document.createElement('td');
                availableCell.className = 'pos-available';
                availableCell.textContent = type === 'product'
                    ? (Number.isFinite(item.available) ? item.available : 0)
                    : '—';

                const qtyCell = document.createElement('td');
                qtyCell.className = 'pos-actions';

                const qtyInput = document.createElement('input');
                qtyInput.type = 'number';
                qtyInput.className = 'pos-qty';
                qtyInput.name = `${baseName}[qty]`;
                qtyInput.min = '1';
                if (type === 'product' && Number.isFinite(item.max) && item.max > 0) {
                    qtyInput.max = String(item.max);
                }
                qtyInput.value = resolvedQty;
                qtyCell.appendChild(qtyInput);

                const removeButton = document.createElement('button');
                removeButton.type = 'button';
                removeButton.className = 'remove-btn';
                removeButton.setAttribute('aria-label', 'Remove item');
                removeButton.innerHTML = "<i class='fas fa-times'></i>";
                qtyCell.appendChild(removeButton);

                tr.appendChild(nameCell);
                tr.appendChild(priceCell);
                tr.appendChild(availableCell);
                tr.appendChild(qtyCell);
                posTableBody.appendChild(tr);
            }
            // End POS cart row creator

            // Begin POS add-product helper
            function addProductById(productId) {
                const product = productCatalog.find((item) => String(item.id) === String(productId));
                if (!product) {
                    return { status: 'missing' };
                }

                const variants = Array.isArray(product.variants) ? product.variants : [];
                if (variants.length > 0) {
                    const totalVariantQty = variants.reduce((total, variant) => {
                        const qty = Number(variant.quantity) || 0;
                        return total + Math.max(0, qty);
                    }, 0);

                    if (totalVariantQty <= 0) {
                        showPosAlert(`${product.name} is out of stock and cannot be added.`);
                        return { status: 'out_of_stock' };
                    }

                    return { status: 'requires_variant', product };
                }

                const availableQty = Number(product.quantity) || 0;
                if (availableQty <= 0) {
                    showPosAlert(`${product.name} is out of stock and cannot be added.`);
                    return { status: 'out_of_stock' };
                }

                const existingRow = posTableBody.querySelector(`[data-product-id="${product.id}"]:not([data-variant-id])`);
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
                        type: 'product',
                        id: product.id,
                        lineId: `product-${product.id}`,
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
                updateSettleButtonState();
                return { status: 'added' };
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
                    const type = item.type || 'product';
                    if (type === 'service') {
                        createRow({
                            type: 'service',
                            lineId: item.lineId || generateLineId('service'),
                            name: item.name || '',
                            price: Number(item.price) || 0,
                            qty: Number(item.qty) || 1,
                        });
                        return;
                    }

                    const productId = item.productId || item.id;
                    if (!productId) {
                        return;
                    }

                    const product = productCatalog.find((productItem) => String(productItem.id) === String(productId));
                    if (product) {
                        const variantId = item.variantId || null;
                        const variants = Array.isArray(product.variants) ? product.variants : [];
                        if (variantId) {
                            const variant = variants.find((variantItem) => String(variantItem.id) === String(variantId));
                            if (variant) {
                                const availableQty = Number(variant.quantity) || 0;
                                const variantLabel = item.variantLabel || variant.label || '';
                                const resolvedName = item.name
                                    || (variantLabel ? `${product.name} — ${variantLabel}` : product.name);
                                const variantPrice = Number(item.price) || Number(variant.price) || Number(product.price) || 0;

                                createRow({
                                    type: 'product',
                                    id: product.id,
                                    lineId: item.lineId || `product-${product.id}-variant-${variant.id}`,
                                    name: resolvedName,
                                    price: variantPrice,
                                    available: availableQty,
                                    qty: Number(item.qty) || 1,
                                    max: availableQty,
                                    variantId: variant.id,
                                    variantLabel,
                                });
                                return;
                            }
                        }

                        const availableQty = Number(product.quantity) || 0;
                        createRow({
                            type: 'product',
                            id: product.id,
                            lineId: item.lineId || `product-${product.id}`,
                            name: item.name || product.name,
                            price: Number(item.price) || Number(product.price) || 0,
                            available: availableQty,
                            qty: Number(item.qty) || 1,
                            max: availableQty,
                        });
                    } else {
                        createRow({
                            type: 'service',
                            lineId: item.lineId || generateLineId('service'),
                            name: item.name || `Item #${productId}`,
                            price: Number(item.price) || 0,
                            qty: Number(item.qty) || 1,
                        });
                    }
                });

                updateEmptyState();
                recalcTotals();
                updateSettleButtonState();
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

            function resetServiceForm() {
                if (serviceForm) {
                    serviceForm.reset();
                }
                if (serviceQtyInput) {
                    serviceQtyInput.value = '1';
                }
                if (servicePriceInput) {
                    servicePriceInput.value = '';
                }
            }

            function openServiceModal() {
                if (!serviceModal) {
                    return;
                }
                resetServiceForm();
                serviceModal.style.display = 'flex';
                if (serviceNameInput) {
                    serviceNameInput.focus();
                }
            }

            function closeServiceModal() {
                if (!serviceModal) {
                    return;
                }
                serviceModal.style.display = 'none';
                resetServiceForm();
            }

            // Helper that pulls the next product in the queue and opens the
            // variant modal. Called after queueing from the product picker and
            // after each successful variant selection.
            function openNextVariantModal() {
                if (!variantModal) {
                    return;
                }

                const nextProduct = pendingVariantProducts.shift();
                if (!nextProduct) {
                    closeVariantModal();
                    return;
                }

                openVariantModal(nextProduct);
            }

            function closeVariantModal(options = {}) {
                if (!variantModal) {
                    return;
                }

                variantModal.style.display = 'none';

                if (variantOptionsContainer) {
                    variantOptionsContainer.innerHTML = '';
                }

                if (variantModalEmpty) {
                    variantModalEmpty.style.display = 'none';
                }

                if (options.clearQueue !== false) {
                    pendingVariantProducts.length = 0;
                }
            }

            function renderVariantOptions(product) {
                if (!variantOptionsContainer || !variantModalEmpty) {
                    return;
                }

                variantOptionsContainer.innerHTML = '';
                variantModalEmpty.style.display = 'none';

                const variants = Array.isArray(product.variants) ? product.variants : [];
                let hasAvailableOption = false;

                variants.forEach((variant) => {
                    const optionButton = document.createElement('button');
                    optionButton.type = 'button';
                    optionButton.className = 'variant-option';

                    const label = (variant.label || '').trim() || 'Variant';
                    const price = Number(variant.price) || Number(product.price) || 0;
                    const stockQty = Number(variant.quantity) || 0;

                    const labelParts = [label];
                    if (price > 0) {
                        labelParts.push(formatPeso(price));
                    }

                    let stockText = '';
                    if (stockQty > 0) {
                        stockText = `${stockQty} in stock`;
                        hasAvailableOption = true;
                    } else {
                        stockText = 'Out of stock';
                    }

                    optionButton.textContent = stockText
                        ? `${labelParts.join(' • ')} (${stockText})`
                        : labelParts.join(' • ');
                    optionButton.dataset.variantId = String(variant.id || '');
                    optionButton.disabled = stockQty <= 0;
                    optionButton.addEventListener('click', () => {
                        handleVariantSelection(product, variant);
                    });

                    variantOptionsContainer.appendChild(optionButton);
                });

                if (!hasAvailableOption) {
                    variantModalEmpty.style.display = 'block';
                }
            }

            function openVariantModal(product) {
                if (!variantModal) {
                    return;
                }

                if (variantModalTitle) {
                    variantModalTitle.textContent = 'Select Variant';
                }
                if (variantModalSubtitle) {
                    const productName = (product.name || '').trim();
                    variantModalSubtitle.textContent = productName
                        ? `Choose a variant for ${productName}.`
                        : 'Choose a configuration for this product.';
                }

                renderVariantOptions(product);
                variantModal.style.display = 'flex';
            }

            function handleVariantSelection(product, variant) {
                if (!product || !variant) {
                    return;
                }

                const availableQty = Number(variant.quantity) || 0;
                if (availableQty <= 0) {
                    showPosAlert('Selected variant is out of stock.');
                    return;
                }

                const variantLabel = (variant.label || '').trim();
                const displayName = variantLabel ? `${product.name} — ${variantLabel}` : product.name;
                const price = Number(variant.price) || Number(product.price) || 0;

                const existingRow = posTableBody.querySelector(
                    `[data-product-id="${product.id}"][data-variant-id="${variant.id}"]`
                );

                if (existingRow) {
                    const qtyInput = existingRow.querySelector('.pos-qty');
                    const currentQty = parseInt(qtyInput.value, 10) || 0;
                    const maxQty = Math.max(parseInt(qtyInput.max, 10) || 0, availableQty);
                    const newQty = Math.min(currentQty + 1, maxQty);
                    qtyInput.max = availableQty > 0 ? String(availableQty) : '';
                    existingRow.querySelector('.pos-available').textContent = availableQty;
                    qtyInput.value = newQty;
                } else {
                    createRow({
                        type: 'product',
                        id: product.id,
                        lineId: `product-${product.id}-variant-${variant.id}`,
                        name: displayName,
                        price,
                        available: availableQty,
                        qty: 1,
                        max: availableQty,
                        variantId: variant.id,
                        variantLabel,
                    });
                }

                updateEmptyState();
                recalcTotals();
                persistTableState();
                updateSettleButtonState();

                // Keep the modal flow alive until all queued variant selections
                // have been completed.
                closeVariantModal({ clearQueue: false });
                if (pendingVariantProducts.length > 0) {
                    openNextVariantModal();
                } else {
                    closeProductModal();
                }
            }

            // Begin POS product table renderer
            function renderProductTable(filter = '') {
                if (!productSearchTableBody) {
                    return;
                }

                // Get selected category and brand filter values
                const categoryFilterEl = document.getElementById('categoryFilter');
                const brandFilterEl = document.getElementById('brandFilter');
                const selectedCategory = categoryFilterEl ? categoryFilterEl.value || '' : '';
                const selectedBrand = brandFilterEl ? brandFilterEl.value || '' : '';

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

                if (tabName === 'online') {
                    fetchOnlineOrders();
                }

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
                let vatable = 0;
                let vat = 0;
                let amountPaid = 0;
                let change = 0;
                let cashierName = 'Cashier';
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
                    cashierName = 'Cashier';
                    createdAt = new Date();
                }

                receiptItemsBody.innerHTML = '';
                items.forEach((item) => {
                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td class="receipt-item-name">${item.name}</td>
                        <td class="receipt-item-qty">${item.qty}</td>
                        <td class="receipt-item-price">${formatPeso(item.price)}</td>
                        <td class="receipt-item-total">${formatPeso(item.total)}</td>
                    `;
                    receiptItemsBody.appendChild(row);
                });

                if (!invoiceNumber) {
                    invoiceNumber = orderId ? `INV-${orderId}` : `INV-${Date.now()}`;
                }

                document.getElementById('receiptNumber').textContent = invoiceNumber;
                document.getElementById('receiptDate').textContent = Number.isNaN(createdAt.getTime()) ? new Date().toLocaleString() : createdAt.toLocaleString();
                document.getElementById('receiptCashier').textContent = cashierName || 'Cashier';
                document.getElementById('receiptSalesTotal').textContent = formatPeso(salesTotal);
                document.getElementById('receiptVatable').textContent = formatPeso(vatable);
                document.getElementById('receiptVat').textContent = formatPeso(vat);
                document.getElementById('receiptAmountPaid').textContent = formatPeso(amountPaid);
                document.getElementById('receiptChange').textContent = formatPeso(change);

                receiptModal.style.display = 'flex';

                if (!hasShownCheckoutAlert) {
                    showPosAlert('Payment settled successfully.');
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
                    showPosAlert('Unable to open the receipt print preview. Please allow pop-ups for this site.');
                    return;
                }

                w.document.write(`
                    <html>
                        <head>
                            <title>Print Receipt</title>
                            <style>
                                body { font-family: 'Courier New', monospace; font-size: 14px; }
                                #receiptItems { width: 100%; border-collapse: collapse; margin-top: 10px; table-layout: fixed; }
                                #receiptItems th, #receiptItems td { padding: 6px 12px; border-bottom: 1px dashed #cbd5f5; font-weight: 500; }
                                #receiptItems th { border-bottom: 1px solid #e2e8f0; font-weight: 700; }
                                #receiptItems th:first-child, #receiptItems td:first-child { text-align: left; width: 48%; word-break: break-word; }
                                #receiptItems th:nth-child(2), #receiptItems td:nth-child(2) { text-align: center; width: 12%; }
                                #receiptItems th:nth-child(3), #receiptItems td:nth-child(3),
                                #receiptItems th:nth-child(4), #receiptItems td:nth-child(4) { text-align: right; width: 20%; white-space: nowrap; }
                                .receipt-totals { margin-top: 14px; display: grid; gap: 4px; font-size: 13px; color: #1f2937; }
                                .receipt-totals div { display: flex; justify-content: space-between; }
                                .receipt-footer { margin-top: 16px; text-align: center; color: #6b7280; font-size: 12px; }
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
                if (!proofModal) {
                    return;
                }

                proofModal.classList.remove('show');
                proofModal.setAttribute('aria-hidden', 'true');
                proofModal.style.display = 'none';
                document.body.classList.remove('modal-open');

                if (proofImage) {
                    proofImage.removeAttribute('src');
                    proofImage.style.display = 'none';
                }

                if (proofNoImage) {
                    proofNoImage.style.display = 'none';
                }
            }
            // End POS proof modal closer

            // Event bindings
            if (mobileToggle) {
                mobileToggle.addEventListener('click', () => {
                    if (sidebar) {
                        sidebar.classList.toggle('mobile-open');
                    }
                });
            }

            // Add event listeners for category and brand filters to re-render product table on change
            const categoryFilterSelect = document.getElementById('categoryFilter');
            const brandFilterSelect = document.getElementById('brandFilter');

            if (categoryFilterSelect) {
                categoryFilterSelect.addEventListener('change', () => {
                    const value = productSearchInput ? productSearchInput.value.trim() : '';
                    renderProductTable(value);
                });
            }

            if (brandFilterSelect) {
                brandFilterSelect.addEventListener('change', () => {
                    const value = productSearchInput ? productSearchInput.value.trim() : '';
                    renderProductTable(value);
                });
            }

            document.addEventListener('click', (event) => {
                if (window.innerWidth <= 768 && sidebar && mobileToggle) {
                    if (!sidebar.contains(event.target) && !mobileToggle.contains(event.target)) {
                        sidebar.classList.remove('mobile-open');
                    }
                }
            });

            if (userAvatar) {
                userAvatar.addEventListener('click', () => {
                    if (userDropdown) {
                        userDropdown.classList.toggle('show');
                    }
                });
            }

            document.addEventListener('click', (event) => {
                if (userMenu && userDropdown && !userMenu.contains(event.target)) {
                    userDropdown.classList.remove('show');
                }
            });

            if (profileButton) {
                profileButton.addEventListener('click', (event) => {
                    event.preventDefault();
                    if (userDropdown) {
                        userDropdown.classList.remove('show');
                    }
                    openProfileModal();
                });
            }

            if (profileModalClose) {
                profileModalClose.addEventListener('click', () => {
                    closeProfileModal();
                });
            }

            if (profileModal) {
                profileModal.addEventListener('click', (event) => {
                    if (event.target === profileModal) {
                        closeProfileModal();
                    }
                });
            }

            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') {
                    if (profileModal && profileModal.classList.contains('show')) {
                        closeProfileModal();
                    }

                    if (onlineOrderModal && onlineOrderModal.style.display !== 'none') {
                        closeOnlineOrderModalOverlay();
                    }

                    if (variantModal && variantModal.style.display !== 'none') {
                        closeVariantModal();
                    }
                }
            });

            if (openProductModalButton) {
                openProductModalButton.addEventListener('click', openProductModal);
            }
            if (closeProductModalButton) {
                closeProductModalButton.addEventListener('click', closeProductModal);
            }
            if (productModal) {
                productModal.addEventListener('click', (event) => {
                    if (event.target === productModal) {
                        closeProductModal();
                    }
                });
            }

            if (addServiceButton) {
                addServiceButton.addEventListener('click', () => {
                    openServiceModal();
                });
            }

            if (closeServiceModalButton) {
                closeServiceModalButton.addEventListener('click', () => {
                    closeServiceModal();
                });
            }

            if (serviceModal) {
                serviceModal.addEventListener('click', (event) => {
                    if (event.target === serviceModal) {
                        closeServiceModal();
                    }
                });
            }

            if (closeVariantModalButton) {
                closeVariantModalButton.addEventListener('click', () => {
                    closeVariantModal();
                });
            }

            if (variantModal) {
                variantModal.addEventListener('click', (event) => {
                    if (event.target === variantModal) {
                        closeVariantModal();
                    }
                });
            }

            if (serviceForm) {
                serviceForm.addEventListener('submit', (event) => {
                    event.preventDefault();

                    const name = serviceNameInput ? serviceNameInput.value.trim() : '';
                    const priceValue = servicePriceInput ? servicePriceInput.value || '0' : '0';
                    const qtyValue = serviceQtyInput ? serviceQtyInput.value || '1' : '1';
                    let price = parseFloat(priceValue);
                    let qty = parseInt(qtyValue, 10);

                    if (name === '') {
                        showPosAlert('Please enter a service description.');
                        if (serviceNameInput) {
                            serviceNameInput.focus();
                        }
                        return;
                    }

                    if (!Number.isFinite(price) || price <= 0) {
                        showPosAlert('Please enter a valid service price.');
                        if (servicePriceInput) {
                            servicePriceInput.focus();
                        }
                        return;
                    }

                    if (!Number.isFinite(qty) || qty <= 0) {
                        qty = 1;
                    }

                    price = parseFloat(price.toFixed(2));
                    qty = Math.max(1, qty);

                    createRow({
                        type: 'service',
                        lineId: generateLineId('service'),
                        name,
                        price,
                        qty,
                    });

                    updateEmptyState();
                    recalcTotals();
                    persistTableState();
                    updateSettleButtonState();
                    closeServiceModal();
                });
            }

            if (productSearchInput) {
                productSearchInput.addEventListener('input', (event) => {
                    const target = event.target || {};
                    const value = target.value ? target.value.trim() : '';
                    renderProductTable(value);
                });
            }

            if (addSelectedProductsButton) {
                addSelectedProductsButton.addEventListener('click', () => {
                    const selected = productModal.querySelectorAll('.product-select-checkbox:checked');
                    if (selected.length === 0) {
                        showPosAlert('Please select at least one product to add.');
                        return;
                    }

                let queuedVariantSelection = false;
                selected.forEach((checkbox) => {
                    const result = addProductById(checkbox.dataset.id);

                    if (result && result.status === 'requires_variant' && result.product) {
                        pendingVariantProducts.push(result.product);
                        queuedVariantSelection = true;
                    }

                    checkbox.checked = false;
                });

                if (queuedVariantSelection) {
                    openNextVariantModal();
                } else {
                    closeProductModal();
                }
                });
            }

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
                const target = event.target;
                if (target.classList.contains('pos-qty')) {
                    const min = parseInt(target.min, 10) || 1;
                    const max = parseInt(target.max, 10);
                    const rawValue = target.value.trim();

                    if (rawValue === '') {
                        // Allow clearing while editing; restore on blur if left empty.
                        recalcTotals();
                        persistTableState();
                        return;
                    }

                    let value = parseInt(rawValue, 10);

                    if (!Number.isFinite(value) || value < min) {
                        value = min;
                        target.value = value;
                        target.dataset.previousValidValue = String(value);
                        recalcTotals();
                        persistTableState();
                        return;
                    }

                    if (Number.isFinite(max) && max > 0 && value > max) {
                        showPosAlert(`Only ${max} stock available.`);
                        const previous = parseInt(target.dataset.previousValidValue || '', 10);
                        const fallback = Number.isFinite(previous) && previous >= min ? previous : min;
                        target.value = fallback;
                        target.dataset.previousValidValue = String(fallback);
                        target.focus();
                        target.select();
                        recalcTotals();
                        persistTableState();
                        return;
                    }

                    target.dataset.previousValidValue = String(value);
                    target.value = value;
                    recalcTotals();
                    persistTableState();
                    return;
                }

                if (target.classList.contains('pos-service-price')) {
                    let value = parseFloat(target.value);
                    if (!Number.isFinite(value) || value <= 0) {
                        value = 0;
                        target.value = '';
                    } else {
                        target.value = value.toFixed(2);
                    }

                    const priceCell = target.closest('.pos-price');
                    if (priceCell) {
                        priceCell.dataset.rawPrice = String(value);
                    }

                    recalcTotals();
                    updateSettleButtonState();
                    persistTableState();
                    return;
                }

                if (target.classList.contains('pos-service-name')) {
                    persistTableState();
                }
            });

            posTableBody.addEventListener('focusout', (event) => {
                const target = event.target;
                if (!target.classList.contains('pos-qty')) {
                    return;
                }

                const min = parseInt(target.min, 10) || 1;
                const max = parseInt(target.max, 10);
                const rawValue = target.value.trim();

                if (rawValue === '') {
                    const previous = parseInt(target.dataset.previousValidValue || '', 10);
                    const fallback = Number.isFinite(previous) && previous >= min ? previous : min;
                    target.value = fallback;
                    target.dataset.previousValidValue = String(fallback);
                    recalcTotals();
                    persistTableState();
                    return;
                }

                let value = parseInt(rawValue, 10);
                if (!Number.isFinite(value) || value < min) {
                    value = min;
                }

                if (Number.isFinite(max) && max > 0 && value > max) {
                    showPosAlert(`Only ${max} stock available.`);
                    value = max;
                }

                target.value = value;
                target.dataset.previousValidValue = String(value);
                recalcTotals();
                persistTableState();
            });

            if (amountReceivedInput) {
                amountReceivedInput.addEventListener('input', () => {
                    recalcTotals();
                    updateSettleButtonState();
                });
            }

            if (clearPosTableButton) {
                clearPosTableButton.addEventListener('click', () => {
                    clearTable();
                });
            }

            if (posForm) {
                posForm.addEventListener('submit', (event) => {
                    const rows = posTableBody.querySelectorAll('tr');
                    if (rows.length === 0) {
                        event.preventDefault();
                        closeProductModal();
                        showPosAlert('No item selected in POS!');
                        return;
                    }

                    const salesTotal = getSalesTotal();
                    const amountReceivedValue = amountReceivedInput ? amountReceivedInput.value || '0' : '0';
                    const amountReceived = parseFloat(amountReceivedValue);

                    if (amountReceived <= 0) {
                        event.preventDefault();
                        showPosAlert('Please enter the amount received from the customer!');
                        if (amountReceivedInput) {
                            amountReceivedInput.focus();
                        }
                        return;
                    }

                if (amountReceived < salesTotal) {
                    event.preventDefault();
                    const shortage = salesTotal - amountReceived;
                    showPosAlert(`Insufficient payment! Need ${formatPeso(shortage)} more.`);
                    if (amountReceivedInput) {
                        amountReceivedInput.focus();
                    }
                }
                });
            }

            tabButtons.forEach((button) => {
                button.addEventListener('click', () => {
                    setActiveTab(button.dataset.tab);
                });
            });

            statusFilterButtons.forEach((button) => {
                button.addEventListener('click', (event) => {
                    event.preventDefault();

                    const value = button && button.dataset && button.dataset.statusValue
                        ? button.dataset.statusValue
                        : '';
                    const href = typeof button.href === 'string' && button.href !== ''
                        ? button.href
                        : null;

                    if (href) {
                        window.location.href = href;
                        return;
                    }

                    const url = new URL(window.location.href);
                    url.searchParams.set('tab', 'online');
                    url.searchParams.set('page', '1');

                    if (value) {
                        url.searchParams.set('status_filter', value);
                    } else {
                        url.searchParams.delete('status_filter');
                    }

                    window.location.href = url.toString();
                });
            });

            const statusAlert = document.querySelector('.status-alert');
            if (statusAlert) {
                const url = new URL(window.location.href);
                url.searchParams.delete('status_updated');
                window.history.replaceState({}, document.title, url.toString());
            }

            initializeStatusForms();

            if (onlineOrdersContainer) {
                onlineOrdersContainer.addEventListener('click', (event) => {
                    const proofButton = event.target.closest('.view-proof-btn');
                    if (proofButton) {
                        event.preventDefault();
                        openProofModalFromButton(proofButton);
                        return;
                    }

                    const row = event.target.closest('.online-order-row');
                    if (!row) {
                        return;
                    }

                    if (
                        event.target.closest('.status-form') ||
                        event.target.closest('.status-save') ||
                        event.target.closest('select')
                    ) {
                        return;
                    }

                    const orderId = row.getAttribute('data-order-id')
                        || (row.dataset && row.dataset.orderId)
                        || '';
                    if (!orderId) {
                        return;
                    }

                    event.preventDefault();
                    fetchOnlineOrderDetails(orderId);
                });

                onlineOrdersContainer.addEventListener('change', (event) => {
                    const select = event.target.closest('[data-status-select]');
                    if (!select) {
                        return;
                    }

                    const form = select.closest('.status-form');
                    updateDeliveryProofField(form);
                });
            }

            if (closeProofModalButton) {
                closeProofModalButton.addEventListener('click', closeProofModal);
            }
            if (proofModal) {
                proofModal.addEventListener('click', (event) => {
                    if (event.target === proofModal) {
                        closeProofModal();
                    }
                });
            }

            // Status filter functionality
            if (closeOnlineOrderModalButton) {
                closeOnlineOrderModalButton.addEventListener('click', closeOnlineOrderModalOverlay);
            }
            if (onlineOrderModal) {
                onlineOrderModal.addEventListener('click', (event) => {
                    if (event.target === onlineOrderModal) {
                        closeOnlineOrderModalOverlay();
                    }
                });
            }

            if (closeReceiptModalButton) {
                closeReceiptModalButton.addEventListener('click', closeReceiptModal);
            }
            if (receiptModal) {
                receiptModal.addEventListener('click', (event) => {
                    if (event.target === receiptModal) {
                        closeReceiptModal();
                    }
                });
            }

            if (printReceiptButton) {
                printReceiptButton.addEventListener('click', printReceipt);
            }

            // Initialisation
            renderOnlineOrdersTable(onlineOrdersState.orders);
            renderOnlineOrdersSummary(onlineOrdersState.orders.length, onlineOrdersState.totalOrders, onlineOrdersState);
            renderOnlineOrdersPagination(onlineOrdersState);
            updateOnlineOrderBadges(onlineOrdersState.badgeCount);
            startOnlineOrdersPoll();
            window.setTimeout(fetchOnlineOrders, 4000);

            window.addEventListener('beforeunload', () => {
                if (onlineOrdersPollTimer) {
                    window.clearInterval(onlineOrdersPollTimer);
                }
            });

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
