<?php
// Tarik pesanan Shopee -> tabel orders, lalu lengkapi resi yang sudah discan.

/** @throws Throwable */
function shopee_sync(PDO $pdo, int $days, string $status, int $maxTracking): array {
    @set_time_limit((int)($GLOBALS['SYNC_TIME_LIMIT'] ?? 0));
    @ini_set('memory_limit', '512M');
    $failed = 0; $pendingResi = 0; $new = 0; $updated = 0;
    try {
        $now = time();
        $name = shop_name();
        $sns = list_order_sns($now - $days * 86400, $now, $status);
        $orders = $sns ? order_details($sns) : [];

        $known = [];
        $st = $pdo->prepare("SELECT resi FROM orders WHERE order_sn = ?");
        foreach ($orders as $o) {
            $st->execute([$o['order_sn']]);
            $r = $st->fetch();
            if ($r && $r['resi'] !== '') $known[$o['order_sn']] = $r['resi'];
        }
        $needTrack = [];
        foreach ($orders as $o) {
            $sn = $o['order_sn'];
            if (isset($known[$sn])) continue;
            if (in_array($o['order_status'] ?? '', ['UNPAID', 'CANCELLED'], true)) continue;
            $needTrack[] = $sn;
        }
        $pendingResi = max(0, count($needTrack) - $maxTracking);
        $tracking = $needTrack ? tracking_numbers(array_slice($needTrack, 0, $maxTracking), $failed) : [];
        $tracking = $tracking + $known;

        $rows = build_rows($orders, $name, $tracking);
        $ins = $pdo->prepare("INSERT INTO orders (order_sn, resi, kurir, status, nama_toko, nama_penerima, no_hp, alamat, nama_barang, qty, batas_kirim, tanggal_pesan, total, username_pembeli, catatan, create_time, synced_at, sku, subtotal, items_json)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $upd = $pdo->prepare("UPDATE orders SET resi = ?, kurir = ?, status = ?, nama_toko = ?, nama_penerima = ?, no_hp = ?, alamat = ?, nama_barang = ?, qty = ?, batas_kirim = ?, tanggal_pesan = ?, total = ?, username_pembeli = ?, catatan = ?, create_time = ?, synced_at = ?, sku = ?, subtotal = ?, items_json = ? WHERE order_sn = ?");
        $sel = $pdo->prepare("SELECT id, resi FROM orders WHERE order_sn = ?");
        $nowStr = date('Y-m-d H:i:s');
        $pdo->beginTransaction();
        foreach ($rows as $i => $r) {
            $o = $orders[$i];
            $sn = (string)$r['Nomor Pesanan'];
            $sel->execute([$sn]);
            $ex = $sel->fetch();
            $resi = (string)$r['Nomor Resi'];
            if ($resi === '' && $ex) $resi = (string)$ex['resi'];
            $vals = [$resi, (string)$r['Kurir'], (string)$r['Status'], (string)$r['Nama Toko'], (string)$r['Nama Penerima'],
                (string)$r['No HP Penerima'], (string)$r['Alamat'], (string)$r['Nama Barang'], (int)$r['Qty'],
                (string)$r['Batas Kirim'], (string)$r['Tanggal Pesan'], (string)$r['Total'], (string)$r['Username Pembeli'],
                (string)$r['Catatan'], (int)($o['create_time'] ?? 0), $nowStr, (string)($r['SKU'] ?? ''), (int)($r['Subtotal Pesanan'] ?? 0), items_to_json($r['Items'] ?? [])];
            if ($ex) { $upd->execute(array_merge($vals, [$sn])); $updated++; }
            else { $ins->execute(array_merge([$sn], $vals)); $new++; }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    $backfilled = backfill_scanned_resi($pdo);
    return [
        'fetched' => count($orders), 'new' => $new, 'updated' => $updated,
        'resi_failed' => $failed, 'resi_pending' => $pendingResi, 'backfilled' => $backfilled,
    ];
}

/** Tarik otomatis Shopee: memakai pembatas & kunci yang sama dengan platform lain. */
function shopee_autosync(PDO $pdo, int $minInterval): array {
    $c = cfg();
    if (empty($c['partner_id']) || empty($c['partner_key']) || !load_tokens()) return ['skipped' => 'not_connected'];
    return platform_autosync($pdo, 'shopee', $minInterval, fn($p) => shopee_sync($p, 2, '', 20));
}
