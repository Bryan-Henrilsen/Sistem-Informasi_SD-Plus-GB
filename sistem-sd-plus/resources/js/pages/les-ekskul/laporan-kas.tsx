import { Form, Head, router } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { useState } from 'react';
import LaporanKasController from '@/actions/App/Domains/LesEkskul/Http/Controllers/LaporanKasController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { namaBulan, rupiah, tanggal } from '@/lib/format';
import { cn } from '@/lib/utils';

type Baris = {
    uraian: string;
    masuk: number;
    keluar: number;
    saldo: number;
    manual: { id: number; tanggal: string; dicatat_oleh: string | null } | null;
};
type Bagian = { saldo_lalu: number; baris: Baris[]; masuk: number; keluar: number; saldo: number };
type Laporan = {
    siap: boolean;
    terkunci: boolean;
    periode_belum_lengkap: string[];
    sekolah: Bagian;
    yayasan: Bagian;
    titipan: number;
    saldo_total: number;
};
type Kategori = { value: string; label: string; masuk: boolean; saldo_awal: boolean; butuh_uraian: boolean };
type Periode = { id: number; bulan: string; tanggal_mulai: string; tanggal_selesai: string; status: string; pertama: boolean };

type Props = {
    daftarPeriode: { id: number; bulan: string; status: string }[];
    periode: Periode | null;
    laporan: Laporan | null;
    kategori: Kategori[];
    bisaCatat: boolean;
};

const kelasPilihan = 'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';

export default function LaporanKas({ daftarPeriode, periode, laporan, kategori, bisaCatat }: Props) {
    const bisaUbah = bisaCatat && laporan !== null && !laporan.terkunci;
    const [setor, setSetor] = useState(false);

    function hapus(id: number) {
        router.delete(LaporanKasController.destroy(id).url, { preserveScroll: true });
    }

    return (
        <>
            <Head title="Laporan Kas" />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4 print:hidden">
                    <Heading
                        title="Laporan Keuangan Kas"
                        description="Kas sekolah dan kas yayasan per periode: angka dari honor yang sudah dihitung ditambah catatan manual Bendahara/Kepala Sekolah. Saldo dibawa ke periode berikutnya."
                    />
                    {laporan?.siap && (
                        <Button variant="outline" onClick={() => window.print()}>
                            Cetak
                        </Button>
                    )}
                </div>

                <div className="flex flex-wrap items-center gap-3 print:hidden">
                    <select
                        value={periode?.id ?? ''}
                        onChange={(e) => router.get(LaporanKasController.index({ query: { periode: e.target.value } }).url)}
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

                {periode && laporan && (
                    <>
                        {laporan.terkunci && <Pesan warna="hijau">Honor periode ini sudah ditandai Sudah Diketahui oleh Kepala Sekolah; laporan kas terkunci.</Pesan>}
                        {!laporan.siap && (
                            <Pesan warna="biru">
                                Kas dari kegiatan (ekstra, les, yayasan, honor flat) muncul setelah buku ditutup dan honor periode ini dihitung. Catatan manual
                                sudah bisa dicatat sekarang.
                            </Pesan>
                        )}
                        {laporan.periode_belum_lengkap.length > 0 && (
                            <Pesan warna="kuning">
                                Saldo periode lalu belum lengkap: honor periode {laporan.periode_belum_lengkap.map((b) => namaBulan(b)).join(', ')} belum dihitung,
                                jadi hanya catatan manualnya yang terbawa.
                            </Pesan>
                        )}

                        <div className="hidden text-center print:block">
                            <h2 className="text-lg font-semibold">LAPORAN KEUANGAN KEGIATAN SD PLUS GEMBALA BAIK</h2>
                            <p className="text-sm">Periode {namaBulan(periode.bulan)}</p>
                        </div>

                        <TabelKas judul="Kas Sekolah" labelLalu="Saldo kas sekolah periode lalu" bagian={laporan.sekolah} bisaUbah={bisaUbah} onHapus={hapus} />
                        <TabelKas
                            judul="Kas Yayasan"
                            labelLalu="Saldo kas yayasan periode lalu"
                            bagian={laporan.yayasan}
                            bisaUbah={bisaUbah}
                            onHapus={hapus}
                            aksi={
                                bisaUbah &&
                                laporan.yayasan.saldo > 0 && (
                                    <Button size="sm" variant="outline" onClick={() => setSetor(true)}>
                                        Tandai sudah disetor
                                    </Button>
                                )
                            }
                        />

                        <Card className="gap-2 py-4 print:border-0 print:shadow-none">
                            <CardHeader className="px-4">
                                <CardTitle className="text-base">Saldo kas akhir periode</CardTitle>
                            </CardHeader>
                            <CardContent className="px-4">
                                <table className="w-full max-w-md text-sm">
                                    <tbody>
                                        <BarisRingkas label="Kas sekolah" nilai={laporan.sekolah.saldo} />
                                        <BarisRingkas label="Kas yayasan" nilai={laporan.yayasan.saldo} />
                                        <BarisRingkas label="Titipan untuk bulan berikutnya (bayar di muka)" nilai={laporan.titipan} />
                                        <tr className="border-t font-semibold">
                                            <td className="py-1.5">Saldo kas</td>
                                            <td className="py-1.5 text-right tabular-nums">{rupiah(laporan.saldo_total)}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </CardContent>
                        </Card>

                        <div className="hidden grid-cols-2 gap-8 pt-8 text-center text-sm print:grid">
                            <div>
                                <p>Mengetahui</p>
                                <p>Kepala Sekolah</p>
                                <div className="h-20" />
                                <p>(..............................)</p>
                            </div>
                            <div>
                                <p>&nbsp;</p>
                                <p>Bendahara Sekolah</p>
                                <div className="h-20" />
                                <p>(..............................)</p>
                            </div>
                        </div>

                        {bisaUbah && <FormCatat periode={periode} kategori={kategori} />}
                        <DialogSetorYayasan buka={setor} periode={periode} saldo={laporan.yayasan.saldo} onClose={() => setSetor(false)} />
                    </>
                )}
            </div>
        </>
    );
}

