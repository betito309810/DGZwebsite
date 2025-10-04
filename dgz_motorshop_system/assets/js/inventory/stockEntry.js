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

    if (!form || !lineItemsBody) {
        return;
    }

    const allProducts = Array.isArray(bootstrapData.products) ? bootstrapData.products : [];
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

    // Bind base row listeners immediately.
    bindRow(lineTemplates.row);
    updateRemoveButtons();
    updateDiscrepancyState();

    addLineItemBtn?.addEventListener('click', () => {
        const newRow = cloneRow();
        lineItemsBody.appendChild(newRow);
        bindRow(newRow);
        updateRemoveButtons();
    });

    saveDraftBtn?.addEventListener('click', () => {
        if (!form) return;
        formActionField.value = 'save_draft';
        if (form.reportValidity()) {
            form.submit();
        }
    });

    postReceiptBtn?.addEventListener('click', () => {
        if (!form) return;
        formActionField.value = 'post_receipt';
        if (form.reportValidity()) {
            form.submit();
        }
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
    });

    function cloneRow() {
        const clone = lineTemplates.row.cloneNode(true);
        clone.classList.remove('has-discrepancy');
        clone.dataset.selectedProduct = '';
        clone.querySelectorAll('input').forEach((input) => {
            input.value = '';
        });
        const select = clone.querySelector('select[name="product_id[]"]');
        if (select) {
            select.value = '';
        }
        const suggestions = clone.querySelector('.product-suggestions');
        if (suggestions) {
            suggestions.innerHTML = '';
        }
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

        expectedInput?.addEventListener('input', () => {
            evaluateRowDiscrepancy(row);
        });
        receivedInput?.addEventListener('input', () => {
            evaluateRowDiscrepancy(row);
        });
        removeBtn?.addEventListener('click', () => {
            if (lineItemsBody.children.length <= 1) {
                return;
            }
            row.remove();
            updateRemoveButtons();
            updateDiscrepancyState();
        });

        if (productSearch && suggestions && productSelect) {
            if (!productSearch.dataset.defaultPlaceholder) {
                productSearch.dataset.defaultPlaceholder = productSearch.placeholder;
            }
            productSearch.addEventListener('input', () => {
                renderProductSuggestions(row, productSearch.value);
            });
            productSearch.addEventListener('focus', () => {
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

            const presetId = row.dataset.selectedProduct;
            if (presetId) {
                applyProductSelection(row, presetId, { skipFocus: true, renderSuggestions: false, prefillSearch: false });
            } else if (productSelect.value) {
                applyProductSelection(row, productSelect.value, { skipFocus: true, renderSuggestions: false, prefillSearch: false });
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
        const hasAny = !!lineItemsBody.querySelector('.has-discrepancy');
        if (hasAny) {
            discrepancyNoteGroup.hidden = false;
            discrepancyNoteField?.setAttribute('required', 'required');
        } else {
            discrepancyNoteGroup.hidden = true;
            discrepancyNoteField?.removeAttribute('required');
            if (discrepancyNoteField) {
                discrepancyNoteField.value = '';
            }
        }
    }

    function updateRemoveButtons() {
        const rows = Array.from(lineItemsBody.querySelectorAll('.line-item-row'));
        rows.forEach((row, index) => {
            const removeBtn = row.querySelector('.remove-line-item');
            if (!removeBtn) {
                return;
            }
            removeBtn.disabled = rows.length === 1;
            if (index > 0) {
                removeBtn.disabled = false;
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
            }
            return;
        }

        results.forEach((entry) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'product-suggestion-item';
            button.dataset.productId = entry.id;
            button.textContent = entry.label;
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
            row.dataset.selectedProduct = '';
            if (suggestions && !options.keepSuggestions) {
                suggestions.innerHTML = '';
            }
            return;
        }

        const prefillSearch = options.prefillSearch !== false;
        const label = buildProductLabel(product);

        select.value = String(product.id);
        if (prefillSearch) {
            searchInput.value = label;
            searchInput.placeholder = searchInput.dataset.defaultPlaceholder || searchInput.placeholder;
        } else {
            searchInput.value = '';
            if (label) {
                searchInput.placeholder = label;
            }
        }
        row.dataset.selectedProduct = String(product.id);
        if (suggestions && !options.keepSuggestions) {
            suggestions.innerHTML = '';
        }
        if (!options.skipFocus) {
            searchInput.blur();
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

    document.addEventListener('click', (event) => {
        if (!event.target.closest('.product-selector')) {
            lineItemsBody.querySelectorAll('.product-suggestions').forEach((node) => {
                node.innerHTML = '';
            });
        }
    });
});
