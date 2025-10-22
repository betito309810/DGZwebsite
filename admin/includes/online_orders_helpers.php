<?php
require_once __DIR__ . '/../../config/config.php';

if (!function_exists('ordersResolveField')) {
    function ordersResolveField(array $row, array $candidates)
    {
        foreach ($candidates as $candidate) {
            if (!array_key_exists($candidate, $row)) {
                continue;
            }

            $value = $row[$candidate];
            if ($value === null) {
                continue;
            }

            if (is_string($value)) {
                $value = trim($value);
                if ($value === '') {
                    continue;
                }
                return $value;
            }

            return $value;
        }

        return null;
    }
}

if (!function_exists('normalizeOnlineOrderRow')) {
    function normalizeOnlineOrderRow(array $row): array
    {
        $normalized = $row;

        $setStringField = static function (string $key, array $candidates) use (&$normalized): void {
            $current = $normalized[$key] ?? null;
            $currentString = is_string($current) ? trim($current) : '';
            if ($currentString !== '') {
                return;
            }

            $value = ordersResolveField($normalized, $candidates);
            if ($value !== null) {
                $normalized[$key] = (string) $value;
            }
        };

        $setStringField('customer_name', ['customer_name', 'full_name', 'name', 'customer_full_name']);
        $setStringField('email', ['email', 'customer_email', 'email_address', 'contact_email']);
        $setStringField('phone', ['phone', 'customer_phone', 'contact', 'contact_number', 'mobile', 'telephone']);
        $setStringField('facebook_account', ['facebook_account', 'facebook', 'fb_account', 'facebook_profile', 'customer_facebook_account']);
        $setStringField('address', ['address', 'customer_address', 'shipping_address', 'address_line1', 'address1', 'street']);
        $setStringField('postal_code', ['postal_code', 'postal', 'zip_code', 'zipcode', 'zip', 'customer_postal_code']);
        $setStringField('city', ['city', 'town', 'municipality', 'customer_city']);
        $setStringField('reference_no', ['reference_no', 'reference_number', 'reference', 'ref_no']);
        $setStringField('invoice_number', ['invoice_number', 'invoice', 'invoice_no']);
        $setStringField('customer_note', ['customer_note', 'notes', 'note']);
        $setStringField('payment_method', ['payment_method', 'paymentmethod', 'payment_type']);
        $setStringField('tracking_code', ['tracking_code', 'tracking_number', 'tracking_no']);

        $createdAt = ordersResolveField($normalized, ['created_at', 'order_date', 'date_created', 'created']);
        if ($createdAt !== null) {
            $normalized['created_at'] = (string) $createdAt;
        }

        $statusValue = ordersResolveField($normalized, ['status', 'order_status']);
        if ($statusValue !== null) {
            $normalized['status'] = strtolower((string) $statusValue);
        } elseif (!isset($normalized['status']) || trim((string) $normalized['status']) === '') {
            $normalized['status'] = 'pending';
        }

        $totalValue = ordersResolveField($normalized, ['total', 'grand_total', 'amount', 'total_amount']);
        if ($totalValue !== null) {
            $normalized['total'] = (float) $totalValue;
        } elseif (!isset($normalized['total'])) {
            $normalized['total'] = 0.0;
        }

        return $normalized;
    }
}

if (!function_exists('normaliseOnlineOrderStatus')) {
    function normaliseOnlineOrderStatus($status): string
    {
        $status = strtolower(trim((string) $status));
        $allowed = ['pending', 'payment_verification', 'approved', 'delivery', 'completed', 'disapproved'];
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
            'delivery' => 'Out for Delivery',
            'completed' => 'Completed',
            'disapproved' => 'Disapproved',
        ];
    }
}

if (!function_exists('getOnlineOrderStatusTransitions')) {
    function getOnlineOrderStatusTransitions(): array
    {
        return [
            'pending' => ['payment_verification', 'approved', 'delivery', 'disapproved', 'completed'],
            'payment_verification' => ['approved', 'delivery', 'disapproved'],
            'approved' => ['delivery', 'completed'],
            'delivery' => ['completed'],
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

        $supportsCustomerAccounts = ordersSupportsCustomerAccounts($pdo);
        $customerSelect = '';
        $customerJoin = '';
        if ($supportsCustomerAccounts) {
            $customerSelect = ',
                c.full_name AS full_name,
                c.email AS customer_email,
                c.phone AS customer_phone,
                c.facebook_account AS customer_facebook_account,
                c.address AS customer_address,
                c.postal_code AS customer_postal_code,
                c.city AS customer_city';
            $customerJoin = ' LEFT JOIN customers c ON c.id = orders.customer_id';
        }

        $sql = 'SELECT orders.*' . $customerSelect . ' FROM orders' . $customerJoin . ' WHERE ' . $whereClause
            . ' ORDER BY orders.created_at DESC LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $orders = [];
        foreach ($rows as $row) {
            $row = normalizeOnlineOrderRow($row);
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
                'reference_number' => $referenceNumber !== '' ? $referenceNumber : (string) ($row['reference_no'] ?? ''),
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
                'email' => (string) ($row['email'] ?? ''),
                'phone' => (string) ($row['phone'] ?? ''),
                'facebook_account' => (string) ($row['facebook_account'] ?? ''),
                'address' => (string) ($row['address'] ?? ''),
                'postal_code' => (string) ($row['postal_code'] ?? ''),
                'city' => (string) ($row['city'] ?? ''),
                'customer_note' => (string) ($row['customer_note'] ?? ''),
                'tracking_code' => (string) ($row['tracking_code'] ?? ''),
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
