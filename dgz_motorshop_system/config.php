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
session_start();
?>