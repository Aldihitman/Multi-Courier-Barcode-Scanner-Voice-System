# Scan Resi RJ BIKE

Aplikasi web untuk **scan resi paket** (barcode/kamera/ketik), membuat **manifest digital**, dan **mengisi detail pesanan otomatis** dari Shopee, Lazada, dan TikTok Shop / Tokopedia. Dibuat untuk penjual online yang mengirim banyak paket per hari dan butuh bukti serah-terima ke kurir yang rapi.

> Dibangun dengan PHP + MySQL (cocok dijalankan di [Laragon](https://laragon.org)) dan satu halaman `index.html` tanpa framework.

## Fitur

**Scan & manifest**
- Scan resi lewat scanner barcode, kamera HP (BarcodeDetector + fallback ZXing), atau ketik manual.
- Kurir terdeteksi otomatis dari prefix resi, dengan suara nama kurir saat scan. Suara bisa diganti dengan upload MP3 per kurir.
- **Tiga tahap scan** untuk satu resi:
  1. Scan pertama: normal (suara kurir).
  2. Scan kedua: suara "Siap kirim, <kurir>".
  3. Scan ketiga dan seterusnya: "Sudah scan".
  Setiap tahap bisa memakai suara MP3 sendiri.
- Daftar manifest dengan filter (kurir, tahap, batch, cabang), pencarian, dan **paging** (10/25/50/100 baris per halaman).
- Tabel **update real-time** antar perangkat (tanpa refresh).
- Kolom "Discan oleh" (siapa yang scan).

**Multi cabang & user**
- Login dengan peran **admin** (lihat semua cabang) dan **user** (staf, terkunci di 1 cabang).
- Kelola cabang dan user dari menu admin, termasuk **edit user** (username, password, role, cabang).

**Integrasi marketplace**
- **Shopee Open Platform v2**, **Lazada Open Platform**, dan **TikTok Shop** (Tokopedia sudah tergabung).
- Pesanan ditarik otomatis (sekitar tiap 1 menit) dan saat resi tidak ditemukan. Setelah itu, scan resi langsung mengisi: nomor pesanan, nama toko, penerima, alamat, barang, SKU, jumlah, tanggal kirim, dan subtotal.
- **Subtotal = harga posting** (harga listing sebelum diskon) dikali jumlah, dirinci **per item/varian** untuk pesanan yang berisi banyak barang.

**Ekspor Excel**
- Sheet per tahap scan, Manifest Resi, dan **Detail Pesanan** (satu blok per resi, varian dirinci per baris, subtotal digabung).
- **Batch ekspor**: resi yang sudah diekspor ditandai Batch 1, 2, dst.; resi baru berstatus "Baru" sehingga tidak tercampur dengan yang lama.
- Garis tepi (border) dan header berwarna.

## Teknologi

| Bagian | Yang dipakai |
|---|---|
| Backend | PHP 8+ (PDO, session), MySQL/MariaDB (SQLite hanya untuk uji coba) |
| Frontend | Satu file `index.html` + `style.css` (tanpa build) |
| Excel | [xlsx-js-style](https://github.com/gitbrent/xlsx-js-style) (SheetJS) via CDN |
| Kamera | BarcodeDetector API, [ZXing](https://github.com/zxing-js/library) sebagai cadangan |
| Server lokal | Laragon (Apache/Nginx + PHP + MySQL) |

## Persyaratan

- PHP 8.0+ dengan ekstensi `pdo_mysql`, `curl`, `zip`, `json`, `mbstring`
- MySQL 5.7+ / MariaDB 10.3+
- Koneksi internet (pustaka Excel & ZXing dimuat dari CDN, dan untuk API marketplace)
- **HTTPS** bila ingin scan lewat kamera HP (browser mewajibkannya; `localhost` di HP berarti HP itu sendiri). Lihat [Kamera HP](#kamera-hp).

## Instalasi (Laragon)

1. Salin proyek ke `C:\laragon\www\Scanner\`.
2. Jalankan Laragon → **Start All**. Pastikan ekstensi PHP `curl` dan `zip` aktif.
3. Buat database dan impor skema:
   ```
   database/schema.sql
   ```
   (Untuk database lama tidak perlu impor ulang; kolom/tabel baru dibuat otomatis saat aplikasi dibuka.)
4. Salin pengaturan:
   ```
   config.example.php  →  config.php
   ```
   Isi data database (bawaan Laragon biasanya sudah benar). Bagian Shopee/Lazada/TikTok boleh diisi belakangan.
5. Buka sekali `http://localhost/Scanner/api/setup.php`. Ini membuat akun awal `admin` / `admin123`. **Setelah itu hapus `api/setup.php` dan segera ganti password admin.**
6. Buka `http://localhost/Scanner/`, login, lalu **Kelola Cabang & User** → buat cabang pertama. Tanpa cabang, kolom input resi terkunci.

## Konfigurasi (`config.php`)

```php
return [
    'db_host' => '127.0.0.1',
    'db_name' => 'resi_rj_bike',
    'db_user' => 'root',
    'db_pass' => '',
    'timezone' => 'Asia/Jakarta',

    // Shopee
    'partner_id'  => 0,
    'partner_key' => '',
    'env'         => 'sandbox',            // 'sandbox' atau 'live'
    'redirect_url'=> 'https://example.com/',

    // Lazada
    'lazada' => ['app_key' => '', 'app_secret' => '', 'redirect_url' => '', 'country' => 'id'],

    // TikTok Shop / Tokopedia
    'tiktok' => ['app_key' => '', 'app_secret' => '', 'auth_url' => '', 'service_id' => ''],
];
```

Semua pengaturan ada di satu file ini. `config.php` **tidak ikut Git** (lihat `.gitignore`).

## Menyambungkan marketplace

Buka menu **Marketplace** (khusus admin), pilih tab platform, lalu ikuti urutannya: **Hubungkan** → login akun toko → setujui izin. Setelah terhubung, tarik otomatis berjalan sendiri.

### Shopee
1. Daftar di [Shopee Open Platform](https://open.shopee.com), buat app, dan isi `partner_id` serta `partner_key`.
2. `redirect_url` harus **sama persis** dengan Redirect URL di Console.
3. Klik Hubungkan, setujui, lalu tempel alamat hasil redirect ke aplikasi.

### Lazada
1. Buat app jenis **Seller In-house APP** di Lazada Open Platform (Service Provider Center) dan isi Callback URL.
2. Daftarkan **Seller Short Code** toko di *App Management → Auth Management* (kalau tidak, otorisasi ditolak).
3. Isi `app_key`, `app_secret`, `redirect_url` di `config.php`, lalu Hubungkan.

### TikTok Shop / Tokopedia
1. Buat **Custom App** di [TikTok Shop Partner Center](https://partner.tiktokshop.com), aktifkan scope **Order**, **Fulfillment**, dan **Shop Authorized Information**, lalu **Publish changes**.
2. Isi `app_key`, `app_secret`, dan `auth_url` (atau `service_id`). Redirect URL di Partner Center harus sama dengan di `config.php`.
3. Hubungkan. Kalau scope diubah setelahnya, **Putuskan lalu Hubungkan ulang**.

> Catatan: nomor resi baru ada setelah pesanan diatur pengirimannya (Atur Pengiriman / packing) di Seller Center masing-masing. Pesanan yang belum diatur tetap masuk tanpa resi dan terisi di tarikan berikutnya.

> Integrasi Lazada dan TikTok awalnya diuji dengan server tiruan; nama field bisa berbeda di akun nyata. Bila ada error, buka issue dengan pesan errornya.

## Kamera HP

Browser hanya mengizinkan kamera pada koneksi aman. Pilihan paling mudah untuk memakai kamera HP ke Laragon di komputer Anda:

- **Cloudflare Tunnel** (`cloudflared tunnel --url http://localhost`) untuk mendapat alamat HTTPS sementara, atau
- sertifikat HTTPS lokal dari Laragon (Menu → Apache → SSL).

Buka aplikasi dari HP lewat alamat HTTPS tersebut. Di iPhone pakai Safari.

## Struktur proyek

```
Scanner/
├── index.html            # seluruh antarmuka (login, scan, manifest, ekspor)
├── style.css
├── config.example.php    # contoh pengaturan (salin ke config.php)
├── api/
│   ├── login.php, logout.php, session.php, setup.php
│   ├── resi.php          # CRUD resi, daftar & tanda tangan real-time
│   ├── users.php, cabang.php
│   ├── export_mark.php   # penandaan batch ekspor
│   ├── shopee.php        # tarik pesanan, status, ekspor, autosync
│   ├── platform.php      # Lazada & TikTok (status/connect/sync)
│   └── lib/              # shopee, lazada, tiktok, sync, orders, platforms
├── database/schema.sql
├── sounds/               # suara kurir (MP3)
└── storage/              # token marketplace & status (tidak ikut Git)
```

## Keamanan

- `config.php` (kunci API, password DB) dan `storage/` (token marketplace) diblokir dari akses web lewat `.htaccess` dan dikecualikan dari Git. **Jangan pernah meng-commit keduanya.**
- Hapus `api/setup.php` setelah akun awal dibuat, dan ganti password bawaan.
- Jika kunci/secret pernah terlihat orang lain, buat ulang di console marketplace masing-masing.
- Bila aplikasi dibuka ke internet, wajib HTTPS dan batasi akses lewat firewall atau Cloudflare Access.

## Pemecahan masalah

| Gejala | Penyebab / solusi |
|---|---|
| Kolom input resi terkunci | Belum ada cabang. Buat cabang di Kelola Cabang & User. |
| `Data too long for column 'nama_barang'` | Buka aplikasi sekali; kolom dilebarkan otomatis. |
| Tarik otomatis `busy` | Proses sebelumnya masih berjalan. Tunggu satu menit atau cek `storage/autosync_last.json`. |
| TikTok `106001 sign invalid` | Cek `app_secret`, pastikan scope aktif dan sudah Publish, lalu Hubungkan ulang. |
| Lazada: "Seller short code is not specified" | Daftarkan Seller Short Code di Auth Management. |
| Kamera HP tidak mau jalan | Gunakan HTTPS (lihat bagian Kamera HP). |
| Tampilan tidak berubah setelah update | Tekan `Ctrl+F5`. |

Panduan langkah demi langkah versi Laragon ada di [`CARA_SETUP_LARAGON.md`](CARA_SETUP_LARAGON.md).

## Lisensi

[MIT](LICENSE) © 2026 Aldi Putra
