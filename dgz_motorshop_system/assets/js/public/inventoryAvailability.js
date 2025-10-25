(function () {
    'use strict';

    const dgzPaths = window.dgzPaths || {};
    const inventoryUrl = typeof dgzPaths.inventoryAvailability === 'string'
        ? dgzPaths.inventoryAvailability
        : '';

    const REFRESH_INTERVAL_MS = 30000;
    const MAX_BATCH_SIZE = 45;

    if (!inventoryUrl) {
        return;
    }

    const inventoryState = new Map();
    let refreshTimer = null;
    let pendingRequest = null;
    let hasInitialised = false;

    function getProductCards() {
        return Array.from(document.querySelectorAll('.product-card'));
    }

    function ensureCardsPresent(cards) {
        if (Array.isArray(cards) && cards.length > 0) {
            return true;
        }

        const discovered = getProductCards();
        return discovered.length > 0;
    }

    function parseVariantsFromCard(card) {
        if (!card) {
            return [];
        }

        const raw = card.dataset.productVariants || '[]';
        try {
            const parsed = JSON.parse(raw);
            return Array.isArray(parsed) ? parsed.slice() : [];
        } catch (error) {
            return [];
        }
    }

    function chunkArray(items, size) {
        const chunks = [];
        for (let index = 0; index < items.length; index += size) {
            chunks.push(items.slice(index, index + size));
        }
        return chunks;
    }

    function gatherRequestItems(cards) {
        const seen = new Set();
        const requestItems = [];

        cards.forEach((card) => {
            const productId = Number(card.dataset.productId);
            if (!Number.isFinite(productId) || productId <= 0) {
                return;
            }

            const variants = parseVariantsFromCard(card);
            if (variants.length === 0) {
                const key = `${productId}:base`;
                if (!seen.has(key)) {
                    seen.add(key);
                    requestItems.push({ product_id: productId, variant_id: null });
                }
                return;
            }

            variants.forEach((variant) => {
                const variantId = Number(variant?.id ?? null);
                if (!Number.isFinite(variantId) || variantId <= 0) {
                    return;
                }
                const key = `${productId}:${variantId}`;
                if (seen.has(key)) {
                    return;
                }
                seen.add(key);
                requestItems.push({ product_id: productId, variant_id: variantId });
            });
        });

        return requestItems;
    }

    function normaliseStock(value) {
        const numeric = Number(value);
        if (!Number.isFinite(numeric) || numeric < 0) {
            return 0;
        }
        return Math.floor(numeric);
    }

    function applyInventorySnapshot(entries) {
        entries.forEach((entry) => {
            const productId = Number(entry?.product_id ?? 0);
            if (!Number.isFinite(productId) || productId <= 0) {
                return;
            }

            const variantIdRaw = entry?.variant_id;
            const variantId = variantIdRaw === null || variantIdRaw === undefined
                ? null
                : Number(variantIdRaw);
            const stock = normaliseStock(entry?.stock ?? 0);

            let state = inventoryState.get(productId);
            if (!state) {
                state = { base: null, variants: new Map() };
                inventoryState.set(productId, state);
            }

            if (variantId === null || !Number.isFinite(variantId)) {
                state.base = stock;
            } else {
                state.variants.set(variantId, stock);
            }
        });

        updateAllCards();
    }

    function pickDefaultVariant(variants) {
        if (!Array.isArray(variants) || variants.length === 0) {
            return null;
        }

        let preferred = variants.find((variant) => Number(variant?.is_default ?? 0) === 1) || variants[0];
        if (preferred && Number(preferred.quantity ?? 0) <= 0) {
            const fallback = variants.find((variant) => Number(variant?.quantity ?? 0) > 0);
            if (fallback) {
                preferred = fallback;
            }
        }

        return preferred || null;
    }

    function formatCurrency(value) {
        const numeric = Number(value);
        if (!Number.isFinite(numeric)) {
            return '0.00';
        }

        try {
            return numeric.toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            });
        } catch (error) {
            return numeric.toFixed(2);
        }
    }

    function updateDefaultVariantData(card, variant) {
        if (!card) {
            return;
        }

        if (!variant) {
            card.dataset.productDefaultVariantId = '';
            card.dataset.productDefaultVariantLabel = '';
            card.dataset.productDefaultVariantPrice = '';
            card.dataset.productDefaultVariantQuantity = '';
            return;
        }

        if (variant.id !== undefined && variant.id !== null) {
            card.dataset.productDefaultVariantId = String(variant.id);
        } else {
            card.dataset.productDefaultVariantId = '';
        }

        card.dataset.productDefaultVariantLabel = variant.label ? String(variant.label) : '';

        if (variant.price !== undefined && variant.price !== null && Number.isFinite(Number(variant.price))) {
            const priceValue = Number(variant.price);
            card.dataset.productDefaultVariantPrice = priceValue.toFixed(2);
            card.dataset.productPrice = priceValue.toFixed(2);

            const priceDisplay = card.querySelector('.price');
            if (priceDisplay) {
                priceDisplay.textContent = `₱${formatCurrency(priceValue)}`;
            }
        } else {
            card.dataset.productDefaultVariantPrice = '';
        }

        if (variant.quantity !== undefined && variant.quantity !== null && Number.isFinite(Number(variant.quantity))) {
            card.dataset.productDefaultVariantQuantity = String(Math.max(0, Number(variant.quantity)));
        } else {
            card.dataset.productDefaultVariantQuantity = '';
        }
    }

    function renderStockMarkup(quantity) {
        if (quantity <= 0) {
            return '<span class="stock-status-text">Out of stock</span>';
        }

        const numericQuantity = Number(quantity);
        const parsedQuantity = Number.isFinite(numericQuantity)
            ? Math.max(0, Math.round(numericQuantity))
            : 0;
        return '<span class="stock-indicator" aria-hidden="true"></span>'
            + `<span class="stock-status-text">Stock: ${parsedQuantity}</span>`;
    }

    function updateCardStockUi(card, quantity) {
        const stockElement = card.querySelector('.stock');
        if (!stockElement) {
            return;
        }

        stockElement.dataset.stock = String(quantity);
        stockElement.classList.remove('low', 'out');
        if (quantity <= 0) {
            stockElement.classList.add('out');
        } else if (quantity <= 5) {
            stockElement.classList.add('low');
        }

        stockElement.innerHTML = renderStockMarkup(quantity);
    }

    function updateQuantityControls(card, quantity) {
        const qtyInput = card.querySelector('.qty-input');
        if (qtyInput) {
            const maxQuantity = Math.max(1, quantity);
            qtyInput.max = String(maxQuantity);

            if (quantity <= 0) {
                qtyInput.setAttribute('disabled', 'disabled');
            } else {
                qtyInput.removeAttribute('disabled');
                const currentValue = Number(qtyInput.value);
                if (!Number.isFinite(currentValue) || currentValue <= 0) {
                    qtyInput.value = '1';
                } else if (currentValue > quantity) {
                    qtyInput.value = String(quantity);
                }
            }
        }

        const addButton = card.querySelector('.add-cart-btn');
        if (addButton) {
            if (quantity <= 0) {
                addButton.setAttribute('disabled', 'disabled');
            } else {
                addButton.removeAttribute('disabled');
            }
        }
    }

    function updateCardFromState(card) {
        const productId = Number(card.dataset.productId);
        if (!Number.isFinite(productId) || productId <= 0) {
            return;
        }

        const state = inventoryState.get(productId);
        if (!state) {
            return;
        }

        const variants = parseVariantsFromCard(card);
        let aggregatedQuantity = 0;
        let updatedVariants = null;

        if (variants.length > 0) {
            updatedVariants = variants.map((variant) => {
                const variantId = Number(variant?.id ?? 0);
                let stock = Number(variant?.quantity ?? 0);
                if (Number.isFinite(variantId) && variantId > 0 && state.variants.has(variantId)) {
                    stock = state.variants.get(variantId);
                }
                return {
                    ...variant,
                    quantity: normaliseStock(stock),
                };
            });

            aggregatedQuantity = updatedVariants.reduce((total, variant) => {
                const quantity = Number(variant.quantity ?? 0);
                return total + (Number.isFinite(quantity) ? Math.max(0, quantity) : 0);
            }, 0);

            card.dataset.productVariants = JSON.stringify(updatedVariants);
            updateDefaultVariantData(card, pickDefaultVariant(updatedVariants));
        } else {
            if (state.base !== null && state.base !== undefined) {
                aggregatedQuantity = normaliseStock(state.base);
            } else if (state.variants.size > 0) {
                aggregatedQuantity = 0;
                state.variants.forEach((stock) => {
                    aggregatedQuantity += normaliseStock(stock);
                });
            } else {
                const existing = Number(card.dataset.productQuantity ?? 0);
                aggregatedQuantity = Number.isFinite(existing) ? Math.max(0, existing) : 0;
            }
        }

        aggregatedQuantity = normaliseStock(aggregatedQuantity);
        card.dataset.productQuantity = String(aggregatedQuantity);

        updateCardStockUi(card, aggregatedQuantity);
        updateQuantityControls(card, aggregatedQuantity);

        const cardPrice = Number(card.dataset.productPrice);
        const defaultVariantIdAttr = card.dataset.productDefaultVariantId;
        const defaultVariantId = defaultVariantIdAttr !== undefined && defaultVariantIdAttr !== ''
            ? Number(defaultVariantIdAttr)
            : null;

        const detail = {
            productId,
            quantity: aggregatedQuantity,
            price: Number.isFinite(cardPrice) ? cardPrice : null,
            variants: Array.isArray(updatedVariants) ? updatedVariants : null,
            defaultVariantId: Number.isFinite(defaultVariantId) ? defaultVariantId : null,
        };

        card.dispatchEvent(new CustomEvent('dgz:inventory-updated', {
            bubbles: true,
            detail,
        }));
    }

    function updateAllCards() {
        const cards = getProductCards();
        cards.forEach((card) => {
            if (card && card.isConnected) {
                updateCardFromState(card);
            }
        });
    }

    async function fetchBatch(batch) {
        const response = await fetch(inventoryUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            cache: 'no-store',
            body: JSON.stringify({ items: batch }),
        });

        if (!response.ok) {
            throw new Error(`Unexpected status ${response.status}`);
        }

        const data = await response.json();
        if (!data || !Array.isArray(data.items)) {
            return [];
        }

        return data.items;
    }

    function refreshInventory(options = {}) {
        const { force = false, cards: providedCards = null } = options;

        if (pendingRequest) {
            return;
        }

        if (!force && document.hidden) {
            return;
        }

        const cards = Array.isArray(providedCards) ? providedCards : getProductCards();
        if (cards.length === 0) {
            return;
        }

        const requestItems = gatherRequestItems(cards);
        if (requestItems.length === 0) {
            return;
        }

        const batches = chunkArray(requestItems, MAX_BATCH_SIZE);
        pendingRequest = (async () => {
            const aggregated = [];

            for (const batch of batches) {
                try {
                    const items = await fetchBatch(batch);
                    aggregated.push(...items);
                } catch (error) {
                    console.error('Unable to refresh storefront inventory availability.', error);
                    break;
                }
            }

            if (aggregated.length > 0) {
                applyInventorySnapshot(aggregated);
            }
        })()
            .finally(() => {
                pendingRequest = null;
            });
    }

    function scheduleRefresh() {
        if (refreshTimer) {
            clearInterval(refreshTimer);
        }

        refreshTimer = setInterval(() => {
            if (document.hidden) {
                return;
            }
            refreshInventory();
        }, REFRESH_INTERVAL_MS);
    }

    function initialiseWatcher() {
        if (hasInitialised) {
            return;
        }

        const cards = getProductCards();
        if (cards.length === 0) {
            return;
        }

        hasInitialised = true;
        refreshInventory({ force: true, cards });
        scheduleRefresh();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialiseWatcher, { once: true });
    } else {
        initialiseWatcher();
    }

    if (typeof MutationObserver === 'function') {
        const observerRoot = document.body || document.documentElement;
        if (observerRoot) {
            const observer = new MutationObserver((mutations) => {
                let detectedCard = false;
                mutations.forEach((mutation) => {
                    mutation.addedNodes.forEach((node) => {
                        if (detectedCard) {
                            return;
                        }

                        if (!(node instanceof HTMLElement)) {
                            return;
                        }

                        if (node.classList.contains('product-card') || node.querySelector('.product-card')) {
                            detectedCard = true;
                        }
                    });
                });

                if (!detectedCard) {
                    return;
                }

                if (!hasInitialised) {
                    initialiseWatcher();
                } else {
                    refreshInventory({ force: true });
                }
            });

            observer.observe(observerRoot, { childList: true, subtree: true });
        }
    }

    document.addEventListener('visibilitychange', () => {
        if (!document.hidden && ensureCardsPresent()) {
            refreshInventory({ force: true });
        }
    });

    window.dgzInventoryWatcher = {
        refresh: () => {
            refreshInventory({ force: true });
        },
    };
})();
