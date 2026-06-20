<?php
class CoinGeckoApi {
    public static function getPrices(array $ids): array {
        return self::get('/simple/price', [
            'ids' => implode(',', $ids),
            'vs_currencies' => 'usd',
            'include_24hr_change' => 'true',
            'include_market_cap' => 'true',
        ]);
    }

    public static function getHistoricalData(string $coinId, int $days = 7): array {
        return self::get("/coins/$coinId/market_chart", [
            'vs_currency' => 'usd',
            'days' => $days,
        ]);
    }

    public static function getCoinInfo(string $coinId): array {
        return self::get("/coins/$coinId", [
            'localization' => 'false',
            'tickers' => 'false',
            'community_data' => 'false',
            'developer_data' => 'false',
        ]);
    }

    private static function get(string $path, array $params = []): array {
        $url = COINGECKO_API . $path;
        if (!empty($params)) $url .= '?' . http_build_query($params);
        $ch = curl_init();
        $curlOpts = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) CryptoBot/1.0',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ];
        $proxy = getenv('BOT_PROXY');
        if ($proxy) {
            $curlOpts[CURLOPT_PROXY] = $proxy;
        }
        curl_setopt_array($ch, $curlOpts);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        if ($error) throw new RuntimeException("CoinGecko Error: $error");
        $data = json_decode($response, true);
        if ($data === null) throw new RuntimeException("Invalid JSON from CoinGecko");
        if (isset($data['status']['error_code'])) {
            throw new RuntimeException("CoinGecko: " . ($data['status']['error_message'] ?? 'unknown error'));
        }
        return $data;
    }
}
