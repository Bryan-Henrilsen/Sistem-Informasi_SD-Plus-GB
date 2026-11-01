<?php

namespace App\Domains\LesEkskul\Enums;

enum StatusLaporanHarian: string
{
    case Draft = 'draft';
    case Diajukan = 'diajukan';
    case Disetujui = 'disetujui';
    case Ditolak = 'ditolak';
}
