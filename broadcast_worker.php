<?php
declare(strict_types=1);

/**
 * Maxgram Enterprise - Background Broadcast Daemon Worker
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/TelegramAPI.php';

use TeleMaker\Database;
use TeleMaker\TelegramAPI;

$config = require __DIR__ . '/config.php';
$pdo = Database::getConnection($config['db_path']);
$masterTg = new TelegramAPI($config['bot_token']);

$batch = Database::getPendingQueueBatch($pdo, 25);
if (empty($batch)) {
    if (php_sapi_name() === 'cli') {
        echo "No pending broadcast items.\n";
    }
    exit(0);
}

foreach ($batch as $item) {
    $queueId = (int)$item['id'];
    $bId     = (int)$item['broadcast_id'];
    $userId  = (int)$item['user_id'];
    $text    = $item['message_text'];

    try {
        $masterTg->sendMessage($userId, $text);
        Database::markQueueItemSuccess($pdo, $queueId, $bId);
    } catch (\RuntimeException $e) {
        $msg = $e->getMessage();
        if (str_starts_with($msg, 'FLOOD_WAIT:')) {
            $retryAfter = (int)str_replace('FLOOD_WAIT:', '', $msg);
            Database::markQueueItemRetry($pdo, $queueId, max($retryAfter, 5));
        } else {
            Database::markQueueItemFailed($pdo, $queueId, $bId, $msg);
        }
    } catch (\Exception $e) {
        Database::markQueueItemFailed($pdo, $queueId, $bId, $e->getMessage());
    }

    usleep(35000); // 35ms throttling delay
}

if (php_sapi_name() === 'cli') {
    echo "Processed batch of " . count($batch) . " broadcast messages successfully.\n";
}
