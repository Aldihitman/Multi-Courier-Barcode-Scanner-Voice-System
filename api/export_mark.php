<?php
// Menandai resi yang baru saja diekspor ke Excel dengan nomor batch (ekspor ke-N pada tanggal itu).
require __DIR__ . '/db.php';
require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Method tidak didukung']); exit; }

$body = json_input();
$ids = array_values(array_unique(array_filter(array_map('intval', (array)($body['ids'] ?? [])))));
$batch = (int)($body['batch'] ?? 0);
if (!$ids || $batch < 1) { echo json_encode(['marked' => 0]); exit; }

$isAdmin = ($_SESSION['role'] ?? '') === 'admin';
$marked = 0;
foreach (array_chunk($ids, 200) as $chunk) {
    $in = implode(',', array_fill(0, count($chunk), '?'));
    $sql = "UPDATE resi SET export_batch = ? WHERE export_batch IS NULL AND id IN ($in)";
    $params = array_merge([$batch], $chunk);
    if (!$isAdmin) { $sql .= " AND cabang_id = ?"; $params[] = (int)$_SESSION['cabang_id']; }
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $marked += $st->rowCount();
}
echo json_encode(['marked' => $marked, 'batch' => $batch]);
