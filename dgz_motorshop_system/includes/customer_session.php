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

if (!function_exists('dgzCustomerSessionCache')) {
    /**
     * Internal utility to hold the authenticated customer cache by reference.
     */
    function &dgzCustomerSessionCache(): mixed
    {
        static $cache = false;

        return $cache;
    }
}

if (!function_exists('customerTableDescribe')) {
    function customerTableDescribe(PDO $pdo): array
    {
        static $cache;
        if ($cache !== null) {
            return $cache;
        }

        try {
            $stmt = $pdo->query('SHOW COLUMNS FROM customers');
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (Throwable $exception) {
            error_log('Unable to inspect customers table: ' . $exception->getMessage());
            $rows = [];
        }

        $cache = [];
        foreach ($rows as $row) {
            if (!isset($row['Field'])) {
                continue;
            }
            $cache[strtolower((string) $row['Field'])] = (string) $row['Field'];
        }

        return $cache;
    }
}

if (!function_exists('customerFindColumn')) {
    function customerFindColumn(PDO $pdo, array $candidates): ?string
    {
        $columns = customerTableDescribe($pdo);
        foreach ($candidates as $candidate) {
            $normalized = strtolower($candidate);
            if (isset($columns[$normalized])) {
                return $columns[$normalized];
            }
        }

        return null;
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
        $cache = &dgzCustomerSessionCache();
        if ($cache !== false) {
            return $cache;
        }

        if (empty($_SESSION['customer_id'])) {
            $cache = null;
            return $cache;
        }

        $customerId = (int) $_SESSION['customer_id'];

        try {
            $pdo = customerRepository();

            $baseColumns = ['id', 'full_name', 'email', 'phone'];
            $optional = [
                'address_line1', 'address', 'address1', 'street',
                'city', 'town', 'municipality',
                'postal_code', 'postal', 'zip_code', 'zipcode', 'zip',
                'facebook_account', 'facebook', 'fb_account',
                'first_name', 'firstname', 'given_name',
                'middle_name', 'middlename', 'middle',
                'last_name', 'lastname', 'surname', 'family_name',
                'email_verified_at', 'verified_at',
                'verification_token', 'email_verification_token',
            ];

            $columnList = $baseColumns;
            foreach ($optional as $candidate) {
                $found = customerFindColumn($pdo, [$candidate]);
                if ($found !== null && !in_array($found, $columnList, true)) {
                    $columnList[] = $found;
                }
            }

            $projection = implode(', ', array_map(static function (string $column): string {
                return '`' . $column . '`';
            }, $columnList));

            $stmt = $pdo->prepare('SELECT ' . $projection . ' FROM customers WHERE id = ? LIMIT 1');
            $stmt->execute([$customerId]);
            $customer = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $exception) {
            error_log('Unable to load authenticated customer: ' . $exception->getMessage());
            $customer = null;
        }

        if ($customer) {
            // Normalise address-related aliases so downstream code can rely on the modern keys
            $addressAliases = ['address_line1', 'address', 'address1', 'street'];
            foreach ($addressAliases as $alias) {
                if (isset($customer[$alias]) && trim((string) $customer[$alias]) !== '') {
                    $customer['address_line1'] = $customer[$alias];
                    break;
                }
            }

            $cityAliases = ['city', 'town', 'municipality'];
            foreach ($cityAliases as $alias) {
                if (isset($customer[$alias]) && trim((string) $customer[$alias]) !== '') {
                    $customer['city'] = $customer[$alias];
                    break;
                }
            }

            $postalAliases = ['postal_code', 'postal', 'zip_code', 'zipcode', 'zip'];
            foreach ($postalAliases as $alias) {
                if (isset($customer[$alias]) && trim((string) $customer[$alias]) !== '') {
                    $customer['postal_code'] = $customer[$alias];
                    break;
                }
            }

            $facebookAliases = ['facebook_account', 'facebook', 'fb_account'];
            foreach ($facebookAliases as $alias) {
                if (isset($customer[$alias]) && trim((string) $customer[$alias]) !== '') {
                    $customer['facebook_account'] = $customer[$alias];
                    break;
                }
            }

            $firstNameAlias = customerFindColumn($pdo, ['first_name', 'firstname', 'given_name']);
            if ($firstNameAlias !== null && isset($customer[$firstNameAlias])) {
                $customer['first_name'] = trim((string) $customer[$firstNameAlias]);
            }

            $middleNameAlias = customerFindColumn($pdo, ['middle_name', 'middlename', 'middle']);
            if ($middleNameAlias !== null && isset($customer[$middleNameAlias])) {
                $customer['middle_name'] = trim((string) $customer[$middleNameAlias]);
            }

            $lastNameAlias = customerFindColumn($pdo, ['last_name', 'lastname', 'surname', 'family_name']);
            if ($lastNameAlias !== null && isset($customer[$lastNameAlias])) {
                $customer['last_name'] = trim((string) $customer[$lastNameAlias]);
            }

            $emailVerifiedAlias = customerFindColumn($pdo, ['email_verified_at', 'verified_at']);
            if ($emailVerifiedAlias !== null && array_key_exists($emailVerifiedAlias, $customer)) {
                $customer['email_verified_at'] = $customer[$emailVerifiedAlias];
            }

            $nameParts = [];
            foreach (['first_name', 'middle_name', 'last_name'] as $nameKey) {
                $part = trim((string) ($customer[$nameKey] ?? ''));
                if ($part !== '') {
                    $nameParts[] = $part;
                }
            }

            if (!isset($customer['full_name']) || trim((string) $customer['full_name']) === '') {
                $assembledName = trim(implode(' ', $nameParts));
                if ($assembledName !== '') {
                    $customer['full_name'] = $assembledName;
                }
            }

            if (!isset($customer['first_name']) || $customer['first_name'] === '') {
                $customer['first_name'] = extractCustomerFirstName($customer['full_name'] ?? '');
            }

            $customer['address_completed'] = customerAddressCompleted($customer);
        }

        if ($customer === null) {
            unset($_SESSION['customer_id']);
        }

        $cache = $customer;
        return $cache;
    }
}

if (!function_exists('customerAddressCompleted')) {
    function customerAddressCompleted(array $customer): bool
    {
        $addressAliases = ['address_line1', 'address', 'address1', 'street'];
        $cityAliases = ['city', 'town', 'municipality'];
        $postalAliases = ['postal_code', 'postal', 'zip_code', 'zipcode', 'zip'];

        $address = '';
        foreach ($addressAliases as $alias) {
            $candidate = trim((string)($customer[$alias] ?? ''));
            if ($candidate !== '') {
                $address = $candidate;
                break;
            }
        }

        $city = '';
        foreach ($cityAliases as $alias) {
            $candidate = trim((string)($customer[$alias] ?? ''));
            if ($candidate !== '') {
                $city = $candidate;
                break;
            }
        }

        $postalCode = '';
        foreach ($postalAliases as $alias) {
            $candidate = trim((string)($customer[$alias] ?? ''));
            if ($candidate !== '') {
                $postalCode = $candidate;
                break;
            }
        }

        return $address !== '' && $city !== '' && $postalCode !== '';
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

if (!function_exists('normalizeCustomerPhone')) {
    function normalizeCustomerPhone(string $input): string
    {
        $trimmed = trim($input);
        if ($trimmed === '') {
            return '';
        }

        $clean = preg_replace('/[^0-9+]/', '', $trimmed);
        if (!is_string($clean)) {
            return '';
        }

        $hasLeadingPlus = isset($clean[0]) && $clean[0] === '+';
        $digitsOnly = preg_replace('/[^0-9]/', '', $clean);
        if (!is_string($digitsOnly)) {
            $digitsOnly = '';
        }

        if ($digitsOnly === '') {
            return '';
        }

        return $hasLeadingPlus ? '+' . $digitsOnly : $digitsOnly;
    }
}

if (!function_exists('customerSessionTokenColumnAvailable')) {
    function customerSessionTokenColumnAvailable(?PDO $pdo = null): bool
    {
        static $available;

        if ($available !== null) {
            return $available;
        }

        try {
            $pdo = $pdo ?? customerRepository();
        } catch (Throwable $exception) {
            error_log('Unable to inspect customers table for session token support: ' . $exception->getMessage());

            return false;
        }

        $column = customerFindColumn($pdo, ['current_session_token']);
        $available = $column !== null;

        return $available;
    }
}

if (!function_exists('customerGenerateSessionToken')) {
    function customerGenerateSessionToken(): string
    {
        try {
            return bin2hex(random_bytes(32));
        } catch (Throwable $exception) {
            error_log('Unable to generate primary customer session token: ' . $exception->getMessage());
        }

        try {
            return bin2hex(random_bytes(16));
        } catch (Throwable $exception) {
            error_log('Unable to generate fallback customer session token: ' . $exception->getMessage());
        }

        return hash('sha256', microtime(true) . '-' . mt_rand());
    }
}

if (!function_exists('customerPersistSessionToken')) {
    function customerPersistSessionToken(PDO $pdo, int $customerId, ?string $token): void
    {
        $column = customerFindColumn($pdo, ['current_session_token']);
        if ($column === null) {
            return;
        }

        try {
            $sql = 'UPDATE customers SET `' . $column . '` = ? WHERE id = ?';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$token, $customerId]);
        } catch (Throwable $exception) {
            error_log('Unable to persist customer session token: ' . $exception->getMessage());
        }
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
        unset($_SESSION['customer_session_token']);

        try {
            $pdo = customerRepository();
        } catch (Throwable $exception) {
            error_log('Unable to acquire repository for customer login: ' . $exception->getMessage());
            $pdo = null;
        }

        if ($pdo instanceof PDO && customerSessionTokenColumnAvailable($pdo)) {
            $token = customerGenerateSessionToken();
            customerPersistSessionToken($pdo, $customerId, $token);
            $_SESSION['customer_session_token'] = $token;
        }

        customerSessionRefresh();
    }
}

if (!function_exists('customerLogout')) {
    function customerLogout(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $customerId = isset($_SESSION['customer_id']) ? (int) $_SESSION['customer_id'] : 0;

        try {
            $pdo = customerRepository();
        } catch (Throwable $exception) {
            $pdo = null;
        }

        if ($customerId > 0 && $pdo instanceof PDO && customerSessionTokenColumnAvailable($pdo)) {
            customerPersistSessionToken($pdo, $customerId, null);
        }

        unset($_SESSION['customer_id'], $_SESSION['customer_session_token']);

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        customerSessionRefresh();
        session_regenerate_id(true);
    }
}

if (!function_exists('customerSessionRefresh')) {
    function customerSessionRefresh(): void
    {
        $cache = &dgzCustomerSessionCache();
        $cache = false;
    }
}

if (!function_exists('customerEnforceSingleActiveSession')) {
    function customerEnforceSingleActiveSession(): void
    {
        static $enforced = false;

        if ($enforced) {
            return;
        }

        $enforced = true;

        if (empty($_SESSION['customer_id'])) {
            return;
        }

        try {
            $pdo = customerRepository();
        } catch (Throwable $exception) {
            error_log('Unable to acquire repository for session enforcement: ' . $exception->getMessage());
            return;
        }

        if (!$pdo instanceof PDO || !customerSessionTokenColumnAvailable($pdo)) {
            return;
        }

        $column = customerFindColumn($pdo, ['current_session_token']);
        if ($column === null) {
            return;
        }

        $customerId = (int) $_SESSION['customer_id'];

        try {
            $sql = 'SELECT `' . $column . '` AS current_session_token FROM customers WHERE id = ? LIMIT 1';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$customerId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $exception) {
            error_log('Unable to verify customer session token: ' . $exception->getMessage());
            return;
        }

        $storedToken = is_array($row) ? ($row['current_session_token'] ?? null) : null;
        $currentToken = $_SESSION['customer_session_token'] ?? '';

        if (!is_string($storedToken) || $storedToken === '' || !is_string($currentToken) || $currentToken === '' || !hash_equals($storedToken, $currentToken)) {
            $message = "You’ve been logged out because your account was used to sign in on another device.";

            $_SESSION = [
                'customer_forced_logout' => true,
                'customer_forced_logout_message' => $message,
            ];

            session_regenerate_id(true);

            header('Location: ' . orderingUrl('login.php'));
            exit;
        }
    }
}

customerEnforceSingleActiveSession();

if (!function_exists('customerLegacyPasswordColumnAvailable')) {
    function customerLegacyPasswordColumnAvailable(?PDO $pdo = null): bool
    {
        static $hasLegacyColumn;

        if ($hasLegacyColumn !== null) {
            return $hasLegacyColumn;
        }

        try {
            $pdo = $pdo ?? customerRepository();
            $pdo->query('SELECT `password` FROM customers LIMIT 0');
            $hasLegacyColumn = true;
        } catch (Throwable $exception) {
            $hasLegacyColumn = false;
        }

        return $hasLegacyColumn;
    }
}

if (!function_exists('customerFetchLegacyPassword')) {
    function customerFetchLegacyPassword(PDO $pdo, int $customerId): ?string
    {
        if (!customerLegacyPasswordColumnAvailable($pdo)) {
            return null;
        }

        try {
            $stmt = $pdo->prepare('SELECT `password` FROM customers WHERE id = ? LIMIT 1');
            $stmt->execute([$customerId]);
            $value = $stmt->fetchColumn();
            if ($value === false) {
                return null;
            }

            $value = trim((string) $value);
            return $value === '' ? null : $value;
        } catch (Throwable $exception) {
            error_log('Unable to fetch legacy customer password: ' . $exception->getMessage());
            return null;
        }
    }
}

if (!function_exists('customerPersistPasswordHash')) {
    function customerPersistPasswordHash(PDO $pdo, int $customerId, string $passwordHash): void
    {
        try {
            $update = $pdo->prepare('UPDATE customers SET password_hash = ?, updated_at = COALESCE(updated_at, CURRENT_TIMESTAMP) WHERE id = ?');
            $update->execute([$passwordHash, $customerId]);

            if (customerLegacyPasswordColumnAvailable($pdo)) {
                $legacy = $pdo->prepare('UPDATE customers SET `password` = ? WHERE id = ?');
                $legacy->execute([$passwordHash, $customerId]);
            }
        } catch (Throwable $exception) {
            error_log('Unable to persist customer password hash: ' . $exception->getMessage());
        }
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
            'addressCompleted' => (bool) ($customer['address_completed'] ?? false),
            'emailVerified' => isset($customer['email_verified_at']) && trim((string) $customer['email_verified_at']) !== '',
        ];
    }
}
