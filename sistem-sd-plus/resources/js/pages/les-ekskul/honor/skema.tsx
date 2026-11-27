import { Form, Head, router } from '@inertiajs/react';
import { useState } from 'react';
import SkemaHonorController from '@/actions/App/Domains/LesEkskul/Http/Controllers/SkemaHonorController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { rupiah, tanggal } from '@/lib/format';
import { cn } from '@/lib/utils';

type KolomPersen =
    | 'persen_pengajar'
    | 'persen_guru_pendamping'
    | 'persen_kas_sekolah'
    | 'persen_yayasan'
    | 'persen_penanggung_jawab'
    | 'persen_bendahara'
    | 'persen_admin_tu'
    | 'persen_admin_it';

type Skema = {
    tipe: 'ekstrakurikuler' | 'les_tambahan';
    jenis: 'internal' | 'eksternal' | null;
    berlaku_sejak: string | null;
    riwayat: number;
    persen: Record<KolomPersen, number | null>;
};

type HonorFlat = {
    id: number;
    kode: string;
    nama: string;
    nominal: number | null;
    berlaku_sejak: string | null;
    penerima: { user_id: number; jumlah: number }[];
};

type Orang = { id: number; nama: string; tanpa_akun: boolean };

type Props = {
    skema: Skema[];
    peran: { peran: string; label: string; user_id: number | null }[];
    honorFlat: HonorFlat[];
    orang: Orang[];
    bisaUbah: boolean;
    bisaTambahTanpaAkun: boolean;
};

const LABEL: Record<KolomPersen, string> = {
    persen_pengajar: 'Pengajar',
    persen_guru_pendamping: 'Guru pendamping',
    persen_kas_sekolah: 'Kas sekolah',
    persen_yayasan: 'Yayasan',
    persen_penanggung_jawab: 'Penanggung jawab',
    persen_bendahara: 'Bendahara',
    persen_admin_tu: 'Admin TU',
    persen_admin_it: 'Admin IT',
};

const kelasPilihan = 'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';

export default function SkemaHonorIndex({ skema, peran, honorFlat, orang, bisaUbah, bisaTambahTanpaAkun }: Props) {
    return (
        <>
            <Head title="Skema Honor" />

            <div className="flex flex-col gap-6 p-4">
                <Heading
                    title="Skema Honor"
                    description="Diatur Kepala Sekolah. Setiap perubahan berlaku mulai hari ini; honor periode yang sudah dihitung tidak berubah karena tersimpan sebagai salinan (snapshot)."
                />

                <div className="grid gap-4 lg:grid-cols-3">
                    {skema.map((s) => (
                        <KartuSkema key={`${s.tipe}-${s.jenis}`} skema={s} bisaUbah={bisaUbah} />
                    ))}
                </div>

                <KartuPeran peran={peran} orang={orang.filter((o) => !o.tanpa_akun)} bisaUbah={bisaUbah} />

                <div className="flex flex-col gap-3">
                    <div>
                        <h3 className="font-medium">Honor flat (di luar persentase)</h3>
                        <p className="text-sm text-muted-foreground">
                            Setiap penerima mendapat nominal penuh × jumlah (mis. Pembuat Soal Les per soal). Dibayar dari kas sekolah
                            setiap periode yang bulannya ditagih.
                        </p>
                    </div>
                    <div className="grid gap-4 lg:grid-cols-2">
                        {honorFlat.map((h) => (
                            <KartuHonorFlat key={h.id} honor={h} orang={orang} bisaUbah={bisaUbah} />
                        ))}
                    </div>
                    {bisaTambahTanpaAkun && <TambahTanpaAkun />}
                </div>
            </div>
        </>
    );
}

