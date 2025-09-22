<?php
// database configuration
$DB_HOST = 'localhost';
$DB_NAME = 'dgz_db';
$DB_USER = 'root';
$DB_PASS = ''; // set your MySQL root password if any

function db() {
    global $DB_HOST,$DB_NAME,$DB_USER,$DB_PASS;
    $pdo = new PDO("mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4", $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
    ]);
    return $pdo;
}

/**
 * Normalize payment proof information so callers can reliably access
 * the reference number and optional image path.
 */
function parsePaymentProofValue($value, $fallbackReference = null) {
    $details = [
        'reference' => null,
        'image' => null,
    ];

    if (empty($value)) {
        // If we have a fallback reference, use it
        if ($fallbackReference !== null) {
            $fallbackReference = trim((string) $fallbackReference);
            if ($fallbackReference !== '') {
                $details['reference'] = $fallbackReference;
            }
        }
        return $details;
    }

    if (is_string($value)) {
        $value = trim($value);
    }

    // Try to decode as JSON first
    $decoded = json_decode($value, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        if (!empty($decoded['reference'])) {
            $details['reference'] = (string) $decoded['reference'];
        }
        if (!empty($decoded['image'])) {
            $details['image'] = (string) $decoded['image'];
        }
        return $details;
    }

    // Handle as string value
    $stringValue = is_scalar($value) ? (string) $value : '';
    if ($stringValue === '') {
        return $details;
    }

    // Check if it looks like an image path or URL
    $hasPathSeparators = strpos($stringValue, '/') !== false || strpos($stringValue, '\\') !== false;
    $looksLikeImage = preg_match('/\.(jpe?g|png|gif|webp|bmp)$/i', $stringValue) === 1;
    $startsWithUrl = stripos($stringValue, 'http://') === 0 || stripos($stringValue, 'https://') === 0;

    if ($looksLikeImage || $hasPathSeparators || $startsWithUrl) {
        // Looks like an image path
        $details['image'] = $stringValue;
    } else {
        // Treat as reference number
        $details['reference'] = $stringValue;
    }

    // Use fallback reference if we don't have one yet
    if ($details['reference'] === null && $fallbackReference !== null) {
        $fallbackReference = trim((string) $fallbackReference);
        if ($fallbackReference !== '') {
            $details['reference'] = $fallbackReference;
        }
    }

    return $details;
}

session_start();
?>