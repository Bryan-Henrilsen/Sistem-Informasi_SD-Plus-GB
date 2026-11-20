import { Link, router } from '@inertiajs/react';
import TransaksiController from '@/actions/App/Domains/LesEkskul/Http/Controllers/TransaksiController';
import { Button } from '@/components/ui/button';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { namaBulan, rupiah, tanggal } from '@/lib/format';
import { cn } from '@/lib/utils';
import { create } from '@/routes/les-ekskul/transaksi';
import type { MetodeTransaksi, StatusPendaftaran, StatusTransaksi } from '@/types/les-ekskul';

// Bentuk data dari RekapIuranService (Ringkasan v1.11 #40)
export type StatusKotak = 'lunas' | 'menunggu' | 'sebagian' | 'tunggakan' | 'belum' | 'batal';

export type Pembayaran = {
    transaksi_id: number;
    tanggal: string;
    metode: MetodeTransaksi;
    nominal: number;
    paraf: string;
    pencatat: string | null;
    status: StatusTransaksi;
};

export type Kotak = {
    tagihan_id: number;
    status: StatusKotak;
    harus_dibayar: number;
    diskon: number;
    sisa: number;
    bayar: Pembayaran[];
    ada_pending: boolean;
};

export type BarisRekap = {
    id: number;
    buku: string;
    nama_buku: string;
    siswa: { id: number; nama: string };
    kelas_id: number | null;
    kelas: string | null;
    kegiatan: string | null; // nama kelas paralel, mis. English Club Flyer
    status: StatusPendaftaran;
    bulan: Record<string, Kotak>;
    tunggakan: number;
    menunggu: boolean;
};

export type RingkasanBuku = {
    kunci: string;
    nama: string;
    jumlah: number;
    bulan: Record<string, { total: number; lunas: number; terbayar: number }>;
    tunggakan: number;
};

// Kotak yang sedang dibuka di panel rincian
export type KotakDipilih = { baris: BarisRekap; bulan: string; kotak: Kotak };

const kodeMetode: Record<MetodeTransaksi, string> = { cash: 'C', transfer: 'T', online: 'O' };
const namaMetode: Record<MetodeTransaksi, string> = { cash: 'Cash', transfer: 'Transfer', online: 'Online' };

const warna: Record<StatusKotak, string> = {
    lunas: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300',
    menunggu: 'bg-emerald-100 text-emerald-800 outline-dashed outline-1 -outline-offset-2 outline-amber-500 dark:bg-emerald-900/40 dark:text-emerald-300',
    sebagian: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
    tunggakan: 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-300',
    belum: 'bg-muted text-muted-foreground',
    batal: 'text-muted-foreground line-through',
};

export const keterangan: { status: StatusKotak; label: string }[] = [
    { status: 'lunas', label: 'Lunas' },
    { status: 'menunggu', label: 'Transfer menunggu verifikasi TU' },
    { status: 'sebagian', label: 'Dibayar sebagian (sisa)' },
    { status: 'tunggakan', label: 'Tunggakan' },
    { status: 'belum', label: 'Belum dibayar' },
];

// "2026-08-12" -> "12/8", seperti tulisan tangan di buku
const tglPendek = (iso: string) => `${Number(iso.slice(8, 10))}/${Number(iso.slice(5, 7))}`;
const ribuan = (n: number) => n.toLocaleString('id-ID');

export function ContohWarna({ status }: { status: StatusKotak }) {
    return <span className={cn('inline-block size-3.5 rounded', warna[status])} />;
}

