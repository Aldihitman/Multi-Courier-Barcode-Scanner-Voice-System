-- ============================================================
-- Database: Scan Resi RJ BIKE — Admin & User per Cabang
-- Cara pakai di Laragon:
--   1. Buka Laragon > klik "Database" (HeidiSQL) ATAU buka phpMyAdmin
--   2. Import file ini (atau copy-paste lalu Execute)
-- ============================================================

CREATE DATABASE IF NOT EXISTS resi_rj_bike
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE resi_rj_bike;

-- ---------- CABANG ----------
CREATE TABLE IF NOT EXISTS cabang (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nama VARCHAR(100) NOT NULL UNIQUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------- USERS (admin & staf per cabang) ----------
CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,          -- disimpan pakai password_hash() PHP (bcrypt), BUKAN plain text
  role ENUM('admin','user') NOT NULL DEFAULT 'user',
  cabang_id INT NULL,                      -- NULL untuk admin (admin lihat semua cabang)
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_users_cabang FOREIGN KEY (cabang_id) REFERENCES cabang(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------- RESI (manifest hasil scan, tiap baris tertaut ke user & cabang) ----------
CREATE TABLE IF NOT EXISTS resi (
  id INT AUTO_INCREMENT PRIMARY KEY,
  resi VARCHAR(100) NOT NULL,
  kurir VARCHAR(100) NOT NULL,
  cabang_id INT NOT NULL,
  user_id INT NULL,                        -- siapa yang scan
  catatan VARCHAR(255) DEFAULT '',
  nama_toko VARCHAR(150) DEFAULT '',
  nama_penerima VARCHAR(150) DEFAULT '',
  alamat TEXT,
  nama_barang VARCHAR(150) DEFAULT '',
  qty VARCHAR(20) DEFAULT '',
  tanggal_kirim VARCHAR(30) DEFAULT '',
  order_sn VARCHAR(60) DEFAULT '',         -- nomor pesanan Shopee (terisi otomatis kalau resi cocok)
  matched TINYINT(1) DEFAULT 0,
  waktu_scan TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_resi_cabang FOREIGN KEY (cabang_id) REFERENCES cabang(id) ON DELETE CASCADE,
  CONSTRAINT fk_resi_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_resi_lookup (resi),
  INDEX idx_resi_tanggal (waktu_scan)
) ENGINE=InnoDB;

-- ---------- KODE UNIK KURIR (opsional, untuk auto-deteksi) ----------
CREATE TABLE IF NOT EXISTS courier_prefixes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  kurir VARCHAR(100) NOT NULL UNIQUE,
  prefixes VARCHAR(255) DEFAULT ''         -- disimpan koma-pisah, mis. "110,100"
) ENGINE=InnoDB;

-- ---------- PESANAN SHOPEE (hasil tarik dari Shopee Open Platform) ----------
-- Tabel ini juga dibuat otomatis oleh aplikasi kalau belum ada, jadi aman walau skip bagian ini.
CREATE TABLE IF NOT EXISTS orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_sn VARCHAR(60) NOT NULL UNIQUE,
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Catatan: TIDAK ada INSERT admin default di sini karena password harus
-- di-hash pakai PHP (password_hash), bukan ditulis manual di SQL.
-- Jalankan api/setup.php sekali lewat browser untuk bikin akun admin default.
