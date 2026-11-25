<?php

use App\Domains\LesEkskul\Enums\JenisPengajar;
use App\Domains\LesEkskul\Enums\PeranHonor;
use App\Domains\LesEkskul\Models\Ekstrakurikuler;
use App\Domains\LesEkskul\Models\JadwalEkskul;
use App\Domains\LesEkskul\Models\LesTambahan;
use App\Domains\LesEkskul\Models\Pendaftaran;
use App\Domains\LesEkskul\Models\RiwayatWaliKelas;
use App\Domains\LesEkskul\Models\Tagihan;
use App\Domains\LesEkskul\Models\Transaksi;
use App\Domains\LesEkskul\Services\HonorService;
use App\Models\Guru;
use App\Models\Kelas;
use App\Models\Siswa;
use App\Models\User;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Pendukung/HonorUji.php';

// Pergantian pengajar / wali kelas per pertemuan (Ringkasan v1.11 #50). Periode uji: Agustus 2026 (hhSetup).
// Sabtu di Agustus 2026: 1, 8, 15, 22, 29; hari sekolah Senin–Jumat: 21 hari
beforeEach(function () {
    hhSetup($this);
});

// Pendapatan satu bulan tagihan yang diakui di periode Agustus (tunggakan atau bayar di muka ikut diakui di sini)
function ppPendapatan(Ekstrakurikuler|LesTambahan $kegiatan, int $total, string $bulan): void
{
    $kolom = $kegiatan instanceof LesTambahan ? ['les_tambahan_id' => $kegiatan->id] : ['ekstrakurikuler_id' => $kegiatan->id];
    $siswa = Siswa::create(['nama' => "Siswa {$kegiatan->id} {$bulan}"]);
    $pendaftaran = Pendaftaran::create(['siswa_id' => $siswa->id, 'tanggal_daftar' => '2026-07-20', ...$kolom]);
    $tagihan = Tagihan::create(['pendaftaran_id' => $pendaftaran->id, 'bulan_tagihan' => "{$bulan}-01", 'nominal' => $total]);
    $transaksi = Transaksi::create([
        'siswa_id' => $siswa->id, 'laporan_harian_id' => test()->laporan->id, 'tanggal' => '2026-08-10', 'metode' => 'cash', 'nominal' => $total,
        'status' => 'terverifikasi', 'diinput_oleh' => test()->tu->id, 'diverifikasi_oleh' => test()->tu->id, 'diverifikasi_at' => now(),
    ]);
    $transaksi->tagihan()->attach($tagihan->id, ['nominal' => $total]);
    DB::table('alokasi_transaksi')->where('transaksi_id', $transaksi->id)->update(['diakui_periode_id' => test()->periode->id]);
}

// Karate tiap Sabtu dengan pelatih eksternal dan satu pendamping
function ppKarate(): Ekstrakurikuler
{
    $karate = hhEkskul('Karate');
    JadwalEkskul::create(['ekstrakurikuler_id' => $karate->id, 'hari' => 6, 'jam_mulai' => '08:00', 'jam_selesai' => '09:30', 'lokasi' => 'Aula']);
    $karate->pengajar()->attach(hhPengajar('Pelatih Lama', JenisPengajar::Eksternal)->id);
    $karate->pendamping()->attach(User::factory()->create(['name' => 'Pendamping Karate'])->id);

    return $karate;
}

function ppGanti(Ekstrakurikuler $kegiatan, array $data)
{
    return test()->actingAs(test()->tu)->post("/les-ekskul/honor/kegiatan/{$kegiatan->id}/ganti-pengajar", $data);
}

