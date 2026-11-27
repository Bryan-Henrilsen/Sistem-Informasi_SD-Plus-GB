import { router } from '@inertiajs/react';
import { X } from 'lucide-react';
import { useState } from 'react';
import RekapHonorController from '@/actions/App/Domains/LesEkskul/Http/Controllers/RekapHonorController';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { rupiah } from '@/lib/format';

type Penerima = { user_id: number; nama: string | null; jumlah: number };

export type HonorFlatPeriodeData = {
    id: number;
    nama: string;
    nominal_per_orang: number;
    dibayar: boolean;
    penerima: Penerima[];
    usulan: Penerima[]; // penerima & jumlah dari halaman Skema Honor
    total: number;
};

export type OrangPenerima = { id: number; nama: string; tanpa_akun: boolean };

const kelasPilihan = 'h-9 min-w-0 flex-1 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';

// Honor flat khusus periode ini (Ringkasan v1.11 #39): Kepala Sekolah memilih jenis yang dibayar dan jumlah per penerima,
// mis. bulan ini tidak ada Pramuka, atau jumlah soal Pembuat Soal berbeda. Hitung ulang honor tidak menimpanya
export function HonorFlatPeriode({
    periodeId,
    data,
    orang,
    bisaUbah,
}: {
    periodeId: number;
    data: HonorFlatPeriodeData[];
    orang: OrangPenerima[];
    bisaUbah: boolean;
}) {
    return (
        <div className="flex flex-col gap-3 print:hidden">
            <div>
                <h3 className="font-medium">Honor flat periode ini</h3>
                <p className="text-sm text-muted-foreground">
                    {bisaUbah
                        ? 'Terisi otomatis dari Skema Honor saat honor pertama kali dihitung. Hapus centang jenis yang tidak ada bulan ini, atau ubah jumlahnya. Hitung ulang tidak mengubah pilihan ini.'
                        : 'Diatur Kepala Sekolah. Nominal per orang mengikuti halaman Skema Honor.'}
                </p>
            </div>
            <div className="grid gap-4 lg:grid-cols-2">
                {data.map((j) => (
                    <KartuFlat key={`${j.id}-${j.total}-${j.penerima.length}`} periodeId={periodeId} jenis={j} orang={orang} bisaUbah={bisaUbah} />
                ))}
            </div>
        </div>
    );
}

