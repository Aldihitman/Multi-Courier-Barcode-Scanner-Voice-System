# Scan Resi RJ BIKE + Konektor Shopee (1 proyek)

## Pasang
1. Taruh seluruh folder ini di `C:\laragon\www\Scanner\` (timpa file lama; **jangan** hapus `config.php` kalau sudah kamu isi).
2. Laragon → Start All. Pastikan ekstensi PHP **curl** dan **zip** aktif (Menu → PHP → Extensions).
3. Database: import `database/schema.sql` (phpMyAdmin/HeidiSQL).
   Kalau database lama sudah ada, TIDAK perlu import ulang — tabel/kolom baru dibuat otomatis.
4. Buka `config.php`: database default Laragon sudah benar. Bagian Shopee diisi belakangan.
5. Buka sekali `http://localhost/Scanner/api/setup.php` → akun `admin` / `admin123`. Lalu hapus `api/setup.php`.
6. Buka `http://localhost/Scanner/`, login, klik **Kelola Cabang & User** → tambah cabang dulu (tanpa cabang kolom resi terkunci — itu penyebab "gak bisa input resi" sebelumnya; sekarang ada petunjuknya dan otomatis aktif setelah cabang dibuat).

## Shopee (menu 🛍 Shopee, khusus admin)
1. Isi `partner_id`, `partner_key` (dan `host`/`auth_base` sesuai sandbox/live) di `config.php`.
2. Klik 🛍 Shopee → buka halaman otorisasi → login & setujui → salin SELURUH alamat hasil redirect → tempel → Simpan otorisasi.
3. Klik **Tarik pesanan**. Pesanan masuk database. Saat resi discan, nama toko, penerima, alamat, barang, qty, tanggal kirim & nomor pesanan terisi otomatis (label "Otomatis"). Resi yang sudah discan sebelum tarik juga dilengkapi.
4. Unduh Excel scan: ada sheet baru **Detail Pesanan** (berisi nomor pesanan dkk). Tombol "Unduh Excel pesanan" mengekspor pesanan hasil tarik.

## Keamanan
- `config.php` (Partner Key, password DB) dan `storage/` (token Shopee) diblokir dari browser lewat `.htaccess`. Jangan upload ke GitHub (sudah ada `.gitignore`).
- Ganti password admin; ganti Partner Key kalau pernah terlihat orang lain.

## Lazada & TikTok Shop / Tokopedia (menu 🛍 Marketplace → tab Lazada / TikTok)
Tokopedia sekarang digabung ke TikTok Shop, jadi satu koneksi TikTok Shop mencakup keduanya.

1. Tambahkan blok `lazada` dan `tiktok` ke `config.php` kamu (lihat `config.example.php` sebagai contoh). Jangan timpa config lama; cukup tambah blok baru.
2. **Lazada:** isi `app_key`, `app_secret`, dan `redirect_url` (harus sama dengan Callback URL di app Lazada). Di menu, klik "Buka halaman otorisasi", login akun penjual, lalu tempel alamat hasil redirect (ada `code=...`).
3. **TikTok:** isi `app_key`, `app_secret`, dan `auth_url` (salin "Authorization link" dari Partner Center) atau `service_id`. Alur sama: otorisasi, lalu tempel alamat hasil redirect (ada `code=...`).
4. Klik **Tarik pesanan**. Selanjutnya tarik otomatis tiap 1 menit, sama seperti Shopee. Scan resi → nomor pesanan terisi sendiri.
5. Nomor resi baru ada setelah pesanan diatur pengirimannya / di-packing di Seller Center masing-masing.

Catatan: konektor Lazada dan TikTok diuji dengan server tiruan; belum dicoba ke akun asli (app belum disetujui).

## Suara scan
- Scan ke-1: seperti biasa (suara ekspedisi).
- Scan ke-2 resi yang sama: suara ucapan "Siap kirim, <ekspedisi>".
- Scan ke-3 dan seterusnya: suara ucapan "Sudah scan".
Tiap ekspedisi bisa diberi MP3 sendiri untuk scan ke-1, ke-2, dan ke-3 dst: klik "⚙ Kode Unik Kurir" lalu Upload MP3 di baris yang sesuai (maks 2MB). Tanpa upload, scan ke-2 dan ke-3 memakai suara ucapan otomatis. Suara tersimpan per perangkat/browser.
Suara ucapan memakai fitur suara bawaan browser (butuh voice bahasa Indonesia terpasang di perangkat; kalau tidak ada, dipakai suara bahasa lain atau bunyi biasa).

## Tahap scan di tabel & Excel
- Tabel Daftar Manifest punya kolom **Tahap**: Scan 1 / Siap kirim (scan ke-2) / Sudah scan (ke-3 dst), plus filter "Semua tahap" di samping filter kurir.
- Excel punya sheet: **Scan 1 - Ekspedisi**, **Scan 2 - Siap Kirim**, **Scan 3 - Sudah Scan** (masing-masing dikategorikan per ekspedisi), **Manifest Resi** (semua scan, format lama), dan **Detail Pesanan** (ada kolom Tahap).
- Tahap dihitung dari urutan scan resi yang sama pada tanggal & cabang yang sedang dibuka.

