document.addEventListener('DOMContentLoaded', () => {
    const modal = document.getElementById('salesReportModal');
    if (!modal) {
        return;
    }

    const openTrigger = document.getElementById('openSalesReport');
    const closeTrigger = document.getElementById('closeModal');
    const periodSelect = document.getElementById('reportPeriod');
    const periodValueInput = document.getElementById('reportPeriodValue');
    const periodValueLabel = document.getElementById('reportValueLabel');
    const periodHint = document.getElementById('reportPeriodHint');
    const form = document.getElementById('salesReportForm');

    let periodFilters = null;
    let updateHint = () => {};

    const openModal = () => {
        if (periodFilters) {
            const analyticsPeriodSelect = document.getElementById('analyticsPeriod');
            const analyticsPickerInput = document.getElementById('analyticsPicker');

            const targetPeriod = analyticsPeriodSelect?.value;
            const targetValue = analyticsPickerInput?.value;

            if (targetPeriod) {
                periodFilters.setPeriod(targetPeriod, targetValue || undefined);
            }

            updateHint(periodFilters.getState());
        }

        modal.style.display = 'flex';
    };

    const closeModal = () => {
        modal.style.display = 'none';
    };

    if (window.SalesPeriodFilters && periodSelect && periodValueInput && periodValueLabel) {
        updateHint = (state) => {
            if (!window.SalesPeriodFilters || !state) {
                return;
            }

            const description = window.SalesPeriodFilters.describe(state.period, state.value);
            if (!description) {
                periodFilters?.setRangeHint('');
                return;
            }

            periodSelect.value = description.period;
            periodValueInput.value = description.value;
            periodFilters?.setRangeHint(
                window.SalesPeriodFilters.formatRangeText({
                    start: description.rangeStart,
                    end: description.rangeEnd,
                })
            );
        };

        periodFilters = window.SalesPeriodFilters.create({
            periodSelect,
            valueInput: periodValueInput,
            labelElement: periodValueLabel,
            hintElement: periodHint,
            onChange: (state) => updateHint(state),
        });

        updateHint(periodFilters.getState());
    }

    form?.addEventListener('submit', () => {
        if (!periodFilters || !window.SalesPeriodFilters) {
            return;
        }

        const state = periodFilters.getState();
        const description = window.SalesPeriodFilters.describe(state.period, state.value);

        if (description) {
            periodSelect.value = description.period;
            periodValueInput.value = description.value;
        }
    });

    openTrigger?.addEventListener('click', openModal);
    closeTrigger?.addEventListener('click', closeModal);

    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            closeModal();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && modal.style.display === 'flex') {
            closeModal();
        }
    });
});
