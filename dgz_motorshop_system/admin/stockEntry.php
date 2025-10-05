<?php
require __DIR__ . '/../config/config.php';
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$pdo = db();
$role = $_SESSION['role'] ?? '';
enforceStaffAccess();
$notificationManageLink = 'inventory.php';

require_once __DIR__ . '/includes/inventory_notifications.php';
require_once __DIR__ . '/includes/stock_receipt_helpers.php';
$inventoryNotificationData = loadInventoryNotifications($pdo);
$inventoryNotifications = $inventoryNotificationData['notifications'];
$inventoryNotificationCount = $inventoryNotificationData['active_count'];

$currentUser = loadCurrentUser($pdo, (int)$_SESSION['user_id']);
$profile_name = $currentUser['name'] ?? 'N/A';
$profile_role = !empty($currentUser['role']) ? ucfirst($currentUser['role']) : 'N/A';
$profile_created = format_profile_date($currentUser['created_at'] ?? null);

$moduleReady = ensureStockReceiptTablesExist($pdo);
$errors = [];
$infoMessage = '';
$successMessage = '';
$activeReceipt = null;
$editingReceiptId = null;
$formMode = 'create';

$requestedReceiptId = isset($_GET['receipt']) ? (int)$_GET['receipt'] : 0;
$requestedReceiptCode = isset($_GET['code']) ? trim((string)($_GET['code'] ?? '')) : '';

if ($moduleReady) {
    if ($requestedReceiptId > 0) {
        $activeReceipt = loadStockReceiptWithItems($pdo, $requestedReceiptId);
    } elseif ($requestedReceiptCode !== '') {
        $activeReceipt = loadStockReceiptByCode($pdo, $requestedReceiptCode);
    }

    if ($activeReceipt) {
        $editingReceiptId = (int)($activeReceipt['header']['id'] ?? 0);
        $formMode = 'edit';
        if (in_array($activeReceipt['header']['status'], ['posted', 'with_discrepancy'], true) && ($_GET['mode'] ?? '') !== 'edit') {
            header('Location: stockReceiptView.php?receipt=' . $editingReceiptId);
            exit;
        }
    } elseif (($requestedReceiptId > 0 || $requestedReceiptCode !== '') && !$activeReceipt) {
        $errors[] = 'Requested stock-in document was not found.';
    }
}

if ($moduleReady && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = handleStockReceiptSubmission($pdo, $currentUser, $_POST, $_FILES);
    $errors = $result['errors'];
    $successMessage = $result['success_message'];
    if (!empty($result['redirect'])) {
        header('Location: ' . $result['redirect']);
        exit;
    }
}

if (!$moduleReady) {
    $infoMessage = 'Stock-in tables are missing. Please run the provided database migrations before using this page.';
}

if (isset($_GET['created']) && $_GET['created'] === '1') {
    $successMessage = 'Stock-in document saved successfully.';
}

if (isset($_GET['posted']) && $_GET['posted'] === '1') {
    $successMessage = 'Stock-in document posted and inventory updated.';
}

$products = fetchProductCatalog($pdo);
$suppliers = fetchSuppliersList($pdo);
$recentReceipts = $moduleReady ? fetchRecentReceipts($pdo) : [];

// Stock-in report state: filters, rows for on-page preview, and inventory snapshot
$stockReceiptStatusOptions = getStockReceiptStatusOptions();
$productLookup = [];
$brandOptions = [];
$categoryOptions = [];
foreach ($products as $productMeta) {
    $productLookup[(int)$productMeta['id']] = $productMeta['name'];
    if (!empty($productMeta['brand'])) {
        $brandOptions[] = $productMeta['brand'];
    }
    if (!empty($productMeta['category'])) {
        $categoryOptions[] = $productMeta['category'];
    }
}
$brandOptions = array_values(array_unique($brandOptions));
$categoryOptions = array_values(array_unique($categoryOptions));
$reportFilters = parseStockInReportFilters($_GET ?? [], $stockReceiptStatusOptions, $productLookup, $brandOptions, $categoryOptions);
$stockInReportRows = [];
$currentInventorySnapshot = [];

if ($moduleReady) {
    if (!empty($_GET['stock_in_export'])) {
        $exportFormat = strtolower((string)$_GET['stock_in_export']);
        if (in_array($exportFormat, ['csv', 'xlsx', 'pdf'], true)) {
            $exportRows = fetchStockInReport($pdo, $reportFilters, null);
            handleStockInReportExport($exportFormat, $exportRows, $reportFilters);
        }
    }

    $stockInReportRows = fetchStockInReport($pdo, $reportFilters, 50);
    $currentInventorySnapshot = fetchCurrentInventorySnapshot($pdo, 12);
}

$formSupplier = $activeReceipt['header']['supplier_name'] ?? '';
$formDocumentNumber = $activeReceipt['header']['document_number'] ?? '';
$formDateReceived = $activeReceipt['header']['date_received'] ?? date('Y-m-d');
$formNotes = $activeReceipt['header']['notes'] ?? '';
$formRelatedReference = $activeReceipt['header']['related_reference'] ?? '';
$formDiscrepancyNote = $activeReceipt['header']['discrepancy_note'] ?? '';

if (!empty($_POST)) {
    $formSupplier = $_POST['supplier_name'] ?? $formSupplier;
    $formDocumentNumber = $_POST['document_number'] ?? $formDocumentNumber;
    $formDateReceived = $_POST['date_received'] ?? $formDateReceived;
    $formNotes = $_POST['notes'] ?? $formNotes;
    $formRelatedReference = $_POST['related_reference'] ?? $formRelatedReference;
    $formDiscrepancyNote = $_POST['discrepancy_note'] ?? $formDiscrepancyNote;
}

$existingItems = [];
if ($activeReceipt) {
    foreach ($activeReceipt['items'] as $item) {
        $existingItems[] = [
            'product_id' => (int)$item['product_id'],
            'qty_expected' => $item['qty_expected'],
            'qty_received' => $item['qty_received'],
            'unit_cost' => $item['unit_cost'],
            'expiry_date' => $item['expiry_date'],
            'expiry_value' => $item['expiry_date'],
            'lot_number' => $item['lot_number'],
            'has_discrepancy' => $item['qty_expected'] !== null && (int)$item['qty_expected'] !== (int)$item['qty_received'],
            'invalid_expiry' => false,
        ];
    }
}

if (!empty($_POST)) {
    $postItems = normalizeLineItemsPreserveBlank($_POST);
    if (!empty($postItems)) {
        $existingItems = $postItems;
    }
}

if (empty($existingItems)) {
    $existingItems[] = [
        'product_id' => null,
        'qty_expected' => null,
        'qty_received' => null,
        'unit_cost' => null,
        'expiry_date' => null,
        'expiry_value' => null,
        'lot_number' => null,
        'has_discrepancy' => false,
        'invalid_expiry' => false,
    ];
}

