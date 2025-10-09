/**
 * Added: Front-end controller that powers the product gallery modal on the
 * public storefront. It fetches the selected product's photo set and renders
 * them inside an accessible carousel-like experience.
 */
(function () {
    const modal = document.getElementById('productGalleryModal');
    const mainImage = document.getElementById('productGalleryMain');
    const caption = document.getElementById('productGalleryTitle');
    const status = document.getElementById('productGalleryStatus');
    const thumbs = document.getElementById('productGalleryThumbs');
    const closeButton = document.getElementById('productGalleryClose');
    const prevButton = document.getElementById('productGalleryPrev');
    const nextButton = document.getElementById('productGalleryNext');

    if (!modal || !mainImage || !caption || !thumbs || !closeButton) {
        return;
    }

    const state = {
        productId: null,
        productName: '',
        images: [],
        index: 0,
    };

    function setAriaHidden(isHidden) {
        modal.setAttribute('aria-hidden', String(isHidden));
        document.body.classList.toggle('modal-open', !isHidden);
    }

    function updateStage() {
        if (!state.images.length) {
            return;
        }

        const current = state.images[state.index];
        mainImage.src = current.url;
        mainImage.alt = `${state.productName} photo ${state.index + 1}`;
        caption.textContent = state.productName;
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

    function openModal(productId, productName, primaryImage) {
        state.productId = productId;
        state.productName = productName;
        state.index = 0;
        state.images = normaliseImages(productName, [], primaryImage);

        renderThumbnails();
        updateStage();
        setAriaHidden(false);
        modal.focus();

        fetch(`api/product-images.php?product_id=${encodeURIComponent(productId)}`)
            .then((response) => response.ok ? response.json() : Promise.reject(new Error('Unable to load images')))
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

    function closeModal() {
        setAriaHidden(true);
        state.productId = null;
        state.productName = '';
        state.images = [];
        state.index = 0;
    }

    document.querySelectorAll('[data-product-gallery-trigger]').forEach((trigger) => {
        trigger.addEventListener('click', (event) => {
            const card = event.currentTarget.closest('.product-card');
            if (!card) {
                return;
            }
            const productId = card.dataset.productId;
            const productName = card.dataset.productName || 'Product photo';
            const primaryImage = trigger.getAttribute('data-primary-image') || '../assets/img/product-placeholder.svg';
            if (!productId) {
                return;
            }
            openModal(productId, productName, primaryImage);
        });
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
