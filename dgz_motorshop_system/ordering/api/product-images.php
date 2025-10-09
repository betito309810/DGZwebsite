<?php
// Added: JSON endpoint that supplies product gallery images to the public modal.
require __DIR__ . '/../../config/config.php';

header('Content-Type: application/json');

$productId = isset($_GET['product_id']) ? (int) $_GET['product_id'] : 0;
if ($productId <= 0) {
    echo json_encode([
        'images' => [],
        'error' => 'Invalid product id provided.',
    ]);
    exit;
}

try {
    $pdo = db();

    $stmt = $pdo->prepare('SELECT image, name FROM products WHERE id = ? LIMIT 1');
    $stmt->execute([$productId]);
    $product = $stmt->fetch();

    if (!$product) {
        echo json_encode([
            'images' => [],
            'error' => 'Product not found.',
        ]);
        exit;
    }

    $imageResults = [];

    $primaryImage = trim((string) ($product['image'] ?? ''));
    if ($primaryImage !== '') {
        $imageResults[] = [
            'url' => '../' . ltrim($primaryImage, '/'),
            'label' => $product['name'] ?? 'Product image',
        ];
    }

    // Added: also return any additional gallery images stored for the product.
    $extraStmt = $pdo->prepare('SELECT file_path FROM product_images WHERE product_id = ? ORDER BY sort_order ASC, id ASC');
    $extraStmt->execute([$productId]);
    foreach ($extraStmt->fetchAll(PDO::FETCH_COLUMN) as $path) {
        $path = trim((string) $path);
        if ($path === '') {
            continue;
        }
        $imageResults[] = [
            'url' => '../' . ltrim($path, '/'),
            'label' => $product['name'] ?? 'Product image',
        ];
    }

    echo json_encode([
        'images' => $imageResults,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'images' => [],
        'error' => 'Unexpected error while loading images.',
    ]);
}
