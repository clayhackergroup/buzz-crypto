import time

from . import coingecko_api as cg


def get_current_price() -> float:
    data = cg.get_prices(["bitcoin"])
    return data.get("bitcoin", {}).get("usd", 0)


def format_price(usd: float) -> str:
    if usd >= 1:
        return f"${usd:,.2f}"
    if usd >= 0.001:
        return f"${usd:.4f}"
    return f"${usd:.6f}"


def format_compact(usd: float) -> str:
    if usd >= 1000:
        return f"${usd:,.0f}"
    if usd >= 1:
        return f"${usd:.2f}"
    return format_price(usd)


def format_change(pct: float) -> str:
    arr = " \u2191" if pct > 0 else (" \u2193" if pct < 0 else "")
    sign = "+" if pct > 0 else ""
    return f"{sign}{pct:.2f}%{arr}"


def shorten_address(address: str, chars: int = 8) -> str:
    if len(address) <= chars * 2 + 3:
        return address
    return f"{address[:chars]}...{address[-chars:]}"


def format_time(timestamp: int) -> str:
    diff = time.time() - timestamp
    if diff < 60:
        return "just now"
    if diff < 3600:
        return f"{int(diff // 60)} min ago"
    if diff < 86400:
        return f"{int(diff // 3600)} hours ago"
    if diff < 172800:
        return "yesterday"
    return f"{int(diff // 86400)} days ago"


def format_tx_value(btc: float) -> str:
    sign = "+" if btc >= 0 else ""
    return f"{sign}{btc:.8f} BTC"