test('honor bulan pergantian dibagi per pertemuan; bulan lain mengikuti pengajar bulan tagihannya', function () {
    $karate = ppKarate();
    $lama = $karate->pengajar()->first();
    $baru = hhPengajar('Pelatih Baru', JenisPengajar::Eksternal);

    ppGanti($karate, ['pengajar_lama_id' => $lama->id, 'tanggal_terakhir' => '2026-08-15', 'pengajar_baru_id' => $baru->id, 'tanggal_mulai' => '2026-08-17'])
        ->assertSessionHasNoErrors();

    ppPendapatan($karate, 1000000, '2026-08'); // Agustus: lama 3 pertemuan, baru 2 pertemuan
    ppPendapatan($karate, 500000, '2026-09'); // bayar di muka September: seluruhnya pengajar baru

    app(HonorService::class)->hitung($this->periode);

    // Porsi pengajar 60% × 1.500.000 = 900.000: lama 1.000.000 × 3/5 → 360.000; baru 1.000.000 × 2/5 + 500.000 → 540.000
    expect(hhNominal('Karate', PeranHonor::Pengajar, 'Pelatih Lama'))->toBe([360000])
        ->and(hhNominal('Karate', PeranHonor::Pengajar, 'Pelatih Baru'))->toBe([540000])
        ->and(hhNominal('Karate', PeranHonor::GuruPendamping))->toBe([75000]);

    $catatan = collect(app(HonorService::class)->rekap($this->periode)['kegiatan'])->firstWhere('nama', 'Karate')['catatan'];
    expect($catatan)->toBe(['Agustus 2026: Pelatih Lama 3 dari 5 pertemuan, Pelatih Baru 2 dari 5 pertemuan']);
});

test('pengajar lama eksternal diganti internal di periode yang sama menahan perhitungan honor', function () {
    $karate = ppKarate();
    $internal = hhPengajar('Guru Karate');

    ppGanti($karate, ['pengajar_lama_id' => $karate->pengajar()->first()->id, 'tanggal_terakhir' => '2026-08-15', 'pengajar_baru_id' => $internal->id, 'tanggal_mulai' => '2026-08-17'])
        ->assertSessionHasNoErrors();
    ppPendapatan($karate, 1000000, '2026-08');

    expect(implode(' ', app(HonorService::class)->hambatan($this->periode)))->toContain('Karate: pengajar internal dan eksternal tercampur');
});

test('ganti pengajar memeriksa tanggal dan pengajar; atur pengajar biasa tidak menghapus riwayat', function () {
    $karate = ppKarate();
    $lama = $karate->pengajar()->first();
    $baru = hhPengajar('Pelatih Baru', JenisPengajar::Eksternal);

    ppGanti($karate, ['pengajar_lama_id' => $lama->id, 'tanggal_terakhir' => '2026-08-15', 'pengajar_baru_id' => $baru->id, 'tanggal_mulai' => '2026-08-15'])
        ->assertSessionHasErrors('tanggal_mulai');
    ppGanti($karate, ['pengajar_lama_id' => $lama->id, 'tanggal_terakhir' => '2026-08-15', 'pengajar_baru_id' => $lama->id, 'tanggal_mulai' => '2026-08-17'])
        ->assertSessionHasErrors('pengajar_baru_id');
    ppGanti($karate, ['pengajar_lama_id' => $baru->id, 'tanggal_terakhir' => '2026-08-15', 'pengajar_baru_id' => $lama->id, 'tanggal_mulai' => '2026-08-17'])
        ->assertSessionHasErrors('pengajar_lama_id');

    ppGanti($karate, ['pengajar_lama_id' => $lama->id, 'tanggal_terakhir' => '2026-08-15', 'pengajar_baru_id' => $baru->id, 'tanggal_mulai' => '2026-08-17'])
        ->assertSessionHasNoErrors();
    expect($karate->pengajar()->pluck('pengajar_ekskul.id')->all())->toBe([$baru->id])
        ->and($karate->riwayatPengajar()->count())->toBe(2);

    // Centang ulang daftar pengajar (membetulkan salah input) hanya mengubah penugasan yang masih berjalan
    $this->actingAs($this->tu)->post("/les-ekskul/honor/kegiatan/{$karate->id}/pengajar", ['pengajar_ids' => [$baru->id]])->assertSessionHasNoErrors();
    expect($karate->riwayatPengajar()->count())->toBe(2);

    // Orang tua / Bendahara tidak boleh mengganti pengajar
    $this->actingAs($this->bendahara)->post("/les-ekskul/honor/kegiatan/{$karate->id}/ganti-pengajar", [])->assertForbidden();
});

