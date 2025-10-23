<?php
/**
 * Prepare variables required by the System Logs settings panel.
 *
 * Expects $pdo and $role to be available in the including scope.
 */

if (!isset($pdo)) {
    throw new RuntimeException('System logs data requires an initialised $pdo connection.');
}

require_once __DIR__ . '/system_logs_helpers.php';

$systemLogsFilters = systemLogsNormaliseFilters([
    'range' => $_GET['log_range'] ?? null,
    'search' => $_GET['log_search'] ?? null,
]);

$systemLogsError = null;
$systemLogsResult = [
    'logs' => [],
    'has_more' => false,
    'limit' => 100,
    'filters' => $systemLogsFilters,
];

try {
    $systemLogsResult = fetchSystemLogEntries($pdo, $systemLogsFilters, 100);
} catch (Throwable $e) {
    $systemLogsError = 'Unable to load recent system logs.';
    error_log('Failed to load system logs: ' . $e->getMessage());
}

$systemLogs = $systemLogsResult['logs'] ?? [];
$systemLogsHasMore = (bool) ($systemLogsResult['has_more'] ?? false);
$systemLogsLimit = (int) ($systemLogsResult['limit'] ?? 100);
$systemLogsCount = count($systemLogs);
$systemLogsRowsHtml = renderSystemLogRows($systemLogs);
$systemLogsSummary = buildSystemLogsSummary($systemLogsCount, $systemLogsHasMore, $systemLogsResult['filters'] ?? $systemLogsFilters);
