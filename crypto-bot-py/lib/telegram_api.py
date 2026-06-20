import json
import requests


from config import PROXY_DICT, BOT_API_URL


class TelegramApi:
    def __init__(self, token: str):
        self.api_url = f"{BOT_API_URL}{token}"
        self.proxies = PROXY_DICT

    def _call(self, method: str, params: dict | None = None) -> dict:
        url = f"{self.api_url}/{method}"
        resp = requests.post(url, data=params, timeout=35, proxies=self.proxies)
        resp.raise_for_status()
        data = resp.json()
        if not data.get("ok"):
            raise RuntimeError(f"Telegram API: {data.get('description', 'unknown error')}")
        return data

    def get_updates(self, offset: int = 0, timeout: int = 30) -> list:
        data = self._call("getUpdates", {
            "offset": offset,
            "timeout": timeout,
            "allowed_updates": json.dumps(["message", "callback_query"]),
        })
        return data.get("result", [])

    def send_message(self, chat_id: int, text: str, parse_mode: str = "Markdown",
                     reply_markup: dict | None = None) -> dict:
        params = {
            "chat_id": chat_id,
            "text": text,
            "parse_mode": parse_mode,
        }
        if reply_markup is not None:
            params["reply_markup"] = json.dumps(reply_markup)
        return self._call("sendMessage", params)

    def edit_message_text(self, chat_id: int, message_id: int, text: str,
                          parse_mode: str = "Markdown",
                          reply_markup: dict | None = None) -> dict:
        params = {
            "chat_id": chat_id,
            "message_id": message_id,
            "text": text,
            "parse_mode": parse_mode,
        }
        if reply_markup is not None:
            params["reply_markup"] = json.dumps(reply_markup)
        return self._call("editMessageText", params)

    def send_photo(self, chat_id: int, photo_path: str, caption: str = "") -> dict:
        url = f"{self.api_url}/sendPhoto"
        with open(photo_path, "rb") as f:
            resp = requests.post(
                url,
                files={"photo": f},
                data={"chat_id": chat_id, "caption": caption},
                timeout=30,
                proxies=self.proxies,
            )
        resp.raise_for_status()
        data = resp.json()
        if not data.get("ok"):
            raise RuntimeError(f"Telegram API: {data.get('description', 'unknown error')}")
        return data

    def answer_callback_query(self, callback_query_id: str, text: str = "") -> None:
        self._call("answerCallbackQuery", {
            "callback_query_id": callback_query_id,
            "text": text,
        })

    def delete_message(self, chat_id: int, message_id: int) -> None:
        self._call("deleteMessage", {
            "chat_id": chat_id,
            "message_id": message_id,
        })
