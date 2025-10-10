<?php
// Database configuration
$DB_HOST = 'auth-db2052.hostgtr.io';
$DB_NAME = 'u776610364_dgzstonino';
$DB_USER = 'u776610364_dgzadmin';
$DB_PASS = 'Dgzstonino123';

// Resolve the application's base path so generated links work regardless of
// where the project is deployed inside the web root.
$appRoot = str_replace('\\', '/', realpath(__DIR__ . '/..'));
$documentRoot = isset($_SERVER['DOCUMENT_ROOT'])
    ? str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT']))
    : false;

$APP_BASE_PATH = '';

if ($appRoot !== false && $documentRoot !== false && strpos($appRoot, $documentRoot) === 0) {
    $relativePath = substr($appRoot, strlen($documentRoot));
    $APP_BASE_PATH = $relativePath === '' ? '' : '/' . ltrim($relativePath, '/');
}

if ($APP_BASE_PATH === '/') {
    $APP_BASE_PATH = '';
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
} else {
    $APP_BASE_URL = $APP_BASE_PATH;
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

if (!function_exists('assetUrl')) {
    function assetUrl(string $path): string
    {
        $normalized = ltrim($path, '/');

        if ($normalized === '') {
            return appBasePath() === '' ? '/' : appBasePath();
        }

        $basePath = appBasePath();
        if ($basePath === '' || $basePath === '/') {
            return '/' . $normalized;
        }

        return rtrim($basePath, '/') . '/' . $normalized;
    }
}

if (!function_exists('routeUrl')) {
    function routeUrl(string $path = ''): string
    {
        $normalized = ltrim($path, '/');
        if ($normalized === '') {
            return assetUrl('');
        }

        return assetUrl($normalized);
    }
}

if (!function_exists('orderingUrl')) {
    function orderingUrl(string $path = ''): string
    {
        $normalized = ltrim($path, '/');
        return routeUrl('ordering/' . $normalized);
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
        $relative = routeUrl($path);
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

        // Allow fully-qualified URLs or data URIs to pass through untouched.
        if (preg_match('#^https?://#i', $trimmed) === 1 || strncmp($trimmed, 'data:', 5) === 0) {
            return $trimmed;
        }

        // Preserve absolute paths as-is.
        if ($trimmed[0] === '/') {
            return $trimmed;
        }

        // Already relative to the admin root; leave unchanged.
        if (strncmp($trimmed, '../', 3) === 0 || strncmp($trimmed, './', 2) === 0) {
            return $trimmed;
        }

        return $defaultPrefix . ltrim($trimmed, '/');
    }
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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
