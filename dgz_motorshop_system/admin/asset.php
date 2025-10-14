<?php
declare(strict_types=1);

$systemRoot = realpath(__DIR__ . '/..');
if ($systemRoot === false) {
    http_response_code(500);
    exit('Asset directory is unavailable.');
}

$requestedPath = $_GET['path'] ?? '';
if (!is_string($requestedPath)) {
    http_response_code(400);
    exit('Invalid asset path.');
}

$sanitised = str_replace('\\', '/', $requestedPath);
$sanitised = ltrim($sanitised, '/');
if ($sanitised === '' || strpos($sanitised, '..') !== false) {
    http_response_code(400);
    exit('Invalid asset path.');
}

$allowedPrefixes = ['assets/', 'uploads/'];
$allowed = false;
foreach ($allowedPrefixes as $prefix) {
    if (stripos($sanitised, $prefix) === 0) {
        $allowed = true;
        break;
    }
}

if (!$allowed) {
    http_response_code(403);
    exit('Access to this resource is not allowed.');
}

$fullPath = realpath($systemRoot . '/' . $sanitised);
if ($fullPath === false || strpos($fullPath, $systemRoot) !== 0 || !is_file($fullPath)) {
    http_response_code(404);
    exit('Asset not found.');
}

$extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
$mimeTypes = [
    'css'  => 'text/css; charset=UTF-8',
    'js'   => 'application/javascript; charset=UTF-8',
    'json' => 'application/json; charset=UTF-8',
    'png'  => 'image/png',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif'  => 'image/gif',
    'svg'  => 'image/svg+xml',
    'webp' => 'image/webp',
    'ico'  => 'image/x-icon',
];

$mime = $mimeTypes[$extension] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Cache-Control: public, max-age=31536000');
header('X-Content-Type-Options: nosniff');

$size = filesize($fullPath);
if ($size !== false) {
    header('Content-Length: ' . $size);
}

$handle = fopen($fullPath, 'rb');
if ($handle === false) {
    http_response_code(500);
    exit('Unable to read asset.');
}

while (!feof($handle)) {
    $chunk = fread($handle, 8192);
    if ($chunk === false) {
        break;
    }
    echo $chunk;
}

fclose($handle);
exit;
