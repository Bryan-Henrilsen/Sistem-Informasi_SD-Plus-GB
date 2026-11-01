<?php

namespace App\Domains\LesEkskul\Enums;

enum StatusPeriodeLaporan: string
{
    case Terbuka = 'terbuka';
    case Diajukan = 'diajukan';
    case Ditutup = 'ditutup';
}
