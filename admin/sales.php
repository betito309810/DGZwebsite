<?php
require __DIR__ . '/../config/config.php';
if(empty($_SESSION['user_id'])){ header('Location: login.php'); exit; }

$pdo = db();
$role = $_SESSION['role'] ?? '';
enforceStaffAccess();
$supportsProcessedBy = ordersSupportsProcessedBy($pdo);

if (!function_exists('ordersHasColumn')) {
    function ordersHasColumn(PDO $pdo, string $column): bool
    {
        static $cache = [];

        if (!array_key_exists($column, $cache)) {
            try {
                $stmt = $pdo->prepare('SHOW COLUMNS FROM orders LIKE :column');
                $stmt->execute([':column' => $column]);
                $cache[$column] = $stmt->fetchColumn() !== false;
            } catch (Throwable $e) {
                error_log('ordersHasColumn detection failed: ' . $e->getMessage());
                $cache[$column] = false;
            }
        }

        return $cache[$column];
    }
}
$buildCashierFragments = static function (bool $enabled): array {
    if ($enabled) {
        return [
            'select' => 'u.username AS cashier_username, u.name AS cashier_name',
            'join'   => 'LEFT JOIN users u ON u.id = o.processed_by_user_id',
        ];
    }

    return [
        'select' => 'NULL AS cashier_username, NULL AS cashier_name',
        'join'   => '',
    ];
};

/**
 * Generate sales report for a given period.
 * Supports 'daily', 'weekly', 'monthly', and 'annually' periods.
 * Returns an associative array with total orders and total sales amount.
 *
 * @param PDO $pdo The PDO database connection object.
 * @param string $period The period for the report: 'daily', 'weekly', 'monthly', or 'annually'.
 * @return array Associative array with keys 'total_orders' and 'total_sales'.
 */
function generateSalesReport(PDO $pdo, string $period): array
{
    $sql = '';
    switch ($period) {
        case 'daily':
            // Sales for today
            $sql = "SELECT 
                        COUNT(*) AS total_orders, 
                        COALESCE(SUM(total), 0) AS total_sales 
                    FROM orders 
                    WHERE DATE(created_at) = CURDATE()
                    AND status IN ('approved','completed')";
            break;
        case 'weekly':
            // Sales for current week (Monday to Sunday)
            $sql = "SELECT 
                        COUNT(*) AS total_orders, 
                        COALESCE(SUM(total), 0) AS total_sales 
                    FROM orders 
                    WHERE YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1)
                    AND status IN ('approved','completed')";
            break;
        case 'monthly':
            // Sales for current month
            $sql = "SELECT 
                        COUNT(*) AS total_orders, 
                        COALESCE(SUM(total), 0) AS total_sales 
                    FROM orders 
                    WHERE YEAR(created_at) = YEAR(CURDATE()) 
                    AND MONTH(created_at) = MONTH(CURDATE())
                    AND status IN ('approved','completed')";
            break;
        case 'annually':
            // Sales for current year
            $sql = "SELECT 
                        COUNT(*) AS total_orders, 
                        COALESCE(SUM(total), 0) AS total_sales 
                    FROM orders 
                    WHERE YEAR(created_at) = YEAR(CURDATE())
                    AND status IN ('approved','completed')";
            break;
        default:
            // Invalid period, return zeros
            return ['total_orders' => 0, 'total_sales' => 0.0];
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    return [
        'total_orders' => (int)($result['total_orders'] ?? 0),
        'total_sales' => (float)($result['total_sales'] ?? 0.0)
    ];
}

function resolveCashierName(array $row): string
{
    $candidates = [];

    foreach (['cashier_name', 'cashier_username'] as $key) {
        if (isset($row[$key]) && $row[$key] !== null && $row[$key] !== '') {
            $candidates[] = $row[$key];
        }
    }

    foreach ($candidates as $candidate) {
        $candidate = trim((string) $candidate);
        if ($candidate !== '') {
            return $candidate;
        }
    }

    $userId = isset($row['processed_by_user_id']) ? (int) $row['processed_by_user_id'] : 0;
    if ($userId > 0) {
        static $cache = [];
        if (!array_key_exists($userId, $cache)) {
            $cache[$userId] = '';

            try {
                global $pdo;
                if ($pdo instanceof PDO) {
                    $resolved = fetchUserDisplayName($pdo, $userId);
                    if (is_string($resolved)) {
                        $cache[$userId] = $resolved;
                    }
                }
            } catch (Throwable $e) {
                error_log('Unable to resolve cashier name for listing: ' . $e->getMessage());
            }
        }

        $fallback = $cache[$userId];
        if (is_string($fallback)) {
            $fallback = trim($fallback);
            if ($fallback !== '') {
                return $fallback;
            }
        }
    }

    return 'Unassigned';
}

