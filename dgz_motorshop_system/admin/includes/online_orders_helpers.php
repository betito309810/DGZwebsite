<?php
require_once __DIR__ . '/../../config/config.php';

if (!function_exists('normaliseOnlineOrderStatus')) {
    function normaliseOnlineOrderStatus($status): string
    {
        $status = strtolower(trim((string) $status));
        $allowed = ['pending', 'payment_verification', 'approved', 'completed', 'disapproved'];
        return in_array($status, $allowed, true) ? $status : '';
    }
}

if (!function_exists('getOnlineOrderStatusOptions')) {
    function getOnlineOrderStatusOptions(): array
    {
        return [
            'pending' => 'Pending',
            'payment_verification' => 'Payment Verification',
            'approved' => 'Approved',
            'completed' => 'Completed',
            'disapproved' => 'Disapproved',
        ];
    }
}

if (!function_exists('getOnlineOrderStatusTransitions')) {
    function getOnlineOrderStatusTransitions(): array
    {
        return [
            'pending' => ['payment_verification', 'approved', 'disapproved', 'completed'],
            'payment_verification' => ['approved', 'disapproved'],
            'approved' => ['completed'],
            'completed' => [],
            'disapproved' => [],
        ];
    }
}

if (!function_exists('fetchOnlineOrdersData')) {
    function fetchOnlineOrdersData(PDO $pdo, array $options = []): array
    {
        $perPage = (int) ($options['per_page'] ?? 15);
        $perPage = max(1, min($perPage, 100));

        $page = (int) ($options['page'] ?? 1);
        $page = max(1, $page);

        $statusFilter = normaliseOnlineOrderStatus($options['status'] ?? '');
        $statusOptions = getOnlineOrderStatusOptions();
        $statusTransitions = getOnlineOrderStatusTransitions();
        $declineLookup = [];
        if (isset($options['decline_reason_lookup']) && is_array($options['decline_reason_lookup'])) {
            $declineLookup = $options['decline_reason_lookup'];
        }

        $whereClause = getOnlineOrdersBaseCondition();
        $params = [];
        if ($statusFilter !== '') {
            $whereClause .= ' AND status = ?';
            $params[] = $statusFilter;
        }

        // Total count for pagination
        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE ' . $whereClause);
        $countStmt->execute($params);
        $totalOrders = (int) $countStmt->fetchColumn();
        $totalPages = (int) ceil($totalOrders / $perPage);
        if ($totalPages < 1) {
            $totalPages = 1;
        }

        if ($page > $totalPages) {
            $page = $totalPages;
        }

        $offset = ($page - 1) * $perPage;
        if ($offset < 0) {
            $offset = 0;
        }

        $sql = 'SELECT * FROM orders WHERE ' . $whereClause . ' ORDER BY created_at DESC LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $orders = [];
        foreach ($rows as $row) {
            $statusValue = strtolower((string) ($row['status'] ?? 'pending'));
            if (!isset($statusOptions[$statusValue])) {
                $statusValue = 'pending';
            }

            $paymentDetails = parsePaymentProofValue($row['payment_proof'] ?? null, $row['reference_no'] ?? null);
            $referenceNumber = (string) ($paymentDetails['reference'] ?? '');
            $proofImage = normalizePaymentProofPath($paymentDetails['image'] ?? '');

            $contactDisplay = '';
            if (!empty($row['email'])) {
                $contactDisplay = (string) $row['email'];
            } elseif (!empty($row['phone'])) {
                $contactDisplay = (string) $row['phone'];
            }

            $createdAtRaw = $row['created_at'] ?? null;
            $formattedDate = 'N/A';
            if (!empty($createdAtRaw)) {
                $timestamp = strtotime((string) $createdAtRaw);
                if ($timestamp !== false) {
                    $formattedDate = date('M d, Y g:i A', $timestamp);
                } else {
                    $formattedDate = (string) $createdAtRaw;
                }
            }

            $declineReasonId = (int) ($row['decline_reason_id'] ?? 0);
            $declineReasonLabel = '';
            if ($declineReasonId > 0 && isset($declineLookup[$declineReasonId])) {
                $declineReasonLabel = (string) $declineLookup[$declineReasonId];
            }
            $declineReasonNote = (string) ($row['decline_reason_note'] ?? '');

            $availableTransitions = $statusTransitions[$statusValue] ?? [];
            $transitionOptions = [];
            foreach ($availableTransitions as $value) {
                $transitionOptions[] = [
                    'value' => $value,
                    'label' => $statusOptions[$value] ?? ucfirst($value),
                ];
            }

            $orders[] = [
                'id' => (int) $row['id'],
                'customer_name' => (string) ($row['customer_name'] ?? 'Customer'),
                'customer_email' => (string) ($row['email'] ?? ''),
                'customer_phone' => (string) ($row['phone'] ?? ''),
                'contact_display' => $contactDisplay,
                'total' => (float) ($row['total'] ?? 0),
                'total_formatted' => '₱' . number_format((float) ($row['total'] ?? 0), 2),
                'reference_number' => $referenceNumber,
                'proof_image_url' => $proofImage,
                'status_value' => $statusValue,
                'status_label' => $statusOptions[$statusValue] ?? ucfirst($statusValue),
                'status_badge_class' => 'status-' . $statusValue,
                'available_status_changes' => $transitionOptions,
                'status_form_disabled' => empty($transitionOptions),
                'decline_reason_id' => $declineReasonId,
                'decline_reason_label' => $declineReasonLabel,
                'decline_reason_note' => $declineReasonNote,
                'created_at' => $createdAtRaw,
                'created_at_formatted' => $formattedDate,
                'payment_method' => (string) ($row['payment_method'] ?? ''),
            ];
        }

        $attentionStatuses = $options['attention_statuses'] ?? ['pending', 'payment_verification'];
        $attentionCount = countOnlineOrdersByStatus($pdo, $attentionStatuses);

        return [
            'orders' => $orders,
            'page' => $page,
            'per_page' => $perPage,
            'total_orders' => $totalOrders,
            'total_pages' => $totalPages,
            'status_filter' => $statusFilter,
            'attention_count' => $attentionCount,
        ];
    }
}
