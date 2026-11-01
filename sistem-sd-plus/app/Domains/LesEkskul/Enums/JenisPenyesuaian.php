<?php

namespace App\Domains\LesEkskul\Enums;

enum JenisPenyesuaian: string
{
    case AlihKegiatan = 'alih_kegiatan';
    case KelebihanTransfer = 'kelebihan_transfer';
    case KegiatanTidakBerlangsung = 'kegiatan_tidak_berlangsung';
    case RefundBerhenti = 'refund_berhenti';
}
