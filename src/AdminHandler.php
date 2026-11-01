<?php
declare(strict_types=1);

namespace TeleMaker;

use PDO;
use Exception;

class AdminHandler
{
    /**
     * Handle Admin Dashboard view showing pending activation requests
     */
    public static function handleAdminDashboard(TelegramAPI $tg, PDO $pdo, int $adminChatId): void
    {
        $pendingRequests = Database::getPendingActivationRequests($pdo);

        if (empty($pendingRequests)) {
            $tg->sendMessage($adminChatId, "⚡ <b>لوحة تحكم المشرف:</b>\n\n📭 لا توجد طلبات تفعيل معلقة حالياً.");
            return;
        }

        $msg = "⚡ <b>لوحة تحكم المشرف - طلبات BaridiMob المعلقة (" . count($pendingRequests) . "):</b>\n\n";
        $tg->sendMessage($adminChatId, $msg, ['parse_mode' => 'HTML']);

        foreach ($pendingRequests as $req) {
            $ticketNum = (int)$req['ticket_number'];
            $bId       = (int)$req['bot_id'];
            $userId    = (int)$req['user_id'];
            $userLang  = $req['user_lang'] ?? 'ar';
            $langFlag  = Language::SUPPORTED_LANGUAGES[$userLang]['flag'] ?? '🌐';

            $card = "🇩🇿 <b>طلب تفعيل بريدي موب #{$ticketNum}</b> [{$langFlag}]\n"
                  . "--------------------------------------\n"
                  . "👤 <b>المستخدم:</b> {$req['full_name']}\n"
                  . "🆔 <b>المعرف:</b> <code>{$userId}</code>\n"
                  . "🌐 <b>اللغة:</b> {$userLang} ({$langFlag})\n"
                  . "🔗 <b>اليوزر:</b> @" . ($req['user_uname'] ?? 'none') . "\n"
                  . "🤖 <b>البوت:</b> @{$req['bot_username']} (<code>{$bId}</code>)\n"
                  . "📦 <b>الخطة:</b> {$req['plan_label']}\n"
                  . "💵 <b>المبلغ:</b> {$req['price_text']}\n"
                  . "📄 <b>ملاحظة التحويل:</b> " . ($req['receipt_note'] ?? 'لا توجد') . "\n"
                  . "--------------------------------------";

            $tg->sendMessage($adminChatId, $card, [
                'parse_mode'   => 'HTML',
                'reply_markup' => json_encode(Keyboards::getAdminApprovalMenu($ticketNum, $bId, $userId)),
            ]);
        }
    }

    /**
     * Handle Inline Callback Approvals / Rejections from Admin Card
     */
    public static function handleApprovalCallback(TelegramAPI $tg, PDO $pdo, int $chatId, int $msgId, string $cbId, string $data): void
    {
        // Format: approve_1m:TICKET:BOT_ID or approve_2m:TICKET:BOT_ID or reject_pay:TICKET:BOT_ID
        $parts = explode(':', $data);
        $action = $parts[0] ?? '';
        $ticketNum = (int)($parts[1] ?? 0);
        $botId = (int)($parts[2] ?? 0);

        $req = Database::getActivationRequestByTicket($pdo, $ticketNum);
        if (!$req || $req['status'] !== 'PENDING') {
            $tg->answerCallbackQuery($cbId, "⚠️ هذا الطلب غير موجود أو تم معالجته مسبقاً.", true);
            return;
        }

        $targetUserId = (int)$req['user_id'];
        $userLang = $req['user_lang'] ?? 'ar';

        if (str_starts_with($action, 'approve_')) {
            $durationDays = str_contains($action, '2m') ? 60 : 30;
            $planLabel = str_contains($action, '2m') ? 'شهرين (2 Months)' : 'شهر (1 Month)';

            $result = Database::approveActivationPlan($pdo, $botId, $ticketNum, $durationDays, $planLabel);
            $botUsername = $result['bot']['bot_username'] ?? 'bot';

            $tg->answerCallbackQuery($cbId, "✅ تم تفعيل البوت بنجاح!", false);

            // Notify User in their preferred language
            try {
                $userMsg = Language::trans('payment_approved_notification', [
                    'bot_username' => $botUsername,
                    'plan_label'   => $planLabel,
                    'expires_at'   => $result['expires_at'],
                ], $userLang);
                $tg->sendMessage($targetUserId, $userMsg);
            } catch (Exception $e) {}

            // Update Admin message card
            $updatedText = "✅ <b>تمت الموافقة وتفعيل البوت بنجاح (#{$ticketNum})</b>\n🤖 البوت: @{$botUsername}\n📅 صالح حتى: {$result['expires_at']}";
            try {
                $tg->editMessageText($chatId, $msgId, $updatedText, ['reply_markup' => json_encode(['inline_keyboard' => []])]);
            } catch (Exception $e) {}

        } elseif ($action === 'reject_pay') {
            Database::rejectActivation($pdo, $ticketNum);
            $tg->answerCallbackQuery($cbId, "❌ تم رفض طلب الدفع.", false);

            try {
                $userMsg = Language::trans('payment_rejected_notification', [
                    'ticket_number' => $ticketNum,
                ], $userLang);
                $tg->sendMessage($targetUserId, $userMsg);
            } catch (Exception $e) {}

            $updatedText = "❌ <b>تم رفض طلب الدفع (#{$ticketNum})</b>";
            try {
                $tg->editMessageText($chatId, $msgId, $updatedText, ['reply_markup' => json_encode(['inline_keyboard' => []])]);
            } catch (Exception $e) {}
        }
    }

