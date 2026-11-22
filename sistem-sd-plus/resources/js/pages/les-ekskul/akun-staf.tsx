import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Copy, Plus } from 'lucide-react';
import { useEffect, useState } from 'react';
import AkunStafController from '@/actions/App/Domains/LesEkskul/Http/Controllers/AkunStafController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { cn } from '@/lib/utils';
import { index } from '@/routes/les-ekskul/akun-staf';

type Peran = 'kepala_sekolah' | 'tu' | 'bendahara' | 'waka_kurikulum' | 'guru';
type Akun = {
    id: number;
    nama: string;
    email: string | null;
    peran: Peran[];
    kode_guru: string | null;
    nuptk: string | null;
    mapel_ids: number[];
    mapel: string[];
    wali_kelas: string | null;
    aktif: boolean;
};
type PasswordBaru = { nama: string; email: string; password: string };
type Mapel = { id: number; kode: string; nama: string; aktif: boolean };

const PERAN: Record<Peran, string> = {
    kepala_sekolah: 'Kepala Sekolah',
    tu: 'TU',
    bendahara: 'Bendahara',
    waka_kurikulum: 'Waka Kurikulum',
    guru: 'Guru',
};

// Akun Guru & Staf, khusus Kepala Sekolah (Ringkasan v1.11 #46): tambah/ubah, reset password, nonaktifkan
export default function AkunStaf({ akun, saya, mapel }: { akun: Akun[]; saya: number; mapel: Mapel[] }) {
    const { flash } = usePage();
    const [dibuka, setDibuka] = useState<Akun | 'baru' | null>(null);
    const [password, setPassword] = useState<PasswordBaru | null>(null);
    const [cari, setCari] = useState('');

    // Password awal/reset dikirim sekali lewat flash lalu ditampilkan di dialog
    useEffect(() => {
        const baru = (flash as { password?: PasswordBaru }).password;

        if (baru) {
            setPassword(baru);
        }
    }, [flash]);

    const terlihat = akun.filter((a) => `${a.nama} ${a.email} ${a.kode_guru ?? ''}`.toLowerCase().includes(cari.toLowerCase()));

    function aksi(url: string, pesan: string) {
        if (confirm(pesan)) {
            router.post(url, {}, { preserveScroll: true });
        }
    }

    return (
        <>
            <Head title="Akun Guru & Staf" />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Heading
                        title="Akun Guru & Staf"
                        description="Akun login guru dan staf beserta perannya. Akun tidak dihapus; nonaktifkan bila guru/staf sudah tidak bertugas."
                    />
                    <Button onClick={() => setDibuka('baru')}>
                        <Plus /> Tambah akun
                    </Button>
                </div>

                <Input placeholder="Cari nama, email, atau kode guru" value={cari} onChange={(e) => setCari(e.target.value)} className="max-w-sm" />

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="min-w-48 p-3">Nama</th>
                                <th className="p-3">Peran</th>
                                <th className="p-3">Guru</th>
                                <th className="p-3">Status</th>
                                <th className="p-3" />
                            </tr>
                        </thead>
                        <tbody>
                            {terlihat.map((a) => (
                                <tr key={a.id} className={cn('border-t align-top', !a.aktif && 'text-muted-foreground')}>
                                    <td className="p-3">
                                        <p className="font-medium">{a.nama}</p>
                                        <p className="text-xs text-muted-foreground">{a.email}</p>
                                    </td>
                                    <td className="p-3">
                                        <div className="flex flex-wrap gap-1">
                                            {a.peran.map((p) => (
                                                <span key={p} className="rounded-full bg-muted px-2 py-0.5 text-xs whitespace-nowrap">
                                                    {PERAN[p]}
                                                </span>
                                            ))}
                                        </div>
                                    </td>
                                    <td className="p-3 text-xs">
                                        {a.kode_guru ? (
                                            <>
                                                Kode {a.kode_guru}
                                                {a.nuptk && <span className="block text-muted-foreground">NUPTK {a.nuptk}</span>}
                                                {a.mapel.length > 0 && <span className="block text-muted-foreground">Mapel {a.mapel.join(', ')}</span>}
                                                {a.wali_kelas && <span className="block text-muted-foreground">Wali {a.wali_kelas}</span>}
                                            </>
                                        ) : (
                                            <span className="text-muted-foreground">–</span>
                                        )}
                                    </td>
                                    <td className="p-3">
                                        <span
                                            className={cn(
                                                'rounded-full px-2 py-0.5 text-xs whitespace-nowrap',
                                                a.aktif ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300' : 'bg-muted text-muted-foreground',
                                            )}
                                        >
                                            {a.aktif ? 'Aktif' : 'Nonaktif'}
                                        </span>
                                    </td>
                                    <td className="p-3">
                                        <div className="flex flex-wrap justify-end gap-1">
                                            <Button size="sm" variant="outline" onClick={() => setDibuka(a)}>
                                                Ubah
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() => aksi(AkunStafController.resetPassword.url(a.id), `Reset password ${a.nama}? Password lama tidak berlaku lagi.`)}
                                            >
                                                Reset password
                                            </Button>
                                            {a.id !== saya &&
                                                (a.aktif ? (
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        className="text-red-600"
                                                        onClick={() =>
                                                            aksi(AkunStafController.nonaktifkan.url(a.id), `Nonaktifkan ${a.nama}? Akun tidak bisa login lagi dan data gurunya ikut nonaktif.`)
                                                        }
                                                    >
                                                        Nonaktifkan
                                                    </Button>
                                                ) : (
                                                    <Button size="sm" variant="ghost" onClick={() => aksi(AkunStafController.aktifkan.url(a.id), `Aktifkan kembali ${a.nama}?`)}>
                                                        Aktifkan
                                                    </Button>
                                                ))}
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            <Sheet open={dibuka !== null} onOpenChange={(buka) => !buka && setDibuka(null)}>
                <SheetContent className="w-full overflow-y-auto sm:max-w-md">
                    {dibuka !== null && (
                        <FormAkun
                            key={dibuka === 'baru' ? 'baru' : dibuka.id}
                            akun={dibuka === 'baru' ? null : dibuka}
                            saya={saya}
                            mapel={mapel}
                            onSelesai={() => setDibuka(null)}
                        />
                    )}
                </SheetContent>
            </Sheet>

            <Dialog open={password !== null} onOpenChange={(buka) => !buka && setPassword(null)}>
                <DialogContent>
                    {password && (
                        <>
                            <DialogHeader>
                                <DialogTitle>Password untuk {password.nama}</DialogTitle>
                                <DialogDescription>
                                    Hanya ditampilkan sekali. Berikan kepada pemilik akun; ia dapat menggantinya di menu Pengaturan setelah login.
                                </DialogDescription>
                            </DialogHeader>
                            <div className="rounded-md bg-muted/60 p-3 text-sm">
                                <p className="text-xs text-muted-foreground">Email login</p>
                                <p className="mb-2 font-medium">{password.email}</p>
                                <p className="text-xs text-muted-foreground">Password</p>
                                <div className="flex items-center justify-between gap-2">
                                    <p className="font-mono text-lg tracking-wide">{password.password}</p>
                                    <Button variant="ghost" size="sm" onClick={() => void navigator.clipboard?.writeText(password.password)}>
                                        <Copy className="size-4" /> Salin
                                    </Button>
                                </div>
                            </div>
                            <DialogFooter>
                                <Button onClick={() => setPassword(null)}>Selesai</Button>
                            </DialogFooter>
                        </>
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}

// Form tambah/ubah akun; aturan email/kode guru unik & pengaman Kepala Sekolah dijaga AkunStafService
function FormAkun({ akun, saya, mapel, onSelesai }: { akun: Akun | null; saya: number; mapel: Mapel[]; onSelesai: () => void }) {
    const form = useForm({
        nama: akun?.nama ?? '',
        email: akun?.email ?? '',
        peran: akun?.peran ?? (['guru'] as Peran[]),
        kode_guru: akun?.kode_guru ?? '',
        nuptk: akun?.nuptk ?? '',
        mapel_ids: akun?.mapel_ids ?? ([] as number[]),
    });
    // Mapel nonaktif hanya ditampilkan bila sudah tercatat diampu guru ini (#47)
    const pilihanMapel = mapel.filter((m) => m.aktif || akun?.mapel_ids.includes(m.id));
    const d = form.data;
    const errors = form.errors as Record<string, string | undefined>;

    function centang(p: Peran, ya: boolean) {
        form.setData('peran', ya ? [...d.peran, p] : d.peran.filter((x) => x !== p));
    }

    function simpan(e: React.FormEvent) {
        e.preventDefault();
        const opsi = { preserveScroll: true, onSuccess: onSelesai };

        if (akun) {
            form.put(AkunStafController.update.url(akun.id), opsi);
        } else {
            form.post(AkunStafController.store.url(), opsi);
        }
    }

    return (
        <form onSubmit={simpan} className="flex flex-col gap-5 px-4 pb-6">
            <SheetHeader className="px-0">
                <SheetTitle>{akun ? `Ubah ${akun.nama}` : 'Tambah akun'}</SheetTitle>
                <SheetDescription>{akun ? 'Password diubah lewat tombol Reset password.' : 'Password awal dibuat sistem dan ditampilkan setelah disimpan.'}</SheetDescription>
            </SheetHeader>

            <div className="grid gap-2">
                <Label htmlFor="nama">Nama lengkap (dengan gelar)</Label>
                <Input id="nama" value={d.nama} onChange={(e) => form.setData('nama', e.target.value)} />
                <InputError message={errors.nama} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor="email">Email login</Label>
                <Input id="email" type="email" value={d.email} onChange={(e) => form.setData('email', e.target.value)} />
                <InputError message={errors.email} />
            </div>

            <fieldset className="flex flex-col gap-2">
                <legend className="mb-1 text-sm font-medium">Peran</legend>
                {(Object.keys(PERAN) as Peran[]).map((p) => (
                    <label key={p} className="flex items-center gap-2 text-sm">
                        <Checkbox
                            checked={d.peran.includes(p)}
                            disabled={p === 'kepala_sekolah' && akun?.id === saya}
                            onCheckedChange={(v) => centang(p, v === true)}
                        />
                        {PERAN[p]}
                    </label>
                ))}
                <InputError message={errors.peran} />
            </fieldset>

            {d.peran.includes('guru') && (
                <div className="grid grid-cols-2 gap-4 rounded-md border p-3">
                    <div className="grid gap-2">
                        <Label htmlFor="kode_guru">Kode guru</Label>
                        <Input id="kode_guru" value={d.kode_guru} onChange={(e) => form.setData('kode_guru', e.target.value)} />
                        <InputError message={errors.kode_guru} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="nuptk">NUPTK</Label>
                        <Input id="nuptk" value={d.nuptk} onChange={(e) => form.setData('nuptk', e.target.value)} />
                        <InputError message={errors.nuptk} />
                    </div>
                    <fieldset className="col-span-2 flex flex-col gap-1.5">
                        <legend className="mb-1 text-sm font-medium">Mapel yang diampu</legend>
                        <div className="grid grid-cols-2 gap-1.5">
                            {pilihanMapel.map((m) => (
                                <label key={m.id} className="flex items-center gap-2 text-sm">
                                    <Checkbox
                                        checked={d.mapel_ids.includes(m.id)}
                                        onCheckedChange={(v) =>
                                            form.setData('mapel_ids', v === true ? [...d.mapel_ids, m.id] : d.mapel_ids.filter((x) => x !== m.id))
                                        }
                                    />
                                    {m.nama}
                                    {!m.aktif && <span className="text-xs text-muted-foreground">(nonaktif)</span>}
                                </label>
                            ))}
                        </div>
                        <p className="text-xs text-muted-foreground">Dipakai menyaring pilihan mapel guru ini di kisi-kisi Jadwal Guru.</p>
                    </fieldset>
                </div>
            )}

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

AkunStaf.layout = {
    breadcrumbs: [{ title: 'Akun Guru & Staf', href: index() }],
};
