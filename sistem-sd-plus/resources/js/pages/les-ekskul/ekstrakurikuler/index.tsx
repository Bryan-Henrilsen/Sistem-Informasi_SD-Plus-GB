import { Head, router, useForm } from '@inertiajs/react';
import { Plus, Trash2, X } from 'lucide-react';
import { useState } from 'react';
import EkstrakurikulerController from '@/actions/App/Domains/LesEkskul/Http/Controllers/EkstrakurikulerController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { rupiah } from '@/lib/format';
import { cn } from '@/lib/utils';
import { index } from '@/routes/les-ekskul/ekstrakurikuler';

type Jenis = 'biasa' | 'honor_flat' | 'wajib';
type Jadwal = { id?: number; hari: number; jam_mulai: string; jam_selesai: string; jenjang_min: number | null; jenjang_max: number | null; lokasi: string };
type KelasParalel = { id?: number; nama: string; kuota: number | null; terisi?: number; bisa_dihapus?: boolean };
type Kegiatan = {
    id: number;
    nama: string;
    jenis: Jenis;
    biaya: number;
    biaya_non_honor: number;
    kuota: number | null;
    jenjang_min: number | null;
    jenjang_max: number | null;
    terisi: number;
    jadwal: Jadwal[];
    kelas_paralel: KelasParalel[];
    bisa_dihapus: boolean;
};
type Props = {
    tahunAjaran: { id: number; nama: string };
    daftarTahun: { id: number; nama: string }[];
    kegiatan: Kegiatan[];
    sumberSalinan: { id: number; nama: string } | null;
    bisaUbah: boolean;
};

const HARI: Record<number, string> = { 5: 'Jumat', 6: 'Sabtu' };
const JENIS: Record<Jenis, { label: string; keterangan: string; kelas: string }> = {
    biasa: { label: 'Ekskul biasa', keterangan: 'Berbayar per bulan, siswa mendaftar', kelas: 'bg-muted text-muted-foreground' },
    honor_flat: {
        label: 'Honor flat',
        keterangan: 'Tanpa biaya, tetap mendaftar & berkuota (mis. Olimpiade)',
        kelas: 'bg-peran-ortu/15 text-peran-ortu',
    },
    wajib: {
        label: 'Wajib otomatis',
        keterangan: 'Tanpa biaya & pendaftaran, semua siswa di jenjangnya (Bina Karakter, Pramuka)',
        kelas: 'bg-sekolah-info/15 text-sekolah-info',
    },
};
const kelasPilihan = 'h-9 rounded-md border border-input bg-transparent px-2 text-sm shadow-xs';

const jenjangTeks = (min: number | null, max: number | null) => (min === null ? 'Semua kelas' : min === max ? `Kelas ${min}` : `Kelas ${min}–${max}`);

