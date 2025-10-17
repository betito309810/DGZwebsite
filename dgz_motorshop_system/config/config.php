<?php
// Database configuration
$DB_HOST = 'localhost';
$DB_NAME = 'dgzdb';
$DB_USER = 'root';
$DB_PASS = '';
// Ensure all pages consistently render dates in Philippine time.
date_default_timezone_set('Asia/Manila');

// Preload shared helpers that expose product variant utilities so storefront
// and admin pages do not need to include the file manually (which can be
// fragile on hosts that relocate the public web root).
$productVariantHelpers = __DIR__ . '/../includes/product_variants.php';
if (is_file($productVariantHelpers)) {
    require_once $productVariantHelpers;
}

// Resolve the project's public and system base paths so generated links work
// regardless of where the project is deployed inside the web root. The
// storefront PHP files live alongside this directory, so we compute both the
// project root (public) and the `dgz_motorshop_system` path for shared assets.
$systemRoot = str_replace('\\', '/', realpath(__DIR__ . '/..'));
$projectRoot = $systemRoot !== false ? str_replace('\\', '/', dirname($systemRoot)) : false;

$envAppBasePath = getenv('DGZ_APP_BASE_PATH');
$envSystemBasePath = getenv('DGZ_SYSTEM_BASE_PATH');

$documentRootCandidates = [];
$addDocumentRoot = static function ($value) use (&$documentRootCandidates): void {
    if (!is_string($value) || $value === '') {
        return;
    }

    $normalized = str_replace('\\', '/', $value);
    $normalized = rtrim($normalized, '/');

    if ($normalized === '') {
        $normalized = '/';
    }

    $documentRootCandidates[$normalized] = strlen($normalized);
};

if (!empty($_SERVER['DOCUMENT_ROOT'])) {
    $addDocumentRoot($_SERVER['DOCUMENT_ROOT']);

    $resolved = realpath($_SERVER['DOCUMENT_ROOT']);
    if ($resolved !== false) {
        $addDocumentRoot($resolved);
    }
}

$scriptNames = [];
foreach (['SCRIPT_NAME', 'PHP_SELF'] as $key) {
    if (!empty($_SERVER[$key])) {
        $value = '/' . ltrim(str_replace('\\', '/', (string) $_SERVER[$key]), '/');
        $scriptNames[] = $value;
    }
}

if (!empty($_SERVER['SCRIPT_FILENAME']) && $scriptNames !== []) {
    $scriptFilename = str_replace('\\', '/', (string) $_SERVER['SCRIPT_FILENAME']);

    foreach ($scriptNames as $scriptName) {
        $length = strlen($scriptName);
        if ($length === 0 || $length > strlen($scriptFilename)) {
            continue;
        }

        if (substr_compare($scriptFilename, $scriptName, -$length) !== 0) {
            continue;
        }

        $candidate = substr($scriptFilename, 0, -$length);
        if ($candidate !== '') {
            $addDocumentRoot($candidate);
        }
    }
}

if ($documentRootCandidates === [] && $projectRoot !== false) {
    $addDocumentRoot($projectRoot);
}

arsort($documentRootCandidates);
$documentRoots = array_keys($documentRootCandidates);

$APP_BASE_PATH = '';
$SYSTEM_BASE_PATH = '';
$SYSTEM_BASE_URL = '';
$systemFolderName = $systemRoot !== false ? basename($systemRoot) : 'dgz_motorshop_system';
$documentRootMatchesProject = false;
$documentRootMatchesSystem = false;

$normalizeRelativePath = static function ($path) {
    if (!is_string($path)) {
        return '';
    }

    $normalized = str_replace('\\', '/', $path);
    $normalized = '/' . ltrim($normalized, '/');

    if ($normalized === '/') {
        return '';
    }

    $hostingRoots = [
        'public_html',
        'public',
        'htdocs',
        'httpdocs',
        'www',
        'wwwroot',
    ];

    foreach ($hostingRoots as $folder) {
        $prefix = '/' . $folder;

        if (strcasecmp($normalized, $prefix) === 0) {
            return '';
        }

        if (stripos($normalized, $prefix . '/') === 0) {
            $normalized = substr($normalized, strlen($prefix));
            $normalized = '/' . ltrim($normalized, '/');

            if ($normalized === '/') {
                return '';
            }

            break;
        }
    }

    return $normalized;
};