if (!function_exists('buildOrderTypeFilterCondition')) {
    /**
     * Build the SQL clause and parameters required to filter orders by type.
     *
     * When the "order_type" column is available we filter directly against it.
     * Otherwise we fall back to classifying walk-in transactions as any order
     * whose payment method matches a known cash-based keyword. All other
     * payment methods are treated as online orders.
     *
     * @param string $filter               The requested filter value.
     * @param bool   $hasOrderTypeColumn   Whether the orders table exposes the order_type column.
     * @return array{clause:string,params:array<string,string>,uses_payment_method_fallback:bool}
     */
    function buildOrderTypeFilterCondition(string $filter, bool $hasOrderTypeColumn): array
    {
        $normalizedFilter = strtolower(trim($filter));
        $result = [
            'clause' => '',
            'params' => [],
            'uses_payment_method_fallback' => false,
        ];

        if ($normalizedFilter === '' || $normalizedFilter === 'all') {
            $result['uses_payment_method_fallback'] = !$hasOrderTypeColumn;
            return $result;
        }

        if ($hasOrderTypeColumn) {
            $result['clause'] = "LOWER(TRIM(COALESCE(o.order_type, ''))) = :order_type_filter";
            $result['params'][':order_type_filter'] = $normalizedFilter;
            return $result;
        }

        $normalizedMethodExpression = "LOWER(TRIM(COALESCE(o.payment_method, '')))";
        $walkInKeywords = [
            'cash',
            'cash payment',
            'cash (walk-in)',
            'cash - walk-in',
            'walk-in',
            'walk in',
            'cash on delivery',
            'cash-on-delivery',
            'cod',
        ];

        $placeholders = [];
        foreach ($walkInKeywords as $index => $keyword) {
            $paramName = ':walkin_keyword_' . $index;
            $placeholders[] = $paramName;
            $result['params'][$paramName] = $keyword;
        }

        $comparisonList = $normalizedMethodExpression . ' IN (' . implode(', ', $placeholders) . ')';
        $result['uses_payment_method_fallback'] = true;

        if ($normalizedFilter === 'walkin') {
            $result['clause'] = $comparisonList;
            return $result;
        }

        // Treat all other filters ("online") as the inverse of the walk-in list.
        $result['clause'] = $normalizedMethodExpression . ' NOT IN (' . implode(', ', $placeholders) . ')';
        return $result;
    }
}

$validOrderTypes = ['all', 'walkin', 'online'];
$orderTypeFilter = isset($_GET['order_type']) ? strtolower(trim((string) $_GET['order_type'])) : 'all';
if (!in_array($orderTypeFilter, $validOrderTypes, true)) {
    $orderTypeFilter = 'all';
}

$hasOrderTypeColumn = false;
try {
    $hasOrderTypeColumn = ordersHasColumn($pdo, 'order_type');
} catch (Throwable $e) {
    error_log('Unable to determine order_type availability: ' . $e->getMessage());
    $hasOrderTypeColumn = false;
}

$orderTypeFilterDetails = buildOrderTypeFilterCondition($orderTypeFilter, $hasOrderTypeColumn);

