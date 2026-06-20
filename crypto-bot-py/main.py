import logging
import os
import re
import time

from config import (
    BOT_TOKEN, ADMIN_ID, COINS, resolve_coin, get_default_coins,
    is_valid_btc_address,
)
from lib import (
    database as db,
    telegram_api as tg,
    blockchain_api as bc,
    coingecko_api as cg,
    price_predictor as predictor,
    chart_generator as chart,
    helpers as hlp,
)

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(message)s",
)
log = logging.getLogger(__name__)

DB_PATH = os.path.join(os.path.dirname(__file__), "crypto_bot.db")
ALT_CHECK_INTERVAL = 300  # 5 minutes


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def chat_id(msg: dict) -> int:
    return msg["chat"]["id"]


def user_id(msg: dict) -> int:
    return msg["from"]["id"]


def extract_command(text: str) -> str:
    return text.strip().lower().split()[0].split("@")[0]


def is_admin(uid: int) -> bool:
    return uid == ADMIN_ID


def register_and_check(msg: dict, dbase: db.Database) -> int | None:
    uid = user_id(msg)
    from_ = msg.get("from", {})
    dbase.register_user(uid, from_.get("username", ""),
                        from_.get("first_name", ""), from_.get("last_name", ""))
    if dbase.is_banned(uid):
        return None
    return uid


def send_or_edit(api: tg.TelegramApi, chat_id: int, text: str,
                 edit_msg_id: int | None = None,
                 keyboard: dict | None = None) -> int:
    if edit_msg_id:
        api.edit_message_text(chat_id, edit_msg_id, text, "HTML", keyboard)
        return edit_msg_id
    r = api.send_message(chat_id, text, "HTML", keyboard)
    return r.get("result", {}).get("message_id", 0)


def admin_keyboard() -> dict:
    return {
        "inline_keyboard": [[
            {"text": "Users", "callback_data": "admin:users"},
            {"text": "Stats", "callback_data": "admin:stats"},
            {"text": "Broadcast", "callback_data": "admin:broadcast"},
        ]]
    }


# ---------------------------------------------------------------------------
# BTC address validation helper
# ---------------------------------------------------------------------------

def validate_btc_address(address: str) -> bool:
    return is_valid_btc_address(address)


# ---------------------------------------------------------------------------
# /start /help
# ---------------------------------------------------------------------------

def cmd_start(msg: dict, api: tg.TelegramApi, dbase: db.Database) -> None:
    chat = chat_id(msg)
    uid = user_id(msg)
    dbase.log_command(uid, "/start")
    lines = [
        "<b>CRYPTO BOT</b>",
        "<i>Track. Analyse. Alert.</i>",
        "",
        "Monitor Bitcoin wallets, mempool stats, price alerts, and technical analysis - all in one place.",
        "",
        "<b>How to use:</b>",
        "Tap a button below, or type commands directly.",
    ]
    kb = {
        "inline_keyboard": [
            [
                {"text": "PRICE", "callback_data": "cmd:price"},
                {"text": "CHART", "callback_data": "cmd:chart"},
                {"text": "PREDICT", "callback_data": "cmd:predict"},
            ],
            [
                {"text": "WALLET INFO", "callback_data": "cmd:info"},
                {"text": "WATCH", "callback_data": "cmd:watch"},
                {"text": "WALLETS", "callback_data": "cmd:wallets"},
            ],
            [
                {"text": "MEMPOOL", "callback_data": "cmd:mempool"},
                {"text": "SET ALERT", "callback_data": "cmd:alert"},
                {"text": "MY ALERTS", "callback_data": "cmd:alerts"},
            ],
        ]
    }
    if is_admin(uid):
        kb["inline_keyboard"].append([
            {"text": "ADMIN", "callback_data": "admin:panel"},
        ])
    api.send_message(chat, "\n".join(lines), "HTML", kb)


def cmd_help(msg: dict, api: tg.TelegramApi, dbase: db.Database) -> None:
    cmd_start(msg, api, dbase)


# ---------------------------------------------------------------------------
# /price
# ---------------------------------------------------------------------------

