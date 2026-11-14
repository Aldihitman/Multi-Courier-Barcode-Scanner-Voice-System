<?php
// Konektor TikTok Shop (termasuk Tokopedia yang sudah digabung ke TikTok Shop). API versi 202309.
// Tanda tangan: HMAC-SHA256, kunci app_secret, isi = secret + path + (query terurut tanpa sign & access_token) + body + secret.

function tt_conf(): array { $c = app_cfg('tiktok', []); return is_array($c) ? $c : []; }
function tt_configured(): bool { $c = tt_conf(); return !empty($c['app_key']) && !empty($c['app_secret']); }
function tt_api_host(): string { $c = tt_conf(); return !empty($c['host']) ? rtrim($c['host'], '/') : 'https://open-api.tiktokglobalshop.com'; }
function tt_auth_host(): string { $c = tt_conf(); return !empty($c['auth_host']) ? rtrim($c['auth_host'], '/') : 'https://auth.tiktok-shops.com'; }

function tt_auth_url(): string
{
    $c = tt_conf();
    if (!empty($c['auth_url'])) return (string)$c['auth_url']; // salin "Authorization link" dari Partner Center
    return 'https://services.tiktokshop.com/open/authorize?' . http_build_query(['service_id' => $c['service_id'] ?? '']);
}

/**
 * Tanda tangan TikTok. $variant dipakai kalau varian standar ditolak (106001):
 *  0 = standar dokumentasi (nilai query mentah, body ikut ditandatangani)
 *  1 = nilai query di-urlencode dulu sebelum ditandatangani
 *  2 = body tidak ikut ditandatangani
 */
function tt_sign(string $path, array $query, string $body, int $variant = 0): string
{
    $secret = trim((string)tt_conf()['app_secret']);
    unset($query['sign'], $query['access_token']);
    ksort($query, SORT_STRING);
    $s = $path;
    foreach ($query as $k => $v) $s .= $k . ($variant === 1 ? rawurlencode((string)$v) : $v);
    if ($variant !== 2) $s .= $body;
    return hash_hmac('sha256', $secret . $s . $secret, $secret);
}

function tt_check(array $j, string $what): array
{
    if (isset($j['_error'])) throw new RuntimeException("TikTok $what: " . $j['_error']);
    if (isset($j['code']) && (int)$j['code'] !== 0) {
        throw new RuntimeException("TikTok $what: " . $j['code'] . ' - ' . ($j['message'] ?? ''));
    }
    return $j;
}

function tt_call(string $method, string $path, array $query, ?array $body, string $token): array
{
    $query['app_key'] = trim((string)tt_conf()['app_key']);
    $json = $body === null ? '' : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stamp = __DIR__ . '/../../storage/tiktok_sign_variant.txt';
    $first = is_file($stamp) ? (int)trim((string)@file_get_contents($stamp)) : 0;
    $order = array_values(array_unique(array_merge([$first], [0, 1, 2])));
    $j = [];
    foreach ($order as $v) {
        $query['timestamp'] = (string)time();
        $query['sign'] = tt_sign($path, $query, $json, $v);
        $j = http_json($method, tt_api_host() . $path . '?' . http_build_query($query),
            ['x-tts-access-token: ' . $token], $body === null ? null : $json);
        if ((int)($j['code'] ?? 0) === 106001) continue; // sign ditolak: coba varian berikutnya
        if ($v !== $first && (int)($j['code'] ?? 0) === 0) @file_put_contents($stamp, (string)$v);
        break;
    }
    if ((int)($j['code'] ?? 0) === 106001) {
        // diagnosa: apakah tanda tangan GET sederhana diterima? (membedakan masalah secret vs body POST)
        $diag = '';
        try {
            $q2 = ['app_key' => $query['app_key'], 'timestamp' => (string)time()];
            $q2['sign'] = tt_sign('/authorization/202309/shops', $q2, '', 0);
            $r = http_json('GET', tt_api_host() . '/authorization/202309/shops?' . http_build_query($q2),
                ['x-tts-access-token: ' . $token]);
            $diag = ' [diagnosa: GET shops ' . ((int)($r['code'] ?? -1) === 0 ? 'OK (secret benar, masalah di POST)' : 'GAGAL ' . ($r['code'] ?? '?') . ' (cek App Secret/App Key)') . ']';
        } catch (Throwable $e) { $diag = ' [diagnosa gagal: ' . $e->getMessage() . ']'; }
        $keys = $query; unset($keys['sign']); ksort($keys, SORT_STRING);
        $diag .= ' [parameter: ' . implode(',', array_keys($keys)) . '; body ' . strlen($json) . ' byte; host ' . tt_api_host() . ']';
        throw new RuntimeException("TikTok $path: 106001 - sign ditolak (semua varian)" . $diag);
    }
    return tt_check($j, $path);
}

