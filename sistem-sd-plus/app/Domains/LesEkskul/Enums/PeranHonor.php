<?php

namespace App\Domains\LesEkskul\Enums;

enum PeranHonor: string
{
    case Pengajar = 'pengajar';
    case GuruPendamping = 'guru_pendamping';
    case KasSekolah = 'kas_sekolah';
    case Yayasan = 'yayasan';
    case PenanggungJawab = 'penanggung_jawab';
    case Bendahara = 'bendahara';
    case AdminTu = 'admin_tu';
    case AdminIt = 'admin_it';
    case HonorFlat = 'honor_flat';

    // Peran yang penerimanya diatur di penerima_peran_honor, bukan dari pengajar/pendamping kegiatan
    public function adalahPengelola(): bool
    {
        return in_array($this, [self::PenanggungJawab, self::Bendahara, self::AdminTu, self::AdminIt], true);
    }
}