def cmd_price(msg: dict, api: tg.TelegramApi, dbase: db.Database) -> None:
    chat = chat_id(msg)
    uid = user_id(msg)
    dbase.log_command(uid, "/price")
    parts = msg.get("text", "").split()[1:]

    if parts:
        coins = []
        for p in parts:
            c = resolve_coin(p)
            if c:
                coins.append(c)
        if not coins:
            api.send_message(chat, "No matching coins found. Try: btc, eth, sol, etc.")
            return
        ids = [c["id"] for c in coins]
        mid = send_or_edit(api, chat, "Fetching prices...")
        try:
            data = cg.get_prices(ids)
            lines = []
            for c in coins:
                d = data.get(c["id"])
                if not d:
                    continue
                p = d.get("usd", 0)
                ch = d.get("usd_24h_change", 0)
                mc = d.get("usd_market_cap", 0)
                lines.append(f"{c['sym']}  {hlp.format_price(p)}  {hlp.format_change(ch)}")
                lines.append(f"  Market Cap: ${mc:,.0f}")
                lines.append("")
            send_or_edit(api, chat, "\n".join(lines), mid)
        except Exception as e:
            send_or_edit(api, chat, f"Error: {e}", mid)
        return

    mid = send_or_edit(api, chat, "Fetching prices...")
    try:
        all_ids = [c["id"] for c in COINS.values()]
        data = cg.get_prices(all_ids)
        lines = ["Top Cryptocurrencies", ""]
        for i, c in enumerate(COINS.values()):
            d = data.get(c["id"])
            if not d:
                continue
            p = d.get("usd", 0)
            ch = d.get("usd_24h_change", 0)
            sym = c["sym"].ljust(6)
            price = hlp.format_compact(p).rjust(12)
            change = hlp.format_change(ch).rjust(10)
            lines.append(f"<code>{sym}{price}{change}</code>")
            if i >= 19:
                break
        lines.append("")
        lines.append("Use /price [coin] for details (e.g. /price btc eth)")
        send_or_edit(api, chat, "\n".join(lines), mid)
    except Exception as e:
        send_or_edit(api, chat, f"Error: {e}", mid)


# ---------------------------------------------------------------------------
# /chart
# ---------------------------------------------------------------------------

def cmd_chart(msg: dict, api: tg.TelegramApi, dbase: db.Database) -> None:
    chat = chat_id(msg)
    uid = user_id(msg)
    dbase.log_command(uid, "/chart")
    parts = msg.get("text", "").split()[1:]

    coin = None
    days_map = {"1d": 1, "7d": 7, "30d": 30, "90d": 90}
    days = 7

    for p in parts:
        pl = p.lower()
        if pl in days_map:
            days = days_map[pl]
        else:
            c = resolve_coin(p)
            if c:
                coin = c

    if not coin:
        coin = COINS["btc"]
    label = next((k for k, v in days_map.items() if v == days), "7d")

    mid = send_or_edit(api, chat, f"Generating {coin['sym']} chart ({label})...")
    try:
        data = cg.get_historical_data(coin["id"], days)
        path = chart.generate_chart(data["prices"], days, coin["sym"])
        api.delete_message(chat, mid)
        api.send_photo(chat, path, f"{coin['sym']}/USD - Last {label}")
        os.unlink(path)
    except Exception as e:
        send_or_edit(api, chat, f"Error: {e}", mid)


# ---------------------------------------------------------------------------
# /predict
# ---------------------------------------------------------------------------

