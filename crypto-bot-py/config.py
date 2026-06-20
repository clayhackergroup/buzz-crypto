import os
import sys


BOT_TOKEN = os.environ.get("CRYPTO_BOT_TOKEN", "")
if not BOT_TOKEN:
    print("ERROR: CRYPTO_BOT_TOKEN environment variable is not set", file=sys.stderr)
    sys.exit(1)

ADMIN_ID = int(os.environ.get("CRYPTO_BOT_ADMIN_ID", "0"))
if ADMIN_ID == 0:
    print("ERROR: CRYPTO_BOT_ADMIN_ID environment variable is not set", file=sys.stderr)
    sys.exit(1)

BOT_PROXY = os.environ.get("BOT_PROXY", "")
PROXY_DICT = {"http": BOT_PROXY, "https": BOT_PROXY} if BOT_PROXY else None

BOT_API_URL = os.environ.get("BOT_API_URL", "https://api.telegram.org/bot")

COINS = {
    "btc":  {"id": "bitcoin",            "sym": "BTC",  "name": "Bitcoin"},
    "eth":  {"id": "ethereum",           "sym": "ETH",  "name": "Ethereum"},
    "bnb":  {"id": "binancecoin",        "sym": "BNB",  "name": "BNB"},
    "sol":  {"id": "solana",             "sym": "SOL",  "name": "Solana"},
    "xrp":  {"id": "ripple",             "sym": "XRP",  "name": "XRP"},
    "ada":  {"id": "cardano",            "sym": "ADA",  "name": "Cardano"},
    "doge": {"id": "dogecoin",           "sym": "DOGE", "name": "Dogecoin"},
    "avax": {"id": "avalanche-2",        "sym": "AVAX", "name": "Avalanche"},
    "dot":  {"id": "polkadot",           "sym": "DOT",  "name": "Polkadot"},
    "matic":{"id": "matic-network",      "sym": "MATIC","name": "Polygon"},
    "shib": {"id": "shiba-inu",          "sym": "SHIB", "name": "Shiba Inu"},
    "trx":  {"id": "tron",               "sym": "TRX",  "name": "TRON"},
    "ltc":  {"id": "litecoin",           "sym": "LTC",  "name": "Litecoin"},
    "bch":  {"id": "bitcoin-cash",       "sym": "BCH",  "name": "Bitcoin Cash"},
    "link": {"id": "chainlink",          "sym": "LINK", "name": "Chainlink"},
    "uni":  {"id": "uniswap",            "sym": "UNI",  "name": "Uniswap"},
    "atom": {"id": "cosmos",             "sym": "ATOM", "name": "Cosmos"},
    "xlm":  {"id": "stellar",            "sym": "XLM",  "name": "Stellar"},
    "vet":  {"id": "vechain",            "sym": "VET",  "name": "VeChain"},
    "near": {"id": "near",               "sym": "NEAR", "name": "Near Protocol"},
    "apt":  {"id": "aptos",              "sym": "APT",  "name": "Aptos"},
    "arb":  {"id": "arbitrum",           "sym": "ARB",  "name": "Arbitrum"},
    "op":   {"id": "optimism",           "sym": "OP",   "name": "Optimism"},
    "sui":  {"id": "sui",                "sym": "SUI",  "name": "Sui"},
    "pepe": {"id": "pepe",               "sym": "PEPE", "name": "Pepe"},
    "inj":  {"id": "injective-protocol", "sym": "INJ",  "name": "Injective"},
}

DEFAULT_COIN_KEYS = ["btc", "eth", "bnb", "sol", "xrp", "ada", "doge",
                     "avax", "dot", "matic", "ltc", "link"]


def resolve_coin(input_str: str) -> dict | None:
    key = input_str.lower()
    if key in COINS:
        return COINS[key]
    for c in COINS.values():
        if c["sym"].lower() == key or c["name"].lower() == key:
            return c
    return None


def get_coin_ids() -> list[str]:
    return [c["id"] for c in COINS.values()]


def get_default_coins() -> list[dict]:
    return [COINS[k] for k in DEFAULT_COIN_KEYS]


# BTC address validation helper
def is_valid_btc_address(address: str) -> bool:
    if not address or len(address) < 26 or len(address) > 62:
        return False
    if address.startswith("1") or address.startswith("3"):
        return all(c in "123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz" for c in address)
    if address.startswith("bc1"):
        return all(c in "qpzry9x8gf2tvdw0s3jn54khce6mua7l" for c in address[2:].lower())
    return False
