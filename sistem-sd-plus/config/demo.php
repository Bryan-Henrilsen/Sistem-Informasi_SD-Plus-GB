<?php

// Data demo (database/seeders/DataDemoSeeder.php). Akun orang tua utama demo diisi dari .env supaya data pribadi
// (nama, no HP, email, password) tidak ikut tersimpan di repository. Kosong = dipakai akun orang tua fiktif.
return [
    'ortu' => [
        'nama' => env('DEMO_ORTU_NAMA'),
        'no_hp' => env('DEMO_ORTU_HP'),
        'email' => env('DEMO_ORTU_EMAIL'),
        'password' => env('DEMO_ORTU_PASSWORD'),
    ],
];