$startsWithPath = static function ($haystack, $needle): bool {
    if (!is_string($haystack) || !is_string($needle)) {
        return false;
    }

    $needleLength = strlen($needle);
    if ($needleLength === 0) {
        return false;
    }

    if ($needleLength > strlen($haystack)) {
        return false;
    }

    return strncasecmp($haystack, $needle, $needleLength) === 0;
};

foreach ($documentRoots as $documentRoot) {
    if ($documentRoot === '/' || $documentRoot === '') {
        continue;
    }

    $normalizedDocumentRoot = rtrim(str_replace('\\', '/', $documentRoot), '/');

    if ($projectRoot !== false) {
        $normalizedProjectRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');
        if ($normalizedProjectRoot !== '' && strcasecmp($normalizedProjectRoot, $normalizedDocumentRoot) === 0) {
            $documentRootMatchesProject = true;
        }

        if ($startsWithPath($projectRoot, $documentRoot)) {
            $relative = substr($projectRoot, strlen($documentRoot));
            $APP_BASE_PATH = $normalizeRelativePath($relative);
            break;
        }
    }
}

foreach ($documentRoots as $documentRoot) {
    if ($documentRoot === '/' || $documentRoot === '') {
        continue;
    }

    $normalizedDocumentRoot = rtrim(str_replace('\\', '/', $documentRoot), '/');

    if ($systemRoot !== false) {
        $normalizedSystemRoot = rtrim(str_replace('\\', '/', $systemRoot), '/');
        if ($normalizedSystemRoot !== '' && strcasecmp($normalizedSystemRoot, $normalizedDocumentRoot) === 0) {
            $documentRootMatchesSystem = true;
        }

        if ($startsWithPath($systemRoot, $documentRoot)) {
            $relative = substr($systemRoot, strlen($documentRoot));
            $SYSTEM_BASE_PATH = $normalizeRelativePath($relative);
            break;
        }
    }
}

if ($APP_BASE_PATH === '' && $documentRootMatchesProject) {
    $APP_BASE_PATH = '';
}

if ($SYSTEM_BASE_PATH === '' && $documentRootMatchesSystem) {
    $SYSTEM_BASE_PATH = '';
}

if ($APP_BASE_PATH === '/') {
    $APP_BASE_PATH = '';
}

if ($SYSTEM_BASE_PATH === '/') {
    $SYSTEM_BASE_PATH = '';
}

if (
    $SYSTEM_BASE_PATH === ''
    && !$documentRootMatchesSystem
    && $projectRoot !== false
    && $systemRoot !== false
    && $startsWithPath($systemRoot, $projectRoot)
) {
    $relative = substr($systemRoot, strlen($projectRoot));
    $SYSTEM_BASE_PATH = $normalizeRelativePath($relative);
}

if ($SYSTEM_BASE_PATH === '') {
    if ($documentRootMatchesSystem) {
        $SYSTEM_BASE_PATH = '';
    } elseif ($APP_BASE_PATH !== '') {
        $SYSTEM_BASE_PATH = rtrim($APP_BASE_PATH, '/') . '/' . $systemFolderName;
    } else {
        $SYSTEM_BASE_PATH = '/' . ltrim($systemFolderName, '/');
    }
}

if (is_string($envAppBasePath) && $envAppBasePath !== '') {
    $APP_BASE_PATH = $normalizeRelativePath($envAppBasePath);
}

if (is_string($envSystemBasePath) && $envSystemBasePath !== '') {
    $SYSTEM_BASE_PATH = $normalizeRelativePath($envSystemBasePath);
}

$isSecure = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['REQUEST_SCHEME']) && $_SERVER['REQUEST_SCHEME'] === 'https')
    || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443)
);

