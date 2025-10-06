<?php
declare(strict_types=1);

require __DIR__ . '/../config/config.php';
require __DIR__ . '/../includes/email.php';
require __DIR__ . '/includes/decline_reasons.php';

header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit;
}

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Unsupported method.']);
    exit;
}

$input = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid payload.']);
    exit;
}

$orderId = isset($input['orderId']) ? (int) $input['orderId'] : 0;
$reasonId = isset($input['reasonId']) ? (int) $input['reasonId'] : 0;
$reasonLabel = isset($input['reasonLabel']) ? trim((string) $input['reasonLabel']) : '';
$note = isset($input['note']) ? trim((string) $input['note']) : '';

if ($orderId <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Order id is required.']);
    exit;
}

$pdo = db();
ensureOrderDeclineSchema($pdo);

try {
    $pdo->beginTransaction();

    $orderStmt = $pdo->prepare(
        'SELECT id, customer_name, email, status, total, created_at
         FROM orders
         WHERE id = ?
         LIMIT 1'
    );
    $orderStmt->execute([$orderId]);
    $order = $orderStmt->fetch();

    if (!$order) {
        throw new RuntimeException('Order not found.');
    }

    $currentStatus = strtolower((string) ($order['status'] ?? ''));
    if ($currentStatus === 'disapproved') {
        throw new RuntimeException('Order already disapproved.');
    }

    $allowedTransitions = [
        'pending' => true,
        'payment_verification' => true,
        'approved' => true,
    ];
    if (!isset($allowedTransitions[$currentStatus])) {
        throw new RuntimeException('Status update not allowed.');
    }

    $declineReason = null;
    if ($reasonId > 0) {
        $declineReason = findOrderDeclineReason($pdo, $reasonId);
    }

    if ($declineReason === null) {
        if ($reasonLabel === '') {
            throw new RuntimeException('Select a disapproval reason.');
        }
        $existing = findOrderDeclineReasonByLabel($pdo, $reasonLabel);
        if ($existing) {
            $declineReason = $existing;
            $reasonId = (int) $existing['id'];
        } else {
            $created = createOrderDeclineReason($pdo, $reasonLabel);
            if ($created === null) {
                throw new RuntimeException('Unable to create disapproval reason.');
            }
            $declineReason = $created;
            $reasonId = (int) $created['id'];
        }
    }

    $updateStmt = $pdo->prepare(
        'UPDATE orders
             SET status = "disapproved",
                 decline_reason_id = ?,
                 decline_reason_note = ?
           WHERE id = ?'
    );
    $updateStmt->execute([$reasonId, $note !== '' ? $note : null, $orderId]);

    $pdo->commit();
} catch (RuntimeException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to disapprove order.']);
    error_log('Disapprove order failed: ' . $e->getMessage());
    exit;
}

// send email after commit
try {
    $email = (string) ($order['email'] ?? '');
    $customerName = trim((string) ($order['customer_name'] ?? 'Customer'));
    $prettyDate = $order['created_at'] ? date('F j, Y g:i A', strtotime((string) $order['created_at'])) : date('F j, Y g:i A');

    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && strtolower($customerName) !== 'walk-in') {
        $itemsStmt = $pdo->prepare(
            'SELECT oi.qty, oi.price, p.name
               FROM order_items oi
          LEFT JOIN products p ON p.id = oi.product_id
              WHERE oi.order_id = ?'
        );
        $itemsStmt->execute([$orderId]);
        $items = $itemsStmt->fetchAll() ?: [];

        $rowsHtml = '';
        $total = 0.0;
        foreach ($items as $item) {
            $qty = (int) ($item['qty'] ?? 0);
            $price = (float) ($item['price'] ?? 0);
            $line = $qty * $price;
            $total += $line;
            $label = trim((string) ($item['name'] ?? 'Item'));
            if ($label === '') {
                $label = 'Item';
            }
            $rowsHtml .= sprintf(
                '<tr><td style="padding:6px 8px; border-bottom:1px solid #edf2f7;">%s</td><td style="padding:6px 8px; text-align:center; border-bottom:1px solid #edf2f7;">%d</td><td style="padding:6px 8px; text-align:right; border-bottom:1px solid #edf2f7;">₱%s</td><td style="padding:6px 8px; text-align:right; border-bottom:1px solid #edf2f7;">₱%s</td></tr>',
                htmlspecialchars($label, ENT_QUOTES, 'UTF-8'),
                $qty,
                number_format($price, 2),
                number_format($line, 2)
            );
        }
        if ($rowsHtml === '') {
            $rowsHtml = '<tr><td colspan="4" style="padding:8px; text-align:center; color:#718096;">No items available.</td></tr>';
        }

        $reasonText = htmlspecialchars((string) ($declineReason['label'] ?? $reasonLabel), ENT_QUOTES, 'UTF-8');
        $noteBlock = $note !== ''
            ? '<p style="margin:0 0 12px;">Additional details:<br>' . nl2br(htmlspecialchars($note, ENT_QUOTES, 'UTF-8')) . '</p>'
            : '';

        $body = '<div style="font-family: Arial, sans-serif; color:#1a202c; font-size:14px;">'
            . '<h2 style="color:#c53030; margin-bottom:12px;">Order Disapproved</h2>'
            . '<p style="margin:0 0 12px;">Hi ' . htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8') . ',</p>'
            . '<p style="margin:0 0 12px;">We reviewed your order #' . (int) $orderId . ' placed on '
            . htmlspecialchars($prettyDate, ENT_QUOTES, 'UTF-8') . '.</p>'
            . '<p style="margin:0 0 12px;">Reason: <strong>' . $reasonText . '</strong></p>'
            . $noteBlock
            . '<table style="width:100%; border-collapse:collapse; margin:16px 0;">
                    <thead>
                        <tr style="background:#f7fafc;">
                            <th style="text-align:left; padding:8px; border-bottom:1px solid #cbd5e0;">Item</th>
                            <th style="text-align:center; padding:8px; border-bottom:1px solid #cbd5e0;">Qty</th>
                            <th style="text-align:right; padding:8px; border-bottom:1px solid #cbd5e0;">Price</th>
                            <th style="text-align:right; padding:8px; border-bottom:1px solid #cbd5e0;">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>' . $rowsHtml . '</tbody>
                    <tfoot>
                        <tr>
                            <td colspan="3" style="padding:8px; text-align:right; font-weight:600;">Total</td>
                            <td style="padding:8px; text-align:right; font-weight:600;">₱' . number_format((float) ($order['total'] ?? $total), 2) . '</td>
                        </tr>
                    </tfoot>
                </table>'
            . '<p style="margin:0;">If you have questions or would like to place a new order, feel free to contact us.</p>'
            . '<p style="margin:16px 0 0;">Thank you,<br>DGZ Motorshop Team</p>'
            . '</div>';

        sendEmail($email, 'Order Disapproved - DGZ Motorshop', $body);
    }
} catch (Throwable $e) {
    error_log('Disapproval email failed: ' . $e->getMessage());
}

echo json_encode([
    'success' => true,
    'message' => 'Order disapproved successfully.',
    'reason' => [
        'id' => $reasonId,
        'label' => $declineReason['label'] ?? $reasonLabel,
    ],
]);
