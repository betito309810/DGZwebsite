<?php

declare(strict_types=1);

require __DIR__ . '/../dgz_motorshop_system/config/config.php';

//
// This maintenance script assigns non-sequential tracking codes to existing online orders.
// It will add the tracking_code column when needed and then generate codes for orders that
// appear to be online purchases (based on order_type and proof of payment).
//

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function ordersHasColumn(PDO $pdo, string $column): bool
{
    $stmt = $pdo->prepare('SHOW COLUMNS FROM orders LIKE ?');
    $stmt->execute([$column]);

    return $stmt->fetch() !== false;
}

function ordersSupportsTrackingCodes(PDO $pdo): bool
{
    return ordersHasColumn($pdo, 'tracking_code');
}

function ensureTrackingCodeColumn(PDO $pdo): void
{
    if (ordersSupportsTrackingCodes($pdo)) {
        return;
    }

    $sql = "ALTER TABLE orders ADD COLUMN tracking_code VARCHAR(20) NULL UNIQUE";
    $pdo->exec($sql);
    echo "Executed schema change: {$sql}\n";
}

function generateTrackingCodeCandidate(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $segment = static function () use ($alphabet): string {
        $characters = '';
        for ($i = 0; $i < 4; $i++) {
            $characters .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $characters;
    };

    return 'DGZ-' . $segment() . '-' . $segment();
}

function generateUniqueTrackingCode(PDO $pdo, int $maxAttempts = 8): string
{
    for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
        $code = generateTrackingCodeCandidate();
        $stmt = $pdo->prepare('SELECT 1 FROM orders WHERE tracking_code = ? LIMIT 1');
        $stmt->execute([$code]);

        if ($stmt->fetchColumn() === false) {
            return $code;
        }
    }

    throw new RuntimeException('Unable to generate a unique tracking code after multiple attempts.');
}

try {
    ensureTrackingCodeColumn($pdo);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Failed to ensure tracking_code column: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

$hasOrderType = false;
try {
    $hasOrderType = ordersHasColumn($pdo, 'order_type');
} catch (Throwable $exception) {
    fwrite(STDERR, 'Warning: unable to detect order_type column: ' . $exception->getMessage() . PHP_EOL);
}

$criteria = [];
if ($hasOrderType) {
    $criteria[] = "LOWER(order_type) = 'online'";
}
$criteria[] = "(payment_proof IS NOT NULL AND payment_proof <> '')";

$whereClause = '';
if (!empty($criteria)) {
    $whereClause = ' AND (' . implode(' OR ', $criteria) . ')';
}

$sql = 'SELECT id FROM orders WHERE tracking_code IS NULL' . $whereClause . ' AND (customer_name IS NULL OR LOWER(customer_name) NOT LIKE ?)' ;
$stmt = $pdo->prepare($sql);
$stmt->execute(['%walkin%']);
$orderIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

if (empty($orderIds)) {
    echo "No online orders without tracking codes were found.\n";
    exit(0);
}

$updateStmt = $pdo->prepare('UPDATE orders SET tracking_code = ? WHERE id = ?');
$updatedCount = 0;

foreach ($orderIds as $orderId) {
    $code = generateUniqueTrackingCode($pdo);
    $updateStmt->execute([$code, $orderId]);
    echo "Assigned tracking code {$code} to order #{$orderId}.\n";
    $updatedCount++;
}

echo "Finished assigning tracking codes to {$updatedCount} order(s).\n";
