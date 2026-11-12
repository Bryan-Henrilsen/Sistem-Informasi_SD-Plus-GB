<?php

namespace App\Domains\LesEkskul\Http\Requests;

use App\Domains\LesEkskul\Enums\MetodeTransaksi;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// Validasi bentuk input form Catat Pembayaran; aturan bisnis (tagihan milik siswa, kelebihan, laporan harian) ada di TransaksiService

class CatatTransaksiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // role sudah dijaga middleware route
    }

    public function rules(): array
    {
        return [
            'siswa_id' => ['required', 'integer', 'exists:siswa,id'],
            'metode' => ['required', Rule::enum(MetodeTransaksi::class)->except([MetodeTransaksi::Online])],
            'nominal' => ['required', 'integer', 'min:1'],
            'tanggal' => ['required', 'date', 'before_or_equal:today'],
            'tagihan_ids' => ['required', 'array', 'min:1'],
            'tagihan_ids.*' => ['integer'],
            'catatan_transfer' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'tagihan_ids.required' => 'Centang minimal satu tagihan.',
            'nominal.required' => 'Nominal wajib diisi.',
            'nominal.min' => 'Nominal harus lebih dari 0.',
            'tanggal.before_or_equal' => 'Tanggal uang masuk tidak boleh di masa depan.',
        ];
    }
}
