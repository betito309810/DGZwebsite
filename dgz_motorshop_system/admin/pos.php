<?php
require __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/email.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/includes/decline_reasons.php'; // Decline reason helpers

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Generate a PDF receipt from the order data
 */
function generateReceiptPDF(array $data): ?string {
    try {
        require_once __DIR__ . '/../vendor/autoload.php';

        // Configure Dompdf exactly like the test file
        $options = new Options();
        $options->set('defaultFont', 'Arial');
        $dompdf = new Dompdf($options);

        // Prepare logo image as base64 to embed in PDF
        $logoPath = __DIR__ . '/../assets/logo.png';    
        $logoBase64 = '';
        if (file_exists($logoPath)) {
            $logoData = file_get_contents($logoPath);
            $logoBase64 = 'data:image/png;base64,' . base64_encode($logoData);
        }

        // Create the HTML content with logo and updated layout
        $html = '<html><body style="font-family: Arial, sans-serif;">';

        // Header with logo and title
        $html .= '<div style="text-align: center; margin-bottom: 20px;">';
        if ($logoBase64 !== '') {
            $html .= '<img src="' . $logoBase64 . '" alt="DGZ Motorshop Logo" style="max-height: 80px; margin-bottom: 10px;">';
        }
        $html .= '<h1 style="color: #333; margin: 0;">DGZ Motorshop</h1>';
        $html .= '<p style="font-size: 18px; font-weight: bold; margin: 0;">Official Receipt</p>';
        $html .= '</div>';

        // Order Info
        $html .= '<div style="margin-bottom: 20px;">';
        $html .= '<p>Order #: ' . htmlspecialchars($data['order_id']) . '</p>';
        if (!empty($data['invoice_number'])) {
            $html .= '<p>Invoice #: ' . htmlspecialchars($data['invoice_number']) . '</p>';
        }
        $html .= '<p>Date: ' . htmlspecialchars($data['created_at']) . '</p>';
        $html .= '<p>Cashier: ' . htmlspecialchars($data['cashier']) . '</p>';
        $html .= '</div>';

        // Items
        $html .= '<table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">';
        $html .= '<tr style="background-color: #f0f0f0;">';
        $html .= '<th style="border: 1px solid #ddd; padding: 8px;">Item</th>';
        $html .= '<th style="border: 1px solid #ddd; padding: 8px;">Qty</th>';
        $html .= '<th style="border: 1px solid #ddd; padding: 8px;">Price</th>';
        $html .= '<th style="border: 1px solid #ddd; padding: 8px;">Total</th>';
        $html .= '</tr>';

        foreach ($data['items'] as $item) {
            $html .= '<tr>';
            $html .= '<td style="border: 1px solid #ddd; padding: 8px;">' . htmlspecialchars($item['name']) . '</td>';
            $html .= '<td style="border: 1px solid #ddd; padding: 8px; text-align: center;">' . htmlspecialchars($item['quantity']) . '</td>';
            $html .= '<td style="border: 1px solid #ddd; padding: 8px; text-align: right;">₱' . number_format($item['price'], 2) . '</td>';
            $html .= '<td style="border: 1px solid #ddd; padding: 8px; text-align: right;">₱' . number_format($item['total'], 2) . '</td>';
            $html .= '</tr>';
        }

        $html .= '</table>';

        // Totals section with vatable on the left and other totals on the right
        $html .= '<div style="display: flex; justify-content: space-between; width: 100%;">';

        // Left side: Vatable and VAT
        $html .= '<div style="width: 50%;">';
        $html .= '<p style="margin: 4px 0;"><strong>Vatable:</strong> ₱' . number_format($data['vatable'], 2) . '</p>';
        $html .= '<p style="margin: 4px 0;"><strong>VAT:</strong> ₱' . number_format($data['vat'], 2) . '</p>';
        $html .= '<p style="margin: 4px 0; font-weight: bold;">Total: ₱' . number_format($data['sales_total'], 2) . '</p>';
        $html .= '<p style="margin: 4px 0;">Amount Paid: ₱' . number_format($data['amount_paid'], 2) . '</p>';
        $html .= '<p style="margin: 4px 0;">Change: ₱' . number_format($data['change'], 2) . '</p>';
        $html .= '</div>';

        

        $html .= '</div>';

        $html .= '</body></html>';

        // Generate PDF
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        // Return the PDF content as a string
        $pdfContent = $dompdf->output();
        error_log('PDF Generated. Size: ' . strlen($pdfContent) . ' bytes');
        return $pdfContent;
    } catch (Exception $e) {
        error_log('Failed to generate PDF: ' . $e->getMessage());
        return '';
    }
}

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$pdo = db();
ensureOrderDeclineSchema($pdo); // Ensure orders table can store decline reasons
$declineReasons = fetchOrderDeclineReasons($pdo); // Preload decline reasons for UI/bootstrap
$declineReasonLookup = []; // Map reason id to label for quick lookups
foreach ($declineReasons as $declineReason) {
    $declineReasonLookup[(int) ($declineReason['id'] ?? 0)] = (string) ($declineReason['label'] ?? '');
}
$role = $_SESSION['role'] ?? '';
enforceStaffAccess();
$notificationManageLink = 'inventory.php';

require_once __DIR__ . '/includes/inventory_notifications.php';
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

$allowedStatuses = ['pending', 'payment_verification', 'approved', 'completed', 'disapproved'];

function ordersSupportsInvoiceNumbers(PDO $pdo): bool
{
    static $supports = null;

    if ($supports !== null) {
        return $supports;
    }

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM orders LIKE 'invoice_number'");
        $supports = $stmt !== false && $stmt->fetch() !== false;
    } catch (Throwable $e) {
        $supports = false;
        error_log('Unable to detect invoice_number column: ' . $e->getMessage());
    }

    return $supports;
}

/**
 * Generate a unique invoice number with an INV- prefix.
 */
