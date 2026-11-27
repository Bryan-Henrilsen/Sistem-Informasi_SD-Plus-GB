<?php

namespace Database\Seeders;

use App\Domains\JurnalGuru\Models\ForumPesan;
use App\Domains\JurnalGuru\Services\ForumService;
use App\Domains\LesEkskul\Enums\KategoriKas;
use App\Domains\LesEkskul\Enums\KategoriPendamping;
use App\Domains\LesEkskul\Enums\MetodeTransaksi;
use App\Domains\LesEkskul\Enums\PeranHonor;
use App\Domains\LesEkskul\Enums\StatusLaporanHarian;
use App\Domains\LesEkskul\Enums\StatusPeriodeLaporan;
use App\Domains\LesEkskul\Enums\StatusTransaksi;
use App\Domains\LesEkskul\Enums\SumberInput;
use App\Domains\LesEkskul\Models\Ekstrakurikuler;
use App\Domains\LesEkskul\Models\HonorDetail;
use App\Domains\LesEkskul\Models\HonorFlatJenis;
use App\Domains\LesEkskul\Models\HonorFlatPengaturan;
use App\Domains\LesEkskul\Models\JadwalEkskul;
use App\Domains\LesEkskul\Models\KonfirmasiTransfer;
use App\Domains\LesEkskul\Models\LaporanHarian;
use App\Domains\LesEkskul\Models\LesTambahan;
use App\Domains\LesEkskul\Models\Pendaftaran;
use App\Domains\LesEkskul\Models\PenerimaPeranHonor;
use App\Domains\LesEkskul\Models\PengajarEkskul;
use App\Domains\LesEkskul\Models\PenyesuaianDana;
use App\Domains\LesEkskul\Models\PeriodeLaporan;
use App\Domains\LesEkskul\Models\Tagihan;
use App\Domains\LesEkskul\Models\Transaksi;
use App\Domains\LesEkskul\Services\AkunOrangTuaService;
use App\Domains\LesEkskul\Services\HonorService;
use App\Domains\LesEkskul\Services\KegiatanLiburService;
use App\Domains\LesEkskul\Services\KonfirmasiTransferService;
use App\Domains\LesEkskul\Services\LaporanKasService;
use App\Domains\LesEkskul\Services\LaporanService;
use App\Domains\LesEkskul\Services\PendaftaranService;
use App\Domains\LesEkskul\Services\PengajarEkskulService;
use App\Domains\LesEkskul\Services\PengaturanHonorService;
use App\Domains\LesEkskul\Services\PeriodeLaporanService;
use App\Domains\LesEkskul\Services\PerubahanPendaftaranService;
use App\Domains\LesEkskul\Services\TagihanService;
use App\Domains\LesEkskul\Services\TransaksiService;
use App\Models\Guru;
use App\Models\Kelas;
use App\Models\Siswa;
use App\Models\SiswaKelas;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Support\NomorHp;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Faker\Factory as Faker;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

// DATA DEMO untuk uji coba dan demonstrasi ke Kepala Sekolah, Bendahara, TU, orang tua, dan guru — bukan data sekolah.
// Nama siswa, orang tua, dan pengajar eksternal fiktif; biaya kegiatan perkiraan. Panduan: docs/Panduan_Demo.md.
//
// MENGOSONGKAN database lalu mengisi ulang:  php artisan migrate:fresh --seed --seeder=DataDemoSeeder
//
// Riwayat Agustus s.d. hari ini dibuat lewat service aplikasi dengan jam yang disimulasikan (setTestNow), jadi tagihan,
// laporan harian, tutup buku, honor, dan kas saling cocok seperti hasil pemakaian sungguhan. Keadaan akhir:
// - Agustus & September: buku ditutup, honor dihitung, "Sudah Diketahui" Kepala Sekolah, kas manual tercatat
// - Oktober: buku ditutup, honor dihitung, BELUM diketahui (demo Kepala Sekolah)
// - November: laporan bulanan diajukan TU, menunggu tutup buku (demo Bendahara)
// - Desember (berjalan): laporan harian kemarin menunggu Bendahara, hari ini masih draft dengan transfer pending (demo TU)
// Harus dijalankan antara 24 November dan 22 Desember 2026 (di dalam periode Desember).
class DataDemoSeeder extends Seeder
{
    // Periode pertama dibuka TU 1 Agustus mulai tanggal itu (#39); periode berikutnya menyambung 23 – 22
    private const PERIODE_PERTAMA = ['2026-08-01', '2026-08-22'];

    private const SELESAI_PERIODE = ['2026-08-22', '2026-09-22', '2026-10-22', '2026-11-22', '2026-12-22'];

    private const SISWA_PER_KELAS = 9;

    private User $tu;

    private User $bendahara;

    private User $kepsek;

    private CarbonImmutable $hariIni;

    private CarbonImmutable $hariDraft; // hari kerja terakhir: laporannya masih draft

    private CarbonImmutable $hariDiajukan; // hari kerja sebelumnya: laporannya menunggu Bendahara

    private Collection $kegiatan; // nama => Ekstrakurikuler

    private array $pola = []; // siswa_id => pola pembayaran

    private array $siswaKhusus = []; // kunci => Siswa (anak akun orang tua demo)

    private array $ortu = []; // kunci => User

    private array $rencana = []; // 'Y-m-d' => daftar pembayaran

    private array $acara = []; // 'Y-m-d' => daftar callable kejadian khusus (dijalankan pagi hari)

    private array $transferHariIni = [];

    private array $transferDitolak = []; // transaksi_id => alasan (ditolak Bendahara saat pengecekan)

    public function __construct(
        private TagihanService $tagihan,
        private TransaksiService $transaksi,
        private LaporanService $laporan,
        private PeriodeLaporanService $periode,
        private PendaftaranService $pendaftaran,
        private PengajarEkskulService $pengajar,
        private PengaturanHonorService $pengaturanHonor,
        private HonorService $honor,
        private LaporanKasService $kas,
        private KegiatanLiburService $libur,
        private KonfirmasiTransferService $konfirmasi,
        private PerubahanPendaftaranService $perubahan,
        private AkunOrangTuaService $akunOrangTua,
        private ForumService $forum,
    ) {}

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command->warn('Data demo tidak boleh dijalankan di produksi.');

