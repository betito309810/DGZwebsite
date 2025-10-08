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
        updateDiscrepancyState();
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
        clearProductSelection(clone);
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
                const currentValue = productSearch.value;
                if (row.dataset.selectedProduct && currentValue !== (row.dataset.selectedLabel || '')) {
                    clearProductSelection(row, { keepInputValue: true, keepSuggestions: true });
                }
                renderProductSuggestions(row, currentValue);
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
                applyProductSelection(row, presetId, { skipFocus: true, renderSuggestions: false });
            } else if (productSelect.value) {
                applyProductSelection(row, productSelect.value, { skipFocus: true, renderSuggestions: false });
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
            }
            return;
        }

        row.classList.add('suggestions-open');

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
            row.dataset.selectedProduct = '';
            row.dataset.selectedLabel = '';
            if (suggestions && !options.keepSuggestions) {
                suggestions.innerHTML = '';
            }
            row.classList.remove('suggestions-open');
            clearBtn?.setAttribute('hidden', 'hidden');
            return;
        }

        const label = buildProductLabel(product);

        select.value = String(product.id);
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
    }

    function clearProductSelection(row, options = {}) {
        const select = row.querySelector('select[name="product_id[]"]');
        const searchInput = row.querySelector('.product-search');
        const suggestions = row.querySelector('.product-suggestions');
        const clearBtn = row.querySelector('.product-clear');

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

    document.addEventListener('click', (event) => {
        if (!event.target.closest('.product-selector')) {
            lineItemsBody.querySelectorAll('.product-suggestions').forEach((node) => {
                node.innerHTML = '';
            });
            lineItemsBody.querySelectorAll('.line-item-row.suggestions-open').forEach((row) => {
                row.classList.remove('suggestions-open');
            });
        }
    });

    discrepancyNoteField?.addEventListener('input', () => {
        updateDiscrepancyState();
    });
});
