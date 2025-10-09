// Handles the interactive Stock-In creation form: line items, product lookup, discrepancy prompts, and submission flow.
document.addEventListener('DOMContentLoaded', () => {
    const bootstrapNode = document.getElementById('stockReceiptBootstrap');
    const bootstrapData = bootstrapNode ? JSON.parse(bootstrapNode.textContent || '{}') : {};

    const form = document.getElementById('stockInForm');
    const formActionField = document.getElementById('formAction');
    const addLineItemBtn = document.getElementById('addLineItemBtn');
    const saveDraftBtn = document.getElementById('saveDraftBtn');
    const postReceiptBtn = document.getElementById('postReceiptBtn');
    const lineItemsBody = document.getElementById('lineItemsBody');
    const discrepancyNoteGroup = document.getElementById('discrepancyNoteGroup');
    const discrepancyNoteField = document.getElementById('discrepancy_note');
    const attachmentInput = document.getElementById('attachments');
    const attachmentList = document.getElementById('attachmentList');
    const discrepancyRequiredIndicator = document.querySelector('[data-discrepancy-required]');

    const panelToggleButtons = document.querySelectorAll('[data-toggle-target]');
    const panelTransitionHandlers = new WeakMap();

    const setPanelVisibilityState = (element, state) => {
        element.dataset.panelVisibility = state;
    };

    const isPanelHidden = (element) => {
        const state = element.dataset.panelVisibility;
        if (state === 'hiding') {
            return true;
        }
        if (state === 'visible' || state === 'showing') {
            return false;
        }
        return element.classList.contains('hidden');
    };

    const clearPanelAnimation = (element) => {
        const activeHandler = panelTransitionHandlers.get(element);
        if (activeHandler) {
            element.removeEventListener('transitionend', activeHandler);
            panelTransitionHandlers.delete(element);
        }
        element.style.removeProperty('height');
        element.style.removeProperty('overflow');
        element.style.removeProperty('transition');
        element.style.removeProperty('opacity');
    };

    const registerPanelTransition = (element, callback) => {
        const handler = (event) => {
            if (event.target !== element || event.propertyName !== 'height') {
                return;
            }
            element.removeEventListener('transitionend', handler);
            panelTransitionHandlers.delete(element);
            callback();
        };

        const existingHandler = panelTransitionHandlers.get(element);
        if (existingHandler) {
            element.removeEventListener('transitionend', existingHandler);
        }

        panelTransitionHandlers.set(element, handler);
        element.addEventListener('transitionend', handler);
    };

    const hidePanel = (element, { immediate = false } = {}) => {
        if (element.classList.contains('hidden')) {
            clearPanelAnimation(element);
            setPanelVisibilityState(element, 'hidden');
            return;
        }

        clearPanelAnimation(element);

        if (immediate) {
            element.classList.add('hidden');
            setPanelVisibilityState(element, 'hidden');
            return;
        }

        const startHeight = element.scrollHeight;
        if (startHeight === 0) {
            element.classList.add('hidden');
            setPanelVisibilityState(element, 'hidden');
            return;
        }

        setPanelVisibilityState(element, 'hiding');
        element.style.height = `${startHeight}px`;
        element.style.opacity = '1';
        element.style.overflow = 'hidden';
        element.style.transition = 'height 0.3s ease, opacity 0.2s ease';

        requestAnimationFrame(() => {
            element.style.height = '0px';
            element.style.opacity = '0';
        });

        registerPanelTransition(element, () => {
            element.classList.add('hidden');
            setPanelVisibilityState(element, 'hidden');
            clearPanelAnimation(element);
        });
    };

    const showPanel = (element, { immediate = false } = {}) => {
        clearPanelAnimation(element);
        element.classList.remove('hidden');
        setPanelVisibilityState(element, 'showing');

        if (immediate) {
            setPanelVisibilityState(element, 'visible');
            return;
        }

        const targetHeight = element.scrollHeight;
        if (targetHeight === 0) {
            clearPanelAnimation(element);
            setPanelVisibilityState(element, 'visible');
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

        registerPanelTransition(element, () => {
            setPanelVisibilityState(element, 'visible');
            clearPanelAnimation(element);
        });
    };

    panelToggleButtons.forEach((button) => {
        const targetId = button.getAttribute('data-toggle-target');
        if (!targetId) {
            return;
        }
        const target = document.getElementById(targetId);
        if (!target) {
            return;
        }

        const collapsedText = button.getAttribute('data-collapsed-text') || 'Show';
        const expandedText = button.getAttribute('data-expanded-text') || 'Hide';
        const label = button.querySelector('.panel-toggle__label');
        const icon = button.querySelector('.panel-toggle__icon');
        const startCollapsed = button.getAttribute('data-start-collapsed') === 'true';

        if (startCollapsed) {
            hidePanel(target, { immediate: true });
        } else {
            showPanel(target, { immediate: true });
        }

        const syncState = (isHidden) => {
            button.setAttribute('aria-expanded', (!isHidden).toString());
            if (label) {
                label.textContent = isHidden ? collapsedText : expandedText;
            }
            if (icon) {
                icon.className = `${isHidden ? 'fas fa-chevron-down' : 'fas fa-chevron-up'} panel-toggle__icon`;
            }
            target.setAttribute('aria-hidden', isHidden.toString());
        };

        syncState(isPanelHidden(target));

        let scrollTimer;

        button.addEventListener('click', () => {
            const isCurrentlyHidden = isPanelHidden(target);
            if (isCurrentlyHidden) {
                showPanel(target);
                syncState(false);
                window.clearTimeout(scrollTimer);
                scrollTimer = window.setTimeout(() => {
                    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }, 320);
            } else {
                hidePanel(target);
                syncState(true);
            }
        });
    });

    if (!form || !lineItemsBody) {
        return;
    }

    const allProducts = Array.isArray(bootstrapData.products) ? bootstrapData.products : [];
    const formLocked = Boolean(bootstrapData.formLocked);
    // Added guard to alert about unsaved Stock-In form changes before navigating away.
    const unsavedWarningMessage = 'You have unsaved stock-in form. '
        + 'Save the form as a draft or post it before leaving. Or do you want to discard it?';
    let isFormDirty = false;
    let isSubmitting = false;

    const productMap = new Map();
    const productSearchIndex = allProducts.map((product) => {
        productMap.set(String(product.id), product);
        const tokens = [product.name, product.code, product.brand, product.category]
            .filter(Boolean)
            .join(' ')
            .toLowerCase();
        return {
            id: product.id,
            label: buildProductLabel(product),
            tokens,
        };
    });

    const lineTemplates = {
        row: lineItemsBody.querySelector('.line-item-row'),
    };

    if (!lineTemplates.row) {
        return;
    }

    const handleBeforeUnload = (event) => {
        if (!isFormDirty || isSubmitting) {
            return;
        }
        event.preventDefault();
        event.returnValue = unsavedWarningMessage;
        return unsavedWarningMessage;
    };

    const markDirty = () => {
        if (formLocked || isSubmitting) {
            return;
        }
        if (!isFormDirty) {
            isFormDirty = true;
            form.setAttribute('data-dirty', 'true');
        }
    };

    const resetDirty = () => {
        if (!isFormDirty) {
            return;
        }
        isFormDirty = false;
        form.removeAttribute('data-dirty');
    };

    const navigateAway = (href) => {
        isSubmitting = true;
        resetDirty();
        window.removeEventListener('beforeunload', handleBeforeUnload);
        window.location.href = href;
    };

    if (!formLocked) {
        window.addEventListener('beforeunload', handleBeforeUnload);
        form.addEventListener('input', () => {
            markDirty();
        }, true);
        form.addEventListener('change', () => {
            markDirty();
        }, true);
        form.addEventListener('submit', () => {
            isSubmitting = true;
            resetDirty();
            window.removeEventListener('beforeunload', handleBeforeUnload);
        });
    }

    const triggerSubmit = (action) => {
        if (!form || formLocked) {
            return;
        }
        formActionField.value = action;
        if (form.reportValidity()) {
            isSubmitting = true;
            resetDirty();
            window.removeEventListener('beforeunload', handleBeforeUnload);
            form.submit();
        }
    };

    // Bind base row listeners immediately.
    bindRow(lineTemplates.row);
    updateRemoveButtons();
    updateDiscrepancyState();

    addLineItemBtn?.addEventListener('click', () => {
        const newRow = cloneRow();
        lineItemsBody.appendChild(newRow);
        bindRow(newRow);
        updateRemoveButtons();
        updateDiscrepancyState();
        markDirty();
    });

    saveDraftBtn?.addEventListener('click', () => {
        triggerSubmit('save_draft');
    });

    postReceiptBtn?.addEventListener('click', () => {
        triggerSubmit('post_receipt');
    });

    attachmentInput?.addEventListener('change', () => {
        attachmentList.innerHTML = '';
        const files = Array.from(attachmentInput.files || []);
        files.forEach((file) => {
            const item = document.createElement('li');
            item.className = 'attachment-list-item';
            item.innerHTML = `<i class="fas fa-paperclip"></i> <span>${file.name}</span>`;
            attachmentList.appendChild(item);
        });
        markDirty();
    });

    function cloneRow() {
        const clone = lineTemplates.row.cloneNode(true);
        clone.classList.remove('has-discrepancy');
        clone.classList.remove('suggestions-open');
        clone.dataset.selectedProduct = '';
        clone.dataset.selectedLabel = '';
        clone.querySelectorAll('input').forEach((input) => {
            if (input.classList.contains('product-search')) {
                return;
            }
            input.value = '';
        });
        const select = clone.querySelector('select[name="product_id[]"]');
        if (select) {
            select.value = '';
        }
        clearProductSelection(clone, { suppressDirty: true });
        const removeBtn = clone.querySelector('.remove-line-item');
        if (removeBtn) {
            removeBtn.disabled = false;
        }
        return clone;
    }

    function bindRow(row) {
        const expectedInput = row.querySelector('input[name="qty_expected[]"]');
        const receivedInput = row.querySelector('input[name="qty_received[]"]');
        const removeBtn = row.querySelector('.remove-line-item');
        const productSelect = row.querySelector('select[name="product_id[]"]');
        const productSearch = row.querySelector('.product-search');
        const suggestions = row.querySelector('.product-suggestions');
        const clearBtn = row.querySelector('.product-clear');

        expectedInput?.addEventListener('input', () => {
            evaluateRowDiscrepancy(row);
            markDirty();
        });
        receivedInput?.addEventListener('input', () => {
            evaluateRowDiscrepancy(row);
            markDirty();
        });
        removeBtn?.addEventListener('click', () => {
            if (lineItemsBody.children.length <= 1) {
                return;
            }
            row.remove();
            updateRemoveButtons();
            updateDiscrepancyState();
            markDirty();
        });

        if (productSearch && suggestions && productSelect) {
            if (!productSearch.dataset.defaultPlaceholder) {
                productSearch.dataset.defaultPlaceholder = productSearch.placeholder;
            }
            productSearch.addEventListener('input', () => {
                const currentValue = productSearch.value;
                if (row.dataset.selectedProduct && currentValue !== (row.dataset.selectedLabel || '')) {
                    clearProductSelection(row, { keepInputValue: true, keepSuggestions: true });
                }
                renderProductSuggestions(row, currentValue);
                markDirty();
            });
            productSearch.addEventListener('focus', () => {
                if (row.dataset.selectedProduct) {
                    productSearch.select();
                }
                renderProductSuggestions(row, productSearch.value, { showDefault: true });
            });
            productSearch.addEventListener('keydown', (event) => {
                if (event.key === 'Enter') {
                    const firstSuggestion = suggestions.querySelector('button');
                    if (firstSuggestion) {
                        event.preventDefault();
                        applyProductSelection(row, firstSuggestion.dataset.productId);
                    }
                }
            });

            productSelect.addEventListener('change', () => {
                const productId = productSelect.value;
                applyProductSelection(row, productId, { skipFocus: true });
            });

            clearBtn?.addEventListener('click', () => {
                clearProductSelection(row, { focus: true });
            });

            const presetId = row.dataset.selectedProduct;
            if (presetId) {
                applyProductSelection(row, presetId, { skipFocus: true, renderSuggestions: false, skipDirty: true });
            } else if (productSelect.value) {
                applyProductSelection(row, productSelect.value, { skipFocus: true, renderSuggestions: false, skipDirty: true });
            }
        }
    }

    function evaluateRowDiscrepancy(row) {
        const expectedValue = row.querySelector('input[name="qty_expected[]"]')?.value;
        const receivedValue = row.querySelector('input[name="qty_received[]"]')?.value;
        const expected = expectedValue === '' ? null : Number(expectedValue);
        const received = receivedValue === '' ? null : Number(receivedValue);

        const hasDiscrepancy = expected !== null && received !== null && expected !== received;
        row.classList.toggle('has-discrepancy', hasDiscrepancy);
        updateDiscrepancyState();
    }

    function updateDiscrepancyState() {
        if (!discrepancyNoteGroup) {
            return;
        }
        const hasAny = !!lineItemsBody.querySelector('.has-discrepancy');
        const noteValue = (discrepancyNoteField?.value || '').trim();
        const hasNote = noteValue !== '';
        const shouldShow = hasAny || hasNote;

        discrepancyNoteGroup.hidden = !shouldShow;

        if (hasAny) {
            discrepancyNoteField?.setAttribute('required', 'required');
            discrepancyRequiredIndicator?.removeAttribute('hidden');
        } else {
            discrepancyNoteField?.removeAttribute('required');
            discrepancyRequiredIndicator?.setAttribute('hidden', 'hidden');
        }

        if (!hasAny && !hasNote && discrepancyNoteField) {
            discrepancyNoteField.value = '';
        }

        if (discrepancyNoteGroup) {
            discrepancyNoteGroup.dataset.hasInitial = hasNote ? '1' : '0';
        }
    }

    function updateRemoveButtons() {
        const rows = Array.from(lineItemsBody.querySelectorAll('.line-item-row'));
        rows.forEach((row, index) => {
            const removeBtn = row.querySelector('.remove-line-item');
            if (removeBtn) {
                removeBtn.disabled = rows.length === 1;
                if (index > 0) {
                    removeBtn.disabled = false;
                }
            }
        });
        refreshLineItemLabels(rows);
    }

    function refreshLineItemLabels(rows = null) {
        const lineRows = rows || Array.from(lineItemsBody.querySelectorAll('.line-item-row'));
        lineRows.forEach((row, index) => {
            const title = row.querySelector('.line-item-title');
            if (title) {
                title.textContent = `Item ${index + 1}`;
            }
        });
    }

    function renderProductSuggestions(row, term, options = {}) {
        const suggestions = row.querySelector('.product-suggestions');
        if (!suggestions) {
            return;
        }

        const showDefault = options.showDefault ?? false;
        const query = term.trim().toLowerCase();
        suggestions.innerHTML = '';
        row.classList.remove('suggestions-open');

        let results;
        if (!query) {
            results = showDefault ? productSearchIndex.slice(0, 15) : [];
        } else {
            results = productSearchIndex
                .filter((entry) => entry.tokens.includes(query) || entry.label.toLowerCase().includes(query))
                .slice(0, 15);
        }

        if (!results.length) {
            if (query) {
                const emptyState = document.createElement('div');
                emptyState.className = 'product-suggestion-empty';
                emptyState.textContent = 'No matches found';
                suggestions.appendChild(emptyState);
                row.classList.add('suggestions-open');
                suggestions.scrollTop = 0;
            }
            return;
        }

        row.classList.add('suggestions-open');
        suggestions.scrollTop = 0;

        results.forEach((entry) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'product-suggestion-item';
            button.dataset.productId = entry.id;

            const product = productMap.get(String(entry.id));
            const name = product?.name || entry.label;
            const metaParts = [];
            if (product?.code) {
                metaParts.push(`#${product.code}`);
            }
            if (product?.brand) {
                metaParts.push(product.brand);
            }
            if (product?.category) {
                metaParts.push(product.category);
            }
            const meta = metaParts.join(' • ');
            const title = product ? buildProductLabel(product) : entry.label;
            button.title = title;
            if (meta) {
                button.innerHTML = [
                    `<span class="product-suggestion-name">${escapeHtml(name)}</span>`,
                    `<span class="product-suggestion-meta">${escapeHtml(meta)}</span>`,
                ].join('');
            } else {
                button.innerHTML = `<span class="product-suggestion-name">${escapeHtml(name)}</span>`;
            }
            button.addEventListener('click', () => {
                applyProductSelection(row, entry.id);
            });
            suggestions.appendChild(button);
        });
    }

    function applyProductSelection(row, productId, options = {}) {
        const select = row.querySelector('select[name="product_id[]"]');
        const searchInput = row.querySelector('.product-search');
        const suggestions = row.querySelector('.product-suggestions');
        const clearBtn = row.querySelector('.product-clear');
        if (!select || !searchInput) {
            return;
        }

        const product = productMap.get(String(productId));
        if (!product) {
            select.value = '';
            searchInput.value = '';
            if (searchInput.dataset.defaultPlaceholder) {
                searchInput.placeholder = searchInput.dataset.defaultPlaceholder;
            }
            const hadSelection = !!row.dataset.selectedProduct;
            row.dataset.selectedProduct = '';
            row.dataset.selectedLabel = '';
            if (suggestions && !options.keepSuggestions) {
                suggestions.innerHTML = '';
            }
            row.classList.remove('suggestions-open');
            clearBtn?.setAttribute('hidden', 'hidden');
            if (!options.suppressDirty && hadSelection) {
                markDirty();
            }
            return;
        }

        const label = buildProductLabel(product);

        select.value = String(product.id);
        const previousSelection = row.dataset.selectedProduct;
        row.dataset.selectedProduct = String(product.id);
        row.dataset.selectedLabel = label;
        searchInput.value = label;
        if (searchInput.dataset.defaultPlaceholder) {
            searchInput.placeholder = searchInput.dataset.defaultPlaceholder;
        }
        if (suggestions && !options.keepSuggestions) {
            suggestions.innerHTML = '';
        }
        row.classList.remove('suggestions-open');
        clearBtn?.removeAttribute('hidden');
        if (!options.skipFocus) {
            searchInput.blur();
        }
        if (!options.skipDirty && previousSelection !== String(product.id)) {
            markDirty();
        }
    }

    function clearProductSelection(row, options = {}) {
        const select = row.querySelector('select[name="product_id[]"]');
        const searchInput = row.querySelector('.product-search');
        const suggestions = row.querySelector('.product-suggestions');
        const clearBtn = row.querySelector('.product-clear');
        const hadSelection = !!row.dataset.selectedProduct;

        if (select) {
            select.value = '';
        }
        row.dataset.selectedProduct = '';
        row.dataset.selectedLabel = '';

        if (searchInput && !options.keepInputValue) {
            searchInput.value = '';
        }
        if (searchInput && searchInput.dataset.defaultPlaceholder && !options.keepPlaceholder) {
            searchInput.placeholder = searchInput.dataset.defaultPlaceholder;
        }

        if (!options.keepSuggestions && suggestions) {
            suggestions.innerHTML = '';
        }
        if (!options.keepSuggestions) {
            row.classList.remove('suggestions-open');
        }

        if (clearBtn && !options.keepClearButton) {
            clearBtn.setAttribute('hidden', 'hidden');
        }

        if (options.focus && searchInput) {
            searchInput.focus();
        }

        if (!options.suppressDirty && (hadSelection || !options.keepInputValue)) {
            markDirty();
        }
    }

    function buildProductLabel(product) {
        const parts = [product.name];
        if (product.code) {
            parts.push(`#${product.code}`);
        }
        if (product.brand) {
            parts.push(`(${product.brand})`);
        }
        return parts.filter(Boolean).join(' ');
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    // Intercept navigation clicks so unsaved Stock-In forms prompt before leaving the page.
    document.addEventListener('click', (event) => {
        if (!event.target.closest('.product-selector')) {
            lineItemsBody.querySelectorAll('.product-suggestions').forEach((node) => {
                node.innerHTML = '';
            });
            lineItemsBody.querySelectorAll('.line-item-row.suggestions-open').forEach((row) => {
                row.classList.remove('suggestions-open');
            });
        }

        if (formLocked || !isFormDirty || isSubmitting) {
            return;
        }

        const link = event.target.closest('a[href]');
        if (!link) {
            return;
        }

        if (link.dataset.allowUnsaved === 'true') {
            return;
        }

        if (link.target && link.target !== '_self') {
            return;
        }

        const href = link.getAttribute('href');
        if (!href || href.startsWith('#') || href.startsWith('javascript:')) {
            return;
        }

        event.preventDefault();
        const proceed = window.confirm(unsavedWarningMessage);
        if (proceed) {
            navigateAway(link.href);
        }
    });

    discrepancyNoteField?.addEventListener('input', () => {
        updateDiscrepancyState();
        markDirty();
    });

    const inventoryFilterForm = document.getElementById('inventoryFilterForm');
    if (inventoryFilterForm) {
        const searchInput = inventoryFilterForm.querySelector('.filter-search-input');
        const clearButton = inventoryFilterForm.querySelector('[data-filter-clear]');
        const pageField = inventoryFilterForm.querySelector('input[name="inv_page"]');

        inventoryFilterForm.addEventListener('submit', () => {
            if (pageField) {
                pageField.value = '1';
            }
        });

        if (clearButton && searchInput) {
            clearButton.addEventListener('click', () => {
                searchInput.value = '';
                if (pageField) {
                    pageField.value = '1';
                }
                inventoryFilterForm.submit();
            });
        }
    }
});
