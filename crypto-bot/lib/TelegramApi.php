<?php
class TelegramApi {
    private string $apiUrl;

    public function __construct(string $token) {
        $base = getenv('BOT_API_URL') ?: 'https://api.telegram.org/bot';
        $this->apiUrl = "{$base}{$token}";
    }

    private function call(string $method, array $params = []): array {
        $url = "{$this->apiUrl}/{$method}";
        $ch = curl_init();
        $curlOpts = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 35,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ];
        if (!empty($params)) {
            $curlOpts[CURLOPT_POST] = true;
            $curlOpts[CURLOPT_POSTFIELDS] = http_build_query($params);
        }
        $proxy = getenv('BOT_PROXY');
        if ($proxy) {
            $curlOpts[CURLOPT_PROXY] = $proxy;
        }
        curl_setopt_array($ch, $curlOpts);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error) {
            throw new RuntimeException("CURL: $error");
        }
        $data = json_decode($response, true);
        if (!$data || !($data['ok'] ?? false)) {
            throw new RuntimeException("Telegram API: " . ($data['description'] ?? 'unknown error'));
        }
        return $data;
    }

    public function getUpdates(int $offset = 0, int $timeout = 30): array {
        $data = $this->call('getUpdates', [
            'offset' => $offset,
            'timeout' => $timeout,
            'allowed_updates' => json_encode(['message', 'callback_query']),
        ]);
        return $data['result'] ?? [];
    }

    public function sendMessage(int $chatId, string $text, string $parseMode = 'Markdown', ?array $replyMarkup = null): array {
        $params = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => $parseMode,
        ];
        if ($replyMarkup !== null) {
            $params['reply_markup'] = json_encode($replyMarkup);
        }
        return $this->call('sendMessage', $params);
    }

    public function editMessageText(int $chatId, int $messageId, string $text, string $parseMode = 'Markdown', ?array $replyMarkup = null): array {
        $params = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => $parseMode,
        ];
        if ($replyMarkup !== null) {
            $params['reply_markup'] = json_encode($replyMarkup);
        }
        return $this->call('editMessageText', $params);
    }

    public function sendPhoto(int $chatId, string $photoPath, string $caption = ''): array {
        $url = "{$this->apiUrl}/sendPhoto";
        $ch = curl_init();
        $curlOpts = [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_POSTFIELDS => [
                'chat_id' => $chatId,
                'photo' => new CURLFile($photoPath),
                'caption' => $caption,
            ],
        ];
        $proxy = getenv('BOT_PROXY');
        if ($proxy) {
            $curlOpts[CURLOPT_PROXY] = $proxy;
        }
        curl_setopt_array($ch, $curlOpts);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        if ($error) {
            throw new RuntimeException("CURL: $error");
        }
        $data = json_decode($response, true);
        if (!$data || !($data['ok'] ?? false)) {
            throw new RuntimeException("Telegram API: " . ($data['description'] ?? 'unknown error'));
        }
        return $data;
    }

    public function answerCallbackQuery(string $callbackQueryId, string $text = ''): void {
        $this->call('answerCallbackQuery', [
            'callback_query_id' => $callbackQueryId,
            'text' => $text,
        ]);
    }

    public function deleteMessage(int $chatId, int $messageId): void {
        $this->call('deleteMessage', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
        ]);
    }
}
