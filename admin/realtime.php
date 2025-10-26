<?php
require __DIR__ . '/../config/config.php';
require_once __DIR__ . '/includes/restock_request_helpers.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    exit;
}

session_write_close();

try {
    $pdo = db();
} catch (Throwable $e) {
    http_response_code(500);
    exit;
}

ignore_user_abort(true);
set_time_limit(0);

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

while (ob_get_level() > 0) {
    ob_end_flush();
}

function fetchOnlineOrderSnapshot(PDO $pdo): array
{
    $snapshot = [
        'pendingCount' => 0,
        'badgeCount' => 0,
        'latestId' => 0,
        'latestCreatedAt' => 0,
    ];

    try {
        $trackedStatuses = ['pending', 'payment_verification', 'approved', 'delivery'];
        $count = countOnlineOrdersByStatus($pdo, $trackedStatuses);
        $snapshot['pendingCount'] = $count;
        $snapshot['badgeCount'] = $count;
    } catch (Throwable $e) {
        error_log('SSE countOnlineOrdersByStatus failed: ' . $e->getMessage());
    }

    try {
        $where = getOnlineOrdersBaseCondition();
        $sql = 'SELECT COALESCE(MAX(id), 0) AS latest_id, COALESCE(MAX(UNIX_TIMESTAMP(created_at)), 0) AS latest_created '
             . 'FROM orders WHERE ' . $where;
        $stmt = $pdo->query($sql);
        if ($stmt) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $snapshot['latestId'] = (int) ($row['latest_id'] ?? 0);
            $snapshot['latestCreatedAt'] = (int) ($row['latest_created'] ?? 0);
        }
    } catch (Throwable $e) {
        error_log('SSE latest order lookup failed: ' . $e->getMessage());
    }

    return $snapshot;
}

function fetchRestockSnapshot(PDO $pdo): array
{
    $snapshot = [
        'pendingCount' => 0,
        'latestId' => 0,
        'latestCreatedAt' => 0,
    ];

    try {
        $snapshot['pendingCount'] = countPendingRestockRequests($pdo);
    } catch (Throwable $e) {
        error_log('SSE countPendingRestockRequests failed: ' . $e->getMessage());
    }

    try {
        $stmt = $pdo->query('SELECT COALESCE(MAX(id), 0) AS latest_id, COALESCE(MAX(UNIX_TIMESTAMP(created_at)), 0) AS latest_created FROM restock_requests');
        if ($stmt) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $snapshot['latestId'] = (int) ($row['latest_id'] ?? 0);
            $snapshot['latestCreatedAt'] = (int) ($row['latest_created'] ?? 0);
        }
    } catch (Throwable $e) {
        error_log('SSE latest restock lookup failed: ' . $e->getMessage());
    }

    return $snapshot;
}

echo "retry: 4000\n\n";
flush();

$lastSnapshot = null;

while (!connection_aborted()) {
    $currentSnapshot = [
        'pos' => fetchOnlineOrderSnapshot($pdo),
        'stock' => fetchRestockSnapshot($pdo),
    ];

    if ($currentSnapshot !== $lastSnapshot) {
        $payload = $currentSnapshot;
        $payload['generatedAt'] = time();

        echo "event: update\n";
        echo 'data: ' . json_encode($payload) . "\n\n";
        $lastSnapshot = $currentSnapshot;
    } else {
        echo ': heartbeat ' . time() . "\n\n";
    }

    @ob_flush();
    @flush();

    sleep(3);
}
