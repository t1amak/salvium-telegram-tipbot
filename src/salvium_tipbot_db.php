<?php
// src/salvium_tipbot_db.php
namespace Salvium;

use PDO;
use PDOException;

class SalviumTipBotDB {
    private PDO $pdo;

    public function __construct(array $config) {
        try {
            $dsn = "mysql:host={$config['DB_HOST']};dbname={$config['DB_NAME']};charset={$config['DB_CHARSET']}";
            $this->pdo = new PDO($dsn, $config['DB_USER'], $config['DB_PASS']);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            die("Database connection error.");
        }
    }

    // --- Table Creation SQL (Commented for reference) ---
    /*
    CREATE TABLE users (
        id INT PRIMARY KEY AUTO_INCREMENT,
        telegram_user_id BIGINT UNIQUE NOT NULL,
        salvium_subaddress VARCHAR(128) UNIQUE NOT NULL,
        tip_balance DECIMAL(20, 12) DEFAULT 0.000000000000,
        withdrawal_address VARCHAR(128),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    );

    CREATE TABLE deposits (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
        txid VARCHAR(64) UNIQUE NOT NULL,
        amount DECIMAL(20, 12) NOT NULL,
        block_height INT NOT NULL,
        timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id)
    );

    CREATE TABLE withdrawals (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
        txid VARCHAR(64),
        address VARCHAR(128) NOT NULL,
        amount DECIMAL(20, 12) NOT NULL,
        status ENUM('pending', 'sent', 'failed') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id)
    );

    CREATE TABLE tips (
        id INT PRIMARY KEY AUTO_INCREMENT,
        sender_user_id INT NOT NULL,
        recipient_user_id INT NOT NULL,
        amount DECIMAL(20, 12) NOT NULL,
        channel_id BIGINT,
        status ENUM('pending', 'credited') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (sender_user_id) REFERENCES users(id),
        FOREIGN KEY (recipient_user_id) REFERENCES users(id)
    );

    CREATE TABLE bot_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        chat_id BIGINT NOT NULL,
        chat_name VARCHAR(255),
        username VARCHAR(255),
        message TEXT,
        response TEXT,
        logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );

    */

