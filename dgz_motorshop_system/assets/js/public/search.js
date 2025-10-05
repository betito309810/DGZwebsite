 // ===== Start File 2: searchAndPageEnhancements.js (search, filters, navigation helpers) =====

        let currentCategory = 'all';
        let currentSearchTerm = '';

        // Search functionality and category filter wiring
        document.addEventListener('DOMContentLoaded', function () {
            loadCart();

            const searchBar = document.querySelector('.search-bar');
            const searchBtn = document.querySelector('.search-btn');
            const categoryLinks = document.querySelectorAll('.category-link');
            const sectionTitle = document.getElementById('productSectionTitle');

            if (searchBar && searchBtn) {
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
            }

            if (categoryLinks.length) {
                categoryLinks.forEach(link => {
                    link.addEventListener('click', function (e) {
                        e.preventDefault();

                        const selectedCategory = (this.dataset.category || 'all').toLowerCase();
                        currentCategory = selectedCategory;

                        categoryLinks.forEach(item => item.classList.remove('active'));
                        this.classList.add('active');

                        if (sectionTitle) {
                            const label = selectedCategory === 'all' ? 'All Products' : this.textContent.trim();
                            sectionTitle.textContent = label;
                        }

                        applyFilters();
                    });
                });
            }

            applyFilters();
        });

        // Start filterProducts: record search term then apply combined filters
        function filterProducts(searchTerm) {
            currentSearchTerm = (searchTerm || '').toLowerCase().trim();
            applyFilters();
        }
        // End filterProducts

        function applyFilters() {
            const products = document.querySelectorAll('.product-card');
            const term = currentSearchTerm;
            const selectedCategory = currentCategory;

            products.forEach(product => {
                const name = product.querySelector('h3')?.textContent.toLowerCase() || '';
                const desc = product.querySelector('.product-description')?.textContent.toLowerCase() || '';
                const category = (product.getAttribute('data-category') || '').toLowerCase();
                const brand = (product.getAttribute('data-brand') || '').toLowerCase();

                const categoryMatches = selectedCategory === 'all' || category === selectedCategory;
                const searchMatches = !term || name.includes(term) || desc.includes(term) || category.includes(term) || brand.includes(term);

                product.style.display = categoryMatches && searchMatches ? 'block' : 'none';
            });
        }

        // Smooth scrolling for anchor links (skip category links handled above)
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            if (anchor.classList.contains('category-link')) {
                return;
            }
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
