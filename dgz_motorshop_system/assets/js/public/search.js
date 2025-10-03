 // ===== Start File 2: searchAndPageEnhancements.js (search, filters, navigation helpers) =====

        // Search functionality - robust and clean
        document.addEventListener('DOMContentLoaded', function () {
            loadCart();
            const searchBar = document.querySelector('.search-bar');
            const searchBtn = document.querySelector('.search-btn');
            if (!searchBar || !searchBtn) return;

            // Search on Enter key
            searchBar.addEventListener('keyup', function (e) {
                if (e.key === 'Enter') {
                    filterProducts(this.value);
                }
            });
            // Search on button click
            searchBtn.addEventListener('click', function () {
                filterProducts(searchBar.value);
            });
            // Live search as user types
            searchBar.addEventListener('input', function () {
                filterProducts(this.value);
            });
        });

        // Start filterProducts: evaluate product cards against the search term and toggle visibility
        function filterProducts(searchTerm) {
            const term = (searchTerm || '').toLowerCase().trim();
            const products = document.querySelectorAll('.product-card');
            products.forEach(product => {
                const name = product.querySelector('h3')?.textContent.toLowerCase() || '';
                const desc = product.querySelector('.product-description')?.textContent.toLowerCase() || '';
                const category = (product.getAttribute('data-category') || '').toLowerCase();
                const brand = (product.getAttribute('data-brand') || '').toLowerCase();
                if (
                    !term ||
                    name.includes(term) ||
                    desc.includes(term) ||
                    category.includes(term) ||
                    brand.includes(term)
                ) {
                    product.style.display = 'block';
                } else {
                    product.style.display = 'none';
                }
            });
        }
        // End filterProducts

        // Smooth scrolling for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            // Start anchor click handler: override default to perform smooth scrolling to sections
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth'
                    });
                }
            });
            // End anchor click handler: smooth scroll logic
        });

        // Update cart count when buy forms are submitted
        document.querySelectorAll('.buy-form').forEach(form => {
            // Start buy form submit handler: increment cart badge when user proceeds directly to checkout
            form.addEventListener('submit', function () {
                cartCount++;
                document.getElementById('cartCount').textContent = cartCount;
            });
            // End buy form submit handler
        });
        // ===== End File 2: searchAndPageEnhancements.js =====