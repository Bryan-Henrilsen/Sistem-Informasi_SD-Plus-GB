<?php

namespace App\Domains\LesEkskul\Http\Requests;

use App\Domains\LesEkskul\Enums\TipeKegiatan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// Validasi bentuk input form Tambah Pendaftaran (FR-6); aturan bisnis (jenjang, duplikasi, kuota, bentrok) ada di PendaftaranService
class DaftarkanSiswaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // role sudah dijaga middleware route
    }

    public function rules(): array
    {
        return [
            'siswa_id' => ['required', 'integer', 'exists:siswa,id'],
            'jenis' => ['required', Rule::enum(TipeKegiatan::class)],
            'kegiatan_id' => ['required', 'integer'],
            'tanggal_daftar' => ['required', 'date', 'before_or_equal:today'],
            'abaikan_kuota' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'jenis.required' => 'Pilih kegiatan.',
            'kegiatan_id.required' => 'Pilih kegiatan.',
            'tanggal_daftar.required' => 'Tanggal daftar wajib diisi.',
            'tanggal_daftar.before_or_equal' => 'Tanggal daftar tidak boleh di masa depan.',
        ];
    }
}
