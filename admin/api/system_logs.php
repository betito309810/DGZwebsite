<?php
require __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../includes/system_logs_helpers.php';

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

try {
    $pdo = db();
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    return;
}

$filters = systemLogsNormaliseFilters([
    'range' => $_GET['range'] ?? null,
    'search' => $_GET['search'] ?? null,
]);

try {
    $result = fetchSystemLogEntries($pdo, $filters, 100);
    $logs = $result['logs'] ?? [];
    $count = count($logs);
    $hasMore = (bool) ($result['has_more'] ?? false);
    $rowsHtml = renderSystemLogRows($logs);
    $summary = buildSystemLogsSummary($count, $hasMore, $result['filters'] ?? $filters);

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'rows_html' => $rowsHtml,
        'count' => $count,
        'has_more' => $hasMore,
        'summary' => $summary,
        'filters' => $result['filters'] ?? $filters,
        'limit' => (int) ($result['limit'] ?? 100),
    ]);
} catch (Throwable $e) {
    error_log('Failed to fetch system logs via API: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Unable to load system logs']);
}
