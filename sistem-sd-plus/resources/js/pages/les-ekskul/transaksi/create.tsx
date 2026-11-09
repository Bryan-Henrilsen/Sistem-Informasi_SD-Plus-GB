import { Form, Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import TagihanController from '@/actions/App/Domains/LesEkskul/Http/Controllers/TagihanController';
import TransaksiController from '@/actions/App/Domains/LesEkskul/Http/Controllers/TransaksiController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { namaBulan, rupiah } from '@/lib/format';
import { create, index } from '@/routes/les-ekskul/transaksi';

type Siswa = { id: number; nama: string; kelas: string | null };

type Tagihan = {
    id: number;
    pendaftaran_id: number;
    kegiatan: string;
    bulan: string;
    harus_dibayar: number;
    sisa: number;
};

type Props = {
    cari: string;
    hasilCari: Siswa[];
    siswa: Siswa | null;
    tagihan: Tagihan[];
    pendaftaran: { id: number; kegiatan: string }[];
    hariIni: string;
};

export default function CatatPembayaran({ cari, hasilCari, siswa, tagihan, pendaftaran, hariIni }: Props) {
    return (
        <>
            <Head title="Catat Pembayaran" />

            <div className="flex max-w-3xl flex-col gap-6 p-4">
                <Heading
                    title="Catat Pembayaran"
                    description="Cari siswa, centang tagihan yang dibayar, lalu isi nominal uang yang diterima."
                />

                {siswa ? (
                    <FormPembayaran
                        key={siswa.id}
                        siswa={siswa}
                        tagihan={tagihan}
                        pendaftaran={pendaftaran}
                        hariIni={hariIni}
                    />
                ) : (
                    <CariSiswa cari={cari} hasilCari={hasilCari} />
                )}
            </div>
        </>
    );
}

// Langkah 1: cari siswa berdasarkan nama (GET ke halaman yang sama dengan ?cari=...)
function CariSiswa({ cari, hasilCari }: { cari: string; hasilCari: Siswa[] }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>Pilih siswa</CardTitle>
            </CardHeader>
            <CardContent className="flex flex-col gap-4">
                <Form {...TransaksiController.create.form()} className="flex gap-2">
                    <Input name="cari" defaultValue={cari} placeholder="Ketik nama siswa" autoFocus />
                    <Button type="submit" variant="secondary">
                        Cari
                    </Button>
                </Form>

                {cari && hasilCari.length === 0 && (
                    <p className="text-sm text-muted-foreground">Siswa tidak ditemukan.</p>
                )}

                <ul className="divide-y rounded-md border empty:hidden">
                    {hasilCari.map((s) => (
                        <li key={s.id}>
                            <Link
                                href={create({ query: { siswa: s.id } })}
                                className="flex justify-between p-3 hover:bg-muted/50"
                            >
                                <span className="font-medium">{s.nama}</span>
                                <span className="text-sm text-muted-foreground">{s.kelas}</span>
                            </Link>
                        </li>
                    ))}
                </ul>
            </CardContent>
        </Card>
    );
}

