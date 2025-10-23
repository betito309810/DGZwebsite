(function () {
    const focusableSelector = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

    function getFocusableElements(container) {
        if (!container) {
            return [];
        }
        return Array.from(container.querySelectorAll(focusableSelector)).filter(function (element) {
            return element.offsetParent !== null || container === element;
        });
    }

    function trapFocus(event, container) {
        if (event.key !== 'Tab' || !container) {
            return;
        }
        const focusable = getFocusableElements(container);
        if (!focusable.length) {
            event.preventDefault();
            return;
        }
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        const active = document.activeElement;

        if (event.shiftKey && active === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && active === last) {
            event.preventDefault();
            first.focus();
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        const filterToggle = document.getElementById('mobileFilterToggle');
        const sidebar = document.getElementById('categorySidebar');
        const sidebarClose = document.getElementById('sidebarCloseButton');
        const backdrop = document.getElementById('sidebarBackdrop');
        const sortToggle = document.getElementById('mobileSortToggle');
        const sortValue = document.getElementById('mobileSortValue');
        const sortSheet = document.getElementById('sortSheet');
        const sortClose = document.getElementById('sortSheetClose');
        const sortOptions = sortSheet ? Array.from(sortSheet.querySelectorAll('.sort-option')) : [];
        const desktopSortSelect = document.getElementById('desktopSortSelect');
        const productsGrid = document.querySelector('.products-grid');
        const categoryLinks = document.querySelectorAll('.category-link');

        if (!filterToggle && !sortToggle && !desktopSortSelect) {
            return;
        }

        const originalOrder = [];
        if (productsGrid) {
            Array.from(productsGrid.children).forEach(function (card, index) {
                if (!card.dataset.originalIndex) {
                    card.dataset.originalIndex = String(index);
                }
                originalOrder.push(card);
            });
        }

        let lastFocused = null;
        let lastSortFocused = null;
        let sidebarOpen = false;
        let sortOpen = false;
        let currentSort = 'recommended';

        function setAriaHiddenForSidebar(hidden) {
            if (!sidebar) {
                return;
            }
            if (hidden) {
                sidebar.setAttribute('aria-hidden', 'true');
            } else {
                sidebar.removeAttribute('aria-hidden');
            }
        }

        function openSidebar() {
            if (!sidebar) {
                return;
            }
            sidebar.classList.add('is-open');
            setAriaHiddenForSidebar(false);
            if (backdrop) {
                backdrop.hidden = false;
                backdrop.classList.add('is-active');
            }
            document.body.classList.add('sidebar-open');
            sidebarOpen = true;
            if (filterToggle) {
                filterToggle.setAttribute('aria-expanded', 'true');
            }
            const focusables = getFocusableElements(sidebar);
            const target = focusables.find(function (el) {
                return el.classList && el.classList.contains('category-link');
            }) || focusables[0];
            if (target) {
                target.focus();
            }
        }

        function closeSidebar(restoreFocus) {
            if (!sidebarOpen || !sidebar) {
                return;
            }
            sidebar.classList.remove('is-open');
            setAriaHiddenForSidebar(true);
            if (backdrop) {
                backdrop.classList.remove('is-active');
                backdrop.hidden = true;
            }
            document.body.classList.remove('sidebar-open');
            sidebarOpen = false;
            if (filterToggle) {
                filterToggle.setAttribute('aria-expanded', 'false');
            }
            const focusTarget = restoreFocus || lastFocused || filterToggle;
            if (focusTarget && typeof focusTarget.focus === 'function') {
                focusTarget.focus();
            }
        }

        function openSortSheet() {
            if (!sortSheet) {
                return;
            }
            sortSheet.hidden = false;
            requestAnimationFrame(function () {
                sortSheet.classList.add('is-open');
            });
            document.body.classList.add('sort-sheet-open');
            sortOpen = true;
            if (sortToggle) {
                sortToggle.setAttribute('aria-expanded', 'true');
            }
            const active = sortOptions.find(function (option) {
                return option.classList.contains('is-active');
            }) || sortOptions[0];
            if (active) {
                active.focus();
            }
        }

        function closeSortSheet(restoreFocus) {
            if (!sortOpen || !sortSheet) {
                return;
            }
            sortSheet.classList.remove('is-open');
            document.body.classList.remove('sort-sheet-open');
            sortOpen = false;
            if (sortToggle) {
                sortToggle.setAttribute('aria-expanded', 'false');
            }
            window.setTimeout(function () {
                if (sortSheet) {
                    sortSheet.hidden = true;
                }
            }, 200);
            const focusTarget = restoreFocus || lastSortFocused || sortToggle;
            if (focusTarget && typeof focusTarget.focus === 'function') {
                focusTarget.focus();
            }
        }

        function applySort(mode, trigger) {
            if (!productsGrid || currentSort === mode) {
                currentSort = mode;
                updateSortButtonLabel(trigger, mode);
                return;
            }
            const cards = Array.from(productsGrid.children);
            var sorted;
            if (mode === 'price-asc') {
                sorted = cards.sort(function (a, b) {
                    return parseFloat(a.dataset.productPrice || '0') - parseFloat(b.dataset.productPrice || '0');
                });
            } else if (mode === 'price-desc') {
                sorted = cards.sort(function (a, b) {
                    return parseFloat(b.dataset.productPrice || '0') - parseFloat(a.dataset.productPrice || '0');
                });
            } else if (mode === 'newest') {
                sorted = cards.sort(function (a, b) {
                    var createdB = parseInt(b.dataset.productCreated || '0', 10);
                    var createdA = parseInt(a.dataset.productCreated || '0', 10);
                    if (isNaN(createdB)) {
                        createdB = 0;
                    }
                    if (isNaN(createdA)) {
                        createdA = 0;
                    }
                    return createdB - createdA;
                });
            } else if (mode === 'name-asc') {
                sorted = cards.sort(function (a, b) {
                    return (a.dataset.productName || '').localeCompare(b.dataset.productName || '');
                });
            } else {
                sorted = originalOrder.slice();
            }

            sorted.forEach(function (card) {
                productsGrid.appendChild(card);
            });

            currentSort = mode;
            updateSortButtonLabel(trigger, mode);
            if (typeof window.applyFilters === 'function') {
                window.applyFilters();
            }
        }

        function updateSortButtonLabel(trigger, mode) {
            const resolvedMode = mode || (trigger && trigger.dataset ? trigger.dataset.sort : currentSort);
            let activeOption = null;
            sortOptions.forEach(function (option) {
                const isActive = option.dataset.sort === resolvedMode;
                option.classList.toggle('is-active', isActive);
                option.setAttribute('aria-pressed', String(isActive));
                if (isActive) {
                    activeOption = option;
                }
            });
            if (sortValue) {
                if (activeOption) {
                    var labelSource = activeOption;
                    var label = labelSource.dataset.shortLabel || labelSource.dataset.label || labelSource.textContent.trim();
                    sortValue.textContent = label;
                } else if (resolvedMode === 'recommended') {
                    sortValue.textContent = 'Recommended';
                }
            }
            if (desktopSortSelect) {
                const desktopOptions = Array.from(desktopSortSelect.options || []);
                const hasMatch = desktopOptions.some(function (option) {
                    return option.value === resolvedMode;
                });
                if (hasMatch && desktopSortSelect.value !== resolvedMode) {
                    desktopSortSelect.value = resolvedMode;
                }
            }
        }

        if (sortOptions.length) {
            const initialOption = sortOptions.find(function (option) {
                return option.classList.contains('is-active');
            }) || sortOptions[0];
            if (initialOption) {
                updateSortButtonLabel(initialOption, currentSort);
            }
        }

        if (filterToggle) {
            filterToggle.addEventListener('click', function () {
                lastFocused = document.activeElement;
                if (sidebarOpen) {
                    closeSidebar(filterToggle);
                } else {
                    openSidebar();
                }
            });
        }

        if (sidebarClose) {
            sidebarClose.addEventListener('click', function () {
                closeSidebar(filterToggle || sidebarClose);
            });
        }

        if (backdrop) {
            backdrop.addEventListener('click', function () {
                closeSidebar(filterToggle || backdrop);
            });
        }

        if (sortToggle) {
            sortToggle.addEventListener('click', function () {
                lastSortFocused = document.activeElement;
                if (sortOpen) {
                    closeSortSheet(sortToggle);
                } else {
                    openSortSheet();
                }
            });
        }

        if (sortClose) {
            sortClose.addEventListener('click', function () {
                closeSortSheet(sortToggle || sortClose);
            });
        }

        if (sortSheet) {
            sortSheet.addEventListener('click', function (event) {
                if (event.target === sortSheet) {
                    closeSortSheet(sortToggle || sortSheet);
                }
            });
        }

        sortOptions.forEach(function (option) {
            option.addEventListener('click', function () {
                applySort(option.dataset.sort || 'recommended', option);
                closeSortSheet(sortToggle || option);
            });
        });

        if (desktopSortSelect) {
            desktopSortSelect.addEventListener('change', function (event) {
                const selectedMode = event.target.value || 'recommended';
                const matchingOption = sortOptions.find(function (option) {
                    return option.dataset.sort === selectedMode;
                }) || null;
                applySort(selectedMode, matchingOption);
            });
        }

        categoryLinks.forEach(function (link) {
            link.addEventListener('click', function () {
                if (window.matchMedia('(max-width: 768px)').matches) {
                    closeSidebar(filterToggle || link);
                }
            });
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                if (sidebarOpen) {
                    closeSidebar(filterToggle);
                }
                if (sortOpen) {
                    closeSortSheet(sortToggle);
                }
            } else if (event.key === 'Tab') {
                if (sidebarOpen) {
                    trapFocus(event, sidebar);
                } else if (sortOpen) {
                    trapFocus(event, sortSheet);
                }
            }
        });

        const mobileQuery = window.matchMedia('(max-width: 768px)');
        function handleViewportChange(event) {
            if (!event.matches) {
                closeSidebar();
                closeSortSheet();
                setAriaHiddenForSidebar(false);
            } else if (!sidebarOpen) {
                setAriaHiddenForSidebar(true);
            }
        }

        if (mobileQuery.addEventListener) {
            mobileQuery.addEventListener('change', handleViewportChange);
        } else if (mobileQuery.addListener) {
            mobileQuery.addListener(handleViewportChange);
        }

        handleViewportChange(mobileQuery);
    });
})();
