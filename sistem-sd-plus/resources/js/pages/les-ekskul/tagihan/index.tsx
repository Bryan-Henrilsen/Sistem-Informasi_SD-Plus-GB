import { Form, Head, Link } from '@inertiajs/react';
import TagihanController from '@/actions/App/Domains/LesEkskul/Http/Controllers/TagihanController';
import Heading from '@/components/heading';
import { Paginasi } from '@/components/les-ekskul/paginasi';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { namaBulan, rupiah } from '@/lib/format';
import { cn } from '@/lib/utils';
import { index } from '@/routes/les-ekskul/tagihan';
import { create } from '@/routes/les-ekskul/transaksi';
import type { Paginasi as PaginasiData, StatusTagihan } from '@/types/les-ekskul';

type SelBulan = { status: StatusTagihan; harus_dibayar: number; sisa: number; };

type Baris = {
    id: number;
    siswa: { id: number; nama: string };
    kelas: string | null;
    kegiatan: string;
    status: string;
    bulan: Record<string, SelBulan>;
    tunggakan: number;
};

type Props = {
    pendaftaran: PaginasiData<Baris>;
    bulan: string[];
    kelas: { id: number; nama: string }[];
    ringkasan: {
        total_tunggakan: number;
        siswa_menunggak: number;
        bulan_ini_lunas: number;
        bulan_ini_total: number;
    };
    filter: { nama?: string; kelas?: string; status?: string };
};

const kelasPilihan =
    'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';

// Bulan "YYYY-MM" yang sedang berjalan; tagihan sebelum bulan ini yang belum lunas = tunggakan
const sekarang = new Date();
const bulanIni = `${sekarang.getFullYear()}-${String(sekarang.getMonth() + 1).padStart(2, '0')}`;

export default function TagihanIndex({ pendaftaran, bulan, kelas, ringkasan, filter }: Props) {
    return (
        <>
            <Head title="Tagihan" />

            <div className="flex flex-col gap-6 p-4">
                <Heading
                    title="Tagihan"
                    description="Kartu iuran per kegiatan. Hijau = lunas, kuning = dibayar sebagian, merah = tunggakan, abu-abu = belum jatuh tempo."
                />

                <div className="grid gap-4 md:grid-cols-3">
                    <Ringkasan judul="Total tunggakan" isi={rupiah(ringkasan.total_tunggakan)} />
                    <Ringkasan judul="Siswa menunggak" isi={`${ringkasan.siswa_menunggak} siswa`} />
                    <Ringkasan
                        judul={`Tagihan ${namaBulan(bulanIni)} lunas`}
                        isi={`${ringkasan.bulan_ini_lunas} dari ${ringkasan.bulan_ini_total}`}
                    />
                </div>

                <Form
                    {...TagihanController.index.form()}
                    options={{ preserveScroll: true }}
                    className="flex flex-wrap items-center gap-2"
                >
                    <Input
                        name='nama'
                        placeholder='Cari nama siswa'
                        defaultValue={filter.nama}
                        className='w-56'
                    />
                    <select name="kelas" defaultValue={filter.kelas ?? ''} className={kelasPilihan}>
                        <option value="">Semua Kelas</option>
                        {kelas.map((k) => (
                            <option key={k.id} value={k.id}>
                                {k.nama}
                            </option>
                        ))}
                    </select>
                    <select name="status" defaultValue={filter.status ?? ''} className={kelasPilihan}>
                        <option value="">Semua Status</option>
                        <option value="tunggakan">Ada tunggakan</option>
                        <option value="lunas">Lunas semua</option>
                    </select>
                    <Button type="submit" variant="secondary">
                        Terapkan
                    </Button>
                </Form>

                <div className="overflow-x-auto rounded-xl border">
                    <table className='w-full text-sm'>
                        <thead className='bg-muted/50 text-left'>
                            <tr>
                                <th className='p-3'>Siswa</th>
                                <th className='p-3'>Kegiatan</th>
                                {bulan.map((b) => (
                                    <th key={b} className='p-2 text-center capitalize'>
                                        {namaBulan(b, true)}
                                    </th>
                                ))}
                                <th className="p-3 text-right">Tunggakan</th>
                                <th className="p-3" />
                            </tr>
                        </thead>
                        <tbody>
                            {pendaftaran.data.map((p) => (
                                <tr key={p.id} className="border-t">
                                    <td className="p-3">
                                        <div className="font-medium">{p.siswa.nama}</div>
                                        <div className="text-xs text-muted-foreground">{p.kelas}</div>
                                    </td>
                                    <td className="p-3">
                                        {p.kegiatan}
                                        {p.status !== 'aktif' && (
                                            <div className="text-xs text-muted-foreground">{p.status}</div>
                                        )}
                                    </td>
                                    {bulan.map((b) => (
                                        <td key={b} className="p-1 text-center">
                                            <SelTagihan bulan={b} sel={p.bulan[b]} />
                                        </td>
                                    ))}
                                    <td className="p-3 text-right font-medium">
                                        {p.tunggakan > 0 ? rupiah(p.tunggakan) : '–'}
                                    </td>
                                    <td className="p-3">
                                        <Button size="sm" variant="outline" asChild>
                                            <Link href={create({ query: { siswa: p.siswa.id } })}>
                                                Bayar
                                            </Link>
                                        </Button>
                                    </td>
                                </tr>
                            ))}
                            {pendaftaran.data.length === 0 && (
                                <tr>
                                    <td colSpan={bulan.length + 4} className="p-6 text-center text-muted-foreground">
                                        Tidak ada tagihan yang cocok dengan filter.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
                <Paginasi data={pendaftaran} />
            </div>
        </>
    );
}

function Ringkasan({ judul, isi }: { judul: string; isi: string }) {
    return (
        <Card className='gap-2 py-4'>
            <CardHeader className='px-4'>
                <CardTitle className='text-sm font-normal text-muted-foreground'>{judul}</CardTitle>
            </CardHeader>
            <CardContent className='px-4 text-2xl font-semibold'>{isi}</CardContent>
        </Card>
    );
}

// Satu kotak bulan: warna menunjukkan status, angka menunjukkan sisa yang belum dibayar
function SelTagihan({ bulan, sel }: { bulan: string; sel?: SelBulan }) {
    if (!sel) {
        return <span className="text-muted-foreground">-</span>;
    }

    const lunas = sel.status === 'lunas';
    const sebagian = !lunas && sel.sisa < sel.harus_dibayar;
    const tunggakan = !lunas && bulan < bulanIni;

    return (
        <div
            title={lunas ? 'Lunas' : `Sisa ${rupiah(sel.sisa)}`}
            className={cn(
                'rounded-md px-1 py-1 text-xs whitespace-nowrap',
                lunas && 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300',
                sebagian && 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
                !lunas && !sebagian && tunggakan && 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-300',
                !lunas && !sebagian && !tunggakan && 'bg-muted text-muted-foreground',
            )}
        >
            {lunas ? '✓' : `${Math.round(sel.sisa / 1000)}rb`}
        </div>
    )
}

TagihanIndex.layout = {
    breadcrumbs: [{ title: 'Tagihan', href: index() }],
}
