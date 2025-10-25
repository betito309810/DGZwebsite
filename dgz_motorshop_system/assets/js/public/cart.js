
        // ===== Start File 1: cartStateAndInteractions.js (cart state, persistence, cart UI hooks) =====
        // Cart functionality
        const dgzPaths = window.dgzPaths || {};
        const checkoutBaseUrl = (typeof dgzPaths.checkout === 'string' && dgzPaths.checkout !== '')
            ? dgzPaths.checkout
            : 'checkout.php';

        function redirectToCheckout(cartData) {
            const separator = checkoutBaseUrl.includes('?') ? '&' : '?';
            const checkoutDestination = `${checkoutBaseUrl}${separator}cart=${cartData}`;

            window.location.href = checkoutDestination;
        }

        const HIGH_VALUE_WARNING_THRESHOLD = 70000;
        const HIGH_VALUE_BLOCK_THRESHOLD = 100000;

        let cartCount = 0;
        let cartItems = [];
        const highValueState = {
            warningShown: false,
            blockedShown: false,
        };
        const highValueDecision = {
            onProceed: null,
            onCancel: null,
        };
        const customerSessionState = typeof document !== 'undefined' && document.body
            ? String(document.body.dataset.customerSession || '').toLowerCase()
            : 'guest';
        const isCustomerAuthenticated = customerSessionState === 'authenticated';
        const customerCartUrl = (typeof dgzPaths.customerCart === 'string' && dgzPaths.customerCart !== '')
            ? dgzPaths.customerCart
            : null;
        const fetchSupported = typeof window.fetch === 'function';
        const promiseSupported = typeof Promise !== 'undefined' && typeof Promise.resolve === 'function';
        const serverSyncSupported = Boolean(isCustomerAuthenticated && customerCartUrl && fetchSupported && promiseSupported);
        let serverSyncChain = promiseSupported ? Promise.resolve() : null;
        let serverLastPayloadSignature = null;

        function clearHighValueDecision() {
            highValueDecision.onProceed = null;
            highValueDecision.onCancel = null;
        }

        function ensureHighValueModals() {
            const existingConfirm = document.getElementById('highValueConfirmModal');
            const existingBlocked = document.getElementById('highValueBlockedModal');

            let confirmModal = existingConfirm;
            if (!confirmModal) {
                confirmModal = document.createElement('div');
                confirmModal.id = 'highValueConfirmModal';
                confirmModal.className = 'checkout-modal';
                confirmModal.setAttribute('hidden', 'hidden');
                confirmModal.innerHTML = `
                <div class="checkout-modal__dialog">
                    <h3>Large Transaction</h3>
                    <p>This transaction is too big, would you like to personally go to our store or would you like to proceed?</p>
                    <div class="checkout-modal__actions">
                        <button type="button" class="checkout-modal__button checkout-modal__button--primary" data-high-value-proceed>
                            Yes, I would like to proceed
                        </button>
                        <button type="button" class="checkout-modal__button checkout-modal__button--secondary" data-high-value-cancel>
                            Ok
                        </button>
                    </div>
                </div>`;
                document.body.appendChild(confirmModal);
            }

            let blockedModal = existingBlocked;
            if (!blockedModal) {
                blockedModal = document.createElement('div');
                blockedModal.id = 'highValueBlockedModal';
                blockedModal.className = 'checkout-modal';
                blockedModal.setAttribute('hidden', 'hidden');
                blockedModal.innerHTML = `
                <div class="checkout-modal__dialog">
                    <h3>Amount Too High</h3>
                    <p>This transaction is too big for our online ordering. We would advise you to personally go to our physical store to shop!</p>
                    <div class="checkout-modal__actions">
                        <button type="button" class="checkout-modal__button checkout-modal__button--primary" data-high-value-blocked-ok>
                            Ok
                        </button>
                    </div>
                </div>`;
                document.body.appendChild(blockedModal);
            }

            if (!confirmModal.dataset.highValueBound) {
                const proceedButton = confirmModal.querySelector('[data-high-value-proceed]');
                const cancelButton = confirmModal.querySelector('[data-high-value-cancel]');

                const runDecision = (type) => {
                    confirmModal.setAttribute('hidden', 'hidden');
                    const action = type === 'proceed' ? highValueDecision.onProceed : highValueDecision.onCancel;
                    clearHighValueDecision();
                    if (typeof action === 'function') {
                        action();
                    }
                };

                proceedButton?.addEventListener('click', () => runDecision('proceed'));
                cancelButton?.addEventListener('click', () => runDecision('cancel'));

                confirmModal.dataset.highValueBound = 'true';
            }

            if (!blockedModal.dataset.highValueBound) {
                const blockedOkButton = blockedModal.querySelector('[data-high-value-blocked-ok]');
                const closeBlocked = () => {
                    blockedModal.setAttribute('hidden', 'hidden');
                };
                blockedOkButton?.addEventListener('click', closeBlocked);
                blockedModal.dataset.highValueBound = 'true';
            }

            return { confirmModal, blockedModal };
        }

        function openModal(modal) {
            if (!modal) {
                return;
            }
            modal.removeAttribute('hidden');
        }

        function normaliseMoney(value) {
            const numeric = Number(value);
            return Number.isFinite(numeric) ? numeric : 0;
        }

        function normaliseProductId(value) {
            const numeric = Number(value);
            if (Number.isFinite(numeric)) {
                return numeric;
            }
            return String(value);
        }

        function calculateCartSubtotal() {
            return cartItems.reduce((total, item) => {
                const unitPrice = normaliseMoney(
                    item.variantPrice !== undefined && item.variantPrice !== null
                        ? item.variantPrice
                        : item.price,
                );
                const quantity = Number(item.quantity) || 0;
                return total + unitPrice * quantity;
            }, 0);
        }

        function calculateProspectiveSubtotal(productId, variantId, addedQuantity, unitPrice) {
            const normalisedVariantId = variantId ?? null;
            const targetProductId = normaliseProductId(productId);
            const safeQuantity = Number(addedQuantity) || 0;
            const safeUnitPrice = normaliseMoney(unitPrice);

            if (safeQuantity <= 0) {
                return calculateCartSubtotal();
            }

            let matched = false;

            const subtotal = cartItems.reduce((total, item) => {
                const itemQuantity = Number(item.quantity) || 0;
                const currentPrice = normaliseMoney(
                    item.variantPrice !== undefined && item.variantPrice !== null
                        ? item.variantPrice
                        : item.price,
                );
                const itemProductId = normaliseProductId(item.id);
                const isTarget = itemProductId === targetProductId && (item.variantId ?? null) === normalisedVariantId;

                if (isTarget) {
                    matched = true;
                    const combinedQuantity = itemQuantity + safeQuantity;
                    return total + safeUnitPrice * combinedQuantity;
                }

                return total + currentPrice * itemQuantity;
            }, 0);

            if (!matched) {
                return subtotal + safeUnitPrice * safeQuantity;
            }

            return subtotal;
        }

        function handleHighValuePreflight({
            productId,
            variantId,
            quantity,
            unitPrice,
            onProceed,
        }) {
            const projectedSubtotal = calculateProspectiveSubtotal(productId, variantId, quantity, unitPrice);

            if (projectedSubtotal >= HIGH_VALUE_BLOCK_THRESHOLD) {
                const { blockedModal } = ensureHighValueModals();
                openModal(blockedModal);
                highValueState.blockedShown = true;
                highValueState.warningShown = true;
                return 'blocked';
            }

            if (projectedSubtotal >= HIGH_VALUE_WARNING_THRESHOLD && !highValueState.warningShown) {
                const { confirmModal } = ensureHighValueModals();

                clearHighValueDecision();
                highValueDecision.onProceed = () => {
                    highValueState.warningShown = true;
                    if (typeof onProceed === 'function') {
                        onProceed();
                    }
                };
                highValueDecision.onCancel = () => {};

                openModal(confirmModal);
                return 'pending';
            }

            return 'proceed';
        }

        function evaluateHighValueCart() {
            const subtotal = calculateCartSubtotal();
            if (subtotal >= HIGH_VALUE_BLOCK_THRESHOLD) {
                if (!highValueState.blockedShown) {
                    const { blockedModal } = ensureHighValueModals();
                    openModal(blockedModal);
                    highValueState.blockedShown = true;
                    highValueState.warningShown = true;
                }
                return;
            }

            if (subtotal >= HIGH_VALUE_WARNING_THRESHOLD && !highValueState.warningShown) {
                const { confirmModal } = ensureHighValueModals();
                openModal(confirmModal);
                highValueState.warningShown = true;
            }
        }

        function updateCartBadge() {
            const badge = document.getElementById('cartCount');
            if (badge) {
                badge.textContent = cartCount;
            }
        }

        // Start saveCart: persist the in-memory cart to localStorage so the cart survives reloads
        function saveCart(options = {}) {
            localStorage.setItem('cartItems', JSON.stringify(cartItems));
            localStorage.setItem('cartCount', cartCount.toString());

            if (!options || options.skipServer) {
                return;
            }

            if (serverSyncSupported) {
                queueCartSyncToServer();
            }
        }
        // End saveCart

        // Start loadCart: recover cart state from localStorage and update the cart badge
        function loadCart() {
            const savedCart = localStorage.getItem('cartItems');
            const savedCount = localStorage.getItem('cartCount');

            if (savedCart) {
                try {
                    const parsed = JSON.parse(savedCart);
                    if (Array.isArray(parsed)) {
                        cartItems = parsed.map((item) => {
                            const normalised = {
                                id: Number(item.id),
                                name: typeof item.name === 'string' ? item.name : 'Product',
                                price: Number(item.price) || 0,
                                quantity: Number(item.quantity) || 0,
                                variantId: item.variantId !== undefined && item.variantId !== null ? Number(item.variantId) : null,
                                variantLabel: typeof item.variantLabel === 'string' ? item.variantLabel : '',
                                variantPrice: item.variantPrice !== undefined && item.variantPrice !== null ? Number(item.variantPrice) : Number(item.price) || 0,
                            };
                            if (!Number.isFinite(normalised.price) || normalised.price <= 0) {
                                normalised.price = Number(item.variantPrice) || Number(item.price) || 0;
                            }
                            return normalised;
                        });
                    } else {
                        cartItems = [];
                    }
                } catch (e) {
                    cartItems = [];
                    console.error('Error parsing cart items:', e);
                }
            }
            if (savedCount) {
                const parsedCount = parseInt(savedCount, 10);
                cartCount = Number.isNaN(parsedCount) ? 0 : parsedCount;
            }

            updateCartBadge();
            evaluateHighValueCart();

            if (serverSyncSupported) {
                initialiseServerCartSync();
            }
        }
        // End loadCart

        function normaliseCartStateItem(item) {
            if (!item || typeof item !== 'object') {
                return null;
            }

            const productRaw = item.id ?? item.product_id;
            const productId = Number(productRaw);
            if (!Number.isFinite(productId) || productId <= 0) {
                return null;
            }

            const variantRaw = item.variantId ?? item.variant_id ?? null;
            let variantId = null;
            if (variantRaw !== null && variantRaw !== undefined) {
                const candidate = Number(variantRaw);
                if (Number.isFinite(candidate) && candidate > 0) {
                    variantId = candidate;
                }
            }

            const quantity = Number(item.quantity);
            if (!Number.isFinite(quantity) || quantity <= 0) {
                return null;
            }

            const nameCandidate = item.name ?? item.product_name ?? '';
            const variantLabelCandidate = item.variantLabel ?? item.variant_label ?? '';
            const variantPriceRaw = item.variantPrice ?? item.variant_price ?? null;
            const basePriceRaw = item.price ?? item.unitPrice ?? item.unit_price ?? variantPriceRaw ?? 0;

            let unitPrice = normaliseMoney(basePriceRaw);
            if (unitPrice <= 0 && variantPriceRaw !== null && variantPriceRaw !== undefined) {
                unitPrice = normaliseMoney(variantPriceRaw);
            }

            let variantPrice = null;
            if (variantId !== null) {
                variantPrice = normaliseMoney(variantPriceRaw);
                if (variantPrice <= 0) {
                    variantPrice = unitPrice;
                }
            }

            if (variantPrice === null) {
                variantPrice = unitPrice;
            }

            return {
                id: productId,
                name: typeof nameCandidate === 'string' && nameCandidate.trim() !== '' ? nameCandidate.trim() : 'Product',
                price: unitPrice > 0 ? unitPrice : 0,
                quantity: Math.min(999, Math.max(1, Math.trunc(quantity))),
                variantId,
                variantLabel: typeof variantLabelCandidate === 'string' ? variantLabelCandidate : '',
                variantPrice: variantPrice > 0 ? variantPrice : unitPrice,
            };
        }

        function serialiseCartItemsForServer(items = cartItems) {
            const map = new Map();

            items.forEach((item) => {
                const normalised = normaliseCartStateItem(item);
                if (!normalised) {
                    return;
                }

                const key = `${normalised.id}:${normalised.variantId === null ? 'null' : normalised.variantId}`;
                const quantity = Number(normalised.quantity) || 0;
                if (quantity <= 0) {
                    return;
                }

                if (!map.has(key)) {
                    map.set(key, {
                        product_id: normalised.id,
                        variant_id: normalised.variantId,
                        quantity: 0,
                    });
                }

                const entry = map.get(key);
                entry.quantity = Math.min(999, entry.quantity + quantity);
            });

            const payload = Array.from(map.values());
            if (payload.length > 50) {
                return payload.slice(0, 50);
            }

            return payload;
        }

        function computeCartSignature(payload) {
            if (!Array.isArray(payload)) {
                return null;
            }

            const sorted = payload
                .map((item) => ({
                    product_id: Number(item.product_id) || 0,
                    variant_id: item.variant_id === null || item.variant_id === undefined ? null : Number(item.variant_id),
                    quantity: Number(item.quantity) || 0,
                }))
                .sort((a, b) => {
                    if (a.product_id !== b.product_id) {
                        return a.product_id - b.product_id;
                    }
                    const aVariant = a.variant_id ?? 0;
                    const bVariant = b.variant_id ?? 0;
                    if (aVariant !== bVariant) {
                        return aVariant - bVariant;
                    }
                    return a.quantity - b.quantity;
                });

            return JSON.stringify(sorted);
        }

        function mergeCartCollections(primaryItems, secondaryItems) {
            const map = new Map();

            const addItems = (items) => {
                items.forEach((item) => {
                    const normalised = normaliseCartStateItem(item);
                    if (!normalised) {
                        return;
                    }

                    const key = `${normalised.id}:${normalised.variantId === null ? 'null' : normalised.variantId}`;
                    const quantity = Number(normalised.quantity) || 0;
                    if (quantity <= 0) {
                        return;
                    }

                    if (!map.has(key)) {
                        map.set(key, {
                            id: normalised.id,
                            name: normalised.name,
                            price: normalised.variantId !== null ? normalised.variantPrice : normalised.price,
                            quantity: Math.min(999, quantity),
                            variantId: normalised.variantId,
                            variantLabel: normalised.variantLabel,
                            variantPrice: normalised.variantPrice,
                        });
                        return;
                    }

                    const existing = map.get(key);
                    const cappedQuantity = Math.min(999, quantity);
                    existing.quantity = Math.min(999, Math.max(existing.quantity, cappedQuantity));
                    if (existing.name === 'Product' && normalised.name !== 'Product') {
                        existing.name = normalised.name;
                    }
                    if ((existing.variantLabel === '' || existing.variantLabel === null) && normalised.variantLabel) {
                        existing.variantLabel = normalised.variantLabel;
                    }
                    if (normalised.variantId !== null) {
                        if (normalised.variantPrice > 0) {
                            existing.variantPrice = normalised.variantPrice;
                            existing.price = normalised.variantPrice;
                        }
                    } else if (existing.price <= 0 && normalised.price > 0) {
                        existing.price = normalised.price;
                    }
                });
            };

            addItems(primaryItems);
            addItems(secondaryItems);

            return Array.from(map.values());
        }

        function applyServerCartItems(items, options = {}) {
            const normalisedItems = Array.isArray(items)
                ? items.map((item) => normaliseCartStateItem(item)).filter(Boolean)
                : [];

            cartItems = normalisedItems;
            cartCount = cartItems.reduce((sum, item) => sum + (Number(item.quantity) || 0), 0);
            updateCartBadge();
            evaluateHighValueCart();

            if (!options || !options.skipLocalStorage) {
                saveCart({ skipServer: true });
            }

            if (!options || !options.skipSignatureUpdate) {
                const payload = serialiseCartItemsForServer(cartItems);
                serverLastPayloadSignature = computeCartSignature(payload);
            }
        }

        async function fetchServerCart() {
            if (!serverSyncSupported) {
                return null;
            }

            try {
                const response = await fetch(customerCartUrl, {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                    },
                });

                if (!response.ok) {
                    if (response.status === 401) {
                        serverLastPayloadSignature = null;
                        return [];
                    }

                    throw new Error(`Request failed with status ${response.status}`);
                }

                const data = await response.json();
                return Array.isArray(data.items) ? data.items : [];
            } catch (error) {
                console.error('Unable to load your saved cart.', error);
                return null;
            }
        }

        async function pushCartToServer(payload, signature) {
            if (!serverSyncSupported) {
                return;
            }

            try {
                const response = await fetch(customerCartUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                    },
                    body: JSON.stringify({ items: payload }),
                });

                if (!response.ok) {
                    throw new Error(`Request failed with status ${response.status}`);
                }

                const data = await response.json();
                if (data && Array.isArray(data.items)) {
                    applyServerCartItems(data.items, { skipSignatureUpdate: true });
                }

                serverLastPayloadSignature = signature ?? computeCartSignature(serialiseCartItemsForServer(cartItems));
            } catch (error) {
                console.error('Unable to save your cart.', error);
                serverLastPayloadSignature = null;
                throw error;
            }
        }

        function queueCartSyncToServer() {
            if (!serverSyncSupported) {
                return;
            }

            const payload = serialiseCartItemsForServer(cartItems);
            const signature = computeCartSignature(payload);

            if (signature !== null && signature === serverLastPayloadSignature) {
                return;
            }

            serverSyncChain = serverSyncChain
                .catch(() => undefined)
                .then(() => pushCartToServer(payload, signature))
                .catch(() => undefined);
        }

        function initialiseServerCartSync() {
            if (!serverSyncSupported) {
                return;
            }

            const localSnapshot = cartItems.slice();
            const localPayload = serialiseCartItemsForServer(localSnapshot);
            const localSignature = computeCartSignature(localPayload);

            serverSyncChain = serverSyncChain
                .catch(() => undefined)
                .then(async () => {
                    const serverRawItems = await fetchServerCart();
                    if (serverRawItems === null) {
                        serverLastPayloadSignature = null;
                        return;
                    }

                    const normalisedServer = serverRawItems.map((item) => normaliseCartStateItem(item)).filter(Boolean);
                    const serverPayload = serialiseCartItemsForServer(normalisedServer);
                    const serverSignature = computeCartSignature(serverPayload);

                    if (normalisedServer.length === 0 && localSnapshot.length === 0) {
                        serverLastPayloadSignature = '[]';
                        return;
                    }

                    if (
                        serverSignature !== null
                        && localSignature !== null
                        && serverSignature === localSignature
                    ) {
                        applyServerCartItems(normalisedServer);
                        serverLastPayloadSignature = serverSignature;
                        return;
                    }

                    let finalItems;
                    if (normalisedServer.length === 0) {
                        finalItems = localSnapshot;
                    } else if (localSnapshot.length === 0) {
                        finalItems = normalisedServer;
                    } else {
                        finalItems = mergeCartCollections(normalisedServer, localSnapshot);
                    }

                    applyServerCartItems(finalItems, { skipSignatureUpdate: true });

                    const mergedPayload = serialiseCartItemsForServer(finalItems);
                    const mergedSignature = computeCartSignature(mergedPayload);
                    serverLastPayloadSignature = mergedSignature;

                    if (mergedSignature !== serverSignature) {
                        try {
                            await pushCartToServer(mergedPayload, mergedSignature);
                        } catch (error) {
                            // Error already logged in pushCartToServer
                        }
                    } else if (mergedSignature !== localSignature) {
                        saveCart({ skipServer: true });
                    }
                });
        }

        // Start handleCartClick: intercept the cart button to validate and forward cart contents to checkout
        function handleCartClick(event) {
            event.preventDefault();

            if (cartItems.length === 0) {
                // Show message instead of alert
                showToast('Your cart is empty! Add some items first.');
                return;
            }

            // Redirect to checkout with cart data
            const cartData = encodeURIComponent(JSON.stringify(cartItems));
            redirectToCheckout(cartData);
        }
        // End handleCartClick

        // Start addToCart: merge items into the cart, sync badge/localStorage, and notify the user
        function addToCart(
            productId,
            productName,
            price,
            quantity = 1,
            variantId = null,
            variantLabel = '',
            variantPrice = null,
            options = {},
        ) {
            const normalisedVariantId = variantId !== undefined && variantId !== null ? Number(variantId) : null;
            const effectivePrice = normaliseMoney(
                variantPrice !== null && variantPrice !== undefined ? variantPrice : price,
            );
            const safeQuantity = Number(quantity) || 0;

            if (safeQuantity <= 0) {
                return 'blocked';
            }

            const resolvedProductId = normaliseProductId(productId);

            const performAdd = () => {
                const existingItem = cartItems.find(
                    (item) => normaliseProductId(item.id) === resolvedProductId && (item.variantId ?? null) === normalisedVariantId,
                );

                if (existingItem) {
                    existingItem.quantity = (Number(existingItem.quantity) || 0) + safeQuantity;
                    existingItem.price = effectivePrice;
                    existingItem.variantLabel = variantLabel || '';
                    existingItem.variantId = normalisedVariantId;
                    existingItem.variantPrice = effectivePrice;
                } else {
                    cartItems.push({
                        id: resolvedProductId,
                        name: productName,
                        price: effectivePrice,
                        quantity: safeQuantity,
                        variantId: normalisedVariantId,
                        variantLabel: variantLabel || '',
                        variantPrice: effectivePrice,
                    });
                }

                cartCount += safeQuantity;
                updateCartBadge();

                saveCart();

                evaluateHighValueCart();

                showToast(`${productName} added to cart!`);

                if (options && typeof options.postAdd === 'function') {
                    options.postAdd();
                }
            };

            const outcome = handleHighValuePreflight({
                productId: resolvedProductId,
                variantId: normalisedVariantId,
                quantity: safeQuantity,
                unitPrice: effectivePrice,
                onProceed: performAdd,
            });

            if (outcome === 'proceed') {
                performAdd();
                return 'added';
            }

            if (outcome === 'pending') {
                return 'pending';
            }

            return 'blocked';
        }
        // End addToCart

        // Start buyNow: reuse cart merging then jump straight to checkout with the composed cart contents
        function buyNow(productId, productName, price, quantity = 1, variantId = null, variantLabel = '', variantPrice = null) {
            const outcome = addToCart(
                productId,
                productName,
                price,
                quantity,
                variantId,
                variantLabel,
                variantPrice,
                {
                    postAdd: () => {
                        const cartData = encodeURIComponent(JSON.stringify(cartItems));
                        redirectToCheckout(cartData);
                    },
                },
            );

            if (outcome === 'added' || outcome === 'pending') {
                return;
            }
        }
        // End buyNow

        // Start showToast: render a temporary toast message for user feedback
        function showToast(message) {
            // Remove existing toast if any
            const existingToast = document.querySelector('.toast-message');
            if (existingToast) {
                existingToast.remove();
            }

            // Create toast element
            const toast = document.createElement('div');
            toast.className = 'toast-message';
            toast.style.cssText = `
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: #2196f3;
            color: white;
            padding: 12px 20px;
            border-radius: 8px;
            font-weight: 600;
            z-index: 1000;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            animation: slideIn 0.3s ease;
        `;
            toast.textContent = message;

            document.body.appendChild(toast);

            // Remove after 3 seconds
            setTimeout(() => {
                toast.style.animation = 'slideOut 0.3s ease';
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }
        // End showToast

        // Add CSS animations
        const style = document.createElement('style');
        style.textContent = `
        @keyframes slideIn {
            from { transform: translateX(100px); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        @keyframes slideOut {
            from { transform: translateX(0); opacity: 1; }
            to { transform: translateX(100px); opacity: 0; }
        }
        `;
        document.head.appendChild(style);

        // Load cart on page load
        document.addEventListener('DOMContentLoaded', function () {
            loadCart();

            const cartButton = document.getElementById('cartButton');
            if (cartButton) {
                cartButton.addEventListener('click', handleCartClick);
            }
        });

        // ===== End File 1: cartStateAndInteractions.js =====
