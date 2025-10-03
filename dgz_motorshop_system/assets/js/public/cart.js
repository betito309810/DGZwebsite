
        // ===== Start File 1: cartStateAndInteractions.js (cart state, persistence, cart UI hooks) =====
        // Cart functionality
        let cartCount = 0;
        let cartItems = [];

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
                    cartItems = JSON.parse(savedCart);
                } catch (e) {
                    cartItems = [];
                    console.error('Error parsing cart items:', e);
                }
            }
            if (savedCount) {
                cartCount = parseInt(savedCount);
                document.getElementById('cartCount').textContent = cartCount;
            }
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
            window.location.href = 'checkout.php?cart=' + cartData;
        }
        // End handleCartClick

        // Start addToCart: merge items into the cart, sync badge/localStorage, and notify the user
        function addToCart(productId, productName, price, quantity = 1) {
            // Check if product already in cart
            const existingItem = cartItems.find(item => item.id === productId);

            if (existingItem) {
                existingItem.quantity += quantity;
            } else {
                cartItems.push({
                    id: productId,
                    name: productName,
                    price: price,
                    quantity: quantity
                });
            }

            cartCount += quantity;
            document.getElementById('cartCount').textContent = cartCount;

            // Save to localStorage
            saveCart();

            // Show confirmation
            showToast(`${productName} added to cart!`);
        }
        // End addToCart

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
        });

        // ===== End File 1: cartStateAndInteractions.js =====