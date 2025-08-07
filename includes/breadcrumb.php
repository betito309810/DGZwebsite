<?php
// ============================================
// FILE: includes/breadcrumb.php
// Breadcrumb navigation component
// ============================================

function generateBreadcrumb($items = []) {
    if (empty($items)) {
        // Auto-generate breadcrumb based on current URL
        $path = trim($_SERVER['REQUEST_URI'], '/');
        $segments = explode('/', $path);
        
        $items = [['title' => 'Home', 'url' => '/']];
        
        $current_path = '';
        foreach ($segments as $segment) {
            if (!empty($segment) && $segment !== 'index.php') {
                $current_path .= '/' . $segment;
                $title = ucwords(str_replace(['-', '_'], ' ', $segment));
                $items[] = ['title' => $title, 'url' => $current_path];
            }
        }
    }
    
    if (count($items) > 1): ?>
        <nav class="breadcrumb" aria-label="breadcrumb">
            <ul class="breadcrumb-list">
                <?php foreach ($items as $index => $item): ?>
                    <li class="breadcrumb-item">
                        <?php if ($index < count($items) - 1 && isset($item['url'])): ?>
                            <a href="<?php echo htmlspecialchars($item['url']); ?>">
                                <?php echo htmlspecialchars($item['title']); ?>
                            </a>
                        <?php else: ?>
                            <?php echo htmlspecialchars($item['title']); ?>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </nav>
    <?php endif;
}
?>