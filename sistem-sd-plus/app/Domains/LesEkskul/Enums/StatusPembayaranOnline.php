<?php

namespace App\Domains\LesEkskul\Enums;

enum StatusPembayaranOnline: string
{
    case Menunggu = 'menunggu';
    case Lunas = 'lunas';
    case Kedaluwarsa = 'kedaluwarsa';
    case Gagal = 'gagal';
}