/** nilai expire dari TikTok berupa timestamp unix (absolut); kalau kecil dianggap detik relatif */
function tt_abs($v, int $fallback): int
{
    $v = (int)$v;
    if ($v <= 0) return time() + $fallback;
    return $v > 1000000000 ? $v : time() + $v;
}

function tt_store_tokens(array $d, ?array $old = null): array
{
    $t = [
        'access_token' => $d['access_token'],
        'refresh_token' => $d['refresh_token'] ?? ($old['refresh_token'] ?? ''),
        'expires_at' => tt_abs($d['access_token_expire_in'] ?? 0, 7 * 86400),
        'refresh_expires_at' => tt_abs($d['refresh_token_expire_in'] ?? 0, 60 * 86400),
        'seller_name' => $d['seller_name'] ?? ($old['seller_name'] ?? ''),
        'shop_cipher' => $old['shop_cipher'] ?? '',
        'shop_id' => $old['shop_id'] ?? '',
        'shop_name' => $old['shop_name'] ?? '',
    ];
    ptoken_save('tiktok', $t);
    return $t;
}

function tt_connect(string $input): array
{
    $code = trim($input);
    if (preg_match('~^https?://~i', $code)) {
        parse_str((string)parse_url($code, PHP_URL_QUERY), $q);
        $code = (string)($q['code'] ?? ($q['auth_code'] ?? ''));
    }
    if ($code === '') throw new RuntimeException('Kode otorisasi tidak ditemukan. Salin SELURUH alamat hasil redirect (ada "code=...").');
    $c = tt_conf();
    $j = tt_check(http_json('GET', tt_auth_host() . '/api/v2/token/get?' . http_build_query([
        'app_key' => $c['app_key'], 'app_secret' => $c['app_secret'], 'auth_code' => $code, 'grant_type' => 'authorized_code',
    ])), 'token/get');
    $d = $j['data'] ?? [];
    if (empty($d['access_token'])) throw new RuntimeException('TikTok tidak mengembalikan access_token');
    $t = tt_store_tokens($d);
    // ambil shop_cipher (wajib di semua panggilan pesanan)
    $s = tt_call('GET', '/authorization/202309/shops', [], null, $t['access_token']);
    $shops = $s['data']['shops'] ?? [];
    if (!$shops) throw new RuntimeException('Tidak ada toko yang diotorisasi untuk app ini.');
    $pick = $shops[0];
    if (!empty($c['shop_id'])) foreach ($shops as $sh) if ((string)($sh['id'] ?? '') === (string)$c['shop_id']) $pick = $sh;
    $t['shop_cipher'] = (string)($pick['cipher'] ?? '');
    $t['shop_id'] = (string)($pick['id'] ?? '');
    $t['shop_name'] = (string)($pick['name'] ?? '');
    if ($t['shop_cipher'] === '') {
        throw new RuntimeException('TikTok tidak mengembalikan shop_cipher (kode toko). Pastikan scope "Shop Authorized Information" (seller.authorization.info) aktif di Partner Center, klik Publish changes, lalu Putuskan dan Hubungkan ulang.');
    }
    ptoken_save('tiktok', $t);
    return $t;
}

