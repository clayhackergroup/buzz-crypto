import requests
from config import PROXY_DICT


BLOCKCHAIN_INFO = "https://blockchain.info"
MEMPOOL_SPACE = "https://mempool.space/api"


def _get(url: str) -> dict:
    resp = requests.get(url, timeout=20, headers={"Accept": "application/json"}, proxies=PROXY_DICT)
    resp.raise_for_status()
    data = resp.json()
    if data is None:
        raise RuntimeError(f"Invalid JSON response from {url}")
    return data


def get_address_info(address: str) -> dict:
    return _get(f"{BLOCKCHAIN_INFO}/rawaddr/{address}?limit=10")


def get_balance(addresses: list[str]) -> dict:
    active = "|".join(addresses)
    return _get(f"{BLOCKCHAIN_INFO}/balance?active={active}")


def get_latest_block() -> dict:
    return _get(f"{BLOCKCHAIN_INFO}/latestblock")


def get_unconfirmed_txs() -> dict:
    return _get(f"{BLOCKCHAIN_INFO}/unconfirmed-transactions?format=json")


def get_mempool_stats() -> dict:
    return _get(f"{MEMPOOL_SPACE}/mempool")


def get_mempool_recent() -> dict:
    return _get(f"{MEMPOOL_SPACE}/mempool/recent")


def get_fees_recommended() -> dict:
    return _get(f"{MEMPOOL_SPACE}/v1/fees/recommended")


def get_unspent_outputs(address: str) -> dict:
    return _get(f"{BLOCKCHAIN_INFO}/unspent?active={address}")


def get_address_full_info(address: str) -> dict:
    return _get(f"{BLOCKCHAIN_INFO}/rawaddr/{address}?limit=5")