$host = $_SERVER['HTTP_HOST'] ?? '';
if ($host !== '') {
    $scheme = $isSecure ? 'https://' : 'http://';
    $APP_BASE_URL = rtrim($scheme . $host . $APP_BASE_PATH, '/');
    $SYSTEM_BASE_URL = rtrim($scheme . $host . $SYSTEM_BASE_PATH, '/');
} else {
    $APP_BASE_URL = $APP_BASE_PATH;
    $SYSTEM_BASE_URL = $SYSTEM_BASE_PATH;
}

/**
 * Create or reuse the PDO instance used throughout the application.
 */
if (!function_exists('db')) {
    function db(): PDO
    {
        static $pdo = null;

        if ($pdo instanceof PDO) {
            return $pdo;
        }

        global $DB_HOST, $DB_NAME, $DB_USER, $DB_PASS;

        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $DB_HOST, $DB_NAME);

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];

        $pdo = new PDO($dsn, $DB_USER, $DB_PASS, $options);
         try {
            // Align MySQL's session timezone with the PHP runtime. PHP's
            // date_default_timezone_set() only affects PHP date functions; MySQL
            // will continue to use the server's timezone for CURRENT_TIMESTAMP
            // and related values unless we override the session explicitly.
            $pdo->exec("SET time_zone = '+08:00'");
        } catch (Throwable $e) {
            error_log('Unable to set MySQL time_zone: ' . $e->getMessage());
        }

        return $pdo;
    }
}

if (!function_exists('appBasePath')) {
    function appBasePath(): string
    {
        global $APP_BASE_PATH;
        return $APP_BASE_PATH ?: '';
    }
}

if (!function_exists('appBaseUrl')) {
    function appBaseUrl(): string
    {
        global $APP_BASE_URL;
        return $APP_BASE_URL ?: '';
    }
}

if (!function_exists('systemBasePath')) {
    function systemBasePath(): string
    {
        global $SYSTEM_BASE_PATH;
        return $SYSTEM_BASE_PATH ?: '';
    }
}

if (!function_exists('systemBaseUrl')) {
    function systemBaseUrl(): string
    {
        global $SYSTEM_BASE_URL;
        return $SYSTEM_BASE_URL ?: '';
    }
}

if (!function_exists('systemAssetBasePath')) {
    function systemAssetBasePath(): string
    {
        $basePath = str_replace('\\', '/', systemBasePath());

        if ($basePath === '' || $basePath === '/') {
            $basePath = '/';
        }

        $firstChar = $basePath[0] ?? '';
        $looksLikeFilesystemRoot = preg_match('#^[a-z]:#i', $basePath) === 1
            || ($firstChar !== '/' && $firstChar !== '.');

        if ($looksLikeFilesystemRoot) {
            $basePath = '/';
        }

        if ($basePath === '/' || $basePath === './' || $basePath === '.') {
            global $systemFolderName;
            $folder = $systemFolderName ?: 'dgz_motorshop_system';
            $appBase = appBasePath();

            if ($appBase !== '' && $appBase !== '/') {
                $basePath = rtrim($appBase, '/') . '/' . ltrim($folder, '/');
            } else {
                $basePath = '/' . ltrim($folder, '/');
            }
        }

        if ($basePath !== '' && $basePath[0] !== '/') {
            $basePath = '/' . ltrim($basePath, '/');
        }

        return rtrim($basePath, '/') ?: '/';
    }
}

if (!function_exists('assetUrl')) {
    function assetUrl(string $path): string
    {
        $trimmed = trim($path);

        if ($trimmed === '') {
            $base = systemAssetBasePath();
            return $base === '' ? '/' : $base;
        }

        if (preg_match('#^(?:[a-z][a-z0-9+.-]*:)?//#i', $trimmed) === 1) {
            return $trimmed;
        }

        if ($trimmed[0] === '/') {
            return $trimmed;
        }

        if (strncmp($trimmed, './', 2) === 0 || strncmp($trimmed, '../', 3) === 0) {
            return $trimmed;
        }

        $normalized = ltrim($trimmed, '/');
        $basePath = systemAssetBasePath();

        if ($basePath === '' || $basePath === '/') {
            return '/' . $normalized;
        }

        return rtrim($basePath, '/') . '/' . $normalized;
    }
}

