<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/Database.php';
require_once __DIR__ . '/lib/TelegramApi.php';
require_once __DIR__ . '/lib/BlockchainApi.php';
require_once __DIR__ . '/lib/CoinGeckoApi.php';
require_once __DIR__ . '/lib/PricePredictor.php';
require_once __DIR__ . '/lib/Helpers.php';
require_once __DIR__ . '/lib/ChartGenerator.php';

function chatId(array $msg): int { return $msg['chat']['id']; }
function userId(array $msg): int { return $msg['from']['id']; }
function extractCommand(string $text): string { return strtolower(explode(' ', trim($text))[0]); }
function isAdmin(int $id): bool { return $id === ADMIN_ID; }

function registerAndCheck(array $msg, Database $db): ?int {
    $uid = userId($msg);
    $from = $msg['from'] ?? [];
    $db->registerUser($uid, $from['username'] ?? '', $from['first_name'] ?? '', $from['last_name'] ?? '');
    if ($db->isBanned($uid)) return null;
    return $uid;
}

function sendOrEdit(TelegramApi $tg, int $chatId, string $text, ?int $editMsgId = null, ?array $keyboard = null): int {
    if ($editMsgId) { $tg->editMessageText($chatId, $editMsgId, $text, 'HTML', $keyboard); return $editMsgId; }
    $r = $tg->sendMessage($chatId, $text, 'HTML', $keyboard);
    return $r['result']['message_id'] ?? 0;
}

function adminKeyboard(): array {
    return ['inline_keyboard' => [[
        ['text' => 'Users', 'callback_data' => 'admin:users'],
        ['text' => 'Stats', 'callback_data' => 'admin:stats'],
        ['text' => 'Broadcast', 'callback_data' => 'admin:broadcast'],
    ]]];
}

// ---------------------------------------------------------------------------
// /start /help
// ---------------------------------------------------------------------------

function cmdStart(array $msg, TelegramApi $tg, Database $db): void {
    $chat = chatId($msg); $uid = userId($msg); $db->logCommand($uid, '/start');
    $lines = [
        "Crypto Bot",
        "",
        "Track Bitcoin wallets, monitor the mempool, get price alerts, and technical analysis.",
        "",
        "Commands:",
        "/price [coin ...]  - Prices (default: top 12)",
        "/chart [coin] [tf]  - Price chart (tf: 1d/7d/30d/90d)",
        "/predict [coin]  - Technical analysis",
        "/mempool  - Mempool statistics",
        "",
        "/info [address]  - Full wallet intel",
        "/watch [address] [label]  - Track a wallet",
        "/wallets  - List tracked wallets",
        "/unwatch [address]  - Remove wallet",
        "",
        "/alert [above|below] [price]  - Price alert",
        "/alerts  - Manage alerts",
    ];
    if (isAdmin($uid)) { $lines[] = ""; $lines[] = "Admin:"; $lines[] = "/admin  - Admin panel"; }
    $tg->sendMessage($chat, implode("\n", $lines), 'HTML', isAdmin($uid) ? adminKeyboard() : null);
}
function cmdHelp(array $msg, TelegramApi $tg, Database $db): void { cmdStart($msg, $tg, $db); }

// ---------------------------------------------------------------------------
// /price
// ---------------------------------------------------------------------------

