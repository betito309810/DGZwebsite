<?php
/**
 * Helpers shared between inventory-related pages for managing restock requests.
 */

if (!function_exists('restockTableHasColumn')) {
    /**
     * Check whether the given table already contains the specified column.
     */
    function restockTableHasColumn(PDO $pdo, string $table, string $column): bool
    {
        try {
            $stmt = $pdo->prepare(
                'SELECT 1
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = :table
                   AND COLUMN_NAME = :column
                 LIMIT 1'
            );

            if (!$stmt) {
                return false;
            }

            if ($stmt->execute([
                ':table' => $table,
                ':column' => $column,
            ])) {
                return $stmt->fetchColumn() !== false;
            }
        } catch (Throwable $e) {
            error_log('restockTableHasColumn failed: ' . $e->getMessage());
        }

        return false;
    }
}

if (!function_exists('ensureRestockVariantColumns')) {
    /**
     * Add variant tracking columns to restock_requests when they are missing.
     */
    function ensureRestockVariantColumns(PDO $pdo): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }
        $ensured = true;

        try {
            $tableCheck = $pdo->query("SHOW TABLES LIKE 'restock_requests'");
            if (!$tableCheck || $tableCheck->fetchColumn() === false) {
                return;
            }

            if (!restockTableHasColumn($pdo, 'restock_requests', 'variant_id')) {
                $pdo->exec('ALTER TABLE restock_requests ADD COLUMN variant_id INT NULL AFTER product_id');
            }

            if (!restockTableHasColumn($pdo, 'restock_requests', 'variant_label')) {
                $pdo->exec('ALTER TABLE restock_requests ADD COLUMN variant_label VARCHAR(255) NULL AFTER variant_id');
            }
        } catch (Throwable $e) {
            error_log('Unable to ensure restock variant columns: ' . $e->getMessage());
        }
    }
}

if (!function_exists('formatRestockDisplayDate')) {
    /**
     * Format a datetime string for display in tables.
     */
    function formatRestockDisplayDate(?string $datetime): string
    {
        if (empty($datetime)) {
            return 'N/A';
        }

        $timestamp = strtotime((string) $datetime);
        if ($timestamp === false) {
            return (string) $datetime;
        }

        return date('M d, Y H:i', $timestamp);
    }
}

if (!function_exists('getPriorityClass')) {
    /**
     * Map a restock priority label to the badge class used by the UI.
     */
    function getPriorityClass(string $priority): string
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
}

if (!function_exists('getStatusClass')) {
    /**
     * Map a restock status to the CSS class used for badges in the history table.
     */
    function getStatusClass(string $status): string
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
}

