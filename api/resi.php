<?php
require __DIR__ . '/db.php';
require __DIR__ . '/lib/orders.php';
ensure_schema($pdo);

$method = $_SERVER['REQUEST_METHOD'];

/**
 * Menentukan scope cabang yang boleh diakses request ini.
 * - user biasa: dipaksa ke cabang_id miliknya sendiri (tidak bisa diintervensi dari client)
 * - admin: boleh pilih cabang_id tertentu, atau 'all' untuk lihat gabungan semua cabang
 * Return: ['all' => bool, 'cabang_id' => int|null]
 */
function resolve_scope($requestedCabangId) {
    if (($_SESSION['role'] ?? '') !== 'admin') {
        return ['all' => false, 'cabang_id' => (int)$_SESSION['cabang_id']];
    }
    if ($requestedCabangId === null || $requestedCabangId === '' || $requestedCabangId === 'all' || $requestedCabangId === '__ALL__') {
        return ['all' => true, 'cabang_id' => null];
    }
    return ['all' => false, 'cabang_id' => (int)$requestedCabangId];
}

function row_out($row) {
    return [
        'id' => (int)$row['id'],
        'resi' => $row['resi'],
        'kurir' => $row['kurir'],
        'cabangId' => (int)$row['cabang_id'],
        'cabang' => $row['cabang_nama'],
        'catatan' => $row['catatan'],
        'namaToko' => $row['nama_toko'],
        'namaPenerima' => $row['nama_penerima'],
        'alamat' => $row['alamat'],
        'namaBarang' => $row['nama_barang'],
        'qty' => $row['qty'],
        'tanggalKirim' => $row['tanggal_kirim'],
        'scanOleh' => $row['user_nama'] ?? '',
        'orderSn' => $row['order_sn'] ?? '',
        'sku' => $row['sku'] ?? '',
        'items' => (isset($row['items_json']) && $row['items_json'] !== '' && $row['items_json'] !== null) ? (json_decode($row['items_json'], true) ?: []) : [],
        'subtotal' => isset($row['subtotal']) && $row['subtotal'] !== null ? (int)$row['subtotal'] : 0,
        'batch' => isset($row['export_batch']) && $row['export_batch'] !== null ? (int)$row['export_batch'] : null,
        'matched' => (bool)$row['matched'],
        'waktu' => str_replace(' ', 'T', $row['waktu_scan']),
    ];
}

require_login();

if ($method === 'GET') {
    $scope = resolve_scope($_GET['cabang_id'] ?? null);

    // ---- mode 1: index tanggal (dropdown tanggal + jumlah resi per tanggal) ----
    if (!empty($_GET['dates'])) {
        if ($scope['all']) {
            $sql = "SELECT DATE(waktu_scan) AS tanggal, COUNT(*) AS c FROM resi GROUP BY tanggal ORDER BY tanggal DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
        } else {
            $sql = "SELECT DATE(waktu_scan) AS tanggal, COUNT(*) AS c FROM resi WHERE cabang_id = ? GROUP BY tanggal ORDER BY tanggal DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$scope['cabang_id']]);
        }
        $out = array_map(fn($r) => ['date' => $r['tanggal'], 'count' => (int)$r['c']], $stmt->fetchAll());
        echo json_encode($out);
        exit;
    }

    // ---- mode 2: daftar resi untuk 1 tanggal tertentu ----
    $date = $_GET['date'] ?? '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        http_response_code(400);
        echo json_encode(['error' => 'Parameter tanggal tidak valid']);
        exit;
    }

    if ($scope['all']) {
        $sql = "SELECT r.*, c.nama AS cabang_nama, u.username AS user_nama FROM resi r
                JOIN cabang c ON c.id = r.cabang_id LEFT JOIN users u ON u.id = r.user_id
                WHERE DATE(r.waktu_scan) = ?
                ORDER BY r.waktu_scan DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$date]);
    } else {
        $sql = "SELECT r.*, c.nama AS cabang_nama, u.username AS user_nama FROM resi r
                JOIN cabang c ON c.id = r.cabang_id LEFT JOIN users u ON u.id = r.user_id
                WHERE DATE(r.waktu_scan) = ? AND r.cabang_id = ?
                ORDER BY r.waktu_scan DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$date, $scope['cabang_id']]);
    }
    $rows = array_map('row_out', $stmt->fetchAll());
    if (!empty($_GET['sig'])) {
        // sidik jari daftar (untuk pembaruan real-time: browser cuma minta ini tiap beberapa detik)
        echo json_encode(['sig' => md5(json_encode($rows)), 'count' => count($rows)]);
        exit;
    }
    echo json_encode($rows);
    exit;
}

