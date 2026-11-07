<?php

namespace App\Domains\LesEkskul\Enums;

enum MetodeTransaksi: string
{
    case Cash = 'cash';
    case Transfer = 'transfer';
    case Online = 'online';
}
