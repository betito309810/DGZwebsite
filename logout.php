<?php
require __DIR__ . '/dgz_motorshop_system/config/config.php';
require __DIR__ . '/dgz_motorshop_system/includes/customer_session.php';

customerLogout();
header('Location: ' . orderingUrl('index.php'));
exit;