function generateInvoiceNumber(PDO $pdo): string
{
    if (!ordersSupportsInvoiceNumbers($pdo)) {
        return '';
    }

    $prefix = 'INV-';

    $offset = strlen($prefix) + 1;
    $forUpdate = $pdo->inTransaction() ? ' FOR UPDATE' : '';

    try {
        $stmt = $pdo->prepare(
            "SELECT invoice_number\n"
            . "  FROM orders\n"
            . " WHERE invoice_number LIKE ?\n"
            . " ORDER BY CAST(SUBSTRING(invoice_number, {$offset}) AS UNSIGNED) DESC\n"
            . " LIMIT 1{$forUpdate}"
        );
        $stmt->execute([$prefix . '%']);
        $lastInvoice = $stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('Unable to fetch last invoice number: ' . $e->getMessage());
        $lastInvoice = null;
    }

    // ✅ fixed SQL
    try {
        $stmt = $pdo->query(
            "SELECT invoice_number\n"
            . "         FROM orders\n"
            . "         WHERE invoice_number IS NOT NULL\n"
            . "           AND invoice_number <> ''\n"
            . "         ORDER BY id DESC\n"
            . "         LIMIT 1"
        );
    } catch (Throwable $e) {
        error_log('Unable to fetch last invoice number: ' . $e->getMessage());
        $stmt = false;
    }

    $lastInvoice = $stmt ? $stmt->fetchColumn() : null;


    $nextNumber = 1;
    if (is_string($lastInvoice) && preg_match('/(\d+)/', $lastInvoice, $matches)) {
        $nextNumber = (int) $matches[1] + 1;
    }

    $checkStmt = null;
    try {
        $checkStmt = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE invoice_number = ?');
    } catch (Throwable $e) {
        error_log('Unable to prepare invoice uniqueness check: ' . $e->getMessage());
    }

    $counter = 0;
    do {
        $candidate = $prefix . str_pad((string) $nextNumber, 6, '0', STR_PAD_LEFT);
        $exists = false;

        if ($checkStmt) {
            try {
                $checkStmt->execute([$candidate]);
                $exists = ((int) $checkStmt->fetchColumn()) > 0;
            } catch (Throwable $e) {
                error_log('Unable to verify invoice uniqueness: ' . $e->getMessage());
                $exists = false;
            }
        }

        if (!$exists) {
            return $candidate;
        }

        $nextNumber++;
        $counter++;
    } while ($counter < 25);

    try {
        $fallback = $prefix . strtoupper(bin2hex(random_bytes(4)));
    } catch (Throwable $e) {
        error_log('Unable to generate random invoice number: ' . $e->getMessage());
        $fallback = $prefix . (string) time();
    }

    return $fallback;
}

/**
 * Prepare order items for email summaries and PDF receipts.
 */
function prepareOrderItemsData(PDO $pdo, int $orderId): array
{
    $itemsStmt = $pdo->prepare(
        'SELECT oi.product_id, oi.qty, oi.price, p.name AS product_name
         FROM order_items oi
         LEFT JOIN products p ON p.id = oi.product_id
         WHERE oi.order_id = ?'
    );
    $itemsStmt->execute([$orderId]);
    $rawItems = $itemsStmt->fetchAll() ?: [];

    $emailRows = '';
    $itemsTotal = 0.0;
    $itemsForReceipt = [];

    foreach ($rawItems as $row) {
        $name = trim((string) ($row['product_name'] ?? ''));
        if ($name === '') {
            $name = 'Item #' . (int) ($row['product_id'] ?? 0);
        }

        $qty = (int) ($row['qty'] ?? 0);
        $price = (float) ($row['price'] ?? 0);
        $line = $qty * $price;
        $itemsTotal += $line;

        $itemsForReceipt[] = [
            'name' => $name,
            'quantity' => $qty,
            'price' => $price,
            'total' => $line,
        ];

        $emailRows .= sprintf(
            '<tr>'
            . '<td style="padding:8px 12px; border-bottom:1px solid #f1f5f9;">%s</td>'
            . '<td style="padding:8px 12px; border-bottom:1px solid #f1f5f9; text-align:center;">%d</td>'
            . '<td style="padding:8px 12px; border-bottom:1px solid #f1f5f9; text-align:right;">₱%s</td>'
            . '<td style="padding:8px 12px; border-bottom:1px solid #f1f5f9; text-align:right;">₱%s</td>'
            . '</tr>',
            htmlspecialchars($name, ENT_QUOTES, 'UTF-8'),
            $qty,
            number_format($price, 2),
            number_format($line, 2)
        );
    }

    $summaryTable = '<h3 style="margin:20px 0 10px; font-size:16px; color:#111;">Order Summary</h3>'
        . '<table cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse; border:1px solid #e2e8f0; font-size:14px;">'
        . '<thead>'
        . '<tr style="background:#f8fafc;">'
        . '<th style="text-align:left; padding:8px 12px; border-bottom:1px solid #e2e8f0;">Item</th>'
        . '<th style="text-align:center; padding:8px 12px; border-bottom:1px solid #e2e8f0;">Qty</th>'
        . '<th style="text-align:right; padding:8px 12px; border-bottom:1px solid #e2e8f0;">Price</th>'
        . '<th style="text-align:right; padding:8px 12px; border-bottom:1px solid #e2e8f0;">Subtotal</th>'
        . '</tr>'
        . '</thead>'
        . '<tbody>' . ($emailRows !== '' ? $emailRows : '<tr><td colspan="4" style="padding:12px; text-align:center; color:#64748b;">No items found.</td></tr>') . '</tbody>'
        . '<tfoot>'
        . '<tr>'
        . '<td colspan="3" style="padding:10px 12px; text-align:right; font-weight:600; border-top:2px solid #e2e8f0;">Total:</td>'
        . '<td style="padding:10px 12px; text-align:right; font-weight:600; border-top:2px solid #e2e8f0;">₱' . number_format($itemsTotal, 2) . '</td>'
        . '</tr>'
        . '</tfoot>'
        . '</table>';

    return [
        'items' => $itemsForReceipt,
        'items_total' => $itemsTotal,
        'table_html' => $summaryTable,
    ];
}

