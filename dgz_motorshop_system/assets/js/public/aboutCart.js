document.addEventListener('DOMContentLoaded', function () {
    const cartCountElement = document.getElementById('cartCount');
    const cartButton = document.getElementById('cartButton');
    const dgzPaths = window.dgzPaths || {};
    const checkoutUrl = (typeof dgzPaths.checkout === 'string' && dgzPaths.checkout !== '')
        ? dgzPaths.checkout
        : 'checkout.php';

    if (!cartCountElement || !cartButton) {
        return;
    }

    const savedCount = localStorage.getItem('cartCount');
    if (savedCount) {
        cartCountElement.textContent = savedCount;
    }

    cartButton.addEventListener('click', function (event) {
        event.preventDefault();

        try {
            const savedCart = localStorage.getItem('cartItems');
            const cartItems = savedCart ? JSON.parse(savedCart) : [];

            if (!Array.isArray(cartItems) || cartItems.length === 0) {
                alert('Your cart is empty! Add some items first.');
                return;
            }

            const cartData = encodeURIComponent(JSON.stringify(cartItems));
            const separator = checkoutUrl.includes('?') ? '&' : '?';
            window.location.href = `${checkoutUrl}${separator}cart=${cartData}`;
        } catch (error) {
            console.error('Error reading cart items:', error);
            window.location.href = checkoutUrl;
        }
    });
});
