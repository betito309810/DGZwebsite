<?php
declare(strict_types=1);

require __DIR__ . '/../dgz_motorshop_system/config/config.php';

define('DGZ_CUSTOMER_SESSION_PASSIVE', true);
require __DIR__ . '/../dgz_motorshop_system/includes/customer_session.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$state = customerSessionCheckActive();

if (!empty($state['shouldLogout'])) {
    $message = is_string($state['message']) && $state['message'] !== ''
        ? $state['message']
        : "You’ve been logged out because your account was used to sign in on another device.";

    customerHandleForcedLogout($message, false);
}

$response = [
    'authenticated' => !empty($state['authenticated']),
    'active' => !empty($state['active']),
    'shouldLogout' => !empty($state['shouldLogout']),
    'message' => $state['message'] ?? null,
    'loginUrl' => orderingUrl('login.php'),
];

if (!empty($response['shouldLogout'])) {
    http_response_code(409);
}

echo json_encode($response, JSON_UNESCAPED_SLASHES);
