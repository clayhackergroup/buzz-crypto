<?php
class Helpers {
    public static function formatPrice(float $usd): string {
        if ($usd >= 1) return '$' . number_format($usd, 2);
        if ($usd >= 0.001) return '$' . number_format($usd, 4);
        return '$' . number_format($usd, 6);
    }

    public static function formatCompact(float $usd): string {
        if ($usd >= 1000) return '$' . number_format($usd, 0);
        if ($usd >= 1) return '$' . number_format($usd, 2);
        return self::formatPrice($usd);
    }

    public static function formatChange(float $pct): string {
        $sign = $pct >= 0 ? '+' : '';
        return $sign . number_format($pct, 2) . '%';
    }

    public static function shortenAddress(string $address, int $chars = 8): string {
        if (strlen($address) <= $chars * 2 + 3) return $address;
        return substr($address, 0, $chars) . '...' . substr($address, -$chars);
    }

    public static function getCurrentPrice(): float {
        $data = CoinGeckoApi::getPrices(['bitcoin']);
        return $data['bitcoin']['usd'] ?? 0;
    }

    public static function formatTime(int $timestamp): string {
        $diff = time() - $timestamp;
        if ($diff < 60) return 'just now';
        if ($diff < 3600) return floor($diff / 60) . ' min ago';
        if ($diff < 86400) return floor($diff / 3600) . ' hours ago';
        if ($diff < 172800) return 'yesterday';
        return floor($diff / 86400) . ' days ago';
    }

    public static function formatTxValue(float $btc): string {
        return ($btc >= 0 ? '+' : '') . number_format($btc, 8) . ' BTC';
    }
}