if ($method === 'POST') {
    $body = json_input();
    $resi = trim($body['resi'] ?? '');
    $kurir = trim($body['kurir'] ?? '') ?: 'LEX';
    $catatan = trim($body['catatan'] ?? '');

    if ($resi === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Nomor resi kosong']);
        exit;
    }

    $scope = resolve_scope($body['cabang_id'] ?? null);
    if ($scope['all']) {
        http_response_code(400);
        echo json_encode(['error' => 'Mode gabungan semua cabang cuma buat lihat & export — pilih 1 cabang dulu buat nambah resi']);
        exit;
    }

    if (!$scope['cabang_id']) {
        http_response_code(400);
        echo json_encode(['error' => 'Belum ada cabang — buat cabang dulu lewat menu "Kelola Cabang & User"']);
        exit;
    }

    // waktu scan diisi dari PHP (zona waktu aplikasi) supaya "hari ini" di browser & database selalu sama
    $stmt = $pdo->prepare("INSERT INTO resi (resi, kurir, cabang_id, user_id, catatan, waktu_scan) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$resi, $kurir, $scope['cabang_id'], $_SESSION['user_id'], $catatan, date('Y-m-d H:i:s')]);
    $id = $pdo->lastInsertId();

    // kalau resi ini ada di pesanan Shopee yang sudah ditarik, isi detailnya otomatis
    $order = find_order_by_resi($pdo, $resi);
    if ($order) apply_order_to_resi($pdo, (int)$id, $order);

    $stmt = $pdo->prepare("SELECT r.*, c.nama AS cabang_nama, u.username AS user_nama FROM resi r JOIN cabang c ON c.id = r.cabang_id LEFT JOIN users u ON u.id = r.user_id WHERE r.id = ?");
    $stmt->execute([$id]);
    echo json_encode(row_out($stmt->fetch()));
    exit;
}

if ($method === 'PUT') {
    $body = json_input();
    $id = (int)($body['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'ID wajib diisi']); exit; }

    $stmt = $pdo->prepare("SELECT * FROM resi WHERE id = ?");
    $stmt->execute([$id]);
    $existing = $stmt->fetch();
    if (!$existing) { http_response_code(404); echo json_encode(['error' => 'Resi tidak ditemukan']); exit; }

    if (($_SESSION['role'] ?? '') !== 'admin' && (int)$existing['cabang_id'] !== (int)$_SESSION['cabang_id']) {
        http_response_code(403);
        echo json_encode(['error' => 'Tidak boleh mengubah data cabang lain']);
        exit;
    }

    $map = [
        'kurir' => 'kurir',
        'catatan' => 'catatan',
        'namaToko' => 'nama_toko',
        'namaPenerima' => 'nama_penerima',
        'alamat' => 'alamat',
        'namaBarang' => 'nama_barang',
        'qty' => 'qty',
        'tanggalKirim' => 'tanggal_kirim',
    ];
    $sets = [];
    $params = [];
    foreach ($map as $jsKey => $col) {
        if (array_key_exists($jsKey, $body)) {
            $sets[] = "$col = ?";
            $params[] = trim((string)$body[$jsKey]);
        }
    }
    if (array_key_exists('matched', $body)) {
        $sets[] = "matched = ?";
        $params[] = $body['matched'] ? 1 : 0;
    }
    if ($sets) {
        $params[] = $id;
        $pdo->prepare("UPDATE resi SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
    }

    $stmt = $pdo->prepare("SELECT r.*, c.nama AS cabang_nama, u.username AS user_nama FROM resi r JOIN cabang c ON c.id = r.cabang_id LEFT JOIN users u ON u.id = r.user_id WHERE r.id = ?");
    $stmt->execute([$id]);
    echo json_encode(row_out($stmt->fetch()));
    exit;
}

if ($method === 'DELETE') {
    $body = json_input();

    // ---- hapus semua resi pada 1 tanggal + 1 cabang ("Hapus semua data") ----
    if (!empty($body['all'])) {
        $date = $body['date'] ?? '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            http_response_code(400); echo json_encode(['error' => 'Tanggal tidak valid']); exit;
        }
        $scope = resolve_scope($body['cabang_id'] ?? null);
        if ($scope['all']) {
            http_response_code(400);
            echo json_encode(['error' => 'Pilih 1 cabang dulu untuk hapus semua data']);
            exit;
        }
        $stmt = $pdo->prepare("DELETE FROM resi WHERE DATE(waktu_scan) = ? AND cabang_id = ?");
        $stmt->execute([$date, $scope['cabang_id']]);
        echo json_encode(['message' => 'Semua data tanggal ini dihapus', 'deleted' => $stmt->rowCount()]);
        exit;
    }

    // ---- hapus 1 atau beberapa id sekaligus ----
    $ids = [];
    if (!empty($body['ids']) && is_array($body['ids'])) {
        $ids = array_map('intval', $body['ids']);
    } elseif (!empty($body['id'])) {
        $ids = [(int)$body['id']];
    }
    if (!$ids) { http_response_code(400); echo json_encode(['error' => 'ID wajib diisi']); exit; }

    if (($_SESSION['role'] ?? '') !== 'admin') {
        // staf cuma boleh hapus resi milik cabangnya sendiri
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $check = $pdo->prepare("SELECT COUNT(*) c FROM resi WHERE id IN ($placeholders) AND cabang_id <> ?");
        $check->execute([...$ids, $_SESSION['cabang_id']]);
        if ($check->fetch()['c'] > 0) {
            http_response_code(403);
            echo json_encode(['error' => 'Tidak boleh menghapus data cabang lain']);
            exit;
        }
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("DELETE FROM resi WHERE id IN ($placeholders)");
    $stmt->execute($ids);
    echo json_encode(['message' => 'Resi dihapus', 'deleted' => $stmt->rowCount()]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method tidak didukung']);
