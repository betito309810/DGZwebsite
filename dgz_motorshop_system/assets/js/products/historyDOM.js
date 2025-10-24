// Begin Products page DOM wiring
        document.addEventListener('DOMContentLoaded', () => {
            const normaliseCode = (value) => {
                return (value ?? '').toString().trim().toUpperCase();
            };

            const productCodeIndexRaw = Array.isArray(window.PRODUCT_CODE_INDEX)
                ? window.PRODUCT_CODE_INDEX
                : [];
            const productCodeIndex = new Map();
            productCodeIndexRaw.forEach((entry) => {
                if (!entry) {
                    return;
                }
                const code = normaliseCode(entry.code);
                if (!code) {
                    return;
                }
                const ownerId = Number.parseInt(entry.id, 10) || 0;
                const bucket = productCodeIndex.get(code) ?? new Set();
                if (ownerId > 0) {
                    bucket.add(ownerId);
                }
                productCodeIndex.set(code, bucket);
            });

            const enforceUniqueProductCode = (form) => {
                if (!form) {
                    return true;
                }

                const codeField = form.querySelector('input[name="code"]');
                if (!codeField) {
                    return true;
                }

                const idField = form.querySelector('input[name="id"]');
                const currentId = idField ? Number.parseInt(idField.value, 10) || 0 : 0;
                const candidateCode = normaliseCode(codeField.value);

                if (!candidateCode) {
                    codeField.setCustomValidity('');
                    return true;
                }

                const owners = productCodeIndex.get(candidateCode);
                const hasConflict = owners
                    ? Array.from(owners).some((ownerId) => ownerId !== currentId)
                    : false;

                if (hasConflict) {
                    codeField.setCustomValidity('Product code is already in use. Please choose a different code.');
                    if (typeof codeField.reportValidity === 'function') {
                        codeField.reportValidity();
                    }
                    try {
                        codeField.focus({ preventScroll: true });
                    } catch (error) {
                        codeField.focus();
                    }
                    return false;
                }

                codeField.setCustomValidity('');
                return true;
            };

            const attachProductCodeValidation = (form) => {
                if (!form) {
                    return;
                }

                const codeField = form.querySelector('input[name="code"]');
                if (!codeField) {
                    return;
                }

                codeField.addEventListener('input', () => {
                    codeField.setCustomValidity('');
                });

                form.addEventListener('submit', (event) => {
                    if (!enforceUniqueProductCode(form)) {
                        event.preventDefault();
                        event.stopImmediatePropagation();
                    }
                });
            };

            const addProductForm = document.getElementById('addProductForm');
            const editProductForm = document.getElementById('editProductForm');

            attachProductCodeValidation(addProductForm);
            attachProductCodeValidation(editProductForm);

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
            const detailGalleryGrid = detailModal?.querySelector('[data-detail-gallery-grid]');
            const detailGalleryEmpty = detailModal?.querySelector('[data-detail-gallery-empty]');
            const detailFields = {};
            const placeholderImageSrc = (typeof window !== 'undefined' && window.PRODUCT_IMAGE_PLACEHOLDER)
                ? window.PRODUCT_IMAGE_PLACEHOLDER
                : '../assets/img/product-placeholder.svg';
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

                    const code = document.createElement('span');
                    code.textContent = `Code: ${
                        (variant?.variant_code && String(variant.variant_code).trim()) || '—'
                    }`;
                    meta.appendChild(code);

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

                    const threshold = document.createElement('span');
                    const thresholdValue = variant?.low_stock_threshold ?? null;
                    const formattedThreshold = thresholdValue !== null && thresholdValue !== ''
                        ? formatNumber(thresholdValue)
                        : null;
                    threshold.textContent = `Low stock limit: ${formattedThreshold ?? '—'}`;
                    meta.appendChild(threshold);

                    card.appendChild(meta);
                    list.appendChild(card);
                });

                detailVariantsContainer.appendChild(list);
            };

            const renderDetailGallery = (images, productName) => {
                if (!detailGalleryGrid) {
                    return;
                }

                detailGalleryGrid.querySelectorAll('[data-detail-gallery-item]').forEach((node) => {
                    node.remove();
                });

                const safeImages = Array.isArray(images)
                    ? images.filter((image) => image && typeof image === 'object')
                    : [];

                if (detailGalleryEmpty) {
                    detailGalleryEmpty.hidden = safeImages.length !== 0;
                }

                if (safeImages.length === 0) {
                    return;
                }

                safeImages.forEach((image, index) => {
                    const item = document.createElement('div');
                    item.className = 'product-detail__gallery-item';
                    item.dataset.detailGalleryItem = 'true';

                    const thumb = document.createElement('img');
                    thumb.className = 'product-detail__gallery-thumb';
                    const sourceUrl = (image?.url && String(image.url).trim()) || '';
                    thumb.src = sourceUrl !== '' ? sourceUrl : placeholderImageSrc;
                    thumb.alt = `Gallery image ${index + 1} for ${productName || 'selected product'}`;
                    thumb.loading = 'lazy';
                    thumb.addEventListener('error', () => {
                        thumb.src = placeholderImageSrc;
                    });

                    const caption = document.createElement('span');
                    caption.className = 'product-detail__gallery-caption';
                    caption.textContent = `Gallery image ${index + 1}`;

                    item.append(thumb, caption);
                    detailGalleryGrid.appendChild(item);
                });
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

                renderDetailGallery(product.gallery, product.name ?? '');
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

            document.querySelectorAll('.delete-btn').forEach((button) => {
                button.addEventListener('click', (event) => {
                    event.stopPropagation();

                    const targetUrl = button.getAttribute('href');
                    if (!targetUrl) {
                        event.preventDefault();
                        return;
                    }

                    const productName = button.dataset.productName || 'this product';
                    const confirmationMessage = `Archiving ${productName} will hide it from the ordering site until you restore it. Continue?`;

                    if (!window.confirm(confirmationMessage)) {
                        event.preventDefault();
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

            const editImageInput = document.getElementById('edit_image');
            const editGalleryInput = document.getElementById('edit_gallery_images');
            const editImagePreview = document.getElementById('editImagePreview');
            const removeMainToggle = document.getElementById('edit_remove_main_image');
            const removeMainToggleContainer = editModal?.querySelector('[data-main-image-toggle]');
            const galleryContainer = editModal?.querySelector('[data-gallery-container]');
            const galleryList = editModal?.querySelector('[data-gallery-list]');

            const parseJson = (value, fallback = []) => {
                if (typeof value !== 'string' || value.trim() === '') {
                    return fallback;
                }

                try {
                    const parsed = JSON.parse(value);
                    return Array.isArray(parsed) ? parsed : fallback;
                } catch (error) {
                    console.warn('Failed to parse JSON payload', error);
                    return fallback;
                }
            };

            const renderEditGallery = (images) => {
                if (!galleryContainer || !galleryList) {
                    return;
                }

                galleryList.innerHTML = '';

                if (!Array.isArray(images) || images.length === 0) {
                    galleryContainer.hidden = true;
                    return;
                }

                galleryContainer.hidden = false;

                images.forEach((image) => {
                    const id = Number(image?.id);
                    if (!Number.isFinite(id) || id <= 0) {
                        return;
                    }

                    const item = document.createElement('div');
                    item.className = 'product-modal__gallery-item';

                    const thumb = document.createElement('img');
                    thumb.className = 'product-modal__gallery-thumb';
                    thumb.alt = 'Gallery image preview';
                    thumb.src = image?.url || placeholderImageSrc;
                    thumb.addEventListener('error', () => {
                        thumb.src = placeholderImageSrc;
                    });

                    const checkboxId = `remove_gallery_${id}`;
                    const label = document.createElement('label');
                    label.className = 'product-modal__gallery-remove';
                    label.setAttribute('for', checkboxId);

                    const checkbox = document.createElement('input');
                    checkbox.type = 'checkbox';
                    checkbox.name = 'remove_gallery_ids[]';
                    checkbox.id = checkboxId;
                    checkbox.value = String(id);

                    const text = document.createElement('span');
                    text.textContent = 'Remove';

                    label.append(checkbox, text);
                    item.append(thumb, label);
                    galleryList.appendChild(item);
                });
            };

            removeMainToggle?.addEventListener('change', () => {
                if (!editImagePreview) {
                    return;
                }

                if (removeMainToggle.checked) {
                    if (editImageInput) {
                        editImageInput.value = '';
                    }
                    editImagePreview.src = placeholderImageSrc;
                } else {
                    const original = removeMainToggle.dataset.currentImageUrl || placeholderImageSrc;
                    editImagePreview.src = original;
                }
            });

            document.querySelectorAll('.edit-btn').forEach((btn) => {
                btn.addEventListener('click', (event) => {
                    event.preventDefault();
                    // Stop the event from bubbling to the row-level click handler.
                    // This guarantees single-click open for Edit even if DOM changes
                    // or the closest('.action-btn') guard fails in some browsers.
                    event.stopPropagation();
                    (document.getElementById('edit_id') ?? {}).value = btn.dataset.id ?? '';
                    (document.getElementById('edit_code') ?? {}).value = btn.dataset.code ?? '';
                    (document.getElementById('edit_name') ?? {}).value = btn.dataset.name ?? '';
                    (document.getElementById('edit_description') ?? {}).value = btn.dataset.description ?? '';
                    (document.getElementById('edit_price') ?? {}).value = btn.dataset.price ?? '';
                    (document.getElementById('edit_quantity') ?? {}).value = btn.dataset.quantity ?? '';
                    (document.getElementById('edit_low') ?? {}).value = btn.dataset.low ?? '';
                    const editForm = document.getElementById('editProductForm');
                    if (editForm) {
                        const parsedQuantity = Number.parseInt(btn.dataset.quantity ?? '', 10);
                        const safeQuantity = Number.isFinite(parsedQuantity) ? parsedQuantity : 0;
                        editForm.dispatchEvent(new CustomEvent('product:quantityChanged', {
                            detail: { quantity: safeQuantity },
                        }));
                    }
                    setSelectWithFallback('edit_brand', 'edit_brand_new', btn.dataset.brand || '', 'brand');
                    setSelectWithFallback('edit_category', 'edit_category_new', btn.dataset.category || '', 'category');
                    setSelectWithFallback('edit_supplier', 'edit_supplier_new', btn.dataset.supplier || '', 'supplier');
                    if (editImageInput) {
                        editImageInput.value = '';
                    }
                    if (editGalleryInput) {
                        editGalleryInput.value = '';
                    }
                    const preview = document.getElementById('editImagePreview');
                    if (preview) {
                        const imageUrl = btn.dataset.imageUrl || placeholderImageSrc;
                        preview.src = imageUrl;
                    }
                    if (removeMainToggle) {
                        const hasStoredImage = Boolean((btn.dataset.image || '').trim() !== '');
                        removeMainToggle.checked = false;
                        removeMainToggle.disabled = !hasStoredImage;
                        removeMainToggle.dataset.currentImageUrl = btn.dataset.imageUrl || placeholderImageSrc;
                        if (removeMainToggleContainer) {
                            removeMainToggleContainer.hidden = !hasStoredImage;
                        }
                    }
                    const editVariantEditor = editModal?.querySelector('[data-variant-editor][data-context="edit"]');
                    if (editVariantEditor) {
                        editVariantEditor.dataset.initialVariants = btn.dataset.variants || '[]';
                        editVariantEditor.dispatchEvent(new CustomEvent('variant:hydrate'));
                    }
                    renderEditGallery(parseJson(btn.dataset.gallery || '[]'));
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

            const taxonomyModal = document.getElementById('taxonomyModal');
            const taxonomySwitcherButtons = taxonomyModal ? Array.from(taxonomyModal.querySelectorAll('[data-taxonomy-switch]')) : [];
            const taxonomyPanels = taxonomyModal ? Array.from(taxonomyModal.querySelectorAll('[data-taxonomy-panel]')) : [];

            const setActiveTaxonomy = (targetKey) => {
                if (!taxonomyModal || taxonomySwitcherButtons.length === 0) {
                    return;
                }
                const fallbackKey = taxonomySwitcherButtons[0]?.dataset.taxonomySwitch || '';
                const resolvedKey = targetKey || fallbackKey;
                if (!resolvedKey) {
                    return;
                }

                taxonomySwitcherButtons.forEach((button) => {
                    const isActive = (button.dataset.taxonomySwitch || '') === resolvedKey;
                    button.classList.toggle('taxonomy-manager__switcher-button--active', isActive);
                    button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
                });

                taxonomyPanels.forEach((panel) => {
                    const isActive = (panel.dataset.taxonomyPanel || '') === resolvedKey;
                    panel.classList.toggle('taxonomy-manager__section--active', isActive);
                });
            };

            taxonomySwitcherButtons.forEach((button) => {
                button.addEventListener('click', () => {
                    setActiveTaxonomy(button.dataset.taxonomySwitch || '');
                });
            });

            if (taxonomySwitcherButtons.length > 0) {
                const initiallyActive = taxonomySwitcherButtons.find((button) => button.classList.contains('taxonomy-manager__switcher-button--active'));
                setActiveTaxonomy(initiallyActive?.dataset.taxonomySwitch || taxonomySwitcherButtons[0]?.dataset.taxonomySwitch || '');
            }

            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') {
                    if (detailModal?.style.display === 'flex') {
                        closeDetailModal();
                    }
                    if (taxonomyModal?.style.display === 'flex') {
                        taxonomyModal.style.display = 'none';
                    }
                }
            });

            const addModal = document.getElementById('addModal');

            document.getElementById('openAddModal')?.addEventListener('click', () => {
                if (addModal) {
                    addModal.style.display = 'flex';
                }
                setSelectWithFallback('brandSelect', 'brandNewInput', '', 'brand');
                setSelectWithFallback('categorySelect', 'categoryNewInput', '', 'category');
                setSelectWithFallback('supplierSelect', 'supplierNewInput', '', 'supplier');
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

            document.getElementById('openTaxonomyModal')?.addEventListener('click', () => {
                if (taxonomyModal) {
                    taxonomyModal.style.display = 'flex';
                    if (taxonomySwitcherButtons.length > 0) {
                        const activeButton = taxonomySwitcherButtons.find((button) => button.classList.contains('taxonomy-manager__switcher-button--active'));
                        setActiveTaxonomy(activeButton?.dataset.taxonomySwitch || taxonomySwitcherButtons[0]?.dataset.taxonomySwitch || '');
                    }
                }
            });

            document.getElementById('closeTaxonomyModal')?.addEventListener('click', () => {
                if (taxonomyModal) {
                    taxonomyModal.style.display = 'none';
                }
            });

            taxonomyModal?.addEventListener('click', (event) => {
                if (event.target === taxonomyModal) {
                    taxonomyModal.style.display = 'none';
                }
            });
        });
        // End Products page DOM wiring
