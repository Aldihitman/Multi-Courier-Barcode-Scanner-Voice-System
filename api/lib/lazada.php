<?php
// Konektor Lazada Open Platform (Indonesia). Tanda tangan: HMAC-SHA256(path + parameter terurut), hex huruf besar.

function lz_conf(): array { $c = app_cfg('lazada', []); return is_array($c) ? $c : []; }
function lz_configured(): bool { $c = lz_conf(); return !empty($c['app_key']) && !empty($c['app_secret']); }

function lz_api_host(): string
{
    $c = lz_conf();
    if (!empty($c['host'])) return rtrim($c['host'], '/');
    $map = ['id' => 'https://api.lazada.co.id/rest', 'my' => 'https://api.lazada.com.my/rest', 'sg' => 'https://api.lazada.sg/rest',
        'th' => 'https://api.lazada.co.th/rest', 'ph' => 'https://api.lazada.com.ph/rest', 'vn' => 'https://api.lazada.vn/rest'];
    return $map[strtolower((string)($c['country'] ?? 'id'))] ?? $map['id'];
}
function lz_auth_host(): string
{
    $c = lz_conf();
    return !empty($c['auth_host']) ? rtrim($c['auth_host'], '/') : 'https://auth.lazada.com/rest';
}

function lz_auth_url(): string
{
    $c = lz_conf();
    return 'https://auth.lazada.com/oauth/authorize?' . http_build_query([
        'response_type' => 'code', 'force_auth' => 'true',
        'redirect_uri' => $c['redirect_url'] ?? '', 'client_id' => $c['app_key'] ?? '',
    ]);
}

function lz_sign(string $path, array $params): string
{
    ksort($params, SORT_STRING);
    $s = $path;
    foreach ($params as $k => $v) $s .= $k . $v;
    return strtoupper(hash_hmac('sha256', $s, (string)lz_conf()['app_secret']));
}

/** $base = lz_api_host() atau lz_auth_host(). Parameter API ikut di query (GET) atau body form (POST). */
function lz_call(string $base, string $path, array $apiParams, string $method = 'GET', ?string $token = null): array
{
    $sys = ['app_key' => (string)lz_conf()['app_key'], 'timestamp' => (string)(int)round(microtime(true) * 1000), 'sign_method' => 'sha256'];
    if ($token) $sys['access_token'] = $token;
    $sys['sign'] = lz_sign($path, $sys + $apiParams);
    if ($method === 'GET') {
        $j = http_json('GET', $base . $path . '?' . http_build_query($sys + $apiParams));
    } else {
        $j = http_json('POST', $base . $path . '?' . http_build_query($sys), [], http_build_query($apiParams), true);
    }
    if (isset($j['_error'])) throw new RuntimeException('Lazada ' . $path . ': ' . $j['_error']);
    if (isset($j['code']) && (string)$j['code'] !== '0') {
        throw new RuntimeException('Lazada ' . $path . ': ' . $j['code'] . ' - ' . ($j['message'] ?? $j['type'] ?? ''));
    }
    return $j;
}

function lz_store_tokens(array $j, ?array $old = null): array
{
    $t = [
        'access_token' => $j['access_token'],
        'refresh_token' => $j['refresh_token'] ?? ($old['refresh_token'] ?? ''),
        'expires_at' => time() + (int)($j['expires_in'] ?? 604800),
        'refresh_expires_at' => time() + (int)($j['refresh_expires_in'] ?? 2592000),
        'account' => $j['account'] ?? ($old['account'] ?? ''),
        'country' => $j['country'] ?? ($old['country'] ?? ''),
        'seller_id' => $j['country_user_info'][0]['seller_id'] ?? ($j['country_user_info_list'][0]['seller_id'] ?? ($old['seller_id'] ?? '')),
    ];
    ptoken_save('lazada', $t);
    return $t;
}

function lz_connect(string $input): array
{
    $code = trim($input);
    if (preg_match('~^https?://~i', $code)) {
        parse_str((string)parse_url($code, PHP_URL_QUERY), $q);
        $code = (string)($q['code'] ?? '');
    }
    if ($code === '') throw new RuntimeException('Kode otorisasi tidak ditemukan. Salin SELURUH alamat hasil redirect (ada "code=...").');
    $j = lz_call(lz_auth_host(), '/auth/token/create', ['code' => $code], 'POST');
    if (empty($j['access_token'])) throw new RuntimeException('Lazada tidak mengembalikan access_token');
    return lz_store_tokens($j);
}

function lz_tokens(): array
{
    $t = ptoken_load('lazada');
    if (!$t) throw new RuntimeException('NOT_CONNECTED');
    if ($t['expires_at'] - time() < 600) {
        $j = lz_call(lz_auth_host(), '/auth/token/refresh', ['refresh_token' => $t['refresh_token']], 'POST');
        if (empty($j['access_token'])) throw new RuntimeException('Refresh token Lazada gagal, hubungkan ulang toko.');
        $t = lz_store_tokens($j, $t);
    }
    return $t;
}

