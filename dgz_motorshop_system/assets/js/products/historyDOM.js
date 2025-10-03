// Begin Products page DOM wiring
        document.addEventListener('DOMContentLoaded', () => {
            const historyModal = document.getElementById('historyModal');
            const historyList = document.getElementById('historyList');
            const openHistoryButton = document.getElementById('openHistoryModal');
            const closeHistoryButton = document.getElementById('closeHistoryModal');

            openHistoryButton?.addEventListener('click', () => {
                if (!historyModal || !historyList) {
                    return;
                }

                historyModal.style.display = 'flex';
                historyList.innerHTML =
                    '<div style="text-align:center;padding:20px;"><i class="fas fa-spinner fa-spin"></i> Loading history...</div>';

                fetch('products.php?history=1', {
                    cache: 'no-store',
                    headers: { 'Accept': 'text/html' }
                })
                    .then((response) => {
                        if (!response.ok) {
                            throw new Error('Network response was not ok');
                        }
                        return response.text();
                    })
                    .then((html) => {
                        historyList.innerHTML = html;
                    })
                    .catch((error) => {
                        historyList.innerHTML = `
                            <div style="text-align:center;padding:20px;color:#dc3545;">
                                <i class="fas fa-exclamation-circle"></i> Error loading history: ${error.message}
                            </div>`;
                    });
            });

            closeHistoryButton?.addEventListener('click', () => {
                if (historyModal) {
                    historyModal.style.display = 'none';
                }
            });

            historyModal?.addEventListener('click', (event) => {
                if (event.target === historyModal) {
                    historyModal.style.display = 'none';
                }
            });

            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && historyModal?.style.display === 'flex') {
                    historyModal.style.display = 'none';
                }
            });

            const profileButton = document.getElementById('profileTrigger');
            const profileModal = document.getElementById('profileModal');
            const profileModalClose = document.getElementById('profileModalClose');

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

            const sidebar = document.getElementById('sidebar');
            const mobileToggle = document.querySelector('.mobile-toggle');

            document.addEventListener('click', (event) => {
                const userMenu = document.querySelector('.user-menu');
                if (userMenu && !userMenu.contains(event.target)) {
                    document.getElementById('userDropdown')?.classList.remove('show');
                }

                if (
                    window.innerWidth <= 768 &&
                    sidebar &&
                    mobileToggle &&
                    !sidebar.contains(event.target) &&
                    !mobileToggle.contains(event.target)
                ) {
                    sidebar.classList.remove('mobile-open');
                }
            });

            const editModal = document.getElementById('editModal');

            document.querySelectorAll('.edit-btn').forEach((btn) => {
                btn.addEventListener('click', (event) => {
                    event.preventDefault();
                    (document.getElementById('edit_id') ?? {}).value = btn.dataset.id ?? '';
                    (document.getElementById('edit_code') ?? {}).value = btn.dataset.code ?? '';
                    (document.getElementById('edit_name') ?? {}).value = btn.dataset.name ?? '';
                    (document.getElementById('edit_description') ?? {}).value = btn.dataset.description ?? '';
                    (document.getElementById('edit_price') ?? {}).value = btn.dataset.price ?? '';
                    (document.getElementById('edit_quantity') ?? {}).value = btn.dataset.quantity ?? '';
                    (document.getElementById('edit_low') ?? {}).value = btn.dataset.low ?? '';
                    setSelectWithFallback('edit_brand', 'edit_brand_new', btn.dataset.brand || '');
                    setSelectWithFallback('edit_category', 'edit_category_new', btn.dataset.category || '');
                    setSelectWithFallback('edit_supplier', 'edit_supplier_new', btn.dataset.supplier || '');
                    const preview = document.getElementById('editImagePreview');
                    if (preview) {
                        preview.src = 'https://via.placeholder.com/120x120?text=No+Image';
                    }
                    if (editModal) {
                        editModal.style.display = 'flex';
                    }
                });
            });

            document.getElementById('closeEditModal')?.addEventListener('click', () => {
                if (editModal) {
                    editModal.style.display = 'none';
                }
            });

            editModal?.addEventListener('click', (event) => {
                if (event.target === editModal) {
                    editModal.style.display = 'none';
                }
            });

            const addModal = document.getElementById('addModal');

            document.getElementById('openAddModal')?.addEventListener('click', () => {
                if (addModal) {
                    addModal.style.display = 'flex';
                }
                setSelectWithFallback('brandSelect', 'brandNewInput', '');
                setSelectWithFallback('categorySelect', 'categoryNewInput', '');
                setSelectWithFallback('supplierSelect', 'supplierNewInput', '');
            });

            document.getElementById('closeAddModal')?.addEventListener('click', () => {
                if (addModal) {
                    addModal.style.display = 'none';
                }
            });

            addModal?.addEventListener('click', (event) => {
                if (event.target === addModal) {
                    addModal.style.display = 'none';
                }
            });
        });
        // End Products page DOM wiring