function cmdPrice(array $msg, TelegramApi $tg, Database $db): void {
    $chat = chatId($msg); $uid = userId($msg); $db->logCommand($uid, '/price');
    $parts = explode(' ', $msg['text'] ?? '');
    array_shift($parts);

    // Specific coins requested
    if (!empty($parts)) {
        $coins = [];
        foreach ($parts as $p) {
            $c = resolveCoin($p);
            if ($c) $coins[] = $c;
        }
        if (empty($coins)) { $tg->sendMessage($chat, "No matching coins found. Try: btc, eth, sol, etc."); return; }
        $ids = array_column($coins, 'id');
        $mid = sendOrEdit($tg, $chat, 'Fetching prices...');
        try {
            $data = CoinGeckoApi::getPrices($ids);
            $lines = [];
            foreach ($coins as $c) {
                $d = $data[$c['id']] ?? null;
                if (!$d) continue;
                $p = $d['usd'] ?? 0;
                $ch = $d['usd_24h_change'] ?? 0;
                $mc = $d['usd_market_cap'] ?? 0;
                $lines[] = $c['sym'] . "  " . Helpers::formatPrice($p) . "  " . Helpers::formatChange($ch);
                $lines[] = "  Market Cap: $" . number_format($mc, 0);
                $lines[] = "";
            }
            sendOrEdit($tg, $chat, implode("\n", $lines), $mid);
        } catch (Exception $e) { sendOrEdit($tg, $chat, "Error: " . $e->getMessage(), $mid); }
        return;
    }

    // Default: top 12 coins compact
    $mid = sendOrEdit($tg, $chat, 'Fetching prices...');
    try {
        $allIds = array_column(COINS, 'id');
        $data = CoinGeckoApi::getPrices($allIds);
        $lines = ["Top Cryptocurrencies", ""];
        $i = 0;
        foreach (COINS as $key => $c) {
            $d = $data[$c['id']] ?? null;
            if (!$d) continue;
            $p = $d['usd'] ?? 0;
            $ch = $d['usd_24h_change'] ?? 0;
            $sym = str_pad($c['sym'], 6, ' ');
            $price = str_pad(Helpers::formatCompact($p), 12, ' ', STR_PAD_LEFT);
            $change = str_pad(Helpers::formatChange($ch), 10, ' ', STR_PAD_LEFT);
            $lines[] = "<code>$sym$price$change</code>";
            $i++;
            if ($i >= 20) break;
        }
        $lines[] = "";
        $lines[] = "Use /price [coin] for details (e.g. /price btc eth)";
        sendOrEdit($tg, $chat, implode("\n", $lines), $mid);
    } catch (Exception $e) { sendOrEdit($tg, $chat, "Error: " . $e->getMessage(), $mid); }
}

// ---------------------------------------------------------------------------
// /chart
// ---------------------------------------------------------------------------

function cmdChart(array $msg, TelegramApi $tg, Database $db): void {
    $chat = chatId($msg); $uid = userId($msg); $db->logCommand($uid, '/chart');
    $parts = explode(' ', $msg['text'] ?? '');
    array_shift($parts);

    $coin = null;
    $days = 7;
    $daysMap = ['1d'=>1,'7d'=>7,'30d'=>30,'90d'=>90];

    foreach ($parts as $p) {
        $pl = strtolower($p);
        if (isset($daysMap[$pl])) { $days = $daysMap[$pl]; }
        else { $c = resolveCoin($p); if ($c) $coin = $c; }
    }

    if (!$coin) $coin = COINS['btc'];
    $label = array_search($days, $daysMap) ?: '7d';

    $mid = sendOrEdit($tg, $chat, "Generating {$coin['sym']} chart ($label)...");
    try {
        $data = CoinGeckoApi::getHistoricalData($coin['id'], $days);
        $path = ChartGenerator::generateChart($data['prices'], $days, $coin['sym']);
        $tg->deleteMessage($chat, $mid);
        $tg->sendPhoto($chat, $path, $coin['sym'] . "/USD - Last $label");
        unlink($path);
    } catch (Exception $e) { sendOrEdit($tg, $chat, "Error: " . $e->getMessage(), $mid); }
}

// ---------------------------------------------------------------------------
// /predict
// ---------------------------------------------------------------------------

function cmdPredict(array $msg, TelegramApi $tg, Database $db): void {
    $chat = chatId($msg); $uid = userId($msg); $db->logCommand($uid, '/predict');
    $parts = explode(' ', $msg['text'] ?? '');
    $coinArg = $parts[1] ?? 'btc';
    $coin = resolveCoin($coinArg) ?? COINS['btc'];

    $mid = sendOrEdit($tg, $chat, "Running analysis on {$coin['name']}...");
    try {
        $r = PricePredictor::analyze($coin['id']);
        $rsiLabel = $r['rsi'] < 30 ? 'oversold' : ($r['rsi'] > 70 ? 'overbought' : 'neutral');
        $sigLabels = ['bullish'=>'bullish','bearish'=>'bearish','neutral'=>'neutral'];

        $sigText = '';
        foreach ($r['signals'] as $s) $sigText .= "$sigLabels[$s[2]] $s[0]: $s[1]\n";

        $lines = [
            "$coin[name] ($coin[sym]) Technical Analysis",
            "",
            "Price: " . Helpers::formatPrice($r['current_price']),
            "7d Change: " . Helpers::formatChange($r['price_change_7d']),
            "",
            "Indicators:",
            "  RSI(14): " . number_format($r['rsi'], 1) . " ($rsiLabel)",
            "  MACD: " . ($r['macd']['bullish'] ? 'bullish' : 'bearish'),
            "  SMA50: " . Helpers::formatPrice($r['sma50']),
        ];
        if ($r['sma200'] !== null) $lines[] = "  SMA200: " . Helpers::formatPrice($r['sma200']);
        $lines[] = "  Support: " . Helpers::formatPrice($r['support']);
        $lines[] = "  Resistance: " . Helpers::formatPrice($r['resistance']);
        $lines[] = "";
        $lines[] = "Signals:";
        $lines[] = rtrim($sigText);
        $lines[] = "Overall: " . $r['overall'];

        sendOrEdit($tg, $chat, implode("\n", $lines), $mid);
    } catch (Exception $e) { sendOrEdit($tg, $chat, "Error: " . $e->getMessage(), $mid); }
}

