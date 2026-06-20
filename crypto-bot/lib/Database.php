<?php
class Database {
    private ?PDO $pdo = null;

    public function __construct() {
        $this->init();
    }

    private function getDb(): PDO {
        if ($this->pdo === null) {
            $this->pdo = new PDO('sqlite:' . DB_PATH);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->exec('PRAGMA journal_mode=WAL');
        }
        return $this->pdo;
    }

    private function init(): void {
        $db = $this->getDb();
        $db->exec("
            CREATE TABLE IF NOT EXISTS users (
                user_id INTEGER PRIMARY KEY,
                username TEXT DEFAULT '',
                first_name TEXT DEFAULT '',
                last_name TEXT DEFAULT '',
                first_seen TEXT,
                last_seen TEXT,
                banned INTEGER DEFAULT 0,
                is_admin INTEGER DEFAULT 0
            )
        ");
        $db->exec("
            CREATE TABLE IF NOT EXISTS commands_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                command TEXT NOT NULL,
                timestamp TEXT DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $db->exec("
            CREATE TABLE IF NOT EXISTS watched_wallets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                address TEXT NOT NULL,
                label TEXT DEFAULT '',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(user_id, address)
            )
        ");
        $db->exec("
            CREATE TABLE IF NOT EXISTS price_alerts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                target_price REAL NOT NULL,
                direction TEXT NOT NULL DEFAULT 'below',
                triggered INTEGER NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        // Ensure admin is set
        $stmt = $db->prepare("UPDATE users SET is_admin = 1 WHERE user_id = ?");
        $stmt->execute([ADMIN_ID]);
    }

    // --- Users ---

    public function registerUser(int $userId, string $username = '', string $firstName = '', string $lastName = ''): void {
        $db = $this->getDb();
        $stmt = $db->prepare("
            INSERT INTO users (user_id, username, first_name, last_name, first_seen, last_seen)
            VALUES (?, ?, ?, ?, datetime('now'), datetime('now'))
            ON CONFLICT(user_id) DO UPDATE SET
                username = COALESCE(NULLIF(?, ''), username),
                first_name = COALESCE(NULLIF(?, ''), first_name),
                last_name = COALESCE(NULLIF(?, ''), last_name),
                last_seen = datetime('now')
        ");
        $stmt->execute([$userId, $username, $firstName, $lastName, $username, $firstName, $lastName]);
    }

    public function isBanned(int $userId): bool {
        $stmt = $this->getDb()->prepare("SELECT banned FROM users WHERE user_id = ?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (bool)$row['banned'] : false;
    }

    public function isAdmin(int $userId): bool {
        return $userId === ADMIN_ID;
    }

    public function banUser(int $userId): bool {
        $stmt = $this->getDb()->prepare("UPDATE users SET banned = 1 WHERE user_id = ?");
        $stmt->execute([$userId]);
        return $stmt->rowCount() > 0;
    }

    public function unbanUser(int $userId): bool {
        $stmt = $this->getDb()->prepare("UPDATE users SET banned = 0 WHERE user_id = ?");
        $stmt->execute([$userId]);
        return $stmt->rowCount() > 0;
    }

    public function getAllUsers(): array {
        return $this->getDb()->query("SELECT * FROM users ORDER BY last_seen DESC")->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAllUserIds(): array {
        return $this->getDb()->query("SELECT user_id FROM users WHERE banned = 0")->fetchAll(PDO::FETCH_COLUMN);
    }

    public function getUser(int $userId): ?array {
        $stmt = $this->getDb()->prepare("SELECT * FROM users WHERE user_id = ?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getStats(): array {
        $db = $this->getDb();
        return [
            'total' => $db->query("SELECT COUNT(*) FROM users")->fetchColumn(),
            'active_24h' => $db->query("SELECT COUNT(*) FROM users WHERE last_seen >= datetime('now', '-1 day')")->fetchColumn(),
            'active_7d' => $db->query("SELECT COUNT(*) FROM users WHERE last_seen >= datetime('now', '-7 days')")->fetchColumn(),
            'banned' => $db->query("SELECT COUNT(*) FROM users WHERE banned = 1")->fetchColumn(),
            'total_wallets' => $db->query("SELECT COUNT(*) FROM watched_wallets")->fetchColumn(),
            'active_alerts' => $db->query("SELECT COUNT(*) FROM price_alerts WHERE triggered = 0")->fetchColumn(),
        ];
    }

    public function getCommandStats(): array {
        return $this->getDb()->query("
            SELECT command, COUNT(*) as count
            FROM commands_log
            WHERE timestamp >= datetime('now', '-7 days')
            GROUP BY command
            ORDER BY count DESC
            LIMIT 15
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    // --- Command Logging ---

    public function logCommand(int $userId, string $command): void {
        $stmt = $this->getDb()->prepare("INSERT INTO commands_log (user_id, command) VALUES (?, ?)");
        $stmt->execute([$userId, $command]);
    }

    public function getUserCommandCount(int $userId): int {
        $stmt = $this->getDb()->prepare("SELECT COUNT(*) FROM commands_log WHERE user_id = ?");
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }

    // --- Wallets ---

    public function addWallet(int $userId, string $address, string $label = ''): bool {
        try {
            $stmt = $this->getDb()->prepare("INSERT INTO watched_wallets (user_id, address, label) VALUES (?, ?, ?)");
            return $stmt->execute([$userId, $address, $label]);
        } catch (PDOException $e) {
            if ($e->getCode() == 23000 || str_contains($e->getMessage(), 'UNIQUE')) return false;
            throw $e;
        }
    }

    public function removeWallet(int $userId, string $address): bool {
        $stmt = $this->getDb()->prepare("DELETE FROM watched_wallets WHERE user_id = ? AND address = ?");
        $stmt->execute([$userId, $address]);
        return $stmt->rowCount() > 0;
    }

    public function getWallets(int $userId): array {
        $stmt = $this->getDb()->prepare("SELECT address, label, created_at FROM watched_wallets WHERE user_id = ? ORDER BY created_at DESC");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getTotalWallets(): int {
        return (int)$this->getDb()->query("SELECT COUNT(*) FROM watched_wallets")->fetchColumn();
    }

    // --- Alerts ---

    public function addAlert(int $userId, float $targetPrice, string $direction = 'below'): void {
        $stmt = $this->getDb()->prepare("INSERT INTO price_alerts (user_id, target_price, direction) VALUES (?, ?, ?)");
        $stmt->execute([$userId, $targetPrice, $direction]);
    }

    public function getAlerts(int $userId, bool $onlyActive = true): array {
        $sql = "SELECT id, target_price, direction, triggered, created_at FROM price_alerts WHERE user_id = ?";
        if ($onlyActive) $sql .= " AND triggered = 0";
        $sql .= " ORDER BY created_at DESC";
        $stmt = $this->getDb()->prepare($sql);
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAllActiveAlerts(): array {
        return $this->getDb()->query("SELECT id, user_id, target_price, direction FROM price_alerts WHERE triggered = 0")->fetchAll(PDO::FETCH_ASSOC);
    }

    public function markAlertTriggered(int $alertId): void {
        $stmt = $this->getDb()->prepare("UPDATE price_alerts SET triggered = 1 WHERE id = ?");
        $stmt->execute([$alertId]);
    }

    public function deleteAlert(int $alertId, int $userId): bool {
        $stmt = $this->getDb()->prepare("DELETE FROM price_alerts WHERE id = ? AND user_id = ?");
        $stmt->execute([$alertId, $userId]);
        return $stmt->rowCount() > 0;
    }

    public function getTotalActiveAlerts(): int {
        return (int)$this->getDb()->query("SELECT COUNT(*) FROM price_alerts WHERE triggered = 0")->fetchColumn();
    }
}
