<?php
// ============================================================
// Koneksi database & fungsi umum. Pengaturan ada di ../config.php
// (kalau file itu belum ada, dipakai default Laragon).
// ============================================================
$APP_CONFIG = is_file(__DIR__ . '/../config.php') ? (require __DIR__ . '/../config.php') : [];
function app_cfg($key, $default = null) {
    global $APP_CONFIG;
    return array_key_exists($key, $APP_CONFIG) ? $APP_CONFIG[$key] : $default;
}

date_default_timezone_set(app_cfg('timezone', 'Asia/Jakarta'));

$DB_DRIVER = app_cfg('db_driver', 'mysql');
try {
    if ($DB_DRIVER === 'sqlite') {
        $path = app_cfg('sqlite_path', __DIR__ . '/../storage/app.sqlite');
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
    } else {
        $pdo = new PDO(
            'mysql:host=' . app_cfg('db_host', '127.0.0.1') . ';dbname=' . app_cfg('db_name', 'resi_rj_bike') . ';charset=utf8mb4',
            app_cfg('db_user', 'root'),
            app_cfg('db_pass', ''),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    }
} catch (PDOException $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Koneksi database gagal: ' . $e->getMessage()]);
    exit;
}

session_start();
header('Content-Type: application/json');

function json_input() {
    $raw = file_get_contents('php://input');
    return json_decode($raw, true) ?: [];
}

function require_login() {
    global $pdo;
    if (empty($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Belum login']);
        exit;
    }
    // ambil role & cabang terbaru dari database: perubahan oleh admin (edit user) langsung berlaku,
    // dan akun yang sudah dihapus otomatis keluar
    $st = $pdo->prepare("SELECT role, cabang_id FROM users WHERE id = ?");
    $st->execute([$_SESSION['user_id']]);
    $u = $st->fetch();
    if (!$u) {
        $_SESSION = [];
        http_response_code(401);
        echo json_encode(['error' => 'Sesi tidak valid']);
        exit;
    }
    $_SESSION['role'] = $u['role'];
    $_SESSION['cabang_id'] = $u['cabang_id'];
}

function require_admin() {
    require_login();
    if (($_SESSION['role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Hanya admin yang boleh melakukan ini']);
        exit;
    }
}

/**
 * Pastikan tabel/kolom tambahan (fitur Shopee) ada. Aman dijalankan berulang kali,
 * jadi database lama tidak perlu di-import ulang.
 */
function ensure_schema(PDO $pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    global $DB_DRIVER;
    $sqlite = ($DB_DRIVER === 'sqlite');

    if ($sqlite) {
        // hanya untuk uji coba / tanpa MySQL: buat tabel dasar kalau belum ada
        $pdo->exec("CREATE TABLE IF NOT EXISTS cabang (id INTEGER PRIMARY KEY AUTOINCREMENT, nama TEXT NOT NULL UNIQUE, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT NOT NULL UNIQUE, password TEXT NOT NULL, role TEXT NOT NULL DEFAULT 'user', cabang_id INTEGER NULL REFERENCES cabang(id) ON DELETE SET NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS resi (id INTEGER PRIMARY KEY AUTOINCREMENT, resi TEXT NOT NULL, kurir TEXT NOT NULL, cabang_id INTEGER NOT NULL REFERENCES cabang(id) ON DELETE CASCADE, user_id INTEGER NULL REFERENCES users(id) ON DELETE SET NULL, catatan TEXT DEFAULT '', nama_toko TEXT DEFAULT '', nama_penerima TEXT DEFAULT '', alamat TEXT, nama_barang TEXT DEFAULT '', qty TEXT DEFAULT '', tanggal_kirim TEXT DEFAULT '', matched INTEGER DEFAULT 0, waktu_scan TEXT DEFAULT CURRENT_TIMESTAMP)");
    }

    // kolom nomor pesanan di tabel resi
    $has = false;
    if ($sqlite) {
        foreach ($pdo->query("PRAGMA table_info(resi)")->fetchAll() as $c) if ($c['name'] === 'order_sn') $has = true;
    } else {
        $has = (bool)$pdo->query("SHOW COLUMNS FROM resi LIKE 'order_sn'")->fetch();
    }
    if (!$has) $pdo->exec("ALTER TABLE resi ADD COLUMN order_sn VARCHAR(60) DEFAULT ''");

    // batch ekspor Excel (NULL = belum pernah diekspor)
    $has = false;
    if ($sqlite) {
        foreach ($pdo->query("PRAGMA table_info(resi)")->fetchAll() as $c) if ($c['name'] === 'export_batch') $has = true;
    } else {
        $has = (bool)$pdo->query("SHOW COLUMNS FROM resi LIKE 'export_batch'")->fetch();
    }
    if (!$has) $pdo->exec("ALTER TABLE resi ADD COLUMN export_batch INT NULL");

    // tabel pesanan hasil tarik dari Shopee
    if ($sqlite) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS orders (id INTEGER PRIMARY KEY AUTOINCREMENT, order_sn TEXT NOT NULL UNIQUE, platform TEXT DEFAULT 'shopee', resi TEXT DEFAULT '', kurir TEXT DEFAULT '', status TEXT DEFAULT '', nama_toko TEXT DEFAULT '', nama_penerima TEXT DEFAULT '', no_hp TEXT DEFAULT '', alamat TEXT, nama_barang TEXT, qty INTEGER DEFAULT 0, batas_kirim TEXT DEFAULT '', tanggal_pesan TEXT DEFAULT '', total TEXT DEFAULT '', username_pembeli TEXT DEFAULT '', catatan TEXT, create_time INTEGER DEFAULT 0, synced_at TEXT)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_orders_resi ON orders(resi)");
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS orders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_sn VARCHAR(60) NOT NULL UNIQUE,
            platform VARCHAR(20) DEFAULT 'shopee',
            resi VARCHAR(100) DEFAULT '',
            kurir VARCHAR(100) DEFAULT '',
            status VARCHAR(40) DEFAULT '',
            nama_toko VARCHAR(150) DEFAULT '',
            nama_penerima VARCHAR(150) DEFAULT '',
            no_hp VARCHAR(40) DEFAULT '',
            alamat TEXT,
            nama_barang TEXT,
            qty INT DEFAULT 0,
            batas_kirim VARCHAR(30) DEFAULT '',
            tanggal_pesan VARCHAR(30) DEFAULT '',
            total VARCHAR(30) DEFAULT '',
            username_pembeli VARCHAR(100) DEFAULT '',
            catatan TEXT,
            create_time BIGINT DEFAULT 0,
            synced_at DATETIME NULL,
            INDEX idx_orders_resi (resi)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    // database lama: tambah kolom platform (shopee / lazada / tiktok)
    $hasPlat = false;
    if ($sqlite) {
        foreach ($pdo->query("PRAGMA table_info(orders)")->fetchAll() as $c) if ($c['name'] === 'platform') $hasPlat = true;
    } else {
        $hasPlat = (bool)$pdo->query("SHOW COLUMNS FROM orders LIKE 'platform'")->fetch();
    }
    if (!$hasPlat) $pdo->exec("ALTER TABLE orders ADD COLUMN platform VARCHAR(20) DEFAULT 'shopee'");

    // kolom SKU di pesanan & resi
    foreach (['orders', 'resi'] as $tbl) {
        $has = false;
        if ($sqlite) {
            foreach ($pdo->query("PRAGMA table_info($tbl)")->fetchAll() as $c) if ($c['name'] === 'sku') $has = true;
        } else {
            $has = (bool)$pdo->query("SHOW COLUMNS FROM $tbl LIKE 'sku'")->fetch();
        }
        if (!$has) $pdo->exec("ALTER TABLE $tbl ADD COLUMN sku TEXT");
    }

    // kolom subtotal pesanan (nilai barang, sebelum ongkir/biaya) di pesanan & resi
    foreach (['orders', 'resi'] as $tbl) {
        $has = false;
        if ($sqlite) {
            foreach ($pdo->query("PRAGMA table_info($tbl)")->fetchAll() as $c) if ($c['name'] === 'subtotal') $has = true;
        } else {
            $has = (bool)$pdo->query("SHOW COLUMNS FROM $tbl LIKE 'subtotal'")->fetch();
        }
        if (!$has) $pdo->exec("ALTER TABLE $tbl ADD COLUMN subtotal BIGINT NULL");
        // rincian per item (JSON: nama, varian, sku, qty, harga, subtotal)
        $has = false;
        if ($sqlite) {
            foreach ($pdo->query("PRAGMA table_info($tbl)")->fetchAll() as $c) if ($c['name'] === 'items_json') $has = true;
        } else {
            $has = (bool)$pdo->query("SHOW COLUMNS FROM $tbl LIKE 'items_json'")->fetch();
        }
        if (!$has) $pdo->exec("ALTER TABLE $tbl ADD COLUMN items_json TEXT NULL");
    }

    // kolom nama_barang di database lama hanya VARCHAR(150): terlalu pendek untuk pesanan dengan banyak barang
    if (!$sqlite) {
        $col = $pdo->query("SHOW COLUMNS FROM resi LIKE 'nama_barang'")->fetch();
        if ($col && stripos((string)$col['Type'], 'varchar') === 0) {
            $pdo->exec("ALTER TABLE resi MODIFY nama_barang TEXT");
        }
    }
}