/** pastikan shop_cipher terisi; kalau kosong coba ambil ulang dari daftar toko */
function tt_ensure_cipher(array $t): array
{
    if (($t['shop_cipher'] ?? '') !== '') return $t;
    $s = tt_call('GET', '/authorization/202309/shops', [], null, $t['access_token']);
    $pick = ($s['data']['shops'] ?? [])[0] ?? [];
    $t['shop_cipher'] = (string)($pick['cipher'] ?? '');
    $t['shop_id'] = (string)($pick['id'] ?? ($t['shop_id'] ?? ''));
    $t['shop_name'] = (string)($pick['name'] ?? ($t['shop_name'] ?? ''));
    if ($t['shop_cipher'] === '') {
        throw new RuntimeException('Kode toko TikTok (shop_cipher) kosong, jadi pesanan belum bisa ditarik. Aktifkan scope Order, Fulfillment dan "Shop Authorized Information" di Partner Center, Publish changes, lalu Putuskan dan Hubungkan ulang TikTok.');
    }
    ptoken_save('tiktok', $t);
    return $t;
}

function tt_tokens(): array
{
    $t = ptoken_load('tiktok');
    if (!$t) throw new RuntimeException('NOT_CONNECTED');
    if ($t['expires_at'] - time() < 600) {
        $c = tt_conf();
        $j = tt_check(http_json('GET', tt_auth_host() . '/api/v2/token/refresh?' . http_build_query([
            'app_key' => $c['app_key'], 'app_secret' => $c['app_secret'],
            'refresh_token' => $t['refresh_token'], 'grant_type' => 'refresh_token',
        ])), 'token/refresh');
        if (empty($j['data']['access_token'])) throw new RuntimeException('Refresh token TikTok gagal, hubungkan ulang toko.');
        $t = tt_store_tokens($j['data'], $t);
    }
    return $t;
}

