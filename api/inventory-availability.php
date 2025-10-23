<?php
require __DIR__ . '/../dgz_motorshop_system/config/config.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'error' => 'Method not allowed. Use POST.',
        'items' => [],
    ]);
    exit;
}

$rawBody = file_get_contents('php://input');
$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Invalid request payload.',
        'items' => [],
    ]);
    exit;
}

$rawItems = $payload['items'] ?? null;
if (!is_array($rawItems)) {
    echo json_encode([
        'items' => [],
    ]);
    exit;
}

$normalizedItems = [];
foreach ($rawItems as $rawItem) {
    if (!is_array($rawItem)) {
        continue;
    }

    $productId = isset($rawItem['product_id']) ? (int) $rawItem['product_id'] : ($rawItem['productId'] ?? 0);
    $variantId = $rawItem['variant_id'] ?? ($rawItem['variantId'] ?? null);
    $variantId = $variantId !== null ? (int) $variantId : null;

    if ($productId <= 0) {
        continue;
    }

    $normalizedItems[] = [
        'product_id' => $productId,
        'variant_id' => $variantId,
    ];

    if (count($normalizedItems) >= 50) {
        break;
    }
}

if ($normalizedItems === []) {
    echo json_encode([
        'items' => [],
    ]);
    exit;
}

try {
    $pdo = db();
    $activeClause = productsArchiveActiveCondition($pdo);

    $productQuantities = [];
    $variantQuantities = [];

    $variantIds = [];
    foreach ($normalizedItems as $item) {
        if ($item['variant_id'] !== null) {
            $variantIds[$item['variant_id']] = true;
        }
    }

    if ($variantIds !== []) {
        $placeholders = implode(',', array_fill(0, count($variantIds), '?'));
        $variantStmt = $pdo->prepare("SELECT id, product_id, quantity FROM product_variants WHERE id IN ($placeholders)");
        $variantStmt->execute(array_keys($variantIds));
        while ($variantRow = $variantStmt->fetch(PDO::FETCH_ASSOC)) {
            $variantId = (int) $variantRow['id'];
            $variantQuantities[$variantId] = [
                'quantity' => max(0, (int) ($variantRow['quantity'] ?? 0)),
                'product_id' => (int) ($variantRow['product_id'] ?? 0),
            ];
        }
    }

    $productIds = [];
    foreach ($normalizedItems as $item) {
        if ($item['variant_id'] === null) {
            $productIds[$item['product_id']] = true;
        }
    }

    if ($productIds !== []) {
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $productStmt = $pdo->prepare("SELECT id, quantity FROM products WHERE id IN ($placeholders) AND $activeClause");
        $productStmt->execute(array_keys($productIds));
        while ($productRow = $productStmt->fetch(PDO::FETCH_ASSOC)) {
            $productId = (int) $productRow['id'];
            $productQuantities[$productId] = max(0, (int) ($productRow['quantity'] ?? 0));
        }
    }

    $responseItems = [];
    foreach ($normalizedItems as $item) {
        $variantId = $item['variant_id'];
        if ($variantId !== null && isset($variantQuantities[$variantId])) {
            $responseItems[] = [
                'product_id' => $item['product_id'],
                'variant_id' => $variantId,
                'stock' => $variantQuantities[$variantId]['quantity'],
            ];
            continue;
        }

        $stock = $productQuantities[$item['product_id']] ?? 0;
        $responseItems[] = [
            'product_id' => $item['product_id'],
            'variant_id' => $variantId,
            'stock' => $stock,
        ];
    }

    echo json_encode([
        'items' => $responseItems,
    ]);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Unable to refresh inventory availability.',
        'items' => [],
    ]);
}
