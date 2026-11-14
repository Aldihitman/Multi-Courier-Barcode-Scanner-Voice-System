<?php
require __DIR__ . '/db.php';
$_SESSION = [];
session_destroy();
echo json_encode(['message' => 'Logout berhasil']);
