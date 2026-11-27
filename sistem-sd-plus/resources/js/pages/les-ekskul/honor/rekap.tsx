import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import PengajarEkskulController from '@/actions/App/Domains/LesEkskul/Http/Controllers/PengajarEkskulController';
import RekapHonorController from '@/actions/App/Domains/LesEkskul/Http/Controllers/RekapHonorController';
import SkemaHonorController from '@/actions/App/Domains/LesEkskul/Http/Controllers/SkemaHonorController';
import Heading from '@/components/heading';
import { HonorFlatPeriode } from '@/components/les-ekskul/honor-flat-periode';
import type { HonorFlatPeriodeData, OrangPenerima } from '@/components/les-ekskul/honor-flat-periode';
import { LabelStatus } from '@/components/les-ekskul/label-status';
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
import { namaBulan, rupiah, tanggal, waktu } from '@/lib/format';
import { cn } from '@/lib/utils';

type Rincian = { peran: string; penerima: string; persen: number | null; nominal: number };
type Kegiatan = {
    id: number;
    nama: string;
    tipe: 'ekstrakurikuler' | 'les_tambahan';
    total: number;
    non_honor: number;
    dasar: number;
    rincian: Rincian[];
    catatan: string[]; // pembagian per pertemuan bila pengajar/wali berganti (#50)
};
type Orang = {
    kunci: string;
    nama: string;
    eksternal: boolean;
    item: { sumber: string; peran: string; nominal: number }[];
    nilai: Record<string, number>;
    jumlah: number;
};
type Rekap = {
    kegiatan: Kegiatan[];
    orang: Orang[];
    kolom: { kode: string; label: string }[];
    total_kolom: Record<string, number>;
    total_orang: number;
    kas_sekolah: number;
    yayasan: number;
    pendapatan: number;
    non_honor: number;
};
type Periode = {
    id: number;
    bulan: string;
    tanggal_mulai: string;
    tanggal_selesai: string;
    status: string;
    diketahui_oleh: string | null;
    diketahui_at: string | null;
};

type Props = {
    daftarPeriode: { id: number; bulan: string; status: string }[];
    periode: Periode | null;
    sudahDihitung: boolean;
    tanpaHonor: boolean;
    hambatan: string[];
    rekap: Rekap | null;
    bisaHitung: boolean;
    bisaTandai: boolean;
    honorFlat: HonorFlatPeriodeData[];
    bisaAturFlat: boolean;
    orang: OrangPenerima[];
};

type Layer = 'kegiatan' | 'orang' | 'akhir';

const LAYER: { kode: Layer; label: string }[] = [
    { kode: 'kegiatan', label: 'Per kegiatan' },
    { kode: 'orang', label: 'Per orang' },
    { kode: 'akhir', label: 'Rekap akhir' },
];

const kelasPilihan = 'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';

