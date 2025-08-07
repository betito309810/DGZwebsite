<?php
// ============================================
// FILE: includes/footer.php
// Main footer with closing tags
// ============================================
?>

    <!-- Main JavaScript -->
    <script src="../assets/js/jquery.min.js"></script>
    <script src="../assets/js/main.js"></script>
    
    <script>
        // Mobile menu toggle
        document.getElementById('mobileMenuToggle')?.addEventListener('click', function() {
            const navLinks = document.getElementById('mainNavLinks');
            navLinks.classList.toggle('mobile-open');
        });
        
        // Update time for admin panel
        if (document.getElementById('currentTime')) {
            setInterval(function() {
                const now = new Date();
                const timeStr = now.toLocaleTimeString('en-US', {hour12: false});
                const dateStr = now.toLocaleDateString('en-US', {
                    month: 'short',
                    day: '2-digit',
                    year: 'numeric'
                });
                document.getElementById('currentTime').innerHTML = 
                    '<div>' + timeStr + '</div><div>' + dateStr + '</div>';
            }, 1000);
        }
        
        // Sticky header effect
        window.addEventListener('scroll', function() {
            const header = document.getElementById('mainHeader');
            if (header) {
                if (window.scrollY > 50) {
                    header.classList.add('scrolled');
                } else {
                    header.classList.remove('scrolled');
                }
            }
        });
        
        // Admin submenu toggle
        function toggleSubmenu(element) {
            event.preventDefault();
            const parentItem = element.parentNode;
            const isOpen = parentItem.classList.contains('open');
            
            // Close all other submenus
            document.querySelectorAll('.admin-nav-item.has-submenu').forEach(item => {
                item.classList.remove('open');
            });
            
            // Toggle current submenu
            if (!isOpen) {
                parentItem.classList.add('open');
            }
        }
        
        // Search functionality
        document.querySelector('.search-bar')?.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                this.closest('form').submit();
            }
        });
    </script>
</body>
</html>