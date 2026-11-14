<?php
require __DIR__ . '/db.php';

$method = $_SERVER['REQUEST_METHOD'];

// GET: daftar user — admin saja
if ($method === 'GET') {
    require_admin();
    $rows = $pdo->query("
        SELECT u.id, u.username, u.role, u.cabang_id, c.nama AS cabang_nama
        FROM users u
        LEFT JOIN cabang c ON c.id = u.cabang_id
        ORDER BY u.role ASC, c.nama ASC, u.username ASC
    ")->fetchAll();
    echo json_encode($rows);
    exit;
}

// POST: tambah user baru — admin saja
if ($method === 'POST') {
    require_admin();
    $body = json_input();
    $username = trim($body['username'] ?? '');
    $password = $body['password'] ?? '';
    $role = ($body['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
    $cabang_id = $role === 'admin' ? null : (int)($body['cabang_id'] ?? 0);

    if ($username === '' || $password === '') {
        http_response_code(400); echo json_encode(['error' => 'Username & password wajib diisi']); exit;
    }
    if ($role === 'user' && !$cabang_id) {
        http_response_code(400); echo json_encode(['error' => 'Pilih cabang untuk user staf']); exit;
    }

    $dup = $pdo->prepare("SELECT id FROM users WHERE LOWER(username) = LOWER(?)");
    $dup->execute([$username]);
    if ($dup->fetch()) { http_response_code(409); echo json_encode(['error' => 'Username sudah dipakai']); exit; }

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $pdo->prepare("INSERT INTO users (username, password, role, cabang_id) VALUES (?, ?, ?, ?)");
    $stmt->execute([$username, $hash, $role, $cabang_id ?: null]);
    echo json_encode(['id' => $pdo->lastInsertId(), 'username' => $username, 'role' => $role]);
    exit;
}

// PUT: ubah user (username, password opsional, role, cabang) — admin saja
if ($method === 'PUT') {
    require_admin();
    $body = json_input();
    $id = (int)($body['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'ID wajib diisi']); exit; }

    $st = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $st->execute([$id]);
    $cur = $st->fetch();
    if (!$cur) { http_response_code(404); echo json_encode(['error' => 'User tidak ditemukan']); exit; }

    $username = trim($body['username'] ?? $cur['username']);
    $role = ($body['role'] ?? $cur['role']) === 'admin' ? 'admin' : 'user';
    $cabang_id = $role === 'admin' ? null : (int)($body['cabang_id'] ?? $cur['cabang_id'] ?? 0);
    $password = (string)($body['password'] ?? '');

    if ($username === '') { http_response_code(400); echo json_encode(['error' => 'Username wajib diisi']); exit; }
    if ($role === 'user' && !$cabang_id) { http_response_code(400); echo json_encode(['error' => 'Pilih cabang untuk user staf']); exit; }
    if ($role === 'user' && $cabang_id) {
        $c = $pdo->prepare("SELECT id FROM cabang WHERE id = ?");
        $c->execute([$cabang_id]);
        if (!$c->fetch()) { http_response_code(400); echo json_encode(['error' => 'Cabang tidak ditemukan']); exit; }
    }
    // admin tidak boleh menurunkan role akunnya sendiri (supaya tidak terkunci di luar)
    if ($id === (int)$_SESSION['user_id'] && $role !== 'admin') {
        http_response_code(400); echo json_encode(['error' => 'Tidak bisa mengubah role akun sendiri dari Admin']); exit;
    }
    // minimal harus ada 1 admin
    if ($cur['role'] === 'admin' && $role !== 'admin') {
        $n = $pdo->query("SELECT COUNT(*) c FROM users WHERE role = 'admin'")->fetch()['c'];
        if ((int)$n <= 1) { http_response_code(400); echo json_encode(['error' => 'Harus tersisa minimal 1 admin']); exit; }
    }
    $dup = $pdo->prepare("SELECT id FROM users WHERE LOWER(username) = LOWER(?) AND id <> ?");
    $dup->execute([$username, $id]);
    if ($dup->fetch()) { http_response_code(409); echo json_encode(['error' => 'Username sudah dipakai']); exit; }

    if ($password !== '') {
        $pdo->prepare("UPDATE users SET username = ?, password = ?, role = ?, cabang_id = ? WHERE id = ?")
            ->execute([$username, password_hash($password, PASSWORD_BCRYPT), $role, $cabang_id, $id]);
    } else {
        $pdo->prepare("UPDATE users SET username = ?, role = ?, cabang_id = ? WHERE id = ?")
            ->execute([$username, $role, $cabang_id, $id]);
    }
    echo json_encode(['message' => 'User diperbarui', 'id' => $id, 'username' => $username, 'role' => $role]);
    exit;
}

// DELETE: hapus user — admin saja, tidak boleh hapus akun sendiri
if ($method === 'DELETE') {
    require_admin();
    $body = json_input();
    $id = (int)($body['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'ID wajib diisi']); exit; }
    if ($id === (int)$_SESSION['user_id']) {
        http_response_code(400); echo json_encode(['error' => 'Tidak bisa hapus akun sendiri']); exit;
    }

    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
    $stmt->execute([$id]);
    echo json_encode(['message' => 'User dihapus']);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method tidak didukung']);
