<?php

namespace App\Domains\LesEkskul\Enums;

enum StatusPenyesuaian: string
{
    case Selesai = 'selesai';
    case Proses = 'proses';
    case Berhasil = 'berhasil';
}
