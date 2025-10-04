// file 2 start – inventory page helpers (safe to extract)
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
            form?.classList.toggle('hidden');
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

        function toggleRecentEntries() {
            const content = document.getElementById('recentEntriesContent');
            const icon = document.getElementById('toggleIcon');
            if (!content || !icon) {
                return;
            }

            const isHidden = content.classList.toggle('hidden');
            icon.className = isHidden ? 'fas fa-chevron-down' : 'fas fa-chevron-up';
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

            const restockFormEl = document.querySelector('.restock-form');
            const productSelect = document.getElementById('restock_product');
            const categorySelect = document.getElementById('restock_category');
            const categoryNewInput = document.getElementById('restock_category_new');
            const brandSelect = document.getElementById('restock_brand');
            const brandNewInput = document.getElementById('restock_brand_new');
            const supplierSelect = document.getElementById('restock_supplier');
            const supplierNewInput = document.getElementById('restock_supplier_new');
            const statusPanel = document.getElementById('restockStatusPanel');
            const statusButton = document.getElementById('restockStatusButton');
            const quantityInput = document.getElementById('restock_quantity');
            const prioritySelect = document.getElementById('restock_priority');
            const neededByInput = document.getElementById('restock_needed_by');
            const notesTextarea = document.getElementById('restock_notes');

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

            const selectMappings = [
                { select: categorySelect, input: categoryNewInput },
                { select: brandSelect, input: brandNewInput },
                { select: supplierSelect, input: supplierNewInput },
            ];

            selectMappings.forEach(({ select, input }) => {
                if (select && input) {
                    select.addEventListener('change', () => handleSelectChange(select, input));
                    handleSelectChange(select, input);
                }
            });

            function updateProductMeta() {
                if (!productSelect) {
                    return;
                }

                const selectedOption = productSelect.options[productSelect.selectedIndex];
                if (!selectedOption) {
                    selectMappings.forEach(({ select, input }) => {
                        setSelectOrInput(select, input, '');
                        handleSelectChange(select, input);
                    });
                    return;
                }

                setSelectOrInput(categorySelect, categoryNewInput, selectedOption.getAttribute('data-category') || '');
                setSelectOrInput(brandSelect, brandNewInput, selectedOption.getAttribute('data-brand') || '');
                setSelectOrInput(supplierSelect, supplierNewInput, selectedOption.getAttribute('data-supplier') || '');
                handleSelectChange(categorySelect, categoryNewInput);
                handleSelectChange(brandSelect, brandNewInput);
                handleSelectChange(supplierSelect, supplierNewInput);
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
                    data.initialNeededBy || data.initialNotes
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
                    if (prioritySelect) {
                        prioritySelect.value = data.initialPriority || '';
                    }
                    if (neededByInput) {
                        neededByInput.value = data.initialNeededBy || '';
                    }
                    if (notesTextarea) {
                        notesTextarea.value = data.initialNotes || '';
                    }
                }
            }

            if (productSelect) {
                productSelect.addEventListener('change', () => {
                    updateProductMeta();
                });
                if (!hasInitialFormData) {
                    updateProductMeta();
                }
            }

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
            // file 2 end
        });
