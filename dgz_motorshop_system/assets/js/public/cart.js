
        // ===== Start File 1: cartStateAndInteractions.js (cart state, persistence, cart UI hooks) =====
        // Cart functionality
        const dgzPaths = window.dgzPaths || {};
        const checkoutBaseUrl = (typeof dgzPaths.checkout === 'string' && dgzPaths.checkout !== '')
            ? dgzPaths.checkout
            : 'checkout.php';

        function redirectToCheckout(cartData) {
            const separator = checkoutBaseUrl.includes('?') ? '&' : '?';
            window.location.href = `${checkoutBaseUrl}${separator}cart=${cartData}`;
        }

        const HIGH_VALUE_WARNING_THRESHOLD = 70000;
        const HIGH_VALUE_BLOCK_THRESHOLD = 100000;

        let cartCount = 0;
        let cartItems = [];
        const highValueState = {
            warningShown: false,
            blockedShown: false,
        };

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

                const closeConfirm = () => {
                    confirmModal.setAttribute('hidden', 'hidden');
                };

                proceedButton?.addEventListener('click', closeConfirm);
                cancelButton?.addEventListener('click', closeConfirm);

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

        function calculateCartSubtotal() {
            return cartItems.reduce((total, item) => {
                const unitPriceRaw = (item.variantPrice !== undefined && item.variantPrice !== null)
                    ? Number(item.variantPrice)
                    : Number(item.price);
                const unitPrice = Number.isFinite(unitPriceRaw) ? unitPriceRaw : 0;
                const quantity = Number(item.quantity) || 0;
                return total + unitPrice * quantity;
            }, 0);
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
        function saveCart() {
            localStorage.setItem('cartItems', JSON.stringify(cartItems));
            localStorage.setItem('cartCount', cartCount.toString());
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
        }
        // End loadCart

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
        function addToCart(productId, productName, price, quantity = 1, variantId = null, variantLabel = '', variantPrice = null) {
            const normalisedVariantId = variantId !== undefined && variantId !== null ? Number(variantId) : null;
            const effectivePrice = variantPrice !== null && variantPrice !== undefined ? Number(variantPrice) : Number(price);

            const existingItem = cartItems.find(item => item.id === productId && (item.variantId ?? null) === normalisedVariantId);

            if (existingItem) {
                existingItem.quantity += quantity;
                existingItem.price = effectivePrice;
                existingItem.variantLabel = variantLabel || '';
                existingItem.variantId = normalisedVariantId;
                existingItem.variantPrice = effectivePrice;
            } else {
                cartItems.push({
                    id: productId,
                    name: productName,
                    price: effectivePrice,
                    quantity: quantity,
                    variantId: normalisedVariantId,
                    variantLabel: variantLabel || '',
                    variantPrice: effectivePrice
                });
            }

            cartCount += quantity;
            updateCartBadge();

            // Save to localStorage
            saveCart();

            evaluateHighValueCart();

            // Show confirmation
            showToast(`${productName} added to cart!`);
        }
        // End addToCart

        // Start buyNow: reuse cart merging then jump straight to checkout with the composed cart contents
        function buyNow(productId, productName, price, quantity = 1, variantId = null, variantLabel = '', variantPrice = null) {
            addToCart(productId, productName, price, quantity, variantId, variantLabel, variantPrice);

            const cartData = encodeURIComponent(JSON.stringify(cartItems));
            redirectToCheckout(cartData);
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