test('wali kelas berganti di tengah bulan: honor les dibagi per hari sekolah', function () {
    $kelas = Kelas::create(['tahun_ajaran_id' => $this->ta->id, 'jenjang' => 2, 'nama_kelas' => '2A']);
    $waliLama = Guru::where('user_id', hhGuru('Wali Lama')->id)->first();
    $waliBaru = Guru::where('user_id', hhGuru('Wali Baru')->id)->first();
    $kelas->update(['wali_kelas_id' => $waliLama->id]);
    $les = LesTambahan::create(['kelas_id' => $kelas->id, 'biaya' => 180000]);

    $this->actingAs($this->tu)->post("/les-ekskul/kelas/{$kelas->id}/ganti-wali", ['guru_id' => $waliBaru->id, 'tanggal_terakhir' => '2026-08-14', 'tanggal_mulai' => '2026-08-17'])
        ->assertSessionHasNoErrors();

    expect($kelas->fresh()->wali_kelas_id)->toBe($waliBaru->id)
        ->and(RiwayatWaliKelas::where('kelas_id', $kelas->id)->orderBy('id')->get(['guru_id', 'tanggal_mulai', 'tanggal_selesai'])->map(fn ($r) => [$r->guru_id, $r->tanggal_mulai?->toDateString(), $r->tanggal_selesai?->toDateString()])->all())
        ->toBe([[$waliLama->id, null, '2026-08-14'], [$waliBaru->id, '2026-08-17', null]]);

    ppPendapatan($les, 1000000, '2026-08');
    app(HonorService::class)->hitung($this->periode);

    // Porsi pengajar les 60% = 600.000; lama 10 dari 21 hari sekolah, baru 11 dari 21 (dibulatkan ke bawah, sisa ke kas)
    expect(hhNominal('Les 2A', PeranHonor::Pengajar, 'Wali Lama'))->toBe([285714])
        ->and(hhNominal('Les 2A', PeranHonor::Pengajar, 'Wali Baru'))->toBe([314285]);

    $total = DB::table('honor_detail')->join('honor_kegiatan', 'honor_kegiatan.id', '=', 'honor_detail.honor_kegiatan_id')
        ->where('honor_kegiatan.les_tambahan_id', $les->id)->sum('honor_detail.nominal');
    expect((int) $total)->toBe(1000000);
});

test('ganti wali menolak guru yang sudah menjadi wali kelas lain dan tanggal yang terbalik', function () {
    $waliLama = Guru::where('user_id', hhGuru('Wali Lama')->id)->first();
    $waliLain = Guru::where('user_id', hhGuru('Wali 2B')->id)->first();
    $kelas = Kelas::create(['tahun_ajaran_id' => $this->ta->id, 'jenjang' => 2, 'nama_kelas' => '2A', 'wali_kelas_id' => $waliLama->id]);
    Kelas::create(['tahun_ajaran_id' => $this->ta->id, 'jenjang' => 2, 'nama_kelas' => '2B', 'wali_kelas_id' => $waliLain->id]);

    $url = "/les-ekskul/kelas/{$kelas->id}/ganti-wali";
    $this->actingAs($this->tu)->post($url, ['guru_id' => $waliLain->id, 'tanggal_terakhir' => '2026-08-14', 'tanggal_mulai' => '2026-08-17'])
        ->assertSessionHasErrors('guru_id');
    $this->actingAs($this->tu)->post($url, ['guru_id' => Guru::where('user_id', hhGuru('Wali Baru')->id)->value('id'), 'tanggal_terakhir' => '2026-08-17', 'tanggal_mulai' => '2026-08-14'])
        ->assertSessionHasErrors('tanggal_mulai');

    expect(RiwayatWaliKelas::count())->toBe(0);
});
