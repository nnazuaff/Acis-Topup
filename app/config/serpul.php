<?php

return [
    // Kredensial koneksi H2H (lihat menu Koneksi H2H / Developer di dashboard Serpul H2H)
    // Catatan GitBook Serpul H2H menyebut: ID Member + PIN + Password.
    // Di beberapa dashboard tertulis API Key, namun untuk template OTOMAX umumnya dipakai sebagai "Password".
    'member_id' => '2',
    'pin' => '230548',
    // Simpan juga api_key jika dashboard Anda menyebutnya API Key.
    // Sistem akan memakai ini sebagai fallback untuk 'password' bila 'password' kosong.
    'api_key' => '9158efa69a228e981bf69eeed66519ab',
    'password' => '',

    // Center URL / domain H2H Anda (GitBook: [mydomain-h2h].serpul.co.id)
    'base_url' => 'https://acispay.serpul.co.id',

    // Beberapa shared hosting keluar via IPv6 saat target punya AAAA record.
    // Jika whitelist Serpul memakai IPv4, paksa request cURL menggunakan IPv4.
    // Opsi: 'v4' | 'v6' | 'any'
    'ip_resolve' => 'v4',

    // Serpul panel Anda memakai: https://topup.acispayment.com/callback
    // Simpan base URL publik di sini agar callback_url yang dikirim saat order selalu HTTPS.
    'public_base_url' => 'https://topup.acispayment.com',
    'callback_path' => '/callback',

    // Template H2H OTOMAX umumnya memakai GET.
    'order_method' => 'GET',

    // Path Center (GitBook): /without-sign/trx
    'endpoints' => [
        'trx' => '/without-sign/trx',
        // belum ada dokumentasi resmi untuk "products/pricelist" endpoint di GitBook,
        // jadi sementara kita pakai trx saja untuk transaksi.
    ],
];
