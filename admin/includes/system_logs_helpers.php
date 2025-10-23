<?php

/**
 * Return the selectable time ranges for the system logs view.
 */
function systemLogsAvailableRanges(): array
{
    return [
        '24h' => 'Last 24 hours',
        '7d'  => 'Last 7 days',
        '30d' => 'Last 30 days',
        'all' => 'All time',
    ];
}

/**
 * Normalise log filters provided by the request.
 */
function systemLogsNormaliseFilters(array $input): array
{
    $ranges = array_keys(systemLogsAvailableRanges());
    $range = isset($input['range']) ? trim((string) $input['range']) : '7d';

    if (!in_array($range, $ranges, true)) {
        $range = '7d';
    }

    $search = isset($input['search']) ? trim((string) $input['search']) : '';

    if ($search !== '') {
        if (function_exists('mb_substr')) {
            $search = mb_substr($search, 0, 120);
        } else {
            $search = substr($search, 0, 120);
        }
    }

    return [
        'range' => $range,
        'search' => $search,
    ];
}

/**
 * Provide a human-readable label for the selected range.
 */
function systemLogsRangeLabel(string $range): string
{
    $ranges = systemLogsAvailableRanges();

    return $ranges[$range] ?? $ranges['7d'];
}

/**
 * Fetch log entries using the provided filters.
 *
 * @return array{filters: array, logs: array<int, array>, has_more: bool, limit: int}
 */
function fetchSystemLogEntries(PDO $pdo, array $filters, int $limit = 100): array
{
    $filters = systemLogsNormaliseFilters($filters);
    $limit = max(1, min($limit, 500));

    $params = [];
    $where = [];

    switch ($filters['range']) {
        case '24h':
            $where[] = 'l.created_at >= (NOW() - INTERVAL 1 DAY)';
            break;
        case '7d':
            $where[] = 'l.created_at >= (NOW() - INTERVAL 7 DAY)';
            break;
        case '30d':
            $where[] = 'l.created_at >= (NOW() - INTERVAL 30 DAY)';
            break;
        case 'all':
        default:
            break;
    }

    if ($filters['search'] !== '') {
        $params[':term'] = '%' . $filters['search'] . '%';
        $where[] = '(l.event LIKE :term OR l.description LIKE :term OR u.name LIKE :term OR u.email LIKE :term)';
    }

    $sql = 'SELECT l.id, l.event, l.description, l.created_at, l.ip_address, l.user_id, '
        . 'u.name AS user_name, u.email AS user_email, u.role AS user_role '
        . 'FROM system_logs l '
        . 'LEFT JOIN users u ON l.user_id = u.id';

    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $sql .= ' ORDER BY l.created_at DESC, l.id DESC LIMIT :limit';

    $stmt = $pdo->prepare($sql);

    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }

    $limitPlusOne = $limit + 1;
    $stmt->bindValue(':limit', $limitPlusOne, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $hasMore = count($rows) > $limit;
    if ($hasMore) {
        array_pop($rows);
    }

    $logs = array_map('systemLogsTransformRow', $rows);

    return [
        'filters' => $filters,
        'logs' => $logs,
        'has_more' => $hasMore,
        'limit' => $limit,
    ];
}

/**
 * Convert a raw database row into display-friendly data.
 */
function systemLogsTransformRow(array $row): array
{
    return [
        'id' => isset($row['id']) ? (int) $row['id'] : 0,
        'event' => (string) ($row['event'] ?? ''),
        'event_label' => systemLogsEventLabel((string) ($row['event'] ?? '')),
        'description' => (string) ($row['description'] ?? ''),
        'created_at' => $row['created_at'] ?? null,
        'created_at_formatted' => systemLogsFormatTimestamp($row['created_at'] ?? null),
        'ip_address' => isset($row['ip_address']) ? trim((string) $row['ip_address']) : '',
        'user_id' => isset($row['user_id']) ? (int) $row['user_id'] : null,
        'user_name' => isset($row['user_name']) ? (string) $row['user_name'] : null,
        'user_email' => isset($row['user_email']) ? (string) $row['user_email'] : null,
        'user_role' => isset($row['user_role']) ? (string) $row['user_role'] : null,
        'user_display' => systemLogsUserDisplayName($row),
    ];
}