function KartuFlat({ periodeId, jenis, orang, bisaUbah }: { periodeId: number; jenis: HonorFlatPeriodeData; orang: OrangPenerima[]; bisaUbah: boolean }) {
    const [dibayar, setDibayar] = useState(jenis.dibayar);
    const [penerima, setPenerima] = useState<Penerima[]>(jenis.dibayar ? jenis.penerima : jenis.usulan);
    const [tambah, setTambah] = useState('');
    const [proses, setProses] = useState(false);

    const total = dibayar ? penerima.reduce((jumlah, p) => jumlah + p.jumlah * jenis.nominal_per_orang, 0) : 0;
    const belumDipilih = orang.filter((o) => !penerima.some((p) => p.user_id === o.id));

    function ubahJumlah(userId: number, jumlah: number) {
        setPenerima((lama) => lama.map((p) => (p.user_id === userId ? { ...p, jumlah: Math.max(1, jumlah || 1) } : p)));
    }

    function tambahkan() {
        const o = orang.find((x) => String(x.id) === tambah);

        if (o) {
            setPenerima((lama) => [...lama, { user_id: o.id, nama: o.nama, jumlah: 1 }]);
            setTambah('');
        }
    }

    function simpan() {
        router.post(
            RekapHonorController.aturFlat.url({ periode: periodeId, honorFlatJenis: jenis.id }),
            { penerima: dibayar ? penerima.map((p) => ({ user_id: p.user_id, jumlah: p.jumlah })) : [] },
            { preserveScroll: true, onStart: () => setProses(true), onFinish: () => setProses(false) },
        );
    }

    if (!bisaUbah) {
        return (
            <Card className="gap-2 py-4">
                <CardHeader className="flex flex-row items-center justify-between gap-2 px-4">
                    <CardTitle className="text-base">{jenis.nama}</CardTitle>
                    <span className="text-sm tabular-nums">{jenis.dibayar ? rupiah(jenis.total) : 'Tidak dibayar'}</span>
                </CardHeader>
                {jenis.dibayar && (
                    <CardContent className="px-4 text-sm">
                        <ul className="divide-y">
                            {jenis.penerima.map((p) => (
                                <li key={p.user_id} className="flex justify-between py-1">
                                    <span>{p.nama}</span>
                                    <span className="text-muted-foreground">×{p.jumlah}</span>
                                </li>
                            ))}
                        </ul>
                    </CardContent>
                )}
            </Card>
        );
    }

    return (
        <Card className="gap-3 py-4">
            <CardHeader className="flex flex-row items-center justify-between gap-2 px-4">
                <label className="flex cursor-pointer items-center gap-2">
                    <Checkbox checked={dibayar} onCheckedChange={(v) => setDibayar(v === true)} />
                    <CardTitle className="text-base">{jenis.nama}</CardTitle>
                </label>
                <span className="text-sm text-muted-foreground tabular-nums">
                    {rupiah(jenis.nominal_per_orang)} / orang · total {rupiah(total)}
                </span>
            </CardHeader>
            <CardContent className="flex flex-col gap-3 px-4">
                {!dibayar ? (
                    <p className="text-sm text-muted-foreground">Tidak dibayar di periode ini.</p>
                ) : (
                    <>
                        {jenis.nominal_per_orang === 0 && (
                            <p className="text-sm text-sekolah-tertunda">Nominal belum diatur di halaman Skema Honor.</p>
                        )}
                        <ul className="divide-y rounded-md border text-sm empty:hidden">
                            {penerima.map((p) => (
                                <li key={p.user_id} className="flex items-center gap-2 p-2">
                                    <span className="flex-1">{p.nama}</span>
                                    <span className="text-xs text-muted-foreground">jumlah</span>
                                    <Input
                                        type="number"
                                        min={1}
                                        value={p.jumlah}
                                        onChange={(e) => ubahJumlah(p.user_id, Number(e.target.value))}
                                        className="h-8 w-16"
                                        aria-label={`Jumlah untuk ${p.nama}`}
                                    />
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        className="size-7"
                                        title="Hapus penerima"
                                        onClick={() => setPenerima((lama) => lama.filter((x) => x.user_id !== p.user_id))}
                                    >
                                        <X className="size-4" />
                                    </Button>
                                </li>
                            ))}
                        </ul>
                        <div className="flex gap-2">
                            <select value={tambah} onChange={(e) => setTambah(e.target.value)} className={kelasPilihan} aria-label="Tambah penerima">
                                <option value="">Tambah penerima…</option>
                                {belumDipilih.map((o) => (
                                    <option key={o.id} value={o.id}>
                                        {o.nama}
                                        {o.tanpa_akun ? ' (tanpa akun)' : ''}
                                    </option>
                                ))}
                            </select>
                            <Button variant="outline" size="sm" className="h-9" disabled={tambah === ''} onClick={tambahkan}>
                                Tambah
                            </Button>
                        </div>
                        {jenis.usulan.length > 0 && (
                            <button type="button" className="self-start text-xs text-muted-foreground underline underline-offset-4" onClick={() => setPenerima(jenis.usulan)}>
                                Kembalikan ke penerima & jumlah di Skema Honor
                            </button>
                        )}
                    </>
                )}
                <Button size="sm" className="self-end" disabled={proses || (dibayar && penerima.length === 0)} onClick={simpan}>
                    Simpan
                </Button>
            </CardContent>
        </Card>
    );
}