// Satu kotak bulan. Ringkas: tanggal + metode. Lengkap (dan selalu saat dicetak): + nominal + paraf
function SelKotak({ kotak, lengkap, disorot, mendatang, onPilih }: { kotak?: Kotak; lengkap: boolean; disorot: boolean; mendatang: boolean; onPilih: () => void }) {
    if (!kotak) {
        // Bulan mendatang belum punya tagihan (dibuat saat periode dibuka) → kosong; bulan lalu tanpa tagihan = tidak ditagih
        return mendatang ? null : <span className="text-muted-foreground">–</span>;
    }

    const terakhir = kotak.bayar.at(-1);
    const sudahBayar = kotak.status === 'lunas' || kotak.status === 'menunggu';
    const tambahan = lengkap ? '' : 'hidden print:block';

    return (
        <button
            type="button"
            onClick={onPilih}
            className={cn(
                'w-full rounded-md px-0.5 py-1 text-[11px] leading-tight whitespace-nowrap transition hover:ring-2 hover:ring-ring/40 print:rounded-none print:bg-transparent print:text-black print:outline-none',
                warna[kotak.status],
                disorot && 'animate-pulse ring-2 ring-sky-500',
            )}
        >
            {sudahBayar && terakhir && (
                <>
                    <span className="block">
                        {tglPendek(terakhir.tanggal)} {kodeMetode[terakhir.metode]}
                        {kotak.bayar.length > 1 && <sup className="ml-0.5">{kotak.bayar.length}x</sup>}
                    </span>
                    <span className={cn('block', tambahan)}>{ribuan(kotak.harus_dibayar)}</span>
                    <span className={cn('block opacity-80', tambahan)}>{terakhir.paraf}</span>
                </>
            )}
            {kotak.status === 'sebagian' && terakhir && (
                <>
                    <span className="block">
                        {tglPendek(terakhir.tanggal)} {kodeMetode[terakhir.metode]}
                    </span>
                    <span className="block">sisa {ribuan(kotak.sisa)}</span>
                    <span className={cn('block opacity-80', tambahan)}>{terakhir.paraf}</span>
                </>
            )}
            {(kotak.status === 'tunggakan' || kotak.status === 'belum') && (
                <span className="block">{kotak.status === 'tunggakan' ? ribuan(kotak.sisa) : <>&nbsp;</>}</span>
            )}
            {kotak.status === 'batal' && <span className="block">batal</span>}
        </button>
    );
}