def cmd_predict(msg: dict, api: tg.TelegramApi, dbase: db.Database) -> None:
    chat = chat_id(msg)
    uid = user_id(msg)
    dbase.log_command(uid, "/predict")
    parts = msg.get("text", "").split()
    coin_arg = parts[1] if len(parts) > 1 else "btc"
    coin = resolve_coin(coin_arg) or COINS["btc"]

    mid = send_or_edit(api, chat, f"Running analysis on {coin['name']}...")
    try:
        r = predictor.analyze(coin["id"])
        rsi_label = "oversold" if r["rsi"] < 30 else ("overbought" if r["rsi"] > 70 else "neutral")

        sig_lines = []
        for s in r["signals"]:
            sig_lines.append(f"{s[2]} {s[0]}: {s[1]}")

        lines = [
            f"{coin['name']} ({coin['sym']}) Technical Analysis",
            "",
            f"Price: {hlp.format_price(r['current_price'])}",
            f"7d Change: {hlp.format_change(r['price_change_7d'])}",
            "",
            "Indicators:",
            f"  RSI(14): {r['rsi']:.1f} ({rsi_label})",
            f"  MACD: {'bullish' if r['macd']['bullish'] else 'bearish'}",
            f"  SMA50: {hlp.format_price(r['sma50'])}",
        ]
        if r["sma200"] is not None:
            lines.append(f"  SMA200: {hlp.format_price(r['sma200'])}")
        lines.append(f"  Support: {hlp.format_price(r['support'])}")
        lines.append(f"  Resistance: {hlp.format_price(r['resistance'])}")
        lines.append("")
        lines.append("Signals:")
        lines.extend(sig_lines)
        lines.append(f"Overall: {r['overall']}")

        send_or_edit(api, chat, "\n".join(lines), mid)
    except Exception as e:
        send_or_edit(api, chat, f"Error: {e}", mid)


# ---------------------------------------------------------------------------
# Wallet intel
# ---------------------------------------------------------------------------

def build_wallet_intel(address: str) -> str:
    current_price = hlp.get_current_price()
    info = bc.get_address_full_info(address)
    utxos_raw = bc.get_unspent_outputs(address)

    balance = (info.get("final_balance", 0)) / 1e8
    total_received = (info.get("total_received", 0)) / 1e8
    total_sent = (info.get("total_sent", 0)) / 1e8
    tx_count = info.get("n_tx", 0)
    hash160 = info.get("hash160", "")
    txs = info.get("txs", [])

    utxo_count = 0
    utxo_total = 0
    if "unspent_outputs" in utxos_raw:
        utxo_count = len(utxos_raw["unspent_outputs"])
        for u in utxos_raw["unspent_outputs"]:
            utxo_total += u.get("value", 0)
    utxo_total /= 1e8

    lines = [
        "Bitcoin Wallet",
        "",
        "Address:",
        f"<code>{address}</code>",
        "",
        f"Balance: <code>{balance:.8f} BTC</code>  ({hlp.format_price(balance * current_price)})",
        f"Received: <code>{total_received:.8f} BTC</code>  ({hlp.format_price(total_received * current_price)})",
        f"Sent: <code>{total_sent:.8f} BTC</code>  ({hlp.format_price(total_sent * current_price)})",
        "",
        f"Transactions: {tx_count}",
        f"UTXOs: {utxo_count} (sum: {utxo_total:.8f} BTC)",
        f"Hash160: <code>{hash160}</code>",
    ]

    if txs:
        lines.extend(["", "Recent Transactions:"])
        for tx in txs[:5]:
            txid = tx.get("hash", "")
            result = (tx.get("result", 0)) / 1e8
            tx_time = tx.get("time", 0)
            tx_balance = (tx.get("balance", 0)) / 1e8
            sign = "+" if result >= 0 else ""
            lines.extend([
                "",
                f"<code>{hlp.shorten_address(txid, 10)}</code>",
                f"  {sign}{result:.8f} BTC  ({hlp.format_time(tx_time)})",
                f"  Balance: {tx_balance:.8f} BTC",
            ])

    return "\n".join(lines)


def cmd_watch(msg: dict, api: tg.TelegramApi, dbase: db.Database) -> None:
    chat = chat_id(msg)
    uid = user_id(msg)
    parts = msg.get("text", "").split(maxsplit=2)
    if len(parts) < 2:
        api.send_message(chat, "Usage: /watch [address] [label]")
        return
    address = parts[1]
    label = parts[2] if len(parts) > 2 else ""
    if not validate_btc_address(address):
        api.send_message(chat, "Invalid Bitcoin address.")
        return
    dbase.log_command(uid, "/watch")
    mid = send_or_edit(api, chat, "Gathering wallet intel...")
    try:
        intel = build_wallet_intel(address)
        already = not dbase.add_wallet(uid, address, label)
        header = "Already tracking this wallet.\n\n" if already else "Wallet tracked.\n\n"
        send_or_edit(api, chat, header + intel, mid)
    except Exception as e:
        send_or_edit(api, chat, f"Error: {e}", mid)


