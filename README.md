# Buzz Crypto Bot

> Multi-language cryptocurrency tracking bot for Telegram — track wallets, monitor mempool, price alerts, and technical analysis.

<br>

<p align="center">
  <a href="https://ovpnspider.com">
    <img src="https://play-lh.googleusercontent.com/PPOofCAhV2Ns08v2zGflMfak8P0wUKPpx1qb-wwpRJAPwyf2GY6Mv8iuBc_HX1sjFg=w512-h512-p" alt="OVPN Spider" width="180"/>
  </a>
</p>

<p align="center">
  <b>SPONSORED BY</b>
  <br>
  <a href="https://ovpnspider.com"><b>OVPN SPIDER</b></a>
  <br>
  <sub>Secure. Private. Unrestricted. — Your trusted VPN proxy for safe and anonymous browsing.</sub>
</p>

<p align="center">
  <a href="https://play.google.com/store/apps/details?id=com.ovpnspider">
    <img src="https://img.shields.io/badge/Download-Google_Play-414141?logo=googleplay&logoColor=white" alt="Google Play"/>
  </a>
  &nbsp;
  <a href="https://ovpnspider.com">
    <img src="https://img.shields.io/badge/Visit-Website-006BED?logo=googlechrome&logoColor=white" alt="Website"/>
  </a>
</p>

<br>

---

## Table of Contents

