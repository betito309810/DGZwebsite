// Begin Sales report modal toggles
    // Sales Report Modal functions
    function openSalesReportModal() {
        document.getElementById('salesReportModal').style.display = 'flex';
    }

    function closeSalesReportModal() {
        document.getElementById('salesReportModal').style.display = 'none';
    }
    // End Sales report modal toggles

     // Begin Sales report modal quick handlers
            function openSalesReportModal() {
                document.getElementById('salesReportModal').style.display = 'flex';
            }

            function closeSalesReportModal() {
                document.getElementById('salesReportModal').style.display = 'none';
            }

            // Close modal when clicking outside
            document.getElementById('salesReportModal').addEventListener('click', function(event) {
                if (event.target === this) {
                    closeSalesReportModal();
                }
            });
            // End Sales report modal quick handlers