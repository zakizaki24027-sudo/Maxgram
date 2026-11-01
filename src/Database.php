<?php
declare(strict_types=1);

namespace TeleMaker;

use PDO;
use PDOException;
use RuntimeException;

class Database
{
    private static ?PDO $pdo = null;

    public static function getConnection(string $dbPath): PDO
    {
        if (self::$pdo === null) {
            $dir = dirname($dbPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            try {
                self::$pdo = new PDO("sqlite:" . $dbPath, null, null, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_TIMEOUT            => 5,
                ]);

                self::$pdo->exec("PRAGMA journal_mode = WAL;");
                self::$pdo->exec("PRAGMA busy_timeout = 5000;");
                self::$pdo->exec("PRAGMA foreign_keys = ON;");
                self::$pdo->exec("PRAGMA synchronous = NORMAL;");

                self::initSchema(self::$pdo);
                self::runMigrations(self::$pdo);
            } catch (PDOException $e) {
                throw new RuntimeException("SQLite connection failed: " . $e->getMessage(), (int)$e->getCode(), $e);
            }
        }

        return self::$pdo;
    }

    private static function initSchema(PDO $pdo): void
    {
        $sql = "
        CREATE TABLE IF NOT EXISTS users (
            user_id INTEGER PRIMARY KEY,
            username TEXT,
            full_name TEXT NOT NULL,
            language_code TEXT DEFAULT 'ar',
            state TEXT DEFAULT NULL,
            state_data TEXT DEFAULT NULL,
            state_expires_at INTEGER DEFAULT NULL,
            is_banned INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS bots (
            bot_id INTEGER PRIMARY KEY,
            bot_token TEXT UNIQUE NOT NULL,
            bot_username TEXT NOT NULL,
            bot_first_name TEXT NOT NULL,
            owner_id INTEGER NOT NULL,
            is_trial INTEGER DEFAULT 1,
            is_active INTEGER DEFAULT 1,
            max_channels INTEGER DEFAULT 3,
            plan_id TEXT DEFAULT NULL,
            plan_label TEXT DEFAULT NULL,
            plan_expires_at DATETIME DEFAULT NULL,
            status TEXT DEFAULT 'trial',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (owner_id) REFERENCES users (user_id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS bot_channels (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            bot_id INTEGER NOT NULL,
            channel_id TEXT NOT NULL,
            channel_title TEXT NOT NULL,
            channel_username TEXT,
            added_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(bot_id, channel_id),
            FOREIGN KEY (bot_id) REFERENCES bots (bot_id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS activation_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ticket_number INTEGER UNIQUE,
            user_id INTEGER NOT NULL,
            bot_id INTEGER NOT NULL,
            plan_id TEXT NOT NULL,
            plan_label TEXT NOT NULL,
            price_text TEXT NOT NULL,
            payment_method TEXT DEFAULT 'baridimob',
            receipt_note TEXT DEFAULT NULL,
            status TEXT DEFAULT 'PENDING',
            language_code TEXT DEFAULT 'ar',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            reviewed_at DATETIME DEFAULT NULL,
            FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE,
            FOREIGN KEY (bot_id) REFERENCES bots (bot_id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS rate_limits (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            action TEXT NOT NULL,
            created_at INTEGER NOT NULL
        );

        CREATE TABLE IF NOT EXISTS broadcasts (
            broadcast_id INTEGER PRIMARY KEY AUTOINCREMENT,
            sender_id INTEGER NOT NULL,
            message_text TEXT NOT NULL,
            total_recipients INTEGER DEFAULT 0,
            sent_count INTEGER DEFAULT 0,
            failed_count INTEGER DEFAULT 0,
            status TEXT DEFAULT 'queued',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            completed_at DATETIME DEFAULT NULL
        );

        CREATE TABLE IF NOT EXISTS broadcast_queue (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            broadcast_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            message_text TEXT NOT NULL,
            status TEXT DEFAULT 'pending',
            attempts INTEGER DEFAULT 0,
            retry_after INTEGER DEFAULT 0,
            error_message TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            sent_at DATETIME DEFAULT NULL,
            FOREIGN KEY (broadcast_id) REFERENCES broadcasts (broadcast_id) ON DELETE CASCADE
        );

        CREATE INDEX IF NOT EXISTS idx_bots_owner ON bots(owner_id);
        CREATE INDEX IF NOT EXISTS idx_channels_bot ON bot_channels(bot_id);
        CREATE INDEX IF NOT EXISTS idx_activations_status ON activation_requests(status);
        CREATE INDEX IF NOT EXISTS idx_rate_limits ON rate_limits(user_id, action, created_at);
        CREATE INDEX IF NOT EXISTS idx_bcast_queue_status ON broadcast_queue(status, retry_after);
        ";

        $pdo->exec($sql);
    }

    private static function runMigrations(PDO $pdo): void
    {
        try {
            $pdo->exec("ALTER TABLE users ADD COLUMN language_code TEXT DEFAULT 'ar';");
        } catch (\Exception $e) {}

        try {
            $pdo->exec("ALTER TABLE users ADD COLUMN state_expires_at INTEGER DEFAULT NULL;");
        } catch (\Exception $e) {}

        try {
            $pdo->exec("ALTER TABLE bots ADD COLUMN plan_label TEXT DEFAULT NULL;");
        } catch (\Exception $e) {}

        try {
            $pdo->exec("ALTER TABLE activation_requests ADD COLUMN language_code TEXT DEFAULT 'ar';");
        } catch (\Exception $e) {}

        try {
            $pdo->exec("ALTER TABLE activation_requests ADD COLUMN reviewed_at DATETIME DEFAULT NULL;");
        } catch (\Exception $e) {}
    }

    public static function upsertUser(PDO $pdo, int $userId, ?string $username, string $fullName, ?string $langCode = null): void
    {
        $safeLang = $langCode ? Language::sanitizeLang($langCode) : 'ar';
        $stmt = $pdo->prepare("
            INSERT INTO users (user_id, username, full_name, language_code)
            VALUES (?, ?, ?, ?)
            ON CONFLICT(user_id) DO UPDATE SET
                username = excluded.username,
                full_name = excluded.full_name
        ");
        $stmt->execute([$userId, $username, $fullName, $safeLang]);
    }

    public static function getUser(PDO $pdo, int $userId): ?array
    {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function getUserState(PDO $pdo, int $userId): array
    {
        $now = time();
        $stmt = $pdo->prepare("SELECT state, state_data, state_expires_at FROM users WHERE user_id = ?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!$row) {
            return ['state' => null, 'data' => []];
        }

        if ($row['state_expires_at'] !== null && (int)$row['state_expires_at'] < $now) {
            self::setUserState($pdo, $userId, null);
            return ['state' => null, 'data' => []];
        }

        return [
            'state' => $row['state'],
            'data'  => $row['state_data'] ? json_decode($row['state_data'], true) : [],
        ];
    }

    public static function setUserState(PDO $pdo, int $userId, ?string $state, array $data = [], int $ttlSeconds = 900): void
    {
        $expiresAt = $state !== null ? (time() + $ttlSeconds) : null;
        $stmt = $pdo->prepare("
            UPDATE users
            SET state = ?, state_data = ?, state_expires_at = ?
            WHERE user_id = ?
        ");
        $stmt->execute([
            $state,
            $state !== null ? json_encode($data, JSON_UNESCAPED_UNICODE) : null,
            $expiresAt,
            $userId,
        ]);
    }

    public static function cleanupExpiredStates(PDO $pdo): int
    {
        $now = time();
        $stmt = $pdo->prepare("
            UPDATE users
            SET state = NULL, state_data = NULL, state_expires_at = NULL
            WHERE state_expires_at IS NOT NULL AND state_expires_at < ?
        ");
        $stmt->execute([$now]);
        return $stmt->rowCount();
    }

    public static function checkRateLimit(PDO $pdo, int $userId, string $action, int $maxAttempts = 5, int $windowSeconds = 120): bool
    {
        $now = time();
        $cutoff = $now - $windowSeconds;

        $stmtDel = $pdo->prepare("DELETE FROM rate_limits WHERE user_id = ? AND action = ? AND created_at < ?");
        $stmtDel->execute([$userId, $action, $cutoff]);

        $stmtCnt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM rate_limits WHERE user_id = ? AND action = ? AND created_at >= ?");
        $stmtCnt->execute([$userId, $action, $cutoff]);
        $count = (int)$stmtCnt->fetch()['cnt'];

        if ($count >= $maxAttempts) {
            return false;
        }

        $stmtIns = $pdo->prepare("INSERT INTO rate_limits (user_id, action, created_at) VALUES (?, ?, ?)");
        $stmtIns->execute([$userId, $action, $now]);
        return true;
    }

    public static function createChildBot(
        PDO $pdo, int $botId, string $token, string $username, string $firstName, int $ownerId, int $trialMaxChannels = 3
    ): void {
        $stmt = $pdo->prepare("
            INSERT INTO bots (bot_id, bot_token, bot_username, bot_first_name, owner_id, is_trial, is_active, max_channels, status)
            VALUES (?, ?, ?, ?, ?, 1, 1, ?, 'trial')
            ON CONFLICT(bot_id) DO UPDATE SET
                bot_token = excluded.bot_token,
                bot_username = excluded.bot_username,
                bot_first_name = excluded.bot_first_name
        ");
        $stmt->execute([$botId, $token, $username, $firstName, $ownerId, $trialMaxChannels]);
    }

    public static function getBot(PDO $pdo, int $botId): ?array
    {
        $stmt = $pdo->prepare("SELECT * FROM bots WHERE bot_id = ?");
        $stmt->execute([$botId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function getUserBots(PDO $pdo, int $ownerId): array
    {
        $stmt = $pdo->prepare("SELECT * FROM bots WHERE owner_id = ? ORDER BY created_at DESC");
        $stmt->execute([$ownerId]);
        return $stmt->fetchAll();
    }

    public static function getBotChannelsCount(PDO $pdo, int $botId): int
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM bot_channels WHERE bot_id = ?");
        $stmt->execute([$botId]);
        $row = $stmt->fetch();
        return (int)($row['cnt'] ?? 0);
    }

    public static function addBotChannel(PDO $pdo, int $botId, string $channelId, string $channelTitle, ?string $channelUsername): bool
    {
        $bot = self::getBot($pdo, $botId);
        if (!$bot) {
            throw new RuntimeException("Bot not found");
        }

        $currentCount = self::getBotChannelsCount($pdo, $botId);
        if ((int)$bot['is_trial'] === 1 && $currentCount >= (int)$bot['max_channels']) {
            throw new RuntimeException("LIMIT_EXCEEDED");
        }

        $stmt = $pdo->prepare("
            INSERT OR IGNORE INTO bot_channels (bot_id, channel_id, channel_title, channel_username)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$botId, $channelId, $channelTitle, $channelUsername]);
        return true;
    }

    public static function createActivationRequest(
        PDO $pdo, int $userId, int $botId, string $planId, string $planLabel, string $priceText, ?string $receiptNote = null, string $lang = 'ar'
    ): int {
        $tNum = (int)$pdo->query("SELECT IFNULL(MAX(ticket_number), 100) + 1 AS nxt FROM activation_requests")->fetch()['nxt'];

        $stmt = $pdo->prepare("
            INSERT INTO activation_requests (ticket_number, user_id, bot_id, plan_id, plan_label, price_text, receipt_note, status, language_code)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'PENDING', ?)
        ");
        $stmt->execute([$tNum, $userId, $botId, $planId, $planLabel, $priceText, $receiptNote, $lang]);
        return $tNum;
    }

    public static function getPendingActivationRequests(PDO $pdo): array
    {
        $stmt = $pdo->prepare("
            SELECT ar.*, u.full_name, u.username AS user_uname, u.language_code AS user_lang, b.bot_username, b.bot_first_name
            FROM activation_requests ar
            JOIN users u ON ar.user_id = u.user_id
            JOIN bots b ON ar.bot_id = b.bot_id
            WHERE ar.status = 'PENDING'
            ORDER BY ar.created_at ASC
        ");
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function getActivationRequestByTicket(PDO $pdo, int $ticketNumber): ?array
    {
        $stmt = $pdo->prepare("
            SELECT ar.*, u.full_name, u.username AS user_uname, u.language_code AS user_lang, b.bot_username, b.bot_first_name, b.owner_id
            FROM activation_requests ar
            JOIN users u ON ar.user_id = u.user_id
            JOIN bots b ON ar.bot_id = b.bot_id
            WHERE ar.ticket_number = ?
        ");
        $stmt->execute([$ticketNumber]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function approveActivationPlan(PDO $pdo, int $botId, int $ticketNumber, int $durationDays = 30, string $planLabel = '1 Month'): array
    {
        $expiresAt = date('Y-m-d H:i:s', strtotime("+{$durationDays} days"));

        $pdo->beginTransaction();
        try {
            $stmtBot = $pdo->prepare("
                UPDATE bots
                SET is_trial = 0, is_active = 1, max_channels = 9999, status = 'active',
                    plan_id = ?, plan_label = ?, plan_expires_at = ?
                WHERE bot_id = ?
            ");
            $stmtBot->execute([$durationDays === 30 ? '1_month' : '2_months', $planLabel, $expiresAt, $botId]);

            $stmtReq = $pdo->prepare("
                UPDATE activation_requests
                SET status = 'APPROVED', reviewed_at = CURRENT_TIMESTAMP
                WHERE ticket_number = ?
            ");
            $stmtReq->execute([$ticketNumber]);

            $pdo->commit();
        } catch (\Exception $e) {
            $pdo->rollBack();
            throw $e;
        }

        return [
            'bot' => self::getBot($pdo, $botId),
            'request' => self::getActivationRequestByTicket($pdo, $ticketNumber),
            'expires_at' => $expiresAt,
        ];
    }

    public static function rejectActivation(PDO $pdo, int $ticketNumber): ?array
    {
        $req = self::getActivationRequestByTicket($pdo, $ticketNumber);
        if (!$req) {
            return null;
        }

        $stmt = $pdo->prepare("
            UPDATE activation_requests
            SET status = 'REJECTED', reviewed_at = CURRENT_TIMESTAMP
            WHERE ticket_number = ?
        ");
        $stmt->execute([$ticketNumber]);
        return $req;
    }

    public static function createBroadcast(PDO $pdo, int $senderId, string $messageText): int
    {
        $total = (int)$pdo->query("SELECT COUNT(*) AS cnt FROM users WHERE is_banned = 0")->fetch()['cnt'];

        $stmt = $pdo->prepare("
            INSERT INTO broadcasts (sender_id, message_text, total_recipients, status)
            VALUES (?, ?, ?, 'queued')
        ");
        $stmt->execute([$senderId, $messageText, $total]);
        $bId = (int)$pdo->lastInsertId();

        $stmtUsers = $pdo->query("SELECT user_id FROM users WHERE is_banned = 0");
        $stmtQueue = $pdo->prepare("
            INSERT INTO broadcast_queue (broadcast_id, user_id, message_text, status)
            VALUES (?, ?, ?, 'pending')
        ");

        $pdo->beginTransaction();
        while ($row = $stmtUsers->fetch()) {
            $stmtQueue->execute([$bId, (int)$row['user_id'], $messageText]);
        }
        $pdo->commit();
        return $bId;
    }

    public static function getPendingQueueBatch(PDO $pdo, int $limit = 25): array
    {
        $now = time();
        $stmt = $pdo->prepare("
            SELECT * FROM broadcast_queue
            WHERE (status = 'pending' OR (status = 'retry' AND retry_after <= ?))
            ORDER BY id ASC
            LIMIT ?
        ");
        $stmt->execute([$now, $limit]);
        return $stmt->fetchAll();
    }

    public static function markQueueItemSuccess(PDO $pdo, int $queueId, int $broadcastId): void
    {
        $stmt = $pdo->prepare("UPDATE broadcast_queue SET status = 'sent', sent_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$queueId]);

        $stmtBcast = $pdo->prepare("UPDATE broadcasts SET sent_count = sent_count + 1 WHERE broadcast_id = ?");
        $stmtBcast->execute([$broadcastId]);
    }

    public static function markQueueItemFailed(PDO $pdo, int $queueId, int $broadcastId, string $errorMessage): void
    {
        $stmt = $pdo->prepare("UPDATE broadcast_queue SET status = 'failed', error_message = ? WHERE id = ?");
        $stmt->execute([$errorMessage, $queueId]);

        $stmtBcast = $pdo->prepare("UPDATE broadcasts SET failed_count = failed_count + 1 WHERE broadcast_id = ?");
        $stmtBcast->execute([$broadcastId]);
    }

    public static function markQueueItemRetry(PDO $pdo, int $queueId, int $retryAfterSeconds): void
    {
        $retryTimestamp = time() + $retryAfterSeconds;
        $stmt = $pdo->prepare("
            UPDATE broadcast_queue
            SET status = 'retry', attempts = attempts + 1, retry_after = ?
            WHERE id = ?
        ");
        $stmt->execute([$retryTimestamp, $queueId]);
    }
}