export default function RekapHonor({
    daftarPeriode,
    periode,
    sudahDihitung,
    tanpaHonor,
    hambatan,
    rekap,
    bisaHitung,
    bisaTandai,
    honorFlat,
    bisaAturFlat,
    orang,
}: Props) {
    const [layer, setLayer] = useState<Layer>('kegiatan');
    const [konfirmasi, setKonfirmasi] = useState<'hitung' | 'diketahui' | null>(null);
    const [proses, setProses] = useState(false);
    const { errors } = usePage<{ errors: Record<string, string> }>().props;

    const ditutup = periode?.status === 'ditutup';
    const terkunci = periode?.diketahui_at != null;
    const bolehHitung = bisaHitung && ditutup && !terkunci && !tanpaHonor && hambatan.length === 0;

    function kirim() {
        if (!periode || !konfirmasi) {
            return;
        }

        const aksi = konfirmasi === 'hitung' ? RekapHonorController.hitung(periode.id) : RekapHonorController.tandaiDiketahui(periode.id);
        router.post(aksi.url, {}, {
            preserveScroll: true,
            onStart: () => setProses(true),
            onFinish: () => setProses(false),
            onSuccess: () => setKonfirmasi(null),
        });
    }

    return (
        <>
            <Head title="Rekap Honor" />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Rekap Honor"
                        description="Honor dihitung dari pembayaran yang diakui saat tutup buku. Boleh dihitung ulang sampai Kepala Sekolah menandai Sudah Diketahui."
                    />
                    <div className="flex flex-wrap gap-2 print:hidden">
                        {bolehHitung && (
                            <Button variant={sudahDihitung ? 'outline' : 'default'} onClick={() => setKonfirmasi('hitung')}>
                                {sudahDihitung ? 'Hitung ulang' : 'Hitung honor'}
                            </Button>
                        )}
                        {bisaTandai && (sudahDihitung || tanpaHonor) && !terkunci && (
                            <Button onClick={() => setKonfirmasi('diketahui')}>Tandai Sudah Diketahui</Button>
                        )}
                        {sudahDihitung && (
                            <Button variant="outline" onClick={() => window.print()}>
                                Cetak
                            </Button>
                        )}
                    </div>
                </div>

                <div className="flex flex-wrap items-center gap-3 print:hidden">
                    <select
                        value={periode?.id ?? ''}
                        onChange={(e) => router.get(RekapHonorController.index({ query: { periode: e.target.value } }).url)}
                        className={kelasPilihan}
                    >
                        {daftarPeriode.map((p) => (
                            <option key={p.id} value={p.id}>
                                Periode {namaBulan(p.bulan)}
                            </option>
                        ))}
                    </select>
                    {periode && (
                        <span className="flex items-center gap-2 text-sm text-muted-foreground">
                            {tanggal(periode.tanggal_mulai)} – {tanggal(periode.tanggal_selesai)}
                            <LabelStatus status={periode.status} />
                        </span>
                    )}
                </div>

                {errors.honor && <Pesan warna="merah">{errors.honor}</Pesan>}

                {periode && terkunci && (
                    <Pesan warna="hijau">
                        Sudah Diketahui oleh {periode.diketahui_oleh} pada {waktu(periode.diketahui_at!)}. Honor periode ini terkunci.
                    </Pesan>
                )}

                {periode && !ditutup && (
                    <Pesan warna="biru">Honor dihitung setelah Bendahara menutup buku periode {namaBulan(periode.bulan)}.</Pesan>
                )}

                {hambatan.length > 0 && (
                    <Pesan warna="kuning">
                        <p className="font-medium">
                            {sudahDihitung ? 'Honor tidak bisa dihitung ulang' : 'Honor belum bisa dihitung'} karena data belum lengkap:
                        </p>
                        <ul className="mt-1 list-disc pl-5">
                            {hambatan.map((h) => (
                                <li key={h}>{h}</li>
                            ))}
                        </ul>
                        <p className="mt-2">
                            Lengkapi di{' '}
                            <Link href={PengajarEkskulController.index()} className="font-medium underline underline-offset-4">
                                Pengajar Ekskul
                            </Link>{' '}
                            atau{' '}
                            <Link href={SkemaHonorController.index()} className="font-medium underline underline-offset-4">
                                Skema Honor
                            </Link>
                            , lalu hitung honor.
                        </p>
                    </Pesan>
                )}

                {ditutup && !sudahDihitung && !tanpaHonor && hambatan.length === 0 && (
                    <Pesan warna="biru">Honor periode ini belum dihitung.</Pesan>
                )}

                {tanpaHonor && !terkunci && (
                    <Pesan warna="biru">Tidak ada pendapatan les/ekskul yang diakui dan tidak ada honor flat di periode ini, jadi tidak ada honor yang dibagi.</Pesan>
                )}

                {rekap && sudahDihitung && (
                    <>
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                            <Angka label="Pendapatan diakui" nilai={rekap.pendapatan} />
                            <Angka label="Bagian non-honor" nilai={rekap.non_honor} keterangan="mis. uang kolam" />
                            <Angka label="Honor perorangan" nilai={rekap.total_orang} />
                            <Angka label="Kas Sekolah" nilai={rekap.kas_sekolah} />
                            <Angka label="Yayasan" nilai={rekap.yayasan} />
                        </div>

                        <div className="flex gap-1 rounded-md border p-1 print:hidden">
                            {LAYER.map((l) => (
                                <button
                                    key={l.kode}
                                    type="button"
                                    onClick={() => setLayer(l.kode)}
                                    className={cn(
                                        'flex-1 rounded px-3 py-1.5 text-sm',
                                        layer === l.kode ? 'bg-primary text-primary-foreground' : 'hover:bg-muted',
                                    )}
                                >
                                    {l.label}
                                </button>
                            ))}
                        </div>

                        {layer === 'kegiatan' && <PerKegiatan kegiatan={rekap.kegiatan} />}
                        {layer === 'orang' && <PerOrang orang={rekap.orang} />}
                        {layer === 'akhir' && periode && <RekapAkhir rekap={rekap} periode={periode} />}

                        {periode && honorFlat.length > 0 && (
                            <HonorFlatPeriode periodeId={periode.id} data={honorFlat} orang={orang} bisaUbah={bisaAturFlat} />
                        )}
                    </>
                )}
            </div>

            <Dialog open={konfirmasi !== null} onOpenChange={(b) => !b && setKonfirmasi(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{konfirmasi === 'hitung' ? (sudahDihitung ? 'Hitung ulang honor?' : 'Hitung honor?') : 'Tandai Sudah Diketahui?'}</DialogTitle>
                        <DialogDescription>
                            {konfirmasi === 'hitung'
                                ? 'Honor dihitung dengan pengaturan pengajar, pendamping, skema, dan honor flat saat ini. Hasil sebelumnya diganti.'
                                : 'Setelah ditandai, honor periode ini terkunci dan tidak bisa dihitung ulang.'}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="ghost" onClick={() => setKonfirmasi(null)}>
                            Batal
                        </Button>
                        <Button onClick={kirim} disabled={proses}>
                            {konfirmasi === 'hitung' ? 'Hitung' : 'Tandai'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

// Layer 1: tiap kegiatan dengan pendapatan, dasar honor, dan pembagian per peran
function PerKegiatan({ kegiatan }: { kegiatan: Kegiatan[] }) {
    if (kegiatan.length === 0) {
        return <p className="text-sm text-muted-foreground">Tidak ada pendapatan les/ekskul yang diakui di periode ini.</p>;
    }

    return (
        <div className="grid gap-4 lg:grid-cols-2">
            {kegiatan.map((k) => (
                <Card key={k.id} className="gap-3 py-4">
                    <CardHeader className="px-4">
                        <CardTitle className="text-base">{k.nama}</CardTitle>
                        <p className="text-sm text-muted-foreground">
                            Pendapatan {rupiah(k.total)}
                            {k.non_honor > 0 && ` − non-honor ${rupiah(k.non_honor)} = dasar ${rupiah(k.dasar)}`}
                        </p>
                        {k.catatan.length > 0 && (
                            <ul className="text-xs text-sekolah-info">
                                {k.catatan.map((c) => (
                                    <li key={c}>Pergantian pengajar — {c}</li>
                                ))}
                            </ul>
                        )}
                    </CardHeader>
                    <CardContent className="px-4">
                        <table className="w-full text-sm">
                            <tbody>
                                {k.rincian.map((r, i) => (
                                    <tr key={i} className="border-t">
                                        <td className="py-1.5 pr-2 capitalize text-muted-foreground">{r.peran}</td>
                                        <td className="py-1.5 pr-2">{r.penerima}</td>
                                        <td className="py-1.5 pr-2 text-right text-muted-foreground">
                                            {r.persen !== null && `${r.persen.toLocaleString('id-ID')}%`}
                                        </td>
                                        <td className="py-1.5 text-right tabular-nums">{rupiah(r.nominal)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </CardContent>
                </Card>
            ))}
        </div>
    );
}

// Layer 2: tiap orang dengan asal honornya
function PerOrang({ orang }: { orang: Orang[] }) {
    if (orang.length === 0) {
        return <p className="text-sm text-muted-foreground">Belum ada penerima honor.</p>;
    }

    return (
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            {orang.map((o) => (
                <Card key={o.kunci} className="gap-3 py-4">
                    <CardHeader className="px-4">
                        <CardTitle className="flex items-center justify-between gap-2 text-base">
                            <span>
                                {o.nama}
                                {o.eksternal && <span className="ml-2 text-xs font-normal text-muted-foreground">eksternal</span>}
                            </span>
                            <span className="tabular-nums">{rupiah(o.jumlah)}</span>
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="px-4">
                        <ul className="divide-y text-sm">
                            {o.item.map((it, i) => (
                                <li key={i} className="flex justify-between gap-2 py-1.5">
                                    <span>
                                        {it.sumber} <span className="text-muted-foreground">· {it.peran}</span>
                                    </span>
                                    <span className="tabular-nums">{rupiah(it.nominal)}</span>
                                </li>
                            ))}
                        </ul>
                    </CardContent>
                </Card>
            ))}
        </div>
    );
}

// Layer 3: satu baris per orang dengan kolom seperti rekap honor sekolah; bisa dicetak
function RekapAkhir({ rekap, periode }: { rekap: Rekap; periode: Periode }) {
    const angka = (n: number) => (n === 0 ? '' : n.toLocaleString('id-ID'));

    return (
        <div className="flex flex-col gap-3">
            <h3 className="hidden text-center text-lg font-semibold print:block">
                Rekap Honor Les & Ekstrakurikuler — {namaBulan(periode.bulan)}
            </h3>
            <div className="overflow-x-auto rounded-md border print:overflow-visible print:border-0">
                <table className="w-full text-sm print:text-xs">
                    <thead className="bg-muted/50 print:bg-transparent">
                        <tr>
                            <th className="border-b p-2 text-left">No</th>
                            <th className="border-b p-2 text-left">Nama</th>
                            {rekap.kolom.map((k) => (
                                <th key={k.kode} className="border-b p-2 text-right">
                                    {k.label}
                                </th>
                            ))}
                            <th className="border-b p-2 text-right">Jumlah</th>
                            <th className="hidden border-b p-2 print:table-cell">Tanda tangan</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rekap.orang.map((o, i) => (
                            <tr key={o.kunci} className="border-b last:border-0">
                                <td className="p-2">{i + 1}</td>
                                <td className="p-2 whitespace-nowrap">{o.nama}</td>
                                {rekap.kolom.map((k) => (
                                    <td key={k.kode} className="p-2 text-right tabular-nums">
                                        {angka(o.nilai[k.kode])}
                                    </td>
                                ))}
                                <td className="p-2 text-right font-medium tabular-nums">{angka(o.jumlah)}</td>
                                <td className="hidden w-32 p-2 print:table-cell" />
                            </tr>
                        ))}
                    </tbody>
                    <tfoot>
                        <tr className="border-t font-semibold">
                            <td className="p-2" colSpan={2}>
                                Total
                            </td>
                            {rekap.kolom.map((k) => (
                                <td key={k.kode} className="p-2 text-right tabular-nums">
                                    {angka(rekap.total_kolom[k.kode])}
                                </td>
                            ))}
                            <td className="p-2 text-right tabular-nums">{angka(rekap.total_orang)}</td>
                            <td className="hidden print:table-cell" />
                        </tr>
                    </tfoot>
                </table>
            </div>
            <p className="text-xs text-muted-foreground">
                Kas Sekolah {rupiah(rekap.kas_sekolah)} dan Yayasan {rupiah(rekap.yayasan)} tidak termasuk tabel ini. Pendamping kedua dan
                seterusnya sudah dibayar dari porsi Kas Sekolah.
            </p>
        </div>
    );
}

function Angka({ label, nilai, keterangan }: { label: string; nilai: number; keterangan?: string }) {
    return (
        <div className="rounded-md border p-3">
            <p className="text-xs text-muted-foreground">
                {label}
                {keterangan && ` (${keterangan})`}
            </p>
            <p className="text-lg font-semibold tabular-nums">{rupiah(nilai)}</p>
        </div>
    );
}

const WARNA = {
    merah: 'border-sekolah-bahaya/40 bg-sekolah-bahaya/10 text-sekolah-bahaya',
    kuning: 'border-sekolah-tertunda/40 bg-sekolah-tertunda/10 text-sekolah-tertunda',
    biru: 'border-sekolah-info/40 bg-sekolah-info/10 text-sekolah-info',
    hijau: 'border-sekolah-sukses/40 bg-sekolah-sukses/10 text-sekolah-sukses',
};

function Pesan({ warna, children }: { warna: keyof typeof WARNA; children: React.ReactNode }) {
    return <div className={cn('rounded-md border p-3 text-sm print:hidden', WARNA[warna])}>{children}</div>;
}

RekapHonor.layout = {
    breadcrumbs: [{ title: 'Rekap Honor', href: RekapHonorController.index.url() }],
};
