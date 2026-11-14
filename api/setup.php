<?php
// ============================================================
// JALANKAN SEKALI SAJA lewat browser:
//   http://localhost/resi-rj-bike/api/setup.php
// Ini bikin akun admin default (admin / admin123) kalau belum ada.
// Setelah berhasil login & ganti password admin, file ini boleh dihapus.
// ============================================================
require __DIR__ . '/db.php';
ensure_schema($pdo); // bikin tabel tambahan (pesanan Shopee) kalau belum ada

$check = $pdo->query("SELECT COUNT(*) c FROM users")->fetch();
if ($check['c'] > 0) {
    echo json_encode(['message' => 'Sudah ada user di database, setup dilewati. Hapus file ini untuk keamanan.']);
    exit;
}

$hash = password_hash('admin123', PASSWORD_BCRYPT);
$stmt = $pdo->prepare("INSERT INTO users (username, password, role, cabang_id) VALUES ('admin', ?, 'admin', NULL)");
$stmt->execute([$hash]);

echo json_encode([
    'message' => 'Akun admin default dibuat: username=admin, password=admin123. SEGERA login & ganti password, lalu hapus file setup.php ini.'
]);
