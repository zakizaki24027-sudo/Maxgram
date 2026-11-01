<?php
declare(strict_types=1);

namespace TeleMaker;

use PDO;
use Exception;

class ChildBotHandler
{
    private PDO $pdo;
    private array $botData;
    private TelegramAPI $childTg;
    private string $supportBot;

    public function __construct(PDO $pdo, array $botData, string $supportBot = 'Tawassolmax_bot')
    {
        $this->pdo        = $pdo;
        $this->botData    = $botData;
        $this->childTg    = new TelegramAPI($botData['bot_token']);
        $this->supportBot = $supportBot;
    }

    public function handleUpdate(array $update): void
    {
        if (isset($update['message'])) {
            $this->handleMessage($update['message']);
        } elseif (isset($update['callback_query'])) {
            $this->handleCallback($update['callback_query']);
        }
    }

    private function handleMessage(array $message): void
    {
        $chatId = (int)$message['chat']['id'];
        $userId = (int)($message['from']['id'] ?? $chatId);
        $text   = trim($message['text'] ?? '');
        $botId  = (int)$this->botData['bot_id'];
        $isOwner = ($userId === (int)$this->botData['owner_id']);

        $userLang = Language::getUserLanguage($this->pdo, $userId);

        if ($text === '/start') {
            $welcome = "👋 <b>مرحباً بك في بوت النشر التابع!</b>\n\n"
                     . "🤖 <b>اسم البوت:</b> {$this->botData['bot_first_name']} (@{$this->botData['bot_username']})\n";

            if ($this->botData['is_trial']) {
                $count = Database::getBotChannelsCount($this->pdo, $botId);
                $welcome .= "⚠️ <b>الوضع الحالي:</b> فترة تجريبية (Trial)\n"
                          . "📊 <b>القنوات المربوطة:</b> {$count} من أصل 3 قنوات مسموحة.\n"
                          . "💡 للترقية غير المحدودة تواصل مع الدعم: @{$this->supportBot}";
            } else {
                $welcome .= "💎 <b>الوضع الحالي:</b> باقة مفعلة بالكامل (Pro Active) غير محدودة!";
            }

            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '➕ إضافة قناة (Add Channel)', 'callback_data' => 'worker:add_channel']],
                    [['text' => '📋 قنواتي المربوطة', 'callback_data' => 'worker:list_channels']],
                ],
            ];

            if ($this->botData['is_trial']) {
                $keyboard['inline_keyboard'][] = [
                    ['text' => '💳 ترقية الباقة عبر BaridiMob', 'url' => "https://t.me/{$this->supportBot}"],
                ];
            }

            $this->childTg->sendMessage($chatId, $welcome, [
                'parse_mode'   => 'HTML',
                'reply_markup' => json_encode($keyboard),
            ]);
            return;
        }

        if (str_starts_with($text, '/addchannel') || str_starts_with($text, '/add_channel')) {
            $parts = explode(' ', $text, 2);
            $targetChannel = trim($parts[1] ?? '');

            if (empty($targetChannel)) {
                $this->childTg->sendMessage(
                    $chatId,
                    "⚠️ يرجى تحديد معرف القناة. مثال:\n<code>/addchannel @my_channel</code>",
                    ['parse_mode' => 'HTML']
                );
                return;
            }

            $this->processAddChannel($chatId, $botId, $targetChannel, $userLang);
            return;
        }

        if (str_starts_with($text, '/broadcast') && $isOwner) {
            $bcastText = trim(substr($text, 10));
            if (empty($bcastText)) {
                $this->childTg->sendMessage($chatId, "⚠️ اكتب نص الرسالة بعد الأمر:\n<code>/broadcast السلام عليكم</code>", ['parse_mode' => 'HTML']);
                return;
            }

            $this->broadcastToChannels($chatId, $botId, $bcastText);
            return;
        }
    }

    private function handleCallback(array $cb): void
    {
        $cbId   = $cb['id'];
        $chatId = (int)$cb['message']['chat']['id'];
        $data   = $cb['data'] ?? '';
        $botId  = (int)$this->botData['bot_id'];

        if ($data === 'worker:add_channel') {
            $currentCount = Database::getBotChannelsCount($this->pdo, $botId);
            $maxChannels  = (int)$this->botData['max_channels'];
            $isTrial      = (bool)$this->botData['is_trial'];

            if ($isTrial && $currentCount >= $maxChannels) {
                $prompt = "🚫 <b>تم بلوغ الحد الأقصى للقنوات التجريبية ({$maxChannels} قنوات)!</b>\n\n"
                        . "💳 لربط المزيد من القنوات دون قيود، يرجى ترقية البوت عبر تحويل <b>BaridiMob</b> والتواصل مع: @{$this->supportBot}\n\n"
                        . "💰 <b>الأسعار:</b>\n"
                        . "• شهر واحد: 250 ألف (250,000 DA)\n"
                        . "• شهرين: 500 ألف دج (500,000 DA)";

                $this->childTg->sendMessage($chatId, $prompt, [
                    'parse_mode'   => 'HTML',
                    'reply_markup' => json_encode([
                        'inline_keyboard' => [
                            [['text' => '💳 تواصل للدفع والتفعيل', 'url' => "https://t.me/{$this->supportBot}"]],
                        ],
                    ]),
                ]);
                $this->childTg->answerCallbackQuery($cbId, "تم بلوغ الحد التجريبي!", true);
                return;
            }

            $instructions = "➕ <b>خطوات ربط قناة جديدة بالبوت:</b>\n\n"
                          . "1️⃣ قم بإضافة هذا البوت مشرفاً (Admin) في قناتك مع صلاحية نشر الرسائل.\n"
                          . "2️⃣ أرسل الأمر التالي متبوعاً بمعرف القناة:\n"
                          . "<code>/addchannel @اسم_قناتك</code>";

            $this->childTg->sendMessage($chatId, $instructions, ['parse_mode' => 'HTML']);
            $this->childTg->answerCallbackQuery($cbId);
            return;
        }

        if ($data === 'worker:list_channels') {
            $stmt = $this->pdo->prepare("SELECT * FROM bot_channels WHERE bot_id = ? ORDER BY added_at DESC");
            $stmt->execute([$botId]);
            $channels = $stmt->fetchAll();

            if (empty($channels)) {
                $this->childTg->sendMessage($chatId, "📭 لا توجد قنوات مربوطة بهذا البوت حتى الآن.");
                $this->childTg->answerCallbackQuery($cbId);
                return;
            }

            $msg = "📋 <b>القنوات المربوطة بالبوت (" . count($channels) . "):</b>\n\n";
            foreach ($channels as $idx => $ch) {
                $num = $idx + 1;
                $msg .= "{$num}. <b>{$ch['channel_title']}</b> (<code>{$ch['channel_id']}</code>)\n";
            }

            $this->childTg->sendMessage($chatId, $msg, ['parse_mode' => 'HTML']);
            $this->childTg->answerCallbackQuery($cbId);
            return;
        }
    }

    private function processAddChannel(int $chatId, int $botId, string $targetChannel, string $userLang): void
    {
        $currentCount = Database::getBotChannelsCount($this->pdo, $botId);
        $maxChannels  = (int)$this->botData['max_channels'];
        $isTrial      = (bool)$this->botData['is_trial'];

        if ($isTrial && $currentCount >= $maxChannels) {
            $alert = "🚫 <b>عذراً، لا يمكن إضافة أكثر من {$maxChannels} قنوات في الوضع التجريبي!</b>\n\n"
                   . "💳 قم بترقية حسابك عبر BaridiMob بمراسلة @{$this->supportBot} للاستفادة من عدد غير محدود من القنوات.";
            $this->childTg->sendMessage($chatId, $alert, ['parse_mode' => 'HTML']);
            return;
        }

        try {
            $chatInfo = $this->childTg->getChat($targetChannel);
            $cResult  = $chatInfo['result'] ?? null;
            if (!$cResult) {
                $this->childTg->sendMessage($chatId, "❌ تعذر العثور على القناة. تأكد من صحة المعرف وكون البوت مشرفاً فيها.");
                return;
            }

            $cId    = (string)$cResult['id'];
            $cTitle = (string)($cResult['title'] ?? $targetChannel);
            $cUname = $cResult['username'] ?? null;

            Database::addBotChannel($this->pdo, $botId, $cId, $cTitle, $cUname);

            $newCount = Database::getBotChannelsCount($this->pdo, $botId);
            $msg = "✅ <b>تم ربط القناة بنجاح!</b>\n\n"
                 . "📢 <b>العنوان:</b> {$cTitle}\n"
                 . "🆔 <b>المعرف:</b> <code>{$cId}</code>\n"
                 . "📊 <b>القنوات الحالية:</b> {$newCount} / " . ($isTrial ? (string)$maxChannels : 'غير محدود');

            $this->childTg->sendMessage($chatId, $msg, ['parse_mode' => 'HTML']);
        } catch (Exception $e) {
            $err = $e->getMessage() === 'LIMIT_EXCEEDED'
                ? "🚫 تم بلوغ الحد الأقصى المسموح به للقنوات!"
                : "❌ حدث خطأ أثناء ربط القناة: " . htmlspecialchars($e->getMessage());

            $this->childTg->sendMessage($chatId, $err, ['parse_mode' => 'HTML']);
        }
    }

    private function broadcastToChannels(int $chatId, int $botId, string $bcastText): void
    {
        $stmt = $this->pdo->prepare("SELECT channel_id, channel_title FROM bot_channels WHERE bot_id = ?");
        $stmt->execute([$botId]);
        $channels = $stmt->fetchAll();

        if (empty($channels)) {
            $this->childTg->sendMessage($chatId, "⚠️ لا توجد قنوات مربوطة بعد لنشر الرسالة إليها.");
            return;
        }

        $sent = 0;
        $failed = 0;

        foreach ($channels as $ch) {
            try {
                $this->childTg->sendMessage($ch['channel_id'], $bcastText, ['parse_mode' => 'HTML']);
                $sent++;
                usleep(50000);
            } catch (Exception $e) {
                $failed++;
            }
        }

        $report = "📊 <b>تقرير الإذاعة في القنوات:</b>\n\n"
                . "✅ تم الإرسال بنجاح إلى: {$sent} قناة\n"
                . "❌ فشل الإرسال إلى: {$failed} قناة";

        $this->childTg->sendMessage($chatId, $report, ['parse_mode' => 'HTML']);
    }
}
