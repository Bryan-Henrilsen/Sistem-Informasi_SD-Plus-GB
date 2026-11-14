<?php

namespace App\Domains\LesEkskul\Services;

use App\Domains\LesEkskul\Enums\JenisPenyesuaian;
use App\Domains\LesEkskul\Enums\StatusPembayaranOnline;
use App\Domains\LesEkskul\Enums\StatusPendaftaran;
use App\Domains\LesEkskul\Enums\StatusPenyesuaian;
use App\Domains\LesEkskul\Enums\StatusTagihan;
use App\Domains\LesEkskul\Enums\StatusTransaksi;
use App\Domains\LesEkskul\Models\Ekstrakurikuler;
use App\Domains\LesEkskul\Models\Pendaftaran;
use App\Domains\LesEkskul\Models\PenyesuaianDana;
use App\Domains\LesEkskul\Models\PeriodeLaporan;
use App\Models\Semester;
use App\Models\TahunAjaran;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Berhenti & pindah kegiatan serta refund (FR-10, FR-11, FR-22; Ringkasan v1.11 #20, #21, #29).
// tanggal_efektif_berhenti = hari pertama siswa tidak ikut lagi; bulan yang masih ada hari ikut tetap ditagih dan tidak direfund.
class PerubahanPendaftaranService
{
    public function __construct(
        private TagihanService $tagihan,
        private TransaksiService $transaksi,
        private PendaftaranService $pendaftaran,
    ) {}

    // Les boleh berhenti kapan saja; ekskul di luar batas semester = peringatan (konfirmasi + alasan wajib, #21).
    // Tagihan bulan yang tidak diikuti lagi dibatalkan; yang sudah dibayar direfund (FR-22)
    public function berhenti(Pendaftaran $pendaftaran, string $tanggalEfektif, ?string $alasan, bool $konfirmasi, User $oleh): array
    {
        return DB::transaction(function () use ($pendaftaran, $tanggalEfektif, $alasan, $konfirmasi, $oleh) {
            $pendaftaran = $this->kunciYangAktif($pendaftaran);
            $tanggal = Carbon::parse($tanggalEfektif)->toImmutable()->startOfDay();

            $this->periksaTanggal($pendaftaran, $tanggal);
            $this->periksaBatasSemester($pendaftaran, $tanggal, $alasan, $konfirmasi);

            $pendaftaran->update([
                'status' => StatusPendaftaran::Berhenti,
                'tanggal_efektif_berhenti' => $tanggal->toDateString(),
                'alasan_berhenti' => filled($alasan) ? trim($alasan) : null,
            ]);

            $lepas = $this->lepasTagihan($pendaftaran, $this->bulanPertamaTidakIkut($tanggal));
            $refund = array_sum($lepas['uang']);

            if ($refund > 0) {
                $this->catatPenyesuaian($pendaftaran, JenisPenyesuaian::RefundBerhenti, $refund, $oleh, "Refund berhenti, sudah dibayar: {$lepas['bulan_dibayar']}");
            }

            return ['dibatalkan' => $lepas['jumlah'], 'refund' => $refund];
        });
    }

    // Pindah ekskul ke ekskul lain (les hanya bisa berhenti, #29). Kegiatan tujuan diperiksa dengan aturan pendaftaran biasa
    // (jenjang, duplikasi, bentrok ditolak, kuota penuh = peringatan). Uang bulan yang sudah dibayar menjadi potongan
    // tagihan kegiatan baru; yang melewati akhir semester direfund
    public function pindah(
        Pendaftaran $lama,
        Ekstrakurikuler $tujuan,
        string $tanggalPindah,
        ?string $alasan,
        bool $konfirmasi,
        bool $abaikanKuota,
        User $oleh,
    ): array {
        return DB::transaction(function () use ($lama, $tujuan, $tanggalPindah, $alasan, $konfirmasi, $abaikanKuota, $oleh) {
            $lama = $this->kunciYangAktif($lama);
            $asal = $lama->ekstrakurikuler;

            if (! $asal) {
                throw $this->gagal('kegiatan', 'Les tambahan tidak bisa dipindah. Berhentikan, lalu daftarkan les kelas yang baru.');
            }

            $indukAsal = $asal->parent_ekstrakurikuler_id ?? $asal->id;
            if ($tujuan->id == $indukAsal || $tujuan->parent_ekstrakurikuler_id == $indukAsal) {
                throw $this->gagal('kegiatan', 'Kegiatan tujuan sama dengan kegiatan sekarang. Untuk ganti kelas paralel, gunakan Assign.');
            }

            $tanggal = Carbon::parse($tanggalPindah)->toImmutable()->startOfDay();
            $this->periksaTanggal($lama, $tanggal);
            $this->periksaBatasSemester($lama, $tanggal, $alasan, $konfirmasi);

            // Biaya sama: kegiatan baru berlaku mulai bulan pindah dan bulan itu dialihkan utuh.
            // Biaya beda: kegiatan lama diselesaikan sampai akhir bulan, kegiatan baru mulai bulan berikutnya (FR-11)
            $biayaSama = $asal->biaya == $tujuan->biaya;
            $mulaiBaru = $biayaSama ? $tanggal : $this->bulanPertamaTidakIkut($tanggal);

            $lama->update([
                'status' => StatusPendaftaran::Pindah,
                'tanggal_efektif_berhenti' => $mulaiBaru->toDateString(),
                'alasan_berhenti' => filled($alasan) ? trim($alasan) : null,
            ]);

            // Didaftarkan setelah yang lama tidak aktif, supaya jadwal kegiatan lama tidak dianggap bentrok
            $baru = $this->pendaftaran->daftar($lama->siswa, $tujuan, $mulaiBaru->toDateString(), $abaikanKuota);
            $lama->update(['pindah_ke_id' => $baru->id]);

            $lepas = $this->lepasTagihan($lama, $mulaiBaru->startOfMonth());
            $total = array_sum($lepas['uang']);
            $sisa = $total > 0 ? $this->transaksi->alihkanKeKegiatanBaru($lepas['uang'], $baru, $mulaiBaru) : 0;

            if ($total - $sisa > 0) {
                $this->catatPenyesuaian($lama, JenisPenyesuaian::AlihKegiatan, $total - $sisa, $oleh, "Dialihkan ke {$tujuan->nama}, sudah dibayar: {$lepas['bulan_dibayar']}");
            }

            if ($sisa > 0) {
                $this->catatPenyesuaian($lama, JenisPenyesuaian::RefundBerhenti, $sisa, $oleh, "Sisa pindah ke {$tujuan->nama} yang melewati akhir semester");
            }

            return ['baru' => $baru, 'dibatalkan' => $lepas['jumlah'], 'dialihkan' => $total - $sisa, 'refund' => $sisa];
        });
    }

    // TU menandai refund sudah dikembalikan ke orang tua (FR-22): proses -> berhasil
    public function tandaiRefundDikembalikan(PenyesuaianDana $refund, User $oleh): void
    {
        DB::transaction(function () use ($refund, $oleh) {
            $refund = PenyesuaianDana::whereKey($refund->id)->lockForUpdate()->firstOrFail();

            if (! $refund->adalahRefund() || $refund->status !== StatusPenyesuaian::Proses) {
                throw $this->gagal('refund', 'Refund ini sudah ditandai dikembalikan.');
            }

            $refund->update(['status' => StatusPenyesuaian::Berhasil, 'tanggal_proses' => today(), 'diproses_oleh' => $oleh->id]);
        });
    }

    // Ekskul boleh berhenti/pindah tanpa peringatan di pergantian semester, atau sejak awal semester sampai akhir
    // bulan tagihan pertamanya (Agustus/Januari, #29) — tanggal efektif paling lambat 1 September / 1 Februari
    public function dalamBatasSemester(TahunAjaran $tahunAjaran, CarbonInterface $tanggal): bool
    {
        if ($tanggal->gt($tahunAjaran->tanggal_selesai)) {
            return true;
        }

        return $tahunAjaran->semester->contains(fn (Semester $s) => $tanggal->between(
            $s->tanggal_mulai,
            $this->bulanPertamaDitagih($s)->addMonth(),
        ));
    }

    // Saran tanggal efektif di form: les = tanggal 1 bulan depan (berhenti di akhir bulan, tidak ada bulan yang hangus);
    // ekskul = tanggal 1 bulan depan bila masih di dalam batas semester, selain itu awal semester berikutnya
    public function saranTanggal(TahunAjaran $tahunAjaran): array
    {
        $bulanDepan = today()->startOfMonth()->addMonth();
        $semesterBerikutnya = $tahunAjaran->semester->first(fn (Semester $s) => $s->tanggal_mulai->gt(today()));

        return [
            'les' => $bulanDepan->toDateString(),
            'ekskul' => $this->dalamBatasSemester($tahunAjaran, $bulanDepan)
                ? $bulanDepan->toDateString()
                : ($semesterBerikutnya?->tanggal_mulai ?? $tahunAjaran->tanggal_selesai->addDay())->toDateString(),
        ];
    }

    // Batalkan tagihan mulai bulan tertentu. Uang yang sudah masuk dilepas dan dikembalikan sebagai [transaksi_id => nominal]
    private function lepasTagihan(Pendaftaran $pendaftaran, CarbonInterface $mulaiBulan): array
    {
        // Pindah dengan biaya sama di tengah bulan: tagihan kegiatan lama bulan itu dibuat (bila belum ada) lalu ikut dibatalkan,
        // supaya pembukaan periode nanti tidak membuatnya lagi. Pada kasus lain bulan ini memang tidak ditagih (hasilnya null)
        $this->tagihan->buatUntukBulan($pendaftaran, $mulaiBulan);

        $tagihan = $pendaftaran->tagihan()
            ->where('bulan_tagihan', '>=', $mulaiBulan->toDateString())
            ->where('status', '!=', StatusTagihan::Dibatalkan)
            ->with('transaksi', 'pembayaranOnline')
            ->orderBy('bulan_tagihan')
            ->lockForUpdate()
            ->get();

        foreach ($tagihan as $t) {
            $bulan = $t->bulan_tagihan->locale('id')->translatedFormat('F Y');

            if ($t->pembayaranOnline->contains(fn ($order) => $order->status === StatusPembayaranOnline::Menunggu)) {
                throw $this->gagal('tanggal', "Tagihan {$bulan} sedang dalam pembayaran online yang belum selesai. Tunggu sampai lunas atau kedaluwarsa.");
            }

            // Alokasi yang sudah diakui saat tutup buku sudah masuk honor; tidak boleh dilepas lagi (#31)
            if ($t->transaksi->contains(fn ($tr) => $tr->pivot->diakui_periode_id !== null)) {
                throw $this->gagal('tanggal', "Tagihan {$bulan} sudah masuk tutup buku. Pilih tanggal efektif setelah periode yang sudah ditutup.");
            }

            // Uang transfer yang belum diverifikasi belum pasti ada; jangan dialihkan atau direfund
            if ($t->transaksi->contains(fn ($tr) => $tr->status === StatusTransaksi::Pending)) {
                throw $this->gagal('tanggal', "Tagihan {$bulan} dibayar dengan transfer yang belum diverifikasi TU. Verifikasi atau tolak transfer itu dulu.");
            }
        }

        $uang = [];
        $bulanDibayar = [];

        foreach ($tagihan as $t) {
            foreach ($t->transaksi as $transaksi) {
                $uang[$transaksi->id] = ($uang[$transaksi->id] ?? 0) + (int) $transaksi->pivot->nominal;
            }

            if ($t->transaksi->isNotEmpty()) {
                $bulanDibayar[] = $t->bulan_tagihan->locale('id')->translatedFormat('M Y');
            }

            $t->transaksi()->detach();
            $t->update(['status' => StatusTagihan::Dibatalkan]);
        }

        return ['jumlah' => $tagihan->count(), 'uang' => $uang, 'bulan_dibayar' => implode(', ', $bulanDibayar)];
    }

    private function catatPenyesuaian(Pendaftaran $pendaftaran, JenisPenyesuaian $jenis, int $nominal, User $oleh, string $keterangan): void
    {
        $periode = PeriodeLaporan::whereDate('tanggal_mulai', '<=', today())->whereDate('tanggal_selesai', '>=', today())->first()
            ?? PeriodeLaporan::latest('bulan')->first()
            ?? throw $this->gagal('tanggal', 'Belum ada periode laporan. Buka periode laporan dulu.');

        PenyesuaianDana::create([
            'pendaftaran_id' => $pendaftaran->id,
            'jenis_penyesuaian' => $jenis,
            'periode_asal_id' => $periode->id,
            'periode_tujuan_id' => $jenis === JenisPenyesuaian::AlihKegiatan ? $periode->id : null, // kosong untuk refund
            'nominal' => $nominal,
            'status' => $jenis === JenisPenyesuaian::AlihKegiatan ? StatusPenyesuaian::Selesai : StatusPenyesuaian::Proses,
            'tanggal_proses' => today(),
            'diproses_oleh' => $oleh->id,
            'keterangan' => $keterangan,
        ]);
    }

    private function kunciYangAktif(Pendaftaran $pendaftaran): Pendaftaran
    {
        $pendaftaran = Pendaftaran::whereKey($pendaftaran->id)->lockForUpdate()->firstOrFail();

        if ($pendaftaran->status !== StatusPendaftaran::Aktif) {
            throw $this->gagal('pendaftaran', 'Pendaftaran ini sudah tidak aktif.');
        }

        return $pendaftaran;
    }

    private function periksaTanggal(Pendaftaran $pendaftaran, CarbonImmutable $tanggal): void
    {
        $tahunAjaran = $pendaftaran->kegiatan()->tahunAjaran;

        if ($tanggal->lt($pendaftaran->tanggal_daftar)) {
            throw $this->gagal('tanggal', 'Tanggal tidak boleh sebelum tanggal daftar ('.$pendaftaran->tanggal_daftar->format('d/m/Y').').');
        }

        if ($tanggal->gt($tahunAjaran->tanggal_selesai->addDay())) {
            throw $this->gagal('tanggal', "Tanggal harus di dalam tahun ajaran {$tahunAjaran->nama}.");
        }
    }

    private function periksaBatasSemester(Pendaftaran $pendaftaran, CarbonImmutable $tanggal, ?string $alasan, bool $konfirmasi): void
    {
        if ($pendaftaran->adalahLesTambahan() || $this->dalamBatasSemester($pendaftaran->kegiatan()->tahunAjaran, $tanggal)) {
            return;
        }

        if (! $konfirmasi) {
            throw $this->gagal('konfirmasi', 'Ekskul hanya berhenti/pindah di pergantian semester atau di bulan pertama semester (Agustus/Januari). '
                .'Tanggal ini di luar batas: centang konfirmasi dan isi alasannya untuk mencatat pengecualian.');
        }

        if (blank($alasan)) {
            throw $this->gagal('alasan_berhenti', 'Alasan wajib diisi untuk pengecualian di luar batas semester.');
        }
    }

    // Hari pertama tidak ikut tanggal 1 = bulan itu tidak diikuti; selain itu bulan itu masih diikuti dan tetap ditagih (#20)
    private function bulanPertamaTidakIkut(CarbonImmutable $tanggal): CarbonImmutable
    {
        return $tanggal->day === 1 ? $tanggal : $tanggal->startOfMonth()->addMonth();
    }

    // Bulan tagihan pertama semester: bulan mulai semester, dilewati bila tidak ditagih (mis. Juli -> Agustus)
    private function bulanPertamaDitagih(Semester $semester): CarbonImmutable
    {
        $bulan = $semester->tanggal_mulai->toImmutable()->startOfMonth();

        while (in_array($bulan->month, config('les_ekskul.bulan_tidak_ditagih'), true)) {
            $bulan = $bulan->addMonth();
        }

        return $bulan;
    }

    private function gagal(string $field, string $pesan): ValidationException
    {
        return ValidationException::withMessages([$field => $pesan]);
    }
}
