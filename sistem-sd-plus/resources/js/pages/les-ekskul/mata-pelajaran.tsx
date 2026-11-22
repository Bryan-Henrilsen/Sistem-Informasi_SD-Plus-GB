import { Form, Head, router } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import MataPelajaranController from '@/actions/App/Domains/LesEkskul/Http/Controllers/MataPelajaranController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { index } from '@/routes/les-ekskul/mata-pelajaran';

type Mapel = { id: number; kode: string; nama: string; aktif: boolean; jumlah_guru: number; dipakai_kisi_kisi: boolean; bisa_dihapus: boolean };
type JenisTugas = { id: number; kode: string; nama: string };

// Mata Pelajaran & Jenis Tugas — Waka Kurikulum & Kepala Sekolah (Ringkasan v1.11 #47)
export default function MataPelajaran({ mapel, jenisTugas }: { mapel: Mapel[]; jenisTugas: JenisTugas[] }) {
    const [dibuka, setDibuka] = useState<Mapel | 'baru' | null>(null);
    const [jenisDibuka, setJenisDibuka] = useState<JenisTugas | null>(null);

    function aturAktif(m: Mapel, aktif: boolean) {
        router.post(MataPelajaranController.aktif.url(m.id), { aktif }, { preserveScroll: true });
    }

    function hapus(m: Mapel) {
        if (confirm(`Hapus ${m.nama}?`)) {
            router.delete(MataPelajaranController.destroy.url(m.id), { preserveScroll: true });
        }
    }

    return (
        <>
            <Head title="Mata Pelajaran" />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Heading
                        title="Mata Pelajaran & Jenis Tugas"
                        description="Dipakai modul Jadwal Guru. Mapel yang diampu tiap guru diatur di Akun Guru & Staf."
                    />
                    <Button onClick={() => setDibuka('baru')}>
                        <Plus /> Tambah mapel
                    </Button>
                </div>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Kode</th>
                                <th className="p-3">Nama</th>
                                <th className="p-3 text-right">Guru</th>
                                <th className="p-3">Status</th>
                                <th className="p-3" />
                            </tr>
                        </thead>
                        <tbody>
                            {mapel.map((m) => (
                                <tr key={m.id} className={cn('border-t', !m.aktif && 'text-muted-foreground')}>
                                    <td className="p-3 font-mono">{m.kode}</td>
                                    <td className="p-3">
                                        {m.nama}
                                        {m.dipakai_kisi_kisi && <span className="block text-xs text-muted-foreground">dipakai di kisi-kisi</span>}
                                    </td>
                                    <td className="p-3 text-right tabular-nums">{m.jumlah_guru}</td>
                                    <td className="p-3">
                                        <span
                                            className={cn(
                                                'rounded-full px-2 py-0.5 text-xs',
                                                m.aktif ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300' : 'bg-muted text-muted-foreground',
                                            )}
                                        >
                                            {m.aktif ? 'Aktif' : 'Nonaktif'}
                                        </span>
                                    </td>
                                    <td className="p-3">
                                        <div className="flex justify-end gap-1">
                                            <Button size="sm" variant="outline" onClick={() => setDibuka(m)}>
                                                Ubah
                                            </Button>
                                            <Button size="sm" variant="ghost" onClick={() => aturAktif(m, !m.aktif)}>
                                                {m.aktif ? 'Nonaktifkan' : 'Aktifkan'}
                                            </Button>
                                            {m.bisa_dihapus && (
                                                <Button size="icon" variant="ghost" className="size-8" title={`Hapus ${m.nama}`} onClick={() => hapus(m)}>
                                                    <Trash2 className="size-4" />
                                                </Button>
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <Card className="gap-3 py-4">
                    <CardHeader className="px-4">
                        <CardTitle className="text-base">Jenis tugas guru</CardTitle>
                        <p className="text-sm text-muted-foreground">Tetap tiga karena dipakai aturan penjadwalan; hanya nama tampilannya yang bisa diubah.</p>
                    </CardHeader>
                    <CardContent className="px-4">
                        <ul className="divide-y rounded-md border text-sm">
                            {jenisTugas.map((j) => (
                                <li key={j.id} className="flex items-center justify-between gap-3 p-3">
                                    <span>
                                        {j.nama} <span className="font-mono text-xs text-muted-foreground">({j.kode})</span>
                                    </span>
                                    <Button size="sm" variant="ghost" onClick={() => setJenisDibuka(j)}>
                                        Ubah nama
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    </CardContent>
                </Card>
            </div>

            <Dialog open={dibuka !== null} onOpenChange={(buka) => !buka && setDibuka(null)}>
                <DialogContent>
                    {dibuka !== null && (
                        <Form
                            {...(dibuka === 'baru' ? MataPelajaranController.store.form() : MataPelajaranController.update.form(dibuka.id))}
                            options={{ preserveScroll: true }}
                            onSuccess={() => setDibuka(null)}
                            className="flex flex-col gap-4"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <DialogHeader>
                                        <DialogTitle>{dibuka === 'baru' ? 'Tambah mata pelajaran' : `Ubah ${dibuka.nama}`}</DialogTitle>
                                        <DialogDescription>Kode dipakai di tampilan jadwal, mis. MTK atau BI.</DialogDescription>
                                    </DialogHeader>
                                    <div className="grid grid-cols-3 gap-3">
                                        <div className="grid gap-2">
                                            <Label htmlFor="kode">Kode</Label>
                                            <Input id="kode" name="kode" defaultValue={dibuka === 'baru' ? '' : dibuka.kode} autoFocus />
                                        </div>
                                        <div className="col-span-2 grid gap-2">
                                            <Label htmlFor="nama">Nama</Label>
                                            <Input id="nama" name="nama" defaultValue={dibuka === 'baru' ? '' : dibuka.nama} />
                                        </div>
                                    </div>
                                    <InputError message={errors.kode ?? errors.nama} />
                                    <DialogFooter>
                                        <Button type="button" variant="ghost" onClick={() => setDibuka(null)}>
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

            <Dialog open={jenisDibuka !== null} onOpenChange={(buka) => !buka && setJenisDibuka(null)}>
                <DialogContent>
                    {jenisDibuka && (
                        <Form
                            {...MataPelajaranController.ubahJenisTugas.form(jenisDibuka.id)}
                            options={{ preserveScroll: true }}
                            onSuccess={() => setJenisDibuka(null)}
                            className="flex flex-col gap-4"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <DialogHeader>
                                        <DialogTitle>Ubah nama jenis tugas</DialogTitle>
                                        <DialogDescription>Kode {jenisDibuka.kode} tidak berubah.</DialogDescription>
                                    </DialogHeader>
                                    <Input name="nama" defaultValue={jenisDibuka.nama} autoFocus />
                                    <InputError message={errors.nama} />
                                    <DialogFooter>
                                        <Button type="button" variant="ghost" onClick={() => setJenisDibuka(null)}>
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

MataPelajaran.layout = {
    breadcrumbs: [{ title: 'Mata Pelajaran', href: index() }],
};
