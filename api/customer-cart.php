<?php
require __DIR__ . '/../dgz_motorshop_system/config/config.php';
require __DIR__ . '/../dgz_motorshop_system/includes/customer_session.php';
require __DIR__ . '/../dgz_motorshop_system/includes/customer_cart.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$customer = getAuthenticatedCustomer();
if (!$customer) {
    http_response_code(401);
    echo json_encode([
        'error' => 'Authentication required.',
        'items' => [],
    ]);
    exit;
}

$customerId = (int) ($customer['id'] ?? 0);
if ($customerId <= 0) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Unable to resolve customer account.',
        'items' => [],
    ]);
    exit;
}

$pdo = db();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    try {
        $items = customerCartFetch($pdo, $customerId);
        echo json_encode([
            'items' => $items,
        ]);
    } catch (Throwable $exception) {
        http_response_code(500);
        echo json_encode([
            'error' => 'Unable to load saved cart.',
            'items' => [],
        ]);
    }
    exit;
}

if ($method === 'POST') {
    $rawBody = file_get_contents('php://input');
    $payload = json_decode($rawBody ?: '[]', true);
    if (!is_array($payload)) {
        http_response_code(400);
        echo json_encode([
            'error' => 'Invalid request payload.',
            'items' => [],
        ]);
        exit;
    }

    $items = $payload['items'] ?? [];
    if (!is_array($items)) {
        $items = [];
    }

    try {
        $persisted = customerCartReplace($pdo, $customerId, $items);
        echo json_encode([
            'items' => $persisted,
        ]);
    } catch (Throwable $exception) {
        http_response_code(500);
        echo json_encode([
            'error' => 'Unable to save cart.',
            'items' => [],
        ]);
    }
    exit;
}

http_response_code(405);
echo json_encode([
    'error' => 'Method not allowed. Use GET or POST.',
    'items' => [],
]);
