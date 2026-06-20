<?php
class PricePredictor {
    public static function analyze(string $coinId = 'bitcoin'): array {
        $data = CoinGeckoApi::getHistoricalData($coinId, 90);
        $prices = array_map(fn($p) => $p[1], $data['prices']);

        $currentPrice = $prices[count($prices) - 1];
        $priceChange7d = (($prices[count($prices) - 1] - $prices[0]) / $prices[0]) * 100;

        $rsi = self::computeRsi($prices);
        $macd = self::computeMacd($prices);
        $bollinger = self::computeBollinger($prices);
        $sma50 = self::sma($prices, min(50, count($prices)));
        $sma200 = count($prices) >= 200 ? self::sma($prices, 200) : null;

        $recent20 = array_slice($prices, -20);
        $support = min($recent20);
        $resistance = max($recent20);

        $signals = [];
        $scores = [];

        if ($rsi < 30) { $signals[] = ['RSI', 'oversold', 'bullish']; $scores[] = 1; }
        elseif ($rsi > 70) { $signals[] = ['RSI', 'overbought', 'bearish']; $scores[] = -1; }
        else { $signals[] = ['RSI', 'neutral', 'neutral']; $scores[] = 0; }

        if ($macd['bullish']) { $signals[] = ['MACD', 'bullish crossover', 'bullish']; $scores[] = 1; }
        else { $signals[] = ['MACD', 'bearish crossover', 'bearish']; $scores[] = -1; }

        if ($currentPrice > $sma50) { $signals[] = ['SMA50', 'price above SMA50', 'bullish']; $scores[] = 1; }
        else { $signals[] = ['SMA50', 'price below SMA50', 'bearish']; $scores[] = -1; }

        if ($sma200 !== null) {
            if ($currentPrice > $sma200) { $signals[] = ['SMA200', 'price above SMA200', 'bullish']; $scores[] = 1; }
            else { $signals[] = ['SMA200', 'price below SMA200', 'bearish']; $scores[] = -1; }
        }

        $bbPos = $bollinger['position'];
        if ($bbPos < 20) { $signals[] = ['Bollinger', 'near lower band', 'bullish']; $scores[] = 1; }
        elseif ($bbPos > 80) { $signals[] = ['Bollinger', 'near upper band', 'bearish']; $scores[] = -1; }
        else { $signals[] = ['Bollinger', 'mid-range', 'neutral']; $scores[] = 0; }

        $avgScore = array_sum($scores) / count($scores);
        $overall = $avgScore > 0.3 ? 'BUY' : ($avgScore < -0.3 ? 'SELL' : 'HOLD');

        return [
            'current_price' => $currentPrice,
            'price_change_7d' => $priceChange7d,
            'rsi' => $rsi,
            'macd' => $macd,
            'bollinger' => $bollinger,
            'sma50' => $sma50,
            'sma200' => $sma200,
            'support' => $support,
            'resistance' => $resistance,
            'signals' => $signals,
            'overall' => $overall,
        ];
    }

    private static function computeRsi(array $prices, int $period = 14): float {
        $changes = [];
        for ($i = 1; $i < count($prices); $i++) $changes[] = $prices[$i] - $prices[$i - 1];
        $gains = array_map(fn($c) => $c > 0 ? $c : 0, $changes);
        $losses = array_map(fn($c) => $c < 0 ? -$c : 0, $changes);
        $avgGain = array_sum(array_slice($gains, 0, $period)) / $period;
        $avgLoss = array_sum(array_slice($losses, 0, $period)) / $period;
        for ($i = $period; $i < count($changes); $i++) {
            $avgGain = ($avgGain * ($period - 1) + $gains[$i]) / $period;
            $avgLoss = ($avgLoss * ($period - 1) + $losses[$i]) / $period;
        }
        if ($avgLoss == 0) return 100;
        return 100 - (100 / (1 + ($avgGain / $avgLoss)));
    }

    private static function computeMacd(array $prices): array {
        $ema12 = self::ema($prices, 12);
        $ema26 = self::ema($prices, 26);
        $macdHistory = [];
        for ($i = 0; $i < count($prices); $i++) {
            $macdHistory[] = self::ema(array_slice($prices, 0, $i + 1), 12) - self::ema(array_slice($prices, 0, $i + 1), 26);
        }
        $signal = self::ema($macdHistory, 9);
        $macdLine = $macdHistory[count($macdHistory) - 1];
        return ['macd' => $macdLine, 'signal' => $signal, 'histogram' => $macdLine - $signal, 'bullish' => $macdLine > $signal];
    }

    private static function ema(array $data, int $period): float {
        if (count($data) < $period) return array_sum($data) / count($data);
        $multiplier = 2 / ($period + 1);
        $ema = array_sum(array_slice($data, 0, $period)) / $period;
        for ($i = $period; $i < count($data); $i++) $ema = ($data[$i] - $ema) * $multiplier + $ema;
        return $ema;
    }

    private static function sma(array $data, int $period): float {
        if (count($data) < $period) $period = count($data);
        return array_sum(array_slice($data, -$period)) / $period;
    }

    private static function computeBollinger(array $prices, int $period = 20): array {
        $recent = array_slice($prices, -$period);
        $sma = array_sum($recent) / count($recent);
        $variance = array_sum(array_map(fn($p) => pow($p - $sma, 2), $recent)) / count($recent);
        $std = sqrt($variance);
        $current = $prices[count($prices) - 1];
        return [
            'upper' => $sma + 2 * $std,
            'middle' => $sma,
            'lower' => $sma - 2 * $std,
            'position' => ($std == 0) ? 50 : (($current - ($sma - 2 * $std)) / (4 * $std)) * 100,
        ];
    }
}
