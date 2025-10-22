(function (global) {
    'use strict';

    function setSelectWithFallback(selectId, inputId, value) {
        const selectEl = document.getElementById(selectId);
        const inputEl = document.getElementById(inputId);
        if (!selectEl || !inputEl) {
            return;
        }

        const normalisedValue = (value ?? '').toString().trim();
        const hasMatchingOption = Array.from(selectEl.options).some((option) => {
            return option.value === normalisedValue && normalisedValue !== '';
        });

        if (normalisedValue && hasMatchingOption) {
            selectEl.value = normalisedValue;
            inputEl.style.display = 'none';
            inputEl.required = false;
            inputEl.value = '';
        } else if (normalisedValue) {
            selectEl.value = '__addnew__';
            inputEl.style.display = 'block';
            inputEl.required = true;
            inputEl.value = normalisedValue;
        } else {
            selectEl.value = '';
            inputEl.style.display = 'none';
            inputEl.required = false;
            inputEl.value = '';
        }
    }

    function toggleBrandInput(selectEl) {
        const input = document.getElementById('brandNewInput');
        if (!input) {
            return;
        }
        if (selectEl?.value === '__addnew__') {
            input.style.display = 'block';
            input.required = true;
        } else {
            input.style.display = 'none';
            input.required = false;
        }
    }

    function toggleCategoryInput(selectEl) {
        const input = document.getElementById('categoryNewInput');
        if (!input) {
            return;
        }
        if (selectEl?.value === '__addnew__') {
            input.style.display = 'block';
            input.required = true;
        } else {
            input.style.display = 'none';
            input.required = false;
        }
    }

    function toggleSupplierInput(selectEl) {
        const input = document.getElementById('supplierNewInput');
        if (!input) {
            return;
        }
        if (selectEl?.value === '__addnew__') {
            input.style.display = 'block';
            input.required = true;
        } else {
            input.style.display = 'none';
            input.required = false;
        }
    }

    global.setSelectWithFallback = setSelectWithFallback;
    global.toggleBrandInput = toggleBrandInput;
    global.toggleCategoryInput = toggleCategoryInput;
    global.toggleSupplierInput = toggleSupplierInput;

    const MAX_QUANTITY = 9999;
    const CODE_SUFFIX_WIDTH = 3;
    const EVENT_QUANTITY_CHANGED = 'product:quantityChanged';
    const CATEGORY_CODE_PREFIXES = new Map([
        ['LUBRICANT', 'LRT'],
        ['LUBRICANTS', 'LRT'],
    ]);

    const normaliseText = (value) => (value ?? '').toString().trim();
    const normaliseCode = (value) => normaliseText(value).toUpperCase();

    const clampQuantityNumber = (value) => {
        if (!Number.isFinite(value)) {
            return 0;
        }
        if (value < 0) {
            return 0;
        }
        if (value > MAX_QUANTITY) {
            return MAX_QUANTITY;
        }
        return Math.trunc(value);
    };

    const computeLowStockThreshold = (quantity) => {
        if (!Number.isFinite(quantity) || quantity <= 0) {
            return 0;
        }
        const candidate = Math.round(quantity * 0.2);
        const threshold = Math.max(1, candidate);
        return clampQuantityNumber(threshold);
    };

    const parseExistingCodePrefixes = () => {
        const rawIndex = Array.isArray(global.PRODUCT_CODE_INDEX) ? global.PRODUCT_CODE_INDEX : [];
        const highestByPrefix = new Map();

        rawIndex.forEach((entry) => {
            const code = normaliseCode(entry?.code);
            if (!code) {
                return;
            }
            const match = code.match(/^(.*?)(\d{1,})$/);
            const prefix = match ? match[1] : code;
            const suffix = match ? Number.parseInt(match[2], 10) : 0;
            const numericSuffix = Number.isFinite(suffix) ? suffix : 0;
            const currentHighest = highestByPrefix.get(prefix) ?? 0;
            if (numericSuffix > currentHighest) {
                highestByPrefix.set(prefix, numericSuffix);
            }
        });

        return highestByPrefix;
    };

    const highestSuffixByPrefix = parseExistingCodePrefixes();
    const generatedSuggestions = new Map();

    const derivePrefixFromCategory = (categoryName) => {
        const key = normaliseCode(categoryName);
        if (!key) {
            return '';
        }
        if (CATEGORY_CODE_PREFIXES.has(key)) {
            return CATEGORY_CODE_PREFIXES.get(key);
        }
        const cleaned = key.replace(/[^A-Z0-9]/g, '');
        if (cleaned.length >= 3) {
            return cleaned.slice(0, 3);
        }
        if (cleaned.length === 2) {
            return `${cleaned}X`;
        }
        if (cleaned.length === 1) {
            return `${cleaned}XX`;
        }
        return 'PRD';
    };

    const getNextCodeForPrefix = (prefix) => {
        if (!prefix) {
            return '';
        }
        const cached = generatedSuggestions.get(prefix);
        if (cached) {
            return cached;
        }
        const nextIndex = (highestSuffixByPrefix.get(prefix) ?? 0) + 1;
        const suggestion = `${prefix}${String(nextIndex).padStart(CODE_SUFFIX_WIDTH, '0')}`;
        generatedSuggestions.set(prefix, suggestion);
        return suggestion;
    };

    const markManualOverride = (input) => {
        if (input) {
            input.dataset.manualCode = '1';
        }
    };

    const clearManualOverride = (input) => {
        if (input) {
            delete input.dataset.manualCode;
        }
    };

    const hasManualOverride = (input) => Boolean(input?.dataset.manualCode === '1');

    const normaliseQuantityInput = (input) => {
        if (!input) {
            return 0;
        }
        const raw = typeof input.value === 'string' ? input.value : String(input.value ?? '');
        const trimmed = raw.trim();
        if (trimmed === '') {
            return 0;
        }
        const parsed = Number.parseInt(trimmed, 10);
        const clamped = clampQuantityNumber(parsed);
        if (String(clamped) !== trimmed) {
            input.value = String(clamped);
        }
        return clamped;
    };

    const updateLowStockField = (lowInput, quantity) => {
        if (!lowInput) {
            return;
        }
        const threshold = computeLowStockThreshold(quantity);
        lowInput.value = String(threshold);
    };

    const attachLowStockSync = (form, quantityInput, lowInput) => {
        if (!lowInput) {
            return;
        }
        lowInput.readOnly = true;

        const syncFromField = () => {
            const qty = normaliseQuantityInput(quantityInput);
            updateLowStockField(lowInput, qty);
        };

        if (quantityInput) {
            quantityInput.addEventListener('input', syncFromField);
            quantityInput.addEventListener('change', syncFromField);
        }

        if (form) {
            form.addEventListener(EVENT_QUANTITY_CHANGED, (event) => {
                const detailQuantity = Number(event?.detail?.quantity);
                if (Number.isFinite(detailQuantity)) {
                    updateLowStockField(lowInput, clampQuantityNumber(detailQuantity));
                } else {
                    syncFromField();
                }
            });
        }

        syncFromField();
    };

    const deriveSelectedCategory = (selectEl, textInput) => {
        const selectValue = selectEl ? normaliseText(selectEl.value) : '';
        if (selectValue === '__addnew__') {
            return normaliseText(textInput?.value ?? '');
        }
        if (selectValue !== '') {
            return selectValue;
        }
        return normaliseText(textInput?.value ?? '');
    };

    const autoFillProductCode = (codeInput, selectEl, textInput) => {
        if (!codeInput || hasManualOverride(codeInput)) {
            return;
        }
        if (normaliseText(codeInput.value) !== '') {
            return;
        }
        const categoryName = deriveSelectedCategory(selectEl, textInput);
        if (!categoryName) {
            return;
        }
        const prefix = derivePrefixFromCategory(categoryName);
        if (!prefix) {
            return;
        }
        const suggestion = getNextCodeForPrefix(prefix);
        if (!suggestion) {
            return;
        }
        codeInput.value = suggestion;
    };

    document.addEventListener('DOMContentLoaded', () => {
        const addForm = document.getElementById('addProductForm');
        const editForm = document.getElementById('editProductForm');

        const addCodeInput = document.getElementById('addProductCode');
        const addCategorySelect = document.getElementById('categorySelect');
        const addCategoryNewInput = document.getElementById('categoryNewInput');
        const addQuantityInput = document.getElementById('addProductQuantity');
        const addLowInput = document.getElementById('addProductLowStock');

        const editQuantityInput = document.getElementById('edit_quantity');
        const editLowInput = document.getElementById('edit_low');

        if (addCodeInput) {
            addCodeInput.addEventListener('input', () => {
                const trimmed = normaliseText(addCodeInput.value);
                if (trimmed === '') {
                    clearManualOverride(addCodeInput);
                } else {
                    markManualOverride(addCodeInput);
                }
            });
        }

        const triggerAutoFill = () => autoFillProductCode(addCodeInput, addCategorySelect, addCategoryNewInput);

        addCategorySelect?.addEventListener('change', () => {
            triggerAutoFill();
        });

        addCategoryNewInput?.addEventListener('input', () => {
            const selectValue = addCategorySelect ? normaliseText(addCategorySelect.value) : '';
            if (selectValue === '__addnew__' || selectValue === '') {
                triggerAutoFill();
            }
        });

        document.getElementById('openAddModal')?.addEventListener('click', () => {
            if (addCodeInput) {
                addCodeInput.value = '';
                clearManualOverride(addCodeInput);
            }
            updateLowStockField(addLowInput, 0);
        });

        attachLowStockSync(addForm, addQuantityInput, addLowInput);
        attachLowStockSync(editForm, editQuantityInput, editLowInput);

        triggerAutoFill();
    });
})(typeof window !== 'undefined' ? window : this);