// ---------------------------------------------------------------------------
// Wallet intel
// ---------------------------------------------------------------------------

function buildWalletIntel(string $address): string {
    $currentPrice = Helpers::getCurrentPrice();
    $info = BlockchainApi::getAddressFullInfo($address);
    $utxosRaw = BlockchainApi::getUnspentOutputs($address);

    $balance = ($info['final_balance'] ?? 0) / 1e8;
    $totalReceived = ($info['total_received'] ?? 0) / 1e8;
    $totalSent = ($info['total_sent'] ?? 0) / 1e8;
    $txCount = $info['n_tx'] ?? 0;
    $hash160 = $info['hash160'] ?? '';
    $txs = $info['txs'] ?? [];

    $utxoCount = 0; $utxoTotal = 0;
    if (isset($utxosRaw['unspent_outputs'])) {
        $utxoCount = count($utxosRaw['unspent_outputs']);
        foreach ($utxosRaw['unspent_outputs'] as $u) $utxoTotal += $u['value'] ?? 0;
    }
    $utxoTotal /= 1e8;

    $lines = [
        "Bitcoin Wallet",
        "",
        "Address:",
        "<code>$address</code>",
        "",
        "Balance: <code>" . number_format($balance, 8) . " BTC</code>  (" . Helpers::formatPrice($balance * $currentPrice) . ")",
        "Received: <code>" . number_format($totalReceived, 8) . " BTC</code>  (" . Helpers::formatPrice($totalReceived * $currentPrice) . ")",
        "Sent: <code>" . number_format($totalSent, 8) . " BTC</code>  (" . Helpers::formatPrice($totalSent * $currentPrice) . ")",
        "",
        "Transactions: $txCount",
        "UTXOs: $utxoCount (sum: " . number_format($utxoTotal, 8) . " BTC)",
        "Hash160: <code>$hash160</code>",
    ];

    if (!empty($txs)) {
        $lines[] = ""; $lines[] = "Recent Transactions:";
        foreach (array_slice($txs, 0, 5) as $tx) {
            $txid = $tx['hash'] ?? '';
            $result = ($tx['result'] ?? 0) / 1e8;
            $time = $tx['time'] ?? 0;
            $txBalance = ($tx['balance'] ?? 0) / 1e8;
            $dir = $result >= 0 ? '+' : '';
            $lines[] = ""; $lines[] = "<code>" . Helpers::shortenAddress($txid, 10) . "</code>";
            $lines[] = "  $dir" . number_format($result, 8) . " BTC  (" . Helpers::formatTime($time) . ")";
            $lines[] = "  Balance: " . number_format($txBalance, 8) . " BTC";
        }
    }
    return implode("\n", $lines);
}

function cmdWatch(array $msg, TelegramApi $tg, Database $db): void {
    $chat = chatId($msg); $uid = userId($msg);
    $parts = explode(' ', $msg['text'] ?? '', 3);
    if (count($parts) < 2) { $tg->sendMessage($chat, "Usage: /watch [address] [label]"); return; }
    $address = $parts[1]; $label = $parts[2] ?? '';
    $db->logCommand($uid, '/watch');
    $mid = sendOrEdit($tg, $chat, 'Gathering wallet intel...');
    try {
        $intel = buildWalletIntel($address);
        $already = !$db->addWallet($uid, $address, $label);
        sendOrEdit($tg, $chat, ($already ? "Already tracking this wallet.\n\n" : "Wallet tracked.\n\n") . $intel, $mid);
    } catch (Exception $e) { sendOrEdit($tg, $chat, "Error: " . $e->getMessage(), $mid); }
}

