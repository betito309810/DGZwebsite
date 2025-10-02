<?php
require __DIR__. '/../config/config.php';
if(empty($_SESSION['user_id'])){ header('Location: login.php'); exit; }
$pdo = db();
$role = $_SESSION['role'] ?? '';
enforceStaffAccess();

// Product Add History for modal (HTML table, not JSON)
if (isset($_GET['history']) && $_GET['history'] == '1') {
    try {
        $sql = "
            SELECT h.created_at, h.action, h.details,
                   p.code AS product_code, p.name AS product_name,
                   p.price, p.quantity, p.brand, p.category,
                   COALESCE(u.name, CONCAT('User #', h.user_id)) AS added_by
            FROM product_add_history h
            LEFT JOIN products p ON p.id = h.product_id
            LEFT JOIN users u ON u.id = h.user_id
            ORDER BY h.created_at DESC
            LIMIT 10
        ";
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        ?>
        <table class="entries-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Product Code</th>
                    <th>Product Name</th>
                    <th>Brand</th>
                    <th>Category</th>
                    <th>Price</th>
                    <th>Quantity</th>
                    <th>Added by</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($rows)): ?>
                    <?php foreach ($rows as $entry): 
                        $rawDetails = $entry['details'] ?? '';
                        $decodedDetails = json_decode($rawDetails, true);
                        $detailsIsStructured = json_last_error() === JSON_ERROR_NONE && is_array($decodedDetails);
                        $snapshot = $detailsIsStructured && isset($decodedDetails['snapshot']) ? $decodedDetails['snapshot'] : [];
                        $changes = $detailsIsStructured && isset($decodedDetails['changes']) ? $decodedDetails['changes'] : [];
                        $summaryText = $detailsIsStructured && !empty($decodedDetails['summary']) ? $decodedDetails['summary'] : '';

                        $displayCode = $entry['product_code'] ?? ($snapshot['code'] ?? '');
                        $displayName = $entry['product_name'] ?? ($snapshot['name'] ?? '');
                        $displayBrand = $entry['brand'] ?? ($snapshot['brand'] ?? '');
                        $displayCategory = $entry['category'] ?? ($snapshot['category'] ?? '');
                        $displayPrice = $entry['price'] ?? ($snapshot['price'] ?? 0);
                        $displayQuantity = $entry['quantity'] ?? ($snapshot['quantity'] ?? 0);

                        $detailsHtml = '';
                        if ($detailsIsStructured) {
                            if ($summaryText !== '') {
                                $detailsHtml .= '<div>' . htmlspecialchars($summaryText) . '</div>';
                            }
                            if (!empty($changes)) {
                                $detailsHtml .= '<ul class="history-change-list">';
                                foreach ($changes as $change) {
                                    $detailsHtml .= '<li>' . htmlspecialchars($change) . '</li>';
                                }
                                $detailsHtml .= '</ul>';
                            }
                        } elseif ($rawDetails !== '') {
                            $detailsHtml = '<div>' . htmlspecialchars($rawDetails) . '</div>';
                        }
                    ?>
                        <tr>
                            <td><?= date('M d, Y H:i', strtotime($entry['created_at'])) ?></td>
                            <td><span class="product-code"><?= htmlspecialchars($displayCode !== '' ? $displayCode : '-') ?></span></td>
                            <td><span class="product-name"><?= htmlspecialchars($displayName !== '' ? $displayName : '-') ?></span></td>
                            <td><span class="brand-badge"><?= htmlspecialchars($displayBrand !== '' ? $displayBrand : '-') ?></span></td>
                            <td><span class="category-badge"><?= htmlspecialchars($displayCategory !== '' ? $displayCategory : '-') ?></span></td>
                            <td><span class="price">₱<?= number_format($displayPrice, 2) ?></span></td>
                            <td><span class="quantity"><?= number_format($displayQuantity) ?></span></td>
                            <td><span class="user-name"><?= htmlspecialchars($entry['added_by'] ?? '-') ?></span></td>
                            <td>
                                <?php 
                                $action = $entry['action'] ?? 'add';
                                $actionClass = 'action-badge';
                                
                                switch($action) {
                                    case 'edit':
                                        $actionClass .= ' action-edit';
                                        $actionText = 'Edited';
                                        break;
                                    case 'delete':
                                        $actionClass .= ' action-delete';
                                        $actionText = 'Deleted';
                                        break;
                                    default:
                                        $actionClass .= ' action-add';
                                        $actionText = 'Added';
                                }
                                ?>
                                <span class="<?= $actionClass ?>"><?= $actionText ?></span>
                                <?php if ($detailsHtml !== ''): ?>
                                    <div class="action-details"><?= $detailsHtml ?></div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="9" style="text-align:center;">No product history</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        <link rel="stylesheet" href="../assets/css/products/products_history.css">
        <?php
        exit;
    } catch (PDOException $e) {
        http_response_code(500);
        echo "Error loading history";
        exit;
    }
}