function TabelKas({
    judul,
    labelLalu,
    bagian,
    bisaUbah,
    onHapus,
    aksi,
}: {
    judul: string;
    labelLalu: string;
    bagian: Bagian;
    bisaUbah: boolean;
    onHapus: (id: number) => void;
    aksi?: React.ReactNode;
}) {
    const angka = (n: number) => (n === 0 ? '' : rupiah(n));

    return (
        <Card className="gap-2 py-4 print:border-0 print:shadow-none">
            <CardHeader className="flex flex-row items-center justify-between gap-2 px-4">
                <CardTitle className="text-base">{judul}</CardTitle>
                {aksi && <div className="print:hidden">{aksi}</div>}
            </CardHeader>
            <CardContent className="overflow-x-auto px-4">
                <table className="w-full text-sm">
                    <thead>
                        <tr className="border-b text-left text-muted-foreground">
                            <th className="w-10 py-2 pr-2">No</th>
                            <th className="py-2 pr-2">Uraian</th>
                            <th className="py-2 pr-2 text-right">Pemasukan</th>
                            <th className="py-2 pr-2 text-right">Pengeluaran</th>
                            <th className="py-2 text-right">Saldo</th>
                            {bisaUbah && <th className="w-10 print:hidden" />}
                        </tr>
                    </thead>
                    <tbody>
                        <tr className="border-b text-muted-foreground">
                            <td className="py-1.5 pr-2" />
                            <td className="py-1.5 pr-2">{labelLalu}</td>
                            <td />
                            <td />
                            <td className="py-1.5 text-right tabular-nums">{rupiah(bagian.saldo_lalu)}</td>
                            {bisaUbah && <td className="print:hidden" />}
                        </tr>
                        {bagian.baris.map((b, i) => (
                            <tr key={i} className="border-b last:border-0">
                                <td className="py-1.5 pr-2">{i + 1}</td>
                                <td className="py-1.5 pr-2">
                                    {b.uraian}
                                    {b.manual && (
                                        <span className="ml-2 text-xs text-muted-foreground print:hidden">
                                            {tanggal(b.manual.tanggal)}
                                            {b.manual.dicatat_oleh && ` · ${b.manual.dicatat_oleh}`}
                                        </span>
                                    )}
                                </td>
                                <td className="py-1.5 pr-2 text-right tabular-nums">{angka(b.masuk)}</td>
                                <td className="py-1.5 pr-2 text-right tabular-nums">{angka(b.keluar)}</td>
                                <td className="py-1.5 text-right tabular-nums">{rupiah(b.saldo)}</td>
                                {bisaUbah && (
                                    <td className="text-right print:hidden">
                                        {b.manual && (
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                className="size-7"
                                                title="Hapus catatan"
                                                onClick={() => b.manual && onHapus(b.manual.id)}
                                            >
                                                <Trash2 className="size-4" />
                                            </Button>
                                        )}
                                    </td>
                                )}
                            </tr>
                        ))}
                    </tbody>
                    <tfoot>
                        <tr className="border-t font-semibold">
                            <td />
                            <td className="py-1.5 pr-2">Total</td>
                            <td className="py-1.5 pr-2 text-right tabular-nums">{rupiah(bagian.masuk)}</td>
                            <td className="py-1.5 pr-2 text-right tabular-nums">{rupiah(bagian.keluar)}</td>
                            <td className="py-1.5 text-right tabular-nums">{rupiah(bagian.saldo)}</td>
                            {bisaUbah && <td className="print:hidden" />}
                        </tr>
                    </tfoot>
                </table>
            </CardContent>
        </Card>
    );
}

