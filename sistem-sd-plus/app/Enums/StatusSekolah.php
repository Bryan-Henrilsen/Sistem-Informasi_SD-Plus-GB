<?php

namespace App\Enums;

enum StatusSekolah: string
{
    case Aktif = 'aktif';
    case PindahSekolah = 'pindah_sekolah';
    case Lulus = 'lulus';
}
