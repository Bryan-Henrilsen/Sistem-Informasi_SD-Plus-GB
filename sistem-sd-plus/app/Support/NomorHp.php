<?php

namespace App\Support;

// Nomor HP disimpan dalam satu bentuk baku "08…" supaya "+62 812-3456-789", "62812…", dan "0812…" dianggap sama
// (login & registrasi orang tua, Ringkasan v1.11 #36)
class NomorHp
{
    // Bentuk baku, atau null bila bukan nomor HP Indonesia yang masuk akal (08 + 8–12 digit)
    public static function normal(?string $nomor): ?string
    {
        $digit = preg_replace('/\D+/', '', (string) $nomor);

        $digit = match (true) {
            str_starts_with($digit, '62') => '0'.substr($digit, 2),
            str_starts_with($digit, '8') => '0'.$digit,
            default => $digit,
        };

        return preg_match('/^08\d{8,12}$/', $digit) ? $digit : null;
    }

    // Bentuk internasional tanpa "+" untuk tautan WhatsApp wa.me ("0812…" → "62812…"), atau null bila tidak sah
    public static function internasional(?string $nomor): ?string
    {
        $normal = self::normal($nomor);

        return $normal ? '62'.substr($normal, 1) : null;
    }

    // Isian login berupa nomor HP bila tidak memuat "@"
    public static function bukanEmail(?string $isian): bool
    {
        return ! str_contains((string) $isian, '@');
    }
}
