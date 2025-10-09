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
    const productsGrid = document.querySelector('.products-grid');

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
            addImage('../assets/img/product-placeholder.svg');
        }

        return result;
    }

    function fetchGallery(productId, productName, primaryImage) {
        fetch(`api/product-images.php?product_id=${encodeURIComponent(productId)}`)
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
        const primaryImage = card.dataset.primaryImage || '../assets/img/product-placeholder.svg';

        if (!productId) {
            return;
        }

        lastActiveElement = document.activeElement instanceof HTMLElement ? document.activeElement : null;

        state.productId = productId;
        state.productName = productName.trim() || 'Product photo';
        state.brand = (card.dataset.productBrand || '').trim();
        state.categoryLabel = (card.dataset.productCategoryLabel || '').trim();
        state.price = card.dataset.productPrice || '';
        const parsedQuantity = Number.parseInt(card.dataset.productQuantity, 10);
        state.quantity = Number.isNaN(parsedQuantity) ? null : parsedQuantity;
        state.description = card.dataset.productDescription || '';
        state.index = 0;
        state.images = normaliseImages(productName, [], primaryImage);

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
})();
