// file 2 start – stock entry page filters/autocomplete (safe to extract)
// Pass inventory data via window bootstrap (populated in stockEntry.php)
const { products: allProducts = [], categories: allCategories = [] } = window.dgzStockEntryData || {};

// Get references to filter dropdowns, product dropdown, search input, and autocomplete list container
const categoryFilter = document.getElementById('category_filter');
const brandFilter = document.getElementById('brand_filter');
const supplierFilter = document.getElementById('supplier');
const productDropdown = document.getElementById('product_id');
const productSearch = document.getElementById('product_search');
const autocompleteList = document.getElementById('autocomplete-list');

// Function to filter products based on selected filters
function filterProducts() {
            const selectedCategory = categoryFilter.value;
            const selectedBrand = brandFilter.value;
            const selectedSupplier = supplierFilter.value;

            // Filter products based on selected filters
            return allProducts.filter(product => {
                const matchesCategory = selectedCategory === '' || product.category === selectedCategory;
                const matchesBrand = selectedBrand === '' || product.brand === selectedBrand;
                const matchesSupplier = selectedSupplier === '' || product.supplier === selectedSupplier;
                return matchesCategory && matchesBrand && matchesSupplier;
            });
}

// Function to show autocomplete suggestions based on search input and filters
function showAutocomplete() {
            const searchText = productSearch.value.trim().toLowerCase();
            autocompleteList.innerHTML = '';

            if (!searchText) {
                return;
            }

            const filteredProducts = filterProducts().filter(product =>
                product.name.toLowerCase().includes(searchText)
            );

            filteredProducts.forEach(product => {
                const item = document.createElement('div');
                item.textContent = product.name;
                item.addEventListener('click', () => {
                    productSearch.value = product.name;
                    autocompleteList.innerHTML = '';
                    // Set the product dropdown to the selected product
                    productDropdown.value = product.id;
                });
                autocompleteList.appendChild(item);
            });
}

// Event listeners for filters to clear search and autocomplete and update product dropdown
categoryFilter.addEventListener('change', () => {
    productSearch.value = '';
    autocompleteList.innerHTML = '';
    updateProductDropdown();
    updateBrandAndSupplierDropdowns();
});
// Flag to prevent infinite loop when updating dropdowns
let isUpdatingDropdowns = false;

brandFilter.addEventListener('change', () => {
    if (isUpdatingDropdowns) return;
    productSearch.value = '';
    autocompleteList.innerHTML = '';

    // Preserve selected product if it matches the selected brand and supplier
    const selectedProductId = productDropdown.value;
    const selectedBrand = brandFilter.value;
    const selectedSupplier = supplierFilter.value;
    const selectedProduct = allProducts.find(p => p.id == selectedProductId);

    isUpdatingDropdowns = true;
    if (selectedProduct && 
        (selectedBrand === '' || selectedProduct.brand === selectedBrand) &&
        (selectedSupplier === '' || selectedProduct.supplier === selectedSupplier)) {
        // Keep the selected product
        updateProductDropdown(true);
    } else {
        // Reset product selection
        productDropdown.value = '';
        updateProductDropdown(false);
    }
    updateBrandAndSupplierDropdowns();
    isUpdatingDropdowns = false;
});

supplierFilter.addEventListener('change', () => {
    if (isUpdatingDropdowns) return;
    productSearch.value = '';
    autocompleteList.innerHTML = '';

    // Preserve selected product if it matches the selected brand and supplier
    const selectedProductId = productDropdown.value;
    const selectedBrand = brandFilter.value;
    const selectedSupplier = supplierFilter.value;
    const selectedProduct = allProducts.find(p => p.id == selectedProductId);

    isUpdatingDropdowns = true;
    if (selectedProduct && 
        (selectedBrand === '' || selectedProduct.brand === selectedBrand) &&
        (selectedSupplier === '' || selectedProduct.supplier === selectedSupplier)) {
        // Keep the selected product
        updateProductDropdown(true);
    } else {
        // Reset product selection
        productDropdown.value = '';
        updateProductDropdown(false);
    }
    updateBrandAndSupplierDropdowns();
    isUpdatingDropdowns = false;
});

