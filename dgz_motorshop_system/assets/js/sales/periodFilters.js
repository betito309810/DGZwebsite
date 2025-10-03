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
    };
})(window);
