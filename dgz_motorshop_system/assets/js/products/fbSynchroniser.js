// Begin Select + text fallback synchroniser
            // Added: helper to synchronise select + optional text input when editing records.
            function setSelectWithFallback(selectId, inputId, value) {
                const selectEl = document.getElementById(selectId);
                const inputEl = document.getElementById(inputId);
                if (!selectEl || !inputEl) {
                    return;
                }

                const normalisedValue = value || '';
                const hasMatchingOption = Array.from(selectEl.options).some(opt => opt.value === normalisedValue && normalisedValue !== '');

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
            // End Select + text fallback synchroniser

            // Begin Brand selector fallback toggle
            function toggleBrandInput(sel) {
                const input = document.getElementById('brandNewInput');
                if (sel.value === '__addnew__') {
                    input.style.display = 'block';
                    input.required = true;
                } else {
                    input.style.display = 'none';
                    input.required = false;
                }
            }
            // End Brand selector fallback toggle

            // Begin Category selector fallback toggle
            function toggleCategoryInput(sel) {
                const input = document.getElementById('categoryNewInput');
                if (sel.value === '__addnew__') {
                    input.style.display = 'block';
                    input.required = true;
                } else {
                    input.style.display = 'none';
                    input.required = false;
                }
            }
            // End Category selector fallback toggle

            // Begin Supplier selector fallback toggle
            function toggleSupplierInput(sel) {
                const input = document.getElementById('supplierNewInput');
                if (sel.value === '__addnew__') {
                    input.style.display = 'block';
                    input.required = true;
                } else {
                    input.style.display = 'none';
                    input.required = false;
                }
            }
            // End Supplier selector fallback toggle