/**
 * Added: Front-end controller that powers the product gallery modal on the
 * public storefront. It now mirrors a two-column marketplace layout so shoppers
 * get a large preview on the left and rich details on the right.
 */
(function () {
    const modal = document.getElementById('productGalleryModal');
    const mainImage = document.getElementById('productGalleryMain');
    const imageCaption = document.getElementById('productGalleryImageCaption');
    const status = document.getElementById('productGalleryStatus');
    const thumbs = document.getElementById('productGalleryThumbs');
    const closeButton = document.getElementById('productGalleryClose');
    const prevButton = document.getElementById('productGalleryPrev');
    const nextButton = document.getElementById('productGalleryNext');
    const brandField = document.getElementById('productGalleryBrand');
    const titleField = document.getElementById('productGalleryTitle');
    const priceField = document.getElementById('productGalleryPrice');
    const categoryField = document.getElementById('productGalleryCategory');
    const stockField = document.getElementById('productGalleryStock');
    const descriptionField = document.getElementById('productGalleryDescription');
    // Added: references to the modal's quantity and CTA controls so we can mirror cart behaviour.
    const quantityInput = document.getElementById('productGalleryQuantity');
    const buyButton = document.getElementById('productGalleryBuyButton');
    const cartButton = document.getElementById('productGalleryCartButton');
    const variantContainer = document.getElementById('productGalleryVariants');
    const variantList = document.getElementById('productGalleryVariantList');
    const productsGrid = document.querySelector('.products-grid');
    const pathConfig = window.dgzPaths || {};
    const productImagesEndpoint = (typeof pathConfig.productImages === 'string' && pathConfig.productImages !== '')
        ? pathConfig.productImages
        : 'api/product-images.php';
    const productPlaceholder = (typeof pathConfig.productPlaceholder === 'string' && pathConfig.productPlaceholder !== '')
        ? pathConfig.productPlaceholder
        : '../assets/img/product-placeholder.svg';

    if (!modal || !mainImage || !imageCaption || !thumbs || !closeButton) {
        return;
    }

    const DEFAULT_MODAL_OPTIONS = Object.freeze({
        hideBuyButton: false,
        forceVariantSelection: false,
        focusVariantSelector: false,
        presetQuantity: null,
    });

    let currentModalOptions = { ...DEFAULT_MODAL_OPTIONS };

    const state = {
        productId: null,
        productName: '',
        brand: '',
        categoryLabel: '',
        price: '',
        quantity: null,
        description: '',
        images: [],
        index: 0,
        variants: [],
        selectedVariant: null,
        pendingQuantity: null,
    };

    let lastActiveElement = null;

    function resetModalOptions() {
        currentModalOptions = { ...DEFAULT_MODAL_OPTIONS };
        if (buyButton) {
            buyButton.hidden = false;
        }
    }

    function applyModalOptions() {
        if (buyButton) {
            buyButton.hidden = Boolean(currentModalOptions.hideBuyButton);
        }
    }

    function setAriaHidden(isHidden) {
        modal.setAttribute('aria-hidden', String(isHidden));
        document.body.classList.toggle('modal-open', !isHidden);
    }

    function isModalOpen() {
        return modal.getAttribute('aria-hidden') === 'false';
    }

    function formatCurrency(value) {
        const numeric = Number(value);
        if (Number.isFinite(numeric)) {
            return numeric.toLocaleString('en-PH', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            });
        }
        return value;
    }

    function normaliseQuantityField(options = {}) {
        const { allowEmpty = false, suppressAlert = false } = options;

        if (!quantityInput || quantityInput.disabled) {
            return 0;
        }

        const rawValue = quantityInput.value.trim();
        const hasKnownStock = typeof state.quantity === 'number';
        const maxQuantity = hasKnownStock ? Math.max(1, state.quantity) : Number.POSITIVE_INFINITY;

        if (rawValue === '') {
            if (allowEmpty) {
                return 0;
            }

            const previous = Number.parseInt(quantityInput.dataset.previousValidValue || '', 10);
            const fallback = Number.isInteger(previous) && previous >= 1
                ? Math.min(previous, maxQuantity)
                : 1;
            quantityInput.value = String(fallback);
            quantityInput.dataset.previousValidValue = String(fallback);
            return fallback;
        }

        let desired = Number.parseInt(rawValue, 10);

        if (Number.isNaN(desired) || desired < 1) {
            desired = 1;
        }

        if (hasKnownStock && desired > maxQuantity) {
            if (!suppressAlert) {
                alert(`Only ${maxQuantity} stock available.`);
            }

            const previous = Number.parseInt(quantityInput.dataset.previousValidValue || '', 10);
            const fallback = Number.isInteger(previous) && previous >= 1
                ? Math.min(previous, maxQuantity)
                : maxQuantity;
            desired = fallback;
        }

        quantityInput.value = String(desired);
        quantityInput.dataset.previousValidValue = String(desired);
        return desired;
    }

    function updatePurchaseControls() {
        if (!quantityInput || !buyButton || !cartButton) {
            return;
        }

        const hasKnownStock = typeof state.quantity === 'number';
        const requiresVariant = Array.isArray(state.variants) && state.variants.length > 0;
        const variantSelectionRequired = requiresVariant && !state.selectedVariant;
        const hasStockAvailable = !hasKnownStock || state.quantity > 0;
        const canPurchase = !variantSelectionRequired && hasStockAvailable;

        buyButton.disabled = !canPurchase;
        cartButton.disabled = !canPurchase;

        if (canPurchase) {
            quantityInput.disabled = false;
            quantityInput.placeholder = '';
            quantityInput.min = '1';
            if (hasKnownStock) {
                const max = Math.max(1, state.quantity);
                quantityInput.max = String(max);
                if (state.pendingQuantity !== null) {
                    let desired = Number(state.pendingQuantity);
                    if (!Number.isFinite(desired) || desired < 1) {
                        desired = 1;
                    }
                    desired = Math.min(desired, max);
                    quantityInput.value = String(desired);
                    quantityInput.dataset.previousValidValue = String(desired);
                    state.pendingQuantity = null;
                }
            } else {
                if (state.pendingQuantity !== null) {
                    let desired = Number(state.pendingQuantity);
                    if (!Number.isFinite(desired) || desired < 1) {
                        desired = 1;
                    }
                    quantityInput.value = String(desired);
                    quantityInput.dataset.previousValidValue = String(desired);
                    state.pendingQuantity = null;
                }
                quantityInput.removeAttribute('max');
            }
            normaliseQuantityField({ suppressAlert: true });
        } else {
            const fallbackQuantity = Number.isFinite(Number(state.pendingQuantity))
                ? Math.max(1, Number(state.pendingQuantity))
                : 1;
            if (variantSelectionRequired) {
                quantityInput.value = '';
                quantityInput.placeholder = 'Select variant';
                quantityInput.dataset.previousValidValue = String(fallbackQuantity);
            } else {
                quantityInput.value = '0';
                quantityInput.placeholder = '';
                quantityInput.dataset.previousValidValue = '0';
            }
            quantityInput.setAttribute('disabled', 'disabled');
            quantityInput.removeAttribute('max');
        }
    }

    function updateDetails() {
        const brandLabel = (state.brand || '').trim();
        brandField.textContent = brandLabel ? `Brand: ${brandLabel}` : 'Brand: Unspecified';

        const productTitle = (state.productName || '').trim();
        titleField.textContent = productTitle || 'Product details';

        priceField.textContent = state.price ? `₱${formatCurrency(state.price)}` : '₱0.00';

        const categoryLabel = (state.categoryLabel || 'Other').trim();
        categoryField.textContent = `Category: ${categoryLabel || 'Other'}`;

        if (typeof state.quantity === 'number') {
            stockField.textContent = state.quantity > 0
                ? `Stock: ${state.quantity} available`
                : 'Stock: Out of stock';
        } else {
            stockField.textContent = 'Stock: Check availability';
        }

        const descriptionCopy = (state.description || '').trim();
        descriptionField.textContent = descriptionCopy || 'No description provided yet.';

        updatePurchaseControls();
    }

    function updateStage() {
        if (!state.images.length) {
            return;
        }

        const current = state.images[state.index];
        mainImage.src = current.url;
        mainImage.alt = `${state.productName} photo ${state.index + 1}`;
        imageCaption.textContent = state.productName;
        status.textContent = `${state.index + 1} of ${state.images.length}`;

        Array.from(thumbs.children).forEach((btn, idx) => {
            if (idx === state.index) {
                btn.setAttribute('aria-current', 'true');
            } else {
                btn.removeAttribute('aria-current');
            }
        });
    }

    function renderThumbnails() {
        thumbs.innerHTML = '';
        state.images.forEach((item, idx) => {
            const thumbButton = document.createElement('button');
            thumbButton.type = 'button';
            thumbButton.setAttribute('role', 'listitem');
            thumbButton.setAttribute('aria-label', `${state.productName} photo ${idx + 1}`);

            const img = document.createElement('img');
            img.src = item.url;
            img.alt = `${state.productName} thumbnail ${idx + 1}`;

            thumbButton.appendChild(img);
            thumbButton.addEventListener('click', () => {
                state.index = idx;
                updateStage();
            });

            thumbs.appendChild(thumbButton);
        });
    }

    function renderVariantOptions() {
        if (!variantContainer || !variantList) {
            return;
        }

        variantList.innerHTML = '';
        const variants = Array.isArray(state.variants) ? state.variants : [];
        if (!variants.length) {
            variantContainer.hidden = true;
            return;
        }

        variantContainer.hidden = false;

        variants.forEach((variant) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'product-gallery-variant-option';

            const priceText = Number.isFinite(Number(variant.price)) ? `₱${formatCurrency(variant.price)}` : '';
            let stockText = '';
            if (typeof variant.quantity === 'number') {
                stockText = variant.quantity > 0 ? `${variant.quantity} in stock` : 'Out of stock';
            }

            const labelParts = [variant.label];
            if (priceText) {
                labelParts.push(priceText);
            }
            if (stockText) {
                labelParts.push(stockText);
            }
            button.textContent = labelParts.join(' • ');

            const isSelected = state.selectedVariant && Number(state.selectedVariant.id) === Number(variant.id);
            button.setAttribute('aria-pressed', isSelected ? 'true' : 'false');

            if (typeof variant.quantity === 'number' && variant.quantity <= 0) {
                button.disabled = true;
            }

            button.addEventListener('click', () => {
                if (button.disabled) {
                    return;
                }
                selectVariant(variant);
            });

            variantList.appendChild(button);
        });
    }

    function selectVariant(variant) {
        state.selectedVariant = variant || null;
        if (variant) {
            if (variant.price !== undefined && variant.price !== null) {
                state.price = Number(variant.price);
            }
            if (variant.quantity !== undefined && variant.quantity !== null) {
                state.quantity = Number(variant.quantity);
            } else {
                state.quantity = null;
            }
        }
        renderVariantOptions();
        updateDetails();
    }

    function normaliseImages(productName, payloadImages, primaryImage) {
        const seen = new Set();
        const result = [];

        const addImage = (url) => {
            if (!url || seen.has(url)) {
                return;
            }
            seen.add(url);
            result.push({ url });
        };

        addImage(primaryImage);

        if (Array.isArray(payloadImages)) {
            payloadImages.forEach((entry) => {
                if (entry && typeof entry.url === 'string') {
                    addImage(entry.url);
                } else if (typeof entry === 'string') {
                    addImage(entry);
                }
            });
        }

        if (!result.length) {
            addImage(productPlaceholder);
        }

        return result;
    }

    function parseVariantsPayload(raw) {
        if (!raw) {
            return [];
        }

        let parsed;
        try {
            parsed = JSON.parse(raw);
        } catch (error) {
            return [];
        }

        if (!Array.isArray(parsed)) {
            return [];
        }

        return parsed
            .map((variant) => {
                const id = variant && variant.id !== undefined ? Number(variant.id) : null;
                const label = variant && typeof variant.label === 'string' ? variant.label.trim() : '';
                if (!label) {
                    return null;
                }

                return {
                    id: Number.isFinite(id) ? id : null,
                    label,
                    price: variant && variant.price !== undefined ? Number(variant.price) : 0,
                    quantity: variant && variant.quantity !== undefined && variant.quantity !== null
                        ? Number(variant.quantity)
                        : null,
                    is_default: Boolean(variant && variant.is_default),
                };
            })
            .filter(Boolean);
    }

    function pickInitialVariant(variants, defaultId) {
        if (!Array.isArray(variants) || !variants.length) {
            return null;
        }

        if (defaultId !== undefined && defaultId !== null) {
            const numericId = Number(defaultId);
            const byId = variants.find((variant) => Number(variant.id) === numericId);
            if (byId) {
                return byId;
            }
        }

        const flaggedDefault = variants.find((variant) => variant.is_default);
        if (flaggedDefault) {
            return flaggedDefault;
        }

        const availableVariant = variants.find((variant) => {
            if (variant.quantity === null || variant.quantity === undefined) {
                return true;
            }
            return Number(variant.quantity) > 0;
        });
        if (availableVariant) {
            return availableVariant;
        }

        return variants[0];
    }

    function capturePendingQuantity() {
        if (!quantityInput) {
            return null;
        }

        const previous = Number.parseInt(quantityInput.dataset.previousValidValue || '', 10);
        if (Number.isInteger(previous) && previous > 0) {
            return previous;
        }

        const current = Number.parseInt(quantityInput.value || '', 10);
        if (Number.isInteger(current) && current > 0) {
            return current;
        }

        return null;
    }

    function synchroniseModalWithCard(card, detail = {}) {
        if (!isModalOpen()) {
            return;
        }

        if (!(card instanceof HTMLElement)) {
            return;
        }

        const productIdAttr = card.dataset.productId || '';
        if (!productIdAttr || String(productIdAttr) !== String(state.productId)) {
            return;
        }

        const variantsPayload = Array.isArray(detail.variants)
            ? parseVariantsPayload(JSON.stringify(detail.variants))
            : parseVariantsPayload(card.dataset.productVariants || '[]');

        const defaultVariantIdSource = detail.defaultVariantId ?? card.dataset.productDefaultVariantId ?? null;
        const defaultVariantId = defaultVariantIdSource !== null && defaultVariantIdSource !== ''
            ? Number(defaultVariantIdSource)
            : null;

        const previousSelectedId = state.selectedVariant && state.selectedVariant.id !== undefined
            ? Number(state.selectedVariant.id)
            : null;

        state.pendingQuantity = capturePendingQuantity();
        state.variants = variantsPayload;

        let nextSelectedVariant = null;
        if (Number.isFinite(previousSelectedId)) {
            nextSelectedVariant = variantsPayload.find((variant) => Number(variant?.id) === previousSelectedId) || null;
        }

        if (!nextSelectedVariant) {
            nextSelectedVariant = pickInitialVariant(variantsPayload, defaultVariantId);
        }

        state.selectedVariant = nextSelectedVariant || null;

        const detailHasPrice = typeof detail.price === 'number' && Number.isFinite(detail.price);
        const cardPrice = Number(card.dataset.productPrice);
        const resolvedPrice = detailHasPrice
            ? detail.price
            : (Number.isFinite(cardPrice) ? cardPrice : state.price);

        const detailHasQuantity = typeof detail.quantity === 'number' && Number.isFinite(detail.quantity);
        const cardQuantity = Number.parseInt(card.dataset.productQuantity || '', 10);
        const resolvedQuantity = detailHasQuantity
            ? detail.quantity
            : (Number.isNaN(cardQuantity) ? null : cardQuantity);

        if (state.selectedVariant) {
            if (state.selectedVariant.price !== undefined && state.selectedVariant.price !== null
                && Number.isFinite(Number(state.selectedVariant.price))) {
                state.price = Number(state.selectedVariant.price);
            } else {
                state.price = resolvedPrice;
            }

            if (state.selectedVariant.quantity !== undefined && state.selectedVariant.quantity !== null
                && Number.isFinite(Number(state.selectedVariant.quantity))) {
                state.quantity = Number(state.selectedVariant.quantity);
            } else if (resolvedQuantity !== null) {
                state.quantity = resolvedQuantity;
            } else {
                state.quantity = null;
            }
        } else {
            state.price = resolvedPrice;
            state.quantity = resolvedQuantity;
        }

        renderVariantOptions();
        updateDetails();
    }

    function fetchGallery(productId, productName, primaryImage) {
        const separator = productImagesEndpoint.includes('?') ? '&' : '?';
        fetch(`${productImagesEndpoint}${separator}product_id=${encodeURIComponent(productId)}`)
            .then((response) => (response.ok ? response.json() : Promise.reject(new Error('Unable to load images'))))
            .then((data) => {
                const payloadImages = Array.isArray(data?.images) ? data.images : [];
                state.images = normaliseImages(productName, payloadImages, primaryImage);
                state.index = 0;
                renderThumbnails();
                updateStage();
            })
            .catch(() => {
                status.textContent = 'Showing available product photo.';
            });
    }

    function openModalFromCard(card, options = {}) {
        const productId = card.dataset.productId;
        const productName = card.dataset.productName || 'Product photo';
        const primaryImage = card.dataset.primaryImage || productPlaceholder;

        if (!productId) {
            return;
        }

        lastActiveElement = document.activeElement instanceof HTMLElement ? document.activeElement : null;

        currentModalOptions = { ...DEFAULT_MODAL_OPTIONS, ...(options || {}) };
        applyModalOptions();

        const initialQuantity = (() => {
            const preset = Number(currentModalOptions.presetQuantity);
            if (Number.isFinite(preset) && preset > 0) {
                return preset;
            }
            const existing = quantityInput
                ? Number.parseInt(quantityInput.dataset.previousValidValue || quantityInput.value, 10)
                : NaN;
            if (Number.isFinite(existing) && existing > 0) {
                return existing;
            }
            return 1;
        })();

        state.pendingQuantity = initialQuantity;

        state.productId = productId;
        state.productName = productName.trim() || 'Product photo';
        state.brand = (card.dataset.productBrand || '').trim();
        state.categoryLabel = (card.dataset.productCategoryLabel || '').trim();
        state.price = Number(card.dataset.productPrice || 0);
        const parsedQuantity = Number.parseInt(card.dataset.productQuantity, 10);
        state.quantity = Number.isNaN(parsedQuantity) ? null : parsedQuantity;
        state.description = card.dataset.productDescription || '';
        state.index = 0;
        state.images = normaliseImages(productName, [], primaryImage);
        state.variants = parseVariantsPayload(card.dataset.productVariants || '[]');
        const defaultVariantIdAttr = card.dataset.productDefaultVariantId;
        const defaultVariantId = defaultVariantIdAttr !== undefined && defaultVariantIdAttr !== ''
            ? Number(defaultVariantIdAttr)
            : null;
        state.selectedVariant = pickInitialVariant(state.variants, defaultVariantId);
        if (currentModalOptions.forceVariantSelection && state.variants.length > 0) {
            state.selectedVariant = null;
            state.quantity = null;
        }
        if (state.selectedVariant) {
            if (state.selectedVariant.price !== undefined && state.selectedVariant.price !== null) {
                state.price = Number(state.selectedVariant.price);
            }
            if (state.selectedVariant.quantity !== undefined && state.selectedVariant.quantity !== null) {
                state.quantity = Number(state.selectedVariant.quantity);
            } else {
                state.quantity = null;
            }
        }
        renderVariantOptions();

        updateDetails();
        renderThumbnails();
        updateStage();
        setAriaHidden(false);
        const shouldFocusVariant = Boolean(currentModalOptions.focusVariantSelector);
        if (shouldFocusVariant && variantList) {
            const firstVariant = variantList.querySelector('button:not(:disabled)');
            if (firstVariant) {
                firstVariant.focus();
            } else {
                modal.focus();
            }
        } else {
            modal.focus();
        }

        fetchGallery(productId, productName, primaryImage);
    }

    function closeModal() {
        setAriaHidden(true);
        state.productId = null;
        state.productName = '';
        state.images = [];
        state.index = 0;
        status.textContent = '';
        thumbs.innerHTML = '';
        state.variants = [];
        state.selectedVariant = null;
        state.pendingQuantity = null;
        if (variantList) {
            variantList.innerHTML = '';
        }
        if (variantContainer) {
            variantContainer.hidden = true;
        }

        if (quantityInput) {
            quantityInput.value = '1';
            quantityInput.disabled = false;
            quantityInput.removeAttribute('max');
            quantityInput.dataset.previousValidValue = '1';
            quantityInput.placeholder = '';
        }

        if (buyButton) {
            buyButton.disabled = false;
            buyButton.hidden = false;
        }

        if (cartButton) {
            cartButton.disabled = false;
        }

        resetModalOptions();

        if (lastActiveElement) {
            lastActiveElement.focus({ preventScroll: true });
        }
    }

    // Added: open the modal when shoppers click anywhere on the card except
    // inputs/buttons marked with data-gallery-ignore.
    productsGrid?.addEventListener('click', (event) => {
        if (event.target.closest('[data-gallery-ignore]')) {
            return;
        }
        const card = event.target.closest('.product-card');
        if (!card) {
            return;
        }
        openModalFromCard(card);
    });

    productsGrid?.addEventListener('keydown', (event) => {
        if (event.target instanceof HTMLElement && event.target.classList.contains('product-card')) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                openModalFromCard(event.target);
            }
        }
    });

    closeButton.addEventListener('click', closeModal);

    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            closeModal();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (modal.getAttribute('aria-hidden') === 'true') {
            return;
        }
        if (event.key === 'Escape') {
            closeModal();
        }
        if (event.key === 'ArrowRight') {
            event.preventDefault();
            if (state.images.length) {
                state.index = (state.index + 1) % state.images.length;
                updateStage();
            }
        }
        if (event.key === 'ArrowLeft') {
            event.preventDefault();
            if (state.images.length) {
                state.index = (state.index - 1 + state.images.length) % state.images.length;
                updateStage();
            }
        }
    });

    prevButton?.addEventListener('click', () => {
        if (!state.images.length) {
            return;
        }
        state.index = (state.index - 1 + state.images.length) % state.images.length;
        updateStage();
    });

    nextButton?.addEventListener('click', () => {
        if (!state.images.length) {
            return;
        }
        state.index = (state.index + 1) % state.images.length;
        updateStage();
    });

    quantityInput?.addEventListener('focusin', () => {
        if (!quantityInput) {
            return;
        }

        const min = Number.parseInt(quantityInput.min, 10) || 1;
        const current = Number.parseInt(quantityInput.value, 10);
        const safeValue = Number.isFinite(current) && current >= min ? current : min;
        quantityInput.dataset.previousValidValue = String(safeValue);
    });

    quantityInput?.addEventListener('input', () => {
        if (!quantityInput) {
            return;
        }

        const rawValue = quantityInput.value.trim();
        if (rawValue === '') {
            return;
        }

        let value = Number.parseInt(rawValue, 10);
        if (!Number.isFinite(value)) {
            value = 1;
        }

        if (value < 1) {
            value = 1;
            quantityInput.value = '1';
        }

        const hasKnownStock = typeof state.quantity === 'number';
        const maxQuantity = hasKnownStock ? Math.max(1, state.quantity) : null;
        if (maxQuantity !== null && value <= maxQuantity) {
            quantityInput.dataset.previousValidValue = String(value);
        }
    });

    quantityInput?.addEventListener('focusout', () => {
        normaliseQuantityField();
    });

    // Added: mirror the card-level Buy Now/Add to Cart behaviour within the modal controls.
    buyButton?.addEventListener('click', () => {
        if (buyButton.disabled) {
            return;
        }

        const productId = Number.parseInt(state.productId, 10);
        if (!Number.isInteger(productId) || productId <= 0) {
            return;
        }

        const qty = normaliseQuantityField();
        const price = Number(state.price || 0);
        const variantId = state.selectedVariant ? state.selectedVariant.id : null;
        const variantLabel = state.selectedVariant ? state.selectedVariant.label : '';
        const variantPrice = state.selectedVariant && state.selectedVariant.price !== undefined
            ? Number(state.selectedVariant.price)
            : price;

        if (typeof window.buyNow === 'function') {
            window.buyNow(productId, state.productName, price, qty, variantId, variantLabel, variantPrice);
        }
    });

    cartButton?.addEventListener('click', () => {
        if (cartButton.disabled) {
            return;
        }

        const productId = Number.parseInt(state.productId, 10);
        if (!Number.isInteger(productId) || productId <= 0) {
            return;
        }

        const qty = normaliseQuantityField();
        const price = Number(state.price || 0);
        const variantId = state.selectedVariant ? state.selectedVariant.id : null;
        const variantLabel = state.selectedVariant ? state.selectedVariant.label : '';
        const variantPrice = state.selectedVariant && state.selectedVariant.price !== undefined
            ? Number(state.selectedVariant.price)
            : price;

        if (typeof window.addToCart === 'function') {
            window.addToCart(productId, state.productName, price, qty, variantId, variantLabel, variantPrice);
        }
    });

    window.openProductModalFromCard = function openProductModalFromCardPublic(cardElement, options) {
        const targetCard = cardElement instanceof HTMLElement
            ? cardElement
            : (typeof cardElement === 'string' ? document.querySelector(cardElement) : null);
        if (!targetCard) {
            return;
        }
        openModalFromCard(targetCard, options);
    };

    document.addEventListener('dgz:inventory-updated', (event) => {
        const payload = event?.detail && typeof event.detail === 'object' ? event.detail : {};
        const target = event.target instanceof HTMLElement
            ? event.target.closest('.product-card')
            : null;
        if (!target) {
            return;
        }
        synchroniseModalWithCard(target, payload);
    });
})();