function cmdUnwatch(array $msg, TelegramApi $tg, Database $db): void {
    $chat = chatId($msg); $uid = userId($msg);
    $parts = explode(' ', $msg['text'] ?? '', 2);
    if (count($parts) < 2) { $tg->sendMessage($chat, "Usage: /unwatch [address]"); return; }
    $db->logCommand($uid, '/unwatch');
    if ($db->removeWallet($uid, $parts[1])) $tg->sendMessage($chat, "Wallet removed:\n<code>{$parts[1]}</code>");
    else $tg->sendMessage($chat, 'Wallet not found in your list.');
}

function cmdWallets(array $msg, TelegramApi $tg, Database $db): void {
    $chat = chatId($msg); $uid = userId($msg); $db->logCommand($uid, '/wallets');
    $wallets = $db->getWallets($uid);
    if (empty($wallets)) { $tg->sendMessage($chat, "No wallets tracked. Use /watch [address] to add one."); return; }
    $mid = sendOrEdit($tg, $chat, 'Fetching wallet data...');
    try {
        $currentPrice = Helpers::getCurrentPrice();
        $balanceData = BlockchainApi::getBalance(array_column($wallets, 'address'));
        $parts = [];
        foreach ($wallets as $i => $w) {
            $balInfo = $balanceData[$w['address']] ?? [];
            $bal = ($balInfo['final_balance'] ?? 0) / 1e8;
            $totalRecv = ($balInfo['total_received'] ?? 0) / 1e8;
            $txCount = $balInfo['n_tx'] ?? 0;
            $label = $w['label'] ? "  ($w[label])" : '';
            if ($i > 0) $parts[] = "";
            $parts[] = "Wallet #" . ($i + 1) . $label;
            $parts[] = "<code>" . Helpers::shortenAddress($w['address']) . "</code>";
            $parts[] = "Balance: <code>" . number_format($bal, 8) . " BTC</code>  (" . Helpers::formatPrice($bal * $currentPrice) . ")";
            $parts[] = "Received: " . number_format($totalRecv, 4) . " BTC  |  Txs: $txCount";
        }
        sendOrEdit($tg, $chat, implode("\n", $parts), $mid);
    } catch (Exception $e) { sendOrEdit($tg, $chat, "Error: " . $e->getMessage(), $mid); }
}

function cmdInfo(array $msg, TelegramApi $tg, Database $db): void {
    $chat = chatId($msg); $uid = userId($msg);
    $parts = explode(' ', $msg['text'] ?? '', 2);
    if (count($parts) < 2) { $tg->sendMessage($chat, "Usage: /info [address]"); return; }
    $db->logCommand($uid, '/info');
    $mid = sendOrEdit($tg, $chat, 'Gathering wallet intel...');
    try { sendOrEdit($tg, $chat, buildWalletIntel($parts[1]), $mid); }
    catch (Exception $e) { sendOrEdit($tg, $chat, "Error: " . $e->getMessage(), $mid); }
}

// ---------------------------------------------------------------------------
// /alert /alerts
// ---------------------------------------------------------------------------

function cmdAlert(array $msg, TelegramApi $tg, Database $db): void {
    $chat = chatId($msg); $uid = userId($msg);
    $parts = explode(' ', $msg['text'] ?? ''); array_shift($parts);
    if (empty($parts)) { $tg->sendMessage($chat, "Usage: /alert [above|below] [price]\nExample: /alert 70000\nExample: /alert above 85000"); return; }
    $direction = 'below'; $priceArg = $parts[0];
    if (in_array(strtolower($priceArg), ['above','below'])) { $direction = strtolower($priceArg); if (!isset($parts[1])) { $tg->sendMessage($chat, 'Please provide a price target.'); return; } $priceArg = $parts[1]; }
    if (!is_numeric($priceArg)) { $tg->sendMessage($chat, 'Invalid price.'); return; }
    $target = (float)$priceArg; $db->addAlert($uid, $target, $direction); $db->logCommand($uid, '/alert');
    $tg->sendMessage($chat, "Alert set. You will be notified when BTC goes $direction $" . number_format($target, 2) . ".");
}

