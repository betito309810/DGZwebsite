<?php
// Database configuration
$DB_HOST = 'localhost';
$DB_NAME = 'dgz_db';
$DB_USER = 'root';
$DB_PASS = ''; // Set your MySQL root password if any

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
