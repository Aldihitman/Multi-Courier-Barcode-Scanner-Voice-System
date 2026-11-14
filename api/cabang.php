<?php
require __DIR__ . '/db.php';

$method = $_SERVER['REQUEST_METHOD'];

// GET: semua user login boleh lihat daftar cabang (dipakai buat dropdown)
if ($method === 'GET') {
    require_login();
    $rows = $pdo->query("SELECT id, nama FROM cabang ORDER BY nama ASC")->fetchAll();
    echo json_encode($rows);
    exit;
}

// POST: tambah cabang baru — admin saja
if ($method === 'POST') {
    require_admin();
    $body = json_input();
    $nama = trim($body['nama'] ?? '');
    if ($nama === '') { http_response_code(400); echo json_encode(['error' => 'Nama cabang wajib diisi']); exit; }

    $dup = $pdo->prepare("SELECT id FROM cabang WHERE LOWER(nama) = LOWER(?)");
    $dup->execute([$nama]);
    if ($dup->fetch()) { http_response_code(409); echo json_encode(['error' => 'Cabang ini sudah ada']); exit; }

    $stmt = $pdo->prepare("INSERT INTO cabang (nama) VALUES (?)");
    $stmt->execute([$nama]);
    echo json_encode(['id' => $pdo->lastInsertId(), 'nama' => $nama]);
    exit;
}

// PUT: ganti nama cabang — admin saja
if ($method === 'PUT') {
    require_admin();
    $body = json_input();
    $id = (int)($body['id'] ?? 0);
    $nama = trim($body['nama'] ?? '');
    if (!$id || $nama === '') { http_response_code(400); echo json_encode(['error' => 'Data tidak lengkap']); exit; }

    $stmt = $pdo->prepare("UPDATE cabang SET nama = ? WHERE id = ?");
    $stmt->execute([$nama, $id]);
    echo json_encode(['message' => 'Cabang diperbarui']);
    exit;
}

// DELETE: hapus cabang — admin saja, ditolak kalau masih dipakai user
if ($method === 'DELETE') {
    require_admin();
    $body = json_input();
    $id = (int)($body['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'ID wajib diisi']); exit; }

    $inUse = $pdo->prepare("SELECT COUNT(*) c FROM users WHERE cabang_id = ?");
    $inUse->execute([$id]);
    if ($inUse->fetch()['c'] > 0) {
        http_response_code(409);
        echo json_encode(['error' => 'Cabang masih dipakai oleh user, tidak bisa dihapus']);
        exit;
    }

    // cabang_id di tabel resi pakai ON DELETE CASCADE — cegah supaya history scan
    // tidak ikut kehapus otomatis kalau cabang ini masih punya data resi.
    $inUseResi = $pdo->prepare("SELECT COUNT(*) c FROM resi WHERE cabang_id = ?");
    $inUseResi->execute([$id]);
    if ($inUseResi->fetch()['c'] > 0) {
        http_response_code(409);
        echo json_encode(['error' => 'Cabang masih punya data resi, tidak bisa dihapus']);
        exit;
    }

    $stmt = $pdo->prepare("DELETE FROM cabang WHERE id = ?");
    $stmt->execute([$id]);
    echo json_encode(['message' => 'Cabang dihapus']);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method tidak didukung']);