// Form catatan kas manual; saldo awal hanya ditawarkan di periode pertama
function FormCatat({ periode, kategori }: { periode: Periode; kategori: Kategori[] }) {
    const pilihan = kategori.filter((k) => !k.saldo_awal || periode.pertama);
    const [dipilih, setDipilih] = useState(pilihan.find((k) => k.value === 'pembayaran_kolam')?.value ?? pilihan[0]?.value ?? '');
    const kat = kategori.find((k) => k.value === dipilih);
    const hariIni = new Date().toLocaleDateString('sv-SE');

    return (
        <Card className="gap-3 py-4 print:hidden">
            <CardHeader className="px-4">
                <CardTitle className="text-base">Catat kas manual</CardTitle>
            </CardHeader>
            <CardContent className="px-4">
                <Form
                    {...LaporanKasController.store.form(periode.id)}
                    options={{ preserveScroll: true }}
                    resetOnSuccess={['nominal', 'uraian']}
                    className="grid gap-3 sm:grid-cols-2 lg:grid-cols-[1fr_10rem_10rem_1fr_auto] lg:items-end"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-1 text-sm">
                                <Label htmlFor="kategori">Kategori</Label>
                                <select id="kategori" name="kategori" value={dipilih} onChange={(e) => setDipilih(e.target.value)} className={kelasPilihan}>
                                    {pilihan.map((k) => (
                                        <option key={k.value} value={k.value}>
                                            {k.label} ({k.masuk ? 'masuk' : 'keluar'})
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.kategori ?? errors.periode} />
                            </div>
                            <div className="grid gap-1 text-sm">
                                <Label htmlFor="nominal">Nominal (Rp)</Label>
                                <Input id="nominal" name="nominal" type="number" min={1} inputMode="numeric" />
                                <InputError message={errors.nominal} />
                            </div>
                            <div className="grid gap-1 text-sm">
                                <Label htmlFor="tanggal">Tanggal</Label>
                                <Input id="tanggal" name="tanggal" type="date" defaultValue={hariIni} />
                                <InputError message={errors.tanggal} />
                            </div>
                            <div className="grid gap-1 text-sm">
                                <Label htmlFor="uraian">Uraian{kat?.butuh_uraian ? '' : ' (opsional)'}</Label>
                                <Input id="uraian" name="uraian" placeholder={kat?.butuh_uraian ? 'Wajib diisi' : 'mis. keterangan nota'} />
                                <InputError message={errors.uraian} />
                            </div>
                            <Button type="submit" disabled={processing}>
                                Simpan
                            </Button>
                        </>
                    )}
                </Form>
            </CardContent>
        </Card>
    );
}

