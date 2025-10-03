(function (window) {
    const LABELS = {
        daily: 'Select day',
        weekly: 'Select week',
        monthly: 'Select month',
        annually: 'Select year',
    };

    const INPUT_TYPES = {
        daily: 'date',
        weekly: 'week',
        monthly: 'month',
        annually: 'number',
    };

    const PATTERNS = {
        daily: /^\d{4}-\d{2}-\d{2}$/,
        weekly: /^\d{4}-W\d{2}$/,
        monthly: /^\d{4}-\d{2}$/,
        annually: /^\d{4}$/,
    };

    function normalizePeriod(value) {
        const normalized = (value || 'daily').toString().toLowerCase();
        if (normalized === 'annual') {
            return 'annually';
        }
        if (normalized in LABELS) {
            return normalized;
        }
        return 'daily';
    }

    function formatDateValue(date) {
        return date.toISOString().slice(0, 10);
    }

    function formatMonthValue(date) {
        return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;
    }

    function formatYearValue(date) {
        return `${date.getFullYear()}`;
    }

    function formatWeekValue(date) {
        const utcDate = new Date(Date.UTC(date.getFullYear(), date.getMonth(), date.getDate()));
        const day = utcDate.getUTCDay() || 7;
        utcDate.setUTCDate(utcDate.getUTCDate() + 4 - day);
        const isoYear = utcDate.getUTCFullYear();
        const yearStart = new Date(Date.UTC(isoYear, 0, 1));
        const week = Math.ceil((((utcDate - yearStart) / 86400000) + 1) / 7);
        return `${isoYear}-W${String(week).padStart(2, '0')}`;
    }

    function defaultValue(period) {
        const reference = new Date();
        switch (period) {
            case 'weekly':
                return formatWeekValue(reference);
            case 'monthly':
                return formatMonthValue(reference);
            case 'annually':
                return formatYearValue(reference);
            case 'daily':
            default:
                return formatDateValue(reference);
        }
    }

    function isValid(period, value) {
        const pattern = PATTERNS[period];
        return !!pattern && pattern.test(value);
    }

    function pad2(value) {
        return String(value).padStart(2, '0');
    }

    function formatYMD(date) {
        return [
            date.getFullYear(),
            pad2(date.getMonth() + 1),
            pad2(date.getDate()),
        ].join('-');
    }

    function formatDateTime(date) {
        return `${formatYMD(date)} ${pad2(date.getHours())}:${pad2(date.getMinutes())}:${pad2(date.getSeconds())}`;
    }

    function isoWeekStart(year, week) {
        const simple = new Date(Date.UTC(year, 0, 4));
        const dayOfWeek = simple.getUTCDay() || 7;
        const startUtc = new Date(simple);
        startUtc.setUTCDate(simple.getUTCDate() - dayOfWeek + 1 + (week - 1) * 7);
        return new Date(
            startUtc.getUTCFullYear(),
            startUtc.getUTCMonth(),
            startUtc.getUTCDate()
        );
    }

    function describePeriod(period, rawValue) {
        const normalized = normalizePeriod(period);
        let value = rawValue || '';

        if (!isValid(normalized, value)) {
            value = defaultValue(normalized);
        }

        let startDate;
        let endDate;
        let normalizedValue = value;
        let label = '';

        switch (normalized) {
            case 'weekly': {
                const match = value.match(/^(\d{4})-W(\d{2})$/);
                let isoYear;
                let isoWeek;

                if (match) {
                    isoYear = parseInt(match[1], 10);
                    isoWeek = parseInt(match[2], 10);
                } else {
                    const fallback = defaultValue('weekly');
                    const fallbackMatch = fallback.match(/^(\d{4})-W(\d{2})$/);
                    isoYear = parseInt(fallbackMatch[1], 10);
                    isoWeek = parseInt(fallbackMatch[2], 10);
                    normalizedValue = fallback;
                }

                startDate = isoWeekStart(isoYear, isoWeek);
                endDate = new Date(startDate);
                endDate.setDate(startDate.getDate() + 7);

                const endDisplay = new Date(endDate);
                endDisplay.setDate(endDisplay.getDate() - 1);

                label = `Week ${pad2(isoWeek)} (${startDate.toLocaleDateString('en-US', {
                    month: 'short',
                    day: 'numeric',
                })} - ${endDisplay.toLocaleDateString('en-US', {
                    month: 'short',
                    day: 'numeric',
                    year: 'numeric',
                })})`;

                normalizedValue = `${isoYear}-W${pad2(isoWeek)}`;
                break;
            }

            case 'monthly': {
                const match = value.match(/^(\d{4})-(\d{2})$/);
                let year;
                let month;

                if (match) {
                    year = parseInt(match[1], 10);
                    month = parseInt(match[2], 10);
                } else {
                    const fallback = defaultValue('monthly');
                    const fallbackMatch = fallback.match(/^(\d{4})-(\d{2})$/);
                    year = parseInt(fallbackMatch[1], 10);
                    month = parseInt(fallbackMatch[2], 10);
                    normalizedValue = fallback;
                }

                startDate = new Date(year, month - 1, 1);
                endDate = new Date(year, month, 1);
                label = startDate.toLocaleDateString('en-US', {
                    month: 'long',
                    year: 'numeric',
                });

                normalizedValue = `${year}-${pad2(month)}`;
                break;
            }

            case 'annually': {
                let year = parseInt(value, 10);
                if (Number.isNaN(year)) {
                    year = new Date().getFullYear();
                }

                startDate = new Date(year, 0, 1);
                endDate = new Date(year + 1, 0, 1);
                label = `${year}`;
                normalizedValue = `${year}`;
                break;
            }

            case 'daily':
            default: {
                const parts = (value || '').split('-').map((part) => parseInt(part, 10));
                let year = parts[0];
                let month = parts[1];
                let day = parts[2];

                if (parts.length !== 3 || parts.some((n) => Number.isNaN(n))) {
                    const fallback = defaultValue('daily');
                    const fallbackParts = fallback.split('-').map((part) => parseInt(part, 10));
                    year = fallbackParts[0];
                    month = fallbackParts[1];
                    day = fallbackParts[2];
                    normalizedValue = fallback;
                }

                startDate = new Date(year, month - 1, day);
                endDate = new Date(startDate);
                endDate.setDate(startDate.getDate() + 1);

                label = startDate.toLocaleDateString('en-US', {
                    month: 'long',
                    day: 'numeric',
                    year: 'numeric',
                });

                normalizedValue = `${startDate.getFullYear()}-${pad2(startDate.getMonth() + 1)}-${pad2(startDate.getDate())}`;
                break;
            }
        }

        const rangeStart = formatYMD(startDate);
        const inclusiveEnd = new Date(endDate);
        inclusiveEnd.setDate(inclusiveEnd.getDate() - 1);
        const rangeEnd = formatYMD(inclusiveEnd);

        return {
            period: normalized,
            value: normalizedValue,
            start: formatDateTime(startDate),
            end: formatDateTime(endDate),
            rangeStart,
            rangeEnd,
            label,
        };
    }

    function configureInputAttributes(input, period) {
        input.setAttribute('type', INPUT_TYPES[period]);
        if (period === 'annually') {
            input.setAttribute('min', '1900');
            input.setAttribute('max', `${new Date().getFullYear()}`);
            input.setAttribute('step', '1');
            input.setAttribute('placeholder', 'YYYY');
        } else {
            input.removeAttribute('min');
            input.removeAttribute('max');
            input.removeAttribute('step');
            input.removeAttribute('placeholder');
        }
    }

    function formatRangeText(range) {
        if (!range || !range.start) {
            return '';
        }
        if (!range.end || range.start === range.end) {
            return range.start;
        }
        return `${range.start} - ${range.end}`;
    }

    function create(options) {
        if (!options) {
            throw new Error('SalesPeriodFilters.create requires options');
        }
        const {
            periodSelect,
            valueInput,
            labelElement,
            hintElement,
            onChange,
        } = options;

        if (!periodSelect || !valueInput || !labelElement) {
            throw new Error('Missing required filter elements');
        }

        let state = {
            period: normalizePeriod(periodSelect.value),
            value: '',
        };

        function emit() {
            if (typeof onChange === 'function') {
                onChange({ ...state });
            }
        }

        function applyPeriod(period, { emitChange } = { emitChange: true }) {
            state.period = period;
            configureInputAttributes(valueInput, period);
            labelElement.textContent = LABELS[period];

            const nextValue = isValid(period, valueInput.value)
                ? valueInput.value
                : defaultValue(period);

            valueInput.value = nextValue;
            state.value = nextValue;

            if (hintElement) {
                hintElement.textContent = '';
            }

            if (emitChange) {
                emit();
            }
        }

        periodSelect.addEventListener('change', () => {
            const nextPeriod = normalizePeriod(periodSelect.value);
            periodSelect.value = nextPeriod;
            applyPeriod(nextPeriod);
        });

        valueInput.addEventListener('change', () => {
            const current = valueInput.value;
            const nextValue = isValid(state.period, current)
                ? current
                : defaultValue(state.period);

            if (nextValue !== current) {
                valueInput.value = nextValue;
            }

            state.value = nextValue;

            if (hintElement) {
                hintElement.textContent = '';
            }

            emit();
        });

        applyPeriod(state.period, { emitChange: false });

        return {
            getState() {
                return { ...state };
            },
            setRangeHint(rangeText) {
                if (hintElement) {
                    hintElement.textContent = rangeText || '';
                }
            },
            setPeriod(period, value) {
                const normalized = normalizePeriod(period);
                periodSelect.value = normalized;
                applyPeriod(normalized, { emitChange: false });
                if (value && isValid(normalized, value)) {
                    valueInput.value = value;
                    state.value = value;
                }
            },
            refresh() {
                applyPeriod(state.period);
            }
        };
    }

    window.SalesPeriodFilters = {
        create,
        formatRangeText,
        describe: describePeriod,
    };
})(window);
