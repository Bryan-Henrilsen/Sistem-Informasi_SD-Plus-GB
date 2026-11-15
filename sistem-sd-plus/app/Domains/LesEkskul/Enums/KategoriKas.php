<?php

namespace App\Domains\LesEkskul\Enums;

// Kategori catatan kas manual (Ringkasan v1.11 #35); arah dan kas yang terdampak ditentukan di sini, bukan kolom
enum KategoriKas: string
{
    case SaldoAwalSekolah = 'saldo_awal_sekolah';
    case SaldoAwalYayasan = 'saldo_awal_yayasan';
    case PembayaranKolam = 'pembayaran_kolam';
    case Atk = 'atk';
    case AdminBank = 'admin_bank';
    case SetorYayasan = 'setor_yayasan';
    case PemasukanLain = 'pemasukan_lain';
    case PengeluaranLain = 'pengeluaran_lain';

    public function label(): string
    {
        return match ($this) {
            self::SaldoAwalSekolah => 'Saldo awal kas sekolah',
            self::SaldoAwalYayasan => 'Saldo awal kas yayasan',
            self::PembayaranKolam => 'Pembayaran uang kolam',
            self::Atk => 'ATK',
            self::AdminBank => 'Admin bank',
            self::SetorYayasan => 'Setor ke yayasan',
            self::PemasukanLain => 'Pemasukan lain',
            self::PengeluaranLain => 'Pengeluaran lain',
        };
    }

    public function masuk(): bool
    {
        return in_array($this, [self::SaldoAwalSekolah, self::SaldoAwalYayasan, self::PemasukanLain], true);
    }

    // Kas Yayasan hanya terdampak saldo awal yayasan dan setoran ke yayasan; sisanya Kas Sekolah
    public function kasYayasan(): bool
    {
        return in_array($this, [self::SaldoAwalYayasan, self::SetorYayasan], true);
    }

    public function saldoAwal(): bool
    {
        return in_array($this, [self::SaldoAwalSekolah, self::SaldoAwalYayasan], true);
    }

    public function butuhUraian(): bool
    {
        return in_array($this, [self::PemasukanLain, self::PengeluaranLain], true);
    }
}