function cmdAlerts(array $msg, TelegramApi $tg, Database $db): void {
    $chat = chatId($msg); $uid = userId($msg); $db->logCommand($uid, '/alerts');
    $alerts = $db->getAlerts($uid);
    if (empty($alerts)) { $tg->sendMessage($chat, "No active alerts. Use /alert [price] to set one."); return; }
    $lines = ["Your Price Alerts:"]; $buttons = ['inline_keyboard' => []];
    foreach ($alerts as $a) {
        $dir = $a['direction']; $created = substr($a['created_at'] ?? '', 0, 16);
        $lines[] = "#{$a['id']}  $dir  $" . number_format($a['target_price'], 0) . "  ($created)";
        $buttons['inline_keyboard'][] = [['text' => "Delete #{$a['id']}", 'callback_data' => "delete_alert:{$a['id']}"]];
    }
    $lines[] = ""; $lines[] = "Tap a button below to delete.";
    sendOrEdit($tg, $chat, implode("\n", $lines), null, $buttons);
}

// ---------------------------------------------------------------------------
// /mempool
// ---------------------------------------------------------------------------

function cmdMempool(array $msg, TelegramApi $tg, Database $db): void {
    $chat = chatId($msg); $uid = userId($msg); $db->logCommand($uid, '/mempool');
    $mid = sendOrEdit($tg, $chat, 'Fetching mempool data...');
    try {
        $stats = BlockchainApi::getMempoolStats();
        $fees = BlockchainApi::getFeesRecommended();
        $recent = BlockchainApi::getMempoolRecent();
        $lines = [
            "Bitcoin Mempool", "",
            "Pending: " . number_format($stats['count'] ?? 0) . " transactions",
            "Size: " . number_format(($stats['vsize'] ?? 0) / 1e6, 1) . " MB",
            "Usage: " . number_format(($stats['usage'] ?? 0) / 1e6, 1) . " MB", "",
            "Fee Estimates:",
            "  High: " . ($fees['fastestFee'] ?? 0) . " sat/vB",
            "  Medium: " . ($fees['halfHourFee'] ?? 0) . " sat/vB",
            "  Low: " . ($fees['hourFee'] ?? 0) . " sat/vB", "",
            "Recent Transactions:",
        ];
        foreach (array_slice($recent, 0, 5) as $tx) {
            $fr = $tx['vsize'] > 0 ? $tx['fee'] / $tx['vsize'] : 0;
            $vb = ($tx['value'] ?? 0) / 1e8;
            $lines[] = "<code>" . Helpers::shortenAddress($tx['txid'], 8) . "</code>  " . number_format($vb, 6) . " BTC  " . number_format($fr, 1) . " sat/vB";
        }
        sendOrEdit($tg, $chat, implode("\n", $lines), $mid);
    } catch (Exception $e) { sendOrEdit($tg, $chat, "Error: " . $e->getMessage(), $mid); }
}

// ---------------------------------------------------------------------------
// Admin commands
// ---------------------------------------------------------------------------

function cmdAdmin(array $msg, TelegramApi $tg, Database $db): void {
    $chat = chatId($msg); $uid = userId($msg);
    if (!isAdmin($uid)) { $tg->sendMessage($chat, 'Unauthorized.'); return; }
    $db->logCommand($uid, '/admin');
    $s = $db->getStats();
    $tg->sendMessage($chat,
        "Admin Panel\n\nUsers: $s[total]\nActive (24h): $s[active_24h]\nActive (7d): $s[active_7d]\nBanned: $s[banned]\n\nWallets: $s[total_wallets]\nAlerts: $s[active_alerts]",
        'HTML', adminKeyboard()
    );
}

function cmdStats(array $msg, TelegramApi $tg, Database $db): void {
    $chat = chatId($msg); $uid = userId($msg);
    if (!isAdmin($uid)) { $tg->sendMessage($chat, 'Unauthorized.'); return; }
    $db->logCommand($uid, '/stats');
    $s = $db->getStats(); $cs = $db->getCommandStats();
    $lines = ["Bot Statistics","","Users:","  Total: $s[total]","  Active 24h: $s[active_24h]","  Active 7d: $s[active_7d]","  Banned: $s[banned]","","Data:","  Wallets: $s[total_wallets]","  Alerts: $s[active_alerts]","","Top Commands (7d):"];
    foreach ($cs as $c) $lines[] = "  $c[command] - $c[count]";
    sendOrEdit($tg, $chat, implode("\n", $lines));
}