/**
 * Format the log event label.
 */
function systemLogsEventLabel(string $event): string
{
    $event = trim($event);

    if ($event === '') {
        return 'Event';
    }

    $normalized = preg_replace('/[_\-]+/', ' ', strtolower($event));

    return ucwords($normalized ?? $event);
}

/**
 * Generate a human readable timestamp for the log entry.
 */
function systemLogsFormatTimestamp(?string $value): string
{
    if ($value === null || $value === '') {
        return '—';
    }

    $timestamp = strtotime($value);

    if ($timestamp === false) {
        return $value;
    }

    return date('M d, Y g:i A', $timestamp);
}

/**
 * Resolve the best available display name for a log actor.
 */
function systemLogsUserDisplayName(array $row): string
{
    $name = isset($row['user_name']) ? trim((string) $row['user_name']) : '';
    $email = isset($row['user_email']) ? trim((string) $row['user_email']) : '';
    $userId = isset($row['user_id']) ? (int) $row['user_id'] : 0;

    if ($name !== '') {
        return $name;
    }

    if ($email !== '') {
        return $email;
    }

    if ($userId > 0) {
        return 'User #' . $userId;
    }

    return 'System';
}

/**
 * Render table rows for the logs list.
 */
function renderSystemLogRows(array $logs): string
{
    if ($logs === []) {
        return '';
    }

    $html = '';

    foreach ($logs as $log) {
        $description = $log['description'] !== ''
            ? htmlspecialchars($log['description'], ENT_QUOTES, 'UTF-8')
            : '—';

        $actorName = htmlspecialchars($log['user_display'], ENT_QUOTES, 'UTF-8');
        $actorDetails = '';

        $email = isset($log['user_email']) ? trim((string) $log['user_email']) : '';
        if ($email !== '' && strcasecmp($email, $log['user_display']) !== 0) {
            $actorDetails .= '<span class="system-logs__actor-detail">' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '</span>';
        }

        $role = isset($log['user_role']) ? trim((string) $log['user_role']) : '';
        if ($role !== '') {
            $actorDetails .= '<span class="system-logs__actor-detail">' . htmlspecialchars(ucfirst($role), ENT_QUOTES, 'UTF-8') . '</span>';
        }

        $ip = $log['ip_address'] !== ''
            ? htmlspecialchars($log['ip_address'], ENT_QUOTES, 'UTF-8')
            : '—';

        $timestamp = htmlspecialchars($log['created_at_formatted'], ENT_QUOTES, 'UTF-8');
        $rawTimestamp = $log['created_at'] ?? '';
        $timestampAttr = $rawTimestamp !== '' ? ' title="' . htmlspecialchars($rawTimestamp, ENT_QUOTES, 'UTF-8') . '"' : '';

        $html .= '<tr>'
            . '<td>' . (int) $log['id'] . '</td>'
            . '<td><span class="system-logs__event">' . htmlspecialchars($log['event_label'], ENT_QUOTES, 'UTF-8') . '</span></td>'
            . '<td>' . $description . '</td>'
            . '<td><div class="system-logs__actor"><span class="system-logs__actor-name">' . $actorName . '</span>'
            . $actorDetails . '</div></td>'
            . '<td>' . $ip . '</td>'
            . '<td' . $timestampAttr . '>' . $timestamp . '</td>'
            . '</tr>';
    }

    return $html;
}

/**
 * Build a concise summary for the current result set.
 */
function buildSystemLogsSummary(int $count, bool $hasMore, array $filters): string
{
    $rangeLabel = systemLogsRangeLabel($filters['range'] ?? '7d');
    $parts = [];

    $parts[] = $count === 1
        ? 'Showing 1 log entry'
        : 'Showing ' . number_format($count) . ' log entries';

    if (!empty($filters['search'])) {
        $parts[] = 'matching "' . $filters['search'] . '"';
    }

    $parts[] = 'for ' . strtolower($rangeLabel);

    if ($hasMore) {
        $parts[] = '(limited to recent results)';
    }

    return implode(' ', $parts);
}