// Langkah 2: centang tagihan + isi data pembayaran, lalu kirim ke TransaksiService::catat()
function FormPembayaran({ siswa, tagihan, pendaftaran, hariIni }: Omit<Props, 'cari' | 'hasilCari' | 'siswa'> & { siswa: Siswa }) {
    const form = useForm({
        siswa_id: siswa.id,
        metode: 'cash',
        tanggal: hariIni,
        nominal: 0,
        tagihan_ids: [] as number[],
        catatan_transfer: '',
    });
    const [nominalDiubah, setNominalDiubah] = useState(false); // false = nominal mengikuti total tagihan dicentang

    const totalDicentang = tagihan
        .filter((t) => form.data.tagihan_ids.includes(t.id))
        .reduce((jumlah, t) => jumlah + t.sisa, 0);
    const selisih = form.data.nominal - totalDicentang;

    function centang(id: number, dicentang: boolean) {
        const ids = dicentang ? [...form.data.tagihan_ids, id] : form.data.tagihan_ids.filter((x) => x !== id);
        const total = tagihan.filter((t) => ids.includes(t.id)).reduce((jumlah, t) => jumlah + t.sisa, 0);

        form.setData({ ...form.data, tagihan_ids: ids, nominal: nominalDiubah ? form.data.nominal : total });
    }

    function tambahBulanBerikutnya(pendaftaranId: number) {
        router.post(TagihanController.bayarDiMuka.url(), { pendaftaran_id: pendaftaranId }, { preserveScroll: true });
    }

    function simpan(e: React.FormEvent) {
        e.preventDefault();
        form.post(TransaksiController.store.url());
    }

    // Pesan dari TransaksiService (ValidationException) memakai kunci 'tagihan' dan 'laporan'
    const errors = form.errors as Record<string, string | undefined>;

    return (
        <form onSubmit={simpan} className="flex flex-col gap-6">
            <Card>
                <CardHeader className="flex flex-row items-center justify-between">
                    <div>
                        <CardTitle>{siswa.nama}</CardTitle>
                        <p className="text-sm text-muted-foreground">{siswa.kelas}</p>
                    </div>
                    <Button variant="ghost" size="sm" asChild>
                        <Link href={create()}>Ganti siswa</Link>
                    </Button>
                </CardHeader>
                <CardContent className="flex flex-col gap-3">
                    <Label>Tagihan yang dibayar</Label>
                    {tagihan.length === 0 && (
                        <p className="text-sm text-muted-foreground">Semua tagihan siswa ini sudah lunas.</p>
                    )}
                    <ul className="divide-y rounded-md border empty:hidden">
                        {tagihan.map((t) => (
                            <li key={t.id}>
                                <label className="flex cursor-pointer items-center gap-3 p-3 hover:bg-muted/50">
                                    <Checkbox
                                        checked={form.data.tagihan_ids.includes(t.id)}
                                        onCheckedChange={(v) => centang(t.id, v === true)}
                                    />
                                    <span className="flex-1">
                                        <span className="font-medium">{t.kegiatan}</span>{' '}
                                        <span className="text-muted-foreground">– {namaBulan(t.bulan)}</span>
                                    </span>
                                    <span className="text-right">
                                        {rupiah(t.sisa)}
                                        {t.sisa < t.harus_dibayar && (
                                            <span className="block text-xs text-muted-foreground">
                                                sisa dari {rupiah(t.harus_dibayar)}
                                            </span>
                                        )}
                                    </span>
                                </label>
                            </li>
                        ))}
                    </ul>
                    <InputError message={errors.tagihan_ids ?? errors.tagihan} />

                    {pendaftaran.length > 0 && (
                        <div className="flex flex-wrap items-center gap-2 text-sm">
                            <span className="text-muted-foreground">Bayar di muka:</span>
                            {pendaftaran.map((p) => (
                                <Button
                                    key={p.id}
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onClick={() => tambahBulanBerikutnya(p.id)}
                                >
                                    + bulan berikutnya {p.kegiatan}
                                </Button>
                            ))}
                        </div>
                    )}
                </CardContent>
            </Card>

            <Card>
                <CardContent className="grid gap-4 sm:grid-cols-2">
                    <div className="grid gap-2">
                        <Label htmlFor="metode">Metode</Label>
                        <select
                            id="metode"
                            value={form.data.metode}
                            onChange={(e) => form.setData('metode', e.target.value)}
                            className="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs"
                        >
                            <option value="cash">Cash (di loket TU)</option>
                            <option value="transfer">Transfer manual</option>
                        </select>
                        <InputError message={errors.metode} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="tanggal">Tanggal uang masuk</Label>
                        <Input
                            id="tanggal"
                            type="date"
                            max={hariIni}
                            value={form.data.tanggal}
                            onChange={(e) => form.setData('tanggal', e.target.value)}
                        />
                        <InputError message={errors.tanggal ?? errors.laporan} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="nominal">Nominal diterima (Rp)</Label>
                        <Input
                            id="nominal"
                            type="number"
                            min={1}
                            value={form.data.nominal || ''}
                            onChange={(e) => {
                                setNominalDiubah(true);
                                form.setData('nominal', Number(e.target.value));
                            }}
                        />
                        <InputError message={errors.nominal} />
                    </div>

                    {form.data.metode === 'transfer' && (
                        <div className="grid gap-2">
                            <Label htmlFor="catatan_transfer">Catatan transfer</Label>
                            <Input
                                id="catatan_transfer"
                                placeholder="mis. a.n. pengirim / no. referensi"
                                value={form.data.catatan_transfer}
                                onChange={(e) => form.setData('catatan_transfer', e.target.value)}
                            />
                        </div>
                    )}

                    <div className="rounded-md bg-muted/50 p-3 text-sm sm:col-span-2">
                        Total tagihan dicentang: <strong>{rupiah(totalDicentang)}</strong>
                        {selisih < 0 && form.data.nominal > 0 && (
                            <> · kurang {rupiah(-selisih)} (tagihan terakhir terbayar sebagian)</>
                        )}
                        {selisih > 0 && form.data.metode === 'transfer' && (
                            <> · kelebihan {rupiah(selisih)} dialihkan ke bulan berikutnya, sisanya direfund</>
                        )}
                        {selisih > 0 && form.data.metode === 'cash' && (
                            <> · cash tidak boleh melebihi tagihan, berikan kembalian {rupiah(selisih)}</>
                        )}
                    </div>
                </CardContent>
            </Card>

            <div className="flex gap-3">
                <Button type="submit" disabled={form.processing}>
                    Simpan pembayaran
                </Button>
                <Button type="button" variant="ghost" asChild>
                    <Link href={index()}>Batal</Link>
                </Button>
            </div>
        </form>
    );
}

CatatPembayaran.layout = {
    breadcrumbs: [
        { title: 'Transaksi', href: index() },
        { title: 'Catat Pembayaran', href: create() },
    ],
};