if (!function_exists('routeUrl')) {
    function routeUrl(string $path = ''): string
    {
        $trimmed = trim($path);

        if ($trimmed === '') {
            $base = appBasePath();
            return $base === '' ? '/' : $base;
        }

        if (preg_match('#^(?:[a-z][a-z0-9+.-]*:)?//#i', $trimmed) === 1) {
            return $trimmed;
        }

        if ($trimmed[0] === '/') {
            return $trimmed;
        }

        if (strncmp($trimmed, './', 2) === 0 || strncmp($trimmed, '../', 3) === 0) {
            return $trimmed;
        }

        $normalized = ltrim($trimmed, '/');
        $basePath = appBasePath();

        if ($basePath === '' || $basePath === '/') {
            return '/' . $normalized;
        }

        return rtrim($basePath, '/') . '/' . $normalized;
    }
}

if (!function_exists('appDocumentRootPath')) {
    function appDocumentRootPath(): string
    {
        $basePath = appBasePath();

        if ($basePath === '' || $basePath === '/') {
            return '';
        }

        $trimmed = rtrim($basePath, '/');
        if ($trimmed === '') {
            return '';
        }

        $docPath = dirname($trimmed);

        if ($docPath === '.' || $docPath === DIRECTORY_SEPARATOR) {
            return '';
        }

        if ($docPath === '\\' || $docPath === '/') {
            return '';
        }

        return $docPath;
    }
}

if (!function_exists('orderingUrl')) {
    function orderingUrl(string $path = ''): string
    {
        return routeUrl($path);
    }
}

if (!function_exists('adminUrl')) {
    function adminUrl(string $path = ''): string
    {
        $normalized = ltrim($path, '/');
        return routeUrl('admin/' . $normalized);
    }
}

if (!function_exists('absoluteUrl')) {
    function absoluteUrl(string $path = ''): string
    {
        $trimmed = trim($path);

        if ($trimmed === '') {
            $relative = routeUrl('');
        } elseif (preg_match('#^(?:[a-z][a-z0-9+.-]*:)?//#i', $trimmed) === 1) {
            return $trimmed;
        } elseif ($trimmed[0] === '/') {
            $relative = $trimmed;
        } else {
            $relative = routeUrl($trimmed);
        }

        $baseUrl = appBaseUrl();

        if ($baseUrl !== '' && preg_match('#^https?://#i', $baseUrl) === 1) {
            $basePath = appBasePath();
            $relativePath = $relative;

            if ($basePath !== '' && strpos($relative, $basePath) === 0) {
                $relativePath = substr($relative, strlen($basePath));
            }

            $relativePath = '/' . ltrim($relativePath, '/');

            return rtrim($baseUrl, '/') . $relativePath;
        }

        return $relative;
    }
}

if (!function_exists('publicAsset')) {
    function publicAsset(?string $path, ?string $fallback = null): string
    {
        $trimmed = trim((string) $path);
        if ($trimmed === '') {
            if ($fallback === null) {
                return '';
            }

            return publicAsset($fallback);
        }

        if (preg_match('#^(?:[a-z][a-z0-9+.-]*:)?//#i', $trimmed) === 1) {
            return $trimmed;
        }

        if ($trimmed[0] === '/') {
            return $trimmed;
        }

        if (strncmp($trimmed, '../', 3) === 0 || strncmp($trimmed, './', 2) === 0) {
            return $trimmed;
        }

        return assetUrl($trimmed);
    }
}

/**
 * Normalise payment proof information so callers can reliably access
 * the reference number and optional image path.
 *
 * @param mixed       $value             Raw payment proof value from the database.
 * @param string|null $fallbackReference Optional reference number to use when the value
 *                                       does not contain one.
 */
