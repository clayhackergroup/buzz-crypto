import sqlite3


class Database:
    def __init__(self, db_path: str, admin_id: int):
        self.db_path = db_path
        self.admin_id = admin_id
        self._init()

    def _get_conn(self) -> sqlite3.Connection:
        conn = sqlite3.connect(self.db_path)
        conn.row_factory = sqlite3.Row
        conn.execute("PRAGMA journal_mode=WAL")
        conn.execute("PRAGMA foreign_keys=ON")
        return conn

    def _init(self) -> None:
        with self._get_conn() as db:
            db.executescript("""
                CREATE TABLE IF NOT EXISTS users (
                    user_id INTEGER PRIMARY KEY,
                    username TEXT DEFAULT '',
                    first_name TEXT DEFAULT '',
                    last_name TEXT DEFAULT '',
                    first_seen TEXT,
                    last_seen TEXT,
                    banned INTEGER DEFAULT 0,
                    is_admin INTEGER DEFAULT 0
                );
                CREATE TABLE IF NOT EXISTS commands_log (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER NOT NULL,
                    command TEXT NOT NULL,
                    timestamp TEXT DEFAULT CURRENT_TIMESTAMP
                );
                CREATE TABLE IF NOT EXISTS watched_wallets (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER NOT NULL,
                    address TEXT NOT NULL,
                    label TEXT DEFAULT '',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE(user_id, address)
                );
                CREATE TABLE IF NOT EXISTS price_alerts (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER NOT NULL,
                    target_price REAL NOT NULL,
                    direction TEXT NOT NULL DEFAULT 'below',
                    triggered INTEGER NOT NULL DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                );
            """)
            db.execute("UPDATE users SET is_admin = 1 WHERE user_id = ?", (self.admin_id,))

    # --- Users ---

    def register_user(self, user_id: int, username: str = "", first_name: str = "",
                      last_name: str = "") -> None:
        with self._get_conn() as db:
            db.execute("""
                INSERT INTO users (user_id, username, first_name, last_name, first_seen, last_seen)
                VALUES (?, ?, ?, ?, datetime('now'), datetime('now'))
                ON CONFLICT(user_id) DO UPDATE SET
                    username = COALESCE(NULLIF(?, ''), username),
                    first_name = COALESCE(NULLIF(?, ''), first_name),
                    last_name = COALESCE(NULLIF(?, ''), last_name),
                    last_seen = datetime('now')
            """, (user_id, username, first_name, last_name, username, first_name, last_name))

    def is_banned(self, user_id: int) -> bool:
        with self._get_conn() as db:
            row = db.execute("SELECT banned FROM users WHERE user_id = ?", (user_id,)).fetchone()
            return bool(row["banned"]) if row else False

    def is_admin(self, user_id: int) -> bool:
        return user_id == self.admin_id

    def ban_user(self, user_id: int) -> bool:
        with self._get_conn() as db:
            cur = db.execute("UPDATE users SET banned = 1 WHERE user_id = ?", (user_id,))
            return cur.rowcount > 0

    def unban_user(self, user_id: int) -> bool:
        with self._get_conn() as db:
            cur = db.execute("UPDATE users SET banned = 0 WHERE user_id = ?", (user_id,))
            return cur.rowcount > 0

    def get_all_users(self) -> list[dict]:
        with self._get_conn() as db:
            rows = db.execute("SELECT * FROM users ORDER BY last_seen DESC").fetchall()
            return [dict(r) for r in rows]

    def get_all_user_ids(self) -> list[int]:
        with self._get_conn() as db:
            return [r[0] for r in db.execute("SELECT user_id FROM users WHERE banned = 0").fetchall()]

    def get_user(self, user_id: int) -> dict | None:
        with self._get_conn() as db:
            row = db.execute("SELECT * FROM users WHERE user_id = ?", (user_id,)).fetchone()
            return dict(row) if row else None

    def get_stats(self) -> dict:
        with self._get_conn() as db:
            return {
                "total": db.execute("SELECT COUNT(*) FROM users").fetchone()[0],
                "active_24h": db.execute(
                    "SELECT COUNT(*) FROM users WHERE last_seen >= datetime('now', '-1 day')"
                ).fetchone()[0],
                "active_7d": db.execute(
                    "SELECT COUNT(*) FROM users WHERE last_seen >= datetime('now', '-7 days')"
                ).fetchone()[0],
                "banned": db.execute("SELECT COUNT(*) FROM users WHERE banned = 1").fetchone()[0],
                "total_wallets": db.execute("SELECT COUNT(*) FROM watched_wallets").fetchone()[0],
                "active_alerts": db.execute(
                    "SELECT COUNT(*) FROM price_alerts WHERE triggered = 0"
                ).fetchone()[0],
            }

    def get_command_stats(self) -> list[dict]:
        with self._get_conn() as db:
            rows = db.execute("""
                SELECT command, COUNT(*) as count
                FROM commands_log
                WHERE timestamp >= datetime('now', '-7 days')
                GROUP BY command
                ORDER BY count DESC
                LIMIT 15
            """).fetchall()
            return [dict(r) for r in rows]

    # --- Command Logging ---

    def log_command(self, user_id: int, command: str) -> None:
        with self._get_conn() as db:
            db.execute("INSERT INTO commands_log (user_id, command) VALUES (?, ?)",
                       (user_id, command))

    def get_user_command_count(self, user_id: int) -> int:
        with self._get_conn() as db:
            return db.execute(
                "SELECT COUNT(*) FROM commands_log WHERE user_id = ?", (user_id,)
            ).fetchone()[0]

    # --- Wallets ---

    def add_wallet(self, user_id: int, address: str, label: str = "") -> bool:
        try:
            with self._get_conn() as db:
                db.execute(
                    "INSERT INTO watched_wallets (user_id, address, label) VALUES (?, ?, ?)",
                    (user_id, address, label),
                )
                return True
        except sqlite3.IntegrityError:
            return False

    def remove_wallet(self, user_id: int, address: str) -> bool:
        with self._get_conn() as db:
            cur = db.execute(
                "DELETE FROM watched_wallets WHERE user_id = ? AND address = ?",
                (user_id, address),
            )
            return cur.rowcount > 0

    def get_wallets(self, user_id: int) -> list[dict]:
        with self._get_conn() as db:
            rows = db.execute(
                "SELECT address, label, created_at FROM watched_wallets "
                "WHERE user_id = ? ORDER BY created_at DESC",
                (user_id,),
            ).fetchall()
            return [dict(r) for r in rows]

    def get_total_wallets(self) -> int:
        with self._get_conn() as db:
            return db.execute("SELECT COUNT(*) FROM watched_wallets").fetchone()[0]

    # --- Alerts ---

    def add_alert(self, user_id: int, target_price: float, direction: str = "below") -> None:
        with self._get_conn() as db:
            db.execute(
                "INSERT INTO price_alerts (user_id, target_price, direction) VALUES (?, ?, ?)",
                (user_id, target_price, direction),
            )

    def get_alerts(self, user_id: int, only_active: bool = True) -> list[dict]:
        sql = ("SELECT id, target_price, direction, triggered, created_at "
               "FROM price_alerts WHERE user_id = ?")
        if only_active:
            sql += " AND triggered = 0"
        sql += " ORDER BY created_at DESC"
        with self._get_conn() as db:
            rows = db.execute(sql, (user_id,)).fetchall()
            return [dict(r) for r in rows]

    def get_all_active_alerts(self) -> list[dict]:
        with self._get_conn() as db:
            rows = db.execute(
                "SELECT id, user_id, target_price, direction "
                "FROM price_alerts WHERE triggered = 0"
            ).fetchall()
            return [dict(r) for r in rows]

    def mark_alert_triggered(self, alert_id: int) -> None:
        with self._get_conn() as db:
            db.execute("UPDATE price_alerts SET triggered = 1 WHERE id = ?", (alert_id,))

    def delete_alert(self, alert_id: int, user_id: int) -> bool:
        with self._get_conn() as db:
            cur = db.execute(
                "DELETE FROM price_alerts WHERE id = ? AND user_id = ?",
                (alert_id, user_id),
            )
            return cur.rowcount > 0

    def get_total_active_alerts(self) -> int:
        with self._get_conn() as db:
            return db.execute(
                "SELECT COUNT(*) FROM price_alerts WHERE triggered = 0"
            ).fetchone()[0]
