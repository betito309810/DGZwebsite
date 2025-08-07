<?php
// ============================================
// FILE: includes/admin_header.php
// Admin panel header with navigation
// ============================================
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? $page_title . ' - ' : ''; ?>Admin Panel - DCG Motorshop</title>
    
    <!-- CSS Files -->
    <link rel="stylesheet" href="../assets/css/framework.css">
    <link rel="stylesheet" href="../assets/css/navigation.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
    
    <!-- Chart.js for analytics -->
    <script src="../assets/js/chart.js"></script>
</head>
<body>
    <!-- Admin Header -->
    <header class="admin-header">
        <div class="container-fluid">
            <div class="admin-header-content">
                <!-- Logo -->
                <a href="index.php" class="admin-logo">DCG ADMIN</a>
                
                <!-- User Info -->
                <div class="admin-user-info">
                    <div class="admin-time" id="currentTime">
                        <div><?php echo date('H:i:s'); ?></div>
                        <div><?php echo date('M d, Y'); ?></div>
                    </div>
                    
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($_SESSION['admin_name'] ?? 'A', 0, 1)); ?>
                    </div>
                    
                    <a href="logout.php" class="logout-btn">Logout</a>
                </div>
            </div>
        </div>
    </header>