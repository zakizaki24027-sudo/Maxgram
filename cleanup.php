<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/Database.php';

use TeleMaker\Database;

$config = require __DIR__ . '/config.php';
$pdo = Database::getConnection($config['db_path']);

$clearedCount = Database::cleanupExpiredStates($pdo);
$now = date('Y-m-d H:i:s');

if (php_sapi_name() === 'cli') {
    echo "[$now] FSM Cleanup Completed: $clearedCount expired states cleared.\n";
    exit(0);
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok'            => true,
    'cleared_count' => $clearedCount,
    'timestamp'     => $now,
    'message'       => "Cleaned up {$clearedCount} expired user states.",
]);
