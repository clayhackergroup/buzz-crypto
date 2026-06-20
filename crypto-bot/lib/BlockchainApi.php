<?php
class BlockchainApi {
    public static function getAddressInfo(string $address): array {
        return self::get(BLOCKCHAIN_API . "/rawaddr/{$address}?limit=10");
    }

    public static function getBalance(array $addresses): array {
        $active = implode('|', $addresses);
        return self::get(BLOCKCHAIN_API . "/balance?active={$active}");
    }

    public static function getLatestBlock(): array {
        return self::get(BLOCKCHAIN_API . "/latestblock");
    }

    public static function getUnconfirmedTxs(): array {
        return self::get(BLOCKCHAIN_API . "/unconfirmed-transactions?format=json");
    }

    public static function getMempoolStats(): array {
        return self::get(MEMPOOL_API . "/mempool");
    }

    public static function getMempoolRecent(): array {
        return self::get(MEMPOOL_API . "/mempool/recent");
    }

    public static function getFeesRecommended(): array {
        return self::get(MEMPOOL_API . "/v1/fees/recommended");
    }

    public static function getUnspentOutputs(string $address): array {
        return self::get(BLOCKCHAIN_API . "/unspent?active={$address}");
    }

    public static function getAddressFullInfo(string $address): array {
        return self::get(BLOCKCHAIN_API . "/rawaddr/{$address}?limit=5");
    }

    private static function get(string $url): array {
        $ch = curl_init();
        $curlOpts = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ];
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
            throw new RuntimeException("API Error: $error");
        }
        $data = json_decode($response, true);
        if ($data === null) {
            throw new RuntimeException("Invalid JSON response from $url");
        }
        return $data;
    }
}
