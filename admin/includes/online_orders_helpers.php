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
        $allowed = [
            'pending',
            'payment_verification',
            'approved',
            'delivery',
            'completed',
            'complete',
            'disapproved',
            'cancelled_by_customer',
            'cancelled_by_staff',
            'cancelled',
            'canceled',
        ];

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
            'complete' => 'Complete',
            'disapproved' => 'Disapproved',
            'cancelled_by_customer' => 'Cancelled by Customer',
            'cancelled_by_staff' => 'Cancelled by Staff',
            'cancelled' => 'Cancelled',
            'canceled' => 'Cancelled',
        ];
    }
}

if (!function_exists('getOnlineOrderStatusTransitions')) {
    function getOnlineOrderStatusTransitions(): array
    {
        return [
            'pending' => ['payment_verification', 'approved', 'disapproved'],
            'payment_verification' => ['approved', 'disapproved'],
            'approved' => ['delivery'],
            'delivery' => ['completed'],
            'completed' => [],
            'complete' => ['completed'],
            'disapproved' => [],
            'cancelled_by_customer' => [],
            'cancelled_by_staff' => [],
            'cancelled' => [],
            'canceled' => [],
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
        $deliveryProofCandidates = $options['delivery_proof_candidates'] ?? [
            'delivery_proof',
            'proof_of_delivery',
            'delivery_proof_path',
            'delivery_proof_image',
            'delivery_photo',
            'delivery_photo_path',
        ];
        $deliveryProofColumn = $options['delivery_proof_column'] ?? null;
        if (!is_string($deliveryProofColumn) || $deliveryProofColumn === '') {
            $deliveryProofColumn = ordersFindColumn($pdo, $deliveryProofCandidates);
        }
        $supportsDeliveryProof = is_string($deliveryProofColumn) && $deliveryProofColumn !== '';
        $deliveryProofNotice = isset($options['delivery_proof_notice'])
            ? (string) $options['delivery_proof_notice']
            : 'Proof-of-delivery uploads need a delivery_proof column (TEXT) on the existing orders table—no new table required. '
                . 'Run: ALTER TABLE orders ADD COLUMN delivery_proof TEXT NULL;';
        if ($supportsDeliveryProof) {
            $deliveryProofNotice = '';
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
            $customerFields = [];
            if (customersHasColumn($pdo, 'full_name')) {
                $customerFields[] = 'c.full_name AS full_name';
            }
            if (customersHasColumn($pdo, 'email')) {
                $customerFields[] = 'c.email AS customer_email';
            }
            foreach (['phone', 'contact', 'contact_number', 'contact_no', 'mobile', 'telephone'] as $customerPhoneColumn) {
                if (customersHasColumn($pdo, $customerPhoneColumn)) {
                    $customerFields[] = 'c.' . $customerPhoneColumn . ' AS customer_phone';
                    break;
                }
            }
            if (customersHasColumn($pdo, 'facebook_account')) {
                $customerFields[] = 'c.facebook_account AS customer_facebook_account';
            }
            if (customersHasColumn($pdo, 'address')) {
                $customerFields[] = 'c.address AS customer_address';
            }
            if (customersHasColumn($pdo, 'postal_code')) {
                $customerFields[] = 'c.postal_code AS customer_postal_code';
            }
            if (customersHasColumn($pdo, 'city')) {
                $customerFields[] = 'c.city AS customer_city';
            }

            if ($customerFields !== []) {
                $customerSelect = ",\n                " . implode(",\n                ", $customerFields);
            }

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
            $rawStatus = strtolower((string) ($row['status'] ?? ''));
            $statusValue = $rawStatus !== '' ? $rawStatus : 'pending';
            $statusLabel = $statusOptions[$statusValue] ?? ucwords(str_replace('_', ' ', $statusValue));

            $paymentDetails = parsePaymentProofValue($row['payment_proof'] ?? null, $row['reference_no'] ?? null);
            $referenceNumber = (string) ($paymentDetails['reference'] ?? '');
            $proofImage = normalizePaymentProofPath($paymentDetails['image'] ?? '');
            $hasPaymentProof = $proofImage !== '';
            if ($deliveryProofColumn !== null && array_key_exists($deliveryProofColumn, $row)) {
                $deliveryProofRaw = $row[$deliveryProofColumn];
            } else {
                $deliveryProofRaw = $row['delivery_proof'] ?? null;
            }
            $deliveryProofUrl = $deliveryProofRaw !== null ? normalizePaymentProofPath((string) $deliveryProofRaw) : '';
            $hasDeliveryProof = $deliveryProofUrl !== '';
            $primaryProofType = $hasDeliveryProof ? 'delivery' : 'payment';
            $primaryProofUrl = $hasDeliveryProof && $deliveryProofUrl !== '' ? $deliveryProofUrl : $proofImage;

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
            $statusFormHidden = in_array($statusValue, ['completed', 'complete', 'disapproved'], true);
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
                'proof_image_url' => $primaryProofUrl,
                'proof_type' => $primaryProofType,
                'proof_button_label' => $primaryProofType === 'delivery' ? 'Delivery Proof' : 'Payment Proof',
                'payment_proof_url' => $proofImage,
                'delivery_proof_url' => $deliveryProofUrl,
                'has_payment_proof' => $hasPaymentProof,
                'has_delivery_proof' => $hasDeliveryProof,
                'delivery_proof_supported' => $supportsDeliveryProof,
                'status_value' => $statusValue,
                'status_label' => $statusLabel,
                'status_badge_class' => 'status-' . $statusValue,
                'available_status_changes' => $transitionOptions,
                'status_form_hidden' => $statusFormHidden,
                'status_form_disabled' => $statusFormHidden || empty($transitionOptions),
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
            'delivery_proof_supported' => $supportsDeliveryProof,
            'delivery_proof_column' => $supportsDeliveryProof ? $deliveryProofColumn : null,
            'delivery_proof_notice' => $deliveryProofNotice,
        ];
    }
}