$existingAttachments = $activeReceipt['attachments'] ?? [];
$formLocked = !$moduleReady || ($activeReceipt && $activeReceipt['header']['status'] !== 'draft');
$hasPresetDiscrepancy = false;
foreach ($existingItems as $item) {
    if (!empty($item['has_discrepancy'])) {
        $hasPresetDiscrepancy = true;
        break;
    }
}
if (trim((string)$formDiscrepancyNote) !== '') {
    $hasPresetDiscrepancy = true;
}
$discrepancyGroupHiddenAttr = $hasPresetDiscrepancy ? '' : 'hidden';

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Stock-In - DGZ</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/dashboard/dashboard.css">
    <link rel="stylesheet" href="../assets/css/inventory/stockEntry.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>
    <?php
        $activePage = 'inventory.php';
        include __DIR__ . '/includes/sidebar.php';
    ?>
    <main class="main-content">
        <header class="header">
            <div class="header-left">
                <button class="mobile-toggle" onclick="toggleSidebar()">
                    <i class="fas fa-bars"></i>
                </button>
                <h2>Create Stock-In</h2>
            </div>
            <div class="header-right">
                <?php include __DIR__ . '/partials/notification_menu.php'; ?>
                <div class="user-menu">
                    <div class="user-avatar" onclick="toggleDropdown()">
                        <i class="fas fa-user"></i>
                    </div>
                    <div class="dropdown-menu" id="userDropdown">
                        <button type="button" class="dropdown-item" id="profileTrigger">
                            <i class="fas fa-user-cog"></i> Profile
                        </button>
                        <a href="settings.php" class="dropdown-item">
                            <i class="fas fa-cog"></i> Settings
                        </a>
                        <?php if ($role === 'admin'): ?>
                        <a href="userManagement.php" class="dropdown-item">
                            <i class="fas fa-users-cog"></i> User Management
                        </a>
                        <?php endif; ?>
                        <a href="login.php?logout=1" class="dropdown-item logout">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <div class="page-actions">
            <a href="inventory.php" class="btn-action back-btn">Back to Inventory</a>
        </div>

        <div class="dashboard-content">
            <?php if (!empty($infoMessage)): ?>
                <div class="alert alert-info"><?= htmlspecialchars($infoMessage) ?></div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?= htmlspecialchars($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if (!empty($successMessage)): ?>
                <div class="alert alert-success"><?= htmlspecialchars($successMessage) ?></div>
            <?php endif; ?>

            <section class="panel" aria-labelledby="stockInFormTitle">
                <div class="panel-header">
                    <div>
                        <h3 id="stockInFormTitle"><?= $formMode === 'edit' ? 'Edit Stock-In' : 'New Stock-In' ?></h3>
                        <p class="panel-subtitle">
                            <?= $formMode === 'edit'
                                ? 'Update the draft receipt before posting to inventory.'
                                : 'Capture supplier deliveries, attach proofs, and post directly to inventory.'
                            ?>
                            <?php if ($activeReceipt): ?>
                                <span class="panel-subtext">Reference: <?= htmlspecialchars($activeReceipt['header']['receipt_code'] ?? '') ?> · Status: <?= htmlspecialchars(formatStockReceiptStatus($activeReceipt['header']['status'] ?? 'draft')) ?></span>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="panel-actions">
                        <?php if (!$formLocked): ?>
                        <button type="button" class="btn-secondary" id="addLineItemBtn">
                            <i class="fas fa-plus"></i> Add Line Item
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <form id="stockInForm" method="POST" enctype="multipart/form-data" <?= $formLocked ? 'aria-disabled="true"' : '' ?>>
                    <?php if ($editingReceiptId): ?>
                        <input type="hidden" name="receipt_id" value="<?= (int)$editingReceiptId ?>">
                    <?php endif; ?>
                    <fieldset class="form-section" aria-labelledby="headerInfoTitle" <?= $formLocked ? 'disabled' : '' ?>>
                        <legend id="headerInfoTitle">Header</legend>
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="supplier_name">Supplier <span class="required">*</span></label>
                                <input type="text" id="supplier_name" name="supplier_name" list="supplierOptions" placeholder="Enter supplier name" value="<?= htmlspecialchars($formSupplier) ?>" required>
                                <datalist id="supplierOptions">
                                    <?php foreach ($suppliers as $supplier): ?>
                                        <option value="<?= htmlspecialchars($supplier) ?>"></option>
                                    <?php endforeach; ?>
                                </datalist>
                            </div>
                            <div class="form-group">
                                <label for="document_number">DR / Invoice No. <span class="required">*</span></label>
                                <input type="text" id="document_number" name="document_number" placeholder="Delivery receipt or invoice number" value="<?= htmlspecialchars($formDocumentNumber) ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="date_received">Date Received</label>
                                <input type="date" id="date_received" name="date_received" value="<?= htmlspecialchars($formDateReceived) ?>">
                            </div>
                            <div class="form-group">
                                <label for="received_by_name">Received By</label>
                                <input type="text" id="received_by_name" value="<?= htmlspecialchars($currentUser['name'] ?? 'N/A') ?>" readonly>
                            </div>
                            <div class="form-group">
                                <label for="related_reference">Related To</label>
                                <input type="text" id="related_reference" name="related_reference" placeholder="Reference another stock-in if partial" value="<?= htmlspecialchars($formRelatedReference) ?>">
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="notes">Notes</label>
                            <textarea id="notes" name="notes" rows="3" placeholder="Any additional details"><?= htmlspecialchars($formNotes) ?></textarea>
                        </div>
                        <div class="form-group" id="discrepancyNoteGroup" <?= $discrepancyGroupHiddenAttr ?> >
                            <label for="discrepancy_note">Discrepancy Note <span class="required">*</span></label>
                            <textarea id="discrepancy_note" name="discrepancy_note" rows="3" placeholder="Explain missing, damaged, or excess items"><?= htmlspecialchars($formDiscrepancyNote) ?></textarea>
                        </div>
                    </fieldset>

                    <fieldset class="form-section line-items-section" aria-labelledby="lineItemsTitle" <?= $formLocked ? 'disabled' : '' ?>>
                        <legend id="lineItemsTitle">Line Items</legend>
                        <p class="table-hint table-hint-inline">Tip: Use Qty Expected to highlight discrepancies before posting.</p>
                        <div class="table-wrapper">
                            <table class="line-items-table">
                                <thead>
                                    <tr>
                                        <th>Product <span class="required">*</span></th>
                                        <th>Qty Expected</th>
                                        <th>Qty Received <span class="required">*</span></th>
                                        <th>Unit Cost</th>
                                        <th>Expiry</th>
                                        <th>Lot / Batch</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody id="lineItemsBody">
                                    <?php foreach ($existingItems as $index => $item): ?>
                                        <?php
                                            $productId = $item['product_id'];
                                            $qtyExpected = $item['qty_expected'];
                                            $qtyReceived = $item['qty_received'];
                                            $unitCost = $item['unit_cost'];
                                            $expiryDate = $item['expiry_date'];
                                            $lotNumber = $item['lot_number'];
                                            $rowHasDiscrepancy = !empty($item['has_discrepancy']);
                                            $rowInvalidExpiry = !empty($item['invalid_expiry']);
                                        ?>
                                        <tr class="line-item-row<?= $rowHasDiscrepancy ? ' has-discrepancy' : '' ?>" data-selected-product="<?= $productId ? (int)$productId : '' ?>">
                                            <td>
                                                <div class="product-selector">
                                                    <input type="text" class="product-search" placeholder="Search name or code" value="">
                                                    <div class="product-suggestions"></div>
                                                    <select name="product_id[]" class="product-select" required>
                                                        <option value="">Select product</option>
                                                        <?php foreach ($products as $product): ?>
                                                            <option value="<?= (int)$product['id'] ?>" <?= $productId == (int)$product['id'] ? 'selected' : '' ?>>
                                                                <?= htmlspecialchars($product['name']) ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                            </td>
                                            <td>
                                                <input type="number" name="qty_expected[]" min="0" step="1" placeholder="0" value="<?= $qtyExpected !== null ? htmlspecialchars((string)$qtyExpected) : '' ?>">
                                            </td>
                                            <td>
                                                <input type="number" name="qty_received[]" min="0" step="1" placeholder="0" value="<?= $qtyReceived !== null ? htmlspecialchars((string)$qtyReceived) : '' ?>" <?= $formLocked ? 'readonly' : 'required' ?>>
                                            </td>
                                            <td>
                                                <input type="number" name="unit_cost[]" min="0" step="0.01" placeholder="0.00" value="<?= $unitCost !== null ? htmlspecialchars(number_format((float)$unitCost, 2, '.', '')) : '' ?>">
                                            </td>
                                            <td>
                                                <input type="date" name="expiry_date[]" value="<?= $expiryDate ? htmlspecialchars($expiryDate) : '' ?>">
                                                <?php if ($rowInvalidExpiry): ?>
                                                    <small class="input-error">Use YYYY-MM-DD (e.g., <?= date('Y-m-d') ?>).</small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <input type="text" name="lot_number[]" placeholder="Lot or batch" value="<?= $lotNumber ? htmlspecialchars($lotNumber) : '' ?>">
                                            </td>
                                            <td class="actions">
                                                <button type="button" class="icon-btn remove-line-item" aria-label="Remove line item" <?= ($index === 0 || $formLocked) ? 'disabled' : '' ?>>
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </fieldset>

                    <fieldset class="form-section" aria-labelledby="attachmentsTitle" <?= $formLocked ? 'disabled' : '' ?>>
                        <legend id="attachmentsTitle">Attachments</legend>
                        <div class="form-group">
                            <label for="attachments">Upload Proof (PDF/JPG/PNG)</label>
                            <input type="file" id="attachments" name="attachments[]" accept=".pdf,.jpg,.jpeg,.png" multiple <?= $formLocked ? 'disabled' : '' ?>>
                            <small>Include delivery receipt or invoice images. Multiple files allowed.</small>
                        </div>
                        <ul id="attachmentList" class="attachment-list"></ul>
                        <?php if (!empty($existingAttachments)): ?>
                            <div class="existing-attachments">
                                <h4>Existing Attachments</h4>
                                <ul>
                                    <?php foreach ($existingAttachments as $attachment): ?>
                                        <li>
                                            <a href="../<?= htmlspecialchars($attachment['file_path']) ?>" target="_blank" rel="noopener">
                                                <i class="fas fa-paperclip"></i> <?= htmlspecialchars($attachment['original_name']) ?>
                                            </a>
                                            <span class="attachment-meta">Uploaded <?= htmlspecialchars(formatStockReceiptDateTime($attachment['created_at'])) ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                    </fieldset>

                    <div class="form-footer">
                        <input type="hidden" name="form_action" id="formAction" value="">
                        <button type="button" class="btn-secondary" id="saveDraftBtn" <?= $formLocked ? 'disabled' : '' ?>>Save as Draft</button>
                        <button type="button" class="btn-primary" id="postReceiptBtn" <?= $formLocked ? 'disabled' : '' ?>>Post &amp; Receive</button>
                        <?php if ($formLocked && $activeReceipt): ?>
                            <a class="btn-secondary" href="stockReceiptView.php?receipt=<?= (int)$activeReceipt['header']['id'] ?>">View Details</a>
                        <?php endif; ?>
                    </div>
                </form>
            </section>

            <!-- Stock-In report with filters, preview table, and export triggers -->
            <section class="panel" aria-labelledby="stockInReportTitle" id="stock-in-report">
                <div class="panel-header">
                    <div>
                        <h3 id="stockInReportTitle">Stock-In Report</h3>
                        <p class="panel-subtitle">Filter received stock entries and export the result set for external analysis.</p>
                    </div>
                </div>
                <form class="report-filters" method="GET" aria-label="Stock-In report filters">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="filter_date_from">Date From</label>
                            <input type="date" id="filter_date_from" name="report_date_from" value="<?= htmlspecialchars($reportFilters['date_from_input']) ?>">
                        </div>
                        <div class="form-group">
                            <label for="filter_date_to">Date To</label>
                            <input type="date" id="filter_date_to" name="report_date_to" value="<?= htmlspecialchars($reportFilters['date_to_input']) ?>">
                        </div>
                        <div class="form-group">
                            <label for="filter_supplier">Supplier</label>
                            <input type="text" id="filter_supplier" name="report_supplier" list="supplierOptions" placeholder="Match supplier name" value="<?= htmlspecialchars($reportFilters['supplier']) ?>">
                        </div>
                        <div class="form-group">
                            <label for="filter_product">Product</label>
                            <select id="filter_product" name="report_product_id">
                                <option value="">All products</option>
                                <?php foreach ($products as $productOption): ?>
                                    <option value="<?= (int)$productOption['id'] ?>" <?= (int)$reportFilters['product_id'] === (int)$productOption['id'] ? 'selected' : '' ?>><?= htmlspecialchars($productOption['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <!-- Additional filters to support larger catalogs (brand/category/search). -->
                        <div class="form-group">
                            <label for="filter_product_search">Product Search</label>
                            <input type="text" id="filter_product_search" name="report_product_search" placeholder="Search name, code, or receipt" value="<?= htmlspecialchars($reportFilters['product_search']) ?>">
                        </div>
                        <div class="form-group">
                            <label for="filter_brand">Brand</label>
                            <select id="filter_brand" name="report_brand">
                                <option value="">All brands</option>
                                <?php foreach ($brandOptions as $brandOption): ?>
                                    <option value="<?= htmlspecialchars($brandOption) ?>" <?= $reportFilters['brand'] === $brandOption ? 'selected' : '' ?>><?= htmlspecialchars($brandOption) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="filter_category">Category</label>
                            <select id="filter_category" name="report_category">
                                <option value="">All categories</option>
                                <?php foreach ($categoryOptions as $categoryOption): ?>
                                    <option value="<?= htmlspecialchars($categoryOption) ?>" <?= $reportFilters['category'] === $categoryOption ? 'selected' : '' ?>><?= htmlspecialchars($categoryOption) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="filter_status">Status</label>
                            <select id="filter_status" name="report_status">
                                <option value="">All statuses</option>
                                <?php foreach ($stockReceiptStatusOptions as $statusValue => $statusLabel): ?>
                                    <option value="<?= htmlspecialchars($statusValue) ?>" <?= $reportFilters['status'] === $statusValue ? 'selected' : '' ?>><?= htmlspecialchars($statusLabel) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="filter-actions">
                        <button type="submit" class="btn-primary">Apply Filters</button>
                        <a class="btn-secondary" href="stockEntry.php#stock-in-report">Reset</a>
                        <button type="submit" class="btn-secondary" name="stock_in_export" value="csv">Export CSV</button>
                        <button type="submit" class="btn-secondary" name="stock_in_export" value="xlsx">Export XLSX</button>
                        <button type="submit" class="btn-secondary" name="stock_in_export" value="pdf">Export PDF</button>
                    </div>
                </form>
                <?php if (!empty($stockInReportRows)): ?>
                    <div class="table-wrapper">
                        <table class="line-items-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Doc No</th>
                                    <th>Supplier</th>
                                    <th>DR No</th>
                                    <th>Product</th>
                                    <th>Qty Received</th>
                                    <th>Unit Cost</th>
                                    <th>Receiver</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($stockInReportRows as $reportRow): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($reportRow['date_display']) ?></td>
                                        <td><?= htmlspecialchars($reportRow['receipt_code']) ?></td>
                                        <td><?= htmlspecialchars($reportRow['supplier_name']) ?></td>
                                        <td><?= htmlspecialchars($reportRow['document_number']) ?></td>
                                        <td><?= htmlspecialchars($reportRow['product_name']) ?></td>
                                        <td><?= htmlspecialchars($reportRow['qty_received_display']) ?></td>
                                        <td><?= htmlspecialchars($reportRow['unit_cost_display']) ?></td>
                                        <td><?= htmlspecialchars($reportRow['receiver_name']) ?></td>
                                        <td><span class="status-badge status-<?= htmlspecialchars($reportRow['status']) ?>"><?= htmlspecialchars($reportRow['status_label']) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="empty-state">No stock-in activity matches the selected filters.</p>
                <?php endif; ?>
            </section>

            <!-- Current inventory snapshot derived from latest stock-in posts -->
            <section class="panel" aria-labelledby="inventorySnapshotTitle">
                <div class="panel-header">
                    <div>
                        <h3 id="inventorySnapshotTitle">Current Inventory</h3>
                        <p class="panel-subtitle">On-hand counts with the last received date and low-stock indicator.</p>
                    </div>
                </div>
                <?php if (!empty($currentInventorySnapshot)): ?>
                    <div class="table-wrapper">
                        <table class="line-items-table">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>On-hand</th>
                                    <th>Last Received Date</th>
                                    <th>Low Stock?</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($currentInventorySnapshot as $inventoryRow): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($inventoryRow['product_name']) ?></td>
                                        <td><?= htmlspecialchars($inventoryRow['on_hand_display']) ?></td>
                                        <td><?= htmlspecialchars($inventoryRow['last_received_display']) ?></td>
                                        <td>
                                            <?php if ($inventoryRow['is_low_stock']): ?>
                                                <span class="status-badge status-with_discrepancy">Low</span>
                                            <?php else: ?>
                                                <span class="status-badge status-posted">OK</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="empty-state">Inventory data is unavailable until stock receipts are recorded.</p>
                <?php endif; ?>
            </section>

            <section class="panel" aria-labelledby="recentReceiptsTitle">
                <div class="panel-header">
                    <div>
                        <h3 id="recentReceiptsTitle">Recent Stock-In Activity</h3>
                        <p class="panel-subtitle">Latest receipts with status and totals.</p>
                    </div>
                </div>
                <?php if (!empty($recentReceipts)): ?>
                    <div class="table-wrapper">
                        <table class="line-items-table">
                            <thead>
                                <tr>
                                    <th>Posted</th>
                                    <th>Reference</th>
                                    <th>Supplier</th>
                                    <th>Received By</th>
                                    <th>Status</th>
                                    <th>Items</th>
                                    <th>Total Qty</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentReceipts as $receipt): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($receipt['posted_at_formatted'] ?? $receipt['created_at_formatted']) ?></td>
                                        <td><?= htmlspecialchars($receipt['receipt_code']) ?></td>
                                        <td><?= htmlspecialchars($receipt['supplier_name']) ?></td>
                                        <td><?= htmlspecialchars($receipt['received_by_name'] ?? 'Pending') ?></td>
                                        <td><span class="status-badge status-<?= htmlspecialchars($receipt['status']) ?>"><?= htmlspecialchars(formatStatusLabel($receipt['status'])) ?></span></td>
                                        <td><?= (int)$receipt['item_count'] ?></td>
                                        <td><?= (int)$receipt['total_received_qty'] ?></td>
                                        <td class="table-actions">
                                            <?php if ($receipt['status'] === 'draft'): ?>
                                                <a href="stockEntry.php?receipt=<?= (int)$receipt['id'] ?>&mode=edit" class="table-link">Edit Draft</a>
                                            <?php else: ?>
                                                <a href="stockReceiptView.php?receipt=<?= (int)$receipt['id'] ?>" class="table-link">View</a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="empty-state">No stock-in documents recorded yet.</p>
                <?php endif; ?>
            </section>
        </div>
    </main>

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

    <script type="application/json" id="stockReceiptBootstrap">
        <?= json_encode([
            'products' => $products,
            'currentUserId' => (int)($_SESSION['user_id'] ?? 0),
            'suppliers' => $suppliers,
            'formLocked' => $formLocked,
        ], JSON_PRETTY_PRINT) ?>
    </script>
    <script src="../assets/js/inventory/stockEntry.js"></script>
    <script src="../assets/js/notifications.js"></script>
</body>
</html>
<?php

/**
 * Helper: retrieve current user info for profile and audit references.
 */
function loadCurrentUser(PDO $pdo, int $userId): ?array
{
    try {
        $stmt = $pdo->prepare('SELECT id, name, role, created_at FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        error_log('User lookup failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * Format dates for profile modal display.
 */
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

/**
 * Quickly check that the new stock-in tables exist before rendering the form.
 */
function ensureStockReceiptTablesExist(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT 1 FROM stock_receipts LIMIT 1');
        $pdo->query('SELECT 1 FROM stock_receipt_items LIMIT 1');
        $pdo->query('SELECT 1 FROM stock_receipt_files LIMIT 1');
        $pdo->query('SELECT 1 FROM stock_receipt_audit_log LIMIT 1');
        $pdo->query('SELECT 1 FROM inventory_ledger LIMIT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Load product catalog data needed to populate line item selectors.
 */
function fetchProductCatalog(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT id, name, code, brand, category FROM products ORDER BY name');
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Build a deduplicated list of supplier names from either the products table or the receipts table.
 */
function fetchSuppliersList(PDO $pdo): array
{
    $suppliers = [];
    try {
        $result = $pdo->query("SELECT DISTINCT supplier_name FROM stock_receipts WHERE supplier_name IS NOT NULL AND supplier_name != ''");
        $suppliers = $result->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        // ignore if table missing or empty
    }

    $productSuppliers = [];
    try {
        $result = $pdo->query("SELECT DISTINCT supplier FROM products WHERE supplier IS NOT NULL AND supplier != ''");
        $productSuppliers = $result->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        // ignore missing column/table
    }

    return array_values(array_unique(array_merge($suppliers, $productSuppliers)));
}

/**
 * Handle insert/update of a new stock receipt with items, attachments, and audit trail.
 */
function handleStockReceiptSubmission(PDO $pdo, ?array $currentUser, array $post, array $files): array
{
    $response = [
        'errors' => [],
        'success_message' => '',
        'redirect' => null,
    ];

    $action = $post['form_action'] ?? '';
    if (!in_array($action, ['save_draft', 'post_receipt'], true)) {
        $response['errors'][] = 'Unknown action. Please use the form buttons provided.';
        return $response;
    }

    $receiptId = isset($post['receipt_id']) ? (int)$post['receipt_id'] : 0;
    $existingReceipt = null;
    if ($receiptId > 0) {
        $existingReceipt = loadStockReceiptWithItems($pdo, $receiptId);
        if (!$existingReceipt) {
            $response['errors'][] = 'Receipt not found. It may have been removed.';
            return $response;
        }

        if (!in_array($existingReceipt['header']['status'], ['draft'], true)) {
            $response['errors'][] = 'Only draft receipts can be edited. Please create a new stock-in document.';
            return $response;
        }
    }

    $supplierName = trim((string)($post['supplier_name'] ?? ''));
    $documentNumber = trim((string)($post['document_number'] ?? ''));
    $relatedReference = trim((string)($post['related_reference'] ?? ''));
    $notes = trim((string)($post['notes'] ?? ''));
    $discrepancyNote = trim((string)($post['discrepancy_note'] ?? ''));
    $dateReceivedRaw = $post['date_received'] ?? date('Y-m-d');

    $dateReceived = validateDateValue($dateReceivedRaw);
    if ($dateReceived === null) {
        $response['errors'][] = 'Invalid Date Received value.';
    }

    if ($supplierName === '') {
        $response['errors'][] = 'Supplier is required.';
    }

    if ($documentNumber === '') {
        $response['errors'][] = 'Delivery receipt or invoice number is required.';
    }

    $items = normalizeLineItems($post);
    if (empty($items)) {
        $response['errors'][] = 'Please add at least one valid product line.';
    }

    $hasDiscrepancy = false;
    foreach ($items as $item) {
        if (!empty($item['invalid_expiry'])) {
            $response['errors'][] = 'One or more expiry dates are invalid. Please use the YYYY-MM-DD format.';
            break;
        }
        if ($item['qty_received'] <= 0) {
            $response['errors'][] = 'Quantity received must be greater than zero for all items.';
            break;
        }
        if ($item['qty_expected'] !== null && $item['qty_expected'] !== $item['qty_received']) {
            $hasDiscrepancy = true;
        }
    }

    if ($action === 'post_receipt' && $hasDiscrepancy && $discrepancyNote === '') {
        $response['errors'][] = 'Please provide a discrepancy note to explain quantity differences.';
    }

    if (!empty($response['errors'])) {
        return $response;
    }

    $status = $action === 'save_draft' ? 'draft' : ($hasDiscrepancy ? 'with_discrepancy' : 'posted');
    $userId = (int)($_SESSION['user_id'] ?? 0);

    try {
        $pdo->beginTransaction();

        $receiptCode = $existingReceipt['header']['receipt_code'] ?? generateReceiptCode($pdo);
        $receivedByUserId = $status === 'draft' ? null : $userId;
        $postedAt = $status === 'draft' ? null : (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $postedByUserId = $status === 'draft' ? null : $userId;

        if ($receiptId === 0) {
            $insertReceipt = $pdo->prepare('
                INSERT INTO stock_receipts
                    (receipt_code, supplier_name, document_number, related_reference, date_received, notes, discrepancy_note,
                     status, created_by_user_id, updated_by_user_id, received_by_user_id, posted_at, posted_by_user_id,
                     created_at, updated_at)
                VALUES
                    (:receipt_code, :supplier_name, :document_number, :related_reference, :date_received, :notes, :discrepancy_note,
                     :status, :created_by, :updated_by, :received_by, :posted_at, :posted_by, NOW(), NOW())
            ');
            $insertReceipt->execute([
                ':receipt_code' => $receiptCode,
                ':supplier_name' => $supplierName,
                ':document_number' => $documentNumber,
                ':related_reference' => $relatedReference !== '' ? $relatedReference : null,
                ':date_received' => $dateReceived,
                ':notes' => $notes !== '' ? $notes : null,
                ':discrepancy_note' => $discrepancyNote !== '' ? $discrepancyNote : null,
                ':status' => $status,
                ':created_by' => $userId,
                ':updated_by' => $userId,
                ':received_by' => $receivedByUserId,
                ':posted_at' => $postedAt,
                ':posted_by' => $postedByUserId,
            ]);

            $receiptId = (int)$pdo->lastInsertId();
        } else {
            $updateReceipt = $pdo->prepare('
                UPDATE stock_receipts
                SET
                    supplier_name = :supplier_name,
                    document_number = :document_number,
                    related_reference = :related_reference,
                    date_received = :date_received,
                    notes = :notes,
                    discrepancy_note = :discrepancy_note,
                    status = :status,
                    updated_by_user_id = :updated_by,
                    received_by_user_id = :received_by,
                    posted_at = :posted_at,
                    posted_by_user_id = :posted_by,
                    updated_at = NOW()
                WHERE id = :receipt_id
            ');
            $updateReceipt->execute([
                ':supplier_name' => $supplierName,
                ':document_number' => $documentNumber,
                ':related_reference' => $relatedReference !== '' ? $relatedReference : null,
                ':date_received' => $dateReceived,
                ':notes' => $notes !== '' ? $notes : null,
                ':discrepancy_note' => $discrepancyNote !== '' ? $discrepancyNote : null,
                ':status' => $status,
                ':updated_by' => $userId,
                ':received_by' => $receivedByUserId,
                ':posted_at' => $postedAt,
                ':posted_by' => $postedByUserId,
                ':receipt_id' => $receiptId,
            ]);

            $pdo->prepare('DELETE FROM stock_receipt_items WHERE receipt_id = :receipt_id')
                ->execute([':receipt_id' => $receiptId]);
        }

        $insertItem = $pdo->prepare('
            INSERT INTO stock_receipt_items
                (receipt_id, product_id, qty_expected, qty_received, unit_cost, expiry_date, lot_number)
            VALUES
                (:receipt_id, :product_id, :qty_expected, :qty_received, :unit_cost, :expiry_date, :lot_number)
        ');

        $totalQty = 0;
        foreach ($items as $item) {
            $insertItem->execute([
                ':receipt_id' => $receiptId,
                ':product_id' => $item['product_id'],
                ':qty_expected' => $item['qty_expected'],
                ':qty_received' => $item['qty_received'],
                ':unit_cost' => $item['unit_cost'],
                ':expiry_date' => $item['expiry_date'],
                ':lot_number' => $item['lot_number'],
            ]);
            $totalQty += $item['qty_received'];
        }

        if ($status !== 'draft') {
            applyInventoryMovements($pdo, $receiptId, $items, $userId, $receiptCode);
        }

        $storedFiles = storeReceiptAttachments($pdo, $receiptId, $files['attachments'] ?? null, $userId);

        $auditAction = $existingReceipt ? 'updated' : 'created';
        logReceiptAudit($pdo, $receiptId, $auditAction, $userId, sprintf('Status: %s; Items: %d; Qty: %d', $status, count($items), $totalQty));
        if ($status !== 'draft') {
            logReceiptAudit($pdo, $receiptId, 'posted', $userId, 'Inventory quantities updated.');
        }

        $pdo->commit();

        if ($status === 'draft') {
            $queryParams = [
                $existingReceipt ? 'updated' : 'created' => '1',
                'receipt' => $receiptId,
            ];
            $response['redirect'] = 'stockEntry.php?' . http_build_query($queryParams);
        } else {
            $response['redirect'] = 'stockReceiptView.php?' . http_build_query([
                'receipt' => $receiptId,
                'posted' => '1',
            ]);
        }
        return $response;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Stock receipt submission failed: ' . $e->getMessage());
        $response['errors'][] = 'Unable to save stock-in document. Please try again or contact the administrator.';
        return $response;
    }
}

/**
 * Convert posted arrays into clean line item records.
 */
function normalizeLineItems(array $post): array
{
    $productIds = $post['product_id'] ?? [];
    $qtyExpected = $post['qty_expected'] ?? [];
    $qtyReceived = $post['qty_received'] ?? [];
    $unitCost = $post['unit_cost'] ?? [];
    $expiryDate = $post['expiry_date'] ?? [];
    $lotNumbers = $post['lot_number'] ?? [];

    $items = [];
    foreach ($productIds as $index => $productIdRaw) {
        $productId = (int)$productIdRaw;
        if ($productId <= 0) {
            continue;
        }

        $itemQtyReceived = isset($qtyReceived[$index]) ? (int)$qtyReceived[$index] : 0;
        if ($itemQtyReceived <= 0) {
            continue;
        }

        $expected = isset($qtyExpected[$index]) && $qtyExpected[$index] !== '' ? (int)$qtyExpected[$index] : null;
        $unit = isset($unitCost[$index]) && $unitCost[$index] !== '' ? round((float)$unitCost[$index], 2) : null;
        $rawExpiry = isset($expiryDate[$index]) ? trim((string)$expiryDate[$index]) : '';
        $expiry = $rawExpiry !== '' ? validateDateValue($rawExpiry) : null;
        $lot = isset($lotNumbers[$index]) ? trim((string)$lotNumbers[$index]) : null;

        $items[] = [
            'product_id' => $productId,
            'qty_expected' => $expected,
            'qty_received' => $itemQtyReceived,
            'unit_cost' => $unit,
            'expiry_date' => $expiry,
            'invalid_expiry' => $rawExpiry !== '' && $expiry === null,
            'lot_number' => $lot !== '' ? $lot : null,
        ];
    }

    return $items;
}

/**
 * Prepare raw line item values for redisplay, preserving incomplete rows after validation errors.
 */
function normalizeLineItemsPreserveBlank(array $post): array
{
    $productIds = $post['product_id'] ?? [];
    $qtyExpected = $post['qty_expected'] ?? [];
    $qtyReceived = $post['qty_received'] ?? [];
    $unitCost = $post['unit_cost'] ?? [];
    $expiryDate = $post['expiry_date'] ?? [];
    $lotNumbers = $post['lot_number'] ?? [];

    $rowCount = max(
        count($productIds),
        count($qtyExpected),
        count($qtyReceived),
        count($unitCost),
        count($expiryDate),
        count($lotNumbers)
    );

    $items = [];
    for ($index = 0; $index < $rowCount; $index++) {
        $productId = isset($productIds[$index]) && $productIds[$index] !== '' ? (int)$productIds[$index] : null;
        $expectedRaw = $qtyExpected[$index] ?? '';
        $receivedRaw = $qtyReceived[$index] ?? '';
        $unitRaw = $unitCost[$index] ?? '';
        $expiryRaw = isset($expiryDate[$index]) ? trim((string)$expiryDate[$index]) : '';
        $lotRaw = isset($lotNumbers[$index]) ? trim((string)$lotNumbers[$index]) : '';

        $expected = $expectedRaw === '' ? null : (int)$expectedRaw;
        $received = $receivedRaw === '' ? null : (int)$receivedRaw;
        $unit = $unitRaw === '' ? null : round((float)$unitRaw, 2);
        $expiry = $expiryRaw !== '' ? validateDateValue($expiryRaw) : null;
        $invalidExpiry = $expiryRaw !== '' && $expiry === null;

        $items[] = [
            'product_id' => $productId,
            'qty_expected' => $expected,
            'qty_received' => $received,
            'unit_cost' => $unit,
            'expiry_date' => $invalidExpiry ? null : $expiry,
            'expiry_value' => $invalidExpiry ? $expiryRaw : $expiry,
            'lot_number' => $lotRaw !== '' ? $lotRaw : null,
            'has_discrepancy' => $expected !== null && $received !== null && $expected !== $received,
            'invalid_expiry' => $invalidExpiry,
        ];
    }

    return $items;
}

/**
 * Ensure supplied date is valid and returns Y-m-d.
 */
function validateDateValue(?string $value): ?string
{
    if (empty($value)) {
        return date('Y-m-d');
    }

    $date = DateTime::createFromFormat('Y-m-d', $value);
    return $date ? $date->format('Y-m-d') : null;
}

/**
 * Generate a human friendly yet unique receipt code.
 */
function generateReceiptCode(PDO $pdo): string
{
    do {
        $code = 'SR-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM stock_receipts WHERE receipt_code = ?');
        $stmt->execute([$code]);
        $exists = (int)$stmt->fetchColumn() > 0;
    } while ($exists);

    return $code;
}

/**
 * Update product quantities and log each movement for the posted receipt.
 */
function applyInventoryMovements(PDO $pdo, int $receiptId, array $items, int $userId, string $receiptCode): void
{
    $updateProduct = $pdo->prepare('UPDATE products SET quantity = quantity + :qty WHERE id = :product_id');
    $insertLedger = $pdo->prepare('
        INSERT INTO inventory_ledger
            (product_id, change_type, quantity_change, reference_type, reference_id, reference_code, created_by_user_id, created_at)
        VALUES
            (:product_id, :change_type, :quantity_change, :reference_type, :reference_id, :reference_code, :created_by, NOW())
    ');

    foreach ($items as $item) {
        $updateProduct->execute([
            ':qty' => $item['qty_received'],
            ':product_id' => $item['product_id'],
        ]);

        $insertLedger->execute([
            ':product_id' => $item['product_id'],
            ':change_type' => 'in',
            ':quantity_change' => $item['qty_received'],
            ':reference_type' => 'stock_receipt',
            ':reference_id' => $receiptId,
            ':reference_code' => $receiptCode,
            ':created_by' => $userId,
        ]);
    }
}

/**
 * Persist uploaded delivery proof files under uploads/stock_receipts and register them.
 */
function storeReceiptAttachments(PDO $pdo, int $receiptId, ?array $files, int $userId): array
{
    if ($files === null || empty($files['name'])) {
        return [];
    }

    $stored = [];
    $baseDir = dirname(__DIR__, 2) . '/uploads/stock_receipts';
    if (!is_dir($baseDir)) {
        mkdir($baseDir, 0775, true);
    }

    $receiptDir = $baseDir . '/' . $receiptId;
    if (!is_dir($receiptDir)) {
        mkdir($receiptDir, 0775, true);
    }

    $insertFile = $pdo->prepare('
        INSERT INTO stock_receipt_files
            (receipt_id, file_path, original_name, mime_type, uploaded_by_user_id, created_at)
        VALUES
            (:receipt_id, :file_path, :original_name, :mime_type, :uploaded_by, NOW())
    ');

    $allowedMime = ['application/pdf', 'image/jpeg', 'image/png'];

    $fileCount = is_array($files['name']) ? count($files['name']) : 0;
    for ($i = 0; $i < $fileCount; $i++) {
        if (($files['error'][$i] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            continue;
        }

        $tmpName = $files['tmp_name'][$i] ?? null;
        $originalName = $files['name'][$i] ?? '';
        $mimeType = mime_content_type($tmpName);
        if (!in_array($mimeType, $allowedMime, true)) {
            continue;
        }

        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        $targetName = uniqid('receipt_', true) . ($extension ? '.' . strtolower($extension) : '');
        $targetPath = $receiptDir . '/' . $targetName;

        if (!move_uploaded_file($tmpName, $targetPath)) {
            continue;
        }

        $relativePath = 'uploads/stock_receipts/' . $receiptId . '/' . $targetName;
        $insertFile->execute([
            ':receipt_id' => $receiptId,
            ':file_path' => $relativePath,
            ':original_name' => $originalName,
            ':mime_type' => $mimeType,
            ':uploaded_by' => $userId,
        ]);

        $stored[] = $relativePath;
    }

    return $stored;
}

/**
 * Record audit trail events for transparency.
 */
function logReceiptAudit(PDO $pdo, int $receiptId, string $action, int $userId, ?string $details = null): void
{
    $stmt = $pdo->prepare('
        INSERT INTO stock_receipt_audit_log
            (receipt_id, action, details, action_by_user_id, action_at)
        VALUES
            (:receipt_id, :action, :details, :action_by, NOW())
    ');
    $stmt->execute([
        ':receipt_id' => $receiptId,
        ':action' => $action,
        ':details' => $details,
        ':action_by' => $userId,
    ]);
}

/**
 * Fetch latest stock receipts with aggregate info for dashboard table.
 */
function fetchRecentReceipts(PDO $pdo): array
{
    $sql = '
        SELECT
            sr.id,
            sr.receipt_code,
            sr.supplier_name,
            sr.status,
            sr.created_at,
            sr.posted_at,
            u.name AS received_by_name,
            COUNT(items.id) AS item_count,
            COALESCE(SUM(items.qty_received), 0) AS total_received_qty
        FROM stock_receipts sr
        LEFT JOIN stock_receipt_items items ON items.receipt_id = sr.id
        LEFT JOIN users u ON u.id = sr.received_by_user_id
        GROUP BY sr.id, sr.receipt_code, sr.supplier_name, sr.status, sr.created_at, sr.posted_at, u.name
        ORDER BY sr.created_at DESC
        LIMIT 10
    ';
    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        $row['created_at_formatted'] = $row['created_at'] ? date('M d, Y H:i', strtotime($row['created_at'])) : '';
        $row['posted_at_formatted'] = $row['posted_at'] ? date('M d, Y H:i', strtotime($row['posted_at'])) : '';
    }

    return $rows;
}

/**
 * Convert status codes to human friendly labels for the UI.
 */
function formatStatusLabel(string $status): string
{
    return formatStockReceiptStatus($status);
}

/**
 * Provide the list of supported receipt statuses for UI filters.
 */
function getStockReceiptStatusOptions(): array
{
    return [
        'draft' => formatStockReceiptStatus('draft'),
        'posted' => formatStockReceiptStatus('posted'),
        'with_discrepancy' => formatStockReceiptStatus('with_discrepancy'),
    ];
}

/**
 * Normalise stock-in report filter inputs for safe querying and UI feedback.
 */
function parseStockInReportFilters(
    array $source,
    array $statusOptions,
    array $productLookup = [],
    array $brandOptions = [],
    array $categoryOptions = []
): array
{
    $dateFromRaw = trim((string)($source['report_date_from'] ?? ''));
    $dateToRaw = trim((string)($source['report_date_to'] ?? ''));
    $dateFrom = $dateFromRaw !== '' ? normalizeOptionalReportDate($dateFromRaw) : null;
    $dateTo = $dateToRaw !== '' ? normalizeOptionalReportDate($dateToRaw) : null;

    $supplier = trim((string)($source['report_supplier'] ?? ''));
    $productId = isset($source['report_product_id']) ? (int)$source['report_product_id'] : 0;
    $productSearch = trim((string)($source['report_product_search'] ?? ''));
    $brand = trim((string)($source['report_brand'] ?? ''));
    $category = trim((string)($source['report_category'] ?? ''));
    $status = trim((string)($source['report_status'] ?? ''));
    if ($status !== '' && !array_key_exists($status, $statusOptions)) {
        $status = '';
    }

    if ($brand !== '' && !in_array($brand, $brandOptions, true)) {
        $brand = '';
    }

    if ($category !== '' && !in_array($category, $categoryOptions, true)) {
        $category = '';
    }

    return [
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'date_from_input' => $dateFromRaw,
        'date_to_input' => $dateToRaw,
        'supplier' => $supplier,
        'product_id' => $productId > 0 ? $productId : 0,
        'product_search' => $productSearch,
        'brand' => $brand,
        'category' => $category,
        'status' => $status,
        'product_label' => ($productId > 0 && isset($productLookup[$productId])) ? $productLookup[$productId] : '',
        'status_label' => $status !== '' ? ($statusOptions[$status] ?? $status) : '',
    ];
}

/**
 * Sanitize optional report filter date values while preserving invalid input.
 */
function normalizeOptionalReportDate(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    $date = DateTime::createFromFormat('Y-m-d', $value);
    return $date ? $date->format('Y-m-d') : null;
}

/**
 * Fetch stock receipt lines matching the active filters for reporting.
 */
function fetchStockInReport(PDO $pdo, array $filters, ?int $limit = 50): array
{
    $sql = '
        SELECT
            sr.date_received,
            sr.receipt_code,
            sr.supplier_name,
            sr.document_number,
            sr.status,
            sri.qty_received,
            sri.unit_cost,
            COALESCE(p.name, CONCAT(\'Product #\', sri.product_id)) AS product_name,
            COALESCE(receiver.name, \'Pending\') AS receiver_name
        FROM stock_receipts sr
        INNER JOIN stock_receipt_items sri ON sri.receipt_id = sr.id
        LEFT JOIN products p ON p.id = sri.product_id
        LEFT JOIN users receiver ON receiver.id = sr.received_by_user_id
        WHERE 1=1
    ';
    $params = [];

    if (!empty($filters['date_from'])) {
        $sql .= ' AND sr.date_received >= :date_from';
        $params[':date_from'] = $filters['date_from'];
    }

    if (!empty($filters['date_to'])) {
        $sql .= ' AND sr.date_received <= :date_to';
        $params[':date_to'] = $filters['date_to'];
    }

    if ($filters['supplier'] !== '') {
        $sql .= ' AND sr.supplier_name LIKE :supplier';
        $params[':supplier'] = '%' . $filters['supplier'] . '%';
    }

    if (!empty($filters['product_id'])) {
        $sql .= ' AND sri.product_id = :product_id';
        $params[':product_id'] = $filters['product_id'];
    }

    if ($filters['product_search'] !== '') {
        $sql .= ' AND (
            (p.name LIKE :product_search)
            OR (p.code LIKE :product_search)
            OR (sr.receipt_code LIKE :product_search)
            OR (sr.document_number LIKE :product_search)
        )';
        $params[':product_search'] = '%' . $filters['product_search'] . '%';
    }

    if ($filters['brand'] !== '') {
        $sql .= ' AND p.brand = :brand';
        $params[':brand'] = $filters['brand'];
    }

    if ($filters['category'] !== '') {
        $sql .= ' AND p.category = :category';
        $params[':category'] = $filters['category'];
    }

    if (!empty($filters['status'])) {
        $sql .= ' AND sr.status = :status';
        $params[':status'] = $filters['status'];
    }

    $sql .= ' ORDER BY sr.date_received DESC, sr.id DESC, sri.id ASC';
    if ($limit !== null) {
        $sql .= ' LIMIT ' . (int)$limit;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        $row['qty_received'] = isset($row['qty_received']) ? (float)$row['qty_received'] : 0.0;
        $row['unit_cost'] = isset($row['unit_cost']) ? (float)$row['unit_cost'] : 0.0;
        $row['date_display'] = $row['date_received'] ? date('M d, Y', strtotime($row['date_received'])) : '';
        $row['qty_received_display'] = number_format($row['qty_received'], 0);
        $row['unit_cost_display'] = number_format($row['unit_cost'], 2);
        $row['status_label'] = formatStockReceiptStatus($row['status']);
    }

    return $rows;
}

/**
 * Build a lightweight inventory snapshot with last received date for each product.
 */
function fetchCurrentInventorySnapshot(PDO $pdo, int $limit = 12): array
{
    $sql = '
        SELECT
            p.id,
            p.name,
            p.quantity,
            p.low_stock_threshold,
            MAX(CASE WHEN sr.status IN (\'posted\', \'with_discrepancy\') THEN sr.date_received ELSE NULL END) AS last_received_date
        FROM products p
        LEFT JOIN stock_receipt_items sri ON sri.product_id = p.id
        LEFT JOIN stock_receipts sr ON sr.id = sri.receipt_id
        GROUP BY p.id, p.name, p.quantity, p.low_stock_threshold
        ORDER BY p.name ASC
        LIMIT ' . (int)$limit . '
    ';

    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        $quantity = (float)($row['quantity'] ?? 0);
        $threshold = isset($row['low_stock_threshold']) ? (float)$row['low_stock_threshold'] : 0;
        $row['on_hand_display'] = number_format($quantity, 0);
        $row['last_received_display'] = !empty($row['last_received_date']) ? date('M d, Y', strtotime($row['last_received_date'])) : '—';
        $row['is_low_stock'] = $threshold > 0 ? $quantity <= $threshold : $quantity <= 0;
    }

    return $rows;
}

/**
 * Output the filtered rows in the requested export format.
 */
function handleStockInReportExport(string $format, array $rows, array $filters): void
{
    $filenameBase = 'stock-in-report-' . date('Ymd-His');
    $headers = ['Date', 'Doc No', 'Supplier', 'DR No', 'Product', 'Qty Received', 'Unit Cost', 'Receiver', 'Status'];

    switch ($format) {
        case 'csv':
            exportStockInReportCsv($filenameBase, $headers, $rows);
            break;
        case 'xlsx':
            exportStockInReportXlsx($filenameBase, $headers, $rows);
            break;
        case 'pdf':
            exportStockInReportPdf($filenameBase, $headers, $rows, $filters);
            break;
    }
}

/**
 * Emit report data as a CSV file for spreadsheets.
 */
function exportStockInReportCsv(string $filenameBase, array $headers, array $rows): void
{
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filenameBase . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $handle = fopen('php://output', 'w');
    fputcsv($handle, $headers);

    foreach ($rows as $row) {
        fputcsv($handle, [
            $row['date_display'] ?? '',
            $row['receipt_code'] ?? '',
            $row['supplier_name'] ?? '',
            $row['document_number'] ?? '',
            $row['product_name'] ?? '',
            $row['qty_received'] ?? 0,
            $row['unit_cost'] ?? 0,
            $row['receiver_name'] ?? '',
            $row['status_label'] ?? '',
        ]);
    }

    fclose($handle);
    exit;
}

/**
 * Emit report data as an XLSX workbook using basic SpreadsheetML parts.
 */
function exportStockInReportXlsx(string $filenameBase, array $headers, array $rows): void
{
    if (!class_exists('ZipArchive')) {
        exportStockInReportCsv($filenameBase, $headers, $rows);
        return;
    }

    $sheetRows = [];
    $sheetRows[] = buildSpreadsheetRow(1, $headers, true);

    $rowIndex = 2;
    foreach ($rows as $row) {
        $sheetRows[] = buildSpreadsheetRow($rowIndex, [
            $row['date_display'] ?? '',
            $row['receipt_code'] ?? '',
            $row['supplier_name'] ?? '',
            $row['document_number'] ?? '',
            $row['product_name'] ?? '',
            (float)($row['qty_received'] ?? 0),
            (float)($row['unit_cost'] ?? 0),
            $row['receiver_name'] ?? '',
            $row['status_label'] ?? '',
        ]);
        $rowIndex++;
    }

    $sheetXml = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetData>' . implode('', $sheetRows) . '</sheetData>'
        . '</worksheet>';

    $workbookXml = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="StockIn" sheetId="1" r:id="rId1"/></sheets>'
        . '</workbook>';

    $docPropsCoreXml = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties"'
        . ' xmlns:dc="http://purl.org/dc/elements/1.1/"'
        . ' xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/"'
        . ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
        . '<dc:title>Stock-In Report</dc:title>'
        . '<dc:creator>DGZ MotorShop</dc:creator>'
        . '<cp:lastModifiedBy>DGZ MotorShop</cp:lastModifiedBy>'
        . '<dcterms:created xsi:type="dcterms:W3CDTF">' . date('c') . '</dcterms:created>'
        . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . date('c') . '</dcterms:modified>'
        . '</cp:coreProperties>';

    $docPropsAppXml = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"'
        . ' xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
        . '<Application>DGZ MotorShop</Application>'
        . '</Properties>';

    $rootRelsXml = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
        . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
        . '</Relationships>';

    $workbookRelsXml = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '</Relationships>';

    $contentTypesXml = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
        . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
        . '</Types>';

    $tmpFile = tempnam(sys_get_temp_dir(), 'xlsx');
    $zip = new ZipArchive();
    $opened = $zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    if ($opened !== true) {
        @unlink($tmpFile);
        exportStockInReportCsv($filenameBase, $headers, $rows);
        return;
    }
    $zip->addFromString('[Content_Types].xml', $contentTypesXml);
    $zip->addFromString('_rels/.rels', $rootRelsXml);
    $zip->addFromString('docProps/core.xml', $docPropsCoreXml);
    $zip->addFromString('docProps/app.xml', $docPropsAppXml);
    $zip->addFromString('xl/workbook.xml', $workbookXml);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRelsXml);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
    $zip->close();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filenameBase . '.xlsx"');
    header('Pragma: no-cache');
    header('Expires: 0');
    readfile($tmpFile);
    @unlink($tmpFile);
    exit;
}

/**
 * Emit report data as a simple text-based PDF.
 */
function exportStockInReportPdf(string $filenameBase, array $headers, array $rows, array $filters): void
{
    $lines = [];
    $lines[] = 'Stock-In Report';

    $activeFilters = [];
    if (!empty($filters['date_from_input'])) {
        $activeFilters[] = 'From ' . $filters['date_from_input'];
    }
    if (!empty($filters['date_to_input'])) {
        $activeFilters[] = 'To ' . $filters['date_to_input'];
    }
    if (!empty($filters['supplier'])) {
        $activeFilters[] = 'Supplier: ' . $filters['supplier'];
    }
    if (!empty($filters['product_label'])) {
        $activeFilters[] = 'Product: ' . $filters['product_label'];
    } elseif (!empty($filters['product_id'])) {
        $activeFilters[] = 'Product ID: ' . $filters['product_id'];
    }
    if (!empty($filters['product_search'])) {
        $activeFilters[] = 'Product search: ' . $filters['product_search'];
    }
    if (!empty($filters['brand'])) {
        $activeFilters[] = 'Brand: ' . $filters['brand'];
    }
    if (!empty($filters['category'])) {
        $activeFilters[] = 'Category: ' . $filters['category'];
    }
    if (!empty($filters['status_label'])) {
        $activeFilters[] = 'Status: ' . $filters['status_label'];
    }

    if (!empty($activeFilters)) {
        $lines[] = 'Filters: ' . implode(', ', $activeFilters);
    }

    $lines[] = implode(' | ', $headers);

    if (empty($rows)) {
        $lines[] = 'No stock-in records matched the selected filters.';
    } else {
        foreach ($rows as $row) {
            $lines[] = implode(' | ', [
                $row['date_display'] ?? '',
                $row['receipt_code'] ?? '',
                $row['supplier_name'] ?? '',
                $row['document_number'] ?? '',
                $row['product_name'] ?? '',
                (string)($row['qty_received'] ?? 0),
                number_format((float)($row['unit_cost'] ?? 0), 2),
                $row['receiver_name'] ?? '',
                $row['status_label'] ?? '',
            ]);
        }
    }

    $pdfContent = buildSimplePdfDocument($lines);

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filenameBase . '.pdf"');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo $pdfContent;
    exit;
}

/**
 * Helper: build an XLSX worksheet row from raw values.
 */
function buildSpreadsheetRow(int $rowNumber, array $values, bool $isHeader = false): string
{
    $cells = [];
    foreach ($values as $index => $value) {
        $column = columnLetterFromIndex($index) . $rowNumber;
        if ($isHeader || !is_numeric($value)) {
            $escaped = htmlspecialchars((string)$value, ENT_QUOTES | ENT_XML1);
            $cells[] = '<c r="' . $column . '" t="inlineStr"><is><t>' . $escaped . '</t></is></c>';
        } else {
            $cells[] = '<c r="' . $column . '" t="n"><v>' . $value . '</v></c>';
        }
    }

    return '<row r="' . $rowNumber . '">' . implode('', $cells) . '</row>';
}

/**
 * Helper: turn a zero-based column index into Excel style letters.
 */
function columnLetterFromIndex(int $index): string
{
    $letters = '';
    while ($index >= 0) {
        $letters = chr(($index % 26) + 65) . $letters;
        $index = intdiv($index, 26) - 1;
    }
    return $letters;
}

/**
 * Create a minimal multi-page PDF string from plain text lines.
 */
function buildSimplePdfDocument(array $lines): string
{
    $pageHeight = 792; // 11in @ 72dpi
    $leftMargin = 40;
    $topStart = 760;
    $lineHeight = 16;
    $bottomMargin = 40;
    $linesPerPage = max(1, (int)floor(($topStart - $bottomMargin) / $lineHeight));
    $lineChunks = array_chunk($lines, $linesPerPage);

    $objects = [];
    $kids = [];
    $objectNumber = 1;

    $catalogObject = $objectNumber++;
    $pagesObject = $objectNumber++;
    $fontObject = $objectNumber++;

    $objects[$fontObject] = $fontObject . ' 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj';

    foreach ($lineChunks as $chunkIndex => $chunk) {
        $contentStream = buildPdfContentStream($chunk, $leftMargin, $topStart, $lineHeight);
        $contentObject = $objectNumber++;
        $pageObject = $objectNumber++;

        $objects[$contentObject] = $contentObject . ' 0 obj << /Length ' . strlen($contentStream) . ' >> stream\n' . $contentStream . '\nendstream\nendobj';

        $objects[$pageObject] = $pageObject . ' 0 obj << /Type /Page /Parent ' . $pagesObject . ' 0 R /MediaBox [0 0 612 ' . $pageHeight . '] /Resources << /Font << /F1 ' . $fontObject . ' 0 R >> >> /Contents ' . $contentObject . ' 0 R >> endobj';

        $kids[] = $pageObject . ' 0 R';
    }

    $objects[$pagesObject] = $pagesObject . ' 0 obj << /Type /Pages /Count ' . count($kids) . ' /Kids [' . implode(' ', $kids) . '] >> endobj';
    $objects[$catalogObject] = $catalogObject . ' 0 obj << /Type /Catalog /Pages ' . $pagesObject . ' 0 R >> endobj';

    ksort($objects);

    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $number => $objectString) {
        $offsets[$number] = strlen($pdf);
        $pdf .= $objectString . "\n";
    }

    $xrefPosition = strlen($pdf);
    $maxObject = max(array_keys($objects));
    $pdf .= 'xref\n0 ' . ($maxObject + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i <= $maxObject; $i++) {
        $offset = $offsets[$i] ?? 0;
        $pdf .= sprintf('%010d 00000 n ', $offset) . "\n";
    }
    $pdf .= 'trailer << /Size ' . ($maxObject + 1) . ' /Root ' . $catalogObject . ' 0 R >>\n';
    $pdf .= 'startxref\n' . $xrefPosition . "\n%%EOF";

    return $pdf;
}

/**
 * Generate the page content stream for the simple PDF builder.
 */
function buildPdfContentStream(array $lines, int $leftMargin, int $topStart, int $lineHeight): string
{
    $content = "BT\n/F1 12 Tf\n";
    $currentY = $topStart;
    foreach ($lines as $line) {
        $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line);
        $content .= sprintf('1 0 0 1 %d %d Tm (%s) Tj\n', $leftMargin, $currentY, $escaped);
        $currentY -= $lineHeight;
    }
    $content .= "ET";
    return $content;
}
