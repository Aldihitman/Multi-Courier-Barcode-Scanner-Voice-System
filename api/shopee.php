<?php
// ============================================================
// API Shopee (khusus admin): status, hubungkan toko, tarik pesanan, unduh Excel pesanan.
// ============================================================
require __DIR__ . '/db.php';
require __DIR__ . '/lib/shopee.php';
require __DIR__ . '/lib/orders.php';
require __DIR__ . '/lib/platforms.php';
require __DIR__ . '/lib/sync.php';
require __DIR__ . '/lib/lazada.php';
require __DIR__ . '/lib/tiktok.php';

$action0 = $_GET['action'] ?? '';
if ($action0 === 'autosync') {
    // boleh dipanggil user biasa (staf scan), jadi tarik otomatis jalan tanpa admin
    require_login();
    ensure_schema($pdo);
    session_write_close();
    $interval = !empty($_GET["force"]) ? 20 : 50;
    $res = ['shopee' => shopee_autosync($pdo, $interval)];
    if (lz_configured() && ptoken_load('lazada')) $res['lazada'] = platform_autosync($pdo, 'lazada', $interval, fn($p) => lz_sync($p, 2));
    if (tt_configured() && ptoken_load('tiktok')) $res['tiktok'] = platform_autosync($pdo, 'tiktok', $interval, fn($p) => tt_sync($p, 2, 20));
    $res['backfilled'] = array_sum(array_map(fn($r) => (int)($r['backfilled'] ?? 0), $res));
    autosync_last_write($res);
    echo json_encode($res);
    exit;
}
require_admin();
ensure_schema($pdo);

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

function out($data, int $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}
function configured(): bool {
    $c = cfg();
    return !empty($c['partner_id']) && !empty($c['partner_key']);
}

if ($action === 'status') {
    $t = load_tokens();
    $cnt = (int)$pdo->query("SELECT COUNT(*) c FROM orders WHERE platform = 'shopee'")->fetch()['c'];
    $withResi = (int)$pdo->query("SELECT COUNT(*) c FROM orders WHERE platform = 'shopee' AND resi <> ''")->fetch()['c'];
    out([
        'configured' => configured(),
        'connected' => (bool)$t,
        'shop_id' => $t['shop_id'] ?? null,
        'env' => cfg()['env'] ?? 'sandbox',
        'redirect_url' => cfg()['redirect_url'] ?? '',
        'auth_url' => configured() ? auth_url() : '',
        'orders' => $cnt,
        'orders_with_resi' => $withResi,
        'zip' => class_exists('ZipArchive'),
        'curl' => function_exists('curl_init'),
        'autosync_last' => autosync_last_read(),
    ]);
}

if (!configured() && $action !== 'export') {
    out(['error' => 'Shopee belum dikonfigurasi: isi partner_id dan partner_key di file config.php, lalu muat ulang.'], 409);
}

if ($action === 'connect' && $method === 'POST') {
    $b = json_input();
    $in = trim((string)($b['kode'] ?? ''));
    $shop = trim((string)($b['shop_id'] ?? ''));
    $code = $in;
    if (preg_match('~^https?://~i', $in)) {
        parse_str((string)parse_url($in, PHP_URL_QUERY), $q);
        $code = (string)($q['code'] ?? '');
        if ($shop === '') $shop = (string)($q['shop_id'] ?? '');
    }
    if ($code === '' || !ctype_digit($shop)) {
        out(['error' => 'Kode atau shop_id tidak ditemukan. Salin SELURUH alamat (URL) dari address bar setelah login Shopee, atau isi kode dan shop_id terpisah.'], 400);
    }
    try {
        $t = exchange_code($code, $shop);
    } catch (Throwable $e) {
        out(['error' => $e->getMessage()], 502);
    }
    out(['connected' => true, 'shop_id' => $t['shop_id']]);
}

if ($action === 'disconnect' && $method === 'POST') {
    @unlink(token_file());
    out(['connected' => false]);
}

if ($action === 'sync' && $method === 'POST') {
    $b = json_input();
    $days = max(1, min(90, (int)($b['days'] ?? 7)));
    $status = (string)($b['status'] ?? '');
    $okStatus = ['', 'UNPAID', 'READY_TO_SHIP', 'PROCESSED', 'SHIPPED', 'COMPLETED', 'IN_CANCEL', 'CANCELLED'];
    if (!in_array($status, $okStatus, true)) out(['error' => 'Status tidak dikenal'], 400);
    $maxTracking = 120; // batasi per klik supaya tidak timeout; sisanya diambil di klik berikutnya
    try {
        $res = shopee_sync($pdo, $days, $status, $maxTracking);
    } catch (Throwable $e) {
        if ($e->getMessage() === 'NOT_CONNECTED') out(['error' => 'Toko belum terhubung.'], 409);
        out(['error' => $e->getMessage()], 502);
    }
    out($res);
}

if ($action === 'export') {
    $from = $_GET['from'] ?? ''; $to = $_GET['to'] ?? '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) out(['error' => 'Tanggal harus format YYYY-MM-DD'], 400);
    $tFrom = (int)strtotime($from . ' 00:00:00'); $tTo = (int)strtotime($to . ' 23:59:59');
    $st = $pdo->prepare("SELECT * FROM orders WHERE create_time BETWEEN ? AND ? ORDER BY create_time ASC, id ASC");
    $st->execute([$tFrom, $tTo]);
    $rows = [];
    foreach ($st->fetchAll() as $i => $o) {
        $rows[] = [
            'No' => $i + 1, 'Nomor Pesanan' => $o['order_sn'], 'Nomor Resi' => $o['resi'], 'Kurir' => $o['kurir'],
            'Status' => $o['status'], 'Nama Toko' => $o['nama_toko'], 'Nama Penerima' => $o['nama_penerima'],
            'No HP Penerima' => $o['no_hp'], 'Alamat' => (string)$o['alamat'], 'Nama Barang' => (string)$o['nama_barang'],
            'Qty' => (int)$o['qty'], 'Batas Kirim' => $o['batas_kirim'], 'Tanggal Pesan' => $o['tanggal_pesan'],
            'Subtotal Pesanan' => (int)($o['subtotal'] ?? 0), 'Total' => $o['total'], 'Username Pembeli' => $o['username_pembeli'], 'Catatan' => (string)$o['catatan'],
            'Platform' => (string)($o['platform'] ?? 'shopee'),
            'SKU' => (string)($o['sku'] ?? ''),
        ];
    }
    try { $bin = xlsx_binary($rows); }
    catch (Throwable $e) { out(['error' => $e->getMessage()], 500); }
    session_write_close();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="pesanan-shopee-' . $from . '_' . $to . '.xlsx"');
    header('Content-Length: ' . strlen($bin));
    header('Cache-Control: no-store');
    echo $bin;
    exit;
}

if ($action === 'auth_url') {
    out(['auth_url' => auth_url()]);
}

out(['error' => 'Aksi tidak dikenal'], 400);