if (!function_exists('ordersSupportsProcessedBy')) {
    function ordersSupportsProcessedBy(PDO $pdo): bool
    {
        static $supports = null;

        if (is_bool($supports)) {
            return $supports;
        }

        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM orders LIKE 'processed_by_user_id'");
            $supports = $stmt !== false && $stmt->fetch(PDO::FETCH_ASSOC) !== false;
        } catch (Throwable $e) {
            error_log('Unable to determine processed_by_user_id support: ' . $e->getMessage());
            $supports = false;
        }

        return $supports;
    }
}

if (!function_exists('resolveUserDisplayName')) {
    function resolveUserDisplayName(array $user, array $fallbacks = []): ?string
    {
        $candidates = [];

        foreach (['name', 'full_name', 'display_name'] as $key) {
            if (!empty($user[$key])) {
                $candidates[] = $user[$key];
            }
        }

        if (!empty($user['first_name']) || !empty($user['last_name'])) {
            $first = trim((string) ($user['first_name'] ?? ''));
            $last = trim((string) ($user['last_name'] ?? ''));
            $combined = trim($first . ' ' . $last);
            if ($combined !== '') {
                $candidates[] = $combined;
            }
        }

        foreach (['username', 'email'] as $key) {
            if (!empty($user[$key])) {
                $candidates[] = $user[$key];
            }
        }

        foreach ($fallbacks as $value) {
            if (!empty($value)) {
                $candidates[] = $value;
            }
        }

        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }
}

