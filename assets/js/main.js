// ============================================
// DGZ MOTORSHOP NAVIGATION JAVASCRIPT
// File: assets/js/main.js
// ============================================

document.addEventListener('DOMContentLoaded', function() {
    
    // ============================================
    // 1. MOBILE NAVIGATION
    // ============================================
    
    const mobileMenuToggle = document.getElementById('mobileMenuToggle');
    const mainNavLinks = document.getElementById('mainNavLinks');
    const adminSidebar = document.getElementById('adminSidebar');
    
    // Mobile menu toggle for main navigation
    if (mobileMenuToggle && mainNavLinks) {
        mobileMenuToggle.addEventListener('click', function(e) {
            e.preventDefault();
            mainNavLinks.classList.toggle('mobile-open');
            
            // Change hamburger icon
            const icon = mobileMenuToggle.textContent;
            mobileMenuToggle.textContent = icon === '☰' ? '✕' : '☰';
        });
        
        // Close mobile menu when clicking outside
        document.addEventListener('click', function(e) {
            if (!mobileMenuToggle.contains(e.target) && !mainNavLinks.contains(e.target)) {
                mainNavLinks.classList.remove('mobile-open');
                mobileMenuToggle.textContent = '☰';
            }
        });
    }
    
    // Admin sidebar toggle for mobile
    function toggleAdminSidebar() {
        if (adminSidebar) {
            adminSidebar.classList.toggle('mobile-open');
        }
    }
    
    // Make function globally available
    window.toggleAdminSidebar = toggleAdminSidebar;
    
    // ============================================
    // 2. STICKY HEADER
    // ============================================
    
    const mainHeader = document.getElementById('mainHeader');
    let lastScrollY = window.scrollY;
    
    function handleScroll() {
        const currentScrollY = window.scrollY;
        
        if (mainHeader) {
            if (currentScrollY > 50) {
                mainHeader.classList.add('scrolled');
            } else {
                mainHeader.classList.remove('scrolled');
            }
            
            // Hide header on scroll down, show on scroll up
            if (currentScrollY > lastScrollY && currentScrollY > 100) {
                mainHeader.style.transform = 'translateY(-100%)';
            } else {
                mainHeader.style.transform = 'translateY(0)';
            }
        }
        
        lastScrollY = currentScrollY;
    }
    
    // Throttled scroll handler
    let scrollTimeout;
    window.addEventListener('scroll', function() {
        if (!scrollTimeout) {
            scrollTimeout = setTimeout(function() {
                handleScroll();
                scrollTimeout = null;
            }, 10);
        }
    });
    
    // ============================================
    // 3. DROPDOWN MENUS
    // ============================================
    
    const navItems = document.querySelectorAll('.nav-item');
    
    navItems.forEach(item => {
        const dropdownMenu = item.querySelector('.dropdown-menu');
        
        if (dropdownMenu) {
            let timeout;
            
            item.addEventListener('mouseenter', function() {
                clearTimeout(timeout);
                dropdownMenu.style.display = 'block';
                setTimeout(() => {
                    dropdownMenu.style.opacity = '1';
                    dropdownMenu.style.visibility = 'visible';
                    dropdownMenu.style.transform = 'translateX(-50%) translateY(5px)';
                }, 10);
            });
            
            item.addEventListener('mouseleave', function() {
                timeout = setTimeout(() => {
                    dropdownMenu.style.opacity = '0';
                    dropdownMenu.style.visibility = 'hidden';
                    dropdownMenu.style.transform = 'translateX(-50%) translateY(0px)';
                    setTimeout(() => {
                        dropdownMenu.style.display = 'none';
                    }, 300);
                }, 100);
            });
        }
    });
    
    // ============================================
    // 4. ADMIN SUBMENU TOGGLE
    // ============================================
    
    function toggleSubmenu(element) {
        event.preventDefault();
        const parentItem = element.parentNode;
        const submenu = parentItem.querySelector('.admin-submenu');
        const arrow = element.querySelector('.admin-nav-arrow');
        const isOpen = parentItem.classList.contains('open');
        
        // Close all other submenus
        document.querySelectorAll('.admin-nav-item.has-submenu').forEach(item => {
            if (item !== parentItem) {
                item.classList.remove('open');
                const otherArrow = item.querySelector('.admin-nav-arrow');
                if (otherArrow) otherArrow.style.transform = 'rotate(0deg)';
            }
        });
        
        // Toggle current submenu
        if (!isOpen) {
            parentItem.classList.add('open');
            if (arrow) arrow.style.transform = 'rotate(90deg)';
            
            // Smooth slide down animation
            if (submenu) {
                submenu.style.display = 'block';
                submenu.style.maxHeight = '0';
                submenu.style.overflow = 'hidden';
                submenu.style.transition = 'max-height 0.3s ease-out';
                
                setTimeout(() => {
                    submenu.style.maxHeight = submenu.scrollHeight + 'px';
                }, 10);
            }
        } else {
            parentItem.classList.remove('open');
            if (arrow) arrow.style.transform = 'rotate(0deg)';
            
            // Smooth slide up animation
            if (submenu) {
                submenu.style.maxHeight = '0';
                setTimeout(() => {
                    submenu.style.display = 'none';
                    submenu.style.maxHeight = '';
                    submenu.style.overflow = '';
                    submenu.style.transition = '';
                }, 300);
            }
        }
    }
    
    // Make function globally available
    window.toggleSubmenu = toggleSubmenu;
    
    // ============================================
    // 5. SEARCH FUNCTIONALITY
    // ============================================
    
    const searchBar = document.querySelector('.search-bar');
    const searchForm = document.querySelector('.search-container form');
    
    if (searchBar && searchForm) {
        // Search suggestions (basic implementation)
        let searchTimeout;
        
        searchBar.addEventListener('input', function() {
            const query = this.value.trim();
            
            clearTimeout(searchTimeout);
            
            if (query.length >= 2) {
                searchTimeout = setTimeout(() => {
                    fetchSearchSuggestions(query);
                }, 300);
            } else {
                hideSearchSuggestions();
            }
        });
        
        // Handle search form submission
        searchForm.addEventListener('submit', function(e) {
            const query = searchBar.value.trim();
            if (!query) {
                e.preventDefault();
                searchBar.focus();
                showNotification('Please enter a search term', 'warning');
                return false;
            }
        });
        
        // Hide suggestions when clicking outside
        document.addEventListener('click', function(e) {
            if (!searchForm.contains(e.target)) {
                hideSearchSuggestions();
            }
        });
    }
    
    function fetchSearchSuggestions(query) {
        // Mock search suggestions - replace with actual API call
        const suggestions = [
            'Engine Parts',
            'Brake Pads',
            'Motorcycle Batteries',
            'LED Lights',
            'Exhaust Systems'
        ].filter(item => item.toLowerCase().includes(query.toLowerCase()));
        
        showSearchSuggestions(suggestions);
    }
    
    function showSearchSuggestions(suggestions) {
        hideSearchSuggestions(); // Remove existing suggestions
        
        if (suggestions.length === 0) return;
        
        const suggestionBox = document.createElement('div');
        suggestionBox.className = 'search-suggestions';
        suggestionBox.innerHTML = suggestions.map(suggestion => 
            `<div class="search-suggestion-item">${suggestion}</div>`
        ).join('');
        
        // Add click handlers to suggestions
        suggestionBox.querySelectorAll('.search-suggestion-item').forEach(item => {
            item.addEventListener('click', function() {
                searchBar.value = this.textContent;
                searchForm.submit();
            });
        });
        
        searchForm.appendChild(suggestionBox);
    }
    
    function hideSearchSuggestions() {
        const existingSuggestions = document.querySelector('.search-suggestions');
        if (existingSuggestions) {
            existingSuggestions.remove();
        }
    }
    
    // ============================================
    // 6. SHOPPING CART FUNCTIONALITY
    // ============================================
    
    // Update cart count
    function updateCartCount(count) {
        const cartCountElement = document.querySelector('.cart-count');
        if (cartCountElement) {
            cartCountElement.textContent = count;
            cartCountElement.style.display = count > 0 ? 'flex' : 'none';
            
            // Add animation
            cartCountElement.style.animation = 'pulse 0.3s ease-in-out';
            setTimeout(() => {
                cartCountElement.style.animation = '';
            }, 300);
        }
    }
    
    // Add to cart animation
    function addToCartAnimation(button) {
        const cart = document.querySelector('.cart-link');
        if (!cart) return;
        
        const cartRect = cart.getBoundingClientRect();
        const buttonRect = button.getBoundingClientRect();
        
        // Create flying animation element
        const flyingElement = document.createElement('div');
        flyingElement.className = 'flying-cart-item';
        flyingElement.style.cssText = `
            position: fixed;
            width: 20px;
            height: 20px;
            background: var(--brand-primary);
            border-radius: 50%;
            z-index: 9999;
            left: ${buttonRect.left + buttonRect.width/2}px;
            top: ${buttonRect.top + buttonRect.height/2}px;
            transition: all 0.6s cubic-bezier(0.2, 1, 0.3, 1);
            pointer-events: none;
        `;
        
        document.body.appendChild(flyingElement);
        
        // Animate to cart
        setTimeout(() => {
            flyingElement.style.left = cartRect.left + cartRect.width/2 + 'px';
            flyingElement.style.top = cartRect.top + cartRect.height/2 + 'px';
            flyingElement.style.transform = 'scale(0)';
            flyingElement.style.opacity = '0';
        }, 50);
        
        // Remove element after animation
        setTimeout(() => {
            flyingElement.remove();
        }, 700);
    }
    
    // Make functions globally available
    window.updateCartCount = updateCartCount;
    window.addToCartAnimation = addToCartAnimation;
    
    // ============================================
    // 7. NOTIFICATION SYSTEM
    // ============================================
    
    function showNotification(message, type = 'info', duration = 4000) {
        const notification = document.createElement('div');
        notification.className = `notification notification-${type}`;
        notification.innerHTML = `
            <div class="notification-content">
                <span class="notification-message">${message}</span>
                <button class="notification-close">&times;</button>
            </div>
        `;
        
        // Add to page
        let notificationContainer = document.querySelector('.notification-container');
        if (!notificationContainer) {
            notificationContainer = document.createElement('div');
            notificationContainer.className = 'notification-container';
            document.body.appendChild(notificationContainer);
        }
        
        notificationContainer.appendChild(notification);
        
        // Show animation
        setTimeout(() => notification.classList.add('show'), 100);
        
        // Auto remove
        const autoRemove = setTimeout(() => {
            removeNotification(notification);
        }, duration);
        
        // Manual close
        notification.querySelector('.notification-close').addEventListener('click', () => {
            clearTimeout(autoRemove);
            removeNotification(notification);
        });
    }
    
    function removeNotification(notification) {
        notification.classList.add('hiding');
        setTimeout(() => {
            if (notification.parentNode) {
                notification.parentNode.removeChild(notification);
            }
        }, 300);
    }
    
    // Make function globally available
    window.showNotification = showNotification;
    
    // ============================================
    // 8. ADMIN TIME UPDATE
    // ============================================
    
    const currentTimeElement = document.getElementById('currentTime');
    if (currentTimeElement) {
        function updateTime() {
            const now = new Date();
            const timeStr = now.toLocaleTimeString('en-US', {
                hour12: false,
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            });
            const dateStr = now.toLocaleDateString('en-US', {
                month: 'short',
                day: '2-digit',
                year: 'numeric'
            });
            
            currentTimeElement.innerHTML = `<div>${timeStr}</div><div>${dateStr}</div>`;
        }
        
        updateTime();
        setInterval(updateTime, 1000);
    }
    
    // ============================================
    // 9. KEYBOARD SHORTCUTS
    // ============================================
    
    document.addEventListener('keydown', function(e) {
        // Admin shortcuts
        if (e.ctrlKey || e.metaKey) {
            switch(e.key) {
                case '/':
                    e.preventDefault();
                    if (searchBar) searchBar.focus();
                    break;
                case 'k':
                    e.preventDefault();
                    if (searchBar) searchBar.focus();
                    break;
            }
        }
        
        // ESC key actions
        if (e.key === 'Escape') {
            hideSearchSuggestions();
            if (mainNavLinks) mainNavLinks.classList.remove('mobile-open');
            if (mobileMenuToggle) mobileMenuToggle.textContent = '☰';
        }
    });
    
    // ============================================
    // 10. LOADING STATES
    // ============================================
    
    // Add loading state to forms
    document.querySelectorAll('form').forEach(form => {
        form.addEventListener('submit', function() {
            const submitBtn = form.querySelector('button[type="submit"], input[type="submit"]');
            if (submitBtn && !submitBtn.disabled) {
                const originalText = submitBtn.textContent || submitBtn.value;
                submitBtn.disabled = true;
                
                if (submitBtn.tagName === 'BUTTON') {
                    submitBtn.textContent = 'Loading...';
                } else {
                    submitBtn.value = 'Loading...';
                }
                
                // Reset after 10 seconds if no response
                setTimeout(() => {
                    submitBtn.disabled = false;
                    if (submitBtn.tagName === 'BUTTON') {
                        submitBtn.textContent = originalText;
                    } else {
                        submitBtn.value = originalText;
                    }
                }, 10000);
            }
        });
    });
    
    // ============================================
    // 11. INITIALIZATION COMPLETE
    // ============================================
    
    console.log('DCG Motorshop Navigation initialized');
    
    // Trigger custom event
    window.dispatchEvent(new CustomEvent('dcg:navigation:ready'));
});