def cmd_unwatch(msg: dict, api: tg.TelegramApi, dbase: db.Database) -> None:
    chat = chat_id(msg)
    uid = user_id(msg)
    parts = msg.get("text", "").split(maxsplit=1)
    if len(parts) < 2:
        api.send_message(chat, "Usage: /unwatch [address]")
        return
    dbase.log_command(uid, "/unwatch")
    if dbase.remove_wallet(uid, parts[1]):
        api.send_message(chat, f"Wallet removed:\n<code>{parts[1]}</code>")
    else:
        api.send_message(chat, "Wallet not found in your list.")


def cmd_wallets(msg: dict, api: tg.TelegramApi, dbase: db.Database) -> None:
    chat = chat_id(msg)
    uid = user_id(msg)
    dbase.log_command(uid, "/wallets")
    wallets = dbase.get_wallets(uid)
    if not wallets:
        api.send_message(chat, "No wallets tracked. Use /watch [address] to add one.")
        return
    mid = send_or_edit(api, chat, "Fetching wallet data...")
    try:
        current_price = hlp.get_current_price()
        addresses = [w["address"] for w in wallets]
        balance_data = bc.get_balance(addresses)
        parts = []
        for i, w in enumerate(wallets):
            bal_info = balance_data.get(w["address"], {})
            bal = (bal_info.get("final_balance", 0)) / 1e8
            total_recv = (bal_info.get("total_received", 0)) / 1e8
            tx_count = bal_info.get("n_tx", 0)
            label = f"  ({w['label']})" if w["label"] else ""
            if i > 0:
                parts.append("")
            parts.extend([
                f"Wallet #{i + 1}{label}",
                f"<code>{hlp.shorten_address(w['address'])}</code>",
                f"Balance: <code>{bal:.8f} BTC</code>  ({hlp.format_price(bal * current_price)})",
                f"Received: {total_recv:.4f} BTC  |  Txs: {tx_count}",
            ])
        send_or_edit(api, chat, "\n".join(parts), mid)
    except Exception as e:
        send_or_edit(api, chat, f"Error: {e}", mid)


def cmd_info(msg: dict, api: tg.TelegramApi, dbase: db.Database) -> None:
    chat = chat_id(msg)
    uid = user_id(msg)
    parts = msg.get("text", "").split(maxsplit=1)
    if len(parts) < 2:
        api.send_message(chat, "Usage: /info [address]")
        return
    address = parts[1]
    if not validate_btc_address(address):
        api.send_message(chat, "Invalid Bitcoin address.")
        return
    dbase.log_command(uid, "/info")
    mid = send_or_edit(api, chat, "Gathering wallet intel...")
    try:
        send_or_edit(api, chat, build_wallet_intel(address), mid)
    except Exception as e:
        send_or_edit(api, chat, f"Error: {e}", mid)


# ---------------------------------------------------------------------------
# /alert /alerts
# ---------------------------------------------------------------------------

def cmd_alert(msg: dict, api: tg.TelegramApi, dbase: db.Database) -> None:
    chat = chat_id(msg)
    uid = user_id(msg)
    parts = msg.get("text", "").split()[1:]
    if not parts:
        api.send_message(chat, "Usage: /alert [above|below] [price]\n"
                         "Example: /alert 70000\nExample: /alert above 85000")
        return
    direction = "below"
    price_arg = parts[0]
    if price_arg.lower() in ("above", "below"):
        direction = price_arg.lower()
        if len(parts) < 2:
            api.send_message(chat, "Please provide a price target.")
            return
        price_arg = parts[1]
    try:
        target = float(price_arg)
    except ValueError:
        api.send_message(chat, "Invalid price.")
        return
    dbase.add_alert(uid, target, direction)
    dbase.log_command(uid, "/alert")
    api.send_message(chat,
                     f"Alert set. You will be notified when BTC goes {direction} ${target:,.2f}.")


