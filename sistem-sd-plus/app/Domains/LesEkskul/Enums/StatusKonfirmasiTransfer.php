<?php

namespace App\Domains\LesEkskul\Enums;

// Konfirmasi "Saya sudah bayar" dari orang tua (FR-15b, Ringkasan v1.11 #41)
enum StatusKonfirmasiTransfer: string
{
    case Menunggu = 'menunggu'; // belum diproses TU
    case Dicatat = 'dicatat'; // TU sudah mencatat transaksinya
    case Ditolak = 'ditolak'; // TU menolak, mis. tidak ada di mutasi
    case Dibatalkan = 'dibatalkan'; // dibatalkan orang tua sebelum diproses
}
