import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import { FileSpreadsheet, Printer } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import TagihanController from '@/actions/App/Domains/LesEkskul/Http/Controllers/TagihanController';
import Heading from '@/components/heading';
import { ContohWarna, keterangan, RincianKotak, TabelBuku, TabelRingkasan } from '@/components/les-ekskul/rekap-iuran';
import type { BarisRekap, KotakDipilih, RingkasanBuku } from '@/components/les-ekskul/rekap-iuran';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { rupiah } from '@/lib/format';
import { cn } from '@/lib/utils';
import { excel, index } from '@/routes/les-ekskul/tagihan';

type Filter = { buku?: string; kelas?: string; status?: string; nama?: string };

type Props = {
    tahunAjaran: { id: number; nama: string };
    daftarTahun: { id: number; nama: string }[];
    bulan: string[];
    buku: { kunci: string; nama: string; jumlah: number }[];
    baris: BarisRekap[];
    ringkasan: RingkasanBuku[];
    kartu: { total_tunggakan: number; siswa_menunggak: number; menunggu_verifikasi: number };
    kelas: { id: number; nama: string }[];
    filter: Filter;
    bulanIni: string;
    bisaCatat: boolean;
};

const kelasPilihan = 'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';

// Pilihan Ringkas/Lengkap diingat per browser; aman bila penyimpanan diblokir
function bacaMode(): boolean {
    try {
        return localStorage.getItem('rekap-iuran-lengkap') === '1';
    } catch {
        return false;
    }
}

