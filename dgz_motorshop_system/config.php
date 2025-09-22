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
function parsePaymentProofValue($value) {
    $details = [
        'reference' => null,
        'image' => null,
    ];

    if (empty($value)) {
        return $details;
    }

    $decoded = json_decode($value, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        if (!empty($decoded['reference'])) {
            $details['reference'] = (string) $decoded['reference'];
        }
        if (!empty($decoded['image'])) {
            $details['image'] = (string) $decoded['image'];
        }
    } else {
        // Legacy orders stored only the uploaded image path.
        $details['image'] = (string) $value;
    }

    return $details;
}
session_start();
?>