<?php

// Pengaturan Modul Les & Ekstrakurikuler (PRD B.1)
return [
    // Bulan (1-12) yang tidak pernah ditagih (FR-9, Ringkasan V1.11 #19).
    // Semester ganjil Agustus-November, genap Januari-Mei; Juni, Juli, Desember tidak ada tagihan.
    'bulan_tidak_ditagih' => [6, 7, 12],

    // Bulan tambahan yang tidak ditagih untuk jenjang tertentu (Ringkasan v1.11 #23)
    // Kelas 6: les dan ekskul semester genap hanya sampai April, jadi Mei tidak ditagih.
    'bulan_tidak_ditagih_per_jenjang' => [
        6 => [5],
    ],

    // Kewajiban mendaftar kegiatan (FR-33). Kebijakan saat ini dari klien: ekskul wajib minimal satu, les tambahan opsional.
    // Kelas 6 semester genap tidak wajib ekskul (Ringkasan v1.11 #23).
    'wajib_minimal_satu_ekskul' => env('WAJIB_MINIMAL_SATU_EKSKUL', true),
    'wajib_les_tambahan' => env('WAJIB_LES_TAMBAHAN', false),

    // Pembayaran online (FR-29–FR-32): selama dimatikan, halaman orang tua hanya menampilkan rekening untuk transfer manual
    'pembayaran_online' => env('PEMBAYARAN_ONLINE', false),

    // Rekening sekolah untuk transfer manual (FR-32, Ringkasan v1.11 #38); diisi di .env server
    'rekening' => [
        'bank' => env('REKENING_BANK'),
        'nomor' => env('REKENING_NOMOR'),
        'atas_nama' => env('REKENING_ATAS_NAMA'),
    ],

    // Nomor WhatsApp TU untuk konfirmasi bukti transfer (bukti tidak diunggah ke aplikasi, Catatan v1.6)
    'wa_tu' => env('WA_TU'),
];
