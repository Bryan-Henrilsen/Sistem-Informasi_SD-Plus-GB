<?php

namespace App\Domains\LesEkskul\Enums;

enum StatusTransaksi: string
{
    case Terverifikasi = 'terverifikasi';
    case Pending = 'pending';
    case Ditolak = 'ditolak';
}
