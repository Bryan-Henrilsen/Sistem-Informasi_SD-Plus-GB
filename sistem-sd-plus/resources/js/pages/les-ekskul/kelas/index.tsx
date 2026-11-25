import { Form, Head, router, useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { Fragment, useState } from 'react';
import KelasLesController from '@/actions/App/Domains/LesEkskul/Http/Controllers/KelasLesController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { rupiah, tanggal } from '@/lib/format';
import { index } from '@/routes/les-ekskul/kelas';

type Kelas = {
    id: number;
    jenjang: number;
    nama_kelas: string;
    nama_pelindung: string | null;
    nama_lengkap: string;
    wali_kelas_id: number | null;
    wali_kelas: string | null;
    jumlah_siswa: number;
    les: { biaya: number; peserta: number; bisa_dihapus: boolean } | null;
    jenjang_terkunci: boolean;
    bisa_dihapus: boolean;
    riwayat_wali: Riwayat[]; // kosong bila wali belum pernah berganti di tengah tahun (#50)
};
type Riwayat = { id: number; nama: string; mulai: string | null; selesai: string | null };
type Guru = { id: number; nama: string; wali_di: string | null };
type Props = {
    tahunAjaran: { id: number; nama: string };
    daftarTahun: { id: number; nama: string }[];
    kelas: Kelas[];
    guru: Guru[];
    sumberSalinan: { id: number; nama: string } | null;
    bisaUbah: boolean;
};

const kelasPilihan = 'h-9 rounded-md border border-input bg-transparent px-2 text-sm shadow-xs';

// Data master Kelas & Les Tambahan per tahun ajaran (Ringkasan v1.11 #43). Les tambahan selalu satu per kelas
// dan pengajarnya wali kelas. TU mengubah; Kepala Sekolah & Bendahara melihat
export default function KelasLes({ tahunAjaran, daftarTahun, kelas, guru, sumberSalinan, bisaUbah }: Props) {
    const [dibuka, setDibuka] = useState<Kelas | 'baru' | null>(null);
    const [samakan, setSamakan] = useState<number | null>(null); // jenjang yang biaya lesnya akan disamakan
    const [ganti, setGanti] = useState<Kelas | null>(null); // kelas yang wali kelasnya diganti di tengah tahun
    const perJenjang = kelas.reduce<Record<number, Kelas[]>>((acc, k) => {
        (acc[k.jenjang] ??= []).push(k);

        return acc;
    }, {});
    const tanpaWali = kelas.filter((k) => !k.wali_kelas_id).length;

    function hapus(k: Kelas) {
        if (confirm(`Hapus kelas ${k.nama_lengkap}${k.les ? ' beserta les tambahannya' : ''}?`)) {
            router.delete(KelasLesController.destroy.url(k.id), { preserveScroll: true });
        }
    }

    return (
        <>
            <Head title="Kelas & Les" />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Heading
                        title="Kelas & Les Tambahan"
                        description="Kelas, wali kelas, dan biaya les tambahan per tahun ajaran. Wali kelas otomatis menjadi pengajar les kelasnya."
                    />
                    <div className="flex items-center gap-2">
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
                        {bisaUbah && (
                            <Button onClick={() => setDibuka('baru')}>
                                <Plus /> Tambah kelas
                            </Button>
                        )}
                    </div>
                </div>

                {sumberSalinan && (
                    <Card className="border-sky-300 py-4 dark:border-sky-800">
                        <CardContent className="flex flex-wrap items-center justify-between gap-3 px-4 text-sm">
                            <p>
                                Tahun ajaran {tahunAjaran.nama} belum punya kelas. Salin kelas dan biaya les dari {sumberSalinan.nama}; wali kelas perlu diisi lagi
                                dan siswa tidak ikut disalin.
                            </p>
                            <Button variant="outline" onClick={() => router.post(KelasLesController.salin.url(tahunAjaran.id), {}, { preserveScroll: true })}>
                                Salin dari {sumberSalinan.nama}
                            </Button>
                        </CardContent>
                    </Card>
                )}

                {tanpaWali > 0 && (
                    <p className="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200">
                        {tanpaWali} kelas belum punya wali kelas. Honor les kelas itu belum bisa dihitung sampai wali kelasnya diisi.
                    </p>
                )}

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Kelas</th>
                                <th className="p-3">Wali kelas</th>
                                <th className="p-3 text-right">Siswa</th>
                                <th className="p-3 text-right whitespace-nowrap">Les / bulan</th>
                                {bisaUbah && <th className="p-3" />}
                            </tr>
                        </thead>
                        <tbody>
                            {Object.entries(perJenjang).map(([jenjang, daftar]) => (
                                <Fragment key={jenjang}>
                                    <tr className="border-t bg-muted/30">
                                        <td colSpan={bisaUbah ? 5 : 4} className="px-3 py-1.5">
                                            <div className="flex items-center justify-between gap-2">
                                                <span className="font-medium">Jenjang {jenjang}</span>
                                                {bisaUbah && daftar.some((k) => k.les) && (
                                                    <Button size="sm" variant="ghost" className="h-7" onClick={() => setSamakan(Number(jenjang))}>
                                                        Samakan biaya les
                                                    </Button>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                    {daftar.map((k) => (
                                        <tr key={k.id} className="border-t">
                                            <td className="p-3 font-medium whitespace-nowrap">{k.nama_lengkap}</td>
                                            <td className="p-3">
                                                {k.wali_kelas ?? <span className="text-amber-700 dark:text-amber-400">Belum diisi</span>}
                                                <DaftarRiwayat riwayat={k.riwayat_wali} />
                                            </td>
                                            <td className="p-3 text-right tabular-nums">{k.jumlah_siswa}</td>
                                            <td className="p-3 text-right tabular-nums">
                                                {k.les ? (
                                                    <>
                                                        {rupiah(k.les.biaya)}
                                                        <p className="text-xs text-muted-foreground">{k.les.peserta} peserta</p>
                                                    </>
                                                ) : (
                                                    <span className="text-muted-foreground">Tanpa les</span>
                                                )}
                                            </td>
                                            {bisaUbah && (
                                                <td className="p-3">
                                                    <div className="flex justify-end gap-1">
                                                        <Button size="sm" variant="outline" onClick={() => setDibuka(k)}>
                                                            Ubah
                                                        </Button>
                                                        {k.wali_kelas_id && (
                                                            <Button size="sm" variant="ghost" onClick={() => setGanti(k)}>
                                                                Ganti wali
                                                            </Button>
                                                        )}
                                                        {k.bisa_dihapus && (
                                                            <Button size="icon" variant="ghost" className="size-8" title={`Hapus kelas ${k.nama_lengkap}`} onClick={() => hapus(k)}>
                                                                <Trash2 className="size-4" />
                                                            </Button>
                                                        )}
                                                    </div>
                                                </td>
                                            )}
                                        </tr>
                                    ))}
                                </Fragment>
                            ))}
                            {kelas.length === 0 && (
                                <tr>
                                    <td colSpan={5} className="p-6 text-center text-muted-foreground">
                                        Belum ada kelas di tahun ajaran {tahunAjaran.nama}.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>

            <Sheet open={dibuka !== null} onOpenChange={(buka) => !buka && setDibuka(null)}>
                <SheetContent className="w-full overflow-y-auto sm:max-w-md">
                    {dibuka !== null && (
                        <FormKelas
                            key={dibuka === 'baru' ? 'baru' : dibuka.id}
                            kelas={dibuka === 'baru' ? null : dibuka}
                            guru={guru}
                            tahunAjaranId={tahunAjaran.id}
                            onSelesai={() => setDibuka(null)}
                        />
                    )}
                </SheetContent>
            </Sheet>

            <Dialog open={ganti !== null} onOpenChange={(buka) => !buka && setGanti(null)}>
                <DialogContent>{ganti && <FormGantiWali kelas={ganti} guru={guru} onSelesai={() => setGanti(null)} />}</DialogContent>
            </Dialog>

            <Dialog open={samakan !== null} onOpenChange={(buka) => !buka && setSamakan(null)}>
                <DialogContent>
                    {samakan !== null && (
                        <Form
                            {...KelasLesController.samakanBiaya.form(tahunAjaran.id)}
                            options={{ preserveScroll: true }}
                            onSuccess={() => setSamakan(null)}
                            className="flex flex-col gap-4"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <DialogHeader>
                                        <DialogTitle>Samakan biaya les jenjang {samakan}</DialogTitle>
                                        <DialogDescription>
                                            Semua kelas jenjang {samakan} yang punya les tambahan memakai biaya ini. Berlaku untuk tagihan yang dibuat sesudahnya.
                                        </DialogDescription>
                                    </DialogHeader>
                                    <input type="hidden" name="jenjang" value={samakan} />
                                    <Input
                                        name="biaya"
                                        type="number"
                                        min={1}
                                        placeholder="Biaya per bulan (Rp)"
                                        defaultValue={perJenjang[samakan]?.find((k) => k.les)?.les?.biaya}
                                        autoFocus
                                    />
                                    <InputError message={errors.biaya} />
                                    <DialogFooter>
                                        <Button type="button" variant="ghost" onClick={() => setSamakan(null)}>
                                            Batal
                                        </Button>
                                        <Button type="submit" disabled={processing}>
                                            Simpan
                                        </Button>
                                    </DialogFooter>
                                </>
                            )}
                        </Form>
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}

// Riwayat wali kelas di bawah nama wali sekarang: "Bu A s.d. 12 Sep 2026 → Pak B mulai 15 Sep 2026"
function DaftarRiwayat({ riwayat }: { riwayat: Riwayat[] }) {
    if (riwayat.length === 0) {
        return null;
    }

    return (
        <ul className="mt-1 text-xs text-muted-foreground">
            {riwayat.map((r) => (
                <li key={r.id}>
                    {r.nama}
                    {r.mulai && ` mulai ${tanggal(r.mulai)}`}
                    {r.selesai ? ` s.d. ${tanggal(r.selesai)}` : ' (sekarang)'}
                </li>
            ))}
        </ul>
    );
}

// Pergantian wali kelas di tengah tahun (#50): honor les bulan pergantian dibagi per hari sekolah Senin–Jumat.
// Berbeda dengan Ubah kelas, yang dipakai untuk membetulkan salah input
function FormGantiWali({ kelas, guru, onSelesai }: { kelas: Kelas; guru: Guru[]; onSelesai: () => void }) {
    return (
        <Form {...KelasLesController.gantiWali.form(kelas.id)} options={{ preserveScroll: true }} onSuccess={onSelesai} className="flex flex-col gap-4">
            {({ processing, errors }) => (
                <>
                    <DialogHeader>
                        <DialogTitle>Ganti wali kelas {kelas.nama_lengkap}</DialogTitle>
                        <DialogDescription>
                            Untuk pergantian di tengah tahun ajaran. Honor les bulan pergantian dibagi menurut hari sekolah (Senin–Jumat) tiap wali kelas.
                            Untuk membetulkan salah input, pakai tombol Ubah.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2">
                        <Label htmlFor="tanggal_terakhir">Tanggal terakhir {kelas.wali_kelas} bertugas</Label>
                        <Input id="tanggal_terakhir" name="tanggal_terakhir" type="date" />
                        <InputError message={errors.tanggal_terakhir} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="guru_id">Wali kelas baru</Label>
                        <select id="guru_id" name="guru_id" defaultValue="" className={kelasPilihan}>
                            <option value="">Pilih guru</option>
                            {guru
                                .filter((g) => g.id !== kelas.wali_kelas_id)
                                .map((g) => (
                                    <option key={g.id} value={g.id} disabled={g.wali_di !== null}>
                                        {g.nama}
                                        {g.wali_di ? ` (wali ${g.wali_di})` : ''}
                                    </option>
                                ))}
                        </select>
                        <InputError message={errors.guru_id} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="tanggal_mulai">Tanggal mulai wali kelas baru</Label>
                        <Input id="tanggal_mulai" name="tanggal_mulai" type="date" />
                        <InputError message={errors.tanggal_mulai} />
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={onSelesai}>
                            Batal
                        </Button>
                        <Button type="submit" disabled={processing}>
                            Simpan pergantian
                        </Button>
                    </DialogFooter>
                </>
            )}
        </Form>
    );
}

// Form tambah/ubah satu kelas beserta les tambahannya; aturan lengkap dijaga KelasLesService
function FormKelas({ kelas, guru, tahunAjaranId, onSelesai }: { kelas: Kelas | null; guru: Guru[]; tahunAjaranId: number; onSelesai: () => void }) {
    const form = useForm({
        jenjang: kelas?.jenjang ?? 1,
        nama_kelas: kelas?.nama_kelas ?? '',
        nama_pelindung: kelas?.nama_pelindung ?? '',
        wali_kelas_id: kelas?.wali_kelas_id ?? (null as number | null),
        ada_les: kelas ? kelas.les !== null : true,
        biaya_les: kelas?.les?.biaya ?? (null as number | null),
    });
    const d = form.data;
    const errors = form.errors as Record<string, string | undefined>;
    const lesTerkunci = kelas?.les !== null && kelas?.les?.bisa_dihapus === false;
    const biayaBerubah = kelas?.les && d.ada_les && Number(d.biaya_les) !== kelas.les.biaya;

    function simpan(e: React.FormEvent) {
        e.preventDefault();
        const opsi = { preserveScroll: true, onSuccess: onSelesai };

        if (kelas) {
            form.put(KelasLesController.update.url(kelas.id), opsi);
        } else {
            form.post(KelasLesController.store.url(tahunAjaranId), opsi);
        }
    }

    return (
        <form onSubmit={simpan} className="flex flex-col gap-5 px-4 pb-6">
            <SheetHeader className="px-0">
                <SheetTitle>{kelas ? `Ubah kelas ${kelas.nama_lengkap}` : 'Tambah kelas'}</SheetTitle>
                <SheetDescription>Siswa dimasukkan ke kelas lewat data master Siswa.</SheetDescription>
            </SheetHeader>

            <div className="grid grid-cols-2 gap-4">
                <div className="grid gap-2">
                    <Label htmlFor="jenjang">Jenjang</Label>
                    <select
                        id="jenjang"
                        value={d.jenjang}
                        disabled={kelas?.jenjang_terkunci}
                        onChange={(e) => form.setData('jenjang', Number(e.target.value))}
                        className={kelasPilihan}
                    >
                        {[1, 2, 3, 4, 5, 6].map((n) => (
                            <option key={n} value={n}>
                                Kelas {n}
                            </option>
                        ))}
                    </select>
                    {kelas?.jenjang_terkunci && <p className="text-xs text-muted-foreground">Terkunci karena kelas sudah punya siswa.</p>}
                    <InputError message={errors.jenjang} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="nama_kelas">Nama kelas</Label>
                    <Input id="nama_kelas" placeholder="mis. 2A" value={d.nama_kelas} onChange={(e) => form.setData('nama_kelas', e.target.value)} />
                    <InputError message={errors.nama_kelas} />
                </div>
            </div>

            <div className="grid gap-2">
                <Label htmlFor="nama_pelindung">Nama pelindung</Label>
                <Input id="nama_pelindung" placeholder="mis. Gregory" value={d.nama_pelindung} onChange={(e) => form.setData('nama_pelindung', e.target.value)} />
                <InputError message={errors.nama_pelindung} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor="wali_kelas_id">Wali kelas</Label>
                <select
                    id="wali_kelas_id"
                    value={d.wali_kelas_id ?? ''}
                    onChange={(e) => form.setData('wali_kelas_id', e.target.value === '' ? null : Number(e.target.value))}
                    className={kelasPilihan}
                >
                    <option value="">Belum diisi</option>
                    {guru.map((g) => (
                        <option key={g.id} value={g.id} disabled={g.wali_di !== null && g.id !== kelas?.wali_kelas_id}>
                            {g.nama}
                            {g.wali_di && g.id !== kelas?.wali_kelas_id ? ` (wali ${g.wali_di})` : ''}
                        </option>
                    ))}
                </select>
                <p className="text-xs text-muted-foreground">Satu guru hanya menjadi wali satu kelas. Wali kelas juga pengajar les kelas ini. Untuk pergantian di tengah tahun, pakai tombol Ganti wali.</p>
                <InputError message={errors.wali_kelas_id} />
            </div>

            <div className="flex flex-col gap-2 rounded-md border p-3">
                <label className="flex items-center gap-2 text-sm font-medium">
                    <Checkbox checked={d.ada_les} disabled={lesTerkunci} onCheckedChange={(v) => form.setData('ada_les', v === true)} />
                    Ada les tambahan
                </label>
                {lesTerkunci && <p className="text-xs text-muted-foreground">Les sudah punya pendaftar, jadi tidak bisa dihilangkan.</p>}
                {d.ada_les && (
                    <div className="grid gap-2">
                        <Label htmlFor="biaya_les">Biaya les per bulan (Rp)</Label>
                        <Input
                            id="biaya_les"
                            type="number"
                            min={1}
                            value={d.biaya_les ?? ''}
                            onChange={(e) => form.setData('biaya_les', e.target.value === '' ? null : Number(e.target.value))}
                        />
                        {biayaBerubah && (
                            <p className="text-xs text-amber-700 dark:text-amber-400">
                                Biaya baru hanya berlaku untuk tagihan yang dibuat sesudah disimpan. Tagihan yang sudah ada tetap memakai biaya lama.
                            </p>
                        )}
                    </div>
                )}
                <InputError message={errors.biaya_les ?? errors.ada_les} />
            </div>

            <InputError message={errors.kelas} />
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

KelasLes.layout = {
    breadcrumbs: [{ title: 'Kelas & Les', href: index() }],
};