// Pintasan mencatat setoran ke yayasan: nominal terisi saldo kas yayasan, boleh dikurangi bila disetor sebagian (#35)
function DialogSetorYayasan({ buka, periode, saldo, onClose }: { buka: boolean; periode: Periode; saldo: number; onClose: () => void }) {
    const hariIni = new Date().toLocaleDateString('sv-SE');

    return (
        <Dialog open={buka} onOpenChange={(b) => !b && onClose()}>
            <DialogContent>
                {buka && (
                    <Form
                        {...LaporanKasController.store.form(periode.id)}
                        options={{ preserveScroll: true }}
                        onSuccess={onClose}
                        className="flex flex-col gap-4"
                    >
                        {({ processing, errors }) => (
                            <>
                                <DialogHeader>
                                    <DialogTitle>Tandai kas yayasan sudah disetor</DialogTitle>
                                    <DialogDescription>
                                        Dicatat sebagai pengeluaran "Setor ke yayasan" di Kas Yayasan periode {namaBulan(periode.bulan)}. Saldo kas yayasan saat ini{' '}
                                        {rupiah(saldo)}; kurangi nominal bila hanya sebagian yang disetor.
                                    </DialogDescription>
                                </DialogHeader>
                                <input type="hidden" name="kategori" value="setor_yayasan" />
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <div className="grid gap-1 text-sm">
                                        <Label htmlFor="setor-nominal">Nominal (Rp)</Label>
                                        <Input id="setor-nominal" name="nominal" type="number" min={1} max={saldo} defaultValue={saldo} inputMode="numeric" />
                                        <InputError message={errors.nominal} />
                                    </div>
                                    <div className="grid gap-1 text-sm">
                                        <Label htmlFor="setor-tanggal">Tanggal setor</Label>
                                        <Input id="setor-tanggal" name="tanggal" type="date" defaultValue={hariIni} />
                                        <InputError message={errors.tanggal} />
                                    </div>
                                </div>
                                <div className="grid gap-1 text-sm">
                                    <Label htmlFor="setor-uraian">Uraian (opsional)</Label>
                                    <Input id="setor-uraian" name="uraian" placeholder="mis. diserahkan ke bendahara yayasan" />
                                    <InputError message={errors.uraian ?? errors.periode} />
                                </div>
                                <DialogFooter>
                                    <Button type="button" variant="ghost" onClick={onClose}>
                                        Batal
                                    </Button>
                                    <Button type="submit" disabled={processing}>
                                        Tandai disetor
                                    </Button>
                                </DialogFooter>
                            </>
                        )}
                    </Form>
                )}
            </DialogContent>
        </Dialog>
    );
}

function BarisRingkas({ label, nilai }: { label: string; nilai: number }) {
    return (
        <tr>
            <td className="py-1.5">{label}</td>
            <td className="py-1.5 text-right tabular-nums">{rupiah(nilai)}</td>
        </tr>
    );
}

const WARNA = {
    kuning: 'border-sekolah-tertunda/40 bg-sekolah-tertunda/10 text-sekolah-tertunda',
    biru: 'border-sekolah-info/40 bg-sekolah-info/10 text-sekolah-info',
    hijau: 'border-sekolah-sukses/40 bg-sekolah-sukses/10 text-sekolah-sukses',
};

function Pesan({ warna, children }: { warna: keyof typeof WARNA; children: React.ReactNode }) {
    return <div className={cn('rounded-md border p-3 text-sm print:hidden', WARNA[warna])}>{children}</div>;
}

LaporanKas.layout = {
    breadcrumbs: [{ title: 'Laporan Kas', href: LaporanKasController.index.url() }],
};
