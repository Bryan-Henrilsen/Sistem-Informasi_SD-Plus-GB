<?php

namespace Database\Seeders;

use App\Domains\LesEkskul\Enums\MetodeTransaksi;
use App\Domains\LesEkskul\Enums\StatusTagihan;
use App\Domains\LesEkskul\Enums\SumberInput;
use App\Domains\LesEkskul\Models\Ekstrakurikuler;
use App\Domains\LesEkskul\Models\LesTambahan;
use App\Domains\LesEkskul\Models\Pendaftaran;
use App\Domains\LesEkskul\Models\PeriodeLaporan;
use App\Domains\LesEkskul\Models\Tagihan;
use App\Domains\LesEkskul\Services\TagihanService;
use App\Domains\LesEkskul\Services\TransaksiService;
use App\Models\Guru;
use App\Models\Siswa;
use App\Models\SiswaKelas;
use App\Models\TahunAjaran;
use App\Models\User;
use Faker\Factory as Faker;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

// DATA CONTOH untuk pengembangan dan demo — bukan data sekolah. Nama siswa fiktif (Faker), biaya kegiatan perkiraan.
// Jalankan setelah DatabaseSeeder: php artisan db:seed --class=DataContohLesEkskulSeeder
class DataContohLesEkskulSeeder extends Seeder
{
    public function __construct(private TagihanService $tagihan, private TransaksiService $transaksi) {}

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command->warn('Data contoh tidak boleh dijalankan di produksi.');

