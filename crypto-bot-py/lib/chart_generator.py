import os
import tempfile
import matplotlib
matplotlib.use("Agg")
import matplotlib.pyplot as plt
import numpy as np


def _calc_sma(data: list[float], period: int) -> list[float | None]:
    result: list[float | None] = [None] * len(data)
    for i in range(period - 1, len(data)):
        result[i] = sum(data[i - period + 1 : i + 1]) / period
    return result


def generate_chart(prices: list[list], days: int, coin_name: str = "BTC") -> str:
    values = [p[1] for p in prices]
    total = len(values)
    if total < 2:
        return ""

    min_val = min(values)
    max_val = max(values)
    if min_val == max_val:
        min_val -= 1
        max_val += 1
    pad = (max_val - min_val) * 0.05
    min_val -= pad
    max_val += pad

    sma7 = _calc_sma(values, min(7, total))
    sma25 = _calc_sma(values, min(25, total))

    plt.style.use("dark_background")
    fig, ax = plt.subplots(figsize=(12, 6), facecolor="#1a1a2e")
    ax.set_facecolor("#1a1a2e")

    x = np.arange(total)
    ax.fill_between(x, values, min_val, alpha=0.15, color="#f7931a")
    ax.plot(x, values, color="#f7931a", linewidth=1.5, label="Price")
    ax.plot(x, sma7, color="#4ecdc4", linewidth=1, linestyle="--", label="SMA7")
    ax.plot(x, sma25, color="#ff6b6b", linewidth=1, linestyle="--", label="SMA25")

    ax.set_ylim(min_val, max_val)
    ax.set_title(f"{coin_name}/USD - Last {days} Days", color="#c8c8c8", fontsize=14)
    ax.set_ylabel("Price (USD)", color="#c8c8c8")
    ax.legend(loc="upper left", facecolor="#1a1a2e", edgecolor="#323250",
              labelcolor="#c8c8c8")
    ax.grid(color="#323250", linewidth=0.5)
    ax.tick_params(colors="#c8c8c8")
    for spine in ax.spines.values():
        spine.set_color("#323250")

    fmt_func = lambda v, _: f"${v:,.0f}" if v >= 100 else f"${v:.2f}"
    ax.yaxis.set_major_formatter(plt.FuncFormatter(fmt_func))

    plt.tight_layout()

    fd, path = tempfile.mkstemp(suffix=".png", prefix="chart_")
    os.close(fd)
    fig.savefig(path, dpi=100, facecolor=fig.get_facecolor())
    plt.close(fig)
    return path
