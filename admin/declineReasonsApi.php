<?php
/**
 * Lightweight JSON API for creating/updating reusable decline reasons.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/includes/decline_reasons.php';

header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Authentication required.',
    ]);
    exit;
}

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Unsupported method.',
    ]);
    exit;
}

$rawBody = file_get_contents('php://input');
$payload = json_decode($rawBody ?? '', true);

if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request payload.',
    ]);
    exit;
}

$action = isset($payload['action']) ? strtolower((string) $payload['action']) : '';

$pdo = db();
ensureOrderDeclineSchema($pdo);

try {
    switch ($action) {
        case 'create':
            $label = isset($payload['label']) ? trim((string) $payload['label']) : '';
            if ($label === '') {
                throw new RuntimeException('Reason label cannot be empty.');
            }

            $newReason = createOrderDeclineReason($pdo, $label);
            if ($newReason === null) {
                throw new RuntimeException('Failed to create decline reason.');
            }

            $reasons = fetchOrderDeclineReasons($pdo);

            echo json_encode([
                'success' => true,
                'reason' => $newReason,
                'reasons' => $reasons,
            ]);
            break;

        case 'update':
            $reasonId = isset($payload['id']) ? (int) $payload['id'] : 0;
            $label = isset($payload['label']) ? trim((string) $payload['label']) : '';

            if ($reasonId <= 0) {
                throw new RuntimeException('Invalid decline reason id.');
            }

            if ($label === '') {
                throw new RuntimeException('Reason label cannot be empty.');
            }

            $updated = updateOrderDeclineReason($pdo, $reasonId, $label);
            if (!$updated) {
                throw new RuntimeException('Failed to update decline reason.');
            }

            $reasons = fetchOrderDeclineReasons($pdo);
            $updatedReason = findOrderDeclineReason($pdo, $reasonId);

            echo json_encode([
                'success' => true,
                'reason' => $updatedReason,
                'reasons' => $reasons,
            ]);
            break;

        case 'delete':
            $reasonId = isset($payload['id']) ? (int) $payload['id'] : 0;

            if ($reasonId <= 0) {
                throw new RuntimeException('Invalid decline reason id.');
            }

            $deleted = deleteOrderDeclineReason($pdo, $reasonId);
            if (!$deleted) {
                throw new RuntimeException('Failed to delete decline reason.');
            }

            $reasons = fetchOrderDeclineReasons($pdo);

            echo json_encode([
                'success' => true,
                'reasons' => $reasons,
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Unsupported action.',
            ]);
    }
} catch (RuntimeException $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unexpected error while processing decline reason.',
    ]);
}
