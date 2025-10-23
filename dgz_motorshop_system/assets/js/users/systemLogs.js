(function () {
    function ready(callback) {
        if (document.readyState === 'complete' || document.readyState === 'interactive') {
            callback();
        } else {
            document.addEventListener('DOMContentLoaded', callback, { once: true });
        }
    }

    function debounce(fn, delay) {
        let timer = null;
        function debounced(...args) {
            clearTimeout(timer);
            timer = setTimeout(() => fn.apply(this, args), delay);
        }
        debounced.cancel = () => {
            if (timer) {
                clearTimeout(timer);
                timer = null;
            }
        };
        return debounced;
    }

    ready(function () {
        const container = document.querySelector('[data-system-logs]');

        if (!container) {
            return;
        }

        const endpoint = container.getAttribute('data-system-logs-endpoint');
        if (!endpoint) {
            return;
        }

        const rangeSelect = container.querySelector('[data-system-logs-range]');
        const searchInput = container.querySelector('[data-system-logs-search]');
        const refreshButton = container.querySelector('[data-system-logs-refresh]');
        const tableBody = container.querySelector('[data-system-logs-body]');
        const countLabel = container.querySelector('[data-system-logs-count]');
        const feedback = container.querySelector('[data-system-logs-feedback]');
        const emptyState = container.querySelector('[data-system-logs-empty]');
        const truncatedHint = container.querySelector('[data-system-logs-truncated]');

        let currentRange = rangeSelect ? rangeSelect.value : '7d';
        let currentSearch = searchInput ? searchInput.value.trim() : '';
        let activeRequest = null;

        const limitFromDataset = Number(container.getAttribute('data-system-logs-limit')) || 100;

        const setLoading = (isLoading) => {
            container.classList.toggle('is-loading', Boolean(isLoading));
            if (refreshButton) {
                refreshButton.disabled = Boolean(isLoading);
            }
        };

        const updateFeedback = (message, isError = false) => {
            if (!feedback) {
                return;
            }

            feedback.textContent = message || '';
            feedback.classList.toggle('error', Boolean(isError) && !!message);
        };

        const applyFiltersToInputs = (filters) => {
            if (!filters) {
                return;
            }

            if (filters.range && rangeSelect && rangeSelect.value !== filters.range) {
                rangeSelect.value = filters.range;
            }

            if (typeof filters.search === 'string' && searchInput && searchInput.value.trim() !== filters.search) {
                searchInput.value = filters.search;
            }
        };

        const updateTruncatedHint = (hasMore, limitValue) => {
            if (!truncatedHint) {
                return;
            }

            truncatedHint.classList.toggle('hidden', !hasMore);
            if (hasMore && limitValue) {
                truncatedHint.textContent = 'Showing the latest ' + limitValue + ' entries.';
            }
        };

        const renderLogs = (data) => {
            if (tableBody) {
                tableBody.innerHTML = data.rows_html || '';
            }

            if (countLabel) {
                countLabel.textContent = data.summary || '';
            }

            if (emptyState) {
                emptyState.classList.toggle('hidden', (data.count || 0) > 0);
            }

            updateTruncatedHint(Boolean(data.has_more), data.limit || limitFromDataset);
        };

        const fetchLogs = (range, searchTerm, options = {}) => {
            if (!endpoint) {
                return;
            }

            const params = new URLSearchParams();
            if (range) {
                params.append('range', range);
            }
            if (searchTerm) {
                params.append('search', searchTerm);
            }

            if (activeRequest) {
                activeRequest.abort();
            }

            const controller = new AbortController();
            activeRequest = controller;

            if (!options.silent) {
                setLoading(true);
                updateFeedback('Loading…');
            }

            fetch(endpoint + '?' + params.toString(), {
                method: 'GET',
                credentials: 'same-origin',
                signal: controller.signal,
            })
                .then((response) => {
                    if (!response.ok) {
                        throw new Error('Unable to load system logs.');
                    }
                    return response.json();
                })
                .then((payload) => {
                    if (!payload || !payload.success) {
                        throw new Error(payload && payload.error ? payload.error : 'Unable to load system logs.');
                    }

                    currentRange = payload.filters && payload.filters.range ? payload.filters.range : range;
                    currentSearch = payload.filters && typeof payload.filters.search === 'string'
                        ? payload.filters.search
                        : searchTerm;

                    applyFiltersToInputs(payload.filters);
                    renderLogs(payload);
                    updateFeedback('');
                })
                .catch((error) => {
                    if (error.name === 'AbortError') {
                        return;
                    }
                    updateFeedback(error.message || 'Unable to load system logs.', true);
                })
                .finally(() => {
                    if (activeRequest === controller) {
                        activeRequest = null;
                    }
                    if (!options.silent) {
                        setLoading(false);
                    }
                });
        };

        if (rangeSelect) {
            rangeSelect.addEventListener('change', () => {
                currentRange = rangeSelect.value;
                fetchLogs(currentRange, currentSearch);
            });
        }

        if (searchInput) {
            const debouncedSearch = debounce(() => {
                currentSearch = searchInput.value.trim();
                fetchLogs(currentRange, currentSearch, { silent: false });
            }, 350);

            searchInput.addEventListener('input', debouncedSearch);
            searchInput.addEventListener('keydown', (event) => {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    debouncedSearch.cancel && debouncedSearch.cancel();
                    currentSearch = searchInput.value.trim();
                    fetchLogs(currentRange, currentSearch);
                }
            });
        }

        if (refreshButton) {
            refreshButton.addEventListener('click', () => {
                fetchLogs(currentRange, currentSearch);
            });
        }

        const initiallyTruncated = truncatedHint ? !truncatedHint.classList.contains('hidden') : false;
        updateTruncatedHint(initiallyTruncated, limitFromDataset);
    });
})();
