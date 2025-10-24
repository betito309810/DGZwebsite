<?php

if (!function_exists('catalogTaxonomyNormaliseName')) {
    function catalogTaxonomyNormaliseName(string $name): string
    {
        $normalised = trim(preg_replace('/\s+/u', ' ', (string) $name));
        $lower = function_exists('mb_strtolower')
            ? mb_strtolower($normalised, 'UTF-8')
            : strtolower($normalised);
        return $lower;
    }
}

if (!function_exists('catalogTaxonomyEnsureSchema')) {
    function catalogTaxonomyEnsureSchema(PDO $pdo): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }

        $ddl = <<<SQL
CREATE TABLE IF NOT EXISTS product_taxonomies (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(30) NOT NULL,
    name VARCHAR(190) NOT NULL,
    normalized_name VARCHAR(190) NOT NULL,
    is_archived TINYINT(1) NOT NULL DEFAULT 0,
    archived_at DATETIME NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_product_taxonomies_type_name (type, normalized_name),
    KEY idx_product_taxonomies_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

        try {
            $pdo->exec($ddl);
        } catch (Throwable $e) {
            error_log('Failed to ensure product_taxonomies table: ' . $e->getMessage());
        }

        // Guard columns individually in case older deployments already created the table partially.
        $ensureColumn = static function (string $column, string $definition) use ($pdo): void {
            try {
                $statement = $pdo->prepare('SHOW COLUMNS FROM product_taxonomies LIKE ?');
                $statement->execute([$column]);
                $exists = $statement->fetch(PDO::FETCH_ASSOC);
                if ($exists) {
                    return;
                }
                $pdo->exec("ALTER TABLE product_taxonomies ADD COLUMN {$definition}");
            } catch (Throwable $e) {
                error_log('Failed ensuring column ' . $column . ' on product_taxonomies: ' . $e->getMessage());
            }
        };

        $ensureColumn('normalized_name', 'VARCHAR(190) NOT NULL AFTER name');
        $ensureColumn('is_archived', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER normalized_name');
        $ensureColumn('archived_at', 'DATETIME NULL DEFAULT NULL AFTER is_archived');
        $ensureColumn('created_at', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER archived_at');
        $ensureColumn('updated_at', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at');

        try {
            $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_product_taxonomies_type_name ON product_taxonomies (type, normalized_name)');
        } catch (Throwable $e) {
            // Some MySQL versions do not support the IF NOT EXISTS clause for indexes; fall back to manual detection.
            try {
                $statement = $pdo->query("SHOW INDEX FROM product_taxonomies WHERE Key_name = 'uq_product_taxonomies_type_name'");
                $indexExists = $statement && $statement->fetch(PDO::FETCH_ASSOC);
                if (!$indexExists) {
                    $pdo->exec('ALTER TABLE product_taxonomies ADD UNIQUE INDEX uq_product_taxonomies_type_name (type, normalized_name)');
                }
            } catch (Throwable $inner) {
                error_log('Failed ensuring unique index for product_taxonomies: ' . $inner->getMessage());
            }
        }

        try {
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_product_taxonomies_type ON product_taxonomies (type)');
        } catch (Throwable $e) {
            try {
                $statement = $pdo->query("SHOW INDEX FROM product_taxonomies WHERE Key_name = 'idx_product_taxonomies_type'");
                $indexExists = $statement && $statement->fetch(PDO::FETCH_ASSOC);
                if (!$indexExists) {
                    $pdo->exec('ALTER TABLE product_taxonomies ADD INDEX idx_product_taxonomies_type (type)');
                }
            } catch (Throwable $inner) {
                error_log('Failed ensuring taxonomy type index: ' . $inner->getMessage());
            }
        }

        $ensured = true;
    }
}

if (!function_exists('catalogTaxonomyEnsureTerm')) {
    function catalogTaxonomyEnsureTerm(PDO $pdo, string $type, ?string $name, bool $reactivate = true): ?int
    {
        catalogTaxonomyEnsureSchema($pdo);

        $typeKey = strtolower(trim($type));
        if (!in_array($typeKey, ['brand', 'category', 'supplier'], true)) {
            return null;
        }

        $label = trim((string) $name);
        if ($label === '') {
            return null;
        }

        $normalized = catalogTaxonomyNormaliseName($label);

        try {
            $select = $pdo->prepare('SELECT id, name, is_archived FROM product_taxonomies WHERE type = ? AND normalized_name = ? LIMIT 1');
            $select->execute([$typeKey, $normalized]);
            $row = $select->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                $updates = [];
                $params = [];

                if ((string) ($row['name'] ?? '') !== $label) {
                    $updates[] = 'name = ?';
                    $params[] = $label;
                }

                if ($reactivate && (int) ($row['is_archived'] ?? 0) === 1) {
                    $updates[] = 'is_archived = 0';
                    $updates[] = 'archived_at = NULL';
                }

                if ($updates) {
                    $params[] = (int) $row['id'];
                    $sql = 'UPDATE product_taxonomies SET ' . implode(', ', $updates) . ' WHERE id = ?';
                    $pdo->prepare($sql)->execute($params);
                }

                return (int) $row['id'];
            }

            $insert = $pdo->prepare('INSERT INTO product_taxonomies (type, name, normalized_name) VALUES (?, ?, ?)');
            $insert->execute([$typeKey, $label, $normalized]);
            return (int) $pdo->lastInsertId();
        } catch (Throwable $e) {
            error_log('Failed ensuring taxonomy term: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('catalogTaxonomyBackfillFromProducts')) {
    function catalogTaxonomyBackfillFromProducts(PDO $pdo): void
    {
        catalogTaxonomyEnsureSchema($pdo);

        $mapping = [
            'brand' => 'brand',
            'category' => 'category',
            'supplier' => 'supplier',
        ];

        foreach ($mapping as $type => $column) {
            try {
                $sql = sprintf('SELECT DISTINCT %s FROM products WHERE %s IS NOT NULL AND %s != ""', $column, $column, $column);
                $rows = $pdo->query($sql);
            } catch (Throwable $e) {
                continue;
            }

            if (!$rows) {
                continue;
            }

            foreach ($rows->fetchAll(PDO::FETCH_COLUMN) as $value) {
                catalogTaxonomyEnsureTerm($pdo, $type, (string) $value);
            }
        }
    }
}

if (!function_exists('catalogTaxonomyFetchOptions')) {
    function catalogTaxonomyFetchOptions(PDO $pdo, string $type, bool $includeArchived = false): array
    {
        catalogTaxonomyEnsureSchema($pdo);

        $typeKey = strtolower(trim($type));
        if (!in_array($typeKey, ['brand', 'category', 'supplier'], true)) {
            return [];
        }

        $conditions = ['type = :type'];
        $params = [':type' => $typeKey];

        if (!$includeArchived) {
            $conditions[] = 'is_archived = 0';
        }

        try {
            $sql = 'SELECT id, name, is_archived FROM product_taxonomies WHERE ' . implode(' AND ', $conditions) . ' ORDER BY name';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('Failed fetching taxonomy options: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('catalogTaxonomyFetchWithUsage')) {
    function catalogTaxonomyFetchWithUsage(PDO $pdo, string $type): array
    {
        catalogTaxonomyEnsureSchema($pdo);

        $typeKey = strtolower(trim($type));
        if (!in_array($typeKey, ['brand', 'category', 'supplier'], true)) {
            return [];
        }

        try {
            $stmt = $pdo->prepare('SELECT id, name, is_archived, archived_at, created_at, normalized_name FROM product_taxonomies WHERE type = :type ORDER BY is_archived ASC, name ASC');
            $stmt->execute([':type' => $typeKey]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) {
                return [];
            }

            $column = $typeKey;
            $counts = [];
            try {
                $valueStmt = $pdo->query('SELECT ' . $column . ' AS taxonomy_value FROM products');
                if ($valueStmt) {
                    while ($valueRow = $valueStmt->fetch(PDO::FETCH_ASSOC)) {
                        $rawValue = $valueRow['taxonomy_value'] ?? '';
                        $normalized = catalogTaxonomyNormaliseName((string) $rawValue);
                        if ($normalized === '') {
                            continue;
                        }
                        if (!isset($counts[$normalized])) {
                            $counts[$normalized] = 0;
                        }
                        $counts[$normalized]++;
                    }
                }
            } catch (Throwable $countError) {
                error_log('Failed computing taxonomy usage counts: ' . $countError->getMessage());
            }

            foreach ($rows as &$row) {
                $normalizedName = (string) ($row['normalized_name'] ?? '');
                $row['usage_count'] = $counts[$normalizedName] ?? 0;
            }
            unset($row);

            return $rows;
        } catch (Throwable $e) {
            error_log('Failed fetching taxonomy usage: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('catalogTaxonomyArchiveTerm')) {
    function catalogTaxonomyArchiveTerm(PDO $pdo, int $id, bool $archive): bool
    {
        catalogTaxonomyEnsureSchema($pdo);

        $flag = $archive ? 1 : 0;
        $archivedAt = $archive ? (new DateTimeImmutable())->format('Y-m-d H:i:s') : null;

        try {
            $stmt = $pdo->prepare('UPDATE product_taxonomies SET is_archived = ?, archived_at = ? WHERE id = ?');
            return $stmt->execute([$flag, $archivedAt, $id]);
        } catch (Throwable $e) {
            error_log('Failed toggling taxonomy archive flag: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('catalogTaxonomyOptionLabels')) {
    function catalogTaxonomyOptionLabels(PDO $pdo, string $type, bool $includeArchived = false): array
    {
        $rows = catalogTaxonomyFetchOptions($pdo, $type, $includeArchived);
        $labels = [];
        foreach ($rows as $row) {
            $labels[] = (string) ($row['name'] ?? '');
        }
        return $labels;
    }
}

if (!function_exists('catalogTaxonomyMarkUsage')) {
    function catalogTaxonomyMarkUsage(PDO $pdo, array $values): void
    {
        catalogTaxonomyEnsureSchema($pdo);

        foreach ($values as $type => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            catalogTaxonomyEnsureTerm($pdo, $type, (string) $value, false);
        }
    }
}

?>
