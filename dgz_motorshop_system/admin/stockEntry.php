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
        if (in_array($exportFormat, ['csv', 'pdf'], true)) {
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
                        <div class="form-group" id="discrepancyNoteGroup" data-has-initial="<?= $hasPresetDiscrepancy ? '1' : '0' ?>" <?= $discrepancyGroupHiddenAttr ?> >
                            <label for="discrepancy_note">Discrepancy Note <span class="required" data-discrepancy-required <?= $hasPresetDiscrepancy ? '' : 'hidden' ?>>*</span></label>
                            <textarea id="discrepancy_note" name="discrepancy_note" rows="3" placeholder="Explain missing, damaged, or excess items"<?= $hasPresetDiscrepancy ? ' required' : '' ?>><?= htmlspecialchars($formDiscrepancyNote) ?></textarea>
                        </div>
                    </fieldset>

                    <?php if (!$formLocked): ?>
                        <div class="line-items-controls">
                            <button type="button" class="btn-secondary" id="addLineItemBtn">
                                <i class="fas fa-plus"></i> Add Line Item
                            </button>
                            <span class="line-items-hint">Add rows for each product received.</span>
                        </div>
                    <?php endif; ?>

                    <fieldset class="form-section line-items-section" aria-labelledby="lineItemsTitle" <?= $formLocked ? 'disabled' : '' ?>>
                        <legend id="lineItemsTitle">Line Items</legend>
                        <p class="table-hint table-hint-inline">Tip: Use Qty Expected to highlight discrepancies before posting.</p>
                        <div id="lineItemsBody" class="line-items-body">
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
                                <div class="line-item-row<?= $rowHasDiscrepancy ? ' has-discrepancy' : '' ?>" data-selected-product="<?= $productId ? (int)$productId : '' ?>">
                                    <div class="line-item-header">
                                        <h4 class="line-item-title">Item <?= $index + 1 ?></h4>
                                        <button type="button" class="icon-btn remove-line-item" aria-label="Remove line item" <?= ($index === 0 || $formLocked) ? 'disabled' : '' ?>>
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                    <div class="line-item-grid">
                                        <div class="line-item-field product-field">
                                            <label>Product <span class="required">*</span></label>
                                            <div class="product-selector">
                                                <div class="product-search-wrapper">
                                                    <span class="product-search-icon" aria-hidden="true">
                                                        <i class="fas fa-search"></i>
                                                    </span>
                                                    <input type="text" class="product-search" placeholder="Search name or code" value="">
                                                    <button type="button" class="product-clear" aria-label="Clear selected product" hidden>
                                                        <i class="fas fa-times"></i>
                                                    </button>
                                                    <div class="product-suggestions"></div>
                                                </div>
                                                <select name="product_id[]" class="product-select" required>
                                                    <option value="">Select product</option>
                                                    <?php foreach ($products as $product): ?>
                                                        <option value="<?= (int)$product['id'] ?>" <?= $productId == (int)$product['id'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($product['name']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="line-item-field">
                                            <label>Qty Expected</label>
                                            <input type="number" name="qty_expected[]" min="0" step="1" placeholder="0" value="<?= $qtyExpected !== null ? htmlspecialchars((string)$qtyExpected) : '' ?>">
                                        </div>
                                        <div class="line-item-field">
                                            <label>Qty Received <span class="required">*</span></label>
                                            <input type="number" name="qty_received[]" min="0" step="1" placeholder="0" value="<?= $qtyReceived !== null ? htmlspecialchars((string)$qtyReceived) : '' ?>" <?= $formLocked ? 'readonly' : 'required' ?>>
                                        </div>
                                        <div class="line-item-field">
                                            <label>Unit Cost</label>
                                            <input type="number" name="unit_cost[]" min="0" step="0.01" placeholder="0.00" value="<?= $unitCost !== null ? htmlspecialchars(number_format((float)$unitCost, 2, '.', '')) : '' ?>">
                                        </div>
                                        <div class="line-item-field">
                                            <label>Expiry</label>
                                            <div>
                                                <input type="date" name="expiry_date[]" value="<?= $expiryDate ? htmlspecialchars($expiryDate) : '' ?>">
                                                <?php if ($rowInvalidExpiry): ?>
                                                    <small class="input-error">Use YYYY-MM-DD (e.g., <?= date('Y-m-d') ?>).</small>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="line-item-field">
                                            <label>Lot / Batch</label>
                                            <input type="text" name="lot_number[]" placeholder="Lot or batch" value="<?= $lotNumber ? htmlspecialchars($lotNumber) : '' ?>">
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
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
                        <button type="submit" class="btn-secondary" name="stock_in_export" value="pdf">Export PDF</button>
                    </div>
                </form>
                <?php if (!empty($stockInReportRows)): ?>
                    <div class="table-wrapper">
                        <table class="data-table">
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
                                        <td><?= htmlspecialchars($reportRow['product_name'] ?? 'Unknown Product') ?></td>
                                        <td><?= htmlspecialchars($reportRow['qty_received_display'] ?? '0') ?></td>
                                        <td><?= htmlspecialchars($reportRow['unit_cost_display'] ?? '0.00') ?></td>
                                        <td><?= htmlspecialchars($reportRow['receiver_name'] ?? 'Pending') ?></td>
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
                        <table class="data-table data-table--compact">
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
                        <table class="data-table data-table--compact">
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
    <script src="../assets/js/dashboard/userMenu.js"></script>
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
 * Use the database server time so posted timestamps align with other audit fields.
 */
function fetchDatabaseCurrentDateTime(PDO $pdo): string
{
    try {
        $statement = $pdo->query('SELECT NOW()');
        $value = $statement ? $statement->fetchColumn() : false;
        if ($value !== false && $value !== null) {
            return (string)$value;
        }
    } catch (Throwable $e) {
        // Ignore and fall back to PHP time below.
    }

    return (new DateTimeImmutable())->format('Y-m-d H:i:s');
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
        $postedByUserId = $status === 'draft' ? null : $userId;
        $postedAt = $status === 'draft' ? null : fetchDatabaseCurrentDateTime($pdo);

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
    $baseDir = dirname(__DIR__) . '/uploads/stock-receipts';
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

        $relativePath = 'uploads/stock-receipts/' . $receiptId . '/' . $targetName;
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
    $productsSql = '
        SELECT
            p.id,
            p.name,
            p.quantity,
            p.low_stock_threshold
        FROM products p
        ORDER BY p.name ASC
        LIMIT :limit
    ';

    $stmt = $pdo->prepare($productsSql);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($products)) {
        return [];
    }

    $productIds = array_map(static fn($row) => (int)$row['id'], $products);

    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $lastReceivedSql = '
        SELECT
            sri.product_id,
            MAX(sr.date_received) AS last_received_date
        FROM stock_receipt_items sri
        JOIN stock_receipts sr ON sr.id = sri.receipt_id
        WHERE sri.product_id IN (' . $placeholders . ')
            AND sr.status IN (\'posted\', \'with_discrepancy\')
        GROUP BY sri.product_id
    ';
    $lastStmt = $pdo->prepare($lastReceivedSql);
    $lastStmt->execute($productIds);
    $lastReceivedMap = [];
    foreach ($lastStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $lastReceivedMap[(int)$row['product_id']] = $row['last_received_date'];
    }

    foreach ($products as &$row) {
        $productId = (int)$row['id'];
        $quantity = (float)($row['quantity'] ?? 0);
        $threshold = isset($row['low_stock_threshold']) ? (float)$row['low_stock_threshold'] : 0;
        $lastReceivedRaw = $lastReceivedMap[$productId] ?? null;

        $row['product_name'] = $row['name'] ?? ('Product #' . $productId);
        $row['on_hand_display'] = number_format($quantity, 0);
        $row['last_received_display'] = $lastReceivedRaw ? date('M d, Y', strtotime($lastReceivedRaw)) : '—';
        $row['is_low_stock'] = $threshold > 0 ? $quantity <= $threshold : $quantity <= 0;
    }

    return $products;
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
 * Emit report data as a Dompdf-rendered PDF so it matches the styling of the sales report.
 */
function exportStockInReportPdf(string $filenameBase, array $headers, array $rows, array $filters): void
{
<<<<<<< HEAD

    $columnWidths = [12, 12, 18, 12, 28, 12, 12, 16, 12];
    $strongDivider = buildPdfTableDivider($columnWidths, '=');
    $lightDivider = buildPdfTableDivider($columnWidths, '-');

    $lines = [];
    $lines[] = 'DGZ Motorshop · Stock-In Report';
    $lines[] = 'Generated: ' . date('M d, Y g:i A');

    $activeFilters = [];
    if (!empty($filters['date_from_input'])) {
        $activeFilters[] = 'Date From: ' . $filters['date_from_input'];
    }
    if (!empty($filters['date_to_input'])) {
        $activeFilters[] = 'Date To: ' . $filters['date_to_input'];
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
        $activeFilters[] = 'Product Search: ' . $filters['product_search'];
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

    $lines[] = '';
    if (!empty($activeFilters)) {
        $lines[] = 'Filters:';
        foreach ($activeFilters as $filterLine) {
            $lines[] = '  • ' . $filterLine;
        }
    } else {
        $lines[] = 'Filters: All stock-in entries';
    }

    $lines[] = '';
    $lines[] = $strongDivider;
    $lines[] = formatPdfTableRow($headers, $columnWidths);
    $lines[] = $lightDivider;

    if (empty($rows)) {
        $lines[] = 'No stock-in records matched the selected filters.';
    } else {
        foreach ($rows as $row) {
            $lines[] = formatPdfTableRow([
                $row['date_display'] ?? '',
                $row['receipt_code'] ?? '',
                $row['supplier_name'] ?? '',
                $row['document_number'] ?? '',
                $row['product_name'] ?? 'Unknown Product',
                $row['qty_received_display'] ?? (string)($row['qty_received'] ?? 0),
                $row['unit_cost_display'] ?? number_format((float)($row['unit_cost'] ?? 0), 2),
                $row['receiver_name'] ?? 'Pending',
                $row['status_label'] ?? '',
            ], $columnWidths);

=======
>>>>>>> codex/fix-stockentry-http-error-500
    require_once __DIR__ . '/../vendor/autoload.php';

    $generatedOn = date('F j, Y g:i A');
    $reportTitle = 'DGZ Motorshop · Stock-In Report';

    $filterSummaries = [];

    $dateFromLabel = '';
    if (!empty($filters['date_from'])) {
        $dateFromLabel = date('M d, Y', strtotime($filters['date_from']));
    } elseif (!empty($filters['date_from_input'])) {
        $dateFromLabel = $filters['date_from_input'];
    }

    $dateToLabel = '';
    if (!empty($filters['date_to'])) {
        $dateToLabel = date('M d, Y', strtotime($filters['date_to']));
    } elseif (!empty($filters['date_to_input'])) {
        $dateToLabel = $filters['date_to_input'];
    }

    if ($dateFromLabel !== '' || $dateToLabel !== '') {
        if ($dateFromLabel !== '' && $dateToLabel !== '') {
            $filterSummaries[] = 'Date: ' . $dateFromLabel . ' – ' . $dateToLabel;
        } elseif ($dateFromLabel !== '') {
            $filterSummaries[] = 'Date From: ' . $dateFromLabel;
        } else {
            $filterSummaries[] = 'Date To: ' . $dateToLabel;
<<<<<<< HEAD

=======
>>>>>>> codex/fix-stockentry-http-error-500
        }
    }

<<<<<<< HEAD

    $lines[] = '';
    $lines[] = 'Prepared via DGZ Inventory System';

    $pdfContent = buildSimplePdfDocument($lines);

=======
>>>>>>> codex/fix-stockentry-http-error-500
    if (!empty($filters['supplier'])) {
        $filterSummaries[] = 'Supplier: ' . $filters['supplier'];
    }
    if (!empty($filters['product_label'])) {
        $filterSummaries[] = 'Product: ' . $filters['product_label'];
    } elseif (!empty($filters['product_id'])) {
        $filterSummaries[] = 'Product ID: ' . $filters['product_id'];
    }
    if (!empty($filters['product_search'])) {
        $filterSummaries[] = 'Search: ' . $filters['product_search'];
    }
    if (!empty($filters['brand'])) {
        $filterSummaries[] = 'Brand: ' . $filters['brand'];
    }
    if (!empty($filters['category'])) {
        $filterSummaries[] = 'Category: ' . $filters['category'];
    }
    if (!empty($filters['status_label'])) {
        $filterSummaries[] = 'Status: ' . $filters['status_label'];
    }
<<<<<<< HEAD

=======
>>>>>>> codex/fix-stockentry-http-error-500

    $totalRows = count($rows);
    $totalQty = 0.0;
    $totalValue = 0.0;
    $receiptTracker = [];

    foreach ($rows as $row) {
        $qty = isset($row['qty_received']) ? (float)$row['qty_received'] : 0.0;
        $totalQty += $qty;

        $unitCost = isset($row['unit_cost']) ? (float)$row['unit_cost'] : 0.0;
        $totalValue += $qty * $unitCost;

        if (!empty($row['receipt_code'])) {
            $receiptTracker[$row['receipt_code']] = true;
        }
    }

    $uniqueReceipts = count($receiptTracker);

    ob_start();
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title><?= htmlspecialchars($reportTitle) ?></title>
        <style>
            body {
                font-family: 'Helvetica', Arial, sans-serif;
                font-size: 12px;
                color: #1f2937;
                margin: 24px;
                line-height: 1.5;
            }
            .header {
                text-align: center;
                margin-bottom: 24px;
                border-bottom: 2px solid #0f172a;
                padding-bottom: 16px;
            }
            .header h1 {
                margin: 0;
                font-size: 24px;
                letter-spacing: 0.08em;
                text-transform: uppercase;
            }
            .header h2 {
                margin: 8px 0 6px;
                font-size: 18px;
                font-weight: 600;
            }
            .header p {
                margin: 0;
                color: #475569;
            }
            .section {
                margin-bottom: 24px;
            }
            .section h3 {
                margin: 0 0 12px;
                font-size: 15px;
                text-transform: uppercase;
                letter-spacing: 0.08em;
                color: #0f172a;
            }
            .filters ul {
                list-style: none;
                padding: 0;
                margin: 0;
            }
            .filters li {
                padding: 4px 0;
                border-bottom: 1px solid #e2e8f0;
            }
            .filters li:last-child {
                border-bottom: none;
            }
            .summary table {
                width: 100%;
                border-collapse: collapse;
            }
            .summary th,
            .summary td {
                padding: 10px 12px;
                border: 1px solid #d1d5db;
                font-size: 12px;
                text-align: left;
            }
            .summary th {
                background: #f8fafc;
                width: 45%;
                font-weight: 700;
            }
            .report-table {
                width: 100%;
                border-collapse: collapse;
                font-size: 11px;
            }
            .report-table thead th {
                background: #0f172a;
                color: #ffffff;
                text-transform: uppercase;
                letter-spacing: 0.06em;
                padding: 10px;
                text-align: left;
            }
            .report-table tbody td {
                padding: 9px 10px;
                border-bottom: 1px solid #e2e8f0;
            }
            .report-table tbody tr:nth-child(even) {
                background: #f8fafc;
            }
            .report-table tbody tr:last-child td {
                border-bottom: 0;
            }
            .report-table .status-cell {
                text-align: center;
            }
            .badge {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 999px;
                font-weight: 600;
                font-size: 10px;
                letter-spacing: 0.04em;
            }
            .badge-posted {
                background: #dcfce7;
                color: #166534;
            }
            .badge-draft {
                background: #e2e8f0;
                color: #1f2937;
            }
            .badge-with_discrepancy {
                background: #fef3c7;
                color: #92400e;
            }
            .empty-row td {
                text-align: center;
                padding: 18px 12px;
                color: #6b7280;
                font-style: italic;
            }
            .footer {
                margin-top: 36px;
                text-align: center;
                font-size: 10px;
                color: #64748b;
            }
        </style>
    </head>
    <body>
        <div class="header">
            <h1>DGZ Motorshop</h1>
            <h2>Stock-In Report</h2>
            <p>Generated on <?= htmlspecialchars($generatedOn) ?></p>
        </div>

        <div class="section filters">
            <h3>Filters</h3>
            <ul>
                <?php if (empty($filterSummaries)): ?>
                    <li>All stock-in entries</li>
                <?php else: ?>
                    <?php foreach ($filterSummaries as $line): ?>
                        <li><?= htmlspecialchars($line) ?></li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>
        </div>

        <div class="section summary">
            <h3>Summary</h3>
            <table>
                <tr>
                    <th>Total Rows</th>
                    <td><?= number_format($totalRows) ?></td>
                </tr>
                <tr>
                    <th>Distinct Receipts</th>
                    <td><?= number_format($uniqueReceipts) ?></td>
                </tr>
                <tr>
                    <th>Total Quantity Received</th>
                    <td><?= number_format($totalQty, 0) ?></td>
                </tr>
                <tr>
                    <th>Estimated Total Value</th>
                    <td>&#8369;<?= number_format($totalValue, 2) ?></td>
                </tr>
            </table>
        </div>

        <div class="section">
            <h3>Stock-In Details</h3>
            <table class="report-table">
                <thead>
                    <tr>
                        <?php foreach ($headers as $header): ?>
                            <th><?= htmlspecialchars($header) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr class="empty-row">
                            <td colspan="<?= count($headers) ?>">No stock-in records matched the selected filters.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $row): ?>
                            <?php
                                $qtyDisplay = $row['qty_received_display'] ?? number_format((float)($row['qty_received'] ?? 0), 0);
                                $unitCostDisplay = $row['unit_cost_display'] ?? number_format((float)($row['unit_cost'] ?? 0), 2);
                                $statusValue = $row['status'] ?? '';
                                $statusLabel = $row['status_label'] ?? formatStockReceiptStatus($statusValue);
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($row['date_display'] ?? '') ?></td>
                                <td><?= htmlspecialchars($row['receipt_code'] ?? '') ?></td>
                                <td><?= htmlspecialchars($row['supplier_name'] ?? '') ?></td>
                                <td><?= htmlspecialchars($row['document_number'] ?? '') ?></td>
                                <td><?= htmlspecialchars($row['product_name'] ?? 'Unknown Product') ?></td>
                                <td><?= htmlspecialchars($qtyDisplay) ?></td>
                                <td>&#8369;<?= htmlspecialchars($unitCostDisplay) ?></td>
                                <td><?= htmlspecialchars($row['receiver_name'] ?? 'Pending') ?></td>
                                <td class="status-cell"><span class="badge badge-<?= htmlspecialchars($statusValue) ?>"><?= htmlspecialchars($statusLabel) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="footer">Prepared via DGZ Inventory System</div>
    </body>
    </html>
    <?php
    $html = ob_get_clean();

    $options = new \Dompdf\Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', true);

    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $dompdf->stream($filenameBase . '.pdf', ['Attachment' => true]);
    exit;
}
<<<<<<< HEAD
    }

/**
 * Create a minimal multi-page PDF string from plain text lines.
 */
function buildSimplePdfDocument(array $lines): string
{
    if (empty($lines)) {
        $lines[] = 'No data available.';
    }

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

        $objects[$contentObject] = $contentObject . " 0 obj << /Length " . strlen($contentStream) . " >> stream\n"
            . $contentStream . "\nendstream\nendobj";

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
    $pdf .= "xref\n0 " . ($maxObject + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i <= $maxObject; $i++) {
        $offset = $offsets[$i] ?? 0;
        $pdf .= sprintf("%010d 00000 n ", $offset) . "\n";
    }
    $pdf .= "trailer << /Size " . ($maxObject + 1) . ' /Root ' . $catalogObject . " 0 R >>\n";
    $pdf .= "startxref\n" . $xrefPosition . "\n%%EOF";

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
        $content .= sprintf("1 0 0 1 %d %d Tm (%s) Tj\n", $leftMargin, $currentY, $escaped);
        $currentY -= $lineHeight;
    }
    $content .= "ET";
    return $content;
}

/**
 * Calculate the divider width for the PDF table layout.
 */
function buildPdfTableDivider(array $columnWidths, string $character = '-'): string
{
    $columns = count($columnWidths);
    $totalWidth = array_sum($columnWidths) + max(0, $columns - 1) * 3; // account for separators
    return str_repeat($character, $totalWidth);
}

/**
 * Format table rows with padding while safely trimming long values.
 */
function formatPdfTableRow(array $cells, array $columnWidths): string
{
    $formatted = [];
    foreach ($columnWidths as $index => $width) {
        $value = isset($cells[$index]) ? (string)$cells[$index] : '';
        $ellipsis = $width > 3 ? '…' : '';

        if (function_exists('mb_strimwidth')) {
            $trimmed = mb_strimwidth($value, 0, $width, $ellipsis, 'UTF-8');
            if (function_exists('mb_strwidth')) {
                $displayWidth = mb_strwidth($trimmed, 'UTF-8');
            } elseif (function_exists('mb_strlen')) {
                $displayWidth = mb_strlen($trimmed, 'UTF-8');
            } else {
                $displayWidth = strlen($trimmed);
            }
        } else {
            $maxLength = max(0, $width - strlen($ellipsis));
            $trimmed = strlen($value) > $width ? substr($value, 0, $maxLength) . $ellipsis : substr($value, 0, $width);
            $displayWidth = strlen($trimmed);
        }

        if ($displayWidth < $width) {
            $trimmed .= str_repeat(' ', $width - $displayWidth);
        }

        $formatted[] = $trimmed;
    }

    return implode(' | ', $formatted);
}
=======
>>>>>>> codex/fix-stockentry-http-error-500
