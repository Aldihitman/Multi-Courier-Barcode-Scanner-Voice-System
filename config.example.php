<?php
// ============================================================
//  SATU-SATUNYA FILE PENGATURAN (database + Shopee).
//  Salin file ini menjadi  config.php  lalu isi.
//  Jangan bagikan config.php ke siapa pun (berisi Partner Key & password DB).
// ============================================================
return [
    // ---------- Database (default Laragon) ----------
    'db_host' => '127.0.0.1',
    'db_name' => 'resi_rj_bike',
    'db_user' => 'root',
    'db_pass' => '',
    // 'mysql' (normal) atau 'sqlite' (tanpa MySQL, file di storage/ — hanya untuk uji coba)
    'db_driver' => 'mysql',

    // Zona waktu aplikasi (menentukan "hari ini" dan jam scan)
    'timezone' => 'Asia/Jakarta',

    // ---------- Shopee Open Platform (boleh dikosongkan dulu) ----------
    'partner_id'  => 0,        // angka, tanpa tanda kutip
    'partner_key' => '',
    'env'         => 'sandbox', // 'sandbox' = uji coba, 'live' = toko asli

    // Harus SAMA PERSIS dengan "Redirect URL" di app Shopee Console.
    // Console menolak localhost, jadi pakai: https://example.com/
    // lalu tempel alamat hasil redirect-nya ke menu Shopee di aplikasi (cara manual).
    'redirect_url' => 'https://example.com/',

    // Bagian depan link otorisasi dari Console (sebelum "/auth?...").
    // Sandbox: https://open.sandbox.test-stable.shopee.com   (kosongkan untuk live memakai cara lama)
    'auth_base' => 'https://open.sandbox.test-stable.shopee.com',

    // Alamat server API Shopee. Kosong = bawaan sesuai env.
    // Sandbox yang kamu pakai: https://openplatform.sandbox.test-stable.shopee.sg
    'host' => '',

    // 'auto' (coba dua cara tanda tangan, disarankan), 'raw' atau 'hex'
    'key_mode' => 'auto',
    // ---------- Lazada (boleh dikosongkan) ----------
    // Dari Lazada Open Platform (open.lazada.com) > App Console > App Management
    'lazada' => [
        'app_key'      => '',
        'app_secret'   => '',
        'redirect_url' => 'https://example.com/', // harus sama dengan "Callback URL" di app Lazada
        'country'      => 'id',                   // id = Indonesia
    ],

    // ---------- TikTok Shop / Tokopedia (boleh dikosongkan) ----------
    // Dari TikTok Shop Partner Center (partner.tiktokshop.com) > App & Service > app kamu
    'tiktok' => [
        'app_key'    => '',
        'app_secret' => '',
        // Salin "Authorization link" dari halaman app di Partner Center (lebih aman), atau isi service_id saja.
        'auth_url'   => '',
        'service_id' => '',
    ],
];