// Tabel satu buku (Les Tambahan atau satu ekstrakurikuler): kolom kelas, siswa, bulan; baris terakhir jumlah terbayar per bulan
export function TabelBuku({
    baris,
    bulan,
    bulanIni,
    lengkap,
    disorot,
    onPilih,
}: {
    baris: BarisRekap[];
    bulan: string[];
    bulanIni: string;
    lengkap: boolean;
    disorot: number[];
    onPilih: (k: KotakDipilih) => void;
}) {
    const terbayar = bulan.map((b) =>
        baris.reduce((jumlah, r) => {
            const k = r.bulan[b];

            return k && k.status !== 'batal' ? jumlah + k.harus_dibayar - k.sisa : jumlah;
        }, 0),
    );

    return (
        <div className="overflow-x-auto rounded-xl border print:overflow-visible print:rounded-none print:border-0">
            <table className="w-full text-sm print:text-[10px]">
                <thead className="bg-muted/50 text-left print:bg-transparent">
                    <tr className="print:border-b print:border-black">
                        <th className="w-28 p-2">Kelas</th>
                        <th className="min-w-40 p-2">Siswa</th>
                        {bulan.map((b) => (
                            <th key={b} className="min-w-16 p-1 text-center capitalize">
                                {namaBulan(b, true)}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {baris.map((r, i) => {
                        const kelasBaru = i === 0 || baris[i - 1].kelas !== r.kelas;

                        return (
                            <tr key={r.id} className={cn('border-t print:break-inside-avoid', kelasBaru && i > 0 && 'border-t-2')}>
                                <td className="p-2 align-top whitespace-nowrap text-muted-foreground print:text-black">{kelasBaru ? (r.kelas ?? '–') : ''}</td>
                                <td className="p-2 align-top">
                                    <div className="font-medium">{r.siswa.nama}</div>
                                    {(r.kegiatan || r.status !== 'aktif') && (
                                        <div className="text-xs text-muted-foreground print:text-black">
                                            {[r.kegiatan, r.status !== 'aktif' ? r.status : null].filter(Boolean).join(' · ')}
                                        </div>
                                    )}
                                </td>
                                {bulan.map((b) => (
                                    <td key={b} className="p-0.5 text-center align-top">
                                        <SelKotak
                                            kotak={r.bulan[b]}
                                            lengkap={lengkap}
                                            disorot={!!r.bulan[b] && disorot.includes(r.bulan[b].tagihan_id)}
                                            mendatang={b > bulanIni && r.status === 'aktif'}
                                            onPilih={() => onPilih({ baris: r, bulan: b, kotak: r.bulan[b] })}
                                        />
                                    </td>
                                ))}
                            </tr>
                        );
                    })}
                    <tr className="border-t-2 bg-muted/30 font-medium print:bg-transparent">
                        <td className="p-2" colSpan={2}>
                            Terbayar
                        </td>
                        {terbayar.map((t, i) => (
                            <td key={bulan[i]} className="p-1 text-center text-xs">
                                {t > 0 ? ribuan(t) : ''}
                            </td>
                        ))}
                    </tr>
                </tbody>
            </table>
        </div>
    );
}

// Panel rincian satu kotak: tagihan, riwayat pembayaran, dan aksi khusus TU
export function RincianKotak({
    dipilih,
    bisaCatat,
    kembali,
    onTutup,
}: {
    dipilih: KotakDipilih | null;
    bisaCatat: boolean;
    kembali: string;
    onTutup: () => void;
}) {
    const k = dipilih?.kotak;
    const status: Record<StatusKotak, string> = {
        lunas: 'Lunas',
        menunggu: 'Lunas, transfer menunggu verifikasi TU',
        sebagian: 'Dibayar sebagian',
        tunggakan: 'Belum dibayar (tunggakan)',
        belum: 'Belum dibayar',
        batal: 'Dibatalkan (berhenti/pindah)',
    };

    return (
        <Sheet open={dipilih !== null} onOpenChange={(buka) => !buka && onTutup()}>
            <SheetContent className="gap-0 overflow-y-auto sm:max-w-md">
                {dipilih && k && (
                    <>
                        <SheetHeader>
                            <SheetTitle>{dipilih.baris.siswa.nama}</SheetTitle>
                            <SheetDescription>
                                {dipilih.baris.kegiatan ?? dipilih.baris.nama_buku}
                                {dipilih.baris.kelas && ` · ${dipilih.baris.kelas}`} · {namaBulan(dipilih.bulan)}
                            </SheetDescription>
                        </SheetHeader>

                        <div className="flex flex-col gap-4 px-4 pb-6 text-sm">
                            <dl className="grid grid-cols-2 gap-y-1 rounded-md border p-3">
                                <dt className="text-muted-foreground">Status</dt>
                                <dd className="text-right font-medium">{status[k.status]}</dd>
                                <dt className="text-muted-foreground">Tagihan</dt>
                                <dd className="text-right">{rupiah(k.harus_dibayar)}</dd>
                                {k.diskon > 0 && (
                                    <>
                                        <dt className="text-muted-foreground">Diskon</dt>
                                        <dd className="text-right">{rupiah(k.diskon)}</dd>
                                    </>
                                )}
                                {k.sisa > 0 && k.status !== 'batal' && (
                                    <>
                                        <dt className="text-muted-foreground">Sisa</dt>
                                        <dd className="text-right font-medium">{rupiah(k.sisa)}</dd>
                                    </>
                                )}
                            </dl>

                            <div>
                                <p className="mb-2 font-medium">Pembayaran</p>
                                {k.bayar.length === 0 && <p className="text-muted-foreground">Belum ada pembayaran.</p>}
                                <ul className="divide-y rounded-md border empty:hidden">
                                    {k.bayar.map((p) => (
                                        <li key={p.transaksi_id} className="flex items-start justify-between gap-3 p-3">
                                            <div>
                                                <div>
                                                    {tanggal(p.tanggal)} · {namaMetode[p.metode]}
                                                </div>
                                                <div className="text-xs text-muted-foreground">
                                                    Dicatat {p.pencatat ?? 'sistem'} (paraf {p.paraf})
                                                    {p.status === 'pending' && ' · menunggu verifikasi TU'}
                                                </div>
                                            </div>
                                            <div className="flex flex-col items-end gap-1">
                                                <span className="font-medium">{rupiah(p.nominal)}</span>
                                                {bisaCatat && p.status === 'pending' && (
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() =>
                                                            router.post(TransaksiController.verifikasi.url(p.transaksi_id), {}, { preserveScroll: true, onSuccess: onTutup })
                                                        }
                                                    >
                                                        Verifikasi
                                                    </Button>
                                                )}
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            </div>

                            {bisaCatat && k.sisa > 0 && k.status !== 'batal' && (
                                <Button asChild>
                                    <Link href={create({ query: { siswa: dipilih.baris.siswa.id, tagihan: k.tagihan_id, kembali } })}>
                                        Catat pembayaran
                                    </Link>
                                </Button>
                            )}
                        </div>
                    </>
                )}
            </SheetContent>
        </Sheet>
    );
}

// Tab Ringkasan: persentase lunas per buku per bulan + tunggakan, untuk Kepala Sekolah dan Bendahara
export function TabelRingkasan({
    ringkasan,
    bulan,
    bulanIni,
    onBuka,
}: {
    ringkasan: RingkasanBuku[];
    bulan: string[];
    bulanIni: string;
    onBuka: (kunci: string) => void;
}) {
    return (
        <div className="overflow-x-auto rounded-xl border">
            <table className="w-full text-sm">
                <thead className="bg-muted/50 text-left">
                    <tr>
                        <th className="p-3">Buku</th>
                        {bulan.map((b) => (
                            <th key={b} className="p-2 text-center capitalize">
                                {namaBulan(b, true)}
                            </th>
                        ))}
                        <th className="p-3 text-right">Tunggakan</th>
                    </tr>
                </thead>
                <tbody>
                    {ringkasan.map((r) => (
                        <tr key={r.kunci} className="border-t">
                            <td className="p-3">
                                <button type="button" className="text-left font-medium hover:underline" onClick={() => onBuka(r.kunci)}>
                                    {r.nama}
                                </button>
                                <div className="text-xs text-muted-foreground">{r.jumlah} peserta</div>
                            </td>
                            {bulan.map((b) => {
                                const s = r.bulan[b];

                                if (!s || s.total === 0) {
                                    return (
                                        <td key={b} className="p-1 text-center text-muted-foreground">
                                            –
                                        </td>
                                    );
                                }

                                const persen = Math.round((s.lunas / s.total) * 100);
                                // Hijau = semua lunas; bulan lalu belum 100% = ada tunggakan (merah); bulan berjalan = kuning; bulan mendatang = abu-abu
                                const kelas = persen === 100 ? warna.lunas : b < bulanIni ? warna.tunggakan : b === bulanIni ? warna.sebagian : warna.belum;

                                return (
                                    <td key={b} className="p-1 text-center">
                                        <div title={`${s.lunas} dari ${s.total} lunas · terbayar ${rupiah(s.terbayar)}`} className={cn('rounded-md px-1 py-1 text-xs', kelas)}>
                                            {persen}%
                                            <span className="block text-[10px] opacity-80">
                                                {s.lunas}/{s.total}
                                            </span>
                                        </div>
                                    </td>
                                );
                            })}
                            <td className="p-3 text-right font-medium">{r.tunggakan > 0 ? rupiah(r.tunggakan) : '–'}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
