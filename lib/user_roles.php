<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/db_compat.php';

/**
 * Role labels used in badges and switchers.
 *
 * @return array<string,string>
 */
function panicRoleLabels(): array {
    return [
        'admin' => 'Admin',
        'band' => 'Band',
        'venue' => 'Venue',
        'promoter' => 'Promoter',
        'agent' => 'Agent',
        'recording_label' => 'Recording Label',
    ];
}

function panicRoleLabel(string $role): string {
    $labels = panicRoleLabels();
    return $labels[$role] ?? ucwords(str_replace('_', ' ', $role));
}

function panicEnsureUserRoleMembershipsTable(PDO $pdo): void {
    if (panicDbIsMysql($pdo)) {
        $pdo->exec("\n            CREATE TABLE IF NOT EXISTS user_role_memberships (\n              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,\n              account_user_id BIGINT UNSIGNED NOT NULL,\n              target_user_id BIGINT UNSIGNED NOT NULL,\n              role_type VARCHAR(64) NOT NULL,\n              is_primary TINYINT(1) NOT NULL DEFAULT 0,\n              created_at DATETIME DEFAULT CURRENT_TIMESTAMP,\n              updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n              UNIQUE KEY uq_account_target_role (account_user_id, target_user_id, role_type),\n              KEY idx_urm_account (account_user_id),\n              KEY idx_urm_target (target_user_id),\n              CONSTRAINT fk_urm_account FOREIGN KEY (account_user_id) REFERENCES users(id) ON DELETE CASCADE,\n              CONSTRAINT fk_urm_target FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE CASCADE\n            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4\n        ");
        return;
    }

    $pdo->exec("\n        CREATE TABLE IF NOT EXISTS user_role_memberships (\n          id INTEGER PRIMARY KEY AUTOINCREMENT,\n          account_user_id INTEGER NOT NULL,\n          target_user_id INTEGER NOT NULL,\n          role_type TEXT NOT NULL,\n          is_primary INTEGER NOT NULL DEFAULT 0,\n          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,\n          updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,\n          UNIQUE(account_user_id, target_user_id, role_type),\n          FOREIGN KEY(account_user_id) REFERENCES users(id) ON DELETE CASCADE,\n          FOREIGN KEY(target_user_id) REFERENCES users(id) ON DELETE CASCADE\n        )\n    ");

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_urm_account ON user_role_memberships(account_user_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_urm_target ON user_role_memberships(target_user_id)');
}

function panicEnsureSelfRoleMembership(PDO $pdo, int $userId, string $type): void {
    if ($userId <= 0 || trim($type) === '') {
        return;
    }

    if (panicDbIsMysql($pdo)) {
        $stmt = $pdo->prepare("\n            INSERT INTO user_role_memberships (account_user_id, target_user_id, role_type, is_primary)\n            VALUES (?, ?, ?, 1)\n            ON DUPLICATE KEY UPDATE\n                is_primary = CASE WHEN is_primary = 1 THEN 1 ELSE VALUES(is_primary) END\n        ");
        $stmt->execute([$userId, $userId, $type]);
        return;
    }

    $stmt = $pdo->prepare("\n        INSERT INTO user_role_memberships (account_user_id, target_user_id, role_type, is_primary)\n        VALUES (?, ?, ?, 1)\n        ON CONFLICT(account_user_id, target_user_id, role_type) DO UPDATE SET\n            is_primary = CASE WHEN user_role_memberships.is_primary = 1 THEN 1 ELSE excluded.is_primary END\n    ");
    $stmt->execute([$userId, $userId, $type]);
}

function panicTableExists(PDO $pdo, string $tableName): bool {
    if ($tableName === '') {
        return false;
    }

    if (panicDbIsMysql($pdo)) {
        $stmt = $pdo->prepare("\n            SELECT 1\n            FROM information_schema.tables\n            WHERE table_schema = DATABASE()\n              AND table_name = :table_name\n            LIMIT 1\n        ");
        $stmt->execute([':table_name' => $tableName]);
        return (bool)$stmt->fetchColumn();
    }

    $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :table_name LIMIT 1");
    $stmt->execute([':table_name' => $tableName]);
    return (bool)$stmt->fetchColumn();
}

/**
 * @return array<int,array<string,mixed>>
 */
function panicLoadRoleContexts(PDO $pdo, int $accountUserId): array {
    $nameExpr = panicSqlJsonTextExpr($pdo, 'p.data', '$.name');

    $stmt = $pdo->prepare("\n        SELECT urm.target_user_id,\n               urm.role_type,\n               urm.is_primary,\n               u.email AS target_email,\n               u.type AS target_type,\n               {$nameExpr} AS profile_name\n        FROM user_role_memberships urm\n        JOIN users u ON u.id = urm.target_user_id\n        LEFT JOIN profiles p ON p.user_id = u.id\n        WHERE urm.account_user_id = :account_user_id\n        ORDER BY urm.is_primary DESC, LOWER(COALESCE({$nameExpr}, u.email)) ASC, urm.target_user_id ASC\n    ");
    $stmt->execute([':account_user_id' => $accountUserId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Existing data model: users can be linked to multiple band entities via band_memberships.
    // Surface those as additional switchable band contexts automatically.
    if (panicTableExists($pdo, 'band_memberships')) {
        $membershipStmt = $pdo->prepare("\n            SELECT p.user_id AS target_user_id,\n                   'band' AS role_type,\n                   0 AS is_primary,\n                   u.email AS target_email,\n                   u.type AS target_type,\n                   {$nameExpr} AS profile_name\n            FROM band_memberships bm\n            JOIN profiles p ON p.id = bm.band_id\n            JOIN users u ON u.id = p.user_id\n            WHERE bm.user_id = :account_user_id\n              AND bm.status = 'active'\n              AND p.type = 'band'\n            ORDER BY LOWER(COALESCE({$nameExpr}, u.email)) ASC, p.user_id ASC\n        ");
        $membershipStmt->execute([':account_user_id' => $accountUserId]);
        $rows = array_merge($rows, $membershipStmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    $contexts = [];
    $seen = [];
    foreach ($rows as $row) {
        $targetId = (int)($row['target_user_id'] ?? 0);
        $roleType = trim((string)($row['role_type'] ?? ''));
        $targetType = trim((string)($row['target_type'] ?? ''));
        if ($targetId <= 0) {
            continue;
        }
        if ($roleType === '') {
            $roleType = $targetType;
        }
        if ($roleType === '') {
            continue;
        }

        $key = $roleType . ':' . $targetId;
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;

        $displayName = trim((string)($row['profile_name'] ?? ''));
        if ($displayName === '') {
            $displayName = (string)($row['target_email'] ?? '');
        }

        $contexts[] = [
            'key' => $key,
            'target_user_id' => $targetId,
            'type' => $roleType,
            'target_type' => $targetType,
            'email' => (string)($row['target_email'] ?? ''),
            'name' => $displayName,
            'is_primary' => !empty($row['is_primary']),
            'is_self' => ($targetId === $accountUserId),
        ];
    }

    return $contexts;
}

/**
 * @return array<string,mixed>
 */
function panicBuildSessionContext(PDO $pdo, int $accountUserId, bool $isAdmin = false, ?string $preferredRoleKey = null): array {
    panicEnsureUserRoleMembershipsTable($pdo);

    $accountStmt = $pdo->prepare('SELECT id, email, type, is_admin FROM users WHERE id = ? LIMIT 1');
    $accountStmt->execute([$accountUserId]);
    $account = $accountStmt->fetch(PDO::FETCH_ASSOC);
    if (!$account) {
        throw new RuntimeException('Account user not found');
    }

    $accountType = (string)($account['type'] ?? '');
    $accountEmail = (string)($account['email'] ?? '');
    $isAdminResolved = $isAdmin || !empty($account['is_admin']);

    if ($accountType !== '') {
        panicEnsureSelfRoleMembership($pdo, $accountUserId, $accountType);
    }

    $contexts = panicLoadRoleContexts($pdo, $accountUserId);
    if (empty($contexts)) {
        $contexts[] = [
            'key' => $accountType . ':' . $accountUserId,
            'target_user_id' => $accountUserId,
            'type' => $accountType,
            'target_type' => $accountType,
            'email' => $accountEmail,
            'name' => $accountEmail,
            'is_primary' => true,
            'is_self' => true,
        ];
    }

    $contextMap = [];
    foreach ($contexts as $ctx) {
        $contextMap[(string)$ctx['key']] = $ctx;
    }

    $active = null;
    $preferredRoleKey = trim((string)$preferredRoleKey);
    if ($preferredRoleKey !== '' && isset($contextMap[$preferredRoleKey])) {
        $active = $contextMap[$preferredRoleKey];
    }

    if ($active === null) {
        foreach ($contexts as $ctx) {
            if (!empty($ctx['is_self']) && ((string)$ctx['type']) === $accountType) {
                $active = $ctx;
                break;
            }
        }
    }

    if ($active === null) {
        $active = $contexts[0];
    }

    $badgeSet = [];
    if ($isAdminResolved) {
        $badgeSet['admin'] = true;
    }
    foreach ($contexts as $ctx) {
        $t = trim((string)($ctx['type'] ?? ''));
        if ($t !== '') {
            $badgeSet[$t] = true;
        }
    }

    return [
        'auth_user_id' => $accountUserId,
        'auth_user_email' => $accountEmail,
        'auth_user_type' => $accountType,
        'user_is_admin' => $isAdminResolved,
        'active_role_key' => (string)$active['key'],
        'active_user_id' => (int)$active['target_user_id'],
        'active_user_email' => (string)$active['email'],
        'active_user_type' => (string)$active['type'],
        'role_contexts' => array_values($contexts),
        'role_badges' => array_keys($badgeSet),
    ];
}

/**
 * @param array<string,mixed> $ctx
 */
function panicWriteSessionContext(array $ctx): void {
    $_SESSION['auth_user_id'] = (int)($ctx['auth_user_id'] ?? 0);
    $_SESSION['auth_user_email'] = (string)($ctx['auth_user_email'] ?? '');
    $_SESSION['auth_user_type'] = (string)($ctx['auth_user_type'] ?? '');

    $_SESSION['active_role_key'] = (string)($ctx['active_role_key'] ?? '');
    $_SESSION['active_user_id'] = (int)($ctx['active_user_id'] ?? 0);
    $_SESSION['active_user_email'] = (string)($ctx['active_user_email'] ?? '');
    $_SESSION['active_user_type'] = (string)($ctx['active_user_type'] ?? '');

    $_SESSION['user_role_contexts'] = is_array($ctx['role_contexts'] ?? null) ? $ctx['role_contexts'] : [];
    $_SESSION['user_role_badges'] = is_array($ctx['role_badges'] ?? null) ? $ctx['role_badges'] : [];

    // Backward-compatible aliases used throughout existing app/API code.
    $_SESSION['user_id'] = (int)($_SESSION['active_user_id'] ?? 0);
    $_SESSION['user_email'] = (string)($_SESSION['active_user_email'] ?? '');
    $_SESSION['user_type'] = (string)($_SESSION['active_user_type'] ?? '');
    $_SESSION['user_is_admin'] = !empty($ctx['user_is_admin']);
}

function panicSessionHasRoleContextData(): bool {
    return isset($_SESSION['auth_user_id']) && isset($_SESSION['active_user_id']) && is_array($_SESSION['user_role_contexts'] ?? null);
}

function panicHydrateLegacySessionFields(): void {
    if (isset($_SESSION['auth_user_id']) || !isset($_SESSION['user_id'])) {
        return;
    }

    $legacyUserId = (int)($_SESSION['user_id'] ?? 0);
    $legacyEmail = (string)($_SESSION['user_email'] ?? '');
    $legacyType = (string)($_SESSION['user_type'] ?? '');

    $_SESSION['auth_user_id'] = $legacyUserId;
    $_SESSION['auth_user_email'] = $legacyEmail;
    $_SESSION['auth_user_type'] = $legacyType;

    $_SESSION['active_user_id'] = $legacyUserId;
    $_SESSION['active_user_email'] = $legacyEmail;
    $_SESSION['active_user_type'] = $legacyType;
    $_SESSION['active_role_key'] = ($legacyType !== '' ? $legacyType : 'user') . ':' . $legacyUserId;
    $_SESSION['user_role_contexts'] = [[
        'key' => $_SESSION['active_role_key'],
        'target_user_id' => $legacyUserId,
        'type' => $legacyType,
        'target_type' => $legacyType,
        'email' => $legacyEmail,
        'name' => $legacyEmail,
        'is_primary' => true,
        'is_self' => true,
    ]];
    $_SESSION['user_role_badges'] = !empty($_SESSION['user_is_admin'])
        ? ['admin', $legacyType]
        : [$legacyType];
}
