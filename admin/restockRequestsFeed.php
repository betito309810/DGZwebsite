<?php
require __DIR__ . '/../config/config.php';
require_once __DIR__ . '/includes/restock_request_helpers.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$pdo = db();
$role = $_SESSION['role'] ?? '';

ensureRestockVariantColumns($pdo);

/**
 * Fetch every restock request along with product and requester metadata.
 *
 * @param PDO $pdo Active database connection.
 * @return array<int, array<string, mixed>>
 */
function fetchRestockRequests(PDO $pdo): array
{
    $sql = '
        SELECT rr.*, p.name AS product_name, p.code AS product_code,
               rr.variant_label,
               pv.label AS variant_current_label,
               COALESCE(requester.name, rr.requested_by_name) AS requester_name
        FROM restock_requests rr
        LEFT JOIN products p ON p.id = rr.product_id
        LEFT JOIN product_variants pv ON pv.id = rr.variant_id
        LEFT JOIN users requester ON requester.id = rr.requested_by
        ORDER BY rr.created_at DESC
    ';

    $stmt = $pdo->query($sql);
    if (!$stmt) {
        return [];
    }

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Fetch the restock request history to power the status tab.
 *
 * @param PDO $pdo Active database connection.
 * @return array<int, array<string, mixed>>
 */
function fetchRestockHistory(PDO $pdo): array
{
    $sql = '
        SELECT h.*, rr.quantity_requested AS request_quantity,
               rr.priority_level AS request_priority,
               rr.category AS request_category,
               rr.brand AS request_brand,
               rr.supplier AS request_supplier,
               rr.variant_label,
               pv.label AS variant_current_label,
               p.name AS product_name, p.code AS product_code,
               COALESCE(requester.name, rr.requested_by_name) AS requester_name,
               COALESCE(status_user.name, h.noted_by_name) AS status_user_name,
               COALESCE(reviewer.name, rr.reviewed_by_name) AS reviewer_name
        FROM restock_request_history h
        JOIN restock_requests rr ON rr.id = h.request_id
        LEFT JOIN products p ON p.id = rr.product_id
        LEFT JOIN product_variants pv ON pv.id = rr.variant_id
        LEFT JOIN users requester ON requester.id = rr.requested_by
        LEFT JOIN users status_user ON status_user.id = h.noted_by
        LEFT JOIN users reviewer ON reviewer.id = rr.reviewed_by
        ORDER BY h.created_at DESC
    ';

    $stmt = $pdo->query($sql);
    if (!$stmt) {
        return [];
    }

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Convert a mixed variant label payload into a single readable string.
 */
function resolveVariantLabel(array $row): ?string
{
    $variant = $row['variant_label'] ?? '';
    if ($variant === '' && !empty($row['variant_current_label'])) {
        $variant = $row['variant_current_label'];
    }

    return $variant !== '' ? (string) $variant : null;
}

/**
 * Produce the CSS class used to color-code request priorities.
 */
function determinePriorityClass(string $priority): string
{
    switch (strtolower($priority)) {
        case 'high':
            return 'badge-high';
        case 'medium':
            return 'badge-medium';
        default:
            return 'badge-low';
    }
}

/**
 * Produce the CSS class used to color-code status entries.
 */
function determineStatusClass(string $status): string
{
    switch (strtolower($status)) {
        case 'approved':
            return 'status-approved';
        case 'denied':
        case 'declined':
            return 'status-denied';
        case 'fulfilled':
            return 'status-fulfilled';
        default:
            return 'status-pending';
    }
}

/**
 * Generate a human-readable timestamp for table display.
 */
function formatRestockTimestamp(?string $timestamp): string
{
    if (empty($timestamp)) {
        return 'N/A';
    }

    $parsed = strtotime($timestamp);
    if ($parsed === false) {
        return 'N/A';
    }

    return date('M d, Y H:i', $parsed);
}

/**
 * Shape a pending request row for the JSON response.
 */
function transformPendingRequest(array $row): array
{
    $variantLabel = resolveVariantLabel($row);
    $priority = strtolower((string) ($row['priority_level'] ?? ''));

    return [
        'id' => (int) ($row['id'] ?? 0),
        'created_at_display' => formatRestockTimestamp($row['created_at'] ?? null),
        'product_name' => (string) ($row['product_name'] ?? 'Product removed'),
        'product_code' => $row['product_code'] ?? null,
        'variant_label' => $variantLabel,
        'category' => (string) ($row['category'] ?? ''),
        'brand' => (string) ($row['brand'] ?? ''),
        'supplier' => (string) ($row['supplier'] ?? ''),
        'quantity_requested' => (int) ($row['quantity_requested'] ?? 0),
        'priority_label' => $priority !== '' ? ucfirst($priority) : 'Pending',
        'priority_class' => determinePriorityClass($priority),
        'requester_name' => (string) ($row['requester_name'] ?? 'Unknown'),
        'notes' => (string) ($row['notes'] ?? ''),
    ];
}

/**
 * Shape a history entry row for the JSON response.
 */
function transformHistoryEntry(array $row): array
{
    $variantLabel = resolveVariantLabel($row);
    $priority = strtolower((string) ($row['request_priority'] ?? ''));
    $status = strtolower((string) ($row['status'] ?? 'pending'));
    $reviewerName = $row['reviewer_name'] ?? null;

    if ($reviewerName === null || $reviewerName === '') {
        $reviewerName = $status === 'pending' ? '—' : 'Unknown';
    }

    return [
        'id' => (int) ($row['id'] ?? 0),
        'created_at_display' => formatRestockTimestamp($row['created_at'] ?? null),
        'product_name' => (string) ($row['product_name'] ?? 'Product removed'),
        'product_code' => $row['product_code'] ?? null,
        'variant_label' => $variantLabel,
        'category' => (string) ($row['request_category'] ?? ''),
        'brand' => (string) ($row['request_brand'] ?? ''),
        'supplier' => (string) ($row['request_supplier'] ?? ''),
        'quantity_requested' => (int) ($row['request_quantity'] ?? 0),
        'priority_label' => $priority !== '' ? ucfirst($priority) : 'Pending',
        'priority_class' => determinePriorityClass($priority),
        'requester_name' => (string) ($row['requester_name'] ?? 'Unknown'),
        'status_label' => ucfirst($status),
        'status_class' => determineStatusClass($status),
        'status_user_name' => (string) ($row['status_user_name'] ?? 'System'),
        'reviewer_name' => (string) $reviewerName,
    ];
}

try {
    $requests = fetchRestockRequests($pdo);
    $pending = array_values(array_filter($requests, static function (array $row): bool {
        return strtolower((string) ($row['status'] ?? 'pending')) === 'pending';
    }));

    $history = fetchRestockHistory($pdo);

    $payload = [
        'pending' => array_map('transformPendingRequest', $pending),
        'history' => array_map('transformHistoryEntry', $history),
        'badge_count' => count($pending),
        'can_manage' => $role === 'admin',
    ];

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'data' => $payload,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('Failed to build restock requests feed: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
    ]);
}