function KartuSkema({ skema, bisaUbah }: { skema: Skema; bisaUbah: boolean }) {
    const les = skema.tipe === 'les_tambahan';
    const kolom = (Object.keys(LABEL) as KolomPersen[]).filter((k) =>
        les ? k !== 'persen_guru_pendamping' : k !== 'persen_yayasan',
    );
    const [nilai, setNilai] = useState<Record<string, string>>(
        Object.fromEntries(kolom.map((k) => [k, skema.persen[k] === null ? '0' : String(skema.persen[k])])),
    );
    const total = Math.round(kolom.reduce((jumlah, k) => jumlah + (Number(nilai[k]) || 0), 0) * 100) / 100;
    const [errors, setErrors] = useState<Record<string, string>>({});

    function simpan() {
        router.post(
            SkemaHonorController.simpanSkema.url(),
            { tipe: skema.tipe, jenis: skema.jenis, persen: nilai },
            { preserveScroll: true, onError: setErrors, onSuccess: () => setErrors({}) },
        );
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle>
                    {les ? 'Les tambahan' : `Ekskul — pengajar ${skema.jenis}`}
                </CardTitle>
                <p className="text-xs text-muted-foreground">
                    {skema.berlaku_sejak ? `Berlaku sejak ${tanggal(skema.berlaku_sejak)} · ${skema.riwayat} versi` : 'Belum diatur'}
                    {!les && skema.jenis === 'internal' && ' · tanpa pendamping, porsinya sudah termasuk pengajar'}
                    {!les && skema.jenis === 'eksternal' && ' · wajib ada guru pendamping'}
                </p>
            </CardHeader>
            <CardContent className="flex flex-col gap-2">
                {kolom.map((k) => (
                    <div key={k} className="flex items-center justify-between gap-3 text-sm">
                        <Label htmlFor={`${skema.tipe}-${skema.jenis}-${k}`}>{LABEL[k]}</Label>
                        <div className="flex items-center gap-1">
                            <Input
                                id={`${skema.tipe}-${skema.jenis}-${k}`}
                                type="number"
                                step="0.01"
                                min={0}
                                max={100}
                                disabled={!bisaUbah}
                                value={nilai[k]}
                                onChange={(e) => setNilai({ ...nilai, [k]: e.target.value })}
                                className="h-8 w-24 text-right"
                            />
                            <span>%</span>
                        </div>
                    </div>
                ))}
                <p className={cn('mt-1 text-right text-sm font-medium', total === 100 ? 'text-sekolah-sukses' : 'text-sekolah-bahaya')}>
                    Total {total}%
                </p>
                <InputError message={errors.persen} />
                {bisaUbah && (
                    <Button className="self-end" size="sm" disabled={total !== 100} onClick={simpan}>
                        Simpan skema
                    </Button>
                )}
            </CardContent>
        </Card>
    );
}

