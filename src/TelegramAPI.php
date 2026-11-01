<?php
declare(strict_types=1);

namespace TeleMaker;

use RuntimeException;

class TelegramAPI
{
    private string $token;
    private string $baseUrl;

    public function __construct(string $token)
    {
        $this->token = $token;
        $this->baseUrl = "https://api.telegram.org/bot" . $token . "/";
    }

    public function call(string $method, array $params = []): array
    {
        $ch = curl_init($this->baseUrl . $method);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($params),
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("cURL Error: " . $err);
        }
        curl_close($ch);

        $data = json_decode((string)$response, true);
        if ($httpCode === 429) {
            $retryAfter = $data['parameters']['retry_after'] ?? 5;
            throw new RuntimeException("FLOOD_WAIT:" . $retryAfter);
        }

        return $data ?? [];
    }

    public function sendMessage(int|string $chatId, string $text, array $extra = []): array
    {
        $payload = array_merge([
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'HTML',
        ], $extra);

        return $this->call('sendMessage', $payload);
    }

    public function editMessageText(int|string $chatId, int $messageId, string $text, array $extra = []): array
    {
        $payload = array_merge([
            'chat_id'    => $chatId,
            'message_id' => $messageId,
            'text'       => $text,
            'parse_mode' => 'HTML',
        ], $extra);

        return $this->call('editMessageText', $payload);
    }

    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null, bool $showAlert = false): array
    {
        $payload = ['callback_query_id' => $callbackQueryId];
        if ($text !== null) {
            $payload['text'] = $text;
            $payload['show_alert'] = $showAlert ? 'true' : 'false';
        }
        return $this->call('answerCallbackQuery', $payload);
    }

    public function getMe(): array
    {
        return $this->call('getMe');
    }

    public function getChatMember(string $chatId, int $userId): array
    {
        return $this->call('getChatMember', [
            'chat_id' => $chatId,
            'user_id' => $userId,
        ]);
    }
}
