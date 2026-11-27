import { Form, Head, router } from '@inertiajs/react';
import { KeyRound, X } from 'lucide-react';
import { useState } from 'react';
import AkunOrangTuaController from '@/actions/App/Domains/LesEkskul/Http/Controllers/AkunOrangTuaController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
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
import { tanggal } from '@/lib/format';

type Anak = { id: number; nama: string; kelas: string | null; diklaim: string | null };
type OrangTua = { id: number; nama: string; no_hp: string | null; email: string | null; terdaftar: string | null; anak: Anak[] };
type Halaman<T> = { data: T[]; links: { url: string | null; label: string; active: boolean }[]; total: number };

type Props = {
    orangTua: Halaman<OrangTua>;
    cari: string;
    kelas: { id: number; nama: string }[];
    passwordBaru: { nama: string; no_hp: string | null; password: string } | null;
};

type Konfirmasi = { jenis: 'klaim'; ortu: OrangTua; anak: Anak } | { jenis: 'reset'; ortu: OrangTua } | null;

const kelasPilihan = 'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';

// Akun orang tua untuk TU: cari, hapus klaim salah, reset password, tambah siswa (FR-1b, Ringkasan v1.11 #36)
export default function AkunOrangTuaIndex({ orangTua, cari, kelas, passwordBaru }: Props) {
    const [konfirmasi, setKonfirmasi] = useState<Konfirmasi>(null);
    const [kata, setKata] = useState(cari);
    const [proses, setProses] = useState(false);

    function jalankan() {
        if (!konfirmasi) {
            return;
        }

        const opsi = { preserveScroll: true, onStart: () => setProses(true), onFinish: () => setProses(false), onSuccess: () => setKonfirmasi(null) };

        if (konfirmasi.jenis === 'klaim') {
            router.delete(AkunOrangTuaController.hapusKlaim({ user: konfirmasi.ortu.id, siswa: konfirmasi.anak.id }).url, opsi);
        } else {
            router.post(AkunOrangTuaController.resetPassword(konfirmasi.ortu.id).url, {}, opsi);
        }
    }

    return (
        <>
            <Head title="Akun Orang Tua" />

            <div className="flex flex-col gap-6 p-4">
                <Heading
                    title="Akun Orang Tua"
                    description="Orang tua mendaftar sendiri dengan no HP lalu mengklaim anaknya. Di sini TU menghapus klaim yang salah, membuat password sementara, dan menambah siswa yang belum ada."
                />

                {passwordBaru && (
                    <div className="rounded-md border border-sekolah-sukses/40 bg-sekolah-sukses/10 p-4 text-sm text-sekolah-sukses">
                        <p>
                            Password sementara untuk <b>{passwordBaru.nama}</b> ({passwordBaru.no_hp}):
                        </p>
                        <p className="my-2 font-mono text-2xl tracking-wider">{passwordBaru.password}</p>
                        <p>Sampaikan langsung ke orang tua dan minta segera menggantinya di menu Akun. Password ini hanya ditampilkan sekali.</p>
                    </div>
                )}

                <form
                    className="flex flex-wrap gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        router.get(AkunOrangTuaController.index.url(), kata ? { cari: kata } : {}, { preserveState: true });
                    }}
                >
                    <Input value={kata} onChange={(e) => setKata(e.target.value)} placeholder="Cari nama, no HP, atau nama anak" className="max-w-sm" />
                    <Button type="submit" variant="outline">
                        Cari
                    </Button>
                    <span className="self-center text-sm text-muted-foreground">{orangTua.total} akun</span>
                </form>

                <div className="overflow-x-auto rounded-md border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-2">Orang tua</th>
                                <th className="p-2">No HP / email</th>
                                <th className="p-2">Anak yang diklaim</th>
                                <th className="p-2" />
                            </tr>
                        </thead>
                        <tbody>
                            {orangTua.data.map((o) => (
                                <tr key={o.id} className="border-t align-top">
                                    <td className="p-2">
                                        <p className="font-medium">{o.nama}</p>
                                        {o.terdaftar && <p className="text-xs text-muted-foreground">Daftar {tanggal(o.terdaftar)}</p>}
                                    </td>
                                    <td className="p-2">
                                        <p className="tabular-nums">{o.no_hp ?? '-'}</p>
                                        <p className="text-xs text-muted-foreground">{o.email ?? 'tanpa email'}</p>
                                    </td>
                                    <td className="p-2">
                                        {o.anak.length === 0 && <span className="text-muted-foreground">Belum mengklaim anak</span>}
                                        <ul className="flex flex-col gap-1">
                                            {o.anak.map((a) => (
                                                <li key={a.id} className="flex items-center gap-2">
                                                    <span>
                                                        {a.nama}
                                                        <span className="text-muted-foreground"> · {a.kelas ?? 'tanpa kelas tahun ini'}</span>
                                                    </span>
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        className="size-6"
                                                        title="Hapus klaim"
                                                        onClick={() => setKonfirmasi({ jenis: 'klaim', ortu: o, anak: a })}
                                                    >
                                                        <X className="size-3.5" />
                                                    </Button>
                                                </li>
                                            ))}
                                        </ul>
                                    </td>
                                    <td className="p-2 text-right">
                                        <Button variant="outline" size="sm" onClick={() => setKonfirmasi({ jenis: 'reset', ortu: o })}>
                                            <KeyRound className="size-3.5" />
                                            Reset password
                                        </Button>
                                    </td>
                                </tr>
                            ))}
                            {orangTua.data.length === 0 && (
                                <tr>
                                    <td colSpan={4} className="p-4 text-center text-muted-foreground">
                                        {cari ? 'Tidak ada akun yang cocok.' : 'Belum ada orang tua yang mendaftar.'}
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                {orangTua.links.length > 3 && (
                    <div className="flex flex-wrap gap-1">
                        {orangTua.links.map((l, i) => (
                            <Button
                                key={i}
                                size="sm"
                                variant={l.active ? 'default' : 'outline'}
                                disabled={!l.url}
                                onClick={() => l.url && router.get(l.url, {}, { preserveScroll: true })}
                                dangerouslySetInnerHTML={{ __html: l.label }}
                            />
                        ))}
                    </div>
                )}

                <FormTambahSiswa kelas={kelas} />
            </div>

            <Dialog open={konfirmasi !== null} onOpenChange={(b) => !b && setKonfirmasi(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{konfirmasi?.jenis === 'klaim' ? 'Hapus klaim?' : 'Buat password sementara?'}</DialogTitle>
                        <DialogDescription>
                            {konfirmasi?.jenis === 'klaim'
                                ? `${konfirmasi.anak.nama} akan dihapus dari daftar anak ${konfirmasi.ortu.nama}. Data kegiatan dan pembayaran anak tidak berubah.`
                                : `Password ${konfirmasi?.ortu.nama} diganti dengan password sementara yang ditampilkan sekali. Pastikan yang meminta benar orang tua tersebut.`}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="ghost" onClick={() => setKonfirmasi(null)}>
                            Batal
                        </Button>
                        <Button variant={konfirmasi?.jenis === 'klaim' ? 'destructive' : 'default'} onClick={jalankan} disabled={proses}>
                            {konfirmasi?.jenis === 'klaim' ? 'Hapus klaim' : 'Buat password'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

// Siswa yang belum ada di data kelas (mis. murid pindahan) supaya bisa diklaim orang tuanya
function FormTambahSiswa({ kelas }: { kelas: { id: number; nama: string }[] }) {
    return (
        <Card className="gap-3 py-4">
            <CardHeader className="px-4">
                <CardTitle className="text-base">Tambah siswa ke kelas</CardTitle>
                <p className="text-sm text-muted-foreground">Untuk anak yang namanya belum ada saat orang tua mengklaim (mis. murid pindahan).</p>
            </CardHeader>
            <CardContent className="px-4">
                <Form
                    {...AkunOrangTuaController.tambahSiswa.form()}
                    options={{ preserveScroll: true }}
                    resetOnSuccess={['nama']}
                    className="grid gap-3 sm:grid-cols-[1fr_12rem_8rem_auto] sm:items-end"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-1 text-sm">
                                <Label htmlFor="nama">Nama siswa</Label>
                                <Input id="nama" name="nama" />
                                <InputError message={errors.nama} />
                            </div>
                            <div className="grid gap-1 text-sm">
                                <Label htmlFor="kelas_id">Kelas</Label>
                                <select id="kelas_id" name="kelas_id" defaultValue="" className={kelasPilihan}>
                                    <option value="">Pilih kelas</option>
                                    {kelas.map((k) => (
                                        <option key={k.id} value={k.id}>
                                            {k.nama}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.kelas_id} />
                            </div>
                            <div className="grid gap-1 text-sm">
                                <Label htmlFor="jenis_kelamin">L/P</Label>
                                <select id="jenis_kelamin" name="jenis_kelamin" defaultValue="" className={kelasPilihan}>
                                    <option value="">-</option>
                                    <option value="L">L</option>
                                    <option value="P">P</option>
                                </select>
                            </div>
                            <Button type="submit" disabled={processing}>
                                Tambah
                            </Button>
                        </>
                    )}
                </Form>
            </CardContent>
        </Card>
    );
}

AkunOrangTuaIndex.layout = {
    breadcrumbs: [{ title: 'Akun Orang Tua', href: AkunOrangTuaController.index.url() }],
};