function lz_get(string $path, array $params): array
{
    $t = lz_tokens();
    return lz_call(lz_api_host(), $path, $params, 'GET', $t['access_token']);
}

function lz_sync(PDO $pdo, int $days): array
{
    @set_time_limit((int)($GLOBALS['SYNC_TIME_LIMIT'] ?? 0));
    $t = lz_tokens();
    $shop = (string)($t['account'] ?? '');
    $after = date('c', time() - $days * 86400); // ISO 8601 dengan zona waktu
    $orders = [];
    $offset = 0; $limit = 100;
    do {
        $j = lz_get('/orders/get', ['created_after' => $after, 'limit' => $limit, 'offset' => $offset, 'sort_by' => 'created_at', 'sort_direction' => 'DESC']);
        $list = $j['data']['orders'] ?? [];
        foreach ($list as $o) $orders[(string)$o['order_id']] = $o;
        $offset += count($list);
        $total = (int)($j['data']['countTotal'] ?? 0);
    } while (count($list) === $limit && ($total === 0 || $offset < $total));

    // item (berisi tracking_code) diambil per 50 pesanan
    $items = [];
    foreach (array_chunk(array_keys($orders), 50) as $chunk) {
        $j = lz_get('/orders/items/get', ['order_ids' => json_encode(array_map('intval', $chunk))]);
        foreach (($j['data'] ?? []) as $row) $items[(string)$row['order_id']] = $row['order_items'] ?? [];
    }

    $new = 0; $updated = 0; $withResi = 0;
    $pdo->beginTransaction();
    try {
        foreach ($orders as $id => $o) {
            $its = $items[$id] ?? [];
            $resi = ''; $kurir = ''; $names = []; $skuC = []; $subtotal = 0; $lines = [];
            foreach ($its as $it) {
                if ($resi === '' && !empty($it['tracking_code'])) { $resi = (string)$it['tracking_code']; $kurir = (string)($it['shipment_provider'] ?? ''); }
                if (($it['status'] ?? '') === 'canceled') continue;
                $harga = (float)(($it['item_price'] ?? 0) ?: ($it['paid_price'] ?? 0));
                $subtotal += (int)round($harga); // tiap baris = 1 unit
                $lines[] = item_line((string)($it['name'] ?? ''), (string)($it['variation'] ?? ''), (string)(($it['sku'] ?? '') !== '' ? $it['sku'] : ($it['shop_sku'] ?? '')), 1, $harga);
                $n = trim((string)($it['name'] ?? '') . (empty($it['variation']) ? '' : ' - ' . $it['variation']));
                $names[$n] = ($names[$n] ?? 0) + 1;
                $k = (string)(($it['sku'] ?? '') !== '' ? $it['sku'] : ($it['shop_sku'] ?? ''));
                if ($k !== '') $skuC[$k] = ($skuC[$k] ?? 0) + 1;
            }
            $barang = implode('; ', array_map(fn($n, $c) => $c > 1 ? "$n x$c" : $n, array_keys($names), $names));
            $qty = array_sum($names);
            $a = $o['address_shipping'] ?? [];
            $alamat = trim(implode(', ', array_filter([$a['address1'] ?? '', $a['address3'] ?? '', $a['address4'] ?? '', $a['city'] ?? '', $a['post_code'] ?? ''])));
            $created = !empty($o['created_at']) ? (int)strtotime((string)$o['created_at']) : 0;
            $ship = '';
            foreach ($its as $it) if (!empty($it['promised_shipping_time'])) { $ship = date('d/m/Y H:i', (int)strtotime((string)$it['promised_shipping_time'])); break; }
            $st = is_array($o['statuses'] ?? null) ? ($o['statuses'][0] ?? '') : (string)($o['statuses'] ?? '');
            if ($resi !== '') $withResi++;
            $isNew = orders_upsert($pdo, 'lazada', [
                'order_sn' => (string)($o['order_number'] ?? $id), 'resi' => $resi, 'kurir' => $kurir, 'status' => $st,
                'nama_toko' => $shop,
                'nama_penerima' => trim(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? '')) ?: (string)($o['customer_first_name'] ?? ''),
                'no_hp' => (string)($a['phone'] ?? ''), 'alamat' => $alamat, 'nama_barang' => $barang, 'qty' => $qty,
                'sku' => implode('; ', array_map(fn($k, $c) => $c > 1 ? "$k x$c" : $k, array_keys($skuC), $skuC)),
                'subtotal' => $subtotal, 'items' => items_group($lines), 'batas_kirim' => $ship, 'tanggal_pesan' => fmt_ts($created), 'total' => (string)($o['price'] ?? ''),
                'username_pembeli' => (string)($o['customer_first_name'] ?? ''), 'catatan' => (string)($o['remarks'] ?? ''), 'create_time' => $created,
            ]);
            $isNew ? $new++ : $updated++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return ['fetched' => count($orders), 'new' => $new, 'updated' => $updated, 'resi_failed' => 0,
        'resi_pending' => 0, 'backfilled' => backfill_scanned_resi($pdo)];
}