function cmdUsers(array $msg, TelegramApi $tg, Database $db): void {
    $chat = chatId($msg); $uid = userId($msg);
    if (!isAdmin($uid)) { $tg->sendMessage($chat, 'Unauthorized.'); return; }
    $db->logCommand($uid, '/users');
    $users = $db->getAllUsers();
    if (empty($users)) { $tg->sendMessage($chat, 'No users.'); return; }
    $lines = ["Users (" . count($users) . " total):",""];
    foreach ($users as $u) {
        $name = $u['first_name'] ?: '?';
        $uname = $u['username'] ? "@{$u['username']}" : '';
        $ls = substr($u['last_seen'] ?? '?', 0, 10);
        $badge = $u['banned'] ? ' [BANNED]' : ($u['is_admin'] ? ' [ADMIN]' : '');
        $lines[] = "<code>{$u['user_id']}</code>  $uname  $name  $ls$badge";
    }
    foreach (array_chunk($lines, 40) as $chunk) $tg->sendMessage($chat, implode("\n", $chunk));
}

function cmdBan(array $msg, TelegramApi $tg, Database $db): void {
    $chat = chatId($msg); $uid = userId($msg);
    if (!isAdmin($uid)) { $tg->sendMessage($chat, 'Unauthorized.'); return; }
    $parts = explode(' ', $msg['text'] ?? '', 2);
    if (count($parts) < 2 || !is_numeric($parts[1])) { $tg->sendMessage($chat, "Usage: /ban [user_id]"); return; }
    $target = (int)$parts[1];
    if (isAdmin($target)) { $tg->sendMessage($chat, 'Cannot ban the admin.'); return; }
    $db->logCommand($uid, '/ban');
    if ($db->banUser($target)) $tg->sendMessage($chat, "User banned: <code>$target</code>");
    else $tg->sendMessage($chat, "User not found: <code>$target</code>");
}

function cmdUnban(array $msg, TelegramApi $tg, Database $db): void {
    $chat = chatId($msg); $uid = userId($msg);
    if (!isAdmin($uid)) { $tg->sendMessage($chat, 'Unauthorized.'); return; }
    $parts = explode(' ', $msg['text'] ?? '', 2);
    if (count($parts) < 2 || !is_numeric($parts[1])) { $tg->sendMessage($chat, "Usage: /unban [user_id]"); return; }
    $target = (int)$parts[1];
    $db->logCommand($uid, '/unban');
    if ($db->unbanUser($target)) $tg->sendMessage($chat, "User unbanned: <code>$target</code>");
    else $tg->sendMessage($chat, "User not found: <code>$target</code>");
}

function cmdBroadcast(array $msg, TelegramApi $tg, Database $db): void {
    $chat = chatId($msg); $uid = userId($msg);
    if (!isAdmin($uid)) { $tg->sendMessage($chat, 'Unauthorized.'); return; }
    $parts = explode(' ', $msg['text'] ?? '', 2);
    if (count($parts) < 2 || trim($parts[1]) === '') { $tg->sendMessage($chat, "Usage: /broadcast [message]"); return; }
    $text = trim($parts[1]); $db->logCommand($uid, '/broadcast');
    $ids = $db->getAllUserIds(); $sent = 0; $failed = 0;
    foreach ($ids as $tid) {
        try { $tg->sendMessage($tid, "Broadcast:\n\n$text"); $sent++; }
        catch (Exception $e) { $failed++; }
        usleep(50000);
    }
    $tg->sendMessage($chat, "Broadcast done.\nSent: $sent\nFailed: $failed\nTotal: " . count($ids));
}

// ---------------------------------------------------------------------------
// Callback handler
// ---------------------------------------------------------------------------

function handleCallback(array $cb, TelegramApi $tg, Database $db): void {
    $data = $cb['data'] ?? ''; $uid = $cb['from']['id']; $cbId = $cb['id'];
    $chatId = $cb['message']['chat']['id'] ?? 0; $msgId = $cb['message']['message_id'] ?? 0;

    if (str_starts_with($data, 'delete_alert:')) {
        $aid = (int)explode(':', $data)[1];
        if ($db->deleteAlert($aid, $uid)) { $tg->answerCallbackQuery($cbId, 'Deleted.'); $tg->editMessageText($chatId, $msgId, 'Alert deleted.'); }
        else $tg->answerCallbackQuery($cbId, 'Could not delete.');
        return;
    }
    if (str_starts_with($data, 'admin:')) {
        if (!isAdmin($uid)) { $tg->answerCallbackQuery($cbId, 'Unauthorized.'); return; }
        $action = explode(':', $data)[1]; $tg->answerCallbackQuery($cbId);
        if ($action === 'users') cmdUsers($cb['message'], $tg, $db);
        elseif ($action === 'stats') cmdStats($cb['message'], $tg, $db);
        elseif ($action === 'broadcast') $tg->sendMessage($chatId, "Use /broadcast [message] to send to all users.");
    }
}