if (!function_exists('fetchRestockRequestCollections')) {
    /**
     * Load pending requests, resolved requests, and history entries for the admin tables.
     */
    function fetchRestockRequestCollections(PDO $pdo): array
    {
        ensureRestockVariantColumns($pdo);

        $requests = [];
        try {
            $stmt = $pdo->query('
                SELECT rr.*, p.name AS product_name, p.code AS product_code,
                       rr.variant_label,
                       pv.label AS variant_current_label,
                       COALESCE(requester.name, rr.requested_by_name) AS requester_name,
                       COALESCE(reviewer.name, rr.reviewed_by_name) AS reviewer_name
                FROM restock_requests rr
                LEFT JOIN products p ON p.id = rr.product_id
                LEFT JOIN product_variants pv ON pv.id = rr.variant_id
                LEFT JOIN users requester ON requester.id = rr.requested_by
                LEFT JOIN users reviewer ON reviewer.id = rr.reviewed_by
                ORDER BY rr.created_at DESC
            ');

            if ($stmt) {
                $requests = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }
        } catch (Throwable $e) {
            error_log('Failed to load restock requests: ' . $e->getMessage());
            $requests = [];
        }

        foreach ($requests as &$requestRow) {
            if (empty($requestRow['variant_label']) && !empty($requestRow['variant_current_label'])) {
                $requestRow['variant_label'] = $requestRow['variant_current_label'];
            }
        }
        unset($requestRow);

        $pendingRequests = array_values(array_filter($requests, static function ($row) {
            return strtolower((string) ($row['status'] ?? 'pending')) === 'pending';
        }));

        $resolvedRequests = array_values(array_filter($requests, static function ($row) {
            return strtolower((string) ($row['status'] ?? 'pending')) !== 'pending';
        }));

        $historyEntries = [];
        try {
            $historyStmt = $pdo->query('
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
            ');

            if ($historyStmt) {
                $historyEntries = $historyStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }
        } catch (Throwable $e) {
            error_log('Failed to load restock history: ' . $e->getMessage());
            $historyEntries = [];
        }

        foreach ($historyEntries as &$historyRow) {
            if (empty($historyRow['variant_label']) && !empty($historyRow['variant_current_label'])) {
                $historyRow['variant_label'] = $historyRow['variant_current_label'];
            }
        }
        unset($historyRow);

        return [
            'requests' => $requests,
            'pending' => $pendingRequests,
            'resolved' => $resolvedRequests,
            'history' => $historyEntries,
        ];
    }
}

if (!function_exists('renderRestockRequestRows')) {
    /**
     * Render the table rows for pending or resolved restock requests.
     */
    function renderRestockRequestRows(array $requests, string $role): string
    {
        if (empty($requests)) {
            return '';
        }

        $isAdmin = strtolower((string) $role) === 'admin';
        $buffer = '';

        foreach ($requests as $request) {
            $submittedAt = formatRestockDisplayDate($request['created_at'] ?? null);
            $productName = htmlspecialchars($request['product_name'] ?? 'Product removed', ENT_QUOTES, 'UTF-8');
            $variantLabel = htmlspecialchars($request['variant_label'] ?? '', ENT_QUOTES, 'UTF-8');
            $productCode = htmlspecialchars($request['product_code'] ?? '', ENT_QUOTES, 'UTF-8');
            $category = htmlspecialchars($request['category'] ?? '', ENT_QUOTES, 'UTF-8');
            $brand = htmlspecialchars($request['brand'] ?? '', ENT_QUOTES, 'UTF-8');
            $supplier = htmlspecialchars($request['supplier'] ?? '', ENT_QUOTES, 'UTF-8');
            $quantity = (int) ($request['quantity_requested'] ?? 0);
            $priority = strtolower((string) ($request['priority_level'] ?? ''));
            $priorityClass = getPriorityClass($priority);
            $priorityLabel = ucfirst($priority ?: 'low');
            $requesterName = htmlspecialchars($request['requester_name'] ?? 'Unknown', ENT_QUOTES, 'UTF-8');
            $notesRaw = (string) ($request['notes'] ?? '');
            $notes = $notesRaw !== '' ? nl2br(htmlspecialchars($notesRaw, ENT_QUOTES, 'UTF-8')) : '<span class="muted">No notes</span>';
            $requestId = (int) ($request['id'] ?? 0);

            $buffer .= '<tr>';
            $buffer .= '<td>' . $submittedAt . '</td>';
            $buffer .= '<td><div class="product-cell">';
            $buffer .= '<span class="product-name">' . $productName . '</span>';
            if ($variantLabel !== '') {
                $buffer .= '<span class="product-variant">Variant: ' . $variantLabel . '</span>';
            }
            if ($productCode !== '') {
                $buffer .= '<span class="product-code">Code: ' . $productCode . '</span>';
            }
            $buffer .= '</div></td>';
            $buffer .= '<td>' . $category . '</td>';
            $buffer .= '<td>' . $brand . '</td>';
            $buffer .= '<td>' . $supplier . '</td>';
            $buffer .= '<td>' . $quantity . '</td>';
            $buffer .= '<td><span class="priority-badge ' . htmlspecialchars($priorityClass, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($priorityLabel, ENT_QUOTES, 'UTF-8') . '</span></td>';
            $buffer .= '<td>' . $requesterName . '</td>';
            $buffer .= '<td class="notes-cell">' . $notes . '</td>';

            if ($isAdmin) {
                $buffer .= '<td class="action-cell">';
                $buffer .= '<form method="post" class="inline-form">';
                $buffer .= '<input type="hidden" name="request_id" value="' . $requestId . '">';
                $buffer .= '<input type="hidden" name="request_action" value="approve">';
                $buffer .= '<button type="submit" class="btn-approve">';
                $buffer .= '<i class="fas fa-check"></i> Approve';
                $buffer .= '</button>';
                $buffer .= '</form>';
                $buffer .= '<form method="post" class="inline-form">';
                $buffer .= '<input type="hidden" name="request_id" value="' . $requestId . '">';
                $buffer .= '<input type="hidden" name="request_action" value="decline">';
                $buffer .= '<button type="submit" class="btn-decline">';
                $buffer .= '<i class="fas fa-times"></i> Decline';
                $buffer .= '</button>';
                $buffer .= '</form>';
                $buffer .= '</td>';
            }

            $buffer .= '</tr>';
        }

        return $buffer;
    }
}

if (!function_exists('renderRestockHistoryRows')) {
    /**
     * Render the history table rows for restock requests.
     */
    function renderRestockHistoryRows(array $historyEntries): string
    {
        if (empty($historyEntries)) {
            return '';
        }

        $buffer = '';

        foreach ($historyEntries as $entry) {
            $loggedAt = formatRestockDisplayDate($entry['created_at'] ?? null);
            $productName = htmlspecialchars($entry['product_name'] ?? 'Product removed', ENT_QUOTES, 'UTF-8');
            $variantLabel = htmlspecialchars($entry['variant_label'] ?? '', ENT_QUOTES, 'UTF-8');
            $productCode = htmlspecialchars($entry['product_code'] ?? '', ENT_QUOTES, 'UTF-8');
            $category = htmlspecialchars($entry['request_category'] ?? '', ENT_QUOTES, 'UTF-8');
            $brand = htmlspecialchars($entry['request_brand'] ?? '', ENT_QUOTES, 'UTF-8');
            $supplier = htmlspecialchars($entry['request_supplier'] ?? '', ENT_QUOTES, 'UTF-8');
            $quantity = (int) ($entry['request_quantity'] ?? 0);
            $priority = strtolower((string) ($entry['request_priority'] ?? ''));
            $priorityClass = getPriorityClass($priority);
            $priorityLabel = ucfirst($priority ?: 'low');
            $requesterName = htmlspecialchars($entry['requester_name'] ?? 'Unknown', ENT_QUOTES, 'UTF-8');
            $statusRaw = strtolower((string) ($entry['status'] ?? 'pending'));
            $statusClass = getStatusClass($statusRaw);
            $statusLabel = ucfirst($statusRaw ?: 'pending');
            $statusUserName = htmlspecialchars($entry['status_user_name'] ?? 'System', ENT_QUOTES, 'UTF-8');
            $reviewerName = htmlspecialchars($entry['reviewer_name'] ?? ($statusRaw === 'pending' ? '—' : 'Unknown'), ENT_QUOTES, 'UTF-8');

            $buffer .= '<tr>';
            $buffer .= '<td>' . $loggedAt . '</td>';
            $buffer .= '<td><div class="product-cell">';
            $buffer .= '<span class="product-name">' . $productName . '</span>';
            if ($variantLabel !== '') {
                $buffer .= '<span class="product-variant">Variant: ' . $variantLabel . '</span>';
            }
            if ($productCode !== '') {
                $buffer .= '<span class="product-code">Code: ' . $productCode . '</span>';
            }
            $buffer .= '</div></td>';
            $buffer .= '<td>' . $category . '</td>';
            $buffer .= '<td>' . $brand . '</td>';
            $buffer .= '<td>' . $supplier . '</td>';
            $buffer .= '<td>' . $quantity . '</td>';
            $buffer .= '<td><span class="priority-badge ' . htmlspecialchars($priorityClass, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($priorityLabel, ENT_QUOTES, 'UTF-8') . '</span></td>';
            $buffer .= '<td>' . $requesterName . '</td>';
            $buffer .= '<td><span class="status-badge ' . htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') . '</span></td>';
            $buffer .= '<td>' . $statusUserName . '</td>';
            $buffer .= '<td>' . $reviewerName . '</td>';
            $buffer .= '</tr>';
        }

        return $buffer;
    }
}
