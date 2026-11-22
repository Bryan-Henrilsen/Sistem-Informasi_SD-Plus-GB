<?php

namespace App\Domains\LesEkskul\Http\Requests;

use App\Domains\LesEkskul\Services\EkstrakurikulerService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// Format isian form Kegiatan Ekstrakurikuler; aturan yang butuh data lain ada di EkstrakurikulerService (#42)
class SimpanEkstrakurikulerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // dijaga middleware role:tu di route
    }

    public function rules(): array
    {
        $biasa = $this->input('jenis') === EkstrakurikulerService::JENIS_BIASA;

        return [
            'jenis' => ['required', Rule::in([EkstrakurikulerService::JENIS_BIASA, EkstrakurikulerService::JENIS_HONOR_FLAT, EkstrakurikulerService::JENIS_WAJIB])],
            'nama' => ['required', 'string', 'max:100'],
            'biaya' => [$biasa ? 'required' : 'nullable', 'integer', $biasa ? 'min:1' : 'min:0'],
            'biaya_non_honor' => ['nullable', 'integer', 'min:0'],
            'kuota' => ['nullable', 'integer', 'min:1'],
            'jenjang_min' => ['nullable', 'integer', 'between:1,6'],
            'jenjang_max' => ['nullable', 'integer', 'between:1,6'],
            'jadwal' => ['array', 'max:10'],
            'jadwal.*.hari' => ['required', Rule::in([5, 6])], // ekskul saat ini hanya Jumat & Sabtu (PRD A4)
            'jadwal.*.jam_mulai' => ['required', 'date_format:H:i'],
            'jadwal.*.jam_selesai' => ['required', 'date_format:H:i'],
            'jadwal.*.jenjang_min' => ['nullable', 'integer', 'between:1,6'],
            'jadwal.*.jenjang_max' => ['nullable', 'integer', 'between:1,6'],
            'jadwal.*.lokasi' => ['required', 'string', 'max:100'],
            'kelas_paralel' => ['array', 'max:20'],
            'kelas_paralel.*.id' => ['nullable', 'integer'],
            'kelas_paralel.*.nama' => ['required', 'string', 'max:100'],
            'kelas_paralel.*.kuota' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'nama.required' => 'Nama kegiatan wajib diisi.',
            'biaya.required' => 'Biaya per bulan wajib diisi.',
            'biaya.min' => 'Biaya harus lebih dari 0. Untuk kegiatan tanpa biaya pilih jenis Honor flat atau Wajib otomatis.',
            'jadwal.*.lokasi.required' => 'Lokasi jadwal wajib diisi.',
            'jadwal.*.jam_mulai.required' => 'Jam mulai wajib diisi.',
            'jadwal.*.jam_selesai.required' => 'Jam selesai wajib diisi.',
            'kelas_paralel.*.nama.required' => 'Nama kelas paralel wajib diisi.',
        ];
    }
}
