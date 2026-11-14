<?php
require __DIR__ . '/db.php';

$body = json_input();
$username = trim($body['username'] ?? '');
$password = $body['password'] ?? '';

if ($username === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Username & password wajib diisi']);
    exit;
}

$stmt = $pdo->prepare("
    SELECT u.id, u.username, u.password, u.role, u.cabang_id, c.nama AS cabang_nama
    FROM users u
    LEFT JOIN cabang c ON c.id = u.cabang_id
    WHERE u.username = ?
    LIMIT 1
");
$stmt->execute([$username]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user['password'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Username atau password salah']);
    exit;
}

if ($user['role'] !== 'admin' && !$user['cabang_id']) {
    http_response_code(403);
    echo json_encode(['error' => 'Akun ini belum punya cabang, hubungi admin']);
    exit;
}

$_SESSION['user_id'] = $user['id'];
$_SESSION['role'] = $user['role'];
$_SESSION['cabang_id'] = $user['cabang_id'];

echo json_encode([
    'id' => $user['id'],
    'username' => $user['username'],
    'role' => $user['role'],
    'cabang_id' => $user['cabang_id'],
    'cabang_nama' => $user['cabang_nama'],
]);
