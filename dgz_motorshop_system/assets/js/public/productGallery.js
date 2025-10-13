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
    };

    let lastActiveElement = null;

    function setAriaHidden(isHidden) {
        modal.setAttribute('aria-hidden', String(isHidden));
        document.body.classList.toggle('modal-open', !isHidden);
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
        const hasVariantSelected = !requiresVariant || !!state.selectedVariant;
        const canPurchase = hasVariantSelected && (!hasKnownStock || state.quantity > 0);

        buyButton.disabled = !canPurchase;
        cartButton.disabled = !canPurchase;

        if (canPurchase) {
            quantityInput.disabled = false;
            quantityInput.min = '1';
            if (hasKnownStock) {
                quantityInput.max = String(Math.max(1, state.quantity));
            } else {
                quantityInput.removeAttribute('max');
            }
            normaliseQuantityField({ suppressAlert: true });
        } else {
            quantityInput.value = '0';
            quantityInput.setAttribute('disabled', 'disabled');
            quantityInput.removeAttribute('max');
            quantityInput.dataset.previousValidValue = '0';
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

    function openModalFromCard(card) {
        const productId = card.dataset.productId;
        const productName = card.dataset.productName || 'Product photo';
        const primaryImage = card.dataset.primaryImage || productPlaceholder;

        if (!productId) {
            return;
        }

        lastActiveElement = document.activeElement instanceof HTMLElement ? document.activeElement : null;

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
        modal.focus();

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
        }

        if (buyButton) {
            buyButton.disabled = false;
        }

        if (cartButton) {
            cartButton.disabled = false;
        }

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
})();
