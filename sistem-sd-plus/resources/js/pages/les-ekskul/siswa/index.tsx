import { Form, Head, Link, router, useForm } from '@inertiajs/react';
import { FileSpreadsheet, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import DataSiswaController from '@/actions/App/Domains/LesEkskul/Http/Controllers/DataSiswaController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Paginasi } from '@/components/les-ekskul/paginasi';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { tanggal } from '@/lib/format';
import { cn } from '@/lib/utils';
import { index } from '@/routes/les-ekskul/data-siswa';
import imporSiswa from '@/routes/les-ekskul/impor-siswa';
import type { Paginasi as PaginasiData } from '@/types/les-ekskul';

type Status = 'aktif' | 'pindah_sekolah' | 'lulus';
type Siswa = {
    id: number;
    nama: string;
    jenis_kelamin: 'L' | 'P' | null;
    nis: string | null;
    nisn: string | null;
    tanggal_lahir: string | null;
    kelas_id: number;
    kelas: string;
    jenjang: number;
    anak_guru_id: number | null;
    anak_guru: string | null;
    orang_tua: number;
    kegiatan_aktif: number;
    status: Status;
    bisa_dihapus: boolean;
};
type Props = {
    tahunAjaran: { id: number; nama: string };
    daftarTahun: { id: number; nama: string }[];
    siswa: PaginasiData<Siswa>;
    kelas: { id: number; nama: string; jenjang: number }[];
    guru: { id: number; nama: string }[];
    filter: { kelas?: string; nama?: string; status?: string };
    hariIni: string;
    bisaUbah: boolean;
};

const STATUS: Record<Status, { teks: string; kelas: string }> = {
    aktif: { teks: 'Aktif', kelas: 'bg-sekolah-sukses/15 text-sekolah-sukses' },
    pindah_sekolah: { teks: 'Keluar sekolah', kelas: 'bg-muted text-muted-foreground' },
    lulus: { teks: 'Lulus', kelas: 'bg-sekolah-info/15 text-sekolah-info' },
};
const kelasPilihan = 'h-9 rounded-md border border-input bg-transparent px-2 text-sm shadow-xs';

// Data master Siswa per tahun ajaran (Ringkasan v1.11 #44): TU tambah/ubah/pindah kelas/keluar/hapus; Kepala Sekolah & Bendahara melihat
export default function DataSiswa({ tahunAjaran, daftarTahun, siswa, kelas, guru, filter, hariIni, bisaUbah }: Props) {
    const [dibuka, setDibuka] = useState<Siswa | 'baru' | null>(null);
    const [keluar, setKeluar] = useState<Siswa | null>(null);

    function hapus(s: Siswa) {
        if (confirm(`Hapus ${s.nama}? Gunakan hanya untuk data yang salah input.`)) {
            router.delete(DataSiswaController.destroy.url(s.id), { preserveScroll: true });
        }
    }

    return (
        <>
            <Head title="Data Siswa" />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Heading title="Data Siswa" description="Data siswa per tahun ajaran: kelas, NIS/NISN, anak guru (diskon 50%), dan status sekolah." />
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
                            // Impor dari Excel: template Juli atau ekspor Dapodik (FR-28, #53)
                            <Button variant="outline" asChild>
                                <Link href={imporSiswa.index()}>
                                    <FileSpreadsheet /> Impor dari Excel
                                </Link>
                            </Button>
                        )}
                        {bisaUbah && kelas.length > 0 && (
                            <Button onClick={() => setDibuka('baru')}>
                                <Plus /> Tambah siswa
                            </Button>
                        )}
                    </div>
                </div>

                <Form {...DataSiswaController.index.form()} options={{ preserveScroll: true }} className="flex flex-wrap items-center gap-2">
                    <input type="hidden" name="tahun" value={tahunAjaran.id} />
                    <Input name="nama" placeholder="Cari nama siswa" defaultValue={filter.nama} className="w-52" />
                    <select name="kelas" defaultValue={filter.kelas ?? ''} className={kelasPilihan}>
                        <option value="">Semua kelas</option>
                        {kelas.map((k) => (
                            <option key={k.id} value={k.id}>
                                {k.nama}
                            </option>
                        ))}
                    </select>
                    <select name="status" defaultValue={filter.status ?? ''} className={kelasPilihan}>
                        <option value="">Semua status</option>
                        <option value="aktif">Aktif</option>
                        <option value="pindah_sekolah">Keluar sekolah</option>
                        <option value="lulus">Lulus</option>
                    </select>
                    <Button type="submit" variant="secondary">
                        Terapkan
                    </Button>
                </Form>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="min-w-44 p-3">Siswa</th>
                                <th className="p-3">Kelas</th>
                                <th className="p-3">Anak guru</th>
                                <th className="p-3 text-right">Orang tua</th>
                                <th className="p-3 text-right">Kegiatan</th>
                                <th className="p-3">Status</th>
                                {bisaUbah && <th className="p-3" />}
                            </tr>
                        </thead>
                        <tbody>
                            {siswa.data.map((s) => (
                                <tr key={s.id} className="border-t align-top">
                                    <td className="p-3">
                                        <p className="font-medium">
                                            {s.nama} {s.jenis_kelamin && <span className="font-normal text-muted-foreground">({s.jenis_kelamin})</span>}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {[s.nis && `NIS ${s.nis}`, s.nisn && `NISN ${s.nisn}`, s.tanggal_lahir && `lahir ${tanggal(s.tanggal_lahir)}`]
                                                .filter(Boolean)
                                                .join(' · ') || 'NIS/NISN belum diisi'}
                                        </p>
                                    </td>
                                    <td className="p-3 whitespace-nowrap">{s.kelas}</td>
                                    <td className="p-3">{s.anak_guru ?? <span className="text-muted-foreground">–</span>}</td>
                                    <td className="p-3 text-right tabular-nums">{s.orang_tua}</td>
                                    <td className="p-3 text-right tabular-nums">{s.kegiatan_aktif}</td>
                                    <td className="p-3">
                                        <span className={cn('inline-block rounded-full px-2 py-0.5 text-xs whitespace-nowrap', STATUS[s.status].kelas)}>{STATUS[s.status].teks}</span>
                                    </td>
                                    {bisaUbah && (
                                        <td className="p-3">
                                            <div className="flex justify-end gap-1">
                                                <Button size="sm" variant="outline" onClick={() => setDibuka(s)}>
                                                    Ubah
                                                </Button>
                                                {s.status === 'aktif' && (
                                                    <Button size="sm" variant="ghost" onClick={() => setKeluar(s)}>
                                                        Keluar
                                                    </Button>
                                                )}
                                                {s.status === 'pindah_sekolah' && (
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        onClick={() => router.post(DataSiswaController.aktifkan.url(s.id), {}, { preserveScroll: true })}
                                                    >
                                                        Aktifkan
                                                    </Button>
                                                )}
                                                {s.bisa_dihapus && (
                                                    <Button size="icon" variant="ghost" className="size-8" title={`Hapus ${s.nama}`} onClick={() => hapus(s)}>
                                                        <Trash2 className="size-4" />
                                                    </Button>
                                                )}
                                            </div>
                                        </td>
                                    )}
                                </tr>
                            ))}
                            {siswa.data.length === 0 && (
                                <tr>
                                    <td colSpan={7} className="p-6 text-center text-muted-foreground">
                                        Tidak ada siswa yang cocok dengan filter.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
                <Paginasi data={siswa} />
            </div>

            <Sheet open={dibuka !== null} onOpenChange={(buka) => !buka && setDibuka(null)}>
                <SheetContent className="w-full overflow-y-auto sm:max-w-md">
                    {dibuka !== null && (
                        <FormSiswa
                            key={dibuka === 'baru' ? 'baru' : dibuka.id}
                            siswa={dibuka === 'baru' ? null : dibuka}
                            kelas={kelas}
                            guru={guru}
                            tahunAjaranId={tahunAjaran.id}
                            kelasAwal={filter.kelas ? Number(filter.kelas) : kelas[0]?.id}
                            onSelesai={() => setDibuka(null)}
                        />
                    )}
                </SheetContent>
            </Sheet>

            <Dialog open={keluar !== null} onOpenChange={(buka) => !buka && setKeluar(null)}>
                <DialogContent>
                    {keluar && (
                        <Form
                            {...DataSiswaController.keluar.form(keluar.id)}
                            options={{ preserveScroll: true }}
                            onSuccess={() => setKeluar(null)}
                            className="flex flex-col gap-4"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <DialogHeader>
                                        <DialogTitle>{keluar.nama} keluar sekolah</DialogTitle>
                                        <DialogDescription>
                                            {keluar.kegiatan_aktif > 0
                                                ? `${keluar.kegiatan_aktif} kegiatan aktif akan diberhentikan per tanggal ini. Bulan berjalan yang sudah dibayar hangus; bayar di muka sesudahnya dicatat sebagai refund.`
                                                : 'Siswa tidak punya kegiatan aktif.'}{' '}
                                            Siswa tidak lagi muncul di klaim orang tua dan pendaftaran.
                                        </DialogDescription>
                                    </DialogHeader>
                                    <div className="grid gap-2">
                                        <Label htmlFor="tanggal">Tanggal efektif (hari pertama tidak bersekolah)</Label>
                                        <Input id="tanggal" name="tanggal" type="date" defaultValue={hariIni} />
                                        <InputError message={errors.tanggal} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="alasan">Alasan</Label>
                                        <Input id="alasan" name="alasan" placeholder="mis. pindah ke sekolah lain" />
                                        <InputError message={errors.alasan ?? (errors as Record<string, string>).siswa} />
                                    </div>
                                    <DialogFooter>
                                        <Button type="button" variant="ghost" onClick={() => setKeluar(null)}>
                                            Batal
                                        </Button>
                                        <Button type="submit" variant="destructive" disabled={processing}>
                                            Simpan keluar sekolah
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

// Form tambah/ubah siswa; aturan pindah kelas, anak guru, dan nama ganda dijaga DataSiswaService
function FormSiswa({
    siswa,
    kelas,
    guru,
    tahunAjaranId,
    kelasAwal,
    onSelesai,
}: {
    siswa: Siswa | null;
    kelas: Props['kelas'];
    guru: Props['guru'];
    tahunAjaranId: number;
    kelasAwal?: number;
    onSelesai: () => void;
}) {
    const form = useForm({
        nama: siswa?.nama ?? '',
        jenis_kelamin: siswa?.jenis_kelamin ?? '',
        nis: siswa?.nis ?? '',
        nisn: siswa?.nisn ?? '',
        tanggal_lahir: siswa?.tanggal_lahir ?? '',
        kelas_id: siswa?.kelas_id ?? kelasAwal ?? 0,
        anak_guru_id: siswa?.anak_guru_id ?? (null as number | null),
    });
    const d = form.data;
    const errors = form.errors as Record<string, string | undefined>;
    const jenjangTujuan = kelas.find((k) => k.id === d.kelas_id)?.jenjang;
    const pindahJenjang = siswa && jenjangTujuan !== undefined && jenjangTujuan !== siswa.jenjang;
    const pindahKelas = siswa && d.kelas_id !== siswa.kelas_id;

    function simpan(e: React.FormEvent) {
        e.preventDefault();
        const opsi = { preserveScroll: true, onSuccess: onSelesai };

        if (siswa) {
            form.put(DataSiswaController.update.url({ tahunAjaran: tahunAjaranId, siswa: siswa.id }), opsi);
        } else {
            form.post(DataSiswaController.store.url(tahunAjaranId), opsi);
        }
    }

    return (
        <form onSubmit={simpan} className="flex flex-col gap-5 px-4 pb-6">
            <SheetHeader className="px-0">
                <SheetTitle>{siswa ? `Ubah ${siswa.nama}` : 'Tambah siswa'}</SheetTitle>
                <SheetDescription>Orang tua mengklaim siswa ini sendiri dari aplikasi setelah datanya ada.</SheetDescription>
            </SheetHeader>

            <div className="grid gap-2">
                <Label htmlFor="nama">Nama lengkap</Label>
                <Input id="nama" value={d.nama} onChange={(e) => form.setData('nama', e.target.value)} />
                <InputError message={errors.nama} />
            </div>

            <div className="grid grid-cols-2 gap-4">
                <div className="grid gap-2">
                    <Label htmlFor="jenis_kelamin">Jenis kelamin</Label>
                    <select id="jenis_kelamin" value={d.jenis_kelamin} onChange={(e) => form.setData('jenis_kelamin', e.target.value)} className={kelasPilihan}>
                        <option value="">Belum diisi</option>
                        <option value="L">Laki-laki</option>
                        <option value="P">Perempuan</option>
                    </select>
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="tanggal_lahir">Tanggal lahir</Label>
                    <Input id="tanggal_lahir" type="date" value={d.tanggal_lahir} onChange={(e) => form.setData('tanggal_lahir', e.target.value)} />
                    <InputError message={errors.tanggal_lahir} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="nis">NIS</Label>
                    <Input id="nis" value={d.nis} onChange={(e) => form.setData('nis', e.target.value)} />
                    <InputError message={errors.nis} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="nisn">NISN</Label>
                    <Input id="nisn" value={d.nisn} onChange={(e) => form.setData('nisn', e.target.value)} />
                    <InputError message={errors.nisn} />
                </div>
            </div>

            <div className="grid gap-2">
                <Label htmlFor="kelas_id">Kelas</Label>
                <select id="kelas_id" value={d.kelas_id} onChange={(e) => form.setData('kelas_id', Number(e.target.value))} className={kelasPilihan}>
                    {kelas.map((k) => (
                        <option key={k.id} value={k.id}>
                            {k.nama}
                        </option>
                    ))}
                </select>
                {pindahKelas && !pindahJenjang && (
                    <p className="text-xs text-muted-foreground">Pindah kelas: les tambahan siswa ikut pindah ke les kelas tujuan.</p>
                )}
                {pindahJenjang && (
                    <p className="text-xs text-sekolah-tertunda">
                        Pindah ke jenjang lain hanya bisa bila siswa tidak ikut kegiatan apa pun{siswa && siswa.kegiatan_aktif > 0 ? ` (sekarang ${siswa.kegiatan_aktif} kegiatan aktif)` : ''}.
                    </p>
                )}
                <InputError message={errors.kelas_id} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor="anak_guru_id">Anak guru SD Plus</Label>
                <select
                    id="anak_guru_id"
                    value={d.anak_guru_id ?? ''}
                    onChange={(e) => form.setData('anak_guru_id', e.target.value === '' ? null : Number(e.target.value))}
                    className={kelasPilihan}
                >
                    <option value="">Bukan anak guru</option>
                    {siswa?.anak_guru_id && !guru.some((g) => g.id === siswa.anak_guru_id) && (
                        <option value={siswa.anak_guru_id}>{siswa.anak_guru} (tidak aktif)</option>
                    )}
                    {guru.map((g) => (
                        <option key={g.id} value={g.id}>
                            {g.nama}
                        </option>
                    ))}
                </select>
                <p className="text-xs text-muted-foreground">
                    Anak guru aktif mendapat diskon 50%. Bila diubah, tagihan tahun ini yang belum dibayar sama sekali ikut disesuaikan.
                </p>
                <InputError message={errors.anak_guru_id} />
            </div>

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

DataSiswa.layout = {
    breadcrumbs: [{ title: 'Data Siswa', href: index() }],
};
