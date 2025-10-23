// file 2 start – inventory page helpers (safe to extract)
        const restockAnimationHandlers = new WeakMap();
        let restockScrollTimer;

        function setRestockVisibility(element, state) {
            if (!element) {
                return;
            }
            element.dataset.panelVisibility = state;
        }

        function isRestockHidden(element) {
            if (!element) {
                return true;
            }
            const state = element.dataset.panelVisibility;
            if (state === 'hiding') {
                return true;
            }
            if (state === 'visible' || state === 'showing') {
                return false;
            }
            return element.classList.contains('hidden');
        }

        function clearRestockAnimation(element) {
            if (!element) {
                return;
            }
            const activeHandler = restockAnimationHandlers.get(element);
            if (activeHandler) {
                element.removeEventListener('transitionend', activeHandler);
                restockAnimationHandlers.delete(element);
            }
            element.style.removeProperty('height');
            element.style.removeProperty('overflow');
            element.style.removeProperty('transition');
            element.style.removeProperty('opacity');
        }

        function registerRestockTransition(element, callback) {
            const handler = (event) => {
                if (event.target !== element || event.propertyName !== 'height') {
                    return;
                }
                element.removeEventListener('transitionend', handler);
                restockAnimationHandlers.delete(element);
                callback();
            };

            const existing = restockAnimationHandlers.get(element);
            if (existing) {
                element.removeEventListener('transitionend', existing);
            }

            restockAnimationHandlers.set(element, handler);
            element.addEventListener('transitionend', handler);
        }

        function hideSection(element) {
            if (!element || element.classList.contains('hidden')) {
                clearRestockAnimation(element);
                setRestockVisibility(element, 'hidden');
                return;
            }

            clearRestockAnimation(element);

            const startHeight = element.scrollHeight;
            if (startHeight === 0) {
                element.classList.add('hidden');
                setRestockVisibility(element, 'hidden');
                return;
            }

            setRestockVisibility(element, 'hiding');
            element.style.height = `${startHeight}px`;
            element.style.opacity = '1';
            element.style.overflow = 'hidden';
            element.style.transition = 'height 0.3s ease, opacity 0.2s ease';

            requestAnimationFrame(() => {
                element.style.height = '0px';
                element.style.opacity = '0';
            });

            registerRestockTransition(element, () => {
                element.classList.add('hidden');
                clearRestockAnimation(element);
                setRestockVisibility(element, 'hidden');
            });
        }

        function showSection(element) {
            if (!element) {
                return;
            }

            clearRestockAnimation(element);
            element.classList.remove('hidden');
            setRestockVisibility(element, 'showing');

            const targetHeight = element.scrollHeight;
            if (targetHeight === 0) {
                clearRestockAnimation(element);
                setRestockVisibility(element, 'visible');
                return;
            }

            element.style.height = '0px';
            element.style.opacity = '0';
            element.style.overflow = 'hidden';
            element.style.transition = 'height 0.3s ease, opacity 0.2s ease';

            requestAnimationFrame(() => {
                element.style.height = `${targetHeight}px`;
                element.style.opacity = '1';
            });

            registerRestockTransition(element, () => {
                clearRestockAnimation(element);
                setRestockVisibility(element, 'visible');
            });
        }

        function openStockModal() {
            const modal = document.getElementById('stockEntryModal');
            if (modal) {
                modal.style.display = 'block';
            }
        }

        function closeStockModal() {
            const modal = document.getElementById('stockEntryModal');
            if (modal) {
                modal.style.display = 'none';
            }
        }

        function toggleRestockForm() {
            const form = document.getElementById('restockRequestForm');
            if (!form) {
                return;
            }

            if (isRestockHidden(form)) {
                showSection(form);
                form.setAttribute('aria-hidden', 'false');
                window.clearTimeout(restockScrollTimer);
                restockScrollTimer = window.setTimeout(() => {
                    form.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }, 320);
            } else {
                window.clearTimeout(restockScrollTimer);
                hideSection(form);
                form.setAttribute('aria-hidden', 'true');
            }
        }

        function toggleRestockStatus() {
            const panel = document.getElementById('restockStatusPanel');
            const button = document.getElementById('restockStatusButton');
            if (!panel || !button) {
                return;
            }

            const isHidden = panel.classList.toggle('hidden');
            button.classList.toggle('active', !isHidden);
            if (!isHidden) {
                panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            // file 2 continue – inventory page behavior bundle (safe to extract)
            const profileButton = document.getElementById('profileTrigger');
            const profileModal = document.getElementById('profileModal');
            const profileModalClose = document.getElementById('profileModalClose');

            document.addEventListener('click', (event) => {
                const userMenu = document.querySelector('.user-menu');
                const dropdown = document.getElementById('userDropdown');

                if (userMenu && dropdown && !userMenu.contains(event.target)) {
                    dropdown.classList.remove('show');
                }

                const sidebar = document.getElementById('sidebar');
                const toggleButton = document.querySelector('.mobile-toggle');
                if (
                    window.innerWidth <= 768 &&
                    sidebar &&
                    toggleButton &&
                    !sidebar.contains(event.target) &&
                    !toggleButton.contains(event.target)
                ) {
                    sidebar.classList.remove('mobile-open');
                }
            });

            profileButton?.addEventListener('click', (event) => {
                event.preventDefault();
                document.getElementById('userDropdown')?.classList.remove('show');
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
                if (event.key === 'Escape' && profileModal?.classList.contains('show')) {
                    closeProfileModal();
                }
            });

            window.addEventListener('click', (event) => {
                const modal = document.getElementById('stockEntryModal');
                if (event.target === modal) {
                    closeStockModal();
                }
            });

            const alerts = document.querySelectorAll('.alert');
            alerts.forEach((alert) => {
                setTimeout(() => {
                    alert.style.transition = 'opacity 0.5s';
                    alert.style.opacity = '0';
                    setTimeout(() => alert.remove(), 500);
                }, 5000);
            });

            const variantBootstrapNode = document.getElementById('inventoryVariants');
            const variantsByProduct = new Map();
            if (variantBootstrapNode) {
                try {
                    const raw = JSON.parse(variantBootstrapNode.textContent || '{}');
                    if (raw && typeof raw === 'object') {
                        Object.entries(raw).forEach(([productId, variants]) => {
                            if (!Array.isArray(variants)) {
                                variantsByProduct.set(String(productId), []);
                                return;
                            }

                            const normalisedVariants = variants.map((variant) => ({
                                id: String(variant?.id ?? ''),
                                label: variant?.label ? String(variant.label) : '',
                                sku: variant?.sku ? String(variant.sku) : '',
                                is_default: Number(variant?.is_default ?? 0),
                            }));

                            variantsByProduct.set(String(productId), normalisedVariants);
                        });
                    }
                } catch (error) {
                    console.warn('Unable to parse inventory variant bootstrap data.', error);
                }
            }

            const restockFormEl = document.querySelector('.restock-form');
            const productSelect = document.getElementById('restock_product');
            const categorySelect = document.getElementById('restock_category');
            const categoryNewInput = document.getElementById('restock_category_new');
            const brandSelect = document.getElementById('restock_brand');
            const brandNewInput = document.getElementById('restock_brand_new');
            const supplierSelect = document.getElementById('restock_supplier');
            const supplierNewInput = document.getElementById('restock_supplier_new');
            const productSearchInput = document.getElementById('restock_product_search');
            const productSuggestions = document.querySelector('[data-product-suggestions]');
            const productFilterApplyButton = document.querySelector('[data-product-filter]');
            const productFilterResetButton = document.querySelector('[data-product-filter-clear]');
            const statusPanel = document.getElementById('restockStatusPanel');
            const statusButton = document.getElementById('restockStatusButton');
            const quantityInput = document.getElementById('restock_quantity');
            const prioritySelect = document.getElementById('restock_priority');
            const notesTextarea = document.getElementById('restock_notes');
            const variantField = document.querySelector('[data-restock-variant-field]');
            const variantSelect = document.getElementById('restock_variant');

            let initialVariantValue = '';
            let pendingInitialVariant = false;
            if (restockFormEl) {
                initialVariantValue = (restockFormEl.dataset.initialVariant || '').toString();
                pendingInitialVariant = initialVariantValue !== '';
            }

            function handleSelectChange(selectEl, inputEl) {
                if (!selectEl || !inputEl) {
                    return;
                }
                const needsInput = selectEl.value === '__addnew__';
                inputEl.style.display = needsInput ? 'block' : 'none';
                inputEl.required = needsInput;
                if (!needsInput) {
                    inputEl.value = '';
                }
            }

            function setSelectOrInput(selectEl, inputEl, value) {
                if (!selectEl || !inputEl) {
                    return;
                }
                const trimmed = (value || '').trim();
                const options = Array.from(selectEl.options).map(opt => opt.value);

                if (trimmed !== '' && options.includes(trimmed)) {
                    selectEl.value = trimmed;
                    inputEl.style.display = 'none';
                    inputEl.required = false;
                    inputEl.value = '';
                } else if (trimmed !== '') {
                    selectEl.value = '__addnew__';
                    inputEl.style.display = 'block';
                    inputEl.required = true;
                    inputEl.value = trimmed;
                } else {
                    selectEl.value = '';
                    inputEl.style.display = 'none';
                    inputEl.required = false;
                    inputEl.value = '';
                }
            }

            const getVariantsForProduct = (productId) => {
                if (!productId) {
                    return [];
                }

                return variantsByProduct.get(String(productId)) || [];
            };

            const resetVariantField = () => {
                if (!variantSelect) {
                    return;
                }

                variantSelect.innerHTML = '<option value="">No variants available</option>';
                variantSelect.value = '';
                variantSelect.disabled = true;
                variantSelect.required = false;
                pendingInitialVariant = false;
                initialVariantValue = '';
            };

            const populateVariantField = (productId, { presetVariant = '' } = {}) => {
                if (!variantSelect || !variantField) {
                    return;
                }

                const variants = getVariantsForProduct(productId);
                if (!variants.length) {
                    resetVariantField();
                    return;
                }

                const previousValue = variantSelect.value;
                variantSelect.innerHTML = '<option value="">Select variant</option>';

                variants.forEach((variant) => {
                    const option = document.createElement('option');
                    option.value = variant.id;
                    const baseLabel = variant.label && variant.label.trim() !== ''
                        ? variant.label
                        : `Variant #${variant.id}`;
                    option.textContent = baseLabel;
                    variantSelect.appendChild(option);
                });

                variantSelect.disabled = false;
                variantSelect.required = true;

                let targetVariant = presetVariant || '';

                if (!targetVariant && previousValue && variants.some((variant) => String(variant.id) === String(previousValue))) {
                    targetVariant = previousValue;
                }

                if (!targetVariant && pendingInitialVariant && initialVariantValue) {
                    if (variants.some((variant) => String(variant.id) === String(initialVariantValue))) {
                        targetVariant = initialVariantValue;
                    }
                    pendingInitialVariant = false;
                }

                if (targetVariant && !variants.some((variant) => String(variant.id) === String(targetVariant))) {
                    targetVariant = '';
                }

                variantSelect.value = targetVariant;
            };

            const metadataOverrides = {
                category: false,
                brand: false,
                supplier: false,
            };

            const metadataFieldMap = new Map([
                [categorySelect, 'category'],
                [brandSelect, 'brand'],
                [supplierSelect, 'supplier'],
            ]);

            const selectMappings = [
                { select: categorySelect, input: categoryNewInput },
                { select: brandSelect, input: brandNewInput },
                { select: supplierSelect, input: supplierNewInput },
            ];

            selectMappings.forEach(({ select, input }) => {
                if (select && input) {
                    select.addEventListener('change', (event) => {
                        handleSelectChange(select, input);
                        if (event.isTrusted) {
                            const key = metadataFieldMap.get(select);
                            if (key) {
                                const rawValue = select.value || '';
                                metadataOverrides[key] = rawValue !== '';
                            }
                        }
                    });
                    input.addEventListener('input', (event) => {
                        if (event.isTrusted) {
                            const key = metadataFieldMap.get(select);
                            if (key) {
                                metadataOverrides[key] = (event.target.value || '').trim() !== '';
                            }
                        }
                    });
                    handleSelectChange(select, input);
                }
            });

            // Added product filter/search helpers for restock picker
            let productOptionsSnapshot = null;
            let suggestionDismissTimer = null;

            const normaliseFilterValue = (value) => {
                if (!value) {
                    return '';
                }
                const trimmed = value.trim();
                if (trimmed === '__addnew__') {
                    return '';
                }
                return trimmed.toLowerCase();
            };

            function ensureProductOptionsSnapshot() {
                if (productOptionsSnapshot || !productSelect) {
                    return;
                }

                const optionNodes = Array.from(productSelect.options);
                const placeholderNode = optionNodes.find((option) => option.value === '');
                const placeholder = placeholderNode
                    ? {
                        value: placeholderNode.value,
                        text: placeholderNode.textContent,
                        disabled: placeholderNode.disabled,
                        hidden: placeholderNode.hidden,
                    }
                    : null;

                const entries = optionNodes
                    .filter((option) => option.value !== '')
                    .map((option) => ({
                        value: option.value,
                        text: option.textContent,
                        name: option.dataset.name || '',
                        code: option.dataset.code || '',
                        category: option.dataset.category || '',
                        brand: option.dataset.brand || '',
                        supplier: option.dataset.supplier || '',
                        variantCount: option.dataset.variantCount !== undefined
                            ? Number(option.dataset.variantCount)
                            : getVariantsForProduct(option.value).length,
                    }));

                productOptionsSnapshot = { placeholder, entries };
            }

            function hideProductSuggestions() {
                if (!productSuggestions) {
                    return;
                }
                productSuggestions.classList.remove('is-visible');
                productSuggestions.innerHTML = '';
            }

            function showProductSuggestions() {
                if (!productSuggestions || !productSuggestions.hasChildNodes()) {
                    return;
                }
                productSuggestions.classList.add('is-visible');
            }

            function createSuggestionItem(entry) {
                const listItem = document.createElement('li');
                const button = document.createElement('button');
                button.type = 'button';
                button.dataset.productId = entry.value;

                const title = document.createElement('span');
                title.className = 'suggestion-title';
                title.textContent = entry.name || entry.text;
                button.appendChild(title);

                const metaParts = [];
                if (entry.code) {
                    metaParts.push(`#${entry.code}`);
                }
                if (entry.brand) {
                    metaParts.push(entry.brand);
                }
                if (entry.category) {
                    metaParts.push(entry.category);
                }

                if (metaParts.length > 0) {
                    const meta = document.createElement('span');
                    meta.className = 'suggestion-meta';
                    meta.textContent = metaParts.join(' • ');
                    button.appendChild(meta);
                }

                listItem.appendChild(button);
                return listItem;
            }

            function updateProductSuggestions() {
                if (!productSearchInput || !productSuggestions) {
                    return;
                }

                ensureProductOptionsSnapshot();
                if (!productOptionsSnapshot) {
                    hideProductSuggestions();
                    return;
                }

                const query = productSearchInput.value.trim().toLowerCase();
                if (query.length === 0) {
                    hideProductSuggestions();
                    return;
                }

                const categoryFilter = normaliseFilterValue(categorySelect?.value || '');
                const brandFilter = normaliseFilterValue(brandSelect?.value || '');
                const supplierFilter = normaliseFilterValue(supplierSelect?.value || '');

                const matches = productOptionsSnapshot.entries.filter((entry) => {
                    const matchesCategory = categoryFilter === '' || (entry.category || '').toLowerCase() === categoryFilter;
                    const matchesBrand = brandFilter === '' || (entry.brand || '').toLowerCase() === brandFilter;
                    const matchesSupplier = supplierFilter === '' || (entry.supplier || '').toLowerCase() === supplierFilter;

                    if (!matchesCategory || !matchesBrand || !matchesSupplier) {
                        return false;
                    }

                    const searchIndex = `${entry.name} ${entry.code} ${entry.text}`.toLowerCase();
                    return searchIndex.includes(query);
                }).slice(0, 8);

                if (matches.length === 0) {
                    hideProductSuggestions();
                    return;
                }

                productSuggestions.innerHTML = '';
                matches.forEach((entry) => {
                    productSuggestions.appendChild(createSuggestionItem(entry));
                });
                showProductSuggestions();
            }

            function createOptionNode(data) {
                const option = document.createElement('option');
                option.value = data.value;
                option.textContent = data.text;
                if (data.disabled) {
                    option.disabled = true;
                }
                if (data.hidden) {
                    option.hidden = true;
                }
                if (data.category !== undefined) {
                    option.setAttribute('data-category', data.category);
                }
                if (data.brand !== undefined) {
                    option.setAttribute('data-brand', data.brand);
                }
                if (data.supplier !== undefined) {
                    option.setAttribute('data-supplier', data.supplier);
                }
                if (data.code !== undefined) {
                    option.setAttribute('data-code', data.code);
                }
                if (data.name !== undefined) {
                    option.setAttribute('data-name', data.name);
                }
                if (data.variantCount !== undefined) {
                    option.setAttribute('data-variant-count', String(data.variantCount));
                }
                return option;
            }

            function renderProductOptions(entries, { focusSelect = false } = {}) {
                if (!productSelect) {
                    return;
                }

                const previousValue = productSelect.value;
                productSelect.innerHTML = '';

                hideProductSuggestions();

                if (productOptionsSnapshot?.placeholder) {
                    const placeholderOption = createOptionNode(productOptionsSnapshot.placeholder);
                    placeholderOption.selected = true;
                    productSelect.appendChild(placeholderOption);
                }

                if (entries.length === 0) {
                    const emptyNotice = document.createElement('option');
                    emptyNotice.value = '';
                    emptyNotice.textContent = 'No matching products';
                    emptyNotice.disabled = true;
                    productSelect.appendChild(emptyNotice);
                    productSelect.value = '';
                } else {
                    entries.forEach((entry) => {
                        productSelect.appendChild(createOptionNode(entry));
                    });

                    if (entries.some((entry) => entry.value === previousValue)) {
                        productSelect.value = previousValue;
                    } else if (entries.length === 1) {
                        productSelect.value = entries[0].value;
                    } else {
                        productSelect.value = '';
                    }
                }

                updateProductMeta();
                if (focusSelect) {
                    productSelect.focus();
                }
            }

            function applyProductFilters({ focusSelect = false } = {}) {
                ensureProductOptionsSnapshot();
                if (!productOptionsSnapshot) {
                    return;
                }

                const searchTerm = (productSearchInput?.value || '').trim().toLowerCase();
                const categoryFilter = normaliseFilterValue(categorySelect?.value || '');
                const brandFilter = normaliseFilterValue(brandSelect?.value || '');
                const supplierFilter = normaliseFilterValue(supplierSelect?.value || '');

                const entries = productOptionsSnapshot.entries.filter((entry) => {
                    const matchesCategory = categoryFilter === '' || (entry.category || '').toLowerCase() === categoryFilter;
                    const matchesBrand = brandFilter === '' || (entry.brand || '').toLowerCase() === brandFilter;
                    const matchesSupplier = supplierFilter === '' || (entry.supplier || '').toLowerCase() === supplierFilter;

                    if (!matchesCategory || !matchesBrand || !matchesSupplier) {
                        return false;
                    }

                    if (searchTerm === '') {
                        return true;
                    }

                    const searchIndex = `${entry.name} ${entry.code} ${entry.text}`.toLowerCase();
                    return searchIndex.includes(searchTerm);
                });

                renderProductOptions(entries, { focusSelect });
                hideProductSuggestions();
            }

            function resetProductFilters() {
                if (productSearchInput) {
                    productSearchInput.value = '';
                }

                const resetMappings = [
                    { key: 'category', select: categorySelect, input: categoryNewInput },
                    { key: 'brand', select: brandSelect, input: brandNewInput },
                    { key: 'supplier', select: supplierSelect, input: supplierNewInput },
                ];

                resetMappings.forEach(({ key, select, input }) => {
                    if (!select || !input) {
                        return;
                    }
                    metadataOverrides[key] = false;
                    setSelectOrInput(select, input, '');
                    handleSelectChange(select, input);
                });

                if (productSelect) {
                    productSelect.value = '';
                }

                ensureProductOptionsSnapshot();
                if (productOptionsSnapshot) {
                    renderProductOptions(productOptionsSnapshot.entries);
                }

                hideProductSuggestions();
            }

            function syncMetadataField(selectEl, inputEl, key, value) {
                if (!selectEl || !inputEl) {
                    return;
                }

                if (metadataOverrides[key]) {
                    return;
                }

                setSelectOrInput(selectEl, inputEl, value);
                handleSelectChange(selectEl, inputEl);
            }

            function updateProductMeta() {
                if (!productSelect) {
                    return;
                }

                const selectedOption = productSelect.options[productSelect.selectedIndex];
                if (!selectedOption) {
                    syncMetadataField(categorySelect, categoryNewInput, 'category', '');
                    syncMetadataField(brandSelect, brandNewInput, 'brand', '');
                    syncMetadataField(supplierSelect, supplierNewInput, 'supplier', '');
                    resetVariantField();
                    return;
                }

                syncMetadataField(categorySelect, categoryNewInput, 'category', selectedOption.getAttribute('data-category') || '');
                syncMetadataField(brandSelect, brandNewInput, 'brand', selectedOption.getAttribute('data-brand') || '');
                syncMetadataField(supplierSelect, supplierNewInput, 'supplier', selectedOption.getAttribute('data-supplier') || '');
                const productId = selectedOption.value || '';
                if (productId) {
                    populateVariantField(productId);
                } else {
                    resetVariantField();
                }
            }

            let hasInitialFormData = false;
            if (restockFormEl) {
                const data = restockFormEl.dataset;
                const initialCategoryValue = data.initialCategoryNew || data.initialCategory;
                const initialBrandValue = data.initialBrandNew || data.initialBrand;
                const initialSupplierValue = data.initialSupplierNew || data.initialSupplier;

                if (
                    data.initialProduct || data.initialQuantity || initialCategoryValue ||
                    initialBrandValue || initialSupplierValue || data.initialPriority ||
                    data.initialNotes
                ) {
                    hasInitialFormData = true;
                    if (productSelect) {
                        productSelect.value = data.initialProduct || '';
                    }
                    if (quantityInput) {
                        quantityInput.value = data.initialQuantity || '';
                    }
                    setSelectOrInput(categorySelect, categoryNewInput, initialCategoryValue || '');
                    handleSelectChange(categorySelect, categoryNewInput);
                    setSelectOrInput(brandSelect, brandNewInput, initialBrandValue || '');
                    handleSelectChange(brandSelect, brandNewInput);
                    setSelectOrInput(supplierSelect, supplierNewInput, initialSupplierValue || '');
                    handleSelectChange(supplierSelect, supplierNewInput);
                    if (initialCategoryValue) {
                        metadataOverrides.category = true;
                    }
                    if (initialBrandValue) {
                        metadataOverrides.brand = true;
                    }
                    if (initialSupplierValue) {
                        metadataOverrides.supplier = true;
                    }
                    if (prioritySelect) {
                        prioritySelect.value = data.initialPriority || '';
                    }
                    if (notesTextarea) {
                        notesTextarea.value = data.initialNotes || '';
                    }

                    updateProductMeta();
                }
            }

            if (productSelect) {
                ensureProductOptionsSnapshot();
                productSelect.addEventListener('change', () => {
                    updateProductMeta();
                });
                if (!hasInitialFormData) {
                    updateProductMeta();
                }
            }

            variantSelect?.addEventListener('change', () => {
                pendingInitialVariant = false;
            });

            productSearchInput?.addEventListener('input', () => {
                updateProductSuggestions();
            });

            productSearchInput?.addEventListener('focus', () => {
                window.clearTimeout(suggestionDismissTimer);
                updateProductSuggestions();
            });

            productSearchInput?.addEventListener('blur', () => {
                window.clearTimeout(suggestionDismissTimer);
                suggestionDismissTimer = window.setTimeout(() => {
                    hideProductSuggestions();
                }, 160);
            });

            productSuggestions?.addEventListener('mousedown', (event) => {
                // Prevent blur handler from clearing suggestions before we handle the click
                event.preventDefault();
            });

            productSuggestions?.addEventListener('click', (event) => {
                const button = event.target.closest('button[data-product-id]');
                if (!button) {
                    return;
                }

                ensureProductOptionsSnapshot();
                const productId = button.dataset.productId;
                const entry = productOptionsSnapshot?.entries.find((item) => item.value === productId);
                if (!entry) {
                    hideProductSuggestions();
                    return;
                }

                if (productSelect) {
                    productSelect.value = entry.value;
                    updateProductMeta();
                    productSelect.focus();
                }

                if (productSearchInput) {
                    productSearchInput.value = entry.name || entry.text || '';
                }

                hideProductSuggestions();
            });

            document.addEventListener('click', (event) => {
                if (
                    productSuggestions &&
                    productSearchInput &&
                    !productSuggestions.contains(event.target) &&
                    event.target !== productSearchInput
                ) {
                    hideProductSuggestions();
                }
            });

            productFilterApplyButton?.addEventListener('click', (event) => {
                event.preventDefault();
                applyProductFilters({ focusSelect: true });
            });

            productSearchInput?.addEventListener('keydown', (event) => {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    if (productSuggestions && productSuggestions.classList.contains('is-visible')) {
                        const firstSuggestion = productSuggestions.querySelector('button[data-product-id]');
                        if (firstSuggestion) {
                            firstSuggestion.click();
                            return;
                        }
                    }
                    applyProductFilters({ focusSelect: true });
                }
            });

            productFilterResetButton?.addEventListener('click', (event) => {
                event.preventDefault();
                resetProductFilters();
                productSelect?.focus();
            });

            if (statusPanel && statusButton && !statusPanel.classList.contains('hidden')) {
                statusButton.classList.add('active');
            }

            if (statusPanel) {
                const tabButtons = statusPanel.querySelectorAll('.tab-btn[data-target]');
                const tabPanels = statusPanel.querySelectorAll('.tab-panel');

                tabButtons.forEach((button) => {
                    button.addEventListener('click', () => {
                        const targetId = button.getAttribute('data-target');
                        if (!targetId) {
                            return;
                        }

                        tabButtons.forEach((btn) => btn.classList.toggle('active', btn === button));
                        tabPanels.forEach((panel) => {
                            panel.classList.toggle('active', panel.id === targetId);
                        });
                    });
                });
            }

            const inventoryFilterForm = document.getElementById('inventoryFilterForm');
            if (inventoryFilterForm) {
                const pageField = inventoryFilterForm.querySelector('input[name="page"]');
                const filterSelects = inventoryFilterForm.querySelectorAll('select');
                const searchInput = inventoryFilterForm.querySelector('input[name="search"]');
                const clearButton = inventoryFilterForm.querySelector('[data-filter-clear]');

                const resetPage = () => {
                    if (pageField) {
                        pageField.value = '1';
                    }
                };

                inventoryFilterForm.addEventListener('submit', () => {
                    resetPage();
                });

                filterSelects.forEach((select) => {
                    select.addEventListener('change', resetPage);
                });

                const updateClearVisibility = () => {
                    if (!searchInput || !clearButton) {
                        return;
                    }
                    if (searchInput.value.trim() !== '') {
                        clearButton.classList.add('is-visible');
                    } else {
                        clearButton.classList.remove('is-visible');
                    }
                };

                if (searchInput) {
                    searchInput.addEventListener('input', () => {
                        resetPage();
                        updateClearVisibility();
                    });
                    updateClearVisibility();
                }

                clearButton?.addEventListener('click', () => {
                    if (!searchInput) {
                        return;
                    }

                    searchInput.value = '';
                    updateClearVisibility();
                    resetPage();
                    if (typeof inventoryFilterForm.requestSubmit === 'function') {
                        inventoryFilterForm.requestSubmit();
                    } else {
                        inventoryFilterForm.submit();
                    }
                    searchInput.focus();
                });
            }

            // Preserve manual adjustment scroll position so the table doesn't jump after submit.
            let sessionStore = null;
            try {
                sessionStore = window.sessionStorage;
                sessionStore.setItem('__inventory_scroll_test__', '1');
                sessionStore.removeItem('__inventory_scroll_test__');
            } catch (error) {
                sessionStore = null;
            }

            const manualAdjustForms = document.querySelectorAll('.manual-adjust-form');
            const scrollStorageKey = 'inventory_manual_adjust_scroll';

            manualAdjustForms.forEach((form) => {
                form.addEventListener('submit', () => {
                    if (!sessionStore) {
                        return;
                    }
                    const currentScroll = typeof window.scrollY === 'number' ? window.scrollY : window.pageYOffset;
                    sessionStore.setItem(scrollStorageKey, String(currentScroll));
                });
            });

            const variantsBootstrapScript = document.getElementById('inventoryVariants');
            let variantInventoryLookup = {};
            if (variantsBootstrapScript) {
                try {
                    const bootstrapText = variantsBootstrapScript.textContent || variantsBootstrapScript.innerText || '';
                    if (bootstrapText.trim() !== '') {
                        variantInventoryLookup = JSON.parse(bootstrapText);
                    }
                } catch (error) {
                    variantInventoryLookup = {};
                }
            }

            const manualVariantSelects = document.querySelectorAll('.manual-adjust-select');

            const deriveVariantLabel = (variantEntry) => {
                if (!variantEntry || typeof variantEntry !== 'object') {
                    return '';
                }
                const label = typeof variantEntry.label === 'string' ? variantEntry.label.trim() : '';
                const sku = typeof variantEntry.sku === 'string' ? variantEntry.sku.trim() : '';
                if (label !== '') {
                    return label;
                }
                if (sku !== '') {
                    return sku;
                }
                if (typeof variantEntry.id === 'number' && Number.isFinite(variantEntry.id)) {
                    return `Variant #${variantEntry.id}`;
                }
                return '';
            };

            const parseQuantity = (rawValue) => {
                if (typeof rawValue === 'number' && Number.isFinite(rawValue)) {
                    return rawValue;
                }
                if (typeof rawValue === 'string' && rawValue.trim() !== '') {
                    const parsed = parseInt(rawValue, 10);
                    if (!Number.isNaN(parsed)) {
                        return parsed;
                    }
                }
                return null;
            };

            const updateQuantityDisplay = (row, variantId, optionElement) => {
                if (!row) {
                    return;
                }
                const quantityDisplay = row.querySelector('[data-quantity-display]');
                if (!quantityDisplay) {
                    return;
                }
                const contextDisplay = row.querySelector('[data-quantity-context]');
                const defaultQuantityRaw = quantityDisplay.dataset.defaultQuantity || '0';
                const defaultQuantity = parseQuantity(defaultQuantityRaw) ?? 0;

                let nextQuantity = defaultQuantity;
                let nextContext = contextDisplay ? 'All variants total' : '';

                const productId = row.dataset.productId || '';
                const resolvedVariantId = parseQuantity(variantId);

                if (resolvedVariantId && resolvedVariantId > 0) {
                    let variantQuantity = null;
                    if (optionElement) {
                        variantQuantity = parseQuantity(optionElement.dataset.variantQuantity || '');
                    }
                    const productVariants = Array.isArray(variantInventoryLookup[productId])
                        ? variantInventoryLookup[productId]
                        : [];
                    let variantEntry = null;
                    if (productVariants.length > 0) {
                        variantEntry = productVariants.find((entry) => {
                            if (!entry) {
                                return false;
                            }
                            if (typeof entry.id === 'number' || typeof entry.id === 'string') {
                                return String(entry.id) === String(resolvedVariantId);
                            }
                            return false;
                        }) || null;
                    }

                    if (variantQuantity === null && variantEntry) {
                        variantQuantity = parseQuantity(variantEntry.quantity);
                    }

                    if (variantQuantity === null) {
                        variantQuantity = defaultQuantity;
                    }

                    nextQuantity = Math.max(0, variantQuantity);

                    if (contextDisplay) {
                        const variantLabel = variantEntry ? deriveVariantLabel(variantEntry) : '';
                        nextContext = variantLabel ? `Variant qty — ${variantLabel}` : 'Variant qty';
                        contextDisplay.dataset.contextState = 'variant';
                    }
                } else if (contextDisplay) {
                    contextDisplay.dataset.contextState = 'product';
                }

                quantityDisplay.textContent = String(nextQuantity);
                if (contextDisplay) {
                    contextDisplay.textContent = nextContext;
                    contextDisplay.setAttribute('title', nextContext);
                }

                const thresholdRaw = row.dataset.lowThreshold || '0';
                const lowThreshold = parseQuantity(thresholdRaw) ?? 0;
                const defaultLowState = row.dataset.lowDefault === '1';
                let isLow = defaultLowState;

                if (resolvedVariantId && resolvedVariantId > 0) {
                    isLow = defaultLowState || nextQuantity <= lowThreshold;
                }

                row.classList.toggle('low-stock', Boolean(isLow));
            };

            manualVariantSelects.forEach((select) => {
                const row = select.closest('tr[data-product-id]');
                if (!row) {
                    return;
                }

                const applySelection = () => {
                    const selectedOption = select.options[select.selectedIndex] || null;
                    updateQuantityDisplay(row, select.value || '0', selectedOption);
                };

                select.addEventListener('change', applySelection);

                const lastVariantId = row.dataset.lastVariantId || '';
                if (lastVariantId && lastVariantId !== '0') {
                    applySelection();
                } else {
                    updateQuantityDisplay(row, '0', select.options[select.selectedIndex] || null);
                }
            });

            let restoredScroll = false;
            if (sessionStore) {
                const storedScroll = sessionStore.getItem(scrollStorageKey);
                if (storedScroll !== null) {
                    sessionStore.removeItem(scrollStorageKey);
                    const parsedScroll = parseFloat(storedScroll);
                    if (!Number.isNaN(parsedScroll)) {
                        restoredScroll = true;
                        window.requestAnimationFrame(() => {
                            window.scrollTo({ top: parsedScroll, behavior: 'auto', left: 0 });
                        });
                    }
                }
            }

            // New: surface manual adjustment feedback while leaving the scroll position untouched
            const flashAlert = document.querySelector('[data-inventory-flash]');
            if (flashAlert) {
                window.setTimeout(() => {
                    flashAlert.classList.add('is-fading');
                }, 3600);
                window.setTimeout(() => {
                    flashAlert.remove();
                }, 4600);
            }

            const flashRow = document.querySelector('tr[data-flash-product="true"]');
            if (flashRow) {
                if (!restoredScroll) {
                    // Ensure the highlighted row remains within view without forcing a jump when we already restored the scroll.
                    const rowBounds = flashRow.getBoundingClientRect();
                    const viewportHeight = window.innerHeight || document.documentElement.clientHeight;
                    if (rowBounds.top < 0 || rowBounds.bottom > viewportHeight) {
                        flashRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                }
                window.setTimeout(() => {
                    flashRow.classList.remove('manual-adjust-highlight');
                }, 4000);
            }
            // file 2 end
        });
