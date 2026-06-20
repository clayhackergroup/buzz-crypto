from . import coingecko_api as cg


def analyze(coin_id: str = "bitcoin") -> dict:
    data = cg.get_historical_data(coin_id, 90)
    prices = [p[1] for p in data["prices"]]

    current_price = prices[-1]
    # Use most recent ~7 days of price data (roughly 1 data point per hour = ~168 points)
    seven_days = 7 * 24
    recent_prices = prices[-seven_days:] if len(prices) > seven_days else prices
    price_change_7d = ((recent_prices[-1] - recent_prices[0]) / recent_prices[0]) * 100

    rsi = _compute_rsi(prices)
    macd = _compute_macd(prices)
    bollinger = _compute_bollinger(prices)
    sma50 = _sma(prices, min(50, len(prices)))
    sma200 = _sma(prices, 200) if len(prices) >= 200 else None

    recent20 = prices[-20:]
    support = min(recent20)
    resistance = max(recent20)

    signals = []
    scores = []

    if rsi < 30:
        signals.append(("RSI", "oversold", "bullish"))
        scores.append(1)
    elif rsi > 70:
        signals.append(("RSI", "overbought", "bearish"))
        scores.append(-1)
    else:
        signals.append(("RSI", "neutral", "neutral"))
        scores.append(0)

    if macd["bullish"]:
        signals.append(("MACD", "bullish crossover", "bullish"))
        scores.append(1)
    else:
        signals.append(("MACD", "bearish crossover", "bearish"))
        scores.append(-1)

    if current_price > sma50:
        signals.append(("SMA50", "price above SMA50", "bullish"))
        scores.append(1)
    else:
        signals.append(("SMA50", "price below SMA50", "bearish"))
        scores.append(-1)

    if sma200 is not None:
        if current_price > sma200:
            signals.append(("SMA200", "price above SMA200", "bullish"))
            scores.append(1)
        else:
            signals.append(("SMA200", "price below SMA200", "bearish"))
            scores.append(-1)

    bb_pos = bollinger["position"]
    if bb_pos < 20:
        signals.append(("Bollinger", "near lower band", "bullish"))
        scores.append(1)
    elif bb_pos > 80:
        signals.append(("Bollinger", "near upper band", "bearish"))
        scores.append(-1)
    else:
        signals.append(("Bollinger", "mid-range", "neutral"))
        scores.append(0)

    avg_score = sum(scores) / len(scores)
    if avg_score > 0.3:
        overall = "BUY"
    elif avg_score < -0.3:
        overall = "SELL"
    else:
        overall = "HOLD"

    return {
        "current_price": current_price,
        "price_change_7d": price_change_7d,
        "rsi": rsi,
        "macd": macd,
        "bollinger": bollinger,
        "sma50": sma50,
        "sma200": sma200,
        "support": support,
        "resistance": resistance,
        "signals": signals,
        "overall": overall,
    }


def _compute_rsi(prices: list[float], period: int = 14) -> float:
    changes = [prices[i] - prices[i - 1] for i in range(1, len(prices))]
    gains = [c if c > 0 else 0 for c in changes]
    losses = [-c if c < 0 else 0 for c in changes]
    avg_gain = sum(gains[:period]) / period
    avg_loss = sum(losses[:period]) / period
    for i in range(period, len(changes)):
        avg_gain = (avg_gain * (period - 1) + gains[i]) / period
        avg_loss = (avg_loss * (period - 1) + losses[i]) / period
    if avg_loss == 0:
        return 100.0
    return 100.0 - (100.0 / (1.0 + (avg_gain / avg_loss)))


def _ema(data: list[float], period: int) -> float:
    if len(data) < period:
        return sum(data) / len(data)
    multiplier = 2.0 / (period + 1)
    ema = sum(data[:period]) / period
    for i in range(period, len(data)):
        ema = (data[i] - ema) * multiplier + ema
    return ema


def _compute_macd(prices: list[float]) -> dict:
    ema12_values = []
    ema26_values = []
    for i in range(len(prices)):
        ema12_values.append(_ema(prices[: i + 1], 12))
        ema26_values.append(_ema(prices[: i + 1], 26))
    macd_history = [e12 - e26 for e12, e26 in zip(ema12_values, ema26_values)]
    signal = _ema(macd_history, 9)
    macd_line = macd_history[-1] if macd_history else 0
    return {
        "macd": macd_line,
        "signal": signal,
        "histogram": macd_line - signal,
        "bullish": macd_line > signal,
    }


def _sma(data: list[float], period: int) -> float:
    if len(data) < period:
        period = len(data)
    return sum(data[-period:]) / period


def _compute_bollinger(prices: list[float], period: int = 20) -> dict:
    recent = prices[-period:]
    sma = sum(recent) / len(recent)
    variance = sum((p - sma) ** 2 for p in recent) / len(recent)
    std = variance ** 0.5
    current = prices[-1]
    upper = sma + 2 * std
    lower = sma - 2 * std
    position = 50.0 if std == 0 else ((current - lower) / (4 * std)) * 100
    return {"upper": upper, "middle": sma, "lower": lower, "position": position}
