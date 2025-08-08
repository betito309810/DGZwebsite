/* <?php
require 'footer.php';
require 'sidebar.php';
require 'header';
s
?> */

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/framework.css">

    <title>Dashboard - Team DGZ</title>
    
</head>
<body>
    <div class="header">
        <img src="../assets/logo/logo.png" alt="logo" width = "80px" height = "80px" >
        <div class="search-bar">
            <input type="text" placeholder="Search...">
            <span class="search-icon">🔍</span>
        </div>
        <div class="user-info">
            <div class="time">17:54:12<br>Aug 7, 2024</div>
            <div class="user-avatar">👤</div>
        </div>
    </div>
    
    <div class="container">
        <nav class="sidebar">
            <ul>
                <li>Dashboard</li>
                <li>Inventory</li>
                <li>Sales</li>
                <li>POS</li>
                <li>Product</li>
                <li>Orders</li>
            </ul>
        </nav>
        
        <main class="main-content">
            <div class="overview-section">
                <div class="overview-header">Overview</div>
                
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-number">100,000</div>
                        <div class="stat-label">Total users</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number">20</div>
                        <div class="stat-label">Today's total orders</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number">5</div>
                        <div class="stat-label">Today's delivered</div>
                    </div>
                </div>
                
                <div class="chart-section">
                    <div class="chart-title">Profit Revenue</div>
                    <div class="chart-container">
                        <div class="bar yellow"></div>
                        <div class="bar pink"></div>
                        <div class="bar magenta"></div>
                        <div class="bar purple"></div>
                    </div>
                </div>
            </div>
        </main>
        
        <aside class="right-sidebar">
            <div class="widget">
                <div class="widget-title">Best selling category</div>
                <div class="category-item">Category 1</div>
                <div class="category-item">Remaining products 1</div>
            </div>
            
            <div class="widget">
                <div class="widget-title">Low stock products</div>
                <div class="product-grid">
                    <div class="product-item">Product 1<br>Remaining quantity</div>
                    <div class="product-item">Product 2<br>Remaining quantity</div>
                </div>
            </div>
        </aside>
    </div>
</body>
</html>