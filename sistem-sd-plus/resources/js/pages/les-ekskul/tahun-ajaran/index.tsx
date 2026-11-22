import { Head, Link, router, useForm } from '@inertiajs/react';
import { CheckCircle2, Circle, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import TahunAjaranController from '@/actions/App/Domains/LesEkskul/Http/Controllers/TahunAjaranController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { tanggal } from '@/lib/format';
import { index as halamanEkskul } from '@/routes/les-ekskul/ekstrakurikuler';
import { index as halamanKelas } from '@/routes/les-ekskul/kelas';
import { index, kenaikan } from '@/routes/les-ekskul/tahun-ajaran';

type Rentang = { tanggal_mulai: string; tanggal_selesai: string };
type TahunAjaran = Rentang & {
    id: number;
    nama: string;
    aktif: boolean;
    semester: (Rentang & { urutan: number })[];
    jumlah_kelas: number;
    jumlah_siswa: number;
    tanggal_terkunci: boolean;
    bisa_dihapus: boolean;
};
type Usulan = Rentang & { nama: string; semester: Rentang[] };
type Props = {
    tahunAjaran: TahunAjaran[];
    usulan: Usulan;
    berikutnya: { id: number; nama: string; hambatan: string[]; siswa_belum_berkelas: number } | null;
    bisaUbah: boolean;
    bisaNaikkan: boolean;
};

// Tahun Ajaran & Semester (Kepala Sekolah) dan persiapan tahun ajaran baru (Ringkasan v1.11 #45)
export default function HalamanTahunAjaran({ tahunAjaran, usulan, berikutnya, bisaUbah, bisaNaikkan }: Props) {
    const [dibuka, setDibuka] = useState<TahunAjaran | 'baru' | null>(null);
    const aktif = tahunAjaran.find((t) => t.aktif);
    const dataBerikutnya = berikutnya ? tahunAjaran.find((t) => t.id === berikutnya.id) : undefined;

    function hapus(t: TahunAjaran) {
        if (confirm(`Hapus tahun ajaran ${t.nama}?`)) {
            router.delete(TahunAjaranController.destroy.url(t.id), { preserveScroll: true });
        }
    }

    function aktifkan(t: { id: number; nama: string }) {
        if (confirm(`Aktifkan tahun ajaran ${t.nama}? Semua halaman (tagihan, pendaftaran, klaim orang tua) akan memakai tahun ajaran ini.`)) {
            router.post(TahunAjaranController.aktifkan.url(t.id), {}, { preserveScroll: true });
        }
    }

    return (
        <>
            <Head title="Tahun Ajaran" />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Heading
                        title="Tahun Ajaran & Semester"
                        description="Tahun ajaran aktif menentukan tagihan, pendaftaran, dan kelas yang dipakai di seluruh aplikasi."
                    />
                    {bisaUbah && !berikutnya && (
                        <Button onClick={() => setDibuka('baru')}>
                            <Plus /> Tahun ajaran baru
                        </Button>
                    )}
                </div>

                {berikutnya && (
                    <Card className="gap-3 border-sky-300 py-4 dark:border-sky-800">
                        <CardHeader className="px-4">
                            <CardTitle className="text-base">Persiapan tahun ajaran {berikutnya.nama}</CardTitle>
                            <p className="text-sm text-muted-foreground">
                                Ikuti urutan ini. Tahun ajaran {aktif?.nama ?? 'aktif'} tetap berjalan sampai {berikutnya.nama} diaktifkan.
                            </p>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-3 px-4 text-sm">
                            <Langkah selesai={(dataBerikutnya?.jumlah_kelas ?? 0) > 0} judul="1. Kelas, wali kelas, dan les tambahan (TU)">
                                <Link className="underline underline-offset-4" href={halamanKelas({ query: { tahun: berikutnya.id } })}>
                                    Buka Kelas & Les {berikutnya.nama}
                                </Link>{' '}
                                · {dataBerikutnya?.jumlah_kelas ?? 0} kelas
                            </Langkah>
                            <Langkah selesai={false} judul="2. Kegiatan ekstrakurikuler dan jadwal (TU)">
                                <Link className="underline underline-offset-4" href={halamanEkskul({ query: { tahun: berikutnya.id } })}>
                                    Buka Kegiatan Ekskul {berikutnya.nama}
                                </Link>{' '}
                                · bisa disalin dari tahun ajaran lalu
                            </Langkah>
                            <Langkah selesai={(dataBerikutnya?.jumlah_kelas ?? 0) > 0 && berikutnya.siswa_belum_berkelas === 0} judul="3. Kenaikan kelas (TU / Kepala Sekolah)">
                                {berikutnya.siswa_belum_berkelas > 0
                                    ? `${berikutnya.siswa_belum_berkelas} siswa aktif belum punya kelas di ${berikutnya.nama}. `
                                    : 'Semua siswa aktif sudah punya kelas. '}
                                {bisaNaikkan && (dataBerikutnya?.jumlah_kelas ?? 0) > 0 && (
                                    <Link className="underline underline-offset-4" href={kenaikan(berikutnya.id)}>
                                        Buka kenaikan kelas
                                    </Link>
                                )}
                            </Langkah>
                            <Langkah selesai={false} judul="4. Aktifkan tahun ajaran (Kepala Sekolah)">
                                {berikutnya.hambatan.length > 0 ? (
                                    <span className="text-amber-700 dark:text-amber-400">{berikutnya.hambatan.join(' ')}</span>
                                ) : (
                                    'Siap diaktifkan.'
                                )}
                                {bisaUbah && berikutnya.hambatan.length === 0 && (
                                    <Button size="sm" className="mt-2" onClick={() => aktifkan(berikutnya)}>
                                        Aktifkan {berikutnya.nama}
                                    </Button>
                                )}
                            </Langkah>
                        </CardContent>
                    </Card>
                )}

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Tahun ajaran</th>
                                <th className="p-3">Semester 1</th>
                                <th className="p-3">Semester 2</th>
                                <th className="p-3 text-right">Kelas</th>
                                <th className="p-3 text-right">Siswa</th>
                                {bisaUbah && <th className="p-3" />}
                            </tr>
                        </thead>
                        <tbody>
                            {tahunAjaran.map((t) => (
                                <tr key={t.id} className="border-t align-top">
                                    <td className="p-3">
                                        <p className="font-medium">
                                            {t.nama}{' '}
                                            {t.aktif && (
                                                <span className="ml-1 rounded-full bg-emerald-100 px-2 py-0.5 text-xs text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300">
                                                    Aktif
                                                </span>
                                            )}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {tanggal(t.tanggal_mulai)} – {tanggal(t.tanggal_selesai)}
                                        </p>
                                    </td>
                                    {[1, 2].map((u) => {
                                        const s = t.semester.find((x) => x.urutan === u);

                                        return (
                                            <td key={u} className="p-3 text-xs whitespace-nowrap">
                                                {s ? `${tanggal(s.tanggal_mulai)} – ${tanggal(s.tanggal_selesai)}` : '–'}
                                            </td>
                                        );
                                    })}
                                    <td className="p-3 text-right tabular-nums">{t.jumlah_kelas}</td>
                                    <td className="p-3 text-right tabular-nums">{t.jumlah_siswa}</td>
                                    {bisaUbah && (
                                        <td className="p-3">
                                            <div className="flex justify-end gap-1">
                                                <Button size="sm" variant="outline" onClick={() => setDibuka(t)}>
                                                    Ubah
                                                </Button>
                                                {t.bisa_dihapus && (
                                                    <Button size="icon" variant="ghost" className="size-8" title={`Hapus tahun ajaran ${t.nama}`} onClick={() => hapus(t)}>
                                                        <Trash2 className="size-4" />
                                                    </Button>
                                                )}
                                            </div>
                                        </td>
                                    )}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            <Sheet open={dibuka !== null} onOpenChange={(buka) => !buka && setDibuka(null)}>
                <SheetContent className="w-full overflow-y-auto sm:max-w-md">
                    {dibuka !== null && (
                        <FormTahunAjaran
                            key={dibuka === 'baru' ? 'baru' : dibuka.id}
                            tahunAjaran={dibuka === 'baru' ? null : dibuka}
                            usulan={usulan}
                            onSelesai={() => setDibuka(null)}
                        />
                    )}
                </SheetContent>
            </Sheet>
        </>
    );
}

function Langkah({ selesai, judul, children }: { selesai: boolean; judul: string; children: React.ReactNode }) {
    const Ikon = selesai ? CheckCircle2 : Circle;

    return (
        <div className="flex gap-2">
            <Ikon className={selesai ? 'mt-0.5 size-4 shrink-0 text-emerald-600' : 'mt-0.5 size-4 shrink-0 text-muted-foreground'} />
            <div className="flex flex-col">
                <p className="font-medium">{judul}</p>
                <div className="text-muted-foreground">{children}</div>
            </div>
        </div>
    );
}

// Form tahun ajaran beserta dua semester; aturan rentang & irisan dijaga TahunAjaranService
function FormTahunAjaran({ tahunAjaran, usulan, onSelesai }: { tahunAjaran: TahunAjaran | null; usulan: Usulan; onSelesai: () => void }) {
    const awal = tahunAjaran ?? usulan;
    const form = useForm({
        nama: awal.nama,
        tanggal_mulai: awal.tanggal_mulai,
        tanggal_selesai: awal.tanggal_selesai,
        semester: [0, 1].map((i) => ({
            tanggal_mulai: (tahunAjaran ? tahunAjaran.semester[i] : usulan.semester[i])?.tanggal_mulai ?? '',
            tanggal_selesai: (tahunAjaran ? tahunAjaran.semester[i] : usulan.semester[i])?.tanggal_selesai ?? '',
        })),
    });
    const d = form.data;
    const errors = form.errors as Record<string, string | undefined>;
    const terkunci = tahunAjaran?.tanggal_terkunci ?? false;

    function simpan(e: React.FormEvent) {
        e.preventDefault();
        const opsi = { preserveScroll: true, onSuccess: onSelesai };

        if (tahunAjaran) {
            form.put(TahunAjaranController.update.url(tahunAjaran.id), opsi);
        } else {
            form.post(TahunAjaranController.store.url(), opsi);
        }
    }

    const ubahSemester = (i: number, isi: Partial<Rentang>) => form.setData('semester', d.semester.map((s, x) => (x === i ? { ...s, ...isi } : s)));

    return (
        <form onSubmit={simpan} className="flex flex-col gap-5 px-4 pb-6">
            <SheetHeader className="px-0">
                <SheetTitle>{tahunAjaran ? `Ubah tahun ajaran ${tahunAjaran.nama}` : 'Tahun ajaran baru'}</SheetTitle>
                <SheetDescription>
                    {tahunAjaran ? 'Tanggal semester menentukan batas berhenti/pindah ekskul.' : 'Sudah diisi usulan; ubah bila tanggal sekolah berbeda.'}
                </SheetDescription>
            </SheetHeader>

            <div className="grid gap-2">
                <Label htmlFor="nama">Nama</Label>
                <Input id="nama" placeholder="2027/2028" value={d.nama} onChange={(e) => form.setData('nama', e.target.value)} />
                <InputError message={errors.nama} />
            </div>

            <div className="grid grid-cols-2 gap-4">
                <div className="grid gap-2">
                    <Label htmlFor="tanggal_mulai">Mulai</Label>
                    <Input id="tanggal_mulai" type="date" disabled={terkunci} value={d.tanggal_mulai} onChange={(e) => form.setData('tanggal_mulai', e.target.value)} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="tanggal_selesai">Selesai</Label>
                    <Input id="tanggal_selesai" type="date" disabled={terkunci} value={d.tanggal_selesai} onChange={(e) => form.setData('tanggal_selesai', e.target.value)} />
                </div>
                {terkunci && <p className="col-span-2 text-xs text-muted-foreground">Tanggal tahun ajaran terkunci karena sudah punya kelas.</p>}
                <InputError className="col-span-2" message={errors.tanggal_mulai ?? errors.tanggal_selesai} />
            </div>

            {[0, 1].map((i) => (
                <div key={i} className="grid grid-cols-2 gap-4 rounded-md border p-3">
                    <p className="col-span-2 text-sm font-medium">Semester {i + 1}</p>
                    <Input aria-label={`Semester ${i + 1} mulai`} type="date" value={d.semester[i].tanggal_mulai} onChange={(e) => ubahSemester(i, { tanggal_mulai: e.target.value })} />
                    <Input aria-label={`Semester ${i + 1} selesai`} type="date" value={d.semester[i].tanggal_selesai} onChange={(e) => ubahSemester(i, { tanggal_selesai: e.target.value })} />
                </div>
            ))}
            <InputError message={errors.semester} />

            <div className="flex gap-2">
                <Button type="submit" disabled={form.processing}>
                    Simpan
                </Button>
                <Button type="button" variant="ghost" onClick={onSelesai}>
                    Batal
                </Button>
            </div>
        </form>
    );
}

HalamanTahunAjaran.layout = {
    breadcrumbs: [{ title: 'Tahun Ajaran', href: index() }],
};