// rewritten status handler (disapproval now handled through orderDisapprove.php)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_order_status'])) {
    $orderId = isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
    $newStatus = isset($_POST['new_status']) ? strtolower(trim((string) $_POST['new_status'])) : '';

    $_SESSION['pos_active_tab'] = 'online';

    $statusParam = '0';
    $statusError = '';
    $supportsInvoiceNumbers = ordersSupportsInvoiceNumbers($pdo);

    if ($orderId > 0 && $newStatus !== '') {
        if ($newStatus === 'disapproved') {
            $statusError = 'missing_reason';
        } elseif (in_array($newStatus, ['payment_verification', 'approved', 'completed'], true)) {
            $selectSql = $supportsInvoiceNumbers
                ? 'SELECT status, invoice_number FROM orders WHERE id = ?'
                : 'SELECT status FROM orders WHERE id = ?';
            $orderStmt = $pdo->prepare($selectSql);
            $orderStmt->execute([$orderId]);
            $currentOrder = $orderStmt->fetch();

            if ($currentOrder) {
                $currentStatus = strtolower((string) ($currentOrder['status'] ?? ''));
                if ($currentStatus === '') {
                    $currentStatus = 'pending';
                }

                $transitions = [
                    'pending' => ['payment_verification', 'approved', 'disapproved', 'completed'],
                    'payment_verification' => ['approved', 'disapproved'],
                    'approved' => ['completed'],
                    'completed' => [],
                    'disapproved' => [],
                ];

                if (!array_key_exists($currentStatus, $transitions)) {
                    $currentStatus = 'pending';
                }

                $allowedNext = $transitions[$currentStatus] ?? [];

                if ($newStatus === $currentStatus || in_array($newStatus, $allowedNext, true)) {
                    $fields = [];
                    $params = [];

                    if ($newStatus !== $currentStatus) {
                        $fields[] = 'status = ?';
                        $params[] = $newStatus;
                    }

                    $existingInvoice = $supportsInvoiceNumbers
                        ? (string) ($currentOrder['invoice_number'] ?? '')
                        : '';
                    $needsInvoice = $supportsInvoiceNumbers
                        && in_array($newStatus, ['approved', 'completed'], true)
                        && $existingInvoice === '';

                    if ($needsInvoice) {
                        $generatedInvoice = generateInvoiceNumber($pdo);
                        if ($generatedInvoice !== '') {
                            $fields[] = 'invoice_number = ?';
                            $params[] = $generatedInvoice;
                        }
                    }

                    $fields[] = 'decline_reason_id = ?';
                    $params[] = null;
                    $fields[] = 'decline_reason_note = ?';
                    $params[] = null;

                    $params[] = $orderId;
                    $updateSql = 'UPDATE orders SET ' . implode(', ', $fields) . ' WHERE id = ?';
                    $stmt = $pdo->prepare($updateSql);
                    $success = $stmt->execute($params);
                    $statusParam = $success ? '1' : '0';

                    if ($success && $newStatus === 'approved') {
                        $orderInfoStmt = $pdo->prepare(
                            'SELECT customer_name, email, phone, invoice_number, total, created_at FROM orders WHERE id = ? LIMIT 1'
                        );
                        $orderInfoStmt->execute([$orderId]);
                        $orderInfo = $orderInfoStmt->fetch();

                        $customerEmail = $orderInfo['email'] ?? '';
                        if ($customerEmail && filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
                            if (strtolower(trim($orderInfo['customer_name'] ?? '')) !== 'walk-in') {
                                $itemData = prepareOrderItemsData($pdo, $orderId);
                                $itemsTotal = (float) ($itemData['items_total'] ?? 0.0);
                                $summaryTableHtml = (string) ($itemData['table_html'] ?? '');

                                $customerName = trim((string) ($orderInfo['customer_name'] ?? 'Customer'));
                                $invoiceNumber = trim((string) ($orderInfo['invoice_number'] ?? ''));
                                $createdAt = (string) ($orderInfo['created_at'] ?? '');
                                $orderTotal = (float) ($orderInfo['total'] ?? $itemsTotal);

                                $prettyDate = $createdAt !== '' ? date('F j, Y g:i A', strtotime($createdAt)) : date('F j, Y g:i A');
                                $displayInvoice = $invoiceNumber !== '' ? $invoiceNumber : 'INV-' . str_pad((string) $orderId, 6, '0', STR_PAD_LEFT);

                                $subject = 'Order Approved - DGZ Motorshop Invoice ' . $displayInvoice;

                                $body = '<div style="font-family: Arial, sans-serif; font-size:14px; color:#333;">'
                                    . '<h2 style="color:#111; margin-bottom:8px;">Your Order is Approved</h2>'
                                    . '<p style="margin:0 0 12px;">Hi ' . htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8') . ',</p>'
                                    . '<p style="margin:0 0 12px;">Good news! Your order #' . (int) $orderId . ' has been approved and is now being processed.</p>'
                                    . '<p style="margin:0 0 12px;">Invoice Number: <strong>' . htmlspecialchars($displayInvoice, ENT_QUOTES, 'UTF-8') . '</strong><br>'
                                    . 'Order Date: ' . htmlspecialchars($prettyDate, ENT_QUOTES, 'UTF-8') . '</p>'
                                    . $summaryTableHtml
                                    . '<p style="margin:16px 0 0;">Thank you for shopping with <strong>DGZ Motorshop</strong>!</p>'
                                    . '</div>';

                                $receiptData = [
                                    'order_id' => $orderId,
                                    'invoice_number' => $invoiceNumber,
                                    'customer_name' => $customerName,
                                    'created_at' => $createdAt,
                                    'sales_total' => $orderTotal,
                                    'vatable' => $orderTotal / 1.12,
                                    'vat' => $orderTotal - ($orderTotal / 1.12),
                                    'amount_paid' => $orderTotal,
                                    'change' => 0.0,
                                    'cashier' => $_SESSION['username'] ?? 'Admin',
                                    'items' => array_map(static function (array $item): array {
                                        return [
                                            'name' => $item['name'] ?? 'Item',
                                            'quantity' => (int) ($item['quantity'] ?? 0),
                                            'price' => (float) ($item['price'] ?? 0),
                                            'total' => (float) ($item['total'] ?? 0),
                                        ];
                                    }, $itemData['items'] ?? [])
                                ];

                                $pdfContent = generateReceiptPDF($receiptData);
                                $pdfFilename = 'receipt_' . $orderId . '.pdf';

                                try { sendEmail($customerEmail, $subject, $body, $pdfContent, $pdfFilename); } catch (Throwable $e) { /* logged */ }
                            }
                        }
                    }

                    if ($success && $newStatus === 'payment_verification') {
                        $orderInfoStmt = $pdo->prepare(
                            'SELECT customer_name, email, phone, total, created_at FROM orders WHERE id = ? LIMIT 1'
                        );
                        $orderInfoStmt->execute([$orderId]);
                        $orderInfo = $orderInfoStmt->fetch();

                        $customerEmail = $orderInfo['email'] ?? '';
                        if ($customerEmail && filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
                            if (strtolower(trim($orderInfo['customer_name'] ?? '')) !== 'walk-in') {
                                $itemData = prepareOrderItemsData($pdo, $orderId);
                                $summaryTableHtml = (string) ($itemData['table_html'] ?? '');

                                $customerName = trim((string) ($orderInfo['customer_name'] ?? 'Customer'));
                                $createdAt = (string) ($orderInfo['created_at'] ?? '');
                                $prettyDate = $createdAt !== '' ? date('F j, Y g:i A', strtotime($createdAt)) : date('F j, Y g:i A');
                                $orderTotal = (float) ($orderInfo['total'] ?? 0);

                                $supportEmail = 'dgzstoninocapstone@gmail.com';
                                $supportPhone = '(123) 456-7890';

                                $subject = 'Payment Verification Needed - DGZ Motorshop Order #' . (int) $orderId;
                                $body = '<div style="font-family: Arial, sans-serif; font-size:14px; color:#333;">'
                                    . '<h2 style="color:#b45309; margin-bottom:8px;">Action Needed: Payment Verification</h2>'
                                    . '<p style="margin:0 0 12px;">Hi ' . htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8') . ',</p>'
                                    . '<p style="margin:0 0 12px;">We reviewed your order #' . (int) $orderId . ' placed on '
                                    . htmlspecialchars($prettyDate, ENT_QUOTES, 'UTF-8') . ', but we could not match any payment on our records.</p>'
                                    . '<p style="margin:0 0 12px;">Please reach us within <strong>5 working days</strong> via '
                                    . '<a href="mailto:' . htmlspecialchars($supportEmail, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($supportEmail, ENT_QUOTES, 'UTF-8') . '</a>'
                                    . ' or call us at ' . htmlspecialchars($supportPhone, ENT_QUOTES, 'UTF-8') . '.</p>'
                                    . $summaryTableHtml
                                    . '<p style="margin:0;">If we don\'t hear back within 5 working days, the order will be automatically cancelled.</p>'
                                    . '<p style="margin:12px 0 0;">Thank you,<br>DGZ Motorshop Team</p>'
                                    . '</div>';

                                try { sendEmail($customerEmail, $subject, $body); } catch (Throwable $e) { /* logged */ }
                            }
                        }
                    }

                    if ($success && $newStatus === 'approved' && $supportsInvoiceNumbers) {
                        $invoiceStmt = $pdo->prepare('SELECT invoice_number FROM orders WHERE id = ?');
                        $invoiceStmt->execute([$orderId]);
                        $updatedInvoice = (string) ($invoiceStmt->fetchColumn() ?: '');
                        if ($updatedInvoice !== '') {
                            $_SESSION['pos_invoice_number'] = $updatedInvoice;
                        }
                    }
                }
            }
        }
    }

    header('Location: pos.php?' . http_build_query([
        'tab' => 'online',
        'status_updated' => $statusParam,
        'status_error' => $statusError,
    ]));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pos_checkout'])) {
    $productIds = isset($_POST['product_id']) ? (array) $_POST['product_id'] : [];
    $quantities = isset($_POST['qty']) ? (array) $_POST['qty'] : [];
    $amountPaid = isset($_POST['amount_paid']) ? (float) $_POST['amount_paid'] : 0.0;

    if (empty($productIds)) {
        $_SESSION['pos_active_tab'] = 'walkin';
        echo "<script>alert('No item selected in POS!'); window.location='pos.php';</script>";
        exit;
    }

    $cartItems = [];
    $salesTotal = 0.0;

    foreach ($productIds as $index => $rawProductId) {
        $productId = (int) $rawProductId;
        if ($productId <= 0) {
            continue;
        }

        $qty = isset($quantities[$index]) ? (int) $quantities[$index] : 0;
        if ($qty <= 0) {
            $qty = 1;
        }

        $productStmt = $pdo->prepare('SELECT id, name, price, quantity FROM products WHERE id = ?');
        $productStmt->execute([$productId]);
        $product = $productStmt->fetch();

        if (!$product) {
            continue;
        }

        $availableQty = (int) $product['quantity'];
        if ($availableQty <= 0) {
            continue;
        }

        if ($qty > $availableQty) {
            $qty = $availableQty;
        }

        $lineTotal = (float) $product['price'] * $qty;
        $salesTotal += $lineTotal;

        $cartItems[] = [
            'id' => (int) $product['id'],
            'qty' => $qty,
            'price' => (float) $product['price'],
        ];
    }

    if (empty($cartItems)) {
        $_SESSION['pos_active_tab'] = 'walkin';
        echo "<script>alert('No item selected in POS!'); window.location='pos.php';</script>";
        exit;
    }

    if ($amountPaid < $salesTotal) {
        $_SESSION['pos_active_tab'] = 'walkin';
        echo "<script>alert('Insufficient payment amount!'); window.location='pos.php';</script>";
        exit;
    }

    $vatable = $salesTotal / 1.12;
    $vat = $salesTotal - $vatable;
    $change = $amountPaid - $salesTotal;

    try {
        $pdo->beginTransaction();

        $supportsInvoiceNumbers = ordersSupportsInvoiceNumbers($pdo);
        $invoiceNumber = $supportsInvoiceNumbers ? generateInvoiceNumber($pdo) : '';

        $orderColumns = [
            'customer_name',
            'address',
            'email',
            'phone',
            'total',
            'payment_method',
            'status',
            'vatable',
            'vat',
            'amount_paid',
            'change_amount',
        ];

        $orderValues = [
            'Walk-in',
            'N/A',
            'N/A',
            'N/A',
            $salesTotal,
            'Cash',
            'completed',
            $vatable,
            $vat,
            $amountPaid,
            $change,
        ];

        if ($supportsInvoiceNumbers) {
            $orderColumns[] = 'invoice_number';
            $orderValues[] = $invoiceNumber;
        }

       $placeholders = str_repeat('?, ', count($orderColumns) - 1) . '?';
$orderStmt = $pdo->prepare(
    'INSERT INTO orders (' . implode(', ', $orderColumns) . ') VALUES (' . $placeholders . ')'
);
$orderStmt->execute($orderValues);


        $orderId = (int) $pdo->lastInsertId();

        $itemInsertStmt = $pdo->prepare('INSERT INTO order_items (order_id, product_id, qty, price) VALUES (?,?,?,?)');
        $inventoryUpdateStmt = $pdo->prepare('UPDATE products SET quantity = quantity - ? WHERE id = ?');

        foreach ($cartItems as $item) {
            $itemInsertStmt->execute([$orderId, $item['id'], $item['qty'], $item['price']]);
            $inventoryUpdateStmt->execute([$item['qty'], $item['id']]);
        }

        $pdo->commit();

        error_log("Starting PDF generation for order: " . $orderId);

        // Generate receipt data for PDF
        $receiptData = [
            'order_id' => $orderId,
            'invoice_number' => $invoiceNumber,
            'customer_name' => 'Walk-in',
            'created_at' => date('Y-m-d H:i:s'),
            'sales_total' => $salesTotal,
            'vatable' => $vatable,
            'vat' => $vat,
            'amount_paid' => $amountPaid,
            'change' => $change,
            'cashier' => $_SESSION['username'] ?? 'Admin',
            'items' => []
        ];

        // Add items with proper error handling
        foreach ($cartItems as $item) {
            try {
                $stmt = $pdo->prepare('SELECT name FROM products WHERE id = ?');
                $stmt->execute([$item['id']]);
                $product = $stmt->fetch();
                $receiptData['items'][] = [
                    'name' => $product['name'] ?? 'Unknown Product',
                    'quantity' => $item['qty'],
                    'price' => $item['price'],
                    'total' => $item['price'] * $item['qty']
                ];
            } catch (Exception $e) {
                error_log("Error fetching product details: " . $e->getMessage());
            }
        }

        try {
            // First generate the PDF for printing (but don't send email for walk-in customers)
            $options = new Options();
            $options->set('defaultFont', 'Arial');
            $dompdf = new Dompdf($options);

            // Create HTML for receipt
            $html = '<h1>DGZ Motorshop Receipt</h1>';
            $html .= '<p>Order #: ' . $orderId . '</p>';
            $html .= '<p>Invoice #: ' . $invoiceNumber . '</p>';
            $html .= '<p>Date: ' . date('Y-m-d H:i:s') . '</p>';
            $html .= '<h2>Items</h2>';
            $html .= '<table border="1" cellpadding="5">';
            $html .= '<tr><th>Item</th><th>Quantity</th><th>Price</th><th>Total</th></tr>';
            
            foreach ($cartItems as $item) {
                $stmt = $pdo->prepare('SELECT name FROM products WHERE id = ?');
                $stmt->execute([$item['id']]);
                $product = $stmt->fetch();
                $total = $item['price'] * $item['qty'];
                
                $html .= '<tr>';
                $html .= '<td>' . htmlspecialchars($product['name']) . '</td>';
                $html .= '<td>' . $item['qty'] . '</td>';
                $html .= '<td>₱' . number_format($item['price'], 2) . '</td>';
                $html .= '<td>₱' . number_format($total, 2) . '</td>';
                $html .= '</tr>';
            }
            
            $html .= '</table>';
            $html .= '<p><strong>Total Amount:</strong> ₱' . number_format($salesTotal, 2) . '</p>';
            $html .= '<p><strong>Amount Paid:</strong> ₱' . number_format($amountPaid, 2) . '</p>';
            $html .= '<p><strong>Change:</strong> ₱' . number_format($change, 2) . '</p>';

            // Generate PDF
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();
            $pdfContent = $dompdf->output();
            
            error_log("PDF Generated for printing. Size: " . strlen($pdfContent) . " bytes");
            
            // Skip email sending for walk-in customers
            // Walk-in customers get their receipt printed directly at the POS
        } catch (Exception $e) {
            error_log("Error in PDF/email process for order {$orderId}: " . $e->getMessage());
            // Continue with the order process even if PDF/email fails
        }

        $params = [
            'ok' => 1,
            'order_id' => $orderId,
            'amount_paid' => number_format($amountPaid, 2, '.', ''),
            'change' => number_format($change, 2, '.', ''),
        ];

        if ($supportsInvoiceNumbers) {
            $params['invoice_number'] = $invoiceNumber;
        }

        header('Location: pos.php?' . http_build_query($params));
        exit;
    } catch (Throwable $exception) {
        $pdo->rollBack();
        $_SESSION['pos_active_tab'] = 'walkin';
        $message = addslashes($exception->getMessage());
        echo "<script>alert('Error processing transaction: {$message}'); window.location='pos.php';</script>";
        exit;
    }
}

