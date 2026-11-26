import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, Download, Upload } from 'lucide-react';
import { useMemo, useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { tanggal } from '@/lib/format';
import { cn } from '@/lib/utils';
import { index as dataSiswa } from '@/routes/les-ekskul/data-siswa';
import imporSiswa from '@/routes/les-ekskul/impor-siswa';

type Tahap = 'juli' | 'dapodik';
type Status = 'lama' | 'tetap' | 'pindah' | 'baru' | 'pilih' | 'lengkapi' | 'error';
type Aksi = 'cocok' | 'baru' | 'lewati';
type SiswaCocok = { id: number; nama: string; kelas: string; jk: 'L' | 'P' | null };
type Keputusan = { aksi: Aksi | null; siswa_id: number | null; nama: 'aplikasi' | 'file' };

type Baris = {
    no: number;
    nama: string;
    kelas: string;
    jk: 'L' | 'P' | null;
    status: Status;
    pesan: string | null;
    siswa: SiswaCocok | null;
    kandidat: SiswaCocok[];
    perubahan: { kolom: string; lama: string | null; baru: string }[];
    nama_beda: boolean;
    bawaan: Keputusan;
};

type Pratinjau = {
    tahap: Tahap;
    nama_file: string;
    tahun_ajaran: { id: number; nama: string };
    baris: Baris[];
    ringkasan: Partial<Record<Status, number>>;
    tidak_di_file: { id: number; nama: string; kelas: string }[];
};

type Props = {
    daftarTahun: { id: number; nama: string; aktif: boolean }[];
    pratinjau: Pratinjau | null;
};

const kelasPilihan = 'h-9 rounded-md border border-input bg-transparent px-2 text-sm shadow-xs';

const labelStatus: Record<Status, string> = {
    pilih: 'Perlu dipilih',
    baru: 'Siswa baru',
    pindah: 'Pindah kelas',
    lama: 'Siswa lama',
    lengkapi: 'Dilengkapi',
    tetap: 'Tidak berubah',
    error: 'Error',
};

const warnaStatus: Record<Status, string> = {
    pilih: 'border-amber-500/40 bg-amber-500/10 text-amber-800 dark:text-amber-300',
    baru: 'border-sky-500/40 bg-sky-500/10 text-sky-800 dark:text-sky-300',
    pindah: 'border-violet-500/40 bg-violet-500/10 text-violet-800 dark:text-violet-300',
    lama: 'border-emerald-500/40 bg-emerald-500/10 text-emerald-800 dark:text-emerald-300',
    lengkapi: 'border-emerald-500/40 bg-emerald-500/10 text-emerald-800 dark:text-emerald-300',
    tetap: 'text-muted-foreground',
    error: 'border-rose-500/40 bg-rose-500/10 text-rose-800 dark:text-rose-300',
};

// Impor data siswa dari Excel (FR-28, Ringkasan v1.11 #53), khusus TU: unggah → pratinjau & keputusan → simpan.
// File tidak disimpan di server; nama mirip dipilih TU beserta nama yang dipakai
export default function ImporSiswa({ daftarTahun, pratinjau }: Props) {
    return (
        <>
            <Head title="Impor Siswa" />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Heading
                        title="Impor Siswa dari Excel"
                        description="Juli: daftar pembagian kelas memakai template aplikasi. Oktober: ekspor Dapodik apa adanya untuk melengkapi NIS, NISN, tanggal lahir, dan L/P. File hanya dibaca, tidak disimpan."
                    />
                    <Button variant="ghost" asChild>
                        <Link href={dataSiswa()}>
                            <ArrowLeft /> Data Siswa
                        </Link>
                    </Button>
                </div>

                {pratinjau ? <TinjauImpor pratinjau={pratinjau} /> : <FormUnggah daftarTahun={daftarTahun} />}
            </div>
        </>
    );
}

function FormUnggah({ daftarTahun }: { daftarTahun: Props['daftarTahun'] }) {
    const terbaru = daftarTahun[0]?.id ?? null;
    const [tahunJuli, setTahunJuli] = useState<number | null>(terbaru);
    const aktif = daftarTahun.find((t) => t.aktif);

    return (
        <div className="grid gap-4 lg:grid-cols-2">
            <KartuUnggah
                tahap="juli"
                judul="Tahap Juli — pembagian kelas"
                keterangan="Isi template: Nama, Kelas (mis. 1A), L/P (boleh kosong). Berlaku untuk semua kelas: siswa lama dicocokkan lewat nama + jenjang tahun lalu, siswa yang kelasnya berbeda dipindah sesuai file."
                tahunAjaranId={tahunJuli}
                tambahan={
                    <div className="flex flex-wrap items-end gap-2">
                        <div className="grid gap-1">
                            <Label htmlFor="tahun">Tahun ajaran tujuan</Label>
                            <select id="tahun" value={tahunJuli ?? ''} onChange={(e) => setTahunJuli(Number(e.target.value))} className={kelasPilihan}>
                                {daftarTahun.map((t) => (
                                    <option key={t.id} value={t.id}>
                                        {t.nama}
                                        {t.aktif ? ' (aktif)' : ''}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <Button variant="outline" asChild>
                            <a href={imporSiswa.template({ query: { tahun: tahunJuli ?? undefined } }).url}>
                                <Download /> Unduh template
                            </a>
                        </Button>
                    </div>
                }
            />
            <KartuUnggah
                tahap="dapodik"
                judul="Tahap Oktober — ekspor Dapodik"
                keterangan={`Unggah file ekspor Dapodik tanpa diubah (Sheet1 "Daftar Peserta Didik"). Untuk tahun ajaran aktif${aktif ? ` (${aktif.nama})` : ''}. Siswa yang tidak ditemukan ditawarkan ditambahkan. Kolom sensitif (NIK, KK, alamat) tidak dibaca.`}
                tahunAjaranId={aktif?.id ?? null}
            />
        </div>
    );
}

function KartuUnggah({ tahap, judul, keterangan, tahunAjaranId, tambahan }: { tahap: Tahap; judul: string; keterangan: string; tahunAjaranId: number | null; tambahan?: React.ReactNode }) {
    const form = useForm<{ tahap: Tahap; tahun_ajaran_id: number | null; file: File | null }>({ tahap, tahun_ajaran_id: tahunAjaranId, file: null });

    function unggah(e: React.FormEvent) {
        e.preventDefault();
        form.transform((d) => ({ ...d, tahun_ajaran_id: tahunAjaranId }));
        form.post(imporSiswa.unggah.url(), { forceFormData: true });
    }

    return (
        <Card className="gap-4 py-4">
            <CardHeader className="px-4">
                <CardTitle className="text-base">{judul}</CardTitle>
                <CardDescription>{keterangan}</CardDescription>
            </CardHeader>
            <CardContent className="flex flex-col gap-3 px-4">
                {tambahan}
                <form onSubmit={unggah} className="flex flex-col gap-2">
                    <input
                        type="file"
                        accept=".xls,.xlsx"
                        onChange={(e) => form.setData('file', e.target.files?.[0] ?? null)}
                        className="text-sm file:mr-3 file:rounded-md file:border file:border-input file:bg-transparent file:px-3 file:py-1.5 file:text-sm"
                    />
                    <InputError message={form.errors.file ?? form.errors.tahun_ajaran_id} />
                    <Button type="submit" className="self-start" disabled={!form.data.file || form.processing}>
                        <Upload /> {form.processing ? 'Membaca file…' : 'Unggah & tinjau'}
                    </Button>
                </form>
            </CardContent>
        </Card>
    );
}

function TinjauImpor({ pratinjau }: { pratinjau: Pratinjau }) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const [keputusan, setKeputusan] = useState<Record<number, Keputusan>>(() =>
        Object.fromEntries(pratinjau.baris.map((b) => [b.no, b.bawaan])),
    );
    const [filter, setFilter] = useState<Status | 'semua'>(pratinjau.ringkasan.pilih ? 'pilih' : 'semua');
    const [menyimpan, setMenyimpan] = useState(false);

    const ubah = (no: number, isi: Partial<Keputusan>) => setKeputusan((k) => ({ ...k, [no]: { ...k[no], ...isi } }));
    const belumDipilih = pratinjau.baris.filter((b) => b.status !== 'error' && keputusan[b.no]?.aksi === null).length;
    const tampil = useMemo(() => pratinjau.baris.filter((b) => filter === 'semua' || b.status === filter), [pratinjau.baris, filter]);
    const urutan: Status[] = ['pilih', 'baru', 'pindah', 'lama', 'lengkapi', 'tetap', 'error'];

    function simpan() {
        router.post(imporSiswa.simpan.url(), { keputusan }, { onStart: () => setMenyimpan(true), onFinish: () => setMenyimpan(false), preserveScroll: true });
    }

    function batal() {
        if (confirm('Batalkan impor ini? Hasil bacaan file dibuang dan tidak ada data yang disimpan.')) {
            router.delete(imporSiswa.batal.url());
        }
    }

    return (
        <div className="flex flex-col gap-4">
            <Card className="gap-3 py-4">
                <CardContent className="flex flex-wrap items-center justify-between gap-3 px-4 text-sm">
                    <div>
                        <p className="font-medium">
                            {pratinjau.tahap === 'juli' ? 'Tahap Juli' : 'Tahap Oktober (Dapodik)'} · tahun ajaran {pratinjau.tahun_ajaran.nama}
                        </p>
                        <p className="text-muted-foreground">
                            {pratinjau.nama_file} · {pratinjau.baris.length} baris. Belum ada yang disimpan; tinjau lalu tekan Simpan.
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <Button variant="ghost" onClick={batal}>
                            Batal
                        </Button>
                        <Button onClick={simpan} disabled={belumDipilih > 0 || menyimpan}>
                            {menyimpan ? 'Menyimpan…' : belumDipilih > 0 ? `${belumDipilih} baris belum dipilih` : 'Simpan impor'}
                        </Button>
                    </div>
                </CardContent>
            </Card>

            <InputError message={errors.keputusan ?? errors.kelas_id ?? errors.nama} />

            <div className="flex flex-wrap gap-2">
                <TombolFilter aktif={filter === 'semua'} onClick={() => setFilter('semua')}>
                    Semua ({pratinjau.baris.length})
                </TombolFilter>
                {urutan.filter((s) => pratinjau.ringkasan[s]).map((s) => (
                    <TombolFilter key={s} aktif={filter === s} onClick={() => setFilter(s)}>
                        {labelStatus[s]} ({pratinjau.ringkasan[s]})
                    </TombolFilter>
                ))}
            </div>

            <div className="overflow-x-auto rounded-xl border">
                <table className="w-full text-sm">
                    <thead className="bg-muted/50 text-left">
                        <tr>
                            <th className="w-14 p-2">Baris</th>
                            <th className="p-2">Di file</th>
                            <th className="p-2">Status</th>
                            <th className="p-2">Keputusan</th>
                        </tr>
                    </thead>
                    <tbody>
                        {tampil.map((b) => (
                            <tr key={b.no} className="border-t align-top">
                                <td className="p-2 text-muted-foreground tabular-nums">{b.no}</td>
                                <td className="p-2">
                                    <p className="font-medium">{b.nama || '–'}</p>
                                    <p className="text-xs text-muted-foreground">
                                        {b.kelas}
                                        {b.jk && ` · ${b.jk}`}
                                    </p>
                                </td>
                                <td className="p-2">
                                    <Badge variant="outline" className={cn('whitespace-nowrap', warnaStatus[b.status])}>
                                        {labelStatus[b.status]}
                                    </Badge>
                                    {b.pesan && <p className={cn('mt-1 text-xs', b.status === 'error' ? 'text-rose-700 dark:text-rose-300' : 'text-muted-foreground')}>{b.pesan}</p>}
                                    {b.perubahan.map((p) => (
                                        <p key={p.kolom} className="mt-1 text-xs text-muted-foreground">
                                            {p.kolom}: {p.lama ? tampilNilai(p.kolom, p.lama) : '–'} → <span className="text-foreground">{tampilNilai(p.kolom, p.baru)}</span>
                                        </p>
                                    ))}
                                </td>
                                <td className="p-2">{b.status !== 'error' && <IsiKeputusan baris={b} keputusan={keputusan[b.no]} onUbah={(isi) => ubah(b.no, isi)} />}</td>
                            </tr>
                        ))}
                        {tampil.length === 0 && (
                            <tr>
                                <td colSpan={4} className="p-6 text-center text-muted-foreground">
                                    Tidak ada baris.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>

            {pratinjau.tidak_di_file.length > 0 && (
                <details className="rounded-xl border p-3 text-sm">
                    <summary className="cursor-pointer font-medium">
                        {pratinjau.tidak_di_file.length} siswa di aplikasi tidak disebut di file (tidak diubah)
                    </summary>
                    <ul className="mt-2 grid gap-1 text-muted-foreground sm:grid-cols-2 lg:grid-cols-3">
                        {pratinjau.tidak_di_file.map((s) => (
                            <li key={s.id}>
                                {s.nama} · {s.kelas}
                            </li>
                        ))}
                    </ul>
                </details>
            )}
        </div>
    );
}

// Pilihan per baris: cocokkan dengan siswa (dan nama mana yang dipakai), buat baru, atau lewati
function IsiKeputusan({ baris, keputusan, onUbah }: { baris: Baris; keputusan: Keputusan; onUbah: (isi: Partial<Keputusan>) => void }) {
    const pilihan = [baris.siswa, ...baris.kandidat].filter((s): s is SiswaCocok => s !== null);
    const terpilih = pilihan.find((s) => s.id === keputusan.siswa_id) ?? null;
    const nilai = keputusan.aksi === 'cocok' ? `s${keputusan.siswa_id}` : (keputusan.aksi ?? '');
    const namaBeda = keputusan.aksi === 'cocok' && terpilih && terpilih.nama.trim() !== baris.nama.trim();

    return (
        <div className="flex flex-col gap-2">
            <select
                value={nilai}
                onChange={(e) => {
                    const v = e.target.value;
                    onUbah(v.startsWith('s') ? { aksi: 'cocok', siswa_id: Number(v.slice(1)) } : { aksi: (v || null) as Aksi | null, siswa_id: null });
                }}
                className={cn(kelasPilihan, 'max-w-sm', keputusan.aksi === null && 'border-amber-500')}
            >
                {keputusan.aksi === null && <option value="">— pilih —</option>}
                {pilihan.map((s) => (
                    <option key={s.id} value={`s${s.id}`}>
                        {baris.status === 'pilih' ? 'Sama dengan: ' : 'Cocok: '}
                        {s.nama} ({s.kelas})
                    </option>
                ))}
                {(baris.status === 'pilih' || baris.status === 'baru') && <option value="baru">Buat siswa baru</option>}
                <option value="lewati">Lewati baris ini</option>
            </select>

            {namaBeda && (
                <div className="flex flex-col gap-1 text-xs">
                    <span className="text-muted-foreground">Nama yang dipakai:</span>
                    <label className="flex items-center gap-2">
                        <input type="radio" checked={keputusan.nama === 'aplikasi'} onChange={() => onUbah({ nama: 'aplikasi' })} className="accent-primary" />
                        Nama di aplikasi: <strong>{terpilih.nama}</strong>
                    </label>
                    <label className="flex items-center gap-2">
                        <input type="radio" checked={keputusan.nama === 'file'} onChange={() => onUbah({ nama: 'file' })} className="accent-primary" />
                        Nama di file: <strong>{baris.nama}</strong>
                    </label>
                </div>
            )}
        </div>
    );
}

function TombolFilter({ aktif, onClick, children }: { aktif: boolean; onClick: () => void; children: React.ReactNode }) {
    return (
        <Button size="sm" variant={aktif ? 'default' : 'outline'} onClick={onClick}>
            {children}
        </Button>
    );
}

function tampilNilai(kolom: string, nilai: string): string {
    return kolom === 'Tanggal lahir' ? tanggal(nilai) : nilai;
}

ImporSiswa.layout = {
    breadcrumbs: [
        { title: 'Data Siswa', href: dataSiswa() },
        { title: 'Impor Siswa', href: imporSiswa.index() },
    ],
};