if (!function_exists('fetchUserDisplayName')) {
    function fetchUserDisplayName(PDO $pdo, int $userId): ?string
    {
        if ($userId <= 0) {
            return null;
        }

        try {
            $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                return null;
            }

            $fallbacks = [];
            if (!empty($_SESSION['user_name']) && isset($_SESSION['user_id']) && (int) $_SESSION['user_id'] === $userId) {
                $fallbacks[] = $_SESSION['user_name'];
            }

            return resolveUserDisplayName($user, $fallbacks);
        } catch (Throwable $e) {
            error_log('Unable to fetch user display name: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('currentSessionUserDisplayName')) {
    function currentSessionUserDisplayName(): ?string
    {
        $sessionCandidates = [];
        foreach (['user_name', 'username', 'name'] as $key) {
            if (!empty($_SESSION[$key])) {
                $sessionCandidates[] = $_SESSION[$key];
            }
        }

        foreach ($sessionCandidates as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        if (!empty($_SESSION['user_id'])) {
            try {
                $pdo = db();
                $fetched = fetchUserDisplayName($pdo, (int) $_SESSION['user_id']);
                if ($fetched !== null) {
                    $_SESSION['user_name'] = $fetched;
                    return $fetched;
                }
            } catch (Throwable $e) {
                error_log('Unable to resolve current session user display name: ' . $e->getMessage());
            }
        }

        if (!empty($_SESSION['role'])) {
            $role = trim((string) $_SESSION['role']);
            if ($role !== '') {
                return ucfirst($role);
            }
        }

        return null;
    }
}

if (!function_exists('parsePaymentProofValue')) {
    function parsePaymentProofValue($value, $fallbackReference = null): array
    {
        $details = [
            'reference' => null,
            'image'     => null,
        ];

        // Handle structured arrays first.
        if (is_array($value)) {
            if (isset($value['reference'])) {
                $reference = trim((string) $value['reference']);
                $details['reference'] = $reference !== '' ? $reference : null;
            }

            if (isset($value['image'])) {
                $image = trim((string) $value['image']);
                $details['image'] = $image !== '' ? $image : null;
            }
        } elseif ($value !== null && $value !== false) {
            $stringValue = is_scalar($value) ? trim((string) $value) : '';

            if ($stringValue !== '') {
                $decoded = json_decode($stringValue, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    if (!empty($decoded['reference'])) {
                        $details['reference'] = (string) $decoded['reference'];
                    }

                    if (!empty($decoded['image'])) {
                        $details['image'] = (string) $decoded['image'];
                    }
                } else {
                    $looksLikeImage = preg_match('/\.(jpe?g|png|gif|webp|bmp)$/i', $stringValue) === 1;
                    $hasPathSeparators = strpbrk($stringValue, "\\/\\") !== false;
                    $startsWithUrl = preg_match('#^https?://#i', $stringValue) === 1;

                    if ($looksLikeImage || $hasPathSeparators || $startsWithUrl) {
                        $details['image'] = $stringValue;
                    } else {
                        $details['reference'] = $stringValue;
                    }
                }
            }
        }

        if ($details['reference'] === null && $fallbackReference !== null) {
            $fallbackReference = trim((string) $fallbackReference);
            if ($fallbackReference !== '') {
                $details['reference'] = $fallbackReference;
            }
        }

        return $details;
    }
}

if (!function_exists('normalizePaymentProofPath')) {
    function normalizePaymentProofPath(?string $path, string $defaultPrefix = '../'): string
    {
        if ($path === null) {
            return '';
        }

        $trimmed = trim($path);
        if ($trimmed === '') {
            return '';
        }

        $normalized = str_replace('\\', '/', $trimmed);
        $normalized = preg_replace('#/+#', '/', $normalized);

        // Allow fully-qualified URLs or data URIs to pass through untouched.
        if (preg_match('#^(?:[a-z][a-z0-9+.-]*:)?//#i', $normalized) === 1 || strncmp($normalized, 'data:', 5) === 0) {
            return $normalized;
        }

        // Preserve absolute or already-relative web paths.
        if ($normalized[0] === '/' || strncmp($normalized, '../', 3) === 0 || strncmp($normalized, './', 2) === 0) {
            return $normalized;
        }

        $relative = ltrim($normalized, '/');

        // If the string contains an uploads directory anywhere (e.g. full filesystem path),
        // trim everything before it so we end up with a web-accessible fragment.
        $lowerRelative = strtolower($relative);
        $uploadsPos = strpos($lowerRelative, 'uploads/');
        if ($uploadsPos !== false) {
            $relative = substr($relative, $uploadsPos);
        }

        // Strip known system folder prefixes that may have been persisted by older installs.
        $knownPrefixes = [
            'dgz_motorshop_system/uploads/',
            'dgz_motorshop_system/',
            'dgz-motorshop_system/uploads/',
            'dgz-motorshop_system/',
        ];

        foreach ($knownPrefixes as $prefix) {
            if (stripos($relative, $prefix) === 0) {
                $relative = substr($relative, strlen($prefix));
                break;
            }
        }

        $relative = ltrim($relative, '/');

        if ($relative === '') {
            return '';
        }

        // Historic records sometimes omitted the uploads directory; patch it back in so
        // links resolve next to the admin uploads folder.
        if (strpos($relative, 'uploads/') !== 0 && strpos($relative, 'payment-proofs/') === 0) {
            $relative = 'uploads/' . $relative;
        }

        if ($defaultPrefix === '../') {
            $assetUrl = assetUrl($relative);
            if ($assetUrl !== '') {
                return $assetUrl;
            }
        }

        $prefix = rtrim($defaultPrefix, '/');
        if ($prefix === '') {
            return $relative;
        }

        return $prefix . '/' . ltrim($relative, '/');
    }
}

if (!function_exists('getOnlineOrdersBaseCondition')) {
    /**
     * Base WHERE clause that determines whether an order should appear in the
     * online orders feed. Mirrors the logic used across the admin POS views.
     */
    function getOnlineOrdersBaseCondition(): string
    {
        static $clause = null;
        if ($clause !== null) {
            return $clause;
        }

        $parts = [
            "(payment_method IS NOT NULL AND payment_method <> '' AND LOWER(payment_method) = 'gcash')",
            "(payment_proof IS NOT NULL AND payment_proof <> '')",
            "status IN ('pending','payment_verification','approved','disapproved')",
        ];

        $clause = '(' . implode(' OR ', $parts) . ')';
        return $clause;
    }
}

if (!function_exists('countOnlineOrdersByStatus')) {
    /**
     * Count online orders that match the supplied statuses. Defaults to orders
     * that need cashier attention (pending or payment verification).
     */
    function countOnlineOrdersByStatus(PDO $pdo, array $statuses = ['pending', 'payment_verification']): int
    {
        $normalized = [];
        foreach ($statuses as $status) {
            $status = strtolower(trim((string) $status));
            if ($status !== '') {
                $normalized[$status] = true;
            }
        }

        if (empty($normalized)) {
            return 0;
        }

        $statusList = array_keys($normalized);
        $placeholders = implode(',', array_fill(0, count($statusList), '?'));

        $sql = 'SELECT COUNT(*) FROM orders WHERE ' . getOnlineOrdersBaseCondition() . ' AND status IN (' . $placeholders . ')';

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($statusList);
            return (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('Unable to count online orders: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('countPendingRestockRequests')) {
    /**
     * Count restock requests that still need attention from the admin team.
     */
    function countPendingRestockRequests(PDO $pdo): int
    {
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM restock_requests WHERE LOWER(status) = 'pending'");
            return (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('Unable to count pending restock requests: ' . $e->getMessage());
            return 0;
        }
    }
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
//Logout
if (!function_exists('logoutUser')) {
    function logoutUser(?PDO $pdo = null): void
    {
        $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;

        if ($userId > 0) {
            if (!$pdo instanceof PDO) {
                try {
                    $pdo = db();
                } catch (Throwable $e) {
                    $pdo = null;
                    error_log('Unable to acquire database connection for logout: ' . $e->getMessage());
                }
            }

            if ($pdo instanceof PDO) {
                try {
                    $clearToken = $pdo->prepare('UPDATE users SET current_session_token = NULL WHERE id = ?');
                    $clearToken->execute([$userId]);
                } catch (Throwable $e) {
                    error_log('Unable to clear session token on logout: ' . $e->getMessage());
                }
            }
        }

        $_SESSION = [];

        if (session_status() === PHP_SESSION_ACTIVE) {
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(
                    session_name(),
                    '',
                    time() - 42000,
                    $params['path'] ?? '/',
                    $params['domain'] ?? '',
                    !empty($params['secure']),
                    !empty($params['httponly'])
                );
            }

            session_destroy();
        }
    }
}

if (!function_exists('enforceSingleActiveSession')) {
    function enforceSingleActiveSession(): void
    {
        if (empty($_SESSION['user_id']) || empty($_SESSION['role'])) {
            return;
        }

        $currentToken = $_SESSION['session_token'] ?? '';
        $userId = (int) $_SESSION['user_id'];

        try {
            $pdo = db();
            $stmt = $pdo->prepare('SELECT current_session_token FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('Unable to verify active session token: ' . $e->getMessage());
            return;
        }

        $storedToken = is_array($row) ? ($row['current_session_token'] ?? null) : null;

        if (!is_string($storedToken) || $storedToken === '' || $currentToken === '' || !hash_equals($storedToken, $currentToken)) {
            $message = "You’ve been logged out because your account was used to sign in on another device.";

            $_SESSION = [
                'forced_logout' => true,
                'forced_logout_message' => $message,
            ];

            session_regenerate_id(true);

            header('Location: ' . adminUrl('login.php'));
            exit;
        }
    }
}

enforceSingleActiveSession();

if (!function_exists('staffAllowedAdminPages')) {
    function staffAllowedAdminPages(): array
    {
        return [
            'dashboard.php',
            'sales.php',
            'pos.php',
            'inventory.php',
            'sales_report_pdf.php',
            'stockEntry.php',
            'products.php',
            'settings.php',
        ];
    }
}

if (!function_exists('enforceStaffAccess')) {
    function enforceStaffAccess(array $additionalAllowed = []): void
    {
        $role = $_SESSION['role'] ?? '';
        if ($role !== 'staff') {
            return;
        }

        $currentScript = basename($_SERVER['PHP_SELF'] ?? '');
        if ($currentScript === '') {
            return;
        }

        $allowedPages = array_unique(array_merge(staffAllowedAdminPages(), $additionalAllowed));

        if (!in_array($currentScript, $allowedPages, true)) {
            header('Location: dashboard.php');
            exit;
        }
    }
}
