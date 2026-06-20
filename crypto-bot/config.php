<?php
$token = getenv('CRYPTO_BOT_TOKEN');
if (!$token) {
    fwrite(STDERR, "ERROR: CRYPTO_BOT_TOKEN environment variable is not set\n");
    exit(1);
}
define('BOT_TOKEN', $token);

$adminId = getenv('CRYPTO_BOT_ADMIN_ID');
if (!$adminId) {
    fwrite(STDERR, "ERROR: CRYPTO_BOT_ADMIN_ID environment variable is not set\n");
    exit(1);
}
define('ADMIN_ID', (int)$adminId);
define('BLOCKCHAIN_API', 'https://blockchain.info');
define('COINGECKO_API', 'https://api.coingecko.com/api/v3');
define('MEMPOOL_API', 'https://mempool.space/api');
define('DB_PATH', __DIR__ . '/crypto_bot.db');

const COINS = [
    'btc'  => ['id' => 'bitcoin',            'sym' => 'BTC',  'name' => 'Bitcoin'],
    'eth'  => ['id' => 'ethereum',           'sym' => 'ETH',  'name' => 'Ethereum'],
    'bnb'  => ['id' => 'binancecoin',        'sym' => 'BNB',  'name' => 'BNB'],
    'sol'  => ['id' => 'solana',             'sym' => 'SOL',  'name' => 'Solana'],
    'xrp'  => ['id' => 'ripple',             'sym' => 'XRP',  'name' => 'XRP'],
    'ada'  => ['id' => 'cardano',            'sym' => 'ADA',  'name' => 'Cardano'],
    'doge' => ['id' => 'dogecoin',           'sym' => 'DOGE', 'name' => 'Dogecoin'],
    'avax' => ['id' => 'avalanche-2',        'sym' => 'AVAX', 'name' => 'Avalanche'],
    'dot'  => ['id' => 'polkadot',           'sym' => 'DOT',  'name' => 'Polkadot'],
    'matic'=> ['id' => 'matic-network',      'sym' => 'MATIC','name' => 'Polygon'],
    'shib' => ['id' => 'shiba-inu',          'sym' => 'SHIB', 'name' => 'Shiba Inu'],
    'trx'  => ['id' => 'tron',               'sym' => 'TRX',  'name' => 'TRON'],
    'ltc'  => ['id' => 'litecoin',           'sym' => 'LTC',  'name' => 'Litecoin'],
    'bch'  => ['id' => 'bitcoin-cash',       'sym' => 'BCH',  'name' => 'Bitcoin Cash'],
    'link' => ['id' => 'chainlink',          'sym' => 'LINK', 'name' => 'Chainlink'],
    'uni'  => ['id' => 'uniswap',            'sym' => 'UNI',  'name' => 'Uniswap'],
    'atom' => ['id' => 'cosmos',             'sym' => 'ATOM', 'name' => 'Cosmos'],
    'xlm'  => ['id' => 'stellar',            'sym' => 'XLM',  'name' => 'Stellar'],
    'vet'  => ['id' => 'vechain',            'sym' => 'VET',  'name' => 'VeChain'],
    'near' => ['id' => 'near',               'sym' => 'NEAR', 'name' => 'Near Protocol'],
    'apt'  => ['id' => 'aptos',              'sym' => 'APT',  'name' => 'Aptos'],
    'arb'  => ['id' => 'arbitrum',           'sym' => 'ARB',  'name' => 'Arbitrum'],
    'op'   => ['id' => 'optimism',           'sym' => 'OP',   'name' => 'Optimism'],
    'sui'  => ['id' => 'sui',                'sym' => 'SUI',  'name' => 'Sui'],
    'pepe' => ['id' => 'pepe',               'sym' => 'PEPE', 'name' => 'Pepe'],
    'inj'  => ['id' => 'injective-protocol', 'sym' => 'INJ',  'name' => 'Injective'],
];

function resolveCoin(string $input): ?array {
    $key = strtolower($input);
    if (isset(COINS[$key])) return COINS[$key];
    foreach (COINS as $c) {
        if (strtolower($c['sym']) === $key || strtolower($c['name']) === $key) return $c;
    }
    return null;
}

function coinIds(): array {
    return array_column(COINS, 'id');
}

function defaultCoins(): array {
    $keys = ['btc','eth','bnb','sol','xrp','ada','doge','avax','dot','matic','ltc','link'];
    return array_map(fn($k) => COINS[$k], $keys);
}
