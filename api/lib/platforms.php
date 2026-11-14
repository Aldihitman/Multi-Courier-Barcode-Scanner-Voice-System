<?php
// Fungsi bersama untuk marketplace selain Shopee (Lazada, TikTok Shop/Tokopedia).

function http_json(string $method, string $url, array $headers = [], $body = null, bool $form = false): array
{
    $last = 'Gagal memanggil server marketplace';
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $ch = curl_init($url);
        $h = $headers;
        if ($body !== null) $h[] = $form ? 'Content-Type: application/x-www-form-urlencoded' : 'Content-Type: application/json';
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $h,
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $text = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);
        if ($text === false) {
            $last = 'Koneksi gagal: ' . $cerr;
            usleep(500000 * ($attempt + 1));
            continue;
        }
        if ($status === 429) { $last = 'Rate limit (HTTP 429)'; sleep($attempt + 1); continue; }
        $j = json_decode((string)$text, true);
        if (!is_array($j)) return ['_error' => 'Respons bukan JSON (HTTP ' . $status . '): ' . substr((string)$text, 0, 200)];
        return $j;
    }
    throw new RuntimeException($last);
}

// ---------- token (file PHP di storage/, tidak bisa dibuka lewat browser) ----------
function ptoken_file(string $name): string
{
    return __DIR__ . '/../../storage/' . $name . '_tokens.php';
}
function ptoken_load(string $name): ?array
{
    $f = ptoken_file($name);
    if (!is_file($f)) return null;
    $raw = preg_replace('/^<\?php[^\n]*\n/', '', (string)file_get_contents($f));
    $j = json_decode($raw, true);
    return is_array($j) && !empty($j['access_token']) ? $j : null;
}
function ptoken_save(string $name, array $t): void
{
    $f = ptoken_file($name);
    file_put_contents($f, "<?php http_response_code(403); exit; ?>\n" . json_encode($t), LOCK_EX);
    @chmod($f, 0600);
}

/** Simpan/perbarui 1 pesanan. Resi lama tidak dihapus kalau resi baru kosong. Return true kalau baru. */
function orders_upsert(PDO $pdo, string $platform, array $o): bool
{
    $sel = $pdo->prepare("SELECT id, resi FROM orders WHERE order_sn = ?");
    $sel->execute([$o['order_sn']]);
    $ex = $sel->fetch();
    $resi = (string)($o['resi'] ?? '');
    if ($resi === '' && $ex) $resi = (string)$ex['resi'];
    $v = [
        $platform, $resi, (string)($o['kurir'] ?? ''), (string)($o['status'] ?? ''), (string)($o['nama_toko'] ?? ''),
        (string)($o['nama_penerima'] ?? ''), (string)($o['no_hp'] ?? ''), (string)($o['alamat'] ?? ''),
        (string)($o['nama_barang'] ?? ''), (int)($o['qty'] ?? 0), (string)($o['batas_kirim'] ?? ''),
        (string)($o['tanggal_pesan'] ?? ''), (string)($o['total'] ?? ''), (string)($o['username_pembeli'] ?? ''),
        (string)($o['catatan'] ?? ''), (int)($o['create_time'] ?? 0), date('Y-m-d H:i:s'), (string)($o['sku'] ?? ''), (int)($o['subtotal'] ?? 0), items_to_json($o['items'] ?? []),
    ];
    if ($ex) {
        $pdo->prepare("UPDATE orders SET platform = ?, resi = ?, kurir = ?, status = ?, nama_toko = ?, nama_penerima = ?, no_hp = ?, alamat = ?, nama_barang = ?, qty = ?, batas_kirim = ?, tanggal_pesan = ?, total = ?, username_pembeli = ?, catatan = ?, create_time = ?, synced_at = ?, sku = ?, subtotal = ?, items_json = ? WHERE order_sn = ?")
            ->execute(array_merge($v, [$o['order_sn']]));
        return false;
    }
    $pdo->prepare("INSERT INTO orders (order_sn, platform, resi, kurir, status, nama_toko, nama_penerima, no_hp, alamat, nama_barang, qty, batas_kirim, tanggal_pesan, total, username_pembeli, catatan, create_time, synced_at, sku, subtotal, items_json) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute(array_merge([$o['order_sn']], $v));
    return true;
}

function autosync_last_file(): string { return __DIR__ . '/../../storage/autosync_last.json'; }
function autosync_last_read(): ?array
{
    $j = json_decode((string)@file_get_contents(autosync_last_file()), true);
    return is_array($j) ? $j : null;
}
/** Catat hasil tarik otomatis terakhir supaya kegagalan kelihatan di menu Marketplace. */
function autosync_last_write(array $res): void
{
    $sum = [];
    foreach ($res as $name => $r) {
        if (!is_array($r)) continue;
        if (!empty($r['synced'])) $sum[$name] = 'ok: ' . ($r['fetched'] ?? 0) . ' pesanan';
        elseif (($r['skipped'] ?? '') === 'error') $sum[$name] = 'GAGAL: ' . ($r['error'] ?? '');
        elseif (($r['skipped'] ?? '') === 'busy') $sum[$name] = 'GAGAL: kunci tarik masih dipegang proses lain (' . (int)($r['held_seconds'] ?? 0) . ' detik) - restart Apache/Laragon';
    }
    if (!$sum) return; // semua "belum terhubung" / "baru saja": tidak perlu dicatat
    @file_put_contents(autosync_last_file(), json_encode(['at' => time(), 'result' => $sum]), LOCK_EX);
}

/** Tarik otomatis berpembatas (jeda minimal + kunci supaya tidak jalan dobel), per platform. */
function platform_autosync(PDO $pdo, string $name, int $minInterval, callable $run): array
{
    $dir = __DIR__ . '/../../storage';
    $lockPath = $dir . "/sync_$name.lock";
    $fh = @fopen($lockPath, 'c+');
    if (!$fh) return ['skipped' => 'error', 'error' => 'Tidak bisa membuat file kunci di folder storage (cek izin tulis folder storage).'];
    if (!flock($fh, LOCK_EX | LOCK_NB)) {
        $since = time() - (int)@filemtime($lockPath);
        fclose($fh);
        return ['skipped' => 'busy', 'held_seconds' => $since];
    }
    ftruncate($fh, 0); fwrite($fh, (string)time()); fflush($fh);
    $GLOBALS['SYNC_TIME_LIMIT'] = 150; // proses macet dihentikan PHP, kunci pun terlepas
    try {
        $stamp = $dir . "/lastsync_$name.txt";
        if (time() - (int)@file_get_contents($stamp) < $minInterval) return ['skipped' => 'recent'];
        @file_put_contents($stamp, (string)time());
        $r = $run($pdo);
        $r['synced'] = true;
        return $r;
    } catch (Throwable $e) {
        return ['skipped' => 'error', 'error' => $e->getMessage()];
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

function fmt_ts($unix): string
{
    return $unix ? date('d/m/Y H:i', (int)$unix) : '';
}