// ---------------------------------------------------------------------------
// Price alert checker
// ---------------------------------------------------------------------------

function checkPriceAlerts(TelegramApi $tg, Database $db): void {
    try {
        $data = CoinGeckoApi::getPrices(['bitcoin']);
        $currentPrice = $data['bitcoin']['usd'] ?? 0;
        if ($currentPrice === 0) { echo "[" . date('Y-m-d H:i:s') . "] Alert check: invalid price\n"; return; }
    } catch (Exception $e) { echo "[" . date('Y-m-d H:i:s') . "] Alert check failed: {$e->getMessage()}\n"; return; }
    foreach ($db->getAllActiveAlerts() as $a) {
        $triggered = ($a['direction'] === 'below' && $currentPrice <= $a['target_price']) || ($a['direction'] === 'above' && $currentPrice >= $a['target_price']);
        if ($triggered) {
            try {
                $tg->sendMessage($a['user_id'], "Price Alert Triggered!\n\nBTC is now $" . number_format($currentPrice, 2) . "\nTarget: $" . number_format($a['target_price'], 2) . " ({$a['direction']})");
                $db->markAlertTriggered($a['id']);
            } catch (Exception $e) { echo "[" . date('Y-m-d H:i:s') . "] Notify failed for {$a['user_id']}: {$e->getMessage()}\n"; }
        }
    }
}

// ---------------------------------------------------------------------------
// Router
// ---------------------------------------------------------------------------

function processUpdate(array $update, TelegramApi $tg, Database $db): void {
    if (isset($update['callback_query'])) { handleCallback($update['callback_query'], $tg, $db); return; }
    $msg = $update['message'] ?? []; if (empty($msg) || empty($msg['text'])) return;
    $text = $msg['text']; $chat = $msg['chat']['id'];
    $uid = registerAndCheck($msg, $db);
    if ($uid === null) { if (($msg['chat']['type'] ?? '') === 'private') $tg->sendMessage($chat, 'You are banned.'); return; }
    echo "[" . date('Y-m-d H:i:s') . "] Chat $chat: $text\n";
    $cmd = extractCommand($text);

    $adminCmds = ['/admin','/stats','/users','/ban','/unban','/broadcast'];
    if (in_array($cmd, $adminCmds)) {
        if (!isAdmin($uid)) { $tg->sendMessage($chat, 'Unauthorized.'); return; }
    }

    $handlers = [
        '/start' => 'cmdStart', '/help' => 'cmdHelp',
        '/price' => 'cmdPrice', '/chart' => 'cmdChart', '/predict' => 'cmdPredict',
        '/watch' => 'cmdWatch', '/unwatch' => 'cmdUnwatch', '/wallets' => 'cmdWallets', '/info' => 'cmdInfo',
        '/alert' => 'cmdAlert', '/alerts' => 'cmdAlerts',
        '/mempool' => 'cmdMempool',
        '/admin' => 'cmdAdmin', '/stats' => 'cmdStats', '/users' => 'cmdUsers',
        '/ban' => 'cmdBan', '/unban' => 'cmdUnban', '/broadcast' => 'cmdBroadcast',
    ];
    if (isset($handlers[$cmd])) $handlers[$cmd]($msg, $tg, $db);
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

$db = new Database(); $tg = new TelegramApi(BOT_TOKEN);
$lastUpdateId = 0; $lastAlertCheck = 0;
echo "Bot started. Polling for updates...\n";

while (true) {
    try {
        $now = time();
        if ($now - $lastAlertCheck >= 300) { checkPriceAlerts($tg, $db); $lastAlertCheck = $now; }
        foreach ($tg->getUpdates($lastUpdateId + 1, 30) as $update) {
            $lastUpdateId = $update['update_id'];
            processUpdate($update, $tg, $db);
        }
    } catch (Exception $e) {
        echo "[" . date('Y-m-d H:i:s') . "] Error: " . $e->getMessage() . "\n";
        sleep(2);
    }
}