// Handle CSV export FIRST - before any other queries
if(isset($_GET['export']) && $_GET['export'] == 'csv') {
    $exportConditions = ["o.status IN ('approved','completed')"];
    if ($orderTypeFilterDetails['clause'] !== '') {
        $exportConditions[] = $orderTypeFilterDetails['clause'];
    }

    $exportWhereClause = implode(' AND ', $exportConditions);

    $fragments = $buildCashierFragments($supportsProcessedBy);
    $cashierSelect = $fragments['select'];
    $cashierJoin = $fragments['join'];
    $export_sql = "SELECT o.*, $cashierSelect
        FROM orders o
        $cashierJoin
        WHERE $exportWhereClause
        ORDER BY o.created_at DESC";

    $export_stmt = null;
    try {
        $export_stmt = $pdo->prepare($export_sql);
        foreach ($orderTypeFilterDetails['params'] as $param => $value) {
            $export_stmt->bindValue($param, $value, PDO::PARAM_STR);
        }
        $export_stmt->execute();
    } catch (Throwable $e) {
        if ($supportsProcessedBy) {
            error_log('Cashier join failed during CSV export; retrying without processed_by_user_id: ' . $e->getMessage());
            $supportsProcessedBy = false;
            $fragments = $buildCashierFragments(false);
            $cashierSelect = $fragments['select'];
            $cashierJoin = $fragments['join'];
            $export_sql = "SELECT o.*, $cashierSelect
                FROM orders o
                $cashierJoin
                WHERE $exportWhereClause
                ORDER BY o.created_at DESC";
            $export_stmt = $pdo->prepare($export_sql);
            foreach ($orderTypeFilterDetails['params'] as $param => $value) {
                $export_stmt->bindValue($param, $value, PDO::PARAM_STR);
            }
            $export_stmt->execute();
        } else {
            throw $e;
        }
    }

    $export_orders = $export_stmt instanceof PDOStatement ? $export_stmt->fetchAll() : [];

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="sales.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID','Invoice','Cashier Name','Contact','Address','Total','Payment Method','Payment Reference','Proof Image','Status','Created At']);
    foreach($export_orders as $o) {

        fputcsv($out, [
            $o['id'],
            $o['invoice_number'] ?? '',
            resolveCashierName($o),
            $o['contact'],
            $o['address'],
            $o['total'],
            $o['payment_method'],
            ($details = parsePaymentProofValue($o['payment_proof'] ?? null, $o['reference_no'] ?? null))['reference'],
            $details['image'],
            $o['status'],
            $o['created_at']
        ]);
    }
    fclose($out);
    exit;
}

require_once __DIR__ . '/includes/inventory_notifications.php';
$notificationManageLink = 'inventory.php';
$inventoryNotificationData = loadInventoryNotifications($pdo);
$inventoryNotifications = $inventoryNotificationData['notifications'];
$inventoryNotificationCount = $inventoryNotificationData['active_count'];

