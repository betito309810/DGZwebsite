(function () {
    function pesoToNumber(text) {
        if (typeof text !== 'string') return Number(text) || 0;
        return parseFloat(text.replace(/[^\d.-]/g, '')) || 0;
    }

    function getRowPrice(row) {
        const priceCell = row.querySelector('.pos-price') || row.cells[1];
        return priceCell ? pesoToNumber(priceCell.textContent) : 0;
    }

    function getRowQty(row) {
        const qtyInput = row.querySelector('.pos-qty, input[type="number"]');
        return qtyInput ? (parseFloat(qtyInput.value) || 0) : 0;
    }

    function rowIncluded(row) {
        const cb = row.querySelector('input[type="checkbox"]');
        return cb ? cb.checked : true;
    }

    function formatPeso(n) {
        n = Number(n) || 0;
        return '₱' + n.toFixed(2);
    }

    function recalcTotal() {
        let total = 0;
        document.querySelectorAll('#posTable tr[data-product-id]').forEach(function (row) {
            if (!rowIncluded(row)) return;
            total += getRowPrice(row) * getRowQty(row);
        });
        var totalEl = document.getElementById('totalAmount');
        if (totalEl) totalEl.textContent = formatPeso(total);
        computeChange();
    }

    function computeChange() {
        var total = pesoToNumber((document.getElementById('totalAmount') || {}).textContent || 0);
        var received = parseFloat((document.getElementById('amountReceived') || {}).value || 0);
        var change = Math.max(0, received - total);
        var changeEl = document.getElementById('changeAmount');
        if (changeEl) changeEl.textContent = formatPeso(change);
        var btn = document.querySelector('button[name="pos_checkout"]');
        if (btn) {
            btn.disabled = (total <= 0 || received < total);
        }
    }
    window.recalcTotal = recalcTotal;

    document.addEventListener('DOMContentLoaded', function () {
        var table = document.getElementById('posTable');
        if (table) {
            table.addEventListener('input', function (e) {
                if (e.target && (e.target.matches('.pos-qty') || e.target.matches('input[type="number"]'))) {
                    recalcTotal();
                }
            });
            table.addEventListener('change', function (e) {
                if (e.target && e.target.matches('input[type="checkbox"]')) {
                    recalcTotal();
                }
            });
        }
        var clearBtn = document.getElementById('clearPosTable');
        if (clearBtn) clearBtn.addEventListener('click', function () {
            setTimeout(recalcTotal, 0);
        });
        var received = document.getElementById('amountReceived');
        if (received) received.addEventListener('input', computeChange);

        // Wrap addProductToPOS to recompute after adding
        if (typeof window.addProductToPOS === 'function' && !window.addProductToPOS.__wrapped) {
            var orig = window.addProductToPOS;
            window.addProductToPOS = function () {
                var r = orig.apply(this, arguments);
                setTimeout(recalcTotal, 0);
                return r;
            };
            window.addProductToPOS.__wrapped = true;
        }
        recalcTotal();
    });
})
();