// Added: client-side controller that powers the variant editor UI on the products page.
(function () {
    const EVENT_HYDRATE = 'variant:hydrate';

    function initialiseVariantEditor(editor) {
        if (!editor) {
            return;
        }

        const context = editor.dataset.context || 'create';
        const rowsContainer = editor.querySelector('[data-variant-rows]');
        const template = editor.querySelector('[data-variant-template]');
        const addButton = editor.querySelector('[data-variant-add]');
        const payloadInput = editor.querySelector('[data-variants-payload]');
        const hostForm = editor.closest('form');
        const quantityInput = hostForm ? hostForm.querySelector('[data-variant-total-quantity]') : null;
        const priceInput = hostForm ? hostForm.querySelector('[data-variant-default-price]') : null;
        const defaultRadioName = context === 'edit' ? 'edit_variant_default' : 'create_variant_default';

        if (!rowsContainer || !template || !payloadInput) {
            return;
        }

        function markEmptyState() {
            if (rowsContainer.children.length === 0) {
                editor.classList.add('variant-editor--empty');
            } else {
                editor.classList.remove('variant-editor--empty');
            }
        }

        function updateAggregates() {
            const rows = Array.from(rowsContainer.querySelectorAll('[data-variant-row]'));
            let totalQty = 0;
            let defaultPrice = 0;
            let defaultFound = false;
            const activeRows = []; // Added: track rows with actual labels so blank placeholders do not affect totals.

            rows.forEach((row) => {
                const labelField = row.querySelector('[data-variant-label]');
                const qtyField = row.querySelector('[data-variant-quantity]');
                const priceField = row.querySelector('[data-variant-price]');
                const defaultRadio = row.querySelector('[data-variant-default]');

                if (defaultRadio) {
                    defaultRadio.name = defaultRadioName;
                }

                const label = labelField ? labelField.value.trim() : '';
                if (label === '') {
                    if (defaultRadio) {
                        defaultRadio.checked = false;
                    }
                    return;
                }

                activeRows.push({ priceField, qtyField, defaultRadio }); // Added: remember usable row metadata for later fallbacks.

                const qty = qtyField ? parseInt(qtyField.value, 10) || 0 : 0;
                const price = priceField ? parseFloat(priceField.value) || 0 : 0;

                totalQty += qty;
                if (defaultRadio && defaultRadio.checked) {
                    defaultPrice = price;
                    defaultFound = true;
                }
            });

            if (!defaultFound && activeRows.length > 0) {
                const firstRow = activeRows[0];
                if (firstRow.defaultRadio) {
                    firstRow.defaultRadio.checked = true;
                }
                const fallbackPrice = firstRow.priceField ? parseFloat(firstRow.priceField.value) || 0 : 0;
                defaultPrice = fallbackPrice;
            }

            const hasVariants = activeRows.length > 0; // Added: only lock the main fields when at least one variant is defined.

            if (quantityInput) {
                quantityInput.readOnly = hasVariants;
                if (hasVariants) {
                    quantityInput.value = String(totalQty);
                }
            }
            if (priceInput) {
                priceInput.readOnly = hasVariants;
                if (hasVariants) {
                    priceInput.value = Number.isFinite(defaultPrice) ? defaultPrice.toFixed(2) : '0.00';
                }
            }
        }

        function collectVariants() {
            const data = [];
            const rows = Array.from(rowsContainer.querySelectorAll('[data-variant-row]'));
            rows.forEach((row, index) => {
                const idField = row.querySelector('[data-variant-id]');
                const labelField = row.querySelector('[data-variant-label]');
                const skuField = row.querySelector('[data-variant-sku]');
                const priceField = row.querySelector('[data-variant-price]');
                const qtyField = row.querySelector('[data-variant-quantity]');
                const defaultRadio = row.querySelector('[data-variant-default]');
                const label = labelField ? labelField.value.trim() : '';
                if (label === '') {
                    return;
                }

                data.push({
                    id: idField && idField.value ? parseInt(idField.value, 10) : null,
                    label,
                    sku: skuField && skuField.value.trim() !== '' ? skuField.value.trim() : null,
                    price: priceField ? parseFloat(priceField.value) || 0 : 0,
                    quantity: qtyField ? parseInt(qtyField.value, 10) || 0 : 0,
                    is_default: defaultRadio ? defaultRadio.checked : false,
                    sort_order: index + 1,
                });
            });
            return data;
        }

        function handleSubmit(event) {
            const serialised = JSON.stringify(collectVariants());
            payloadInput.value = serialised;
        }

        function createRow(data = {}) {
            const fragment = template.content.cloneNode(true);
            const row = fragment.querySelector('[data-variant-row]');
            if (!row) {
                return null;
            }

            const idField = row.querySelector('[data-variant-id]');
            const labelField = row.querySelector('[data-variant-label]');
            const skuField = row.querySelector('[data-variant-sku]');
            const priceField = row.querySelector('[data-variant-price]');
            const qtyField = row.querySelector('[data-variant-quantity]');
            const defaultRadio = row.querySelector('[data-variant-default]');
            const removeButton = row.querySelector('[data-variant-remove]');

            if (idField) {
                idField.value = data.id && Number.isFinite(data.id) ? String(data.id) : '';
            }
            if (labelField) {
                labelField.value = data.label || '';
            }
            if (skuField) {
                skuField.value = data.sku || '';
            }
            if (priceField) {
                priceField.value = data.price !== undefined ? parseFloat(data.price).toFixed(2) : '';
            }
            if (qtyField) {
                qtyField.value = data.quantity !== undefined ? String(data.quantity) : '';
            }
            if (defaultRadio) {
                defaultRadio.name = defaultRadioName;
                defaultRadio.checked = Boolean(data.is_default);
            }

            const triggerUpdate = () => updateAggregates();
            [labelField, skuField, priceField, qtyField].forEach((field) => {
                field?.addEventListener('input', triggerUpdate);
            });
            defaultRadio?.addEventListener('change', triggerUpdate);

            if (removeButton) {
                removeButton.addEventListener('click', (evt) => {
                    evt.preventDefault();
                    row.remove();
                    markEmptyState();
                    if (rowsContainer.children.length === 0) {
                        addVariant();
                    } else {
                        updateAggregates();
                    }
                });
            }

            rowsContainer.appendChild(row);
            return row;
        }

        function addVariant(data = {}) {
            const row = createRow(data);
            markEmptyState();
            updateAggregates();
            if (row && data.is_default) {
                const radio = row.querySelector('[data-variant-default]');
                if (radio) {
                    radio.checked = true;
                    updateAggregates();
                }
            }
            return row;
        }

        function hydrateFromDataset() {
            rowsContainer.innerHTML = '';
            const initialRaw = editor.dataset.initialVariants || '[]';
            let parsed = [];
            try {
                parsed = JSON.parse(initialRaw);
            } catch (error) {
                parsed = [];
            }
            if (!Array.isArray(parsed) || parsed.length === 0) {
                addVariant({ label: '', price: 0, quantity: 0, is_default: true });
            } else {
                parsed.forEach((variant, index) => {
                    addVariant({
                        id: variant.id ?? null,
                        label: variant.label ?? '',
                        sku: variant.sku ?? '',
                        price: variant.price ?? 0,
                        quantity: variant.quantity ?? 0,
                        is_default: Boolean(variant.is_default),
                    });
                });
            }
            markEmptyState();
            updateAggregates();
        }

        addButton?.addEventListener('click', (event) => {
            event.preventDefault();
            addVariant({ label: '', price: 0, quantity: 0, is_default: rowsContainer.children.length === 0 });
        });

        hostForm?.addEventListener('submit', handleSubmit);
        editor.addEventListener(EVENT_HYDRATE, hydrateFromDataset);

        // Initial boot: hydrate based on markup dataset or fallback row.
        hydrateFromDataset();
    }

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('[data-variant-editor]').forEach((editor) => {
            initialiseVariantEditor(editor);
        });
    });
})();
