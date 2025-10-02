<?php
// Add your existing PHP code here
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Products</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/products/products.css">
    <link rel="stylesheet" href="../assets/css/products/products_table_new.css">
</head>
<body>
    <?php include __DIR__ . '/includes/sidebar.php'; ?>
    
    <main class="main-content">
        <div class="table-container">
            <?php if (empty($products)): ?>
                <div style="text-align: center; padding: 40px; color: #6b7280;">No products found matching the criteria.</div>
            <?php else: ?>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>CODE</th>
                                <th>NAME</th>
                                <th>QTY</th>
                                <th>PRICE</th>
                                <th>ACTION</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($products as $p): ?>
                                <tr>
                                    <td><?= htmlspecialchars($p['code']) ?></td>
                                    <td><?= htmlspecialchars($p['name']) ?></td>
                                    <td><?= intval($p['quantity']) ?></td>
                                    <td>₱<?= number_format($p['price'], 2) ?></td>
                                    <td>
                                        <button class="btn btn-edit" onclick="editProduct(<?= htmlspecialchars(json_encode($p)) ?>)">
                                            <i class="fas fa-edit"></i> Edit
                                        </button>
                                        <button class="btn btn-delete" onclick="confirmDelete(<?= $p['id'] ?>)">
                                            <i class="fas fa-trash"></i> Delete
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="pagination-container">
                    <div class="pagination-info">
                        Showing <?= $start_record ?> to <?= $end_record ?> of <?= $total_products ?> entries
                    </div>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?page=<?= ($page-1) ?>&<?= http_build_query(['search' => $search, 'brand' => $brand_filter, 'category' => $category_filter, 'supplier' => $supplier_filter]) ?>" class="prev">
                                <i class="fas fa-chevron-left"></i> Previous
                            </a>
                        <?php else: ?>
                            <span class="prev disabled">
                                <i class="fas fa-chevron-left"></i> Previous
                            </span>
                        <?php endif; ?>

                        <?php
                        $start_page = max(1, $page - 2);
                        $end_page = min($total_pages, $page + 2);
                        
                        if ($start_page > 1): ?>
                            <a href="?page=1&<?= http_build_query(['search' => $search, 'brand' => $brand_filter, 'category' => $category_filter, 'supplier' => $supplier_filter]) ?>">1</a>
                            <?php if ($start_page > 2): ?>
                                <span>...</span>
                            <?php endif;
                        endif;

                        for ($i = $start_page; $i <= $end_page; $i++):
                            if ($i == $page): ?>
                                <span class="current"><?= $i ?></span>
                            <?php else: ?>
                                <a href="?page=<?= $i ?>&<?= http_build_query(['search' => $search, 'brand' => $brand_filter, 'category' => $category_filter, 'supplier' => $supplier_filter]) ?>"><?= $i ?></a>
                            <?php endif;
                        endfor;

                        if ($end_page < $total_pages):
                            if ($end_page < $total_pages - 1): ?>
                                <span>...</span>
                            <?php endif; ?>
                            <a href="?page=<?= $total_pages ?>&<?= http_build_query(['search' => $search, 'brand' => $brand_filter, 'category' => $category_filter, 'supplier' => $supplier_filter]) ?>"><?= $total_pages ?></a>
                        <?php endif; ?>

                        <?php if ($page < $total_pages): ?>
                            <a href="?page=<?= ($page+1) ?>&<?= http_build_query(['search' => $search, 'brand' => $brand_filter, 'category' => $category_filter, 'supplier' => $supplier_filter]) ?>" class="next">
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
    </main>

    <script src="../assets/js/notifications.js"></script>
</body>
</html>