## Scan pakai kamera HP
Kamera di browser HP **hanya jalan lewat alamat HTTPS** (atau localhost di komputer yang sama). Kalau HP membuka `http://192.168.x.x/Scanner/`, semua browser memblokir kamera dan tombol kamera nonaktif dengan pesan penjelasan.
Cara membuat alamat HTTPS:
1. **Cloudflare Tunnel / ngrok (paling mudah):** jalankan tunnel ke `http://localhost:80`, lalu buka alamat `https://....` yang diberikan dari HP. Cocok untuk dipakai dari mana saja.
2. **SSL Laragon:** Menu Laragon → Apache → SSL → Enabled. Buka `https://192.168.x.x/Scanner/` dari HP di Wi-Fi yang sama, lalu setujui peringatan sertifikat (kadang perlu "Lanjutkan"/"Advanced").
3. Hosting dengan HTTPS (cPanel, VPS): otomatis aman.
Pembaca barcode: Chrome Android memakai pembaca bawaan, browser lain (iPhone/Safari, Firefox, dll) memakai pembaca cadangan ZXing yang dimuat dari internet, jadi HP butuh koneksi internet saat pertama membuka halaman.

## Real-time antar perangkat
Daftar manifest diperbarui otomatis tiap ±3 detik (scan dari HP lain, hapus, edit detail, hasil tarik pesanan). Tidak perlu refresh. Pembaruan berhenti sementara kalau tab tidak aktif atau saat jendela Detail Pesanan sedang dibuka.

## iPhone
Kamera di Safari iPhone hanya jalan lewat alamat HTTPS (lihat bagian "Scan pakai kamera HP", cara Cloudflare Tunnel). Setelah halaman terbuka, ketuk tombol kamera lalu pilih "Izinkan" kamera. Pembaca barcode memakai ZXing (butuh internet saat membuka halaman).

## Siapa yang scan
Kolom "Discan oleh" di Daftar Manifest menampilkan nama user yang scan, mis. `budi` (cabang tetap ada di kolom Cabang pada Excel). Ikut tampil di semua sheet Excel (kolom "Discan Oleh") dan bisa dicari lewat kotak pencarian. Resi yang discan sebelum pembaruan ini menampilkan nama cabang kalau akun yang scan sudah dihapus atau tidak tercatat.

## Edit user (admin)
Menu **Kelola Cabang & User** → tombol **Edit** di samping tiap user: ubah username, password baru (kosongkan jika tidak diganti), role, dan cabang. Perubahan role/cabang langsung berlaku tanpa user harus login ulang. Akun sendiri tidak bisa diturunkan dari Admin, dan harus tersisa minimal 1 admin.

## Batch ekspor (resi lama vs baru)
Setiap kali **Unduh Excel**, resi yang belum pernah diekspor ditandai **Batch N** (ekspor ke-N pada tanggal itu). Resi yang discan setelahnya muncul sebagai **Baru** di tabel, dan ikut batch berikutnya saat ekspor lagi. Tabel punya filter **Batch** (Semua / Belum diekspor / Batch 1, 2, ...). Excel berisi kolom **Batch Ekspor**, dan sheet Detail Pesanan memakai filter otomatis sehingga bisa disaring per batch. Kolom baru dibuat otomatis oleh aplikasi saat dibuka (tidak perlu import SQL).

## Subtotal Pesanan
Aplikasi menarik **Subtotal Pesanan** berupa **harga posting** (harga asli listing produk x jumlah, sebelum diskon, ongkir, dan potongan biaya platform) dari Shopee, Lazada, dan TikTok Shop. Tampil di kolom Detail tabel manifest, di sheet **Detail Pesanan** (kolom "Subtotal Pesanan"), dan di Excel pesanan dari menu Marketplace. Pesanan lama terisi setelah Anda menarik pesanan lagi (pilih 30 hari terakhir sekali), dan resi yang sudah discan ikut terisi otomatis.

## Paging daftar manifest
Daftar manifest dibagi per halaman (bawaan 10 baris). Di bawah tabel ada tombol halaman, info "Menampilkan 1–10 dari N", dan pilihan "Per halaman" (10/25/50/100, diingat di browser). Mengubah pencarian atau filter otomatis kembali ke halaman 1. Centang "pilih semua" hanya memilih baris di halaman yang sedang tampil. Unduh Excel tetap berisi semua data, bukan hanya halaman yang tampil.

## Rincian item per resi (sheet Detail Pesanan)
Resi dengan beberapa barang/varian dirinci di sheet **Detail Pesanan**: baris pertama berisi data resi lengkap, baris-baris berikutnya hanya Nama Barang (nama - varian), SKU, Qty, dan **Subtotal varian pesanan**. Kolom **Subtotal Pesanan** (total) dan **Tanggal Kirim** digabung (merge) sepanjang baris resi itu. Resi dengan satu item tetap satu baris. Di tabel manifest, rincian tampil di kolom Detail.

## Garis tepi (border) Excel
Semua sheet di Excel diberi garis tepi tipis pada sel yang berisi, header berwarna, dan sheet Detail Pesanan memakai kotak penuh (termasuk sel Subtotal Pesanan/Tanggal Kirim yang digabung) dengan teks panjang dibungkus otomatis.