// ============================================
// CSS ANIMATIONS FOR JAVASCRIPT INTERACTIONS
// ============================================

// Add required CSS for JavaScript-generated elements
const dynamicStyles = `
<style>
.search-suggestions {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: white;
    border: 1px solid var(--border-color);
    border-top: none;
    border-radius: 0 0 var(--border-radius) var(--border-radius);
    box-shadow: var(--shadow-lg);
    z-index: 1000;
    max-height: 200px;
    overflow-y: auto;
}

.search-suggestion-item {
    padding: var(--spacing-sm) var(--spacing-md);
    cursor: pointer;
    border-bottom: 1px solid var(--border-color);
    transition: background-color var(--transition-fast);
}

.search-suggestion-item:hover {
    background-color: var(--bg-primary);
    color: var(--brand-primary);
}

.search-suggestion-item:last-child {
    border-bottom: none;
}

.notification-container {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 9999;
    pointer-events: none;
}

.notification {
    background: white;
    border-radius: var(--border-radius);
    box-shadow: var(--shadow-lg);
    margin-bottom: var(--spacing-sm);
    transform: translateX(100%);
    transition: all 0.3s ease-out;
    pointer-events: auto;
    min-width: 300px;
    border-left: 4px solid var(--info);
}

.notification.notification-success { border-left-color: var(--success); }
.notification.notification-warning { border-left-color: var(--warning); }
.notification.notification-error { border-left-color: var(--error); }

.notification.show {
    transform: translateX(0);
}

.notification.hiding {
    transform: translateX(100%);
    opacity: 0;
}

.notification-content {
    padding: var(--spacing-md);
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.notification-close {
    background: none;
    border: none;
    font-size: var(--font-size-lg);
    cursor: pointer;
    color: var(--text-muted);
    padding: 0;
    margin-left: var(--spacing-md);
}

.notification-close:hover {
    color: var(--text-primary);
}

@keyframes pulse {
    0% { transform: scale(1); }
    50% { transform: scale(1.2); }
    100% { transform: scale(1); }
}

.flying-cart-item {
    animation: pulse 0.3s ease-in-out;
}

/* Mobile responsive adjustments */
@media (max-width: 768px) {
    .notification {
        min-width: calc(100vw - 40px);
        max-width: calc(100vw - 40px);
    }
    
    .notification-container {
        left: 20px;
        right: 20px;
    }
    
    .search-suggestions {
        font-size: var(--font-size-sm);
    }
}
</style>
`;

// Inject dynamic styles
document.head.insertAdjacentHTML('beforeend', dynamicStyles);