            return;
        }

        $this->tentukanHari();

        try {
            $this->jam('2026-07-13 08:00');
            $this->call(DatabaseSeeder::class);

            $this->tu = User::where('email', 'serafina@sdplus.test')->firstOrFail();
            $this->bendahara = User::role('bendahara')->firstOrFail(); // Angela (juga guru & Waka Kurikulum)
            $this->kepsek = User::role('kepala_sekolah')->firstOrFail();

            $faker = Faker::create('id_ID');
            $faker->seed(2026); // hasil acak selalu sama setiap kali seeder dijalankan
            mt_srand(2026);

            $tahunAjaran = TahunAjaran::aktif()->firstOrFail();
            $this->buatKegiatan($tahunAjaran);
            $this->aturHonor($tahunAjaran);
            $this->buatSiswa($tahunAjaran, $faker);
            $this->buatOrangTua();

            $this->jam('2026-07-14 09:00');
            $this->pendaftaran->simpanPeriode('2026-07-13', '2026-07-22');
            $this->buatPendaftaran();

            $this->jadwalkanAcara();
            $this->simulasi();

            $this->jam($this->hariIni->format('Y-m-d').' 08:00');
            $this->call(DataContohJadwalGuruSeeder::class);
        } finally {
            $this->jam(null); // kembalikan jam ke waktu sebenarnya
        }

        $this->ringkasan();
    }

    // ---------- Persiapan ----------

    private function tentukanHari(): void
    {
        $this->hariIni = CarbonImmutable::today();

        if (! $this->hariIni->between('2026-11-24', '2026-12-22')) {
            throw new RuntimeException('DataDemoSeeder dirancang untuk dijalankan 24 November – 22 Desember 2026 (periode Desember). Sesuaikan tanggal periode di seeder.');
        }

        $hariKerja = collect();
        for ($h = CarbonImmutable::parse('2026-11-23'); $h->lte($this->hariIni); $h = $h->addDay()) {
            if ($h->isWeekday()) {
                $hariKerja->push($h);
            }
        }

        $this->hariDraft = $hariKerja->last();
        $this->hariDiajukan = $hariKerja->get($hariKerja->count() - 2);
    }

    private function jam(?string $waktu): void
    {
        $t = $waktu ? CarbonImmutable::parse($waktu) : null;
        Carbon::setTestNow($t);
        CarbonImmutable::setTestNow($t);
    }

    private function buatKegiatan(TahunAjaran $tahunAjaran): void
    {
        foreach ($tahunAjaran->kelas as $kelas) {
            LesTambahan::create(['kelas_id' => $kelas->id, 'biaya' => $kelas->jenjang <= 3 ? 150000 : 180000]);
        }

        $buat = fn (array $data) => Ekstrakurikuler::create(['tahun_ajaran_id' => $tahunAjaran->id, ...$data]);

        $this->kegiatan = collect([
            $buat(['nama' => 'Basket', 'biaya' => 80000, 'kuota' => 40, 'kategori_pendamping' => KategoriPendamping::Olahraga]),
            $buat(['nama' => 'English Club', 'biaya' => 100000, 'kuota' => 45]),
            $buat(['nama' => 'Swimming', 'biaya' => 150000, 'biaya_non_honor' => 50000, 'kuota' => 30, 'kategori_pendamping' => KategoriPendamping::Olahraga]),
            $buat(['nama' => 'Coding', 'biaya' => 120000, 'kuota' => 25, 'jenjang_min' => 4, 'jenjang_max' => 6]),
            $buat(['nama' => 'Mandarin Club', 'biaya' => 100000, 'kuota' => 25, 'jenjang_min' => 1, 'jenjang_max' => 3]),
            $buat(['nama' => 'Olimpiade MTK', 'biaya' => 0, 'honor_flat' => true, 'kuota' => 20, 'jenjang_min' => 4, 'jenjang_max' => 6]),
            $buat(['nama' => 'Bina Karakter', 'biaya' => 0, 'wajib_otomatis' => true, 'jenjang_min' => 1, 'jenjang_max' => 3]),
            $buat(['nama' => 'Pramuka', 'biaya' => 0, 'wajib_otomatis' => true, 'jenjang_min' => 4, 'jenjang_max' => 6]),
        ])->keyBy('nama');

        $english = $this->kegiatan['English Club'];
        foreach (['Starter', 'Mover', 'Flyer'] as $tingkat) {
            $this->kegiatan["English Club {$tingkat}"] = $buat([
                'parent_ekstrakurikuler_id' => $english->id, 'nama' => "English Club {$tingkat}", 'biaya' => $english->biaya, 'kuota' => 15,
            ]);
        }

        // hari: 1 = Senin … 6 = Sabtu; jenjang diisi bila jamnya beda per jenjang. Olimpiade sengaja sejam dengan English Club (contoh bentrok)
        $jadwal = [
            'Basket' => [[6, '07:00', '08:00', 1, 3, 'Lapangan'], [6, '08:00', '09:00', 4, 6, 'Lapangan']],
            'English Club' => [[5, '14:00', '15:00', null, null, 'Ruang kelas']],
            'Swimming' => [[6, '15:30', '17:00', null, null, 'Kolam renang']],
            'Coding' => [[5, '15:00', '16:00', null, null, 'Lab komputer']],
            'Mandarin Club' => [[4, '14:00', '15:00', null, null, 'Ruang musik']],
            'Olimpiade MTK' => [[5, '14:00', '15:00', null, null, 'Perpustakaan']],
            'Bina Karakter' => [[3, '13:00', '14:00', null, null, 'Aula']],
            'Pramuka' => [[6, '09:30', '11:30', null, null, 'Lapangan']],
        ];

        foreach ($jadwal as $nama => $daftar) {
            foreach ($daftar as [$hari, $mulai, $selesai, $min, $max, $lokasi]) {
                JadwalEkskul::create([
                    'ekstrakurikuler_id' => $this->kegiatan[$nama]->id, 'hari' => $hari, 'jam_mulai' => $mulai, 'jam_selesai' => $selesai,
                    'jenjang_min' => $min, 'jenjang_max' => $max, 'lokasi' => $lokasi,
                ]);
            }
        }

        // Pengajar: eksternal wajib punya guru pendamping; English Club dihitung gabungan (tiga pengajar internal)
        $guru = fn (string $kode) => Guru::where('kode_guru', $kode)->firstOrFail()->user;
        $internal = fn (string $kode, string $hp) => $this->pengajar->simpanPengajar(['jenis' => 'internal', 'user_id' => $guru($kode)->id, 'no_hp' => $hp]);
        $eksternal = fn (string $nama, string $hp) => $this->pengajar->simpanPengajar(['jenis' => 'eksternal', 'nama' => $nama, 'no_hp' => $hp]);

        $atur = [
            'Basket' => [$eksternal('Julianus Hartono', '081234500101'), 'KR'],
            'Swimming' => [$eksternal('Daniel Suryanto', '081234500102'), 'PAU'],
            'Coding' => [$internal('LYD', '081234500103'), null],
            'English Club Starter' => [$internal('FA', '081234500104'), null],
            'English Club Mover' => [$internal('CIN', '081234500105'), null],
            'English Club Flyer' => [$internal('YN', '081234500106'), null],
            'Mandarin Club' => [$internal('LS', '081234500107'), null],
            'Olimpiade MTK' => [$internal('MG', '081234500108'), null],
        ];
        $internal('EK', '081234500109'); // pengganti pengajar Mandarin mulai Oktober

        foreach ($atur as $nama => [$pengajar, $pendamping]) {
            $this->pengajar->aturPengajar($this->kegiatan[$nama], [$pengajar->id]);
            if ($pendamping) {
                $this->pengajar->aturPendamping($this->kegiatan[$nama], [$guru($pendamping)->id]);
            }
        }
    }

    // Skema persen dari SkemaHonorSeeder; di sini penerima peran pengelola dan honor flat, berlaku sejak awal tahun ajaran
    private function aturHonor(TahunAjaran $tahunAjaran): void
    {
        $penerima = [
            PeranHonor::PenanggungJawab->value => $this->kepsek->id,
            PeranHonor::Bendahara->value => $this->bendahara->id,
            PeranHonor::AdminTu->value => $this->tu->id,
            PeranHonor::AdminIt->value => User::where('email', 'bryan@sdplus.test')->value('id'),
        ];
        foreach ($penerima as $peran => $userId) {
            PenerimaPeranHonor::create(['peran' => $peran, 'user_id' => $userId, 'berlaku_sejak' => $tahunAjaran->tanggal_mulai, 'diatur_oleh' => $this->kepsek->id]);
        }

        $user = fn (string $kode) => Guru::where('kode_guru', $kode)->firstOrFail()->user_id;
        $kebersihan = $this->pengaturanHonor->tambahPenerimaTanpaAkun('Yohanes Ladi (Petugas Kebersihan)');

        // [kode jenis, nominal per orang, [user_id => jumlah]]
        $flat = [
            ['olimpiade', 150000, [$user('MG') => 1]],
            ['bina_karakter', 100000, [$user('LYD') => 1, $user('DV') => 1, $user('AY') => 1]],
            ['pramuka', 150000, [$user('KR') => 1, $user('RY') => 1]],
            ['pembuat_soal_les', 25000, [$user('YV') => 2, $user('MG') => 3, $user('IM') => 2, $user('TK') => 2]],
            ['petugas_kebersihan', 200000, [$kebersihan->id => 1]],
        ];

        foreach ($flat as [$kode, $nominal, $daftar]) {
            $pengaturan = HonorFlatPengaturan::create([
                'honor_flat_jenis_id' => HonorFlatJenis::where('kode', $kode)->value('id'),
                'tahun_ajaran_id' => $tahunAjaran->id,
                'berlaku_sejak' => $tahunAjaran->tanggal_mulai,
                'nominal_per_guru' => $nominal,
                'diatur_oleh' => $this->kepsek->id,
            ]);
            $pengaturan->penerima()->sync(collect($daftar)->map(fn ($jumlah) => ['jumlah' => $jumlah])->all());
        }
    }

    // 9 siswa fiktif per kelas. Slot pertama beberapa kelas = anak akun orang tua demo; dua siswa anak guru (diskon 50%)
    private function buatSiswa(TahunAjaran $tahunAjaran, $faker): void
    {
        $khusus = [
            '1A' => ['titi', 'Titi Tarihoran', 'P'],
            '4A' => ['theo', 'Theo Tarihoran', 'L'],
            '2B' => ['nathan', 'Nathan Wijaya', 'L'],
            '3A' => ['clara', 'Clara Gunawan', 'P'],
            '5B' => ['kevin', 'Kevin Lestari', 'L'],
        ];
        $anakGuru = ['2A' => 'DV', '6A' => 'PAU'];
        $dipakai = array_column($khusus, 1);

        foreach ($tahunAjaran->kelas()->orderBy('nama_kelas')->get() as $kelas) {
            foreach (range(1, self::SISWA_PER_KELAS) as $i) {
                if ($i === 1 && isset($khusus[$kelas->nama_kelas])) {
                    [$kunci, $nama, $jk] = $khusus[$kelas->nama_kelas];
                } else {
                    $kunci = null;
                    $jk = $faker->randomElement(['L', 'P']);
                    do { // nama tidak boleh sama dengan siswa lain (mis. anak akun orang tua demo)
                        $nama = $faker->firstName($jk === 'L' ? 'male' : 'female').' '.$faker->lastName();
                    } while (in_array($nama, $dipakai, true));
                }
                $dipakai[] = $nama;

                $siswa = Siswa::create([
                    'nama' => $nama,
                    'jenis_kelamin' => $jk,
                    'anak_guru_id' => $i === 2 && isset($anakGuru[$kelas->nama_kelas])
                        ? Guru::where('kode_guru', $anakGuru[$kelas->nama_kelas])->value('id') : null,
                ]);
                SiswaKelas::create(['siswa_id' => $siswa->id, 'kelas_id' => $kelas->id]);

                if ($kunci) {
                    $this->siswaKhusus[$kunci] = $siswa;
                    $this->pola[$siswa->id] = 'khusus';
                } else {
                    $r = mt_rand(1, 100);
                    $this->pola[$siswa->id] = match (true) {
                        $r <= 62 => 'tertib', // membayar tiap bulan di periodenya
                        $r <= 82 => 'telat', // membayar sebulan terlambat
                        $r <= 90 => 'menunggak', // berhenti membayar sejak Oktober
                        $r <= 95 => 'sebagian', // Oktober membayar sebagian
                        default => 'dimuka', // melunasi Agustus–November sekaligus di Agustus
                    };
                }
            }
        }
    }

    // Akun orang tua utama dari .env (config/demo.php); tanpa isian dipakai akun fiktif. Tiga orang tua fiktif lain
    private function buatOrangTua(): void
    {
        $utama = config('demo.ortu');
        $hp = NomorHp::normal($utama['no_hp'] ?? null);

        $daftar = [
            'utama' => [$hp ? ($utama['nama'] ?: 'Orang Tua Demo') : 'Orang Tua Demo', $hp ?? '081200000100',
                $hp ? ($utama['email'] ?: null) : null, $hp ? ($utama['password'] ?: 'password') : 'password', ['titi', 'theo']],
            'yohana' => ['Yohana Wijaya', '081200000101', null, 'password', ['nathan']],
            'hendra' => ['Hendra Gunawan', '081200000102', null, 'password', ['clara']],
            'maria' => ['Maria Lestari', '081200000103', null, 'password', ['kevin']],
        ];

        foreach ($daftar as $kunci => [$nama, $noHp, $email, $password, $anak]) {
            $user = User::create(['name' => $nama, 'no_hp' => $noHp, 'email' => $email, 'password' => $password]);
            $user->assignRole('orang_tua');
            foreach ($anak as $a) {
                $this->akunOrangTua->klaim($user, $this->siswaKhusus[$a]);
            }
            $this->ortu[$kunci] = $user;
        }
    }

    private function buatPendaftaran(): void
    {
        $k = $this->kegiatan;
        $khusus = [
            'titi' => ['Basket'],
            'theo' => ['English Club', 'Coding'],
            'nathan' => ['Mandarin Club'],
            'clara' => ['Swimming', 'English Club'],
            'kevin' => ['Basket'],
        ];
        foreach ($khusus as $kunci => $ekskul) {
            $siswa = $this->siswaKhusus[$kunci];
            $this->daftar($siswa, $this->lesKelas($siswa), '2026-07-15');
            foreach ($ekskul as $nama) {
                $this->daftar($siswa, $k[$nama], '2026-07-15');
            }
        }

        $pilihan = $k->only(['Basket', 'English Club', 'Swimming', 'Coding', 'Mandarin Club', 'Olimpiade MTK'])->values();
        $khususIds = collect($this->siswaKhusus)->pluck('id');

        foreach (Siswa::whereNotIn('id', $khususIds)->orderBy('id')->get() as $i => $siswa) {
            $tanggal = $i % 17 === 5 ? '2026-09-10' : '2026-07-'.(15 + $i % 8); // beberapa siswa mendaftar terlambat

            if ($i % 4 !== 3) { // ±75% ikut les tambahan kelasnya
                $this->daftar($siswa, $this->lesKelas($siswa), $tanggal);
            }

            // Ekskul minimal satu (FR-33); sebagian dua. Kuota penuh / bentrok jadwal / beda jenjang dilewati
            $mau = $i % 5 === 0 ? 2 : 1;
            foreach ($pilihan->shuffle(mt_rand()) as $ekskul) {
                if ($mau > 0 && $this->daftar($siswa, $ekskul, $tanggal)) {
                    $mau--;
                }
            }
        }

        // Kelas paralel English Club menurut jenjang; yang kelasnya penuh tetap di kegiatan induk (honor digabung)
        $tujuan = [1 => 'Starter', 2 => 'Starter', 3 => 'Mover', 4 => 'Mover', 5 => 'Flyer', 6 => 'Flyer'];
        foreach ($k['English Club']->pendaftaran()->with('siswa.kelasTahunAktif.kelas')->get() as $p) {
            try {
                $this->pendaftaran->assignKelasParalel($p, $k['English Club '.$tujuan[$p->siswa->kelasTahunAktif->kelas->jenjang]]);
            } catch (ValidationException) {
                // kelas paralel penuh
            }
        }
    }

    private function daftar(Siswa $siswa, LesTambahan|Ekstrakurikuler $kegiatan, string $tanggal): bool
    {
        try {
            $this->pendaftaran->daftar($siswa, $kegiatan, $tanggal);

            return true;
        } catch (ValidationException) {
            return false;
        }
    }

    private function lesKelas(Siswa $siswa): LesTambahan
    {
        return LesTambahan::where('kelas_id', $siswa->kelasTahunAktif->kelas_id)->firstOrFail();
    }

    // ---------- Kejadian khusus (dijalankan pagi hari, sebelum transaksi hari itu) ----------

    private function jadwalkanAcara(): void
    {

        // Siswa berhenti Basket mulai Oktober (FR-10): tagihan Oktober dibatalkan
        $this->acara['2026-09-25'][] = function () {
            $p = $this->kegiatan['Basket']->pendaftaran()->whereNotIn('siswa_id', collect($this->siswaKhusus)->pluck('id'))->orderBy('id')->skip(2)->first();
            $this->perubahan->berhenti($p, '2026-10-01', 'Anak mengikuti les renang di luar sekolah', true, $this->tu);
        };

        // Pergantian pengajar Mandarin Club di tengah Oktober (#50): honor Oktober dibagi per pertemuan
        $this->acara['2026-10-09'][] = function () {
            $mandarin = $this->kegiatan['Mandarin Club'];
            $lama = $mandarin->pengajar()->firstOrFail();
            $baru = PengajarEkskul::whereHas('user.guru', fn (Builder $q) => $q->where('kode_guru', 'EK'))->firstOrFail();
            $this->pengajar->gantiPengajar($mandarin, $lama->id, '2026-10-08', $baru->id, '2026-10-15');
        };

        // Swimming tidak berlangsung November (FR-23): tagihan November dibatalkan
        $this->acara['2026-10-26'][] = fn () => $this->libur->tandai(
            ['kegiatan' => 'ekskul-'.$this->kegiatan['Swimming']->id, 'bulan' => '2026-11', 'alasan' => 'Kolam renang sedang dirawat'],
            $this->tu,
        );

        // Konfirmasi "Saya sudah bayar" Clara (Oktober) dicatat TU keesokan harinya
        $this->acara['2026-10-13'][] = fn () => $this->ajukanKonfirmasi('hendra', 'clara', '2026-10');
        $this->acara['2026-10-14'][] = fn () => $this->catatKonfirmasiMenunggu('clara');

        // Konfirmasi Kevin ditolak TU: transfer belum masuk ke rekening sekolah
        $this->acara['2026-11-10'][] = fn () => $this->ajukanKonfirmasi('maria', 'kevin', '2026-10');
        $this->acara['2026-11-11'][] = function () {
            $k = KonfirmasiTransfer::menunggu()->firstWhere('siswa_id', $this->siswaKhusus['kevin']->id);
            $this->konfirmasi->tolak($k, $this->tu, 'Transfer belum masuk ke rekening sekolah. Mohon kirim ulang bukti transfer ke WhatsApp TU.');
        };

        // Periode pendaftaran semester genap dibuka untuk orang tua
        $this->acara['2026-11-20'][] = fn () => $this->pendaftaran->simpanPeriode('2026-11-23', '2026-12-12');

        // Konfirmasi yang masih menunggu TU saat demo: Theo (kemarin) dan Kevin (pagi hari ini)
        $this->acara[$this->hariDiajukan->format('Y-m-d')][] = fn () => $this->ajukanKonfirmasi('utama', 'theo', '2026-11', '19:30');
        $this->acara[$this->hariDraft->format('Y-m-d')][] = fn () => $this->ajukanKonfirmasi('maria', 'kevin', '2026-11', '06:45');

        // Forum Guru: percakapan guru dengan Kepala Sekolah
        $this->acara['2026-11-16'][] = fn () => $this->isiForum();
    }

    private function ajukanKonfirmasi(string $ortu, string $anak, string $sampaiBulan, ?string $jam = null): void
    {
        if ($jam) {
            $this->jam(today()->format('Y-m-d')." {$jam}");
        }

        $siswa = $this->siswaKhusus[$anak];
        $ids = $this->konfirmasi->tagihanBisaDipilih($siswa)->where('bulan_tagihan', '<=', "{$sampaiBulan}-01")->pluck('id')->all();
        $this->konfirmasi->ajukan($this->ortu[$ortu], $siswa, $ids, today()->toDateString(), $this->ortu[$ortu]->name);
    }

    // TU mencatat konfirmasi yang menunggu menjadi transaksi transfer (alur halaman Konfirmasi Transfer)
    private function catatKonfirmasiMenunggu(string $anak): void
    {
        $siswa = $this->siswaKhusus[$anak];
        $k = KonfirmasiTransfer::menunggu()->firstWhere('siswa_id', $siswa->id);

        $t = $this->konfirmasi->catatTransaksi($k, $this->tu, fn () => $this->transaksi->catat(
            siswa: $siswa, metode: MetodeTransaksi::Transfer, nominal: $k->nominal, tagihanIds: $k->tagihan->pluck('id')->all(),
            oleh: $this->tu, tanggal: $k->tanggal_transfer, catatanTransfer: 'Konfirmasi orang tua: '.$k->nama_pengirim,
        ));
        $this->transferHariIni[] = $t->id;
    }

    // ---------- Simulasi hari demi hari ----------

    private function simulasi(): void
    {
        $this->jam(self::PERIODE_PERTAMA[0].' 07:00');
        [$pertama] = $this->periode->buka(self::PERIODE_PERTAMA[1], self::PERIODE_PERTAMA[0]);
        $this->rencanakan($pertama);

        for ($hari = CarbonImmutable::parse(self::PERIODE_PERTAMA[0]); $hari->lte($this->hariIni); $hari = $hari->addDay()) {
            $tgl = $hari->format('Y-m-d');

            if ($hari->isMonday()) {
                $this->perbaruiStatistik();
            }

            if ($hari->isWeekday()) {
                $this->jam("{$tgl} 07:30");
                $this->bendaharaMemeriksa($hari);
                $this->urusanBulanan($hari);
            }

            // Periode berikutnya dibuka TU sehari setelah periode sebelumnya berakhir (tagihan bulan itu ikut dibuat)
            if (in_array($hari->subDay()->format('Y-m-d'), self::SELESAI_PERIODE, true) && $hari->subDay()->format('Y-m-d') !== '2026-12-22') {
                $this->jam("{$tgl} 07:00");
                [$baru] = $this->periode->buka(self::SELESAI_PERIODE[array_search($hari->subDay()->format('Y-m-d'), self::SELESAI_PERIODE) + 1]);
                $this->rencanakan($baru);
            }

            $this->transferHariIni = []; // transfer TU hari ini, diverifikasi sore hari

            foreach ($this->acara[$tgl] ?? [] as $acara) {
                $this->jam("{$tgl} 08:00");
                $acara();
            }

            if ($hari->isWeekday()) {
                $this->transaksiHarian($hari);
            }
        }
    }

    // Statistik tabel Postgres diperbarui tiap minggu: di dalam satu transaksi panjang (mis. saat tes) autovacuum tidak
    // berjalan, sehingga tanpa ini query makin lambat seiring data bertambah
    private function perbaruiStatistik(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ANALYZE');
        }
    }

    // Rencana pembayaran satu periode: siapa membayar, sampai bulan tagihan mana, kapan, dan caranya
    private function rencanakan(PeriodeLaporan $periode): void
    {
        $bulan = $periode->bulan->toImmutable();
        $akhir = $periode->tanggal_selesai->min($this->hariIni);
        $hariKerja = [];
        for ($h = $periode->tanggal_mulai->toImmutable(); $h->lte($akhir); $h = $h->addDay()) {
            if ($h->isWeekday()) {
                $hariKerja[] = $h->format('Y-m-d');
            }
        }

        $berjalan = $bulan->format('Y-m') === '2026-12';
        $kode = $bulan->format('Y-m');

        foreach ($this->pola as $siswaId => $pola) {
            $r = match ($pola) {
                'tertib' => $berjalan ? null : ['sampai' => $bulan],
                'telat' => match (true) {
                    $kode === '2026-08' => null,
                    $berjalan => mt_rand(0, 1) ? ['sampai' => $bulan->subMonth()] : null, // separuh melunasi November
                    default => ['sampai' => $bulan->subMonth()],
                },
                'menunggak' => in_array($kode, ['2026-08', '2026-09'], true) ? ['sampai' => $bulan] : null,
                'sebagian' => match ($kode) {
                    '2026-10' => ['sampai' => $bulan, 'porsi' => 0.5],
                    '2026-12' => null,
                    default => ['sampai' => $bulan],
                },
                'dimuka' => $kode === '2026-08' ? ['sampai' => CarbonImmutable::parse('2026-11-01'), 'dimuka' => true, 'metode' => MetodeTransaksi::Transfer] : null,
                default => null,
            };

            if ($r) {
                $this->tambahRencana($hariKerja[mt_rand(0, count($hariKerja) - 1)], ['siswa' => $siswaId, ...$r]);
            }
        }

        $this->rencanaKhusus($kode, $hariKerja);
    }

    private function rencanaKhusus(string $kode, array $hariKerja): void
    {
        $s = fn (string $k) => $this->siswaKhusus[$k]->id;
        $bulan = CarbonImmutable::parse("{$kode}-01");
        $transfer = MetodeTransaksi::Transfer;
        $cash = MetodeTransaksi::Cash;

        $rencana = match ($kode) {
            '2026-08' => [
                ['2026-08-05', ['siswa' => $s('titi'), 'sampai' => $bulan, 'metode' => $transfer]],
                ['2026-08-05', ['siswa' => $s('theo'), 'sampai' => $bulan, 'metode' => $cash]],
                // Yohana melunasi Nathan sampai November sekaligus (bayar di muka)
                ['2026-08-04', ['siswa' => $s('nathan'), 'sampai' => CarbonImmutable::parse('2026-11-01'), 'dimuka' => true, 'metode' => $transfer]],
                ['2026-08-12', ['siswa' => $s('clara'), 'sampai' => $bulan, 'metode' => $cash]],
                ['2026-08-13', ['siswa' => $s('kevin'), 'sampai' => $bulan, 'metode' => $cash]],
            ],
            '2026-09' => [
                ['2026-09-03', ['siswa' => $s('titi'), 'sampai' => $bulan, 'metode' => $transfer]],
                ['2026-09-08', ['siswa' => $s('theo'), 'sampai' => $bulan, 'metode' => $transfer]],
                // Hendra transfer lebih Rp50.000 dan memilih dikembalikan: refund, ditandai dikembalikan TU beberapa hari kemudian
                ['2026-09-09', ['siswa' => $s('clara'), 'sampai' => $bulan, 'metode' => $transfer, 'lebih' => 50000, 'refund' => true]],
            ],
            '2026-10' => [
                // Transfer Titi tidak ditemukan di mutasi rekening: ditolak Bendahara, tagihan Oktober kembali belum lunas
                ['2026-10-14', ['siswa' => $s('titi'), 'sampai' => $bulan, 'metode' => $transfer, 'tolak' => 'Tidak ditemukan di mutasi rekening sekolah tanggal 14 Oktober. Mohon cek kembali bukti transfernya.']],
                ['2026-10-07', ['siswa' => $s('theo'), 'sampai' => $bulan, 'metode' => $transfer]],
            ],
            '2026-11' => [
                ['2026-11-04', ['siswa' => $s('clara'), 'sampai' => $bulan, 'metode' => $cash]],
            ],
            '2026-12' => [
                // Kelebihan transfer yang refundnya belum dikembalikan (demo menu Refund)
                [$this->hariDiajukan->format('Y-m-d'), ['siswa' => $this->siswaPola('menunggak', 5), 'sampai' => CarbonImmutable::parse('2026-11-01'), 'metode' => $transfer, 'lebih' => 25000, 'refund' => true]],
                // Laporan kemarin (menunggu Bendahara) berisi cash dan transfer yang sudah diverifikasi TU
                [$this->hariDiajukan->format('Y-m-d'), ['siswa' => $this->siswaPola('menunggak', 6), 'sampai' => CarbonImmutable::parse('2026-11-01'), 'metode' => $transfer]],
                [$this->hariDiajukan->format('Y-m-d'), ['siswa' => $this->siswaPola('menunggak', 7), 'sampai' => CarbonImmutable::parse('2026-10-01'), 'metode' => $cash]],
                [$this->hariDiajukan->format('Y-m-d'), ['siswa' => $this->siswaPola('telat', 2), 'sampai' => CarbonImmutable::parse('2026-11-01'), 'metode' => $transfer]],
                [$this->hariDiajukan->format('Y-m-d'), ['siswa' => $this->siswaPola('menunggak', 8), 'sampai' => CarbonImmutable::parse('2026-11-01'), 'metode' => $cash]],
            ],
            default => [],
        };

        foreach ($rencana as [$tgl, $r]) {
            $this->tambahRencana($tgl, $r);
        }

        // Transfer yang ditemukan Bendahara di mutasi rekening tanpa bukti ke TU (FR-8c)
        if ($kode === '2026-09') {
            $this->tambahRencana('2026-09-16', ['siswa' => $this->siswaPola('telat', 1), 'sampai' => $bulan, 'metode' => $transfer, 'bendahara' => true]);
        }
    }

    private function siswaPola(string $pola, int $ke): int
    {
        $ids = array_keys(array_filter($this->pola, fn ($p) => $p === $pola));

        return $ids[min($ke, count($ids) - 1)];
    }

    private function tambahRencana(string $tgl, array $r): void
    {
        $this->rencana[$tgl][] = $r;
    }

    private function transaksiHarian(CarbonImmutable $hari): void
    {
        $tgl = $hari->format('Y-m-d');

        foreach ($this->rencana[$tgl] ?? [] as $i => $r) {
            $this->jam("{$tgl} ".sprintf('%02d:%02d', 8 + intdiv($i, 6), ($i % 6) * 9 + 3));
            $this->bayar($r);
        }

        // Hari terakhir (hari demo) tetap draft dengan transfer pending; selain itu TU memverifikasi transfer lalu mengajukan laporan
        if ($hari->isSameDay($this->hariDraft)) {
            $this->tambahanHariDemo();

            return;
        }

        $this->jam("{$tgl} 14:00");
        foreach (Transaksi::whereKey($this->transferHariIni)->where('status', StatusTransaksi::Pending)->get() as $t) {
            $this->transaksi->verifikasi($t, $this->tu);
        }

        $laporan = LaporanHarian::where('tanggal', $tgl)->first();
        if ($laporan && in_array($laporan->status, [StatusLaporanHarian::Draft, StatusLaporanHarian::Ditolak], true)) {
            $this->jam("{$tgl} 15:30");
            $this->laporan->ajukanHarian($laporan, $this->tu);
        }
    }

    // Transaksi hari demo: beberapa cash dan transfer yang belum diverifikasi TU
    private function tambahanHariDemo(): void
    {
        $tgl = $this->hariDraft->format('Y-m-d');
        $menunggak = collect(array_keys(array_filter($this->pola, fn ($p) => $p === 'menunggak')))->take(4)->values();

        foreach ($menunggak as $i => $siswaId) {
            $this->jam("{$tgl} ".sprintf('%02d:15', 9 + $i));
            $this->bayar([
                'siswa' => $siswaId,
                'sampai' => CarbonImmutable::parse('2026-10-01'),
                'metode' => $i < 2 ? MetodeTransaksi::Cash : MetodeTransaksi::Transfer,
            ]);
        }
    }

    // Satu pembayaran: tagihan belum lunas sampai bulan rencana, urut bulan terlama
    private function bayar(array $r): void
    {
        $siswa = Siswa::findOrFail($r['siswa']);
        $sampai = $r['sampai']->format('Y-m-d');

        if ($r['dimuka'] ?? false) {
            foreach ($siswa->pendaftaran()->aktif()->get() as $p) {
                $this->tagihan->buatSampai($p, $sampai); // tagihan bulan berikutnya dibuat lebih awal (bayar di muka)
            }
        }

        $tagihan = Tagihan::whereHas('pendaftaran', fn (Builder $q) => $q->where('siswa_id', $siswa->id))
            ->bisaDibayarOnline()
            ->whereDoesntHave('konfirmasiTransfer', fn (Builder $q) => $q->where('status', 'menunggu'))
            ->where('bulan_tagihan', '<=', $sampai)
            ->orderBy('bulan_tagihan')
            ->get();
        $total = $tagihan->sum(fn (Tagihan $t) => $t->sisa());

        if ($total === 0) {
            return;
        }

        // Cara bayar mengikuti rencana bila ditentukan; selain itu acak cash/transfer
        $metode = $r['metode'] ?? (mt_rand(0, 1) ? MetodeTransaksi::Transfer : MetodeTransaksi::Cash);
        $nominal = isset($r['porsi']) ? (int) (floor($total * $r['porsi'] / 1000) * 1000) : $total;
        $nominal += $r['lebih'] ?? 0;
        $dariBendahara = $r['bendahara'] ?? false;

        $t = $this->transaksi->catat(
            siswa: $siswa,
            metode: $metode,
            nominal: $nominal,
            tagihanIds: $tagihan->pluck('id')->all(),
            oleh: $dariBendahara ? $this->bendahara : $this->tu,
            sumber: $dariBendahara ? SumberInput::Bendahara : SumberInput::Tu,
            catatanTransfer: $metode === MetodeTransaksi::Transfer ? ($dariBendahara ? 'Ditemukan di mutasi rekening' : 'Bukti transfer via WhatsApp') : null,
            alihkanKelebihan: ! ($r['refund'] ?? false),
        );

        if ($metode === MetodeTransaksi::Transfer && ! $dariBendahara) {
            $this->transferHariIni[] = $t->id;
        }

        if (isset($r['tolak'])) {
            $this->transferDitolak[$t->id] = $r['tolak'];
        }
    }

    // Pagi hari kerja: Bendahara mencocokkan transfer kemarin dengan mutasi rekening, lalu menyetujui laporan harian.
    // Laporan "hari diajukan" sengaja dibiarkan menunggu Bendahara (demo)
    private function bendaharaMemeriksa(CarbonImmutable $hari): void
    {
        $laporan = LaporanHarian::where('status', StatusLaporanHarian::Diajukan)
            ->where('tanggal', '<', $hari->format('Y-m-d'))
            ->where('tanggal', '!=', $this->hariDiajukan->format('Y-m-d'))
            ->orderBy('tanggal')
            ->get();

        foreach ($laporan as $l) {
            foreach ($l->transaksi()->whereKey(array_keys($this->transferDitolak))->get() as $t) {
                $this->transaksi->tolak($t, $this->bendahara, $this->transferDitolak[$t->id]);
            }

            $this->laporan->setujuiSemuaTransfer($l, $this->bendahara);

            // Satu contoh laporan ditolak Bendahara lalu diajukan ulang TU setelah dicek (alur #12)
            if ($l->tanggal->format('Y-m-d') === '2026-11-11') {
                $this->laporan->tolakHarian($l, $this->bendahara, 'Jumlah cash di laporan berbeda Rp50.000 dengan setoran. Mohon dicek ulang.');
                $this->jam($hari->format('Y-m-d').' 09:00');
                $this->laporan->ajukanHarian($l->fresh(), $this->tu);
                $this->jam($hari->format('Y-m-d').' 10:30');
            }

            $this->laporan->setujuiHarian($l->fresh(), $this->bendahara);
        }

        // Refund yang dicatat lebih dari dua hari lalu sudah ditransfer balik TU; refund hari demo tetap menunggu
        $refund = PenyesuaianDana::where('status', 'proses')
            ->where('tanggal_proses', '<', $hari->subDays(2)->format('Y-m-d'))
            ->where('tanggal_proses', '<', $this->hariDiajukan->format('Y-m-d'))
            ->get()
            ->filter(fn (PenyesuaianDana $r) => $r->adalahRefund());
        foreach ($refund as $r) {
            $this->perubahan->tandaiRefundDikembalikan($r, $this->tu);
        }
    }

    // Laporan bulanan, tutup buku, honor, kas manual, dan "Sudah Diketahui" sesuai rencana keadaan akhir
    private function urusanBulanan(CarbonImmutable $hari): void
    {
        $tgl = $hari->format('Y-m-d');

        foreach (PeriodeLaporan::where('tanggal_selesai', '<', $tgl)->where('bulan', '<=', '2026-11-01')->orderBy('bulan')->get() as $p) {
            $kode = $p->bulan->format('Y-m');

            if ($p->status === StatusPeriodeLaporan::Terbuka && $this->laporan->hambatanAjukan($p) === []) {
                $this->jam("{$tgl} 11:00");
                $this->laporan->ajukanBulanan($p, $this->tu);

                continue; // tutup buku paling cepat hari kerja berikutnya
            }

            if ($p->status === StatusPeriodeLaporan::Diajukan && $kode !== '2026-11' && $p->diajukan_at->lt($hari)) {
                $this->jam("{$tgl} 10:00");
                $this->laporan->tutupBuku($p, $this->bendahara);
                $this->catatKas($p->fresh());

                if (! $this->honor->sudahDihitung($p)) {
                    $this->command->warn("Honor {$kode} belum terhitung: ".implode(' ', $this->honor->hambatan($p)));
                }

                continue;
            }

            if (in_array($kode, ['2026-08', '2026-09'], true) && $p->sudahDitutup() && $p->honor_diketahui_at === null
                && $p->ditutup_at->addWeekdays(2)->lte($hari)) {
                $this->jam("{$tgl} 13:00");
                $this->honor->tandaiDiketahui($p, $this->kepsek);
            }
        }
    }

    // Kas manual Bendahara: saldo awal (periode pertama), uang kolam renang, ATK, admin bank, setoran ke yayasan
    private function catatKas(PeriodeLaporan $p): void
    {
        $mulai = $p->tanggal_mulai->format('Y-m-d');
        $selesai = $p->tanggal_selesai->format('Y-m-d');
        $catat = fn (KategoriKas $k, int $nominal, string $tanggal, ?string $uraian = null) => $this->kas->catat($p, $k, $nominal, $tanggal, $uraian, $this->bendahara);

        if ($p->bulan->format('Y-m') === '2026-08') {
            $catat(KategoriKas::SaldoAwalSekolah, 3500000, $mulai);
            $catat(KategoriKas::SaldoAwalYayasan, 8000000, $mulai);
        }

        // Bagian non-honor Swimming (Rp50.000/siswa) dibayarkan ke pengelola kolam
        $kolam = Tagihan::whereHas('pendaftaran', fn (Builder $q) => $q->where('ekstrakurikuler_id', $this->kegiatan['Swimming']->id))
            ->where('bulan_tagihan', $p->bulan->format('Y-m-d'))->where('status', 'lunas')->count();
        if ($kolam > 0) {
            $catat(KategoriKas::PembayaranKolam, $kolam * 50000, $selesai, null);
        }

        $catat(KategoriKas::Atk, [185000, 142500, 96000][$p->bulan->month - 8] ?? 120000, $p->tanggal_mulai->addDays(10)->format('Y-m-d'));
        $catat(KategoriKas::AdminBank, 12500, $selesai);

        // Porsi yayasan periode sebelumnya disetor ke yayasan
        $sebelumnya = PeriodeLaporan::where('bulan', '<', $p->bulan->format('Y-m-d'))->latest('bulan')->first();
        $yayasan = $sebelumnya ? (int) HonorDetail::where('periode_laporan_id', $sebelumnya->id)->where('peran', PeranHonor::Yayasan)->sum('nominal') : 0;
        if ($yayasan > 0) {
            $catat(KategoriKas::SetorYayasan, $yayasan, $p->tanggal_mulai->addDays(7)->format('Y-m-d'));
        }

        if ($p->bulan->format('Y-m') === '2026-09') {
            $catat(KategoriKas::PengeluaranLain, 350000, $p->tanggal_mulai->addDays(15)->format('Y-m-d'), 'Konsumsi lomba basket antarsekolah');
        }
    }

    private function isiForum(): void
    {
        // [kode guru, [[tanggal jam, dari guru?, isi]], dibaca Kepala Sekolah?]
        $percakapan = [
            ['LYD', [
                ['2026-11-02 13:10', true, 'Selamat siang Pak. Dua komputer di lab sering mati sendiri saat kegiatan Coding. Apakah bisa diperiksa teknisi minggu ini?'],
                ['2026-11-02 15:40', false, 'Siang Bu Lydia. Baik, saya minta teknisi datang hari Kamis. Sementara anak-anak bisa berbagi komputer dulu ya.'],
                ['2026-11-06 14:05', true, 'Terima kasih Pak, komputernya sudah normal kembali.'],
            ], true],
            ['EK', [
                ['2026-11-09 10:20', true, 'Pak, saya sudah dua minggu mengajar Mandarin Club. Anak-anak antusias, tetapi buku latihannya kurang 6 eksemplar.'],
                ['2026-11-09 12:00', false, 'Terima kasih Pak Eky. Silakan ajukan daftar bukunya ke Bu Angela, nanti dibelikan dari kas sekolah.'],
            ], true],
            ['RB', [
                ['2026-11-24 09:15', true, 'Selamat pagi Pak. Izin bertanya, untuk persiapan penilaian akhir semester kelas 4B apakah soal dikumpulkan paling lambat tanggal 5 Desember?'],
            ], false],
            ['AY', [
                ['2026-11-25 13:30', true, 'Pak, orang tua salah satu siswa 2B meminta jadwal remedial. Bolehkah saya memakai ruang kelas setelah pulang sekolah hari Rabu?'],
                ['2026-11-25 13:32', true, 'Kira-kira 30 menit saja Pak.'],
            ], false],
            ['TK', [
                ['2026-11-18 08:00', false, 'Bu Kartika, terima kasih sudah mendampingi lomba cerdas cermat kemarin. Anak-anak tampil sangat baik.'],
            ], false],
        ];

        foreach ($percakapan as [$kode, $pesan, $dibaca]) {
            $guru = Guru::where('kode_guru', $kode)->firstOrFail();
            foreach ($pesan as [$waktu, $dariGuru, $isi]) {
                $this->jam($waktu);
                $this->forum->kirim($guru, $dariGuru ? $guru->user : $this->kepsek, $isi);
            }

            if ($dibaca) {
                ForumPesan::where('guru_id', $guru->id)->update(['dibaca_at' => now()]);
            }
        }

        $this->jam('2026-11-16 08:00');
    }

    private function ringkasan(): void
    {
        $this->command->info('Data demo selesai dibuat.');
        $this->command->table(['Data', 'Jumlah'], [
            ['Siswa', Siswa::count()],
            ['Pendaftaran', Pendaftaran::count()],
            ['Tagihan', Tagihan::count()],
            ['Transaksi', Transaksi::count()],
            ['Laporan harian', LaporanHarian::count()],
            ['Periode', PeriodeLaporan::orderBy('bulan')->get()->map(fn ($p) => $p->bulan->format('M').':'.$p->status->value.($p->honor_diketahui_at ? '+diketahui' : ''))->implode(', ')],
        ]);
    }
}