// Data master Kegiatan Ekstrakurikuler per tahun ajaran (Ringkasan v1.11 #42). TU mengubah; Kepala Sekolah & Bendahara melihat.
// Pengajar, pendamping, dan cara hitung honor diatur di halaman Pengajar Ekskul
export default function KegiatanEkstrakurikuler({ tahunAjaran, daftarTahun, kegiatan, sumberSalinan, bisaUbah }: Props) {
    // null = panel tertutup; 'baru' = tambah; selain itu kegiatan yang sedang diubah
    const [dibuka, setDibuka] = useState<Kegiatan | 'baru' | null>(null);

    function hapus(k: Kegiatan) {
        if (confirm(`Hapus ${k.nama} beserta jadwal dan kelas paralelnya?`)) {
            router.delete(EkstrakurikulerController.destroy.url(k.id), { preserveScroll: true });
        }
    }

    return (
        <>
            <Head title="Kegiatan Ekskul" />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Heading
                        title="Kegiatan Ekstrakurikuler"
                        description="Daftar kegiatan, biaya, kuota, jadwal, dan kelas paralel per tahun ajaran. Pengajar dan pendamping diatur di halaman Pengajar Ekskul."
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
                                <Plus /> Tambah kegiatan
                            </Button>
                        )}
                    </div>
                </div>

                {sumberSalinan && (
                    <Card className="border-sekolah-info/40 py-4">
                        <CardContent className="flex flex-wrap items-center justify-between gap-3 px-4 text-sm">
                            <p>
                                Tahun ajaran {tahunAjaran.nama} belum punya kegiatan. Salin semua kegiatan dari {sumberSalinan.nama} beserta jadwal, kelas paralel,
                                pengajar, dan pendampingnya, lalu ubah yang perlu.
                            </p>
                            <Button
                                variant="outline"
                                onClick={() => router.post(EkstrakurikulerController.salin.url(tahunAjaran.id), {}, { preserveScroll: true })}
                            >
                                Salin dari {sumberSalinan.nama}
                            </Button>
                        </CardContent>
                    </Card>
                )}

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="min-w-48 p-3">Kegiatan</th>
                                <th className="p-3 text-right whitespace-nowrap">Biaya / bulan</th>
                                <th className="p-3 text-right">Peserta</th>
                                <th className="p-3">Jenjang</th>
                                <th className="p-3">Jadwal</th>
                                {bisaUbah && <th className="p-3" />}
                            </tr>
                        </thead>
                        <tbody>
                            {kegiatan.map((k) => (
                                <tr key={k.id} className="border-t align-top">
                                    <td className="p-3">
                                        <p className="font-medium">{k.nama}</p>
                                        <span className={cn('mt-1 inline-block rounded-full px-2 py-0.5 text-xs whitespace-nowrap', JENIS[k.jenis].kelas)}>{JENIS[k.jenis].label}</span>
                                        {k.kelas_paralel.length > 0 && (
                                            <ul className="mt-1 text-xs text-muted-foreground">
                                                {k.kelas_paralel.map((p) => (
                                                    <li key={p.id}>
                                                        {p.nama} · {p.terisi}
                                                        {p.kuota ? `/${p.kuota}` : ''} siswa
                                                    </li>
                                                ))}
                                            </ul>
                                        )}
                                    </td>
                                    <td className="p-3 text-right tabular-nums">
                                        {k.biaya > 0 ? rupiah(k.biaya) : 'Rp0'}
                                        {k.biaya_non_honor > 0 && <p className="text-xs text-muted-foreground">non-honor {rupiah(k.biaya_non_honor)}</p>}
                                    </td>
                                    <td className="p-3 text-right tabular-nums">
                                        {k.jenis === 'wajib' ? (
                                            <span className="text-muted-foreground">otomatis</span>
                                        ) : (
                                            <>
                                                {k.terisi}
                                                {k.kuota ? ` / ${k.kuota}` : ''}
                                                {!k.kuota && <p className="text-xs text-muted-foreground">tanpa batas</p>}
                                            </>
                                        )}
                                    </td>
                                    <td className="p-3 whitespace-nowrap">{jenjangTeks(k.jenjang_min, k.jenjang_max)}</td>
                                    <td className="p-3">
                                        {k.jadwal.length === 0 && <span className="text-muted-foreground">Belum diatur</span>}
                                        {k.jadwal.map((j) => (
                                            <p key={j.id} className="whitespace-nowrap">
                                                {HARI[j.hari] ?? `Hari ${j.hari}`} {j.jam_mulai}–{j.jam_selesai}
                                                {j.jenjang_min !== null && <span className="text-muted-foreground"> · {jenjangTeks(j.jenjang_min, j.jenjang_max).toLowerCase()}</span>}
                                                <span className="text-muted-foreground"> · {j.lokasi}</span>
                                            </p>
                                        ))}
                                    </td>
                                    {bisaUbah && (
                                        <td className="p-3">
                                            <div className="flex justify-end gap-1">
                                                <Button size="sm" variant="outline" onClick={() => setDibuka(k)}>
                                                    Ubah
                                                </Button>
                                                {k.bisa_dihapus && (
                                                    <Button size="icon" variant="ghost" className="size-8" title={`Hapus ${k.nama}`} onClick={() => hapus(k)}>
                                                        <Trash2 className="size-4" />
                                                    </Button>
                                                )}
                                            </div>
                                        </td>
                                    )}
                                </tr>
                            ))}
                            {kegiatan.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="p-6 text-center text-muted-foreground">
                                        Belum ada kegiatan di tahun ajaran {tahunAjaran.nama}.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>

            <Sheet open={dibuka !== null} onOpenChange={(buka) => !buka && setDibuka(null)}>
                <SheetContent className="w-full overflow-y-auto sm:max-w-xl">
                    {dibuka !== null && (
                        <FormKegiatan
                            key={dibuka === 'baru' ? 'baru' : dibuka.id}
                            kegiatan={dibuka === 'baru' ? null : dibuka}
                            tahunAjaranId={tahunAjaran.id}
                            onSelesai={() => setDibuka(null)}
                        />
                    )}
                </SheetContent>
            </Sheet>
        </>
    );
}