- [Overview](#overview)
- [Differences: Python vs PHP](#differences-python-vs-php)
- [Features](#features)
- [Architecture](#architecture)
- [Project Structure](#project-structure)
- [Setup & Installation](#setup--installation)
  - [Python Bot Setup](#python-bot-setup)
  - [PHP Bot Setup](#php-bot-setup)
  - [Proxy Configuration (for restricted regions)](#proxy-configuration-for-restricted-regions)
- [Configuration](#configuration)
- [Usage](#usage)
  - [User Commands](#user-commands)
  - [Admin Commands](#admin-commands)
- [How It Works](#how-it-works)
- [API Dependencies](#api-dependencies)
- [Database Schema](#database-schema)
- [Security & Responsibilities](#security--responsibilities)
- [Support](#support)
- [Development](#development)
- [License](#license)

---

## Overview

Buzz Crypto Bot is a Telegram bot that provides real-time cryptocurrency tracking, Bitcoin wallet intelligence, mempool monitoring, price alerts, and technical analysis. It is implemented in **two independent versions** — Python and PHP — giving you deployment flexibility across different hosting environments.

Both versions share the same feature set, API integrations, and database schema. Choose the one that best fits your infrastructure.

---

## Differences: Python vs PHP

| Aspect | Python (`crypto-bot-py/`) | PHP (`crypto-bot/`) |
|---|---|---|
| **Runtime** | Python 3.9+ | PHP 8.1+ with extensions `curl`, `sqlite3`, `gd` |
| **Dependencies** | `requests`, `matplotlib` (via pip) | No external packages (uses built-in curl, gd) |
| **Performance** | Higher throughput with persistent connection pooling | Slightly lower, adequate for single-bot use |
| **Chart Engine** | Matplotlib (smoother visuals) | PHP GD (basic PNG rendering) |
| **Async Ready** | Can be extended with asyncio/aiohttp | Synchronous only |
| **Hosting Compatibility** | VPS, cloud servers, Raspberry Pi | Shared hosting, cPanel, cheap PHP hosts |
| **Memory Footprint** | ~50-80 MB | ~20-40 MB |
| **Setup Complexity** | pip install + env vars | PHP extensions + env vars |
| **Image Quality** | High-quality matplotlib charts | Basic GD-based line charts |
| **File Size** | ~1.5 MB (with deps) | ~200 KB (no deps) |

**Which one should you use?**
- **Use Python** if you have a VPS, prefer better charts, and want easier maintainability.
- **Use PHP** if you only have shared hosting, need minimal dependencies, or want the lightest footprint.

---

## Features

| Feature | Description |
|---|---|
| **Live Prices** | Real-time USD prices, 24h change, market cap for 25+ cryptocurrencies via CoinGecko |
| **Price Charts** | PNG charts with SMA overlays (1d / 7d / 30d / 90d) |
| **Technical Analysis** | RSI(14), MACD, SMA50/200, Bollinger Bands, support/resistance, BUY/SELL/HOLD signals |
| **Wallet Intelligence** | Full BTC address lookups: balance, received, sent, UTXOs, recent transactions |
| **Wallet Tracking** | Watchlist with labels, auto-refresh balances across all tracked wallets |
| **Mempool Monitor** | Pending tx count, mempool size, fee estimates (sat/vB), recent transactions |
| **Price Alerts** | Set above/below BTC price targets; auto-notify when triggered (5-min check interval) |
| **Admin Panel** | User management, ban/unban, broadcast messages, usage statistics |
| **Interactive UI** | Inline keyboard buttons for all commands, plus keyboard shortcuts |
| **Proxy Support** | Built-in proxy support for HTTP/HTTPS/SOCKS5; custom API base URL for Cloudflare Workers |
| **Persistent Storage** | SQLite database with WAL mode — users, wallets, alerts, command logs |

---

## Architecture

```
Telegram User
     |
     v
Telegram Bot API  <-->  [ Cloudflare Worker Proxy ]  <-->  Bot Process
                                                                |
                                    +-----------+-----------+---+
                                    |           |           |
                                    v           v           v
                              CoinGecko   Blockchain   Mempool.space
                                            .info
```

The bot uses **long-polling** (`getUpdates` with a 30-second timeout) to receive messages. All API calls go through configurable proxy support, allowing operation in regions where Telegram is restricted.

---

## Project Structure

```
Crypto Bot/
|
+-- crypto-bot-py/          # Python implementation
|   +-- main.py             # Entry point, command routing, message handling
|   +-- config.py           # Environment variable configuration
|   +-- requirements.txt    # Python dependencies
|   +-- lib/
|       +-- telegram_api.py     # Telegram Bot API wrapper
|       +-- coingecko_api.py    # CoinGecko API client
|       +-- blockchain_api.py   # Blockchain.info + Mempool.space client
|       +-- database.py         # SQLite database layer
|       +-- price_predictor.py  # Technical analysis engine
|       +-- chart_generator.py  # Matplotlib chart generation
|       +-- helpers.py          # Formatting utilities
|
+-- crypto-bot/             # PHP implementation
|   +-- bot.php             # Entry point, command routing
|   +-- config.php          # Configuration (reads env vars)
|   +-- lib/
|       +-- TelegramApi.php     # Telegram Bot API wrapper
|       +-- CoinGeckoApi.php    # CoinGecko API client
|       +-- BlockchainApi.php   # Blockchain.info + Mempool.space client
|       +-- Database.php        # SQLite database layer
|       +-- PricePredictor.php  # Technical analysis engine
|       +-- ChartGenerator.php  # GD-based chart generation
|       +-- Helpers.php         # Formatting utilities
|
+-- .gitignore
+-- LICENSE
+-- README.md
```

---

## Setup & Installation

### Prerequisites

- **Python bot**: Python 3.9+, pip
- **PHP bot**: PHP 8.1+, extensions: `curl`, `sqlite3`, `gd`
- A Telegram Bot Token from [@BotFather](https://t.me/BotFather)
- (Optional) A Cloudflare Workers proxy URL if Telegram is blocked in your region

### Python Bot Setup

```bash
# 1. Clone the repository
git clone https://github.com/clayhackergroup/buzz-crypto.git
cd buzz-crypto/crypto-bot-py

# 2. Create virtual environment (recommended)
python -m venv venv
source venv/bin/activate   # Linux/Mac
# .\venv\Scripts\activate  # Windows

# 3. Install dependencies
pip install -r requirements.txt

# 4. Set environment variables
export CRYPTO_BOT_TOKEN="your_bot_token_here"
export CRYPTO_BOT_ADMIN_ID="your_telegram_user_id"
export BOT_API_URL="https://api.telegram.org/bot"   # or proxy URL

# 5. Run the bot
python main.py
```

### PHP Bot Setup

```bash
# 1. Clone the repository
git clone https://github.com/clayhackergroup/buzz-crypto.git
cd buzz-crypto/crypto-bot

# 2. Set environment variables
export CRYPTO_BOT_TOKEN="your_bot_token_here"
export CRYPTO_BOT_ADMIN_ID="your_telegram_user_id"

# 3. Run the bot
php bot.php
```

### Proxy Configuration (for restricted regions)

If Telegram is blocked in your country (e.g., India, Iran, UAE), use a proxy or Cloudflare Worker:

#### Option A: Cloudflare Worker Proxy (Recommended)

Deploy this [Cloudflare Worker](https://github.com/AmRo045/telegram-api-proxy-worker) and set:

```bash
# Python
export BOT_API_URL="https://your-worker.workers.dev/bot"

# PHP (also reads BOT_API_URL env var)
export BOT_API_URL="https://your-worker.workers.dev/bot"
```

#### Option B: HTTP/SOCKS5 Proxy

```bash
export BOT_PROXY="http://your-proxy:port"
# or
export BOT_PROXY="socks5://127.0.0.1:1080"
```

The bot will route all API calls (Telegram, CoinGecko, Blockchain.info, Mempool.space) through the configured proxy.

---

## Configuration

All configuration is done via environment variables (no hardcoded secrets):

| Variable | Required | Default | Description |
|---|---|---|---|
| `CRYPTO_BOT_TOKEN` | Yes | — | Telegram Bot Token from @BotFather |
| `CRYPTO_BOT_ADMIN_ID` | Yes | — | Your Telegram user ID (admin privileges) |
| `BOT_API_URL` | No | `https://api.telegram.org/bot` | Custom API base URL (for proxies) |
| `BOT_PROXY` | No | — | HTTP/SOCKS5 proxy for all API calls |

---

## Usage

### User Commands

| Command | Description |
|---|---|
| `/start` or `/help` | Show welcome message with interactive buttons |
| `/price [coin...]` | Live prices (default: top 12). E.g., `/price btc eth sol` |
| `/chart [coin] [tf]` | Price chart with SMA overlays. Timeframes: `1d`, `7d`, `30d`, `90d` |
| `/predict [coin]` | Technical analysis: RSI, MACD, SMA, Bollinger, signal |
| `/info [address]` | Full Bitcoin wallet intelligence |
| `/watch [address] [label]` | Track a Bitcoin wallet |
| `/wallets` | List all tracked wallets with balances |
| `/unwatch [address]` | Stop tracking a wallet |
| `/alert [above\|below] [price]` | Set BTC price alert |
| `/alerts` | View and manage alerts |
| `/mempool` | Bitcoin mempool statistics and fee estimates |

### Admin Commands

Only the user with `CRYPTO_BOT_ADMIN_ID` can execute:

| Command | Description |
|---|---|
| `/admin` | Admin panel with stats |
| `/users` | List all registered users |
| `/stats` | Detailed bot statistics |
| `/ban [user_id]` | Ban a user |
| `/unban [user_id]` | Unban a user |
| `/broadcast [message]` | Send message to all users |

---

## How It Works

### Message Flow

1. User sends a message or taps a button in Telegram
2. Bot polls `getUpdates` (30s long-poll) to receive the update
3. `processUpdate()` routes the message to the appropriate command handler
4. Handler calls external APIs (CoinGecko, Blockchain.info, Mempool.space) as needed
5. Response is formatted and sent back via `sendMessage` or `editMessageText`
6. All interactions are logged to SQLite for analytics

### Polling Loop

```
while True:
    for update in getUpdates(offset, timeout=30):
        processUpdate(update)
        offset = update.id + 1
    checkPriceAlerts()   # every 5 minutes
```

### Technical Analysis Calculation

- **RSI(14)**: Relative Strength Index using average gain/loss over 14 periods
- **MACD**: 12-period EMA minus 26-period EMA, with 9-period signal line
- **SMA50/200**: Simple Moving Averages over 50 and 200 periods
- **Bollinger Bands**: 20-period SMA with 2-standard-deviation bands
- **Overall Signal**: Weighted score from all indicators -> BUY/SELL/HOLD

### Price Alert System

- Every 5 minutes, the bot fetches the current BTC price
- Compares against all active alerts across all users
- If triggered, sends a notification and marks the alert as triggered

---

## API Dependencies

| Service | Usage | Rate Limits |
|---|---|---|
| [Telegram Bot API](https://core.telegram.org/bots/api) | Message delivery | 30 msg/sec per bot |
| [CoinGecko API](https://www.coingecko.com/en/api) | Price data, historical data, market data | 10-50 calls/min (free tier) |
| [Blockchain.info](https://www.blockchain.com/explorer/api/blockchain_api) | BTC address info, balances, UTXOs | No official limit; be reasonable |
| [Mempool.space](https://mempool.space/api) | Mempool stats, fee estimates | 1 req/sec recommended |

---

## Database Schema

The bot uses SQLite with WAL mode for persistent storage.

### `users`
| Column | Type | Description |
|---|---|---|
| `user_id` | INTEGER PK | Telegram user ID |
| `username` | TEXT | @username |
| `first_name` | TEXT | First name |
| `last_name` | TEXT | Last name |
| `first_seen` | TEXT | First interaction timestamp |
| `last_seen` | TEXT | Last interaction timestamp |
| `banned` | INTEGER | 1 if banned |
| `is_admin` | INTEGER | 1 if admin |

### `watched_wallets`
| Column | Type | Description |
|---|---|---|
| `id` | INTEGER PK | Auto-increment |
| `user_id` | INTEGER | Owner |
| `address` | TEXT | BTC address |
| `label` | TEXT | User-defined label |
| `created_at` | TIMESTAMP | When added |

### `price_alerts`
| Column | Type | Description |
|---|---|---|
| `id` | INTEGER PK | Auto-increment |
| `user_id` | INTEGER | Owner |
| `target_price` | REAL | Price threshold |
| `direction` | TEXT | 'above' or 'below' |
| `triggered` | INTEGER | 1 if already notified |
| `created_at` | TIMESTAMP | When created |

### `commands_log`
| Column | Type | Description |
|---|---|---|
| `id` | INTEGER PK | Auto-increment |
| `user_id` | INTEGER | Who executed |
| `command` | TEXT | Command name |
| `timestamp` | TEXT | When executed |

---

## Security & Responsibilities

### Credential Safety

- **NEVER hardcode bot tokens or admin IDs** in source code. Always use environment variables.
- The `.gitignore` excludes `*.db`, `__pycache__`, `.env`, and log files by default.
- If you accidentally commit credentials: rotate them immediately at [@BotFather](https://t.me/BotFather).

### Operational Responsibilities

| Responsibility | Description |
|---|---|
| **API Rate Limits** | Respect rate limits of CoinGecko, Blockchain.info, and Mempool.space. Excessive calls may get your IP throttled. |
| **User Privacy** | The bot stores Telegram user IDs and wallet addresses. Do not share or expose this data. |
| **Bot Security** | Use a strong, unique bot token. Restrict admin commands to trusted individuals only. |
| **Database Backups** | SQLite database is a single file (`crypto_bot.db`). Back it up regularly. |
| **Proxy Safety** | If using a proxy, ensure it is from a trusted provider. Proxies can see your traffic metadata. |

### Legal Compliance

- Users are responsible for complying with local laws regarding cryptocurrency tracking tools.
- The bot does not provide financial advice. Technical analysis signals are for informational purposes only.

---

## Support

### Documentation

- Full documentation is available in this README.
- For Telegram Bot API reference: [core.telegram.org/bots/api](https://core.telegram.org/bots/api)
- For CoinGecko API: [coingecko.com/en/api](https://www.coingecko.com/en/api)

### Contact & Community

| Platform | Handle / Link |
|---|---|
| **Telegram Support** | [@MeMrDefault](https://t.me/MeMrDefault) |
| **Instagram** | [@exp1oit](https://instagram.com/exp1oit) |
| **Instagram** | [@h4cker.in](https://instagram.com/h4cker.in) |

### Reporting Issues

Open an issue on [GitHub](https://github.com/clayhackergroup/buzz-crypto/issues) with:
- Bot version (Python or PHP)
- Full error message
- Steps to reproduce

---

## Development

### Developers

| Role | Name |
|---|---|
| **Lead Developer** | Spidey |

### Contributing

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

### Coding Standards

- Python: Follow PEP 8. Type hints required for all functions.
- PHP: Follow PSR-12. Strict typing enabled.
- All API calls must support proxy configuration.
- No hardcoded credentials or secrets.

---

## License

Distributed under the MIT License. See `LICENSE` for more information.

---

<br>

---

<p align="center">
  <a href="https://ovpnspider.com">
    <img src="https://play-lh.googleusercontent.com/PPOofCAhV2Ns08v2zGflMfak8P0wUKPpx1qb-wwpRJAPwyf2GY6Mv8iuBc_HX1sjFg=w128-h128-p" alt="OVPN Spider" width="100"/>
  </a>
  <br>
  <b>SPONSORED BY OVPN SPIDER</b>
  <br>
  <sub>Download on <a href="https://play.google.com/store/apps/details?id=com.ovpnspider">Google Play</a> — <a href="https://ovpnspider.com">ovpnspider.com</a></sub>
  <br><br>
  Made with by <a href="https://github.com/clayhackergroup">clayhackergroup</a>
</p>