    public function getUserByTelegramId(int $telegramUserId): array|false {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE telegram_user_id = ?");
        $stmt->execute([$telegramUserId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getUserByUsername(string $username): array|false {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function updateUsername(int $telegramUserId, string $username): void {
        // First, check if the username is already used by a different user
        $stmt = $this->pdo->prepare("SELECT telegram_user_id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && (int)$row['telegram_user_id'] !== $telegramUserId) {
            // Username is used by another user, don't overwrite
            return;
        }

        // Safe to update
        $stmt = $this->pdo->prepare("UPDATE users SET username = ? WHERE telegram_user_id = ?");
        $stmt->execute([$username, $telegramUserId]);
    }


    public function upgradeTelegramUserId(int $oldId, int $newId): bool {
        $stmt = $this->pdo->prepare("UPDATE users SET telegram_user_id = ? WHERE telegram_user_id = ?");
        return $stmt->execute([$newId, $oldId]);
    }

    public function getUserBySubaddress(string $subaddress): array|false {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE salvium_subaddress = ?");
        $stmt->execute([$subaddress]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function createUser(int $telegramUserId, string $subaddress): bool {
        $stmt = $this->pdo->prepare("INSERT INTO users (telegram_user_id, salvium_subaddress) VALUES (?, ?)");
        return $stmt->execute([$telegramUserId, $subaddress]);
    }

    public function ensureUserExists(
        int $telegramId,
        ?string $username,
        callable $subaddressGenerator,
        bool $allowSynthetic = false
    ): array {
        // 1. Try exact match by Telegram ID
        $user = $this->getUserByTelegramId($telegramId);

        // 2. Try upgrade from placeholder if matching username
        if (!$user && $username) {
            $placeholder = $this->getUserByUsername($username);

            if ($placeholder && $placeholder['telegram_user_id'] < 1_000_000 && $telegramId !== 0) {
                $this->upgradeTelegramUserId($placeholder['telegram_user_id'], $telegramId);
                $user = $this->getUserByTelegramId($telegramId);
            }
            else if ($placeholder && $placeholder['telegram_user_id'] >= 1_000_000 && $telegramId === 0) {
                $user = $this->getUserByTelegramId($placeholder['telegram_user_id']);
            }

        }


        // 3. Still not found? Possibly create new user
        if (!$user) {
            $idToUse = $telegramId;

            if ($telegramId === 0 && $username && $allowSynthetic) {
                // Create synthetic ID
                $clean = ltrim($username, '@');
                $idToUse = 100_000 + (crc32($clean) % 900_000);
            }

            $existing = $this->getUserByTelegramId($idToUse);
            if (!$existing) {
                $sub = $subaddressGenerator();
                if (!$sub) throw new RuntimeException("Failed to generate subaddress");
                $this->createUser($idToUse, $sub);
            }

            if ($username) {
                $this->updateUsername($idToUse, $username);
            }

            $user = $this->getUserByTelegramId($idToUse);
        }

        return $user;
    }

    public function updateUserTipBalance(int $userId, float $amount, string $operation = 'add'): bool {
        $sql = $operation === 'add' ? "UPDATE users SET tip_balance = tip_balance + ? WHERE id = ?" : "UPDATE users SET tip_balance = tip_balance - ? WHERE id = ?";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([$amount, $userId]);
    }

    public function setWithdrawalAddress(int $userId, string $address): bool {
        $stmt = $this->pdo->prepare("UPDATE users SET withdrawal_address = ? WHERE id = ?");
        return $stmt->execute([$address, $userId]);
    }

    public function logDeposit(int $userId, string $txid, float $amount, int $blockHeight): bool {
        $stmt = $this->pdo->prepare("INSERT INTO deposits (user_id, txid, amount, block_height) VALUES (?, ?, ?, ?)");
        return $stmt->execute([$userId, $txid, $amount, $blockHeight]);
    }

    public function isTxidLogged(string $txid): bool {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM deposits WHERE txid = ?");
        $stmt->execute([$txid]);
        return $stmt->fetchColumn() > 0;
    }

    public function logWithdrawal(int $userId, string $address, float $amount): int|false {
        $stmt = $this->pdo->prepare("INSERT INTO withdrawals (user_id, address, amount) VALUES (?, ?, ?)");
        if ($stmt->execute([$userId, $address, $amount])) {
            return $this->pdo->lastInsertId();
        }
        return false;
    }

    public function updateWithdrawalTxid(int $withdrawalId, string $txid): bool {
        $stmt = $this->pdo->prepare("UPDATE withdrawals SET txid = ? WHERE id = ?");
        return $stmt->execute([$txid, $withdrawalId]);
    }

    public function updateWithdrawalStatus(int $withdrawalId, string $status): bool {
        $stmt = $this->pdo->prepare("UPDATE withdrawals SET status = ? WHERE id = ?");
        return $stmt->execute([$status, $withdrawalId]);
    }

    public function addTip(int $senderUserId, int $recipientUserId, float $amount, ?int $channelId = null): bool {
        $stmt = $this->pdo->prepare("INSERT INTO tips (sender_user_id, recipient_user_id, amount, channel_id) VALUES (?, ?, ?, ?)");
        return $stmt->execute([$senderUserId, $recipientUserId, $amount, $channelId]);
    }

    public function getAllPendingTips(): array {
        $stmt = $this->pdo->query("SELECT * FROM tips WHERE status = 'pending'");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getPendingTipsForUser(int $recipientUserId): array {
        $stmt = $this->pdo->prepare("SELECT * FROM tips WHERE recipient_user_id = ? AND status = 'pending'");
        $stmt->execute([$recipientUserId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function markTipsAsCredited(array $tipIds): bool {
        $placeholders = implode(',', array_fill(0, count($tipIds), '?'));
        $stmt = $this->pdo->prepare("UPDATE tips SET status = 'credited' WHERE id IN ($placeholders)");
        return $stmt->execute($tipIds);
    }

    public function getPendingWithdrawals(): array {
        $stmt = $this->pdo->query("SELECT * FROM withdrawals WHERE status = 'pending'");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function logMessage(int $chatId, string $chatName, string $username, string $message, string $response): void {
        try {
            $sql = "INSERT INTO bot_log (chat_id, chat_name, username, message, response) VALUES (:chat_id, :chat_name, :username, :message, :response)";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':chat_id' => $chatId,
                ':chat_name' => $chatName,
                ':username' => $username,
                ':message' => $message,
                ':response' => $response,
            ]);
        } catch (PDOException $e) {
            error_log("Error logging message: " . $e->getMessage());
        }
    }

}
?>