function KartuPeran({ peran, orang, bisaUbah }: { peran: Props['peran']; orang: Orang[]; bisaUbah: boolean }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>Penerima porsi pengelola</CardTitle>
                <p className="text-xs text-muted-foreground">
                    Siapa yang menerima porsi penanggung jawab, bendahara, admin TU, dan admin IT. Pergantian orang berlaku mulai hari ini.
                </p>
            </CardHeader>
            <CardContent>
                <Form {...SkemaHonorController.simpanPeran.form()} options={{ preserveScroll: true }} className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    {({ processing }) => (
                        <>
                            {peran.map((p) => (
                                <div key={p.peran} className="grid gap-1 text-sm">
                                    <Label className="capitalize">{p.label}</Label>
                                    <select name={`penerima[${p.peran}]`} defaultValue={p.user_id ?? ''} disabled={!bisaUbah} className={kelasPilihan}>
                                        <option value="">Belum diatur</option>
                                        {orang.map((o) => (
                                            <option key={o.id} value={o.id}>
                                                {o.nama}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                            ))}
                            {bisaUbah && (
                                <Button type="submit" size="sm" className="self-end justify-self-start" disabled={processing}>
                                    Simpan penerima
                                </Button>
                            )}
                        </>
                    )}
                </Form>
            </CardContent>
        </Card>
    );
}

// Honor flat satu jenis: nominal per orang dan daftar penerima beserta jumlahnya (FR-25b)
function KartuHonorFlat({ honor, orang, bisaUbah }: { honor: HonorFlat; orang: Orang[]; bisaUbah: boolean }) {
    const [nominal, setNominal] = useState(String(honor.nominal ?? ''));
    const [penerima, setPenerima] = useState<Record<number, number>>(
        Object.fromEntries(honor.penerima.map((p) => [p.user_id, p.jumlah])),
    );
    const [cari, setCari] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const dipilih = Object.entries(penerima);
    const total = dipilih.reduce((jumlah, [, n]) => jumlah + n * (Number(nominal) || 0), 0);
    const tampil = orang.filter((o) => o.nama.toLowerCase().includes(cari.toLowerCase()));

    function centang(id: number, ya: boolean) {
        const baru = { ...penerima };
        if (ya) {
            baru[id] = baru[id] ?? 1;
        } else {
            delete baru[id];
        }
        setPenerima(baru);
    }

    function simpan() {
        router.post(
            SkemaHonorController.simpanHonorFlat.url(honor.id),
            { nominal: Number(nominal) || 0, penerima: dipilih.map(([id, jumlah]) => ({ user_id: Number(id), jumlah })) },
            { preserveScroll: true, onError: setErrors, onSuccess: () => setErrors({}) },
        );
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle>{honor.nama}</CardTitle>
                <p className="text-xs text-muted-foreground">
                    {honor.berlaku_sejak ? `Berlaku sejak ${tanggal(honor.berlaku_sejak)}` : 'Belum diatur'} · {dipilih.length} penerima ·
                    total {rupiah(total)} per periode
                </p>
            </CardHeader>
            <CardContent className="flex flex-col gap-3 text-sm">
                <div className="flex items-center gap-2">
                    <Label htmlFor={`nominal-${honor.id}`}>Nominal per orang (Rp)</Label>
                    <Input
                        id={`nominal-${honor.id}`}
                        type="number"
                        min={0}
                        disabled={!bisaUbah}
                        value={nominal}
                        onChange={(e) => setNominal(e.target.value)}
                        className="h-8 w-32"
                    />
                </div>
                <InputError message={errors.nominal} />

                <Input placeholder="Cari nama" value={cari} onChange={(e) => setCari(e.target.value)} className="h-8" />
                <ul className="max-h-56 divide-y overflow-y-auto rounded-md border">
                    {tampil.map((o) => {
                        const ya = penerima[o.id] !== undefined;

                        return (
                            <li key={o.id} className="flex items-center gap-3 p-2">
                                <Checkbox disabled={!bisaUbah} checked={ya} onCheckedChange={(v) => centang(o.id, v === true)} />
                                <span className="flex-1">
                                    {o.nama}
                                    {o.tanpa_akun && <span className="ml-1 text-xs text-muted-foreground">(tanpa akun)</span>}
                                </span>
                                {ya && (
                                    <span className="flex items-center gap-1 text-xs text-muted-foreground">
                                        ×
                                        <Input
                                            type="number"
                                            min={1}
                                            disabled={!bisaUbah}
                                            value={penerima[o.id]}
                                            onChange={(e) => setPenerima({ ...penerima, [o.id]: Math.max(1, Number(e.target.value) || 1) })}
                                            className="h-7 w-16"
                                        />
                                    </span>
                                )}
                            </li>
                        );
                    })}
                </ul>
                {bisaUbah && (
                    <Button size="sm" className="self-end" onClick={simpan}>
                        Simpan {honor.nama}
                    </Button>
                )}
            </CardContent>
        </Card>
    );
}

// Penerima honor flat tanpa akun login (mis. petugas kebersihan); tidak bisa membuka menu apa pun
function TambahTanpaAkun() {
    return (
        <Form
            {...SkemaHonorController.tambahTanpaAkun.form()}
            options={{ preserveScroll: true }}
            resetOnSuccess
            className="flex flex-wrap items-start gap-2 rounded-md border p-3 text-sm"
        >
            {({ processing, errors }) => (
                <>
                    <div className="flex flex-col gap-1">
                        <span className="text-muted-foreground">Penerima tanpa akun login (mis. petugas kebersihan)</span>
                        <div className="flex gap-2">
                            <Input name="nama" placeholder="Nama" className="h-8 w-56" />
                            <Button type="submit" size="sm" variant="outline" disabled={processing}>
                                Tambah
                            </Button>
                        </div>
                        <InputError message={errors.nama} />
                    </div>
                </>
            )}
        </Form>
    );
}

SkemaHonorIndex.layout = {
    breadcrumbs: [{ title: 'Skema Honor', href: SkemaHonorController.index.url() }],
};