$productQuery = $pdo->query('SELECT id, name, price, quantity, brand, category FROM products ORDER BY name');
$products = $productQuery->fetchAll();

// Fetch unique brands and categories for filter dropdowns
$brands = $pdo->query('SELECT DISTINCT brand FROM products WHERE brand IS NOT NULL AND brand != "" ORDER BY brand')->fetchAll(PDO::FETCH_COLUMN);
$categories = $pdo->query('SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND category != "" ORDER BY category')->fetchAll(PDO::FETCH_COLUMN);

$productCatalog = array_map(static function (array $product): array {
    return [
        'id' => (int) $product['id'],
        'name' => (string) $product['name'],
        'price' => (float) $product['price'],
        'quantity' => (int) $product['quantity'],
        'brand' => $product['brand'] ?? '',
        'category' => $product['category'] ?? '',
    ];
}, $products);

$allowedTabs = ['walkin', 'online'];
$activeTab = 'walkin';

if (!empty($_SESSION['pos_active_tab']) && in_array($_SESSION['pos_active_tab'], $allowedTabs, true)) {
    $activeTab = $_SESSION['pos_active_tab'];
    unset($_SESSION['pos_active_tab']);
} elseif (!empty($_GET['tab']) && in_array($_GET['tab'], $allowedTabs, true)) {
    $activeTab = $_GET['tab'];
}