function tt_sync(PDO $pdo, int $days, int $maxPackageCalls = 60): array
{
    @set_time_limit((int)($GLOBALS['SYNC_TIME_LIMIT'] ?? 0));
    $t = tt_ensure_cipher(tt_tokens());
    $now = time();
    $orders = [];
    $pageToken = '';
    do {
        $q = ['page_size' => 50, 'sort_field' => 'create_time', 'sort_order' => 'DESC', 'shop_cipher' => $t['shop_cipher']];
        if ($pageToken !== '') $q['page_token'] = $pageToken;
        $j = tt_call('POST', '/order/202309/orders/search', $q,
            ['create_time_ge' => $now - $days * 86400, 'create_time_lt' => $now], $t['access_token']);
        foreach (($j['data']['orders'] ?? []) as $o) $orders[(string)$o['id']] = $o;
        $pageToken = (string)($j['data']['next_page_token'] ?? '');
    } while ($pageToken !== '');

    $new = 0; $updated = 0; $pkgCalls = 0; $pending = 0; $failed = 0;
    $pdo->beginTransaction();
    try {
        foreach ($orders as $id => $o) {
            $lines = $o['line_items'] ?? [];
            $resi = (string)($o['tracking_number'] ?? '');
            $kurir = (string)($o['shipping_provider'] ?? '');
            foreach ($lines as $li) {
                if ($resi === '' && !empty($li['tracking_number'])) $resi = (string)$li['tracking_number'];
                if ($kurir === '' && !empty($li['shipping_provider_name'])) $kurir = (string)$li['shipping_provider_name'];
            }
            // cadangan: resi dari detail paket (hanya untuk pesanan yang belum punya resi tersimpan)
            if ($resi === '' && !empty($o['packages'][0]['id']) && !in_array($o['status'] ?? '', ['UNPAID', 'CANCELLED'], true)) {
                $ex = $pdo->prepare("SELECT resi FROM orders WHERE order_sn = ?");
                $ex->execute([$id]);
                $have = $ex->fetch();
                if (!$have || $have['resi'] === '') {
                    if ($pkgCalls < $maxPackageCalls) {
                        $pkgCalls++;
                        try {
                            $p = tt_call('GET', '/fulfillment/202309/packages/' . $o['packages'][0]['id'], ['shop_cipher' => $t['shop_cipher']], null, $t['access_token']);
                            $resi = (string)($p['data']['tracking_number'] ?? '');
                            if ($kurir === '') $kurir = (string)($p['data']['shipping_provider_name'] ?? '');
                        } catch (Throwable $e) { $failed++; }
                    } else { $pending++; }
                }
            }
            $names = []; $skuC = [];
            $qty = 0; $subtotal = 0; $itemLines = [];
            foreach ($lines as $li) {
                $n = trim((string)($li['product_name'] ?? '') . (empty($li['sku_name']) ? '' : ' - ' . $li['sku_name']));
                $names[$n] = ($names[$n] ?? 0) + 1; // tiap line item = 1 unit
                $qty++;
                $harga = (float)(($li['original_price'] ?? 0) ?: ($li['sale_price'] ?? 0));
                $subtotal += (int)round($harga); // harga posting; tiap line item = 1 unit
                $itemLines[] = item_line((string)($li['product_name'] ?? ''), (string)($li['sku_name'] ?? ''), (string)(($li['seller_sku'] ?? '') !== '' ? $li['seller_sku'] : ($li['sku_id'] ?? '')), 1, $harga);
                $k = (string)(($li['seller_sku'] ?? '') !== '' ? $li['seller_sku'] : ($li['sku_id'] ?? ''));
                if ($k !== '') $skuC[$k] = ($skuC[$k] ?? 0) + 1;
            }
            if (isset($o['payment']['original_total_product_price']) && (float)$o['payment']['original_total_product_price'] > 0) $subtotal = (int)round((float)$o['payment']['original_total_product_price']);
            $barang = implode('; ', array_map(fn($n, $c) => $c > 1 ? "$n x$c" : $n, array_keys($names), $names));
            $r = $o['recipient_address'] ?? [];
            $alamat = (string)($r['full_address'] ?? $r['address_detail'] ?? '');
            $isNew = orders_upsert($pdo, 'tiktok', [
                'order_sn' => (string)$id, 'resi' => $resi, 'kurir' => $kurir, 'status' => (string)($o['status'] ?? ''),
                'nama_toko' => (string)($t['shop_name'] ?: $t['seller_name']),
                'nama_penerima' => (string)($r['name'] ?? ''), 'no_hp' => (string)($r['phone_number'] ?? ''),
                'alamat' => $alamat, 'nama_barang' => $barang, 'qty' => $qty, 'subtotal' => $subtotal, 'items' => items_group($itemLines),
                'sku' => implode('; ', array_map(fn($k, $c) => $c > 1 ? "$k x$c" : $k, array_keys($skuC), $skuC)),
                'batas_kirim' => fmt_ts($o['rts_sla_time'] ?? $o['shipping_due_time'] ?? 0),
                'tanggal_pesan' => fmt_ts($o['create_time'] ?? 0),
                'total' => (string)($o['payment']['total_amount'] ?? ''),
                'username_pembeli' => (string)($o['buyer_email'] ?? ''), 'catatan' => (string)($o['buyer_message'] ?? ''),
                'create_time' => (int)($o['create_time'] ?? 0),
            ]);
            $isNew ? $new++ : $updated++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return ['fetched' => count($orders), 'new' => $new, 'updated' => $updated, 'resi_failed' => $failed,
        'resi_pending' => $pending, 'backfilled' => backfill_scanned_resi($pdo)];
}