// Fetch the authenticated user's information for the profile modal
$current_user = null;
try {
    $stmt = $pdo->prepare('SELECT name, role, created_at FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $current_user = $stmt->fetch();
} catch (Exception $e) {
    error_log('User lookup failed: ' . $e->getMessage());
}

function format_profile_date(?string $datetime): string
{
    if (!$datetime) {
        return 'N/A';
    }

    $timestamp = strtotime($datetime);
    if ($timestamp === false) {
        return 'N/A';
    }

    return date('F j, Y g:i A', $timestamp);
}

$profile_name = $current_user['name'] ?? 'N/A';
$profile_role = !empty($current_user['role']) ? ucfirst($current_user['role']) : 'N/A';
$profile_created = format_profile_date($current_user['created_at'] ?? null);

$invoiceSearch = isset($_GET['invoice']) ? trim((string) $_GET['invoice']) : '';
$hasInvoiceSearch = $invoiceSearch !== '';
$queryParams = [];
if ($hasInvoiceSearch) {
    $queryParams['invoice'] = $invoiceSearch;
}
if ($orderTypeFilter !== 'all') {
    $queryParams['order_type'] = $orderTypeFilter;
}

$exportUrlParams = $queryParams;
$exportUrlParams['export'] = 'csv';
$exportUrl = '?' . http_build_query($exportUrlParams);

// Pagination variables
$records_per_page = 20;
$current_page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
if ($current_page < 1) {
    $current_page = 1;
}

$baseConditions = ["o.status IN ('approved','completed','disapproved')"];
$sqlParams = [];

if ($hasInvoiceSearch) {
    $baseConditions[] = 'o.invoice_number LIKE :invoice_search';
    $sqlParams[':invoice_search'] = '%' . $invoiceSearch . '%';
}

if ($orderTypeFilterDetails['clause'] !== '') {
    $baseConditions[] = $orderTypeFilterDetails['clause'];
    foreach ($orderTypeFilterDetails['params'] as $param => $value) {
        $sqlParams[$param] = $value;
    }
}

$whereClause = implode(' AND ', $baseConditions);

// Count total records
$count_sql = "SELECT COUNT(*) FROM orders o WHERE $whereClause";
$count_stmt = $pdo->prepare($count_sql);
foreach ($sqlParams as $param => $value) {
    $count_stmt->bindValue($param, $value, PDO::PARAM_STR);
}
$count_stmt->execute();
$total_records = (int) $count_stmt->fetchColumn();
$total_pages = $total_records > 0 ? (int) ceil($total_records / $records_per_page) : 0;

if ($total_pages > 0 && $current_page > $total_pages) {
    $current_page = $total_pages;
} elseif ($total_pages === 0) {
    $current_page = 1;
}

$offset = ($current_page - 1) * $records_per_page;

// Get orders with pagination
$fragments = $buildCashierFragments($supportsProcessedBy);
$cashierSelect = $fragments['select'];
$cashierJoin = $fragments['join'];
$sql = "SELECT o.*, r.label AS decline_reason_label, $cashierSelect
        FROM orders o
        LEFT JOIN order_decline_reasons r ON r.id = o.decline_reason_id
        $cashierJoin
        WHERE $whereClause
        ORDER BY o.created_at DESC
        LIMIT :limit OFFSET :offset";

$stmt = null;
try {
    $stmt = $pdo->prepare($sql);
    foreach ($sqlParams as $param => $value) {
        $stmt->bindValue($param, $value, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $records_per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
} catch (Throwable $e) {
    if ($supportsProcessedBy) {
        error_log('Cashier join failed during sales listing; retrying without processed_by_user_id: ' . $e->getMessage());
        $supportsProcessedBy = false;
        $fragments = $buildCashierFragments(false);
        $cashierSelect = $fragments['select'];
        $cashierJoin = $fragments['join'];
        $sql = "SELECT o.*, r.label AS decline_reason_label, $cashierSelect
            FROM orders o
            LEFT JOIN order_decline_reasons r ON r.id = o.decline_reason_id
            $cashierJoin
            WHERE $whereClause
            ORDER BY o.created_at DESC
            LIMIT :limit OFFSET :offset";
        $stmt = $pdo->prepare($sql);
        foreach ($sqlParams as $param => $value) {
            $stmt->bindValue($param, $value, PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $records_per_page, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
    } else {
        throw $e;
    }
}

$orders = $stmt->fetchAll();

// Calculate showing info
if ($total_records === 0) {
    $start_record = 0;
    $end_record = 0;
} else {
    $start_record = $offset + 1;
    $end_record = min($offset + $records_per_page, $total_records);
}

$buildPageUrl = static function (int $page) use ($queryParams): string {
    $params = $queryParams;
    $params['page'] = $page;
    return '?' . http_build_query($params);
};
?>
<!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../dgz_motorshop_system/assets/css/style.css">
    <link rel="stylesheet" href="../dgz_motorshop_system/assets/css/sales/sales.css">
    <link rel="stylesheet" href="../dgz_motorshop_system/assets/css/sales/piechart.css">
    <link rel="stylesheet" href="../dgz_motorshop_system/assets/css/sales/transaction-modal.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <title>Sales</title>
</head>

<body>
    <!-- Sidebar -->
    <?php
        $activePage = 'sales.php';
        include __DIR__ . '/includes/sidebar.php';
    ?>

    <main class="main-content">
        <!-- Header -->
        <header class="header">
            <div class="header-left">
                <button class="mobile-toggle" onclick="toggleSidebar()">
                    <i class="fas fa-bars"></i>
                </button>
                <h2>Sales</h2>
            </div>
            <div class="header-right">
                <?php include __DIR__ . '/partials/notification_menu.php'; ?>
                <div class="user-menu">
                    <div class="user-avatar" onclick="toggleDropdown()">
                        <i class="fas fa-user"></i>
                    </div>
                    <div class="dropdown-menu" id="userDropdown">
                        <a href="settings.php" class="dropdown-item">
                            <i class="fas fa-cog"></i> Settings
                        </a>
                        <a href="login.php?logout=1" class="dropdown-item logout">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <!-- Filters and Actions -->
        <div class="sales-controls">
            <form method="get" class="sales-search-form" id="salesSearchForm">
                <div class="sales-search-group">
                    <input
                        type="text"
                        id="salesInvoiceSearch"
                        name="invoice"
                        placeholder="Search by invoice number"
                        value="<?=htmlspecialchars($invoiceSearch, ENT_QUOTES, 'UTF-8')?>"
                        class="sales-search-input"
                        data-sales-search-input
                        autocomplete="off"
                        aria-label="Search by invoice number"
                    >
                    <button
                        type="button"
                        class="sales-search-clear<?php if($hasInvoiceSearch) echo ' is-visible'; ?>"
                        aria-label="Clear invoice search"
                        data-sales-search-clear
                    >&times;</button>
                </div>
                <div class="sales-filter-group">
                    <label for="orderTypeFilter" class="sales-filter-label">Customer type</label>
                    <select
                        id="orderTypeFilter"
                        name="order_type"
                        class="sales-filter-select"
                        onchange="this.form.submit()"
                    >
                        <option value="all"<?php if($orderTypeFilter === 'all') echo ' selected'; ?>>All orders</option>
                        <option value="walkin"<?php if($orderTypeFilter === 'walkin') echo ' selected'; ?>>Walk-in orders</option>
                        <option value="online"<?php if($orderTypeFilter === 'online') echo ' selected'; ?>>Online orders</option>
                    </select>
                </div>
            </form>

            <div class="action-buttons">
                <a href="<?=htmlspecialchars($exportUrl, ENT_QUOTES, 'UTF-8')?>" class="btn btn-export">
                    <i class="fas fa-file-export"></i>
                    Export to CSV
                </a>
                <button type="button" id="openSalesReport" class="btn btn-generate">
                    <i class="fas fa-chart-line"></i>
                    Generate Sales Report
                </button>
            </div>
        </div>

        <!-- Table Container -->
        <div class="table-container">
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Invoice</th>
                            <th>Cashier</th>
                            <th>Total</th>
                            <th>Payment Method</th>
                            <th>Reference</th>
                            <th>Proof</th>
                            <th>Status</th>
                            <th>Created At</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($orders)): ?>
                        <tr>
                            <td colspan="9" style="text-align: center; padding: 40px; color: #6b7280;">
                                <i class="fas fa-inbox"
                                    style="font-size: 48px; margin-bottom: 10px; display: block;"></i>
                                <?php if($hasInvoiceSearch): ?>
                                    No sales records matched "<?=htmlspecialchars($invoiceSearch, ENT_QUOTES, 'UTF-8')?>".
                                    <br>
                                    <a href="sales.php" style="color:#0ea5e9; font-weight:600;">Clear the search</a> to view all sales.
                                <?php else: ?>
                                    No sales records found.
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php
                        $statusLabels = [
                            'approved' => 'Approved',
                            'completed' => 'Completed',
                            'disapproved' => 'Disapproved',
                            'payment_verification' => 'Payment Verification',
                        ];
                        ?>
                        <?php foreach($orders as $o): ?>
                        <?php
                            $statusKey = strtolower((string) ($o['status'] ?? ''));
                            $statusLabel = $statusLabels[$statusKey] ?? ucfirst($statusKey);
                            $statusClass = 'status-pill ' . 'status-' . preg_replace('/[^a-z0-9_-]/i', '-', $statusKey);
                            $disapprovalReason = $statusKey === 'disapproved' ? trim((string) ($o['decline_reason_label'] ?? '')) : '';
                            $disapprovalNote = $statusKey === 'disapproved' ? trim((string) ($o['decline_reason_note'] ?? '')) : '';
                        ?>
                        <tr class="transaction-row" data-order-id="<?=$o['id']?>" style="cursor: pointer;">
                            <td><?=$o['id']?></td>
                            <td><?=$o['invoice_number'] ? htmlspecialchars($o['invoice_number']) : 'N/A'?></td>
                            <td><?=htmlspecialchars(resolveCashierName($o))?></td>
                            <td>₱<?=number_format($o['total'],2)?></td>
                            <td><?=htmlspecialchars($o['payment_method'])?></td>
                            <?php
                            $paymentDetails = parsePaymentProofValue($o['payment_proof'] ?? null, $o['reference_no'] ?? null);
                            $proofUrl = normalizePaymentProofPath($paymentDetails['image'] ?? '');
                            ?>

                            <td>
                                <?php if(!empty($paymentDetails['reference'])): ?>
                                    <span style="font-weight:600; color:#1d4ed8;"><?=htmlspecialchars($paymentDetails['reference'])?></span>
                                <?php else: ?>
                                    <span style="color:#94a3b8;">Not provided</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if($proofUrl !== ''): ?>
                                    <a href="<?=htmlspecialchars($proofUrl, ENT_QUOTES, 'UTF-8')?>" target="_blank" style="color:#0ea5e9; font-weight:600;">
                                        View Proof
                                    </a>
                                <?php else: ?>
                                    <span style="color:#94a3b8;">No image</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="<?=htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8')?>"><?=htmlspecialchars($statusLabel)?></span>
                                <?php if($statusKey === 'disapproved' && $disapprovalReason !== ''): ?>
                                    <div class="status-detail">
                                        <strong>Reason:</strong> <?=htmlspecialchars($disapprovalReason)?>
                                        <?php if($disapprovalNote !== ''): ?>
                                            <br><span class="status-detail-note">Details: <?=nl2br(htmlspecialchars($disapprovalNote))?></span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td><?=date('M d, Y g:i A', strtotime($o['created_at']))?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if($total_records > 0): ?>
            <!-- Pagination -->
            <div class="pagination-container">
                <div class="pagination-info">
                    Showing <?=$start_record?> to <?=$end_record?> of <?=$total_records?> entries
                </div>
                <div class="pagination">
                    <!-- Previous button -->
                    <?php if($current_page > 1): ?>
                    <a href="<?=$buildPageUrl($current_page - 1)?>" class="prev">
                        <i class="fas fa-chevron-left"></i> Prev
                    </a>
                    <?php else: ?>
                    <span class="prev disabled">
                        <i class="fas fa-chevron-left"></i> Prev
                    </span>
                    <?php endif; ?>

                    <!-- Page numbers -->
                    <?php
                    $start_page = max(1, $current_page - 2);
                    $end_page = min($total_pages, $current_page + 2);
                    
                    // Show first page if not in range
                    if($start_page > 1): ?>
                    <a href="<?=$buildPageUrl(1)?>">1</a>
                    <?php if($start_page > 2): ?>
                    <span>...</span>
                    <?php endif;
                    endif;
                    
                    // Show page numbers in range
                    for($i = $start_page; $i <= $end_page; $i++):
                        if($i == $current_page): ?>
                    <span class="current"><?=$i?></span>
                    <?php else: ?>
                    <a href="<?=$buildPageUrl($i)?>"><?=$i?></a>
                    <?php endif;
                    endfor;
                    
                    // Show last page if not in range
                    if($end_page < $total_pages):
                        if($end_page < $total_pages - 1): ?>
                    <span>...</span>
                    <?php endif; ?>
                    <a href="<?=$buildPageUrl($total_pages)?>"><?=$total_pages?></a>
                    <?php endif; ?>

                    <!-- Next button -->
                    <?php if($current_page < $total_pages): ?>
                    <a href="<?=$buildPageUrl($current_page + 1)?>" class="next">
                        Next <i class="fas fa-chevron-right"></i>
                    </a>
                    <?php else: ?>
                    <span class="next disabled">
                        Next <i class="fas fa-chevron-right"></i>
                    </span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Sales Widget -->
        <!-- Add this after the Export Button and before the Table Container -->
        <div class="stat-overview">
            <div class="sales-widget">
                <div class="widget-header">
                    <h2 class="widget-title">
                        <i class="fas fa-chart-line"></i>
                        Sales Analytics
                    </h2>
                    <div class="widget-controls" id="analyticsFilters">
                        <div class="control-group">
                            <label for="analyticsPeriod" class="control-label">View</label>
                            <select class="period-dropdown" id="analyticsPeriod" data-period-select>
                                <option value="daily">Daily</option>
                                <option value="weekly">Weekly</option>
                                <option value="monthly">Monthly</option>
                                <option value="annually">Annually</option>
                            </select>
                        </div>
                        <div class="control-group">
                            <label for="analyticsPicker" class="control-label" id="analyticsPickerLabel">Select day</label>
                            <input id="analyticsPicker" class="period-input" type="date" data-period-input>
                            <span class="control-hint" id="analyticsRangeHint"></span>
                        </div>
                    </div>
                </div>

                <div class="stats-container">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fa-solid fa-peso-sign"></i>
                        </div>
                        <div class="stat-value" id="totalSales">₱0.00</div>
                        <div class="stat-label">Total Sales</div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-shopping-cart"></i>
                        </div>
                        <div class="stat-value" id="totalOrders">0</div>
                        <div class="stat-label">Total Orders</div>
                    </div>
                </div>


            </div>

            <!-- piecharat widget -->
            <div class="chart-widget">
                <div class="chart-header">
                    <h2><i class="fa-solid fa-chart-pie"></i>
                        Sales Trend</h2>
                    <div class="widget-controls" id="trendFilters">
                        <div class="control-group">
                            <label for="trendPeriod" class="control-label">View</label>
                            <select id="trendPeriod" class="period-dropdown" data-period-select>
                                <option value="daily">Daily</option>
                                <option value="weekly">Weekly</option>
                                <option value="monthly">Monthly</option>
                                <option value="annually">Annually</option>
                            </select>
                        </div>
                        <div class="control-group">
                            <label for="trendPicker" class="control-label" id="trendPickerLabel">Select day</label>
                            <input id="trendPicker" class="period-input" type="date" data-period-input>
                            <span class="control-hint" id="trendRangeHint"></span>
                        </div>
                    </div>
                </div>
                <div class="chart-canvas-wrap">
                    
                    <canvas id="salesPieChart"></canvas>
                   
                </div>
                
                <div class="chart-legend" id="chartLegend" aria-live="polite"></div>
               
            </div>
        </div>
    </main>

    <!-- Transaction Details Modal -->
        <!-- Transaction Details Modal -->
    <div id="transactionModal" class="modal">
        <div class="modal-content">
            <span class="close">&times;</span>
            <div class="modal-body">
                <!-- Content will be dynamically inserted here -->
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="profileModal" aria-hidden="true">
        <div class="modal-content" role="dialog" aria-modal="true" aria-labelledby="profileModalTitle">
            <button type="button" class="modal-close" id="profileModalClose" aria-label="Close profile information">
                <i class="fas fa-times"></i>
            </button>
            <h3 id="profileModalTitle">Profile information</h3>
            <div class="profile-info">
                <div class="profile-row">
                    <span class="profile-label">Name</span>
                    <span class="profile-value"><?= htmlspecialchars($profile_name) ?></span>
                </div>
                <div class="profile-row">
                    <span class="profile-label">Role</span>
                    <span class="profile-value"><?= htmlspecialchars($profile_role) ?></span>
                </div>
                <div class="profile-row">
                    <span class="profile-label">Date created</span>
                    <span class="profile-value"><?= htmlspecialchars($profile_created) ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- New Modal for Sales Report Options -->
    <div id="salesReportModal" class="modal">
        <div class="modal-content">
            <span class="close" id="closeModal">&times;</span>
            <h2>Generate Sales Report</h2>
            <form id="salesReportForm" method="GET" action="sales_report_pdf.php">
                <div class="modal-controls">
                    <div class="control-group">
                        <label for="reportPeriod" class="control-label" id="reportPeriodLabel">View</label>
                        <select id="reportPeriod" name="period" class="period-dropdown">
                            <option value="daily">Daily</option>
                            <option value="weekly">Weekly</option>
                            <option value="monthly">Monthly</option>
                            <option value="annually">Annually</option>
                        </select>
                    </div>
                    <div class="control-group">
                        <label for="reportPeriodValue" class="control-label" id="reportValueLabel">Select day</label>
                        <input id="reportPeriodValue" name="value" class="period-input" type="date">
                        <span class="control-hint" id="reportPeriodHint"></span>
                    </div>
                    <div class="control-group">
                        <label for="customerType" class="control-label">Customer Type</label>
                        <select id="customerType" name="customer_type" class="period-dropdown">
                            <option value="all">All Customers</option>
                            <option value="walkin">Walk-in Customers</option>
                            <option value="online">Online Customers</option>
                        </select>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">Generate Report</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    <!-- Sales period helpers -->
    <script src="../dgz_motorshop_system/assets/js/sales/periodFilters.js"></script>
    <!-- Sales report modal -->
    <script src="../dgz_motorshop_system/assets/js/sales/salesReportModal.js"></script>
    <!-- Sales analytics widget -->
    <script src="../dgz_motorshop_system/assets/js/sales/salesAnalytics.js"></script>
    <!-- Pie chart widget -->
    <script src="../dgz_motorshop_system/assets/js/sales/pieChart.js"></script>
    <!-- Sales search helpers -->
    <script src="../dgz_motorshop_system/assets/js/sales/salesSearch.js"></script>
    
        
    <!--Transaction modal-->
    <script src="../dgz_motorshop_system/assets/js/sales/transactionModal.js"></script>
    <script src="../dgz_motorshop_system/assets/js/notifications.js"></script>
    <script src="../dgz_motorshop_system/assets/js/transaction-details.js"></script>
    <script src="../dgz_motorshop_system/assets/js/dashboard/userMenu.js"></script>
</body>

</html>
