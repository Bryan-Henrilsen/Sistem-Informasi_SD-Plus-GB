import { Head, Link, useForm } from '@inertiajs/react';
import PendaftaranController from '@/actions/App/Domains/LesEkskul/Http/Controllers/PendaftaranController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { CariSiswa } from '@/components/les-ekskul/cari-siswa';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { rupiah, tanggal } from '@/lib/format';
import { cn } from '@/lib/utils';
import { create, index } from '@/routes/les-ekskul/pendaftaran';
import type { SiswaRingkas, TipeKegiatan } from '@/types/les-ekskul';

// Satu kegiatan yang bisa dipilih, sudah diperiksa PendaftaranService::periksa()
type Pilihan = {
    jenis: TipeKegiatan;
    id: number;
    nama: string;
    biaya: number;
    jadwal: string[];
    kuota: number | null;
    terisi: number | null;
    punya_kelas_paralel: boolean;
    error: string | null; // tidak bisa dipilih (jenjang, sudah terdaftar, bentrok jadwal)
    peringatan: string | null; // kuota penuh: boleh setelah dikonfirmasi
};

type Props = {
    cari: string;
    hasilCari: SiswaRingkas[];
    siswa: SiswaRingkas | null;
    terdaftar: { id: number; kegiatan: string; tanggal_daftar: string }[];
    pilihan: Pilihan[];
    hariIni: string;
};

export default function TambahPendaftaran({ cari, hasilCari, siswa, terdaftar, pilihan, hariIni }: Props) {
    return (
        <>
            <Head title="Tambah Pendaftaran" />

            <div className="flex max-w-3xl flex-col gap-6 p-4">
                <Heading
                    title="Tambah Pendaftaran"
                    description="Input manual untuk pendaftaran yang telat atau luring. Cari siswa, lalu pilih les tambahan atau ekstrakurikuler."
                />

                {siswa ? (
                    <FormPendaftaran key={siswa.id} siswa={siswa} terdaftar={terdaftar} pilihan={pilihan} hariIni={hariIni} />
                ) : (
                    <CariSiswa
                        cari={cari}
                        hasilCari={hasilCari}
                        form={PendaftaranController.create.form()}
                        tautanSiswa={(id) => create({ query: { siswa: id } })}
                    />
                )}
            </div>
        </>
    );
}