            return;
        }

        $faker = Faker::create('id_ID');
        $faker->seed(2026); // hasil acak selalu sama setiap kali seeder dijalankan
        mt_srand(2026);

        $tahunAjaran = TahunAjaran::aktif()->firstOrFail();
        $ekskul = $this->buatKegiatan($tahunAjaran);
        $siswa = $this->buatSiswa($tahunAjaran, $faker);
        $this->buatPendaftaran($siswa, $ekskul);

        // Periode Agustus–November 2026 (pola 23 bulan lalu – 22 bulan ini), masing-masing membuat tagihan bulannya
        foreach (['2026-08', '2026-09', '2026-10', '2026-11'] as $bulan) {
            $awal = Carbon::parse("{$bulan}-01");
            $periode = PeriodeLaporan::create([
                'bulan' => $awal->toDateString(),
                'tanggal_mulai' => $awal->copy()->subMonth()->day(23)->toDateString(),
                'tanggal_selesai' => $awal->copy()->day(22)->toDateString(),
            ]);
            $this->tagihan->buatUntukPeriode($periode);
        }

        $this->buatRiwayatPembayaran();

        Carbon::setTestNow(); // kembalikan jam ke waktu sebenarnya
        $this->command->info('Data contoh Les & Ekskul selesai dibuat.');
    }

    private function buatKegiatan(TahunAjaran $tahunAjaran): Collection
    {
        foreach ($tahunAjaran->kelas as $kelas) {
            LesTambahan::create(['kelas_id' => $kelas->id, 'biaya' => $kelas->jenjang <= 3 ? 150000 : 180000]);
        }

        return collect([
            ['nama' => 'Basket', 'biaya' => 80000, 'kuota' => 40],
            ['nama' => 'English Club', 'biaya' => 100000, 'kuota' => 50],
            ['nama' => 'Swimming', 'biaya' => 150000, 'biaya_non_honor' => 50000, 'kuota' => 30],
            ['nama' => 'Coding', 'biaya' => 120000, 'kuota' => 25, 'jenjang_min' => 4, 'jenjang_max' => 6],
            ['nama' => 'Olimpiade MTK', 'biaya' => 0, 'honor_flat' => true, 'kuota' => 20, 'jenjang_min' => 4, 'jenjang_max' => 6],
        ])->map(fn (array $data) => Ekstrakurikuler::create(['tahun_ajaran_id' => $tahunAjaran->id, ...$data]));
    }

    // 4 siswa fiktif per kelas; siswa pertama di dua kelas pertama adalah anak guru (diskon 50%)
    private function buatSiswa(TahunAjaran $tahunAjaran, $faker): Collection
    {
        $guru = Guru::where('status_aktif', true)->take(2)->pluck('id');
        $hasil = collect();

        foreach ($tahunAjaran->kelas()->orderBy('nama_kelas')->get() as $kelas) {
            foreach (range(1, 4) as $i) {
                $jk = $faker->randomElement(['L', 'P']);
                $siswa = Siswa::create([
                    'nama' => $faker->firstName($jk === 'L' ? 'male' : 'female').' '.$faker->lastName(),
                    'jenis_kelamin' => $jk,
                    'anak_guru_id' => $i === 1 ? $guru->shift() : null, // shift() mengambil lalu membuang id pertama
                ]);
                SiswaKelas::create(['siswa_id' => $siswa->id, 'kelas_id' => $kelas->id]);
                $hasil->push($siswa->setRelation('kelasDemo', $kelas));
            }
        }

        return $hasil;
    }

    private function buatPendaftaran(Collection $siswa, Collection $ekskul): void
    {
        foreach ($siswa as $i => $s) {
            $kelas = $s->getRelation('kelasDemo');
            $tanggalDaftar = $i % 15 === 7 ? '2026-09-10' : '2026-07-'.(15 + $i % 8); // beberapa siswa daftar terlambat

            if ($i % 4 !== 3) { // ±75% ikut les tambahan kelasnya
                Pendaftaran::create([
                    'siswa_id' => $s->id,
                    'les_tambahan_id' => LesTambahan::where('kelas_id', $kelas->id)->value('id'),
                    'tanggal_daftar' => $tanggalDaftar,
                ]);
            }

            $boleh = $ekskul->filter(fn (Ekstrakurikuler $e) => $e->jenjang_min === null
                || ($kelas->jenjang >= $e->jenjang_min && $kelas->jenjang <= $e->jenjang_max))->values();

            foreach ($boleh->random($i % 5 === 0 ? 2 : 1) as $e) { // ekskul wajib minimal satu (FR-33)
                Pendaftaran::create(['siswa_id' => $s->id, 'ekstrakurikuler_id' => $e->id, 'tanggal_daftar' => $tanggalDaftar]);
            }
        }

        // Satu contoh pemberhentian les di tengah bulan (FR-10): Oktober tetap ditagih, November tidak
        Pendaftaran::whereNotNull('les_tambahan_id')->orderBy('id')->skip(3)->first()
            ?->update(['status' => 'berhenti', 'tanggal_efektif_berhenti' => '2026-10-15', 'alasan_berhenti' => 'Contoh: les dihentikan orang tua']);
    }

    // Pembayaran per siswa per bulan: sebagian besar lunas, sebagian menunggak atau membayar sebagian
    private function buatRiwayatPembayaran(): void
    {
        $tu = User::role('tu')->firstOrFail();
        $bendahara = User::role('bendahara')->firstOrFail();
        $nomor = 0;

        foreach (['2026-08', '2026-09', '2026-10', '2026-11'] as $bulan) {
            $tagihanPerSiswa = Tagihan::where('bulan_tagihan', "{$bulan}-01")->with('pendaftaran')->get()
                ->groupBy('pendaftaran.siswa_id');

            foreach ($tagihanPerSiswa as $siswaId => $tagihan) {
                $nomor++;
                $nasib = $nomor % 10;

                if ($nasib === 0 || ($bulan === '2026-11' && $nasib >= 5)) {
                    continue; // menunggak (November: banyak yang belum membayar)
                }

                $belum = $tagihan->where('status', StatusTagihan::Belum);
                $total = $belum->sum(fn (Tagihan $t) => $t->sisa());
                if ($total === 0) {
                    continue;
                }

                $metode = $nasib % 2 === 0 ? MetodeTransaksi::Transfer : MetodeTransaksi::Cash;
                $nominal = $nasib === 9 ? intdiv($total, 2) : $total; // bayar sebagian (FR-21a)
                $tanggal = $this->hariKerjaAcak($bulan);
                Carbon::setTestNow($tanggal->setTime(9, 0)); // laporan harian mengikuti tanggal pencatatan

                $t = $this->transaksi->catat(
                    siswa: Siswa::findOrFail($siswaId),
                    metode: $metode,
                    nominal: $nominal,
                    tagihanIds: $belum->pluck('id')->all(),
                    oleh: $tu,
                    tanggal: $tanggal,
                    catatanTransfer: $metode === MetodeTransaksi::Transfer ? 'Transfer a.n. orang tua' : null,
                );

                // Transfer bulan-bulan lalu sudah diverifikasi TU dan disetujui Bendahara; November sebagian masih pending
                if ($metode === MetodeTransaksi::Transfer && ($bulan !== '2026-11' || $nasib === 2)) {
                    $this->transaksi->verifikasi($t, $tu);
                    if ($bulan !== '2026-11') {
                        $this->transaksi->setujui($t, $bendahara);
                    }
                }
            }
        }

        $this->contohKhusus($tu, $bendahara);
    }

    // Kasus khusus untuk demo: kelebihan transfer → refund, transfer ditolak, transfer tambahan Bendahara (FR-8c)
    private function contohKhusus(User $tu, User $bendahara): void
    {
        Carbon::setTestNow('2026-11-05 10:00');

        $nov = Tagihan::where('bulan_tagihan', '2026-11-01')->where('status', StatusTagihan::Belum)->with('pendaftaran')->get();

        if ($tagihan = $nov->first()) { // Lebih Rp50.000 dari tagihan November → melewati akhir semester → refund
            $this->transaksi->catat(
                siswa: $tagihan->pendaftaran->siswa, metode: MetodeTransaksi::Transfer,
                nominal: $tagihan->sisa() + 50000, tagihanIds: [$tagihan->id], oleh: $tu,
                catatanTransfer: 'Transfer lebih',
            );
        }

        if ($tagihan = $nov->get(1)) { // Bukti transfer tidak ada di mutasi rekening → ditolak (FR-8d)
            $t = $this->transaksi->catat(
                siswa: $tagihan->pendaftaran->siswa, metode: MetodeTransaksi::Transfer,
                nominal: $tagihan->sisa(), tagihanIds: [$tagihan->id], oleh: $tu,
            );
            $this->transaksi->tolak($t, $bendahara, 'Tidak ditemukan di mutasi rekening');
        }

        if ($tagihan = $nov->get(2)) { // Transfer tanpa bukti ke TU, ditemukan Bendahara (FR-8c)
            $this->transaksi->catat(
                siswa: $tagihan->pendaftaran->siswa, metode: MetodeTransaksi::Transfer,
                nominal: $tagihan->sisa(), tagihanIds: [$tagihan->id], oleh: $bendahara,
                sumber: SumberInput::Bendahara, tanggal: '2026-11-03',
            );
        }
    }

    // Senin–Jumat acak di dalam periode bulan itu (23 bulan lalu – 22); November dibatasi sampai 5 November
    private function hariKerjaAcak(string $bulan): Carbon
    {
        $awal = Carbon::parse("{$bulan}-01")->subMonth()->day(23);
        $akhir = $bulan === '2026-11' ? Carbon::parse('2026-11-05') : Carbon::parse("{$bulan}-22");

        do {
            $tanggal = $awal->copy()->addDays(mt_rand(0, (int) $awal->diffInDays($akhir)));
        } while ($tanggal->isWeekend());

        return $tanggal;
    }
}
