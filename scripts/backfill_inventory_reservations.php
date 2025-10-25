<?php
declare(strict_types=1);

require __DIR__ . '/../dgz_motorshop_system/config/config.php';

$pdo = db();

$summary = inventoryReservationsBackfillPendingOrders($pdo);

printf(
    "Processed %d pending orders. Created reservations for %d orders.\n",
    (int) ($summary['processed'] ?? 0),
    (int) ($summary['created'] ?? 0)
);