def cmd_alerts(msg: dict, api: tg.TelegramApi, dbase: db.Database) -> None:
    chat = chat_id(msg)
    uid = user_id(msg)
    dbase.log_command(uid, "/alerts")
    alerts = dbase.get_alerts(uid)
    if not alerts:
        api.send_message(chat, "No active alerts. Use /alert [price] to set one.")
        return
    lines = ["Your Price Alerts:"]
    buttons: dict = {"inline_keyboard": []}
    for a in alerts:
        created = (a.get("created_at") or "")[:16]
        lines.append(f"#{a['id']}  {a['direction']}  ${a['target_price']:,.0f}  ({created})")
        buttons["inline_keyboard"].append([
            {"text": f"Delete #{a['id']}", "callback_data": f"delete_alert:{a['id']}"}
        ])
    lines.extend(["", "Tap a button below to delete."])
    send_or_edit(api, chat, "\n".join(lines), keyboard=buttons)


# ---------------------------------------------------------------------------
# /mempool
# ---------------------------------------------------------------------------

def cmd_mempool(msg: dict, api: tg.TelegramApi, dbase: db.Database) -> None:
    chat = chat_id(msg)
    uid = user_id(msg)
    dbase.log_command(uid, "/mempool")
    mid = send_or_edit(api, chat, "Fetching mempool data...")
    try:
        stats = bc.get_mempool_stats()
        fees = bc.get_fees_recommended()
        recent = bc.get_mempool_recent()
        lines = [
            "Bitcoin Mempool", "",
            f"Pending: {stats.get('count', 0):,} transactions",
            f"Size: {(stats.get('vsize', 0) or 0) / 1e6:.1f} MB",
            f"Usage: {(stats.get('usage', 0) or 0) / 1e6:.1f} MB", "",
            "Fee Estimates:",
            f"  High: {fees.get('fastestFee', 0)} sat/vB",
            f"  Medium: {fees.get('halfHourFee', 0)} sat/vB",
            f"  Low: {fees.get('hourFee', 0)} sat/vB", "",
            "Recent Transactions:",
        ]
        for tx in recent[:5]:
            vsize = tx.get("vsize", 1)
            fee_rate = tx.get("fee", 0) / vsize if vsize > 0 else 0
            value = (tx.get("value", 0) or 0) / 1e8
            lines.append(
                f"<code>{hlp.shorten_address(tx.get('txid', ''), 8)}</code>  "
                f"{value:.6f} BTC  {fee_rate:.1f} sat/vB"
            )
        send_or_edit(api, chat, "\n".join(lines), mid)
    except Exception as e:
        send_or_edit(api, chat, f"Error: {e}", mid)


# ---------------------------------------------------------------------------
# Admin commands
# ---------------------------------------------------------------------------

def cmd_admin(msg: dict, api: tg.TelegramApi, dbase: db.Database) -> None:
    chat = chat_id(msg)
    uid = user_id(msg)
    if not is_admin(uid):
        api.send_message(chat, "Unauthorized.")
        return
    dbase.log_command(uid, "/admin")
    s = dbase.get_stats()
    api.send_message(chat,
                     f"Admin Panel\n\n"
                     f"Users: {s['total']}\n"
                     f"Active (24h): {s['active_24h']}\n"
                     f"Active (7d): {s['active_7d']}\n"
                     f"Banned: {s['banned']}\n\n"
                     f"Wallets: {s['total_wallets']}\n"
                     f"Alerts: {s['active_alerts']}",
                     "HTML", admin_keyboard())


def cmd_stats(msg: dict, api: tg.TelegramApi, dbase: db.Database) -> None:
    chat = chat_id(msg)
    uid = user_id(msg)
    if not is_admin(uid):
        api.send_message(chat, "Unauthorized.")
        return
    dbase.log_command(uid, "/stats")
    s = dbase.get_stats()
    cs = dbase.get_command_stats()
    lines = [
        "Bot Statistics", "",
        "Users:",
        f"  Total: {s['total']}",
        f"  Active 24h: {s['active_24h']}",
        f"  Active 7d: {s['active_7d']}",
        f"  Banned: {s['banned']}", "",
        "Data:",
        f"  Wallets: {s['total_wallets']}",
        f"  Alerts: {s['active_alerts']}", "",
        "Top Commands (7d):",
    ]
    for c in cs:
        lines.append(f"  {c['command']} - {c['count']}")
    send_or_edit(api, chat, "\n".join(lines))


