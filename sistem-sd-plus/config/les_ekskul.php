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
];