    /**
     * Handle Text Command Approvals: /approve <ticket_number>
     */
    public static function handleApproveCommand(TelegramAPI $tg, PDO $pdo, int $chatId, string $text): void
    {
        $parts = explode(' ', $text);
        $ticketNum = (int)($parts[1] ?? 0);

        if ($ticketNum <= 0) {
            $tg->sendMessage($chatId, "⚠️ الصيغة غير صحيحة. مثال: <code>/approve 101</code>", ['parse_mode' => 'HTML']);
            return;
        }

        $req = Database::getActivationRequestByTicket($pdo, $ticketNum);
        if (!$req || $req['status'] !== 'PENDING') {
            $tg->sendMessage($chatId, "❌ الطلب رقم #{$ticketNum} غير موجود أو تم التعامل معه مسبقاً.");
            return;
        }

        $botId = (int)$req['bot_id'];
        $result = Database::approveActivationPlan($pdo, $botId, $ticketNum, 30, 'شهر (1 Month)');
        $targetUserId = (int)$req['user_id'];
        $userLang = $req['user_lang'] ?? 'ar';

        $tg->sendMessage($chatId, "✅ تم تفعيل الطلب رقم #{$ticketNum} وبوت @{$result['bot']['bot_username']} بنجاح!");

        try {
            $userMsg = Language::trans('payment_approved_notification', [
                'bot_username' => $result['bot']['bot_username'],
                'plan_label'   => 'شهر (1 Month)',
                'expires_at'   => $result['expires_at'],
            ], $userLang);
            $tg->sendMessage($targetUserId, $userMsg);
        } catch (Exception $e) {}
    }

    /**
     * Handle Forward-Privacy Bypass Reply Command for Admins
     */
    public static function handleReplyCommand(TelegramAPI $tg, int $adminChatId, string $text, ?string $replyToText): void
    {
        // Extract target user ID if present in the replied card (e.g., المعرف: 123456789)
        if ($replyToText && preg_match('/المعرف:\s*<code>(\d+)<\/code>/u', $replyToText, $matches)) {
            $targetUserId = (int)$matches[1];
            $replyMsg = trim(str_replace('/reply', '', $text));

            if (empty($replyMsg)) {
                $tg->sendMessage($adminChatId, "⚠️ يرجى كتابة نص الرد بعد الأمر أو الرد مباشرة على البطاقة.");
                return;
            }

            try {
                $tg->sendMessage($targetUserId, "💬 <b>رد من الإدارة:</b>\n\n" . $replyMsg);
                $tg->sendMessage($adminChatId, "✅ تم إرسال الرد إلى المستخدم بنجاح.");
            } catch (Exception $e) {
                $tg->sendMessage($adminChatId, "❌ تعذر إرسال الرسالة للمستخدم: " . $e->getMessage());
            }
            return;
        }

        $tg->sendMessage($adminChatId, "⚠️ يرجى الرد مباشرة (Reply) على بطاقة طلب التفعيل الخاصة بالمستخدم مع كتابة الرد.");
    }
}