def cmd_users(msg: dict, api: tg.TelegramApi, dbase: db.Database) -> None:
    chat = chat_id(msg)
    uid = user_id(msg)
    if not is_admin(uid):
        api.send_message(chat, "Unauthorized.")
        return
    dbase.log_command(uid, "/users")
    users = dbase.get_all_users()
    if not users:
        api.send_message(chat, "No users.")
        return
    lines = [f"Users ({len(users)} total):", ""]
    for u in users:
        name = u["first_name"] or "?"
        uname = f"@{u['username']}" if u["username"] else ""
        ls = (u.get("last_seen") or "?")[:10]
        badge = " [BANNED]" if u["banned"] else (" [ADMIN]" if u["is_admin"] else "")
        lines.append(f"<code>{u['user_id']}</code>  {uname}  {name}  {ls}{badge}")
    for chunk in (lines[i:i + 40] for i in range(0, len(lines), 40)):
        api.send_message(chat, "\n".join(chunk))


def cmd_ban(msg: dict, api: tg.TelegramApi, dbase: db.Database) -> None:
    chat = chat_id(msg)
    uid = user_id(msg)
    if not is_admin(uid):
        api.send_message(chat, "Unauthorized.")
        return
    parts = msg.get("text", "").split(maxsplit=1)
    if len(parts) < 2:
        api.send_message(chat, "Usage: /ban [user_id]")
        return
    try:
        target = int(parts[1])
    except ValueError:
        api.send_message(chat, "Usage: /ban [user_id]")
        return
    if is_admin(target):
        api.send_message(chat, "Cannot ban the admin.")
        return
    dbase.log_command(uid, "/ban")
    if dbase.ban_user(target):
        api.send_message(chat, f"User banned: <code>{target}</code>")
    else:
        api.send_message(chat, f"User not found: <code>{target}</code>")


def cmd_unban(msg: dict, api: tg.TelegramApi, dbase: db.Database) -> None:
    chat = chat_id(msg)
    uid = user_id(msg)
    if not is_admin(uid):
        api.send_message(chat, "Unauthorized.")
        return
    parts = msg.get("text", "").split(maxsplit=1)
    if len(parts) < 2:
        api.send_message(chat, "Usage: /unban [user_id]")
        return
    try:
        target = int(parts[1])
    except ValueError:
        api.send_message(chat, "Usage: /unban [user_id]")
        return
    dbase.log_command(uid, "/unban")
    if dbase.unban_user(target):
        api.send_message(chat, f"User unbanned: <code>{target}</code>")
    else:
        api.send_message(chat, f"User not found: <code>{target}</code>")


def cmd_broadcast(msg: dict, api: tg.TelegramApi, dbase: db.Database) -> None:
    chat = chat_id(msg)
    uid = user_id(msg)
    if not is_admin(uid):
        api.send_message(chat, "Unauthorized.")
        return
    parts = msg.get("text", "").split(maxsplit=1)
    if len(parts) < 2 or not parts[1].strip():
        api.send_message(chat, "Usage: /broadcast [message]")
        return
    text = parts[1].strip()
    dbase.log_command(uid, "/broadcast")
    ids = dbase.get_all_user_ids()
    sent = 0
    failed = 0
    for tid in ids:
        try:
            api.send_message(tid, f"Broadcast:\n\n{text}")
            sent += 1
        except Exception:
            failed += 1
        time.sleep(0.05)
    api.send_message(chat, f"Broadcast done.\nSent: {sent}\nFailed: {failed}\nTotal: {len(ids)}")


# ---------------------------------------------------------------------------
# Callback handler
# ---------------------------------------------------------------------------

def handle_callback(cb: dict, api: tg.TelegramApi, dbase: db.Database) -> None:
    data = cb.get("data", "")
    uid = cb["from"]["id"]
    cb_id = cb["id"]
    chat_id_ = cb.get("message", {}).get("chat", {}).get("id", 0)
    msg_id = cb.get("message", {}).get("message_id", 0)

    if data.startswith("delete_alert:"):
        aid = int(data.split(":")[1])
        if dbase.delete_alert(aid, uid):
            api.answer_callback_query(cb_id, "Deleted.")
            api.edit_message_text(chat_id_, msg_id, "Alert deleted.")
        else:
            api.answer_callback_query(cb_id, "Could not delete.")
        return

    if data.startswith("cmd:"):
        api.answer_callback_query(cb_id)
        cmd = data.split(":")[1]
        msg = dict(cb["message"])
        uid = cb["from"]["id"]
        msg["from"] = cb["from"]
        msg["text"] = f"/{cmd}"
        msg["chat"] = msg.get("chat", {"id": chat_id_, "type": "private"})
        process_update({"message": msg}, api, dbase)
        return

    if data.startswith("admin:"):
        if not is_admin(uid):
            api.answer_callback_query(cb_id, "Unauthorized.")
            return
        action = data.split(":")[1]
        api.answer_callback_query(cb_id)
        msg = cb["message"]
        if action == "panel":
            cmd_admin(msg, api, dbase)
        elif action == "users":
            cmd_users(msg, api, dbase)
        elif action == "stats":
            cmd_stats(msg, api, dbase)
        elif action == "broadcast":
            api.send_message(chat_id_, "Use /broadcast [message] to send to all users.")


