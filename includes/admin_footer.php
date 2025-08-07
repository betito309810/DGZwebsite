<?php
// ============================================
// FILE: includes/admin_footer.php
// Admin footer with closing tags and admin-specific scripts
// ============================================
?>

    <!-- Admin Content Area -->
    <main class="admin-content" style="margin-left: 250px; padding: var(--spacing-lg);">
        
    <!-- Footer for admin pages -->
    <footer class="admin-footer mt-5 pt-4 border-top text-center">
        <p class="text-muted">© <?php echo date('Y'); ?> DCG Motorshop Admin Panel</p>
    </footer>

    <!-- Admin JavaScript -->
    <script src="../assets/js/jquery.min.js"></script>
    <script src="../assets/js/bootstrap.min.js"></script>
    <script src="../assets/js/chart.js"></script>
    <script src="../assets/js/admin.js"></script>
    
    <script>
        // Admin-specific JavaScript
        
        // Mobile admin sidebar toggle
        function toggleAdminSidebar() {
            const sidebar = document.getElementById('adminSidebar');
            sidebar.classList.toggle('mobile-open');
        }
        
        // Auto-save forms
        document.querySelectorAll('.auto-save').forEach(form => {
            const inputs = form.querySelectorAll('input, select, textarea');
            inputs.forEach(input => {
                input.addEventListener('change', function() {
                    // Auto-save functionality
                    console.log('Auto-saving form data...');
                });
            });
        });
        
        // Confirmation dialogs
        document.querySelectorAll('.confirm-delete').forEach(button => {
            button.addEventListener('click', function(e) {
                if (!confirm('Are you sure you want to delete this item?')) {
                    e.preventDefault();
                    return false;
                }
            });
        });
        
        // Real-time notifications (placeholder)
        function checkNotifications() {
            // Fetch new notifications
            fetch('../api/notifications.php')
                .then(response => response.json())
                .then(data => {
                    // Update notification badges
                    updateNotificationBadges(data);
                })
                .catch(error => console.log('Notification check failed'));
        }
        
        function updateNotificationBadges(data) {
            // Update inventory alerts
            const inventoryBadge = document.querySelector('.admin-nav-link[href*="inventory"] .notification-badge');
            if (inventoryBadge && data.low_stock_count > 0) {
                inventoryBadge.textContent = data.low_stock_count;
            }
            
            // Update order alerts
            const ordersBadge = document.querySelector('.admin-nav-link[href*="orders"] .notification-badge');
            if (ordersBadge && data.pending_orders > 0) {
                ordersBadge.textContent = data.pending_orders;
            }
        }
        
        // Check notifications every 30 seconds
        setInterval(checkNotifications, 30000);
        
        // Dashboard charts initialization
        if (typeof Chart !== 'undefined') {
            // Initialize charts if on dashboard
            initializeDashboardCharts();
        }
        
        function initializeDashboardCharts() {
            // Sales chart
            const salesCtx = document.getElementById('salesChart');
            if (salesCtx) {
                new Chart(salesCtx, {
                    type: 'line',
                    data: {
                        labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
                        datasets: [{
                            label: 'Sales',
                            data: [12, 19, 3, 5, 2, 3],
                            borderColor: 'rgb(78, 205, 196)',
                            tension: 0.1
                        }]
                    }
                });
            }
            
            // Inventory chart
            const inventoryCtx = document.getElementById('inventoryChart');
            if (inventoryCtx) {
                new Chart(inventoryCtx, {
                    type: 'bar',
                    data: {
                        labels: ['Engines', 'Brakes', 'Batteries', 'Cables'],
                        datasets: [{
                            label: 'Stock Level',
                            data: [65, 59, 80, 81],
                            backgroundColor: [
                                'rgba(255, 107, 157, 0.8)',
                                'rgba(78, 205, 196, 0.8)',
                                'rgba(255, 193, 7, 0.8)',
                                'rgba(40, 167, 69, 0.8)'
                            ]
                        }]
                    }
                });
            }
        }
    </script>
</body>
</html>