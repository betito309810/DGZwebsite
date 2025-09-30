<?php
require 'dgz_motorshop_system/config/config.php';

try {
    $pdo = db();
    echo "Database connection successful.";
} catch (Exception $e) {
    echo "Database connection failed: " . $e->getMessage();
}