# ---------------------------------------------------------------------------
# Price alert checker
# ---------------------------------------------------------------------------

def check_price_alerts(api: tg.TelegramApi, dbase: db.Database) -> None:
    try:
        data = cg.get_prices(["bitcoin"])
        current_price = data.get("bitcoin", {}).get("usd", 0)
        if current_price == 0:
            log.warning("Alert check: invalid price")
            return
    except Exception as e:
        log.warning(f"Alert check failed: {e}")
        return
    for a in dbase.get_all_active_alerts():
        triggered = (
            (a["direction"] == "below" and current_price <= a["target_price"]) or
            (a["direction"] == "above" and current_price >= a["target_price"])
        )
        if triggered:
            try:
                api.send_message(
                    a["user_id"],
                    f"Price Alert Triggered!\n\n"
                    f"BTC is now ${current_price:,.2f}\n"
                    f"Target: ${a['target_price']:,.2f} ({a['direction']})",
                )
                dbase.mark_alert_triggered(a["id"])
            except Exception as e:
                log.warning(f"Notify failed for {a['user_id']}: {e}")


# ---------------------------------------------------------------------------
# Router
# ---------------------------------------------------------------------------

def process_update(update: dict, api: tg.TelegramApi, dbase: db.Database) -> None:
    if "callback_query" in update:
        handle_callback(update["callback_query"], api, dbase)
        return
    msg = update.get("message", {})
    if not msg or "text" not in msg:
        return
    text = msg["text"]
    chat = msg["chat"]["id"]
    uid = register_and_check(msg, dbase)
    if uid is None:
        if msg.get("chat", {}).get("type") == "private":
            api.send_message(chat, "You are banned.")
        return
    log.info(f"Chat {chat}: {text}")
    cmd = extract_command(text)

    admin_cmds = {"/admin", "/stats", "/users", "/ban", "/unban", "/broadcast"}
    if cmd in admin_cmds and not is_admin(uid):
        api.send_message(chat, "Unauthorized.")
        return

    handlers = {
        "/start": cmd_start, "/help": cmd_help,
        "/price": cmd_price, "/chart": cmd_chart, "/predict": cmd_predict,
        "/watch": cmd_watch, "/unwatch": cmd_unwatch, "/wallets": cmd_wallets,
        "/info": cmd_info,
        "/alert": cmd_alert, "/alerts": cmd_alerts,
        "/mempool": cmd_mempool,
        "/admin": cmd_admin, "/stats": cmd_stats, "/users": cmd_users,
        "/ban": cmd_ban, "/unban": cmd_unban, "/broadcast": cmd_broadcast,
    }
    handler = handlers.get(cmd)
    if handler:
        handler(msg, api, dbase)


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

def main() -> None:
    dbase = db.Database(DB_PATH, ADMIN_ID)
    api = tg.TelegramApi(BOT_TOKEN)
    last_update_id = 0
    last_alert_check = 0.0
    log.info("Bot started. Polling for updates...")

    while True:
        try:
            now = time.time()
            if now - last_alert_check >= ALT_CHECK_INTERVAL:
                check_price_alerts(api, dbase)
                last_alert_check = now
            for update in api.get_updates(last_update_id + 1, 30):
                last_update_id = update["update_id"]
                process_update(update, api, dbase)
        except Exception as e:
            log.error(f"Error: {e}")
            time.sleep(2)


if __name__ == "__main__":
    main()