function FormPendaftaran({ siswa, terdaftar, pilihan, hariIni }: Omit<Props, 'cari' | 'hasilCari' | 'siswa'> & { siswa: SiswaRingkas }) {
    const form = useForm({
        siswa_id: siswa.id,
        jenis: '' as TipeKegiatan | '',
        kegiatan_id: 0,
        tanggal_daftar: hariIni,
        abaikan_kuota: false,
    });

    const dipilih = pilihan.find((p) => p.jenis === form.data.jenis && p.id === form.data.kegiatan_id);
    const perluKonfirmasi = dipilih?.peringatan != null && !form.data.abaikan_kuota;

    function pilih(p: Pilihan) {
        form.setData({ ...form.data, jenis: p.jenis, kegiatan_id: p.id, abaikan_kuota: false });
        form.clearErrors();
    }

    function simpan(e: React.FormEvent) {
        e.preventDefault();
        form.post(PendaftaranController.store.url(), {
            preserveScroll: true,
            // Halaman dimuat ulang untuk siswa yang sama; kosongkan pilihan supaya TU bisa menambah kegiatan lain
            onSuccess: () => form.reset('jenis', 'kegiatan_id', 'abaikan_kuota'),
        });
    }

    // Pesan dari PendaftaranService memakai kunci 'kegiatan' dan 'kuota'
    const errors = form.errors as Record<string, string | undefined>;
    const les = pilihan.filter((p) => p.jenis === 'les_tambahan');
    const ekskul = pilihan.filter((p) => p.jenis === 'ekstrakurikuler');

    return (
        <form onSubmit={simpan} className="flex flex-col gap-6">
            <Card>
                <CardHeader className="flex flex-row items-center justify-between">
                    <div>
                        <CardTitle>{siswa.nama}</CardTitle>
                        <p className="text-sm text-muted-foreground">{siswa.kelas ?? 'Belum punya kelas di tahun ajaran aktif'}</p>
                    </div>
                    <Button variant="ghost" size="sm" asChild>
                        <Link href={create()}>Ganti siswa</Link>
                    </Button>
                </CardHeader>
                <CardContent className="text-sm">
                    <span className="text-muted-foreground">Sudah terdaftar: </span>
                    {terdaftar.length === 0
                        ? 'belum ada kegiatan.'
                        : terdaftar.map((t) => `${t.kegiatan} (sejak ${tanggal(t.tanggal_daftar)})`).join(', ')}
                </CardContent>
            </Card>

            {siswa.kelas === null ? (
                <p className="text-sm text-muted-foreground">
                    Siswa ini belum dimasukkan ke kelas tahun ajaran aktif, jadi belum bisa didaftarkan ke kegiatan.
                </p>
            ) : (
                <Card>
                    <CardHeader>
                        <CardTitle>Pilih kegiatan</CardTitle>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-4">
                        <DaftarPilihan judul="Les tambahan" pilihan={les} dipilih={dipilih} onPilih={pilih} />
                        <DaftarPilihan judul="Ekstrakurikuler" pilihan={ekskul} dipilih={dipilih} onPilih={pilih} />
                        <InputError message={errors.kegiatan ?? errors.kegiatan_id ?? errors.jenis} />

                        {dipilih?.peringatan && (
                            <div className="flex flex-col gap-2 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200">
                                <span>{dipilih.peringatan}</span>
                                <label className="flex items-center gap-2">
                                    <Checkbox
                                        checked={form.data.abaikan_kuota}
                                        onCheckedChange={(v) => form.setData('abaikan_kuota', v === true)}
                                    />
                                    Tetap daftarkan (kuota penuh diatur TU secara manual)
                                </label>
                                <InputError message={errors.kuota} />
                            </div>
                        )}

                        <div className="grid max-w-xs gap-2">
                            <Label htmlFor="tanggal_daftar">Tanggal daftar</Label>
                            <Input
                                id="tanggal_daftar"
                                type="date"
                                max={hariIni}
                                value={form.data.tanggal_daftar}
                                onChange={(e) => form.setData('tanggal_daftar', e.target.value)}
                            />
                            <p className="text-xs text-muted-foreground">
                                Tagihan dibuat mulai bulan tanggal daftar sampai periode laporan terakhir yang sudah dibuka.
                            </p>
                            <InputError message={errors.tanggal_daftar} />
                        </div>
                    </CardContent>
                </Card>
            )}

            <div className="flex gap-3">
                <Button type="submit" disabled={!dipilih || perluKonfirmasi || form.processing}>
                    Simpan pendaftaran
                </Button>
                <Button type="button" variant="ghost" asChild>
                    <Link href={index()}>Kembali ke daftar</Link>
                </Button>
            </div>
        </form>
    );
}

// Daftar kegiatan sebagai pilihan radio; yang punya error tampil abu-abu beserta alasannya
function DaftarPilihan({
    judul,
    pilihan,
    dipilih,
    onPilih,
}: {
    judul: string;
    pilihan: Pilihan[];
    dipilih?: Pilihan;
    onPilih: (p: Pilihan) => void;
}) {
    if (pilihan.length === 0) {
        return null;
    }

    return (
        <div className="flex flex-col gap-2">
            <Label>{judul}</Label>
            <ul className="divide-y rounded-md border">
                {pilihan.map((p) => {
                    const aktif = dipilih === p;

                    return (
                        <li key={`${p.jenis}-${p.id}`}>
                            <label
                                className={cn(
                                    'flex items-start gap-3 p-3',
                                    p.error ? 'cursor-not-allowed opacity-60' : 'cursor-pointer hover:bg-muted/50',
                                    aktif && 'bg-muted/50',
                                )}
                            >
                                <input
                                    type="radio"
                                    name="kegiatan"
                                    className="mt-1 size-4 accent-primary"
                                    checked={aktif}
                                    disabled={p.error !== null}
                                    onChange={() => onPilih(p)}
                                />
                                <span className="flex flex-1 flex-col gap-0.5">
                                    <span className="font-medium">{p.nama}</span>
                                    <span className="text-xs text-muted-foreground">
                                        {[
                                            p.biaya > 0 ? `${rupiah(p.biaya)}/bulan` : 'Gratis',
                                            ...p.jadwal,
                                            p.punya_kelas_paralel ? 'kelas paralel di-assign TU setelah mendaftar' : null,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </span>
                                    {p.error && <span className="text-xs text-rose-600">{p.error}</span>}
                                    {!p.error && p.peringatan && <span className="text-xs text-amber-600">{p.peringatan}</span>}
                                </span>
                                {p.kuota !== null && (
                                    <span className="text-xs whitespace-nowrap text-muted-foreground">
                                        {p.terisi}/{p.kuota} slot
                                    </span>
                                )}
                            </label>
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}

TambahPendaftaran.layout = {
    breadcrumbs: [
        { title: 'Pendaftaran', href: index() },
        { title: 'Tambah Pendaftaran', href: create() },
    ],
};
