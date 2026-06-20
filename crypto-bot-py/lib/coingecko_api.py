import requests
from config import PROXY_DICT


BASE = "https://api.coingecko.com/api/v3"


def _get(path: str, params: dict | None = None) -> dict:
    url = f"{BASE}{path}"
    if params:
        url += "?" + "&".join(f"{k}={v}" for k, v in params.items())
    resp = requests.get(url, timeout=20, headers={"Accept": "application/json"}, proxies=PROXY_DICT)
    resp.raise_for_status()
    data = resp.json()
    if data is None:
        raise RuntimeError(f"Invalid JSON from CoinGecko ({url})")
    if isinstance(data, dict) and "status" in data and "error_code" in data.get("status", {}):
        raise RuntimeError(f"CoinGecko: {data['status'].get('error_message', 'unknown error')}")
    return data


def get_prices(ids: list[str]) -> dict:
    return _get("/simple/price", {
        "ids": ",".join(ids),
        "vs_currencies": "usd",
        "include_24hr_change": "true",
        "include_market_cap": "true",
    })


def get_historical_data(coin_id: str, days: int = 7) -> dict:
    return _get(f"/coins/{coin_id}/market_chart", {
        "vs_currency": "usd",
        "days": str(days),
    })


def get_coin_info(coin_id: str) -> dict:
    return _get(f"/coins/{coin_id}", {
        "localization": "false",
        "tickers": "false",
        "community_data": "false",
        "developer_data": "false",
    })