// Pagination and filtering for online orders
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 15;
$offset = ($page - 1) * $perPage;

$statusFilter = '';
if (isset($_GET['status_filter'])) {
    $tmp = strtolower(trim((string) $_GET['status_filter']));
    if (in_array($tmp, ['pending', 'payment_verification', 'approved', 'completed', 'disapproved'], true)) {
        $statusFilter = $tmp;
    }
}

// Build the WHERE clause for online orders
$whereConditions = [
    "(payment_method IS NOT NULL AND payment_method <> '' AND LOWER(payment_method) = 'gcash')",
    "(payment_proof IS NOT NULL AND payment_proof <> '')",
    "status IN ('pending','payment_verification','approved','disapproved')"
];
$whereClause = "(" . implode(" OR ", $whereConditions) . ")";

// Add status filter if specified
$params = [];
if ($statusFilter) {
    $whereClause .= " AND status = ?";
    $params[] = $statusFilter;
}

// Get total count for pagination
$countParams = $params;
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE " . $whereClause);
$countStmt->execute($countParams);
$totalOrders = (int) $countStmt->fetchColumn();
$totalPages = (int) ceil($totalOrders / $perPage);

// Clamp current page to valid range
if ($totalPages > 0 && $page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

// Get orders for current page (inject safe integers for LIMIT/OFFSET)
$sqlOnlineOrders = "SELECT * FROM orders
     WHERE " . $whereClause . "
     ORDER BY created_at DESC
     LIMIT " . (int) $perPage . " OFFSET " . (int) $offset;
$onlineOrdersStmt = $pdo->prepare($sqlOnlineOrders);
$onlineOrdersStmt->execute($params);
$onlineOrders = $onlineOrdersStmt->fetchAll();

foreach ($onlineOrders as &$order) {
    $details = parsePaymentProofValue($order['payment_proof'] ?? null, $order['reference_no'] ?? null);
    $order['reference_number'] = $details['reference'];
    $order['proof_image'] = $details['image'];
    $order['status'] = strtolower((string) ($order['status'] ?? 'pending'));
    $reasonId = (int) ($order['decline_reason_id'] ?? 0);
    $order['decline_reason_label'] = $reasonId > 0 && isset($declineReasonLookup[$reasonId])
        ? $declineReasonLookup[$reasonId]
        : '';
    $order['decline_reason_note'] = (string) ($order['decline_reason_note'] ?? '');
}
unset($order);

$statusOptions = [
    'payment_verification' => 'Payment Verification',
    'approved' => 'Approved',
    'disapproved' => 'Disapproved',
    'completed' => 'Completed',
];

$productCatalogJson = json_encode($productCatalog, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
if ($productCatalogJson === false) {
    $productCatalogJson = '[]';
}

$brandsJson = json_encode($brands, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
if ($brandsJson === false) {
    $brandsJson = '[]';
}

$categoriesJson = json_encode($categories, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
if ($categoriesJson === false) {
    $categoriesJson = '[]';
}

$declineReasonsJson = json_encode($declineReasons, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
if ($declineReasonsJson === false) {
    $declineReasonsJson = '[]';
}

$receiptData = null;
if (isset($_GET['ok'], $_GET['order_id']) && $_GET['ok'] === '1') {
    $requestedOrderId = (int) $_GET['order_id'];

    if ($requestedOrderId > 0) {
        $orderStmt = $pdo->prepare(
            'SELECT id, customer_name, total, vatable, vat, amount_paid, change_amount, created_at, invoice_number
             FROM orders
             WHERE id = ? LIMIT 1'
        );
        $orderStmt->execute([$requestedOrderId]);
        $orderRow = $orderStmt->fetch();

        if ($orderRow) {
            $itemsStmt = $pdo->prepare(
                'SELECT oi.product_id, oi.qty, oi.price, p.name
                 FROM order_items oi
                 LEFT JOIN products p ON p.id = oi.product_id
                 WHERE oi.order_id = ?'
            );
            $itemsStmt->execute([$requestedOrderId]);
            $itemRows = $itemsStmt->fetchAll();

            $items = array_map(static function (array $item): array {
                $name = trim((string) ($item['name'] ?? ''));
                if ($name === '') {
                    $name = 'Item #' . (int) ($item['product_id'] ?? 0);
                }

                $price = (float) ($item['price'] ?? 0);
                $qty = (int) ($item['qty'] ?? 0);

                return [
                    'name' => $name,
                    'price' => $price,
                    'qty' => $qty,
                    'total' => $price * $qty,
                ];
            }, $itemRows ?: []);

            $receiptData = [
                'order_id' => (int) $orderRow['id'],
                'customer_name' => (string) ($orderRow['customer_name'] ?? 'Customer'),
                'created_at' => (string) ($orderRow['created_at'] ?? ''),
                'invoice_number' => (string) ($orderRow['invoice_number'] ?? ''),
                'sales_total' => (float) ($orderRow['total'] ?? 0),
                'vatable' => (float) ($orderRow['vatable'] ?? 0),
                'vat' => (float) ($orderRow['vat'] ?? 0),
                'amount_paid' => (float) ($orderRow['amount_paid'] ?? 0),
                'change' => (float) ($orderRow['change_amount'] ?? 0),
                'discount' => 0.0,
                'cashier' => (string) ($_SESSION['username'] ?? 'Admin'),
                'items' => $items,
            ];
        }
    }
}

$receiptDataJson = $receiptData ? json_encode($receiptData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) : 'null';
if ($receiptDataJson === false) {
    $receiptDataJson = 'null';
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <title>POS - DGZ</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/pos/pos.css">
</head>

<body>
    <?php
        $activePage = 'pos.php';
        include __DIR__ . '/includes/sidebar.php';
    ?>

    <main class="main-content">
        <header class="header">
            <div class="header-left">
                <button class="mobile-toggle" type="button">
                    <i class="fas fa-bars"></i>
                </button>
                <h2>POS</h2>
            </div>
            <div class="header-right">
                <?php include __DIR__ . '/partials/notification_menu.php'; ?>
                <div class="user-menu">
                    <div class="user-avatar">
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

        <div class="pos-tabs">
            <button type="button" class="pos-tab-button<?= $activeTab === 'walkin' ? ' active' : '' ?>" data-tab="walkin">
                <i class="fas fa-store"></i>
                POS Checkout
            </button>
            <button type="button" class="pos-tab-button<?= $activeTab === 'online' ? ' active' : '' ?>" data-tab="online">
                <i class="fas fa-shopping-bag"></i>
                Online Orders
            </button>
        </div>

        <div id="walkinTab" class="tab-panel<?= $activeTab === 'walkin' ? ' active' : '' ?>">
            <div class="walkin-actions">
                <button type="button" id="openProductModal" class="primary-button">
                    <i class="fas fa-search"></i> Search Product
                </button>
                 <div class="top-total-simple">
                    <span id="topTotalAmountSimple">₱0.00</span>
                </div>
            </div>

            <form method="post" id="posForm">
                <div class="pos-table-container">
                    <table id="posTable">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Price</th>
                                <th>Available</th>
                                <th>Qty</th>
                            </tr>
                        </thead>
                        <tbody id="posTableBody"></tbody>
                    </table>
                    <div class="pos-empty-state" id="posEmptyState">
                        <i class="fas fa-shopping-cart"></i>
                        <p>No items in cart. Click "Search Product" to add items.</p>
                    </div>
                </div>

                <div id="totalsPanel" class="totals-panel">
                    <div class="totals-item">
                        <label>Sales Total</label>
                        <div id="salesTotalAmount" class="value">₱0.00</div>
                    </div>
                    <div class="totals-item">
                        <label>Discount</label>
                        <div id="discountAmount" class="value">₱0.00</div>
                    </div>
                    <div class="totals-item">
                        <label>Vatable</label>
                        <div id="vatableAmount" class="value">₱0.00</div>
                    </div>
                    <div class="totals-item">
                        <label>VAT (12%)</label>
                        <div id="vatAmount" class="value">₱0.00</div>
                    </div>
                    <div class="totals-item">
                        <label for="amountReceived">Amount Received</label>
                        <input type="number" id="amountReceived" name="amount_paid" min="0" step="0.01" placeholder="0.00">
                    </div>
                    <div class="totals-item">
                        <label>Change</label>
                        <div id="changeAmount" class="value">₱0.00</div>
                    </div>
                </div>

                <div class="pos-actions">
                    <button type="button" id="clearPosTable" class="danger-button">Clear</button>
                    <button name="pos_checkout" id="settlePaymentButton" type="submit" class="success-button">Settle Payment (Complete)</button>
                </div>
            </form>
        </div>

        <div id="onlineTab" class="tab-panel<?= $activeTab === 'online' ? ' active' : '' ?>">
            <?php if (isset($_GET['disapproved_success']) && $_GET['disapproved_success'] === '1'): ?>
                <div class="status-alert success">
                    <i class="fas fa-check-circle"></i>
                    Order disapproved and customer notified.
                </div>
            <?php elseif (isset($_GET['status_updated'])): ?>
                <?php
                    $success = $_GET['status_updated'] === '1';
                    $statusErrorCode = $_GET['status_error'] ?? '';
                    $statusMessage = $success ? 'Order status updated.' : 'Unable to update order status.';
                    if (!$success && $statusErrorCode === 'missing_reason') {
                        $statusMessage = 'Please use the Disapprove button to choose a reason before updating the status.';
                    }
                ?>
                <div class="status-alert <?= $success ? 'success' : 'error' ?>">
                    <i class="fas <?= $success ? 'fa-check-circle' : 'fa-exclamation-circle' ?>"></i>
                    <?= htmlspecialchars($statusMessage, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <div class="online-orders-filters">
                <div class="filter-group">
                    <label for="statusFilter">Filter by Status:</label>
                    <select id="statusFilter" name="status_filter">
                        <option value="">All Orders</option>
                        <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="payment_verification" <?= $statusFilter === 'payment_verification' ? 'selected' : '' ?>>Payment Verification</option>
                        <option value="approved" <?= $statusFilter === 'approved' ? 'selected' : '' ?>>Approved</option>
                        <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>Completed</option>
                        <option value="disapproved" <?= $statusFilter === 'disapproved' ? 'selected' : '' ?>>Disapproved</option>
                    </select>
                </div>
              
            </div>

            <div class="online-orders-container">
                <table class="online-orders-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Customer</th>
                            <th>Contact</th>
                            <th>Total</th>
                            <th>Reference</th>
                            <th>Proof</th>
                            <th>Status</th>
                            <th>Placed</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($onlineOrders)): ?>
                            <tr>
                                <td colspan="8" class="empty-cell">
                                    <i class="fas fa-inbox"></i>
                                    No online orders yet.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($onlineOrders as $order): ?>
                                <?php
                                $imagePath = normalizePaymentProofPath($order['proof_image'] ?? '');
                                $statusValue = $order['status'] !== '' ? $order['status'] : 'pending';
                                $createdAt = !empty($order['created_at']) ? date('M d, Y g:i A', strtotime($order['created_at'])) : 'N/A';
                                $statusTransitions = [
                                    'pending' => ['payment_verification', 'approved', 'disapproved', 'completed'],
                                    'payment_verification' => ['approved', 'disapproved'],
                                    'approved' => ['completed'],
                                    'completed' => [],
                                    'disapproved' => [],
                                ];
                                $availableStatusChanges = $statusTransitions[$statusValue] ?? [];
                                ?>
                                <tr class="online-order-row" data-order-id="<?= (int) $order['id'] ?>"
                                    data-decline-reason-id="<?= (int) ($order['decline_reason_id'] ?? 0) ?>"
                                    data-decline-reason-label="<?= htmlspecialchars($order['decline_reason_label'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                    data-decline-reason-note="<?= htmlspecialchars($order['decline_reason_note'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                    <td>#<?= (int) $order['id'] ?></td>
                                    <td><?= htmlspecialchars($order['customer_name'] ?? 'Customer', ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?php 
                                        $display = '';
                                        if (!empty($order['email'])) { $display = $order['email']; }
                                        elseif (!empty($order['phone'])) { $display = $order['phone']; }
                                        echo htmlspecialchars($display, ENT_QUOTES, 'UTF-8');
                                    ?></td>
                                    <td>₱<?= number_format((float) $order['total'], 2) ?></td>
                                    <td>
                                        <?php if (!empty($order['reference_number'])): ?>
                                            <span class="reference-badge"><?= htmlspecialchars($order['reference_number'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php else: ?>
                                            <span class="muted">Not provided</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button type="button" class="view-proof-btn"
                                            data-image="<?= htmlspecialchars($imagePath, ENT_QUOTES, 'UTF-8') ?>"
                                            data-reference="<?= htmlspecialchars($order['reference_number'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                            data-customer="<?= htmlspecialchars($order['customer_name'] ?? 'Customer', ENT_QUOTES, 'UTF-8') ?>">
                                            <i class="fas fa-receipt"></i> View
                                        </button>
                                    </td>
                                    <td>
                                        <?php $statusLabel = $statusOptions[$statusValue] ?? ucfirst($statusValue); ?>
                                        <span class="status-badge status-<?= htmlspecialchars($statusValue, ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                        <form method="post" class="status-form">
                                            <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                                            <input type="hidden" name="update_order_status" value="1">
                                            <input type="hidden" name="decline_reason_id" value="">
                                            <input type="hidden" name="decline_reason_note" value="">
                                            <select name="new_status" <?= empty($availableStatusChanges) ? 'disabled' : '' ?>>
                                                <?php if (empty($availableStatusChanges)): ?>
                                                    <?php $currentLabel = $statusOptions[$statusValue] ?? ucfirst($statusValue); ?>
                                                    <option value=""><?= htmlspecialchars($currentLabel, ENT_QUOTES, 'UTF-8') ?></option>
                                                <?php else: ?>
                                                    <?php foreach ($availableStatusChanges as $value): ?>
                                                        <option value="<?= $value ?>"><?= $statusOptions[$value] ?></option>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </select>
                                            <button type="submit" class="status-save" <?= empty($availableStatusChanges) ? 'disabled' : '' ?>>Update</button>
                                        </form>
                                        <?php if ($statusValue === 'disapproved' && !empty($order['decline_reason_label'])): ?>
                                            <div class="decline-reason-display">
                                                Reason: <?= htmlspecialchars($order['decline_reason_label'], ENT_QUOTES, 'UTF-8') ?>
                                                <?php if (!empty($order['decline_reason_note'])): ?>
                                                    <br><span class="decline-reason-note">Details: <?= nl2br(htmlspecialchars($order['decline_reason_note'], ENT_QUOTES, 'UTF-8')) ?></span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($createdAt, ENT_QUOTES, 'UTF-8') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPages > 1): ?>
            <div class="pagination-container">
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?tab=online&page=<?= $page - 1 ?><?= $statusFilter ? '&status_filter=' . urlencode($statusFilter) : '' ?>" class="pagination-btn">
                            <i class="fas fa-chevron-left"></i> Previous
                        </a>
                    <?php endif; ?>

                    <?php
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);
                    
                    if ($startPage > 1): ?>
                        <a href="?tab=online&page=1<?= $statusFilter ? '&status_filter=' . urlencode($statusFilter) : '' ?>" class="pagination-btn">1</a>
                        <?php if ($startPage > 2): ?>
                            <span class="pagination-ellipsis">...</span>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                        <a href="?tab=online&page=<?= $i ?><?= $statusFilter ? '&status_filter=' . urlencode($statusFilter) : '' ?>" 
                           class="pagination-btn <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
                    <?php endfor; ?>

                    <?php if ($endPage < $totalPages): ?>
                        <?php if ($endPage < $totalPages - 1): ?>
                            <span class="pagination-ellipsis">...</span>
                        <?php endif; ?>
                        <a href="?tab=online&page=<?= $totalPages ?><?= $statusFilter ? '&status_filter=' . urlencode($statusFilter) : '' ?>" class="pagination-btn"><?= $totalPages ?></a>
                    <?php endif; ?>

                    <?php if ($page < $totalPages): ?>
                        <a href="?tab=online&page=<?= $page + 1 ?><?= $statusFilter ? '&status_filter=' . urlencode($statusFilter) : '' ?>" class="pagination-btn">
                            Next <i class="fas fa-chevron-right"></i>
                        </a>
                          <div class="orders-info">
                    <span>Showing <?= count($onlineOrders) ?> of <?= $totalOrders ?> orders</span>
                </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php endif; ?>
        </div>
    </main>

    <!-- Online order details modal (POS online orders detail feature) -->
    <div id="onlineOrderModal" class="modal-overlay" style="display:none;">
        <div class="modal-content transaction-modal">
            <div class="modal-header">
                <h3>Transaction Details</h3>
                <button type="button" class="modal-close" id="closeOnlineOrderModal" aria-label="Close order details">&times;</button>
            </div>
            <div class="modal-body">
                <div class="transaction-info">
                    <h4>Order Information</h4>
                    <div class="info-grid">
                        <div class="info-item">
                            <label>Customer:</label>
                            <span id="onlineOrderCustomer">N/A</span>
                        </div>
                        <div class="info-item">
                            <label>Invoice #:</label>
                            <span id="onlineOrderInvoice">N/A</span>
                        </div>
                        <div class="info-item">
                            <label>Date:</label>
                            <span id="onlineOrderDate">N/A</span>
                        </div>
                        <div class="info-item">
                            <label>Status:</label>
                            <span id="onlineOrderStatus">N/A</span>
                        </div>
                        <div class="info-item">
                            <label>Payment Method:</label>
                            <span id="onlineOrderPayment">N/A</span>
                        </div>
                        <div class="info-item">
                            <label>Email:</label>
                            <span id="onlineOrderEmail"></span>
                        </div>
                        <div class="info-item">
                            <label>Phone:</label>
                            <span id="onlineOrderPhone"></span>
                        </div>
                        <div class="info-item" id="onlineOrderReferenceWrapper" style="display:none;">
                            <label>Reference:</label>
                            <span id="onlineOrderReference"></span>
                        </div>
                    </div>
                </div>
                <div class="order-items">
                    <h4>Order Items</h4>
                    <div class="table-responsive">
                        <table class="items-table">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Quantity</th>
                                    <th>Price</th>
                                    <th>Subtotal</th>
                                </tr>
                            </thead>
                            <tbody id="onlineOrderItemsBody"></tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="3" style="text-align: right;"><strong>Total:</strong></td>
                                    <td id="onlineOrderTotal">₱0.00</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    


</script>
    <!-- Profile modal -->
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
<!-- user menu -->
     <script src="../assets/js/dashboard/userMenu.js"></script>
    

     
    <div id="productModal" class="modal-overlay" style="display:none;">
        <div class="modal-content large-modal">
            <button id="closeProductModal" type="button" class="modal-close">&times;</button>
            <h3>Search Product</h3>
            <input type="text" id="productSearchInput" placeholder="Type product name...">
            <div class="filter-row" style="display: flex; gap: 10px; margin-bottom: 10px;">
                <select id="categoryFilter" style="flex: 1; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                    <option value="">Select Category</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="brandFilter" style="flex: 1; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                    <option value="">Select Brand</option>
                    <?php foreach ($brands as $brand): ?>
                        <option value="<?= htmlspecialchars($brand, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($brand, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="modal-table-wrapper" style="flex: 1 1 auto; overflow-y: auto; max-height: 450px;">
                <table id="productSearchTable" style="width: 100%;">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Price</th>
                            <th>Stock</th>
                            <th>Select</th>
                        </tr>
                    </thead>
                    <tbody id="productSearchTableBody"></tbody>
                </table>
            </div>
            <button id="addSelectedProducts" type="button" class="primary-button full-width">Add</button>
        </div>
    </div>

    <div id="receiptModal" class="modal-overlay" style="display:none;">
        <div class="modal-content receipt-modal">
            <button id="closeReceiptModal" type="button" class="modal-close">&times;</button>
            <div id="receiptContent" class="receipt-content">
                <div class="receipt-header">
                    <h2>DGZ Motorshop</h2>
                    <p>123 Main Street</p>
                    <p>Phone: (123) 456-7890</p>
                    <p>Receipt #: <span id="receiptNumber"></span></p>
                    <p>Date: <span id="receiptDate"></span></p>
                    <p>Cashier: <span id="receiptCashier"></span></p>
                </div>
                <div class="receipt-body">
                    <table id="receiptItems">
                        <thead>
                            <tr>
                                <th style="text-align:left;">Item</th>
                                <th style="text-align:right;">Qty</th>
                                <th style="text-align:right;">Price</th>
                                <th style="text-align:right;">Total</th>
                            </tr>
                        </thead>
                        <tbody id="receiptItemsBody"></tbody>
                    </table>
                </div>
                <div class="receipt-totals">
                    <div><span>Sales Total:</span> <span id="receiptSalesTotal">₱0.00</span></div>
                    <div><span>Discount:</span> <span id="receiptDiscount">₱0.00</span></div>
                    <div><span>Vatable:</span> <span id="receiptVatable">₱0.00</span></div>
                    <div><span>VAT (12%):</span> <span id="receiptVat">₱0.00</span></div>
                    <div><span>Amount Paid:</span> <span id="receiptAmountPaid">₱0.00</span></div>
                    <div><span>Change:</span> <span id="receiptChange">₱0.00</span></div>
                </div>
                <div class="receipt-footer">
                    <p>Thank you for shopping!</p>
                    <p>Please come again.</p>
                </div>
            </div>
            <div class="receipt-actions">
                <button type="button" id="printReceiptButton" class="primary-button">
                    <i class="fas fa-print"></i> Print Receipt
                </button>
            </div>
        </div>
    </div>

    <div id="proofModal" class="proof-modal" aria-hidden="true">
        <div class="proof-modal-content">
            <button type="button" class="proof-close" id="closeProofModal">&times;</button>
            <h3 class="proof-title">Payment Proof</h3>
            <p class="proof-reference">Reference: <span id="proofReferenceValue">Not provided</span></p>
            <p class="proof-customer">Customer: <span id="proofCustomerName">N/A</span></p>
            <div class="proof-image-wrapper">
                <img id="proofImage" src="" alt="Payment proof preview" />
                <div id="proofNoImage" class="proof-empty">No proof uploaded.</div>
            </div>
        </div>
    </div>

    <!-- Disapprove order modal: enforce reason selection before submitting disapproval -->
    <div id="declineOrderModal" class="modal-overlay" style="display:none;">
        <div class="modal-content decline-modal">
            <button type="button" class="modal-close" id="closeDeclineOrderModal">&times;</button>
            <h3>Disapprove Order</h3>
            <p class="decline-modal-intro">Select a reason to disapprove this order. The customer will receive the reason in their email.</p>
            <label for="declineReasonSelect" class="decline-label">Disapproval Reason</label>
            <select id="declineReasonSelect"></select>
            <button type="button" id="openManageDeclineReasons" class="manage-reasons-button">Manage reasons</button>
            <label for="declineReasonNote" class="decline-label">Additional Details (optional)</label>
            <textarea id="declineReasonNote" rows="3" placeholder="Add context that will appear in the email."></textarea>
            <div id="declineModalError" class="decline-modal-error" aria-live="polite"></div>
            <div class="decline-modal-actions">
                <button type="button" id="cancelDeclineOrder" class="secondary-button">Cancel</button>
                <button type="button" id="confirmDeclineOrder" class="danger-button">Disapprove Order</button>
            </div>
        </div>
    </div>

    <!-- Manage disapproval reasons modal: maintain reusable catalogue -->
    <div id="manageDeclineReasonsModal" class="modal-overlay" style="display:none;">
        <div class="modal-content decline-manage-modal">
            <button type="button" class="modal-close" id="closeManageDeclineReasons">&times;</button>
            <h3>Manage Disapproval Reasons</h3>
            <p class="decline-modal-intro">Update reusable disapproval reasons so your team stays consistent.</p>
            <div id="declineReasonsList" class="decline-reasons-list"></div>
            <form id="addDeclineReasonForm" class="decline-reason-add">
                <label for="newDeclineReasonInput" class="decline-label">Add a new disapproval reason</label>
                <div class="decline-reason-add-row">
                    <input type="text" id="newDeclineReasonInput" maxlength="255" placeholder="Enter reason label" required>
                    <button type="submit" class="primary-button">Add</button>
                </div>
            </form>
            <div id="manageDeclineError" class="decline-modal-error" aria-live="polite"></div>
        </div>
    </div>
    <!-- POS data bootstrap for external script -->
    <script>
        window.dgzPosData = {
            productCatalog: <?= $productCatalogJson ?>,
            initialActiveTab: <?= json_encode($activeTab) ?>,
            checkoutReceipt: <?= $receiptDataJson ?>,
            declineReasons: <?= $declineReasonsJson ?>
        };
    </script>
    <!--POS main script-->
    <script src="../assets/js/pos/posMain.js"></script>
    <script src="../assets/js/pos/orderDecline.js"></script>
    <script src="../assets/js/notifications.js"></script>
</body>
</html>
