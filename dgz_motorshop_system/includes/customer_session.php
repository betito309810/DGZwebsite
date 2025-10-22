<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!function_exists('customerRepository')) {
    /**
     * Returns a PDO instance for customer related operations.
     */
    function customerRepository(): PDO
    {
        return db();
    }
}

if (!function_exists('getAuthenticatedCustomer')) {
    /**
     * Fetch the authenticated customer from the session (if any).
     *
     * @return array|null
     */
    function getAuthenticatedCustomer(): ?array
    {
        static $cachedCustomer = false;

        if ($cachedCustomer !== false) {
            return $cachedCustomer;
        }

        if (empty($_SESSION['customer_id'])) {
            $cachedCustomer = null;
            return $cachedCustomer;
        }

        $customerId = (int) $_SESSION['customer_id'];

        try {
            $pdo = customerRepository();
            $stmt = $pdo->prepare('SELECT id, full_name, email, phone, created_at FROM customers WHERE id = ? LIMIT 1');
            $stmt->execute([$customerId]);
            $customer = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($customer) {
                $customer['first_name'] = extractCustomerFirstName($customer['full_name'] ?? '');
            }
        } catch (Throwable $exception) {
            error_log('Unable to load authenticated customer: ' . $exception->getMessage());
            $customer = null;
        }

        if ($customer === null) {
            unset($_SESSION['customer_id']);
        }

        $cachedCustomer = $customer;
        return $cachedCustomer;
    }
}

if (!function_exists('extractCustomerFirstName')) {
    function extractCustomerFirstName(string $fullName): string
    {
        $trimmed = trim($fullName);
        if ($trimmed === '') {
            return 'Customer';
        }

        $parts = preg_split('/\s+/', $trimmed);
        if (!$parts || !isset($parts[0])) {
            return 'Customer';
        }

        return ucfirst(strtolower($parts[0]));
    }
}

if (!function_exists('customerLogin')) {
    function customerLogin(int $customerId): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        session_regenerate_id(true);
        $_SESSION['customer_id'] = $customerId;
    }
}

if (!function_exists('customerLogout')) {
    function customerLogout(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        unset($_SESSION['customer_id']);
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_regenerate_id(true);
    }
}

if (!function_exists('requireCustomerAuthentication')) {
    function requireCustomerAuthentication(): void
    {
        if (getAuthenticatedCustomer() !== null) {
            return;
        }

        http_response_code(302);
        $loginUrl = orderingUrl('login.php');
        $currentUrl = absoluteUrl($_SERVER['REQUEST_URI'] ?? '');
        if ($currentUrl !== '') {
            $loginUrl .= (strpos($loginUrl, '?') === false ? '?' : '&') . 'redirect=' . urlencode($currentUrl);
        }
        header('Location: ' . $loginUrl);
        exit;
    }
}

if (!function_exists('customerSessionExport')) {
    function customerSessionExport(): array
    {
        $customer = getAuthenticatedCustomer();
        if (!$customer) {
            return [
                'authenticated' => false,
                'firstName' => null,
            ];
        }

        return [
            'authenticated' => true,
            'firstName' => $customer['first_name'] ?? extractCustomerFirstName($customer['full_name'] ?? ''),
        ];
    }
}