// Form tambah/ubah satu kegiatan induk beserta jadwal & kelas paralel; aturan lengkap dijaga EkstrakurikulerService
function FormKegiatan({ kegiatan, tahunAjaranId, onSelesai }: { kegiatan: Kegiatan | null; tahunAjaranId: number; onSelesai: () => void }) {
    const form = useForm({
        jenis: (kegiatan?.jenis ?? 'biasa') as Jenis,
        nama: kegiatan?.nama ?? '',
        biaya: kegiatan?.biaya ?? (null as number | null),
        biaya_non_honor: kegiatan?.biaya_non_honor ?? 0,
        kuota: kegiatan?.kuota ?? (null as number | null),
        jenjang_min: kegiatan?.jenjang_min ?? (null as number | null),
        jenjang_max: kegiatan?.jenjang_max ?? (null as number | null),
        jadwal: (kegiatan?.jadwal ?? []).map(({ id: _id, ...j }) => j) as Jadwal[],
        kelas_paralel: (kegiatan?.kelas_paralel ?? []).map((p) => ({ id: p.id, nama: p.nama, kuota: p.kuota })) as KelasParalel[],
    });
    const errors = form.errors as Record<string, string | undefined>;
    const d = form.data;
    const sudahAdaPeserta = (kegiatan?.terisi ?? 0) > 0 || (kegiatan?.kelas_paralel.some((p) => !p.bisa_dihapus) ?? false);
    const biayaBerubah = kegiatan !== null && d.jenis === 'biasa' && Number(d.biaya) !== kegiatan.biaya;

    function simpan(e: React.FormEvent) {
        e.preventDefault();
        const opsi = { preserveScroll: true, onSuccess: onSelesai };

        if (kegiatan) {
            form.put(EkstrakurikulerController.update.url(kegiatan.id), opsi);
        } else {
            form.post(EkstrakurikulerController.store.url(tahunAjaranId), opsi);
        }
    }

    const angka = (v: string) => (v === '' ? null : Number(v));
    const ubahJadwal = (i: number, isi: Partial<Jadwal>) => form.setData('jadwal', d.jadwal.map((j, x) => (x === i ? { ...j, ...isi } : j)));
    const ubahParalel = (i: number, isi: Partial<KelasParalel>) => form.setData('kelas_paralel', d.kelas_paralel.map((p, x) => (x === i ? { ...p, ...isi } : p)));
    const paralelLama = (id?: number) => kegiatan?.kelas_paralel.find((p) => p.id === id);

    return (
        <form onSubmit={simpan} className="flex flex-col gap-5 px-4 pb-6">
            <SheetHeader className="px-0">
                <SheetTitle>{kegiatan ? `Ubah ${kegiatan.nama}` : 'Tambah kegiatan'}</SheetTitle>
                <SheetDescription>Pengajar dan pendamping diatur terpisah di halaman Pengajar Ekskul.</SheetDescription>
            </SheetHeader>

            <fieldset className="flex flex-col gap-2">
                <legend className="mb-1 text-sm font-medium">Jenis kegiatan</legend>
                {(Object.keys(JENIS) as Jenis[]).map((j) => (
                    <label key={j} className={cn('flex cursor-pointer items-start gap-2 rounded-md border p-2.5 text-sm', d.jenis === j && 'border-primary bg-muted/40')}>
                        <input
                            type="radio"
                            name="jenis"
                            className="mt-1"
                            checked={d.jenis === j}
                            disabled={sudahAdaPeserta}
                            onChange={() => form.setData('jenis', j)}
                        />
                        <span>
                            {JENIS[j].label}
                            <span className="block text-xs text-muted-foreground">{JENIS[j].keterangan}</span>
                        </span>
                    </label>
                ))}
                {sudahAdaPeserta && <p className="text-xs text-muted-foreground">Jenis tidak bisa diubah karena sudah ada pendaftar.</p>}
                <InputError message={errors.jenis} />
            </fieldset>

            <div className="grid gap-2">
                <Label htmlFor="nama">Nama kegiatan</Label>
                <Input id="nama" value={d.nama} onChange={(e) => form.setData('nama', e.target.value)} placeholder="mis. Basket" />
                <InputError message={errors.nama} />
            </div>

            {d.jenis === 'biasa' && (
                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="grid gap-2">
                        <Label htmlFor="biaya">Biaya per bulan (Rp)</Label>
                        <Input id="biaya" type="number" min={1} value={d.biaya ?? ''} onChange={(e) => form.setData('biaya', angka(e.target.value))} />
                        <InputError message={errors.biaya} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="biaya_non_honor">Uang non-honor (Rp)</Label>
                        <Input
                            id="biaya_non_honor"
                            type="number"
                            min={0}
                            value={d.biaya_non_honor}
                            onChange={(e) => form.setData('biaya_non_honor', Number(e.target.value) || 0)}
                        />
                        <p className="text-xs text-muted-foreground">Bagian biaya untuk pihak ketiga, mis. uang kolam. Tidak ikut dibagi honor.</p>
                        <InputError message={errors.biaya_non_honor} />
                    </div>
                    {biayaBerubah && (
                        <p className="rounded-md bg-sekolah-tertunda/10 p-2 text-xs text-sekolah-tertunda sm:col-span-2">
                            Biaya baru hanya berlaku untuk tagihan yang dibuat sesudah disimpan. Tagihan yang sudah ada tetap memakai biaya lama.
                        </p>
                    )}
                </div>
            )}

            <div className="grid gap-4 sm:grid-cols-2">
                {d.jenis !== 'wajib' && (
                    <div className="grid gap-2">
                        <Label htmlFor="kuota">Kuota</Label>
                        <Input id="kuota" type="number" min={1} placeholder="Kosong = tanpa batas" value={d.kuota ?? ''} onChange={(e) => form.setData('kuota', angka(e.target.value))} />
                        <InputError message={errors.kuota} />
                    </div>
                )}
                <div className="grid gap-2">
                    <Label>{d.jenis === 'wajib' ? 'Jenjang peserta (wajib)' : 'Jenjang sasaran'}</Label>
                    <div className="flex items-center gap-2 text-sm">
                        <PilihJenjang nilai={d.jenjang_min} onChange={(v) => form.setData('jenjang_min', v)} kosong={d.jenis === 'wajib' ? 'Pilih' : 'Semua'} />
                        <span>sampai</span>
                        <PilihJenjang nilai={d.jenjang_max} onChange={(v) => form.setData('jenjang_max', v)} kosong={d.jenis === 'wajib' ? 'Pilih' : 'Semua'} />
                    </div>
                    <InputError message={errors.jenjang_min ?? errors.jenjang_max} />
                </div>
            </div>

            <div className="flex flex-col gap-2">
                <div className="flex items-center justify-between">
                    <p className="text-sm font-medium">Jadwal</p>
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={() => form.setData('jadwal', [...d.jadwal, { hari: 6, jam_mulai: '', jam_selesai: '', jenjang_min: null, jenjang_max: null, lokasi: '' }])}
                    >
                        <Plus /> Tambah jadwal
                    </Button>
                </div>
                {d.jadwal.length === 0 && <p className="text-xs text-muted-foreground">Belum ada jadwal. Jadwal dipakai untuk mencegah pendaftaran yang bentrok.</p>}
                {d.jadwal.map((j, i) => (
                    <div key={i} className="flex flex-col gap-2 rounded-md border p-2.5">
                        <div className="flex flex-wrap items-center gap-2">
                            <select aria-label="Hari" value={j.hari} onChange={(e) => ubahJadwal(i, { hari: Number(e.target.value) })} className={kelasPilihan}>
                                <option value={5}>Jumat</option>
                                <option value={6}>Sabtu</option>
                            </select>
                            <Input aria-label="Jam mulai" type="time" className="w-28" value={j.jam_mulai} onChange={(e) => ubahJadwal(i, { jam_mulai: e.target.value })} />
                            <span className="text-sm">–</span>
                            <Input aria-label="Jam selesai" type="time" className="w-28" value={j.jam_selesai} onChange={(e) => ubahJadwal(i, { jam_selesai: e.target.value })} />
                            <Button
                                type="button"
                                size="icon"
                                variant="ghost"
                                className="ml-auto size-8"
                                title="Hapus jadwal"
                                onClick={() => form.setData('jadwal', d.jadwal.filter((_, x) => x !== i))}
                            >
                                <X className="size-4" />
                            </Button>
                        </div>
                        <div className="flex flex-wrap items-center gap-2 text-sm">
                            <Input aria-label="Lokasi" placeholder="Lokasi, mis. Lapangan" className="min-w-40 flex-1" value={j.lokasi} onChange={(e) => ubahJadwal(i, { lokasi: e.target.value })} />
                            <span className="text-muted-foreground">kelas</span>
                            <PilihJenjang nilai={j.jenjang_min} onChange={(v) => ubahJadwal(i, { jenjang_min: v })} kosong="Semua" />
                            <span>–</span>
                            <PilihJenjang nilai={j.jenjang_max} onChange={(v) => ubahJadwal(i, { jenjang_max: v })} kosong="Semua" />
                        </div>
                        <InputError
                            message={
                                errors[`jadwal.${i}.jam_mulai`] ??
                                errors[`jadwal.${i}.jam_selesai`] ??
                                errors[`jadwal.${i}.lokasi`] ??
                                errors[`jadwal.${i}.jenjang_min`] ??
                                errors[`jadwal.${i}.hari`]
                            }
                        />
                    </div>
                ))}
                {d.jadwal.length > 1 && <p className="text-xs text-muted-foreground">Isi kelas pada jadwal bila jamnya berbeda per jenjang, mis. Basket kelas 1–3 dan 4–6.</p>}
            </div>

            {d.jenis !== 'wajib' && (
                <div className="flex flex-col gap-2">
                    <div className="flex items-center justify-between">
                        <div>
                            <p className="text-sm font-medium">Kelas paralel</p>
                            <p className="text-xs text-muted-foreground">Opsional, mis. English Club Starter/Mover/Flyer. Biaya mengikuti kegiatan ini.</p>
                        </div>
                        <Button type="button" size="sm" variant="outline" onClick={() => form.setData('kelas_paralel', [...d.kelas_paralel, { nama: '', kuota: null }])}>
                            <Plus /> Tambah kelas
                        </Button>
                    </div>
                    {d.kelas_paralel.map((p, i) => {
                        const lama = paralelLama(p.id);

                        return (
                            <div key={p.id ?? `baru-${i}`} className="flex items-center gap-2">
                                <Input aria-label="Nama kelas paralel" placeholder="Nama kelas" value={p.nama} onChange={(e) => ubahParalel(i, { nama: e.target.value })} />
                                <Input
                                    aria-label="Kuota kelas paralel"
                                    type="number"
                                    min={1}
                                    placeholder="Kuota"
                                    className="w-24"
                                    value={p.kuota ?? ''}
                                    onChange={(e) => ubahParalel(i, { kuota: angka(e.target.value) })}
                                />
                                {lama && !lama.bisa_dihapus ? (
                                    <span className="w-8 text-center text-xs text-muted-foreground" title="Sudah ada pendaftar">
                                        {lama.terisi}
                                    </span>
                                ) : (
                                    <Button
                                        type="button"
                                        size="icon"
                                        variant="ghost"
                                        className="size-8"
                                        title="Hapus kelas paralel"
                                        onClick={() => form.setData('kelas_paralel', d.kelas_paralel.filter((_, x) => x !== i))}
                                    >
                                        <X className="size-4" />
                                    </Button>
                                )}
                            </div>
                        );
                    })}
                    <InputError message={errors.kelas_paralel ?? Object.entries(errors).find(([k]) => k.startsWith('kelas_paralel.'))?.[1]} />
                </div>
            )}

            <InputError message={errors.kegiatan} />
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

function PilihJenjang({ nilai, onChange, kosong }: { nilai: number | null; onChange: (v: number | null) => void; kosong: string }) {
    return (
        <select aria-label="Jenjang" value={nilai ?? ''} onChange={(e) => onChange(e.target.value === '' ? null : Number(e.target.value))} className={kelasPilihan}>
            <option value="">{kosong}</option>
            {[1, 2, 3, 4, 5, 6].map((n) => (
                <option key={n} value={n}>
                    {n}
                </option>
            ))}
        </select>
    );
}

KegiatanEkstrakurikuler.layout = {
    breadcrumbs: [{ title: 'Kegiatan Ekskul', href: index() }],
};
