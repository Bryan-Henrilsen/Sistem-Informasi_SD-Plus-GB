<?php

use App\Models\Kelas;
use App\Models\Siswa;
use App\Models\SiswaKelas;
use App\Models\TahunAjaran;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Impor siswa lewat unggah Excel (FR-28, Ringkasan v1.11 #53). File dibuat di dalam test, meniru template Juli dan ekspor Dapodik
beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->tu = User::factory()->create()->assignRole('tu');
    $this->lalu = TahunAjaran::create(['nama' => '2025/2026', 'tanggal_mulai' => '2025-07-14', 'tanggal_selesai' => '2026-06-30', 'status_aktif' => false]);
    $this->ta = TahunAjaran::create(['nama' => '2026/2027', 'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2027-06-30', 'status_aktif' => true]);
});

// File .xlsx sementara dari array baris (baris pertama = judul)
function isExcelUji(array $baris, string $nama = 'uji.xlsx'): UploadedFile
{
    $buku = new Spreadsheet;
    $buku->getActiveSheet()->setTitle('Sheet1')->fromArray($baris, null, 'A1', true);
    $path = tempnam(sys_get_temp_dir(), 'impor').'.xlsx';
    (new Xlsx($buku))->save($path);

    return new UploadedFile($path, $nama, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

function isSiswaDiKelas(string $nama, Kelas $kelas, array $isi = []): Siswa
{
    $siswa = Siswa::create(['nama' => $nama, ...$isi]);
    SiswaKelas::create(['siswa_id' => $siswa->id, 'kelas_id' => $kelas->id]);

    return $siswa;
}

function isStatusBaris(array $pratinjau): array
{
    return collect($pratinjau['baris'])->mapWithKeys(fn ($b) => [$b['nama'] => $b['status']])->all();
}

test('tahap Juli: siswa lama, pindah kelas, siswa baru, nama mirip dipilih TU, dan kelas tidak dikenal', function () {
    $kelas3a = Kelas::create(['tahun_ajaran_id' => $this->lalu->id, 'jenjang' => 3, 'nama_kelas' => '3A']);
    $kelas4a = Kelas::create(['tahun_ajaran_id' => $this->ta->id, 'jenjang' => 4, 'nama_kelas' => '4A']);
    $kelas4b = Kelas::create(['tahun_ajaran_id' => $this->ta->id, 'jenjang' => 4, 'nama_kelas' => '4B']);
    $kelas1a = Kelas::create(['tahun_ajaran_id' => $this->ta->id, 'jenjang' => 1, 'nama_kelas' => '1A', 'nama_pelindung' => 'Gregory']);

    $budi = isSiswaDiKelas('Budi Santoso', $kelas3a);
    $citra = isSiswaDiKelas('Citra Lestari', $kelas3a);
    SiswaKelas::create(['siswa_id' => $citra->id, 'kelas_id' => $kelas4a->id]); // sudah dinaikkan massal ke 4A
    $dewi = isSiswaDiKelas('Dewi Anggraini', $kelas3a);

    $file = isExcelUji([
        ['Nama', 'Kelas', 'L/P'],
        ['Budi Santoso', '4A', 'L'],
        ['citra  lestari', '4B', ''],
        ['Eko Prasetyo', '1A Gregory', 'L'],
        ['Dewi Angraini', '4A', 'P'],
        ['Fajar', '9Z', 'L'],
    ]);

    $this->actingAs($this->tu)->post('/les-ekskul/impor-siswa/unggah', ['tahap' => 'juli', 'tahun_ajaran_id' => $this->ta->id, 'file' => $file])
        ->assertSessionHasNoErrors()->assertRedirect('/les-ekskul/impor-siswa');

    $this->actingAs($this->tu)->get('/les-ekskul/impor-siswa')->assertInertia(fn (Assert $page) => $page
        ->component('les-ekskul/siswa/impor')
        ->where('pratinjau.tahap', 'juli')
        ->where('pratinjau.baris', fn ($baris) => isStatusBaris(['baris' => $baris]) === [
            'Budi Santoso' => 'lama', 'citra lestari' => 'pindah', // spasi ganda dirapikan saat dibaca
            'Eko Prasetyo' => 'baru', 'Dewi Angraini' => 'pilih', 'Fajar' => 'error',
        ] && collect($baris)->firstWhere('nama', 'Dewi Angraini')['kandidat'][0]['id'] === $dewi->id));

    // Baris "pilih" wajib diputuskan dulu
    $this->actingAs($this->tu)->post('/les-ekskul/impor-siswa/simpan', ['keputusan' => []])->assertSessionHasErrors('keputusan');
    expect(Siswa::count())->toBe(3);

    // Dewi: siswa yang sama dengan Dewi Anggraini tahun lalu, nama dibetulkan mengikuti file
    $no = 5; // baris Excel ke-5
    $this->actingAs($this->tu)->post('/les-ekskul/impor-siswa/simpan', ['keputusan' => [$no => ['aksi' => 'cocok', 'siswa_id' => $dewi->id, 'nama' => 'file']]])
        ->assertSessionHasNoErrors()->assertRedirect('/les-ekskul/data-siswa?tahun='.$this->ta->id);

    $kelasDi = fn (Siswa $s) => $s->riwayatKelas()->where('tahun_ajaran_id', $this->ta->id)->value('kelas_id');
    expect($kelasDi($budi))->toBe($kelas4a->id)
        ->and($budi->fresh()->jenis_kelamin)->toBe('L')
        ->and($kelasDi($citra))->toBe($kelas4b->id)
        ->and($kelasDi($dewi))->toBe($kelas4a->id)
        ->and($dewi->fresh()->nama)->toBe('Dewi Angraini')
        ->and(Siswa::where('nama', 'Eko Prasetyo')->sole()->riwayatKelas()->value('kelas_id'))->toBe($kelas1a->id)
        ->and(Siswa::where('nama', 'Fajar')->exists())->toBeFalse()
        ->and(session()->has('impor_siswa'))->toBeFalse();
});

test('tahap Oktober (Dapodik): melengkapi lewat nama atau NISN, pilihan nama, nama mirip, dan siswa baru ditawarkan', function () {
    $kelas = Kelas::create(['tahun_ajaran_id' => $this->ta->id, 'jenjang' => 1, 'nama_kelas' => '1A', 'nama_pelindung' => 'Gregory']);
    $aaron = isSiswaDiKelas('Aaron Yves', $kelas);
    $bella = isSiswaDiKelas('Bella Putri', $kelas, ['nisn' => '0012345678']);
    $clara = isSiswaDiKelas('Clara Wijaya', $kelas);

    // Bentuk ekspor Dapodik: judul di atas, baris judul kolom, sub-judul (Data Ayah), lalu data; kolom sensitif diabaikan
    $file = isExcelUji([
        ['Daftar Peserta Didik'],
        ['SD PLUS GEMBALA BAIK'],
        ['Kecamatan …'],
        ['Tanggal unduh …'],
        ['No', 'Nama', 'NIPD', 'JK', 'NISN', 'Tempat Lahir', 'Tanggal Lahir', 'NIK', 'Data Ayah', 'Rombel Saat Ini'],
        [null, null, null, null, null, null, null, null, 'Nama', null],
        [1, 'Aaron Yves', 1285, 'L', '3185465906', 'Pontianak', '2018-01-07', '6171000000000001', 'Ayah A', '1A Gregory'],
        [2, 'Bella Putri Ayu', 1286, 'P', '0012345678', 'Pontianak', '2018-02-01', '6171000000000002', 'Ayah B', '1A Gregory'],
        [3, 'Klara Wijaya', 1287, 'P', '3185465907', 'Pontianak', '2018-03-01', '6171000000000003', 'Ayah C', '1A Gregory'],
        [4, 'Dimas Baru', 1288, 'L', '3185465908', 'Pontianak', '2018-04-01', '6171000000000004', 'Ayah D', '1A Gregory'],
        [5, 'Eva', 1289, 'P', '3185465909', 'Pontianak', '2018-05-01', '6171000000000005', 'Ayah E', '7Z Nowhere'],
    ], 'Siswa kls I - VI.xlsx');

    $this->actingAs($this->tu)->post('/les-ekskul/impor-siswa/unggah', ['tahap' => 'dapodik', 'file' => $file])->assertSessionHasNoErrors();

    $this->actingAs($this->tu)->get('/les-ekskul/impor-siswa')->assertInertia(fn (Assert $page) => $page
        ->where('pratinjau.baris', fn ($baris) => isStatusBaris(['baris' => $baris]) === [
            'Aaron Yves' => 'lengkapi', 'Bella Putri Ayu' => 'lengkapi', 'Klara Wijaya' => 'pilih', 'Dimas Baru' => 'baru', 'Eva' => 'error',
        ] && collect($baris)->firstWhere('nama', 'Bella Putri Ayu')['nama_beda'] === true
          && collect($baris)->firstWhere('nama', 'Aaron Yves')['perubahan'][0] === ['kolom' => 'NIS', 'lama' => null, 'baru' => '1285']));

    $this->actingAs($this->tu)->post('/les-ekskul/impor-siswa/simpan', ['keputusan' => [
        8 => ['aksi' => 'cocok', 'siswa_id' => $bella->id, 'nama' => 'file'], // Bella: nama mengikuti Dapodik
        9 => ['aksi' => 'cocok', 'siswa_id' => $clara->id, 'nama' => 'aplikasi'], // Klara di Dapodik salah ketik: nama aplikasi dipertahankan
    ]])->assertSessionHasNoErrors();

    expect($aaron->fresh())->nis->toBe('1285')->nisn->toBe('3185465906')->jenis_kelamin->toBe('L')
        ->and($aaron->fresh()->tanggal_lahir->toDateString())->toBe('2018-01-07')
        ->and($bella->fresh())->nama->toBe('Bella Putri Ayu')->nis->toBe('1286')
        ->and($clara->fresh())->nama->toBe('Clara Wijaya')->nisn->toBe('3185465907')
        ->and(Siswa::where('nama', 'Dimas Baru')->sole())->nis->toBe('1288')
        ->and(Siswa::where('nama', 'Eva')->exists())->toBeFalse();
});

test('NISN yang sudah dipakai siswa lain ditandai error; batal membuang hasil bacaan', function () {
    $kelas = Kelas::create(['tahun_ajaran_id' => $this->ta->id, 'jenjang' => 1, 'nama_kelas' => '1A']);
    // Pemilik NISN adalah siswa tahun lalu yang tidak lagi punya kelas tahun ini (mis. sudah lulus)
    isSiswaDiKelas('Lama Sekali', Kelas::create(['tahun_ajaran_id' => $this->lalu->id, 'jenjang' => 6, 'nama_kelas' => '6A']), ['nisn' => '1111111111']);

    $file = isExcelUji([
        ['No', 'Nama', 'NIPD', 'JK', 'NISN', 'Tanggal Lahir', 'Rombel Saat Ini'],
        [1, 'Orang Lain', 2001, 'L', '1111111111', '2018-01-01', '1A'],
    ]);
    $this->actingAs($this->tu)->post('/les-ekskul/impor-siswa/unggah', ['tahap' => 'dapodik', 'file' => $file]);
    $this->actingAs($this->tu)->get('/les-ekskul/impor-siswa')->assertInertia(fn (Assert $page) => $page
        ->where('pratinjau.baris.0.status', 'error')
        ->where('pratinjau.baris.0.pesan', 'NISN 1111111111 sudah dipakai siswa lain di aplikasi.'));

    $this->actingAs($this->tu)->delete('/les-ekskul/impor-siswa')->assertRedirect('/les-ekskul/impor-siswa');
    $this->actingAs($this->tu)->get('/les-ekskul/impor-siswa')->assertInertia(fn (Assert $page) => $page->where('pratinjau', null));
});

test('file salah ditolak, template bisa diunduh, dan hanya TU yang bisa mengimpor', function () {
    Kelas::create(['tahun_ajaran_id' => $this->ta->id, 'jenjang' => 1, 'nama_kelas' => '1A']);

    $this->actingAs($this->tu)->post('/les-ekskul/impor-siswa/unggah', ['tahap' => 'juli', 'tahun_ajaran_id' => $this->ta->id, 'file' => UploadedFile::fake()->create('foto.pdf', 10, 'application/pdf')])
        ->assertSessionHasErrors('file');
    $this->actingAs($this->tu)->post('/les-ekskul/impor-siswa/unggah', ['tahap' => 'juli', 'tahun_ajaran_id' => $this->ta->id, 'file' => isExcelUji([['Siswa', 'Rombel'], ['A', '1A']])])
        ->assertSessionHasErrors(['file' => 'Kolom "Nama" dan "Kelas" tidak ditemukan. Pakai template dari aplikasi.']);
    $this->actingAs($this->tu)->post('/les-ekskul/impor-siswa/unggah', ['tahap' => 'dapodik', 'file' => isExcelUji([['Nama', 'Kelas'], ['A', '1A']])])
        ->assertSessionHasErrors('file');

    $this->actingAs($this->tu)->get('/les-ekskul/impor-siswa/template?tahun='.$this->ta->id)
        ->assertOk()->assertDownload('Template_Impor_Siswa_2026-2027.xlsx');

    $bendahara = User::factory()->create()->assignRole('bendahara');
    $this->actingAs($bendahara)->get('/les-ekskul/impor-siswa')->assertForbidden();
    $this->actingAs($bendahara)->post('/les-ekskul/impor-siswa/unggah', [])->assertForbidden();
});
