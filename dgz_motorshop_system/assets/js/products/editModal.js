// Begin Edit modal image preview updater
        function previewEditImage(event) {
            const [file] = event.target.files;
            if (file) {
                document.getElementById('editImagePreview').src = URL.createObjectURL(file);
            } else {
                document.getElementById('editImagePreview').src = 'https://via.placeholder.com/120x120?text=No+Image';
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