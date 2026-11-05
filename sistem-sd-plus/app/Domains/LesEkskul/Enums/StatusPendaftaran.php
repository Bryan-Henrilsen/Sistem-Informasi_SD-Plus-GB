<?php

namespace App\Domains\LesEkskul\Enums;

enum StatusPendaftaran: string
{
    case Aktif = 'aktif';
    case Berhenti = 'berhenti';
    case Pindah = 'pindah';
}