// Event listener for product dropdown change to update category, brand, and supplier
productDropdown.addEventListener('change', () => {
            const selectedProductId = productDropdown.value;
            const selectedProduct = allProducts.find(p => p.id == selectedProductId);

            if (selectedProduct) {
                // Automatically fill category with only the selected product's category
                categoryFilter.innerHTML = '';
                if (selectedProduct.category) {
                    const option = document.createElement('option');
                    option.value = selectedProduct.category;
                    option.textContent = selectedProduct.category;
                    option.selected = true;
                    categoryFilter.appendChild(option);
                }

                // Filter brand and supplier dropdowns based on selected product
                updateBrandAndSupplierDropdowns(selectedProduct);
            } else {
        // Reset category dropdown to show all categories
        categoryFilter.innerHTML = '<option value="">All Categories</option>';
        allCategories.forEach(category => {
            const option = document.createElement('option');
            option.value = category;
            option.textContent = category;
            categoryFilter.appendChild(option);
        });

        updateBrandAndSupplierDropdowns();
    }
});

        // Event listener for search input to show autocomplete suggestions
        productSearch.addEventListener('input', showAutocomplete);

// Function to update product dropdown based on filters (without search)
// If keepSelected is true, preserve the current selected product if it exists in the filtered list
function updateProductDropdown(keepSelected = false) {
    const filteredProducts = filterProducts();
    const currentSelected = productDropdown.value;
    productDropdown.innerHTML = '<option value="">Select Product</option>';
    filteredProducts.forEach(product => {
        const option = document.createElement('option');
        option.value = product.id;
        option.textContent = product.name;
        productDropdown.appendChild(option);
    });
    if (keepSelected && currentSelected) {
        const exists = filteredProducts.some(p => p.id == currentSelected);
        if (exists) {
            productDropdown.value = currentSelected;
        }
    }
}

// Function to update brand and supplier dropdowns based on selected product or filters
function updateBrandAndSupplierDropdowns(selectedProduct = null) {
            let brandsToShow = [];
            let suppliersToShow = [];

            if (selectedProduct) {
                // Show only the brand and supplier of the selected product
                brandsToShow = [selectedProduct.brand].filter(Boolean);
                suppliersToShow = [selectedProduct.supplier].filter(Boolean);
            } else {
                // Show all brands and suppliers based on current filters
                const filteredProducts = filterProducts();
                brandsToShow = [...new Set(filteredProducts.map(p => p.brand).filter(Boolean))];
                suppliersToShow = [...new Set(filteredProducts.map(p => p.supplier).filter(Boolean))];
            }

            // Update brand dropdown options
            const currentBrandValue = brandFilter.value;
            brandFilter.innerHTML = '<option value="">All Brands</option>';
            brandsToShow.forEach(brand => {
                const option = document.createElement('option');
                option.value = brand;
                option.textContent = brand;
                if (brand === currentBrandValue) {
                    option.selected = true;
                }
                brandFilter.appendChild(option);
            });

            // Update supplier dropdown options
            const currentSupplierValue = supplierFilter.value;
            supplierFilter.innerHTML = '<option value="">Select Supplier</option>';
            suppliersToShow.forEach(supplier => {
                const option = document.createElement('option');
                option.value = supplier;
                option.textContent = supplier;
                if (supplier === currentSupplierValue) {
                    option.selected = true;
                }
                supplierFilter.appendChild(option);
            });
}

// Initial population of product dropdown
updateProductDropdown();

// Close autocomplete list when clicking outside
document.addEventListener('click', function (e) {
    if (e.target !== productSearch) {
        autocompleteList.innerHTML = '';
    }
});