export default function RekapIuran({ tahunAjaran, daftarTahun, bulan, buku, baris, ringkasan, kartu, kelas, filter, bulanIni, bisaCatat }: Props) {
    const { url, flash } = usePage();
    const [tab, setTab] = useState<'rekap' | 'ringkasan'>('rekap');
    const [lengkap, setLengkap] = useState(false);
    const [dipilih, setDipilih] = useState<KotakDipilih | null>(null);
    const [disorot, setDisorot] = useState<number[]>([]);
    const [dicetak, setDicetak] = useState(''); // waktu cetak; diisi di browser supaya sama dengan hasil render server (SSR)

    useEffect(() => {
        setLengkap(bacaMode());
        setDicetak(new Date().toLocaleString('id-ID'));
    }, []);

    // Kotak yang baru dibayar dari Catat Pembayaran disorot beberapa detik (TransaksiController::store, #40)
    useEffect(() => {
        const ids = (flash as { disorot?: number[] }).disorot;

        if (!ids?.length) {
            return;
        }

        setDisorot(ids);
        const waktu = setTimeout(() => setDisorot([]), 6000);

        return () => clearTimeout(waktu);
    }, [flash]);

    useEffect(() => {
        if (disorot.length) {
            document.querySelector('.ring-sekolah-info/40')?.scrollIntoView({ block: 'center', behavior: 'smooth' });
        }
    }, [disorot]);

    function ubahMode(nilai: boolean) {
        setLengkap(nilai);
        try {
            localStorage.setItem('rekap-iuran-lengkap', nilai ? '1' : '0');
        } catch {
            // penyimpanan diblokir: pilihan hanya berlaku sampai halaman ditutup
        }
    }

    // Baris dikelompokkan per buku sesuai urutan daftar buku (Les Tambahan dulu, lalu ekskul abjad)
    const kelompok = useMemo(
        () =>
            buku
                .map((b) => ({ ...b, baris: baris.filter((r) => r.buku === b.kunci) }))
                .filter((b) => b.baris.length > 0),
        [buku, baris],
    );

    const query = (ubah: Filter & { tahun?: number }) => ({ query: { tahun: tahunAjaran.id, ...filter, ...ubah } });
    const namaBukuDipilih = buku.find((b) => b.kunci === filter.buku)?.nama ?? 'Semua buku';

    return (
        <>
            <Head title="Rekap Iuran" />
            <style>{'@media print { @page { size: A4 landscape; margin: 8mm; } }'}</style>

            <div className="flex flex-col gap-6 p-4 print:gap-3 print:p-0">
                <div className="flex flex-wrap items-start justify-between gap-3 print:hidden">
                    <Heading
                        title="Rekap Iuran"
                        description="Pengganti buku rekap TU. Setiap pembayaran yang dicatat langsung masuk ke sini. Klik kotak bulan untuk melihat rinciannya."
                    />
                    <select
                        aria-label="Tahun ajaran"
                        value={tahunAjaran.id}
                        onChange={(e) => router.get(index({ query: { tahun: e.target.value } }).url)}
                        className={kelasPilihan}
                    >
                        {daftarTahun.map((t) => (
                            <option key={t.id} value={t.id}>
                                Tahun ajaran {t.nama}
                            </option>
                        ))}
                    </select>
                </div>

                <div className="grid gap-4 md:grid-cols-3 print:hidden">
                    <Ringkasan judul="Total tunggakan" isi={rupiah(kartu.total_tunggakan)} />
                    <Ringkasan judul="Siswa menunggak" isi={`${kartu.siswa_menunggak} siswa`} />
                    <Ringkasan judul="Transfer menunggu verifikasi" isi={`${kartu.menunggu_verifikasi} tagihan`} />
                </div>

                <div className="flex gap-1 border-b print:hidden">
                    {(['rekap', 'ringkasan'] as const).map((t) => (
                        <button
                            key={t}
                            type="button"
                            onClick={() => setTab(t)}
                            className={cn(
                                '-mb-px border-b-2 px-4 py-2 text-sm',
                                tab === t ? 'border-primary font-medium' : 'border-transparent text-muted-foreground hover:text-foreground',
                            )}
                        >
                            {t === 'rekap' ? 'Rekap per buku' : 'Ringkasan'}
                        </button>
                    ))}
                </div>

                {tab === 'ringkasan' ? (
                    <TabelRingkasan
                        ringkasan={ringkasan}
                        bulan={bulan}
                        bulanIni={bulanIni}
                        onBuka={(kunci) => {
                            setTab('rekap');
                            router.get(index(query({ buku: kunci })).url, {}, { preserveScroll: true });
                        }}
                    />
                ) : (
                    <>
                        <div className="flex flex-col gap-3 print:hidden">
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="text-sm text-muted-foreground">Buku</span>
                                <PilihBuku href={index(query({ buku: undefined })).url} aktif={!filter.buku}>
                                    Semua
                                </PilihBuku>
                                {buku.map((b) => (
                                    <PilihBuku key={b.kunci} href={index(query({ buku: b.kunci })).url} aktif={filter.buku === b.kunci}>
                                        {b.nama}
                                    </PilihBuku>
                                ))}
                            </div>

                            <div className="flex flex-wrap items-center gap-2">
                                <Form {...TagihanController.index.form()} options={{ preserveScroll: true }} className="flex flex-wrap items-center gap-2">
                                    <input type="hidden" name="tahun" value={tahunAjaran.id} />
                                    {filter.buku && <input type="hidden" name="buku" value={filter.buku} />}
                                    <select name="kelas" defaultValue={filter.kelas ?? ''} className={kelasPilihan}>
                                        <option value="">Semua kelas</option>
                                        {kelas.map((k) => (
                                            <option key={k.id} value={k.id}>
                                                {k.nama}
                                            </option>
                                        ))}
                                    </select>
                                    <select name="status" defaultValue={filter.status ?? ''} className={kelasPilihan}>
                                        <option value="">Semua status</option>
                                        <option value="tunggakan">Ada tunggakan</option>
                                        <option value="menunggu">Transfer menunggu verifikasi</option>
                                    </select>
                                    <Input name="nama" placeholder="Cari nama siswa" defaultValue={filter.nama} className="w-48" />
                                    <Button type="submit" variant="secondary">
                                        Terapkan
                                    </Button>
                                </Form>

                                <div className="ml-auto flex items-center gap-2">
                                    <div className="flex rounded-md border p-0.5" role="group" aria-label="Isi kotak">
                                        {[false, true].map((v) => (
                                            <button
                                                key={String(v)}
                                                type="button"
                                                onClick={() => ubahMode(v)}
                                                className={cn('rounded px-3 py-1 text-sm', lengkap === v ? 'bg-muted font-medium' : 'text-muted-foreground')}
                                            >
                                                {v ? 'Lengkap' : 'Ringkas'}
                                            </button>
                                        ))}
                                    </div>
                                    {/* Excel: nominal sebagai angka & warna status, satu sheet per buku (#54); ikut buku yang dipilih */}
                                    <Button variant="outline" asChild>
                                        <a href={excel({ query: { tahun: tahunAjaran.id, buku: filter.buku || undefined } }).url}>
                                            <FileSpreadsheet /> Unduh Excel
                                        </a>
                                    </Button>
                                    <Button variant="outline" onClick={() => window.print()}>
                                        <Printer /> Cetak
                                    </Button>
                                </div>
                            </div>

                            <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground">
                                {keterangan.map((k) => (
                                    <span key={k.status} className="inline-flex items-center gap-1.5">
                                        <ContohWarna status={k.status} /> {k.label}
                                    </span>
                                ))}
                                <span>C = cash · T = transfer · O = online · paraf = inisial staf yang mencatat</span>
                            </div>
                        </div>

                        {kelompok.length === 0 && (
                            <p className="rounded-xl border p-6 text-center text-sm text-muted-foreground">Tidak ada data yang cocok dengan filter.</p>
                        )}

                        {kelompok.map((k, i) => (
                            <section key={k.kunci} className={cn('flex flex-col gap-2', i > 0 && 'print:break-before-page')}>
                                <h2 className="text-base font-semibold print:text-sm">
                                    Rekap Iuran {k.nama}
                                    <span className="ml-2 text-sm font-normal text-muted-foreground print:text-black">
                                        Tahun ajaran {tahunAjaran.nama} · {k.baris.length} peserta
                                    </span>
                                </h2>
                                <TabelBuku baris={k.baris} bulan={bulan} bulanIni={bulanIni} lengkap={lengkap} disorot={disorot} onPilih={setDipilih} />
                            </section>
                        ))}

                        <p className="hidden text-[9px] print:block">
                            {namaBukuDipilih} · dicetak {dicetak} · C = cash, T = transfer, O = online, paraf = inisial staf yang
                            mencatat
                        </p>
                    </>
                )}
            </div>

            <RincianKotak dipilih={dipilih} bisaCatat={bisaCatat} kembali={url} onTutup={() => setDipilih(null)} />
        </>
    );
}

function PilihBuku({ href, aktif, children }: { href: string; aktif: boolean; children: React.ReactNode }) {
    return (
        <Link
            href={href}
            preserveScroll
            className={cn(
                'rounded-md border px-3 py-1 text-sm',
                aktif ? 'border-primary bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-muted hover:text-foreground',
            )}
        >
            {children}
        </Link>
    );
}

function Ringkasan({ judul, isi }: { judul: string; isi: string }) {
    return (
        <Card className="gap-2 py-4">
            <CardHeader className="px-4">
                <CardTitle className="text-sm font-normal text-muted-foreground">{judul}</CardTitle>
            </CardHeader>
            <CardContent className="px-4 text-2xl font-semibold">{isi}</CardContent>
        </Card>
    );
}

RekapIuran.layout = {
    breadcrumbs: [{ title: 'Rekap Iuran', href: index() }],
};
