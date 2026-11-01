<?php
declare(strict_types=1);

/**
 * Maxgram Enterprise Webhook Handler
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/TelegramAPI.php';
require_once __DIR__ . '/src/Language.php';
require_once __DIR__ . '/src/Keyboards.php';
require_once __DIR__ . '/src/AdminHandler.php';

use TeleMaker\Database;
use TeleMaker\TelegramAPI;
use TeleMaker\Language;
use TeleMaker\Keyboards;
use TeleMaker\AdminHandler;

$config = require __DIR__ . '/config.php';

$botToken     = $config['bot_token'];
$adminIds     = $config['admin_ids'];
$dbPath       = $config['db_path'];
$trialMax     = (int)$config['trial_max_channels'];
$forceChannel = $config['force_sub_channel'];
$forceUrl     = $config['force_sub_url'];
$supportBot   = $config['support_bot_user'];

$pdo = Database::getConnection($dbPath);
$tg  = new TelegramAPI($botToken);

Database::cleanupExpiredStates($pdo);

$rawInput = file_get_contents('php://input');
$update = json_decode($rawInput, true);
if (!$update) {
    exit('OK');
}

// 1. HANDLE CALLBACK QUERIES
if (isset($update['callback_query'])) {
    $cb     = $update['callback_query'];
    $cbId   = $cb['id'];
    $userId = (int)$cb['from']['id'];
    $chatId = (int)$cb['message']['chat']['id'];
    $msgId  = (int)$cb['message']['message_id'];
    $data   = $cb['data'] ?? '';

    $userLang = Language::getUserLanguage($pdo, $userId);

    if ($data === 'menu:language') {
        $prompt = Language::trans('choose_language_prompt', [], $userLang);
        $tg->editMessageText($chatId, $msgId, $prompt, [
            'reply_markup' => json_encode(Keyboards::getLanguageSelectionMenu()),
        ]);
        $tg->answerCallbackQuery($cbId);
        exit('OK');
    }

    if (str_starts_with($data, 'set_lang:')) {
        $selectedLang = explode(':', $data)[1] ?? 'ar';
        Language::setUserLanguage($pdo, $userId, $selectedLang);
        $userLang = $selectedLang;

        $confirm = Language::trans('language_selected', [], $userLang);
        $tg->answerCallbackQuery($cbId, $confirm);

        $welcome = Language::trans('welcome_title', [], $userLang) . "\n\n"
                 . Language::trans('welcome_body', ['support_bot' => $supportBot, 'force_url' => $forceUrl], $userLang);

        $tg->editMessageText($chatId, $msgId, $welcome, [
            'reply_markup' => json_encode(Keyboards::getMainMenu(in_array($userId, $adminIds, true), $userLang)),
        ]);
        exit('OK');
    }

    if ($data === 'check:force_sub') {
        try {
            $member = $tg->getChatMember('@' . ltrim($forceChannel, '@'), $userId);
            $status = $member['result']['status'] ?? '';
            if (in_array($status, ['creator', 'administrator', 'member', 'restricted'], true)) {
                $tg->answerCallbackQuery($cbId, Language::trans('force_sub_success', [], $userLang));
                $welcome = Language::trans('welcome_title', [], $userLang) . "\n\n"
                         . Language::trans('welcome_body', ['support_bot' => $supportBot, 'force_url' => $forceUrl], $userLang);

                $tg->editMessageText($chatId, $msgId, $welcome, [
                    'reply_markup' => json_encode(Keyboards::getMainMenu(in_array($userId, $adminIds, true), $userLang)),
                ]);
                exit('OK');
            }
        } catch (\Exception $e) {}

        $tg->answerCallbackQuery($cbId, Language::trans('force_sub_failed', [], $userLang), true);
        exit('OK');
    }

    if ($data === 'menu:main') {
        $welcome = Language::trans('welcome_title', [], $userLang) . "\n\n"
                 . Language::trans('welcome_body', ['support_bot' => $supportBot, 'force_url' => $forceUrl], $userLang);

        $tg->editMessageText($chatId, $msgId, $welcome, [
            'reply_markup' => json_encode(Keyboards::getMainMenu(in_array($userId, $adminIds, true), $userLang)),
        ]);
        $tg->answerCallbackQuery($cbId);
        exit('OK');
    }

    if ($data === 'menu:create_bot') {
        $prompt = Language::trans('create_bot_instructions', [], $userLang);
        Database::setUserState($pdo, $userId, 'WAITING_FOR_TOKEN', [], 900);
        $tg->editMessageText($chatId, $msgId, $prompt);
        $tg->answerCallbackQuery($cbId);
        exit('OK');
    }

    if ($data === 'menu:my_bots') {
        $bots = Database::getUserBots($pdo, $userId);
        if (empty($bots)) {
            $msg = Language::trans('no_bots_found', [], $userLang);
            $tg->editMessageText($chatId, $msgId, $msg, [
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [['text' => Language::trans('btn_create_bot', [], $userLang), 'callback_data' => 'menu:create_bot']],
                        [['text' => Language::trans('btn_back', [], $userLang), 'callback_data' => 'menu:main']],
                    ],
                ]),
            ]);
            $tg->answerCallbackQuery($cbId);
            exit('OK');
        }

        $msg = Language::trans('my_bots_title', ['count' => count($bots)], $userLang);
        foreach ($bots as $i => $b) {
            $cnt = Database::getBotChannelsCount($pdo, (int)$b['bot_id']);
            $maxStr = $b['is_trial'] ? (string)$b['max_channels'] : Language::trans('unlimited', [], $userLang);
            $statusStr = $b['is_trial'] ? Language::trans('status_trial', [], $userLang) : Language::trans('status_pro', [], $userLang);

            $msg .= Language::trans('bot_item_format', [
                'index'            => $i + 1,
                'bot_name'         => $b['bot_first_name'],
                'bot_username'     => $b['bot_username'],
                'status_str'       => $statusStr,
                'channels_count'   => $cnt,
                'max_channels_str' => $maxStr,
            ], $userLang);
        }

        $tg->editMessageText($chatId, $msgId, $msg, [
            'reply_markup' => json_encode(Keyboards::getBotManageMenu((int)$bots[0]['bot_id'], (bool)$bots[0]['is_trial'], $userLang)),
        ]);
        $tg->answerCallbackQuery($cbId);
        exit('OK');
    }

    if ($data === 'menu:baridimob_pricing' || str_starts_with($data, 'pay:baridimob:')) {
        $targetBotId = 0;
        if (str_starts_with($data, 'pay:baridimob:')) {
            $targetBotId = (int)explode(':', $data)[2];
        } else {
            $bots = Database::getUserBots($pdo, $userId);
            $targetBotId = !empty($bots) ? (int)$bots[0]['bot_id'] : 0;
        }

        $msg = Language::trans('baridimob_title', [], $userLang) . "\n\n"
             . Language::trans('baridimob_body', ['support_bot' => $supportBot], $userLang);

        $tg->editMessageText($chatId, $msgId, $msg, [
            'reply_markup' => json_encode(Keyboards::getBaridiMobPricingMenu($targetBotId, $userLang)),
        ]);
        $tg->answerCallbackQuery($cbId);
        exit('OK');
    }

    if (str_starts_with($data, 'plan_select:')) {
        $parts = explode(':', $data);
        $bId = (int)$parts[1];
        $planKey = $parts[2];
        $plan = $config['pricing_plans'][$planKey] ?? $config['pricing_plans']['1_month'];

        Database::setUserState($pdo, $userId, 'WAITING_RECEIPT', [
            'bot_id'     => $bId,
            'plan_id'    => $plan['id'],
            'plan_label' => $plan['label'],
            'price_text' => $plan['price_text'],
            'duration'   => $plan['duration'],
        ], 1800);

        $prompt = Language::trans('receipt_prompt', [
            'plan_label' => $plan['label'],
            'price_text' => $plan['price_text'],
        ], $userLang);

        $tg->sendMessage($chatId, $prompt);
        $tg->answerCallbackQuery($cbId);
        exit('OK');
    }

    if (str_starts_with($data, 'submit_receipt:')) {
        $bId = (int)explode(':', $data)[1];
        Database::setUserState($pdo, $userId, 'WAITING_RECEIPT', [
            'bot_id'     => $bId,
            'plan_id'    => '1_month',
            'plan_label' => 'شهر (1 Month)',
            'price_text' => '250 ألف (250,000 DA)',
            'duration'   => 30,
        ], 1800);

        $prompt = Language::trans('receipt_prompt', [
            'plan_label' => 'شهر (1 Month)',
            'price_text' => '250 ألف (250,000 DA)',
        ], $userLang);

        $tg->sendMessage($chatId, $prompt);
        $tg->answerCallbackQuery($cbId);
        exit('OK');
    }

    if (str_starts_with($data, 'approve_1m:') || str_starts_with($data, 'approve_2m:') || str_starts_with($data, 'reject_pay:')) {
        if (!in_array($userId, $adminIds, true)) {
            $tg->answerCallbackQuery($cbId, "❌ Non autorisé / غير مصرح", true);
            exit('OK');
        }
        AdminHandler::handleApprovalCallback($tg, $pdo, $chatId, $msgId, $cbId, $data);
        exit('OK');
    }

    if ($data === 'admin:dashboard') {
        if (!in_array($userId, $adminIds, true)) {
            $tg->answerCallbackQuery($cbId, "❌ Non autorisé", true);
            exit('OK');
        }
        AdminHandler::handleAdminDashboard($tg, $pdo, $chatId);
        $tg->answerCallbackQuery($cbId);
        exit('OK');
    }

    $tg->answerCallbackQuery($cbId);
    exit('OK');
}

// 2. HANDLE TEXT MESSAGES
$message = $update['message'] ?? null;
if (!$message) {
    exit('OK');
}

$chatId   = (int)$message['chat']['id'];
$userId   = (int)$message['from']['id'];
$text     = trim($message['text'] ?? '');
$fullName = trim(($message['from']['first_name'] ?? '') . ' ' . ($message['from']['last_name'] ?? ''));
$username = $message['from']['username'] ?? null;
$telegramLang = $message['from']['language_code'] ?? null;

$existingUser = Database::getUser($pdo, $userId);
$userLang = $existingUser['language_code'] ?? Language::sanitizeLang($telegramLang);
Database::upsertUser($pdo, $userId, $username, $fullName, $userLang);

if (in_array($userId, $adminIds, true)) {
    if ($text === '/admin' || $text === '/dashboard') {
        AdminHandler::handleAdminDashboard($tg, $pdo, $chatId);
        exit('OK');
    }
    if (str_starts_with($text, '/approve')) {
        AdminHandler::handleApproveCommand($tg, $pdo, $chatId, $text);
        exit('OK');
    }
    if (str_starts_with($text, '/reply') || isset($message['reply_to_message'])) {
        $replyTo = $message['reply_to_message']['text'] ?? null;
        AdminHandler::handleReplyCommand($tg, $chatId, $text, $replyTo);
        exit('OK');
    }
    if (str_starts_with($text, '/broadcast')) {
        $bcastText = trim(str_replace('/broadcast', '', $text));
        if (empty($bcastText)) {
            $tg->sendMessage($chatId, "⚠️ اكتب نص الإذاعة: <code>/broadcast نص الرسالة هنا</code>");
            exit('OK');
        }
        $bId = Database::createBroadcast($pdo, $userId, $bcastText);
        $total = (int)$pdo->query("SELECT total_recipients FROM broadcasts WHERE broadcast_id = {$bId}")->fetch()['total_recipients'];
        $msg = Language::trans('broadcast_started', ['total' => $total], $userLang);
        $tg->sendMessage($chatId, $msg);
        exit('OK');
    }
}

if ($text === '/lang' || $text === '/language') {
    $prompt = Language::trans('choose_language_prompt', [], $userLang);
    $tg->sendMessage($chatId, $prompt, [
        'reply_markup' => json_encode(Keyboards::getLanguageSelectionMenu()),
    ]);
    exit('OK');
}

if (!in_array($userId, $adminIds, true)) {
    $isSubscribed = false;
    try {
        $member = $tg->getChatMember('@' . ltrim($forceChannel, '@'), $userId);
        $status = $member['result']['status'] ?? '';
        if (in_array($status, ['creator', 'administrator', 'member', 'restricted'], true)) {
            $isSubscribed = true;
        }
    } catch (\Exception $e) {
        $isSubscribed = true;
    }

    if (!$isSubscribed) {
        $warning = Language::trans('force_sub_warning', ['channel_url' => $forceUrl], $userLang);
        $tg->sendMessage($chatId, $warning, [
            'reply_markup' => json_encode(Keyboards::getForceSubMenu($forceUrl, $userLang)),
        ]);
        exit('OK');
    }
}

$userState = Database::getUserState($pdo, $userId);

if ($userState['state'] === 'WAITING_RECEIPT') {
    $data = $userState['data'];
    $bId = (int)($data['bot_id'] ?? 0);
    $planLabel = $data['plan_label'] ?? 'شهر (1 Month)';
    $priceText = $data['price_text'] ?? '250 ألف (250,000 DA)';
    $planId    = $data['plan_id'] ?? '1_month';

    $ticketNum = Database::createActivationRequest(
        $pdo, $userId, $bId, $planId, $planLabel, $priceText, $text, $userLang
    );

    Database::setUserState($pdo, $userId, null);

    $confirmMsg = Language::trans('receipt_received_user', [
        'ticket_number' => $ticketNum,
        'plan_label'    => $planLabel,
        'price_text'    => $priceText,
        'support_bot'   => $supportBot,
    ], $userLang);

    $tg->sendMessage($chatId, $confirmMsg);

    $bot = Database::getBot($pdo, $bId);
    $botUname = $bot['bot_username'] ?? 'unknown_bot';
    $langFlag = Language::SUPPORTED_LANGUAGES[$userLang]['flag'] ?? '🌐';

    $adminCard = "🇩🇿 <b>طلب تفعيل بريدي موب جديد #{$ticketNum}</b> [{$langFlag}]\n"
               . "--------------------------------------\n"
               . "👤 <b>المستخدم:</b> {$fullName}\n"
               . "🆔 <b>المعرف:</b> <code>{$userId}</code>\n"
               . "🌐 <b>اللغة:</b> {$userLang} ({$langFlag})\n"
               . "🔗 <b>اليوزر:</b> @" . ($username ?: 'none') . "\n"
               . "🤖 <b>البوت:</b> @{$botUname} (<code>{$bId}</code>)\n"
               . "📦 <b>الخطة:</b> {$planLabel}\n"
               . "💵 <b>المبلغ:</b> {$priceText}\n"
               . "📄 <b>ملاحظة التحويل:</b> {$text}\n"
               . "--------------------------------------\n"
               . "💡 <i>اضغط أحد أزرار التحقق أدناه للبت الفوري:</i>";

    foreach ($adminIds as $aId) {
        try {
            $tg->sendMessage($aId, $adminCard, [
                'reply_markup' => json_encode(Keyboards::getAdminApprovalMenu($ticketNum, $bId, $userId)),
            ]);
        } catch (\Exception $e) {}
    }
    exit('OK');
}

if (str_contains($text, ':') && strlen($text) >= 35) {
    if (!Database::checkRateLimit($pdo, $userId, 'submit_token', 5, 120)) {
        $tg->sendMessage($chatId, Language::trans('token_rate_limited', [], $userLang));
        exit('OK');
    }

    try {
        $childTg = new TelegramAPI($text);
        $res = $childTg->getMe();
        if (!($res['ok'] ?? false)) {
            $tg->sendMessage($chatId, Language::trans('token_invalid', [], $userLang));
            exit('OK');
        }

        $botData       = $res['result'];
        $childBotId    = (int)$botData['id'];
        $childUsername = $botData['username'];
        $childName     = $botData['first_name'];

        Database::createChildBot($pdo, $childBotId, $text, $childUsername, $childName, $userId, $trialMax);
        Database::setUserState($pdo, $userId, null);

        $reply = Language::trans('bot_created_success', [
            'bot_name'     => $childName,
            'bot_username' => $childUsername,
            'bot_id'       => $childBotId,
            'max_channels' => $trialMax,
        ], $userLang);

        $tg->sendMessage($chatId, $reply, [
            'reply_markup' => json_encode(Keyboards::getBotManageMenu($childBotId, true, $userLang)),
        ]);
    } catch (\Exception $e) {
        $tg->sendMessage($chatId, "⚠️ " . Language::trans('token_invalid', [], $userLang));
    }
    exit('OK');
}

if (str_starts_with($text, '/add_channel')) {
    $parts = explode(' ', $text);
    if (count($parts) < 3) {
        $tg->sendMessage($chatId, Language::trans('channel_add_syntax', [], $userLang));
        exit('OK');
    }

    $bId = (int)$parts[1];
    $chName = trim($parts[2]);

    $bot = Database::getBot($pdo, $bId);
    if (!$bot || (int)$bot['owner_id'] !== $userId) {
        $tg->sendMessage($chatId, Language::trans('bot_not_owned', [], $userLang));
        exit('OK');
    }

    $cnt = Database::getBotChannelsCount($pdo, $bId);
    if ((int)$bot['is_trial'] === 1 && $cnt >= (int)$bot['max_channels']) {
        $limitMsg = Language::trans('channel_limit_reached', [
            'max_channels' => $bot['max_channels'],
            'support_bot'  => $supportBot,
        ], $userLang);

        $tg->sendMessage($chatId, $limitMsg, [
            'reply_markup' => json_encode(Keyboards::getBaridiMobPricingMenu($bId, $userLang)),
        ]);
        exit('OK');
    }

    try {
        Database::addBotChannel($pdo, $bId, $chName, $chName, ltrim($chName, '@'));
        $newCount = $cnt + 1;
        $maxStr = (int)$bot['is_trial'] === 1 ? (string)$bot['max_channels'] : Language::trans('unlimited', [], $userLang);

        $msg = Language::trans('channel_added_success', [
            'channel_name'  => $chName,
            'current_count' => $newCount,
            'max_count_str' => $maxStr,
        ], $userLang);

        $tg->sendMessage($chatId, $msg, [
            'reply_markup' => json_encode(Keyboards::getBotManageMenu($bId, (bool)$bot['is_trial'], $userLang)),
        ]);
    } catch (\Exception $e) {
        $tg->sendMessage($chatId, "⚠️ " . $e->getMessage());
    }
    exit('OK');
}

if ($text === '/start') {
    Database::setUserState($pdo, $userId, null);
    $welcome = Language::trans('welcome_title', [], $userLang) . "\n\n"
             . Language::trans('welcome_body', ['support_bot' => $supportBot, 'force_url' => $forceUrl], $userLang);

    $tg->sendMessage($chatId, $welcome, [
        'reply_markup' => json_encode(Keyboards::getMainMenu(in_array($userId, $adminIds, true), $userLang)),
    ]);
    exit('OK');
}

$tg->sendMessage(
    $chatId,
    Language::trans('welcome_title', [], $userLang) . "\n" . Language::trans('choose_language_prompt', [], $userLang),
    [
        'reply_markup' => json_encode(Keyboards::getMainMenu(in_array($userId, $adminIds, true), $userLang)),
    ]
);
