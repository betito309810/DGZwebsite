        const PRODUCT_IMAGE_PLACEHOLDER =
            (typeof window !== 'undefined' && window.PRODUCT_IMAGE_PLACEHOLDER)
                ? window.PRODUCT_IMAGE_PLACEHOLDER
                : '../assets/img/product-placeholder.svg';
        if (typeof window !== 'undefined') {
            window.PRODUCT_IMAGE_PLACEHOLDER = PRODUCT_IMAGE_PLACEHOLDER;
        }

        // Added: preview helper for the add-product modal so admins instantly see their chosen file.
        function previewAddImage(event) {
            const [file] = event.target.files || [];
            const preview = document.getElementById('addImagePreview');
            if (!preview) {
                return;
            }
            preview.src = file ? URL.createObjectURL(file) : PRODUCT_IMAGE_PLACEHOLDER;
        }

        // Begin Edit modal image preview updater
        function previewEditImage(event) {
            const [file] = event.target.files || [];
            const preview = document.getElementById('editImagePreview');
            if (!preview) {
                return;
            }
            preview.src = file ? URL.createObjectURL(file) : PRODUCT_IMAGE_PLACEHOLDER;
            const removeToggle = document.getElementById('edit_remove_main_image');
            if (removeToggle) {
                removeToggle.checked = false;
                removeToggle.dataset.currentImageUrl = preview.src;
            }
        }
        // End Edit modal image preview updater

        // Begin Edit modal brand fallback toggle
        function toggleBrandInputEdit(sel) {
            const input = document.getElementById('edit_brand_new');
            if (sel.value === '__addnew__') {
                input.style.display = 'block';
                input.required = true;
            } else {
                input.style.display = 'none';
                input.required = false;
            }
        }
        // End Edit modal brand fallback toggle

        // Begin Edit modal category fallback toggle
        function toggleCategoryInputEdit(sel) {
            const input = document.getElementById('edit_category_new');
            if (sel.value === '__addnew__') {
                input.style.display = 'block';
                input.required = true;
            } else {
                input.style.display = 'none';
                input.required = false;
            }
        }
        // End Edit modal category fallback toggle

        // Begin Edit modal supplier fallback toggle
        function toggleSupplierInputEdit(sel) {
            const input = document.getElementById('edit_supplier_new');
            if (sel.value === '__addnew__') {
                input.style.display = 'block';
                input.required = true;
            } else {
                input.style.display = 'none';
                input.required = false;
            }
        }
        // End Edit modal supplier fallback toggle