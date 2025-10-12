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
            const detailModal = document.getElementById('productDetailModal');
            const detailCloseButton = document.getElementById('closeProductDetailModal');
            const detailImage = detailModal?.querySelector('[data-detail-image]');
            const detailVariantsContainer = detailModal?.querySelector('[data-detail-variants]');
            const detailFields = {};
            detailModal?.querySelectorAll('[data-detail-field]').forEach((node) => {
                if (!(node instanceof HTMLElement)) {
                    return;
                }
                const fieldKey = node.dataset.detailField;
                if (fieldKey) {
                    detailFields[fieldKey] = node;
                }
            });

            const formatCurrency = (value) => {
                if (value === null || value === undefined || value === '') {
                    return null;
                }
                const numeric = Number(value);
                if (Number.isFinite(numeric)) {
                    return `₱${numeric.toLocaleString(undefined, {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2,
                    })}`;
                }
                return String(value);
            };

            const formatNumber = (value) => {
                if (value === null || value === undefined || value === '') {
                    return null;
                }
                const numeric = Number(value);
                if (Number.isFinite(numeric)) {
                    const formatOptions = Number.isInteger(numeric)
                        ? {}
                        : { maximumFractionDigits: 2, minimumFractionDigits: 0 };
                    return numeric.toLocaleString(undefined, formatOptions);
                }
                return String(value);
            };

            const setDetailText = (field, value, options = {}) => {
                const target = detailFields[field];
                if (!target) {
                    return;
                }

                const { fallback = '—', mutedWhenFallback = true } = options;

                let displayValue = value;
                let isFallback = false;

                if (displayValue === null || displayValue === undefined || displayValue === '') {
                    displayValue = fallback;
                    isFallback = true;
                } else if (typeof displayValue === 'string' && displayValue.trim() === '') {
                    displayValue = fallback;
                    isFallback = true;
                }

                target.textContent = displayValue;
                target.classList.toggle('product-detail__value--muted', isFallback && mutedWhenFallback);
            };

            const renderVariantCards = (variants) => {
                if (!detailVariantsContainer) {
                    return;
                }

                detailVariantsContainer.innerHTML = '';

                if (!Array.isArray(variants) || variants.length === 0) {
                    const empty = document.createElement('p');
                    empty.className = 'product-detail__variants-empty';
                    empty.textContent = 'No variants available for this product.';
                    detailVariantsContainer.appendChild(empty);
                    return;
                }

                const list = document.createElement('div');
                list.className = 'product-detail__variant-list';

                variants.forEach((variant) => {
                    const card = document.createElement('div');
                    card.className = 'product-detail__variant-card';

                    const header = document.createElement('div');
                    header.className = 'product-detail__variant-card-header';

                    const label = document.createElement('span');
                    label.textContent = (variant?.label && String(variant.label).trim()) || 'Untitled variant';
                    header.appendChild(label);

                    if (variant?.is_default) {
                        const badge = document.createElement('span');
                        badge.className = 'product-detail__badge';
                        const icon = document.createElement('i');
                        icon.className = 'fas fa-star';
                        badge.append(icon, document.createTextNode(' Default'));
                        header.appendChild(badge);
                    }

                    card.appendChild(header);

                    const meta = document.createElement('div');
                    meta.className = 'product-detail__variant-meta';

                    const sku = document.createElement('span');
                    sku.textContent = `SKU: ${
                        (variant?.sku && String(variant.sku).trim()) || '—'
                    }`;
                    meta.appendChild(sku);

                    const price = document.createElement('span');
                    const formattedPrice = formatCurrency(variant?.price);
                    price.textContent = `Price: ${formattedPrice ?? '—'}`;
                    meta.appendChild(price);

                    const qty = document.createElement('span');
                    const formattedQty = formatNumber(variant?.quantity);
                    qty.textContent = `Quantity: ${formattedQty ?? '—'}`;
                    meta.appendChild(qty);

                    card.appendChild(meta);
                    list.appendChild(card);
                });

                detailVariantsContainer.appendChild(list);
            };

            const openDetailModal = (product) => {
                if (!detailModal) {
                    return;
                }

                setDetailText('code', product.code ?? '', { fallback: '—' });
                setDetailText('name', product.name ?? '', { fallback: '—' });
                setDetailText('brand', product.brand ?? '', { fallback: '—' });
                setDetailText('category', product.category ?? '', { fallback: '—' });
                setDetailText('supplier', product.supplier ?? '', { fallback: '—' });
                setDetailText('quantity', formatNumber(product.quantity), { fallback: '—' });
                setDetailText('price', formatCurrency(product.price), { fallback: '—' });
                setDetailText('low_stock_threshold', formatNumber(product.low_stock_threshold), {
                    fallback: '—',
                });
                setDetailText('description', product.description ?? '', {
                    fallback: 'No description provided.',
                });
                setDetailText('defaultVariant', product.defaultVariant ?? '', {
                    fallback: 'No default variant selected.',
                });

                if (detailImage) {
                    const imageUrl = (product.imageUrl && String(product.imageUrl)) || '../assets/img/product-placeholder.svg';
                    detailImage.src = imageUrl;
                    detailImage.alt = `Product preview for ${product.name || 'selected product'}`;
                }

                renderVariantCards(product.variants);

                detailModal.style.display = 'flex';
            };

            const closeDetailModal = () => {
                if (detailModal) {
                    detailModal.style.display = 'none';
                }
            };

            if (detailImage) {
                detailImage.addEventListener('error', () => {
                    if (!detailImage.src.includes('product-placeholder.svg')) {
                        detailImage.src = '../assets/img/product-placeholder.svg';
                    }
                });
            }

            document.querySelectorAll('.product-row').forEach((row) => {
                row.addEventListener('click', (event) => {
                    if (event.target.closest('.action-btn')) {
                        return;
                    }

                    const payload = row.dataset.product;
                    if (!payload) {
                        return;
                    }

                    try {
                        const product = JSON.parse(payload);
                        openDetailModal(product);
                    } catch (error) {
                        console.error('Failed to parse product payload for detail modal', error);
                    }
                });
            });

            detailCloseButton?.addEventListener('click', () => {
                closeDetailModal();
            });

            detailModal?.addEventListener('click', (event) => {
                if (event.target === detailModal) {
                    closeDetailModal();
                }
            });

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
                        const imageUrl = btn.dataset.imageUrl || '../assets/img/product-placeholder.svg';
                        preview.src = imageUrl;
                    }
                    const editVariantEditor = editModal?.querySelector('[data-variant-editor][data-context="edit"]');
                    if (editVariantEditor) {
                        editVariantEditor.dataset.initialVariants = btn.dataset.variants || '[]';
                        editVariantEditor.dispatchEvent(new CustomEvent('variant:hydrate'));
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

            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && detailModal?.style.display === 'flex') {
                    closeDetailModal();
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
                const createVariantEditor = addModal?.querySelector('[data-variant-editor][data-context="create"]');
                if (createVariantEditor) {
                    createVariantEditor.dataset.initialVariants = '[]';
                    createVariantEditor.dispatchEvent(new CustomEvent('variant:hydrate'));
                }
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