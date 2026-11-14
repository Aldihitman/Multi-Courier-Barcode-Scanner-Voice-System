<?php
/**
 * Konektor Shopee Open Platform v2 (PHP, tanpa composer/library tambahan).
 * Butuh ekstensi PHP: curl, zip (keduanya sudah aktif di XAMPP/Laragon dan hampir semua hosting).
 */

// Pengaturan diambil dari ../../config.php (lewat app_cfg() di api/db.php).
function cfg(): array
{
    global $APP_CONFIG;
    return is_array($APP_CONFIG) ? $APP_CONFIG : [];
}

function shopee_host(): string
{
    $c = cfg();
    if (!empty($c['host'])) return rtrim($c['host'], '/');
    return ($c['env'] ?? 'sandbox') === 'live'
        ? 'https://partner.shopeemobile.com'
        : 'https://partner.test-stable.shopeemobile.com';
}

// ---------------- tanda tangan HMAC-SHA256 ----------------
// API publik : partner_id + path + timestamp
// API toko   : partner_id + path + timestamp + access_token + shop_id
function shopee_sign(string $path, int $ts, string $accessToken = '', string $shopId = '', int $variant = 0): string
{
    $c = cfg();
    $keys = key_variants();
    $key = $keys[$variant] ?? $keys[0];
    return hash_hmac('sha256', ((string)(int)$c['partner_id']) . $path . $ts . $accessToken . $shopId, $key);
}

/**
 * Cara memakai Partner Key untuk menandatangani:
 *  [0] apa adanya (teks), [1] bagian setelah "shpk" di-decode dari hex (hanya kalau key berawalan shpk).
 * config 'key_mode': 'auto' (coba keduanya, default), 'raw', atau 'hex'.
 */
function key_variants(): array
{
    $k = (string)(cfg()['partner_key'] ?? '');
    $mode = (string)(cfg()['key_mode'] ?? 'auto');
    $hex = null;
    if (stripos($k, 'shpk') === 0) {
        $h = substr($k, 4);
        if ($h !== '' && strlen($h) % 2 === 0 && ctype_xdigit($h)) $hex = hex2bin($h);
    }
    if ($mode === 'raw' || $hex === null) return [$k];
    if ($mode === 'hex') return [$hex];
    return [$k, $hex];
}

function keymode_file(): string
{
    return getenv('SHOPEE_KEYMODE_FILE') ?: __DIR__ . '/../../storage/keymode.txt';
}

function preferred_variant(): int
{
    $i = (int)trim((string)@file_get_contents(keymode_file()));
    return ($i >= 0 && $i < count(key_variants())) ? $i : 0;
}

/**
 * Panggil API dengan tanda tangan. Kalau Shopee menjawab error_sign, coba cara lain memakai key,
 * lalu ingat cara yang berhasil supaya panggilan berikutnya langsung benar.
 */
function signed_request(string $method, string $path, array $params, ?array $body, bool $shop, array $t = []): array
{
    $n = count(key_variants());
    $pref = preferred_variant();
    $order = array_merge([$pref], array_values(array_diff(range(0, $n - 1), [$pref])));
    $pid = (int)cfg()['partner_id'];
    $j = [];
    foreach ($order as $v) {
        $ts = time();
        $q = ['partner_id' => $pid, 'timestamp' => $ts];
        if ($shop) {
            $q['access_token'] = $t['access_token'];
            $q['shop_id'] = $t['shop_id'];
            $q['sign'] = shopee_sign($path, $ts, (string)$t['access_token'], (string)$t['shop_id'], $v);
        } else {
            $q['sign'] = shopee_sign($path, $ts, '', '', $v);
        }
        $j = shopee_request($method, $path, $q + $params, $body);
        if (($j['error'] ?? '') === 'error_sign') continue;
        if (empty($j['error']) && $v !== $pref) @file_put_contents(keymode_file(), (string)$v);
        return $j;
    }
    return $j;
}

// ---------------- penyimpanan token (file PHP, tidak bisa dibaca lewat browser) ----------------
function token_file(): string
{
    return getenv('SHOPEE_TOKEN_FILE') ?: __DIR__ . '/../../storage/tokens.php';
}

function load_tokens(): ?array
{
    $f = token_file();
    if (!is_file($f)) return null;
    $raw = file_get_contents($f);
    $raw = preg_replace('/^<\?php[^\n]*\n/', '', (string)$raw);
    $j = json_decode((string)$raw, true);
    return is_array($j) && !empty($j['access_token']) ? $j : null;
}

function save_tokens(array $t): void
{
    $data = "<?php http_response_code(403); exit; ?>\n" . json_encode($t);
    file_put_contents(token_file(), $data, LOCK_EX);
    @chmod(token_file(), 0600);
}

// ---------------- HTTP ----------------
function shopee_request(string $method, string $path, array $query, ?array $body = null): array
{
    $url = shopee_host() . $path . '?' . http_build_query($query);
    $last = 'Gagal memanggil Shopee';
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        $text = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);
        if ($text === false) {
            $last = 'Koneksi ke Shopee gagal: ' . $cerr;
            usleep(500000 * ($attempt + 1));
            continue;
        }
        $j = json_decode((string)$text, true);
        if (!is_array($j)) $j = ['error' => 'bad_json', 'message' => substr((string)$text, 0, 200)];
        $msg = ($j['error'] ?? '') . ' ' . ($j['message'] ?? '');
        if ($status === 429 || (!empty($j['error']) && preg_match('/rate|limit/i', $msg))) {
            $last = 'Rate limit Shopee: ' . trim($msg);
            sleep($attempt + 1);
            continue;
        }
        return $j;
    }
    throw new RuntimeException($last);
}

function assert_ok(array $j, string $what): array
{
    if (!empty($j['error'])) {
        $hint = $j['error'] === 'error_sign'
            ? "\n\nSaran: pastikan partner_id dan partner_key keduanya dari pasangan Test (bukan Live), dan cek alamat server API sandbox di Console lalu isi di 'host' pada config.php."
            : '';
        throw new RuntimeException($what . ': ' . $j['error'] . ' - ' . ($j['message'] ?? '') . $hint);
    }
    return $j;
}

// ---------------- otorisasi ----------------
function auth_url(): string
{
    $c = cfg();
    // Format baru (tanpa tanda tangan), seperti yang ditampilkan Console Shopee:
    //   https://open.sandbox.test-stable.shopee.com/auth?auth_type=seller&partner_id=...&redirect_uri=...&response_type=code
    if (!empty($c['auth_base'])) {
        return rtrim((string)$c['auth_base'], '/') . '/auth?' . http_build_query([
            'auth_type'     => 'seller',
            'partner_id'    => (int)$c['partner_id'],
            'redirect_uri'  => $c['redirect_url'],
            'response_type' => 'code',
        ]);
    }
    // Format lama (bertanda tangan)
    $p = '/api/v2/shop/auth_partner';
    $ts = time();
    return shopee_host() . $p . '?' . http_build_query([
        'partner_id' => (int)cfg()['partner_id'],
        'timestamp'  => $ts,
        'sign'       => shopee_sign($p, $ts),
        'redirect'   => cfg()['redirect_url'],
    ]);
}

function exchange_code(string $code, string $shopId): array
{
    $p = '/api/v2/auth/token/get';
    $pid = (int)cfg()['partner_id'];
    $j = assert_ok(signed_request('POST', $p, [],
        ['code' => $code, 'shop_id' => (int)$shopId, 'partner_id' => $pid], false), 'Tukar kode otorisasi');
    $t = [
        'shop_id' => (int)$shopId,
        'access_token' => $j['access_token'],
        'refresh_token' => $j['refresh_token'],
        'expires_at' => time() + (int)($j['expire_in'] ?? 14400),
    ];
    save_tokens($t);
    return $t;
}

function refresh_tokens(array $t): array
{
    $p = '/api/v2/auth/access_token/get';
    $pid = (int)cfg()['partner_id'];
    $j = assert_ok(signed_request('POST', $p, [],
        ['refresh_token' => $t['refresh_token'], 'shop_id' => (int)$t['shop_id'], 'partner_id' => $pid], false), 'Refresh token');
    // refresh_token Shopee sekali pakai -> WAJIB simpan yang baru
    $n = [
        'shop_id' => (int)$t['shop_id'],
        'access_token' => $j['access_token'],
        'refresh_token' => $j['refresh_token'],
        'expires_at' => time() + (int)($j['expire_in'] ?? 14400),
    ];
    save_tokens($n);
    return $n;
}

function valid_tokens(): array
{
    $t = load_tokens();
    if (!$t) throw new RuntimeException('NOT_CONNECTED');
    if ($t['expires_at'] - time() < 300) $t = refresh_tokens($t);
    return $t;
}

function shop_call(string $method, string $path, array $params = []): array
{
    $t = valid_tokens();
    return assert_ok(signed_request($method, $path, $params, null, true, $t), $path);
}

// ---------------- data pesanan ----------------
function shop_name(): string
{
    try {
        $j = shop_call('GET', '/api/v2/shop/get_shop_info');
        return (string)(($j['response']['shop_name'] ?? $j['shop_name'] ?? ''));
    } catch (Throwable $e) {
        return '';
    }
}

/** Semua order_sn dalam rentang waktu; otomatis dipecah per 15 hari + paginasi cursor. */
function list_order_sns(int $from, int $to, string $status = ''): array
{
    $window = 15 * 24 * 3600 - 1; // batas Shopee: maks 15 hari per permintaan
    $all = [];
    for ($start = $from; $start <= $to; $start += $window + 1) {
        $end = min($start + $window, $to);
        $cursor = '';
        do {
            $params = [
                'time_range_field' => 'create_time',
                'time_from' => $start,
                'time_to' => $end,
                'page_size' => 100,
            ];
            if ($cursor !== '') $params['cursor'] = $cursor;
            if ($status !== '') $params['order_status'] = $status;
            $j = shop_call('GET', '/api/v2/order/get_order_list', $params);
            $r = $j['response'] ?? [];
            foreach (($r['order_list'] ?? []) as $o) $all[$o['order_sn']] = true;
            $more = !empty($r['more']);
            $cursor = (string)($r['next_cursor'] ?? '');
        } while ($more && $cursor !== '');
    }
    return array_keys($all);
}

function order_details(array $sns): array
{
    $fields = 'buyer_user_id,buyer_username,recipient_address,item_list,note,package_list,shipping_carrier,total_amount,pay_time';
    $out = [];
    foreach (array_chunk($sns, 50) as $chunk) {
        $j = shop_call('GET', '/api/v2/order/get_order_detail', [
            'order_sn_list' => implode(',', $chunk),
            'response_optional_fields' => $fields,
        ]);
        foreach (($j['response']['order_list'] ?? []) as $o) $out[] = $o;
    }
    return $out;
}

/** 1 panggilan per pesanan (berurutan). Gagal satu tidak menggagalkan semuanya. */
function tracking_numbers(array $sns, int &$failed): array
{
    $res = [];
    foreach ($sns as $sn) {
        try {
            $j = shop_call('GET', '/api/v2/logistics/get_tracking_number', ['order_sn' => $sn]);
            $res[$sn] = (string)($j['response']['tracking_number'] ?? '');
        } catch (Throwable $e) {
            $res[$sn] = '';
            $failed++;
        }
    }
    return $res;
}

// ---------------- baris Excel ----------------
function fmt_dt($unix): string
{
    return $unix ? date('d/m/Y H:i', (int)$unix) : '';
}

/** Nama kolom dibuat cocok dengan aplikasi Scan Resi (resi, toko, penerima, alamat, barang, qty, kirim). */
function build_rows(array $orders, string $shopName, array $tracking): array
{
    $rows = [];
    foreach ($orders as $i => $o) {
        $items = $o['item_list'] ?? [];
        $a = $o['recipient_address'] ?? [];
        $pkg = ($o['package_list'] ?? [])[0] ?? [];
        $barang = implode('; ', array_map(
            fn($it) => implode(' - ', array_filter([$it['item_name'] ?? '', $it['model_name'] ?? ''])),
            $items
        ));
        $skus = [];
        foreach ($items as $it) {
            $k = (string)(($it['model_sku'] ?? '') !== '' ? $it['model_sku'] : ($it['item_sku'] ?? ''));
            if ($k === '') continue;
            $n = (int)($it['model_quantity_purchased'] ?? 1);
            $skus[] = $n > 1 ? "$k x$n" : $k;
        }
        $qty = array_sum(array_map(fn($it) => (int)($it['model_quantity_purchased'] ?? 0), $items));
        // Subtotal = HARGA POSTING (harga asli listing, sebelum diskon) x jumlah, dijumlah semua barang
        $subtotal = 0; $lines = [];
        foreach ($items as $it) {
            $price = (float)(($it['model_original_price'] ?? 0) ?: ($it['model_discounted_price'] ?? 0));
            $q = max(1, (int)($it['model_quantity_purchased'] ?? 1));
            $subtotal += (int)round($price * $q);
            $sk = (string)(($it['model_sku'] ?? '') !== '' ? $it['model_sku'] : ($it['item_sku'] ?? ''));
            $lines[] = item_line((string)($it['item_name'] ?? ''), (string)($it['model_name'] ?? ''), $sk, $q, $price);
        }
        $alamat = $a['full_address'] ?? implode(', ', array_filter([
            $a['town'] ?? '', $a['district'] ?? '', $a['city'] ?? '', $a['state'] ?? '', $a['zipcode'] ?? '',
        ]));
        $rows[] = [
            'No' => $i + 1,
            'Nomor Pesanan' => $o['order_sn'] ?? '',
            'Nomor Resi' => $tracking[$o['order_sn'] ?? ''] ?? '' ?: ($pkg['tracking_number'] ?? ''),
            'Kurir' => $o['shipping_carrier'] ?? ($pkg['shipping_carrier'] ?? ''),
            'Status' => $o['order_status'] ?? '',
            'Nama Toko' => $shopName,
            'Nama Penerima' => $a['name'] ?? '',
            'No HP Penerima' => $a['phone'] ?? '',
            'Alamat' => $alamat,
            'Nama Barang' => $barang,
            'Qty' => $qty,
            'Batas Kirim' => fmt_dt($o['ship_by_date'] ?? null),
            'Tanggal Pesan' => fmt_dt($o['create_time'] ?? null),
            'Subtotal Pesanan' => $subtotal,
            'Items' => $lines,
            'Total' => $o['total_amount'] ?? '',
            'Username Pembeli' => $o['buyer_username'] ?? '',
            'Catatan' => $o['note'] ?? '',
            'SKU' => implode('; ', $skus),
        ];
    }
    return $rows;
}

// ---------------- penulis .xlsx minimal (tanpa library) ----------------
function xml_esc(string $s): string
{
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);
    return htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function col_letter(int $i): string
{
    $s = '';
    for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s;
    return $s;
}

/** Mengembalikan isi file .xlsx sebagai string biner. */
function xlsx_binary(array $rows, string $sheetName = 'Pesanan Shopee'): string
{
    if (!class_exists('ZipArchive')) throw new RuntimeException('Ekstensi PHP "zip" belum aktif.');
    $headers = $rows ? array_keys($rows[0]) : ['Info'];
    $widths = [5, 18, 20, 16, 14, 20, 20, 16, 40, 40, 6, 17, 17, 12, 18, 26];

    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><cols>';
    foreach ($headers as $i => $_) {
        $sheet .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . ($widths[$i] ?? 16) . '" customWidth="1"/>';
    }
    $sheet .= '</cols><sheetData><row r="1">';
    foreach ($headers as $i => $hd) {
        $sheet .= '<c r="' . col_letter($i) . '1" t="inlineStr"><is><t>' . xml_esc((string)$hd) . '</t></is></c>';
    }
    $sheet .= '</row>';
    foreach ($rows as $r => $row) {
        $n = $r + 2;
        $sheet .= '<row r="' . $n . '">';
        $c = 0;
        foreach ($row as $v) {
            $ref = col_letter($c) . $n;
            if (is_int($v) || is_float($v)) {
                $sheet .= '<c r="' . $ref . '"><v>' . $v . '</v></c>';
            } else {
                $sheet .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . xml_esc((string)$v) . '</t></is></c>';
            }
            $c++;
        }
        $sheet .= '</row>';
    }
    $sheet .= '</sheetData></worksheet>';

    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    $z = new ZipArchive();
    if ($z->open($tmp, ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Gagal membuat file Excel.');
    $z->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '</Types>');
    $z->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>');
    $z->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="' . xml_esc($sheetName) . '" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $z->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '</Relationships>');
    $z->addFromString('xl/worksheets/sheet1.xml', $sheet);
    $z->close();
    $bin = (string)file_get_contents($tmp);
    @unlink($tmp);
    return $bin;
}
