import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, CalendarClock, Check } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { rupiah, tanggal } from '@/lib/format';
import ortu from '@/routes/ortu';

type Pilihan = {
    jenis: 'les_tambahan' | 'ekstrakurikuler';
    id: number;
    nama: string;
    biaya: number;
    jadwal: string[];
    kuota: number | null;
    terisi: number | null;
    punya_kelas_paralel: boolean;
    alasan_tidak_bisa: string | null;
};
type Periode = { tanggal_mulai: string; tanggal_selesai: string };

type Props = {
    anak: { id: number; nama: string; kelas: string | null; diskon_anak_guru: boolean };
    wajib: { nama: string; jadwal: string[] }[];
    diikuti: { id: number; nama: string; kelas_paralel: string | null; jadwal: string[]; tanggal_daftar: string }[];
    pilihan: Pilihan[];
    periode: Periode | null;
    periodeBerikutnya: Periode | null;
};

// Kegiatan satu anak: wajib (info), yang diikuti, dan daftar kegiatan selama periode pendaftaran (FR-2–FR-5, FR-24)
export default function KegiatanAnak({ anak, wajib, diikuti, pilihan, periode, periodeBerikutnya }: Props) {
    const [dipilih, setDipilih] = useState<Pilihan | null>(null);
    const [proses, setProses] = useState(false);
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const biaya = (n: number) => (anak.diskon_anak_guru ? Math.floor(n / 2) : n);

    function daftar() {
        if (!dipilih) {
            return;
        }

        router.post(
            ortu.anak.daftar(anak.id).url,
            { jenis: dipilih.jenis, kegiatan_id: dipilih.id },
            {
                preserveScroll: true,
                onStart: () => setProses(true),
                onFinish: () => setProses(false),
                onSuccess: () => setDipilih(null),
                onError: () => setDipilih(null),
            },
        );
    }

    return (
        <>
            <Head title={`Kegiatan ${anak.nama}`} />

            <div className="flex flex-col gap-4">
                <div className="flex items-center gap-2">
                    <Button variant="ghost" size="icon" asChild>
                        <Link href={ortu.beranda()} aria-label="Kembali">
                            <ArrowLeft className="size-5" />
                        </Link>
                    </Button>
                    <div className="min-w-0">
                        <h1 className="truncate text-lg font-semibold">{anak.nama}</h1>
                        <p className="text-sm text-muted-foreground">{anak.kelas ? `Kelas ${anak.kelas}` : 'Belum terdaftar di kelas tahun ini'}</p>
                    </div>
                </div>

                {errors.kegiatan && (
                    <div className="rounded-md border border-sekolah-bahaya/40 bg-sekolah-bahaya/10 p-3 text-sm text-sekolah-bahaya">
                        {errors.kegiatan}
                    </div>
                )}

                {wajib.length > 0 && (
                    <Bagian judul="Kegiatan wajib">
                        {wajib.map((w) => (
                            <BarisKegiatan key={w.nama} nama={w.nama} jadwal={w.jadwal} keterangan="Diikuti otomatis, tidak perlu didaftarkan" />
                        ))}
                    </Bagian>
                )}

                <Bagian judul="Kegiatan yang diikuti">
                    {diikuti.length === 0 && <p className="text-sm text-muted-foreground">Belum mengikuti les atau ekstrakurikuler.</p>}
                    {diikuti.map((d) => (
                        <BarisKegiatan
                            key={d.id}
                            nama={d.nama}
                            jadwal={d.jadwal}
                            keterangan={`${d.kelas_paralel ? `${d.kelas_paralel} · ` : ''}terdaftar ${tanggal(d.tanggal_daftar)}`}
                        />
                    ))}
                    {diikuti.length > 0 && (
                        <p className="text-xs text-muted-foreground">Untuk berhenti atau pindah kegiatan, hubungi TU.</p>
                    )}
                </Bagian>

                {periode ? (
                    <Bagian judul="Daftar kegiatan">
                        <p className="flex items-center gap-1.5 text-sm text-sekolah-sukses">
                            <CalendarClock className="size-4" />
                            Pendaftaran dibuka sampai {tanggal(periode.tanggal_selesai)}
                        </p>
                        {anak.diskon_anak_guru && <p className="text-xs text-muted-foreground">Biaya sudah termasuk diskon 50% anak guru.</p>}
                        {pilihan.length === 0 && <p className="text-sm text-muted-foreground">Tidak ada kegiatan lain yang bisa dipilih.</p>}
                        {pilihan.map((p) => (
                            <div key={`${p.jenis}-${p.id}`} className="flex flex-col gap-2 border-t pt-3 first:border-0 first:pt-0">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <p className="font-medium">{p.nama}</p>
                                        <p className="text-sm">{rupiah(biaya(p.biaya))} / bulan</p>
                                        {p.jadwal.map((j) => (
                                            <p key={j} className="text-xs text-muted-foreground">
                                                {j}
                                            </p>
                                        ))}
                                        {p.kuota !== null && (
                                            <p className="text-xs text-muted-foreground">
                                                Kuota {p.terisi}/{p.kuota}
                                            </p>
                                        )}
                                        {p.punya_kelas_paralel && <p className="text-xs text-muted-foreground">Kelas dibagi oleh sekolah setelah mendaftar.</p>}
                                    </div>
                                    <Button size="sm" disabled={p.alasan_tidak_bisa !== null} onClick={() => setDipilih(p)}>
                                        Daftar
                                    </Button>
                                </div>
                                {p.alasan_tidak_bisa && <p className="text-xs text-sekolah-tertunda">{p.alasan_tidak_bisa}</p>}
                            </div>
                        ))}
                    </Bagian>
                ) : (
                    <Card className="py-4">
                        <CardContent className="px-4 text-sm text-muted-foreground">
                            {periodeBerikutnya
                                ? `Pendaftaran kegiatan dibuka ${tanggal(periodeBerikutnya.tanggal_mulai)} – ${tanggal(periodeBerikutnya.tanggal_selesai)}.`
                                : 'Pendaftaran kegiatan sedang ditutup. Untuk mendaftar di luar periode, hubungi TU.'}
                        </CardContent>
                    </Card>
                )}
            </div>

            <Dialog open={dipilih !== null} onOpenChange={(b) => !b && setDipilih(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Daftarkan ke {dipilih?.nama}?</DialogTitle>
                        <DialogDescription>
                            {anak.nama} akan terdaftar mulai bulan ini dengan biaya {dipilih ? rupiah(biaya(dipilih.biaya)) : ''} per bulan. Tagihan dibuat
                            otomatis. Untuk berhenti nanti, hubungi TU.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="ghost" onClick={() => setDipilih(null)}>
                            Batal
                        </Button>
                        <Button onClick={daftar} disabled={proses}>
                            <Check className="size-4" />
                            Daftarkan
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

function Bagian({ judul, children }: { judul: string; children: React.ReactNode }) {
    return (
        <Card className="gap-3 py-4">
            <CardHeader className="px-4">
                <CardTitle className="text-base">{judul}</CardTitle>
            </CardHeader>
            <CardContent className="flex flex-col gap-3 px-4">{children}</CardContent>
        </Card>
    );
}

function BarisKegiatan({ nama, jadwal, keterangan }: { nama: string; jadwal: string[]; keterangan?: string }) {
    return (
        <div>
            <p className="font-medium">{nama}</p>
            {jadwal.map((j) => (
                <p key={j} className="text-xs text-muted-foreground">
                    {j}
                </p>
            ))}
            {keterangan && <p className="text-xs text-muted-foreground">{keterangan}</p>}
        </div>
    );
}
