<?php
// Pencocokan resi <-> pesanan Shopee (dipakai saat scan dan setelah tarik pesanan).

function find_order_by_resi(PDO $pdo, string $resi) {
    $resi = trim($resi);
    if ($resi === '') return null;
    $st = $pdo->prepare("SELECT * FROM orders WHERE UPPER(resi) = UPPER(?) ORDER BY id DESC LIMIT 1");
    $st->execute([$resi]);
    return $st->fetch() ?: null;
}

/** Susun 1 baris rincian item: {nama, varian, sku, qty, harga, subtotal} */
function item_line(string $nama, string $varian, string $sku, int $qty, float $harga): array {
    return ['nama' => $nama, 'varian' => $varian, 'sku' => $sku, 'qty' => $qty, 'harga' => (int)round($harga), 'subtotal' => (int)round($harga * $qty)];
}

/** Gabungkan unit yang sama (nama+varian+sku+harga) jadi 1 baris dengan qty bertambah (Lazada/TikTok: 1 baris = 1 unit). */
function items_group(array $lines): array {
    $out = [];
    foreach ($lines as $l) {
        $k = $l['nama'] . '|' . $l['varian'] . '|' . $l['sku'] . '|' . $l['harga'];
        if (!isset($out[$k])) { $out[$k] = $l; continue; }
        $out[$k]['qty'] += $l['qty'];
        $out[$k]['subtotal'] += $l['subtotal'];
    }
    return array_values($out);
}

function items_to_json(array $items): string {
    return $items ? json_encode(array_values($items), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
}

/** Teks rincian untuk Excel: satu item per baris. */
function items_text(?string $json): string {
    $a = $json ? json_decode($json, true) : [];
    if (!is_array($a)) return '';
    $rows = [];
    foreach ($a as $i => $it) {
        $nm = trim($it['nama'] . ($it['varian'] !== '' ? ' - ' . $it['varian'] : ''));
        $sku = $it['sku'] !== '' ? ' [' . $it['sku'] . ']' : '';
        $rows[] = ($i + 1) . '. ' . $nm . $sku . ' | ' . $it['qty'] . ' x Rp' . number_format((int)$it['harga'], 0, ',', '.') . ' = Rp' . number_format((int)$it['subtotal'], 0, ',', '.');
    }
    return implode("\n", $rows);
}

function cut_str($s, int $n): string {
    $s = (string)$s;
    return function_exists('mb_substr') ? mb_substr($s, 0, $n, 'UTF-8') : substr($s, 0, $n);
}

/** Salin detail pesanan ke 1 baris resi, ditandai matched = otomatis. */
function apply_order_to_resi(PDO $pdo, int $resiId, array $o): void {
    $tgl = substr((string)($o['batas_kirim'] ?? ''), 0, 10);
    $st = $pdo->prepare("UPDATE resi SET order_sn = ?, nama_toko = ?, nama_penerima = ?, alamat = ?, nama_barang = ?, qty = ?, tanggal_kirim = ?, sku = ?, subtotal = ?, items_json = ?, matched = 1 WHERE id = ?");
    $st->execute([
        cut_str($o['order_sn'], 60), cut_str($o['nama_toko'], 150), cut_str($o['nama_penerima'], 150), (string)$o['alamat'],
        (string)$o['nama_barang'], cut_str($o['qty'], 20), cut_str($tgl, 30), (string)($o['sku'] ?? ''), (int)($o['subtotal'] ?? 0), (string)($o['items_json'] ?? ''), $resiId,
    ]);
}

/**
 * Setelah tarik pesanan: isi resi lama yang sudah discan tapi belum punya detail.
 * Baris yang sudah diisi/diedit manual TIDAK ditimpa.
 */
function backfill_scanned_resi(PDO $pdo): int {
    $n = 0;
    $orders = $pdo->query("SELECT * FROM orders WHERE resi <> ''")->fetchAll();
    $sel = $pdo->prepare("SELECT id FROM resi WHERE UPPER(resi) = UPPER(?) AND COALESCE(order_sn,'') = ''
        AND COALESCE(nama_toko,'') = '' AND COALESCE(nama_penerima,'') = '' AND COALESCE(alamat,'') = '' AND COALESCE(nama_barang,'') = ''");
    foreach ($orders as $o) {
        $sel->execute([$o['resi']]);
        foreach ($sel->fetchAll() as $r) {
            apply_order_to_resi($pdo, (int)$r['id'], $o);
            $n++;
        }
    }
    // resi yang sudah punya nomor pesanan tapi belum punya SKU (data lama): lengkapi SKU saja
    $up = $pdo->prepare("UPDATE resi SET sku = ? WHERE order_sn = ? AND COALESCE(sku,'') = ''");
    foreach ($pdo->query("SELECT order_sn, sku FROM orders WHERE COALESCE(sku,'') <> ''")->fetchAll() as $o) {
        $up->execute([$o['sku'], $o['order_sn']]);
        $n += $up->rowCount();
    }
    // samakan subtotal (harga posting) pada resi yang sudah punya nomor pesanan
    $us = $pdo->prepare("UPDATE resi SET subtotal = ? WHERE order_sn = ? AND (subtotal IS NULL OR subtotal <> ?)"); // ikut nilai terbaru dari pesanan
    foreach ($pdo->query("SELECT order_sn, subtotal FROM orders WHERE subtotal IS NOT NULL AND subtotal > 0")->fetchAll() as $o) {
        $us->execute([(int)$o['subtotal'], $o['order_sn'], (int)$o['subtotal']]);
        $n += $us->rowCount();
    }
    // rincian item per resi mengikuti pesanan
    $ui = $pdo->prepare("UPDATE resi SET items_json = ? WHERE order_sn = ? AND COALESCE(items_json,'') <> ?");
    foreach ($pdo->query("SELECT order_sn, items_json FROM orders WHERE COALESCE(items_json,'') <> ''")->fetchAll() as $o) {
        $ui->execute([$o['items_json'], $o['order_sn'], $o['items_json']]);
        $n += $ui->rowCount();
    }
    return $n;
}
