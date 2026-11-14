<?php
// ============================================================
// API Lazada & TikTok Shop (khusus admin): status, hubungkan toko, tarik pesanan.
// Pemakaian: platform.php?p=lazada|tiktok&action=status|connect|disconnect|sync
// ============================================================
require __DIR__ . '/db.php';
require __DIR__ . '/lib/orders.php';
require __DIR__ . '/lib/platforms.php';
require __DIR__ . '/lib/lazada.php';
require __DIR__ . '/lib/tiktok.php';

require_admin();
ensure_schema($pdo);

$p = $_GET['p'] ?? '';
$action = $_GET['action'] ?? '';
if (!in_array($p, ['lazada', 'tiktok'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Platform tidak dikenal']);
    exit;
}
function out($data, int $code = 200) { http_response_code($code); echo json_encode($data); exit; }

$configured = $p === 'lazada' ? lz_configured() : tt_configured();
$t = ptoken_load($p);

if ($action === 'status') {
    $st = $pdo->prepare("SELECT COUNT(*) c FROM orders WHERE platform = ?");
    $st->execute([$p]);
    $cnt = (int)$st->fetch()['c'];
    $st = $pdo->prepare("SELECT COUNT(*) c FROM orders WHERE platform = ? AND resi <> ''");
    $st->execute([$p]);
    $conf = $p === 'lazada' ? lz_conf() : tt_conf();
    out([
        'configured' => $configured,
        'connected' => (bool)$t,
        'shop_id' => $p === 'lazada' ? ($t['account'] ?? null) : (($t['shop_name'] ?? '') ?: ($t['shop_id'] ?? null)),
        'env' => $p === 'lazada' ? 'lazada ' . strtoupper((string)($conf['country'] ?? 'id')) : 'tiktok shop',
        'redirect_url' => $conf['redirect_url'] ?? '',
        'auth_url' => $configured ? ($p === 'lazada' ? lz_auth_url() : tt_auth_url()) : '',
        'orders' => $cnt,
        'orders_with_resi' => (int)$st->fetch()['c'],
        'zip' => class_exists('ZipArchive'),
        'curl' => function_exists('curl_init'),
        'autosync_last' => autosync_last_read(),
    ]);
}

if (!$configured) {
    out(['error' => 'Belum dikonfigurasi: isi blok \'' . $p . '\' (app_key & app_secret) di file config.php, lalu muat ulang.'], 409);
}

if ($action === 'connect' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $b = json_input();
    try {
        $r = $p === 'lazada' ? lz_connect((string)($b['kode'] ?? '')) : tt_connect((string)($b['kode'] ?? ''));
    } catch (Throwable $e) {
        out(['error' => $e->getMessage()], 502);
    }
    out(['connected' => true]);
}

if ($action === 'disconnect' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    @unlink(ptoken_file($p));
    out(['connected' => false]);
}

if ($action === 'sync' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $b = json_input();
    $days = max(1, min(90, (int)($b['days'] ?? 7)));
    try {
        $res = $p === 'lazada' ? lz_sync($pdo, $days) : tt_sync($pdo, $days);
    } catch (Throwable $e) {
        if ($e->getMessage() === 'NOT_CONNECTED') out(['error' => 'Toko belum terhubung.'], 409);
        out(['error' => $e->getMessage()], 502);
    }
    out($res);
}

out(['error' => 'Aksi tidak dikenal'], 400);
