<?php
require __DIR__ . '/db.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Belum login']);
    exit;
}

$stmt = $pdo->prepare("
    SELECT u.id, u.username, u.role, u.cabang_id, c.nama AS cabang_nama
    FROM users u
    LEFT JOIN cabang c ON c.id = u.cabang_id
    WHERE u.id = ?
");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Sesi tidak valid']);
    exit;
}

echo json_encode($user);
