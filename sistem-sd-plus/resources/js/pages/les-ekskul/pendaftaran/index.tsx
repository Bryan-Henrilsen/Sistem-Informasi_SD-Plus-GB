import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import PendaftaranController from '@/actions/App/Domains/LesEkskul/Http/Controllers/PendaftaranController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { DialogBerhenti, DialogPindah } from '@/components/les-ekskul/dialog-perubahan-pendaftaran';
import type { EkskulTujuan, SaranTanggal } from '@/components/les-ekskul/dialog-perubahan-pendaftaran';
import { LabelStatus } from '@/components/les-ekskul/label-status';
import { Paginasi } from '@/components/les-ekskul/paginasi';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { tanggal } from '@/lib/format';
import { cn } from '@/lib/utils';
import { create, index } from '@/routes/les-ekskul/pendaftaran';
import type { Auth } from '@/types';
import type { Paginasi as PaginasiData, StatusPendaftaran, TipeKegiatan } from '@/types/les-ekskul';

type Baris = {
    id: number;
    siswa: { id: number; nama: string };
    kelas: string | null;
    jenjang: number | null;
    kegiatan: string;
    jenis: TipeKegiatan;
    status: StatusPendaftaran;
    tanggal_daftar: string;
    tanggal_efektif_berhenti: string | null;
    alasan_berhenti: string | null;
    biaya: number;
    orang_tua: string | null;
    induk_id: number | null;
    perlu_assign: boolean;
};

type KelasParalel = { id: number; nama: string; kuota: number | null; terisi: number };

type Props = {
    pendaftaran: PaginasiData<Baris>;
    filter: { nama?: string; kegiatan?: string; status?: string };
    kegiatan: EkskulTujuan[];
    kelasParalel: Record<string, KelasParalel[]>; // kunci = id kegiatan induk
    belumMemenuhi: { siswa_kelas_id: number; siswa: { id: number; nama: string }; kelas: string; kurang: string[] }[];
    dibebaskan: { siswa_kelas_id: number; siswa: { id: number; nama: string }; kelas: string; alasan: string }[];
    saranTanggal: SaranTanggal;
    tabAwal: 'semua' | 'belum';
};

const kelasPilihan = 'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';

export default function PendaftaranIndex({ pendaftaran, filter, kegiatan, kelasParalel, belumMemenuhi, dibebaskan, saranTanggal, tabAwal }: Props) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const bisaMengubah = auth.roles.includes('tu');
    const [tab, setTab] = useState<'semua' | 'belum'>(tabAwal);
    const [diassign, setDiassign] = useState<Baris | null>(null); // pendaftaran yang sedang dibuka di dialog assign
    const [dihentikan, setDihentikan] = useState<Baris | null>(null); // ... di dialog berhenti
    const [dipindah, setDipindah] = useState<Baris | null>(null); // ... di dialog pindah

    return (
        <>
            <Head title="Pendaftaran" />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Kelola Pendaftaran"
                        description="Pendaftaran les tambahan & ekstrakurikuler tahun ajaran aktif, termasuk input manual untuk pendaftaran yang telat atau luring."
                    />
                    {bisaMengubah && (
                        <Button asChild>
                            <Link href={create()}>Tambah pendaftaran</Link>
                        </Button>
                    )}
                </div>

                <div className="flex gap-1 border-b">
                    <TombolTab aktif={tab === 'semua'} onClick={() => setTab('semua')}>
                        Semua pendaftaran
                    </TombolTab>
                    <TombolTab aktif={tab === 'belum'} onClick={() => setTab('belum')}>
                        Belum memenuhi kewajiban ({belumMemenuhi.length})
                    </TombolTab>
                </div>

                {tab === 'semua' ? (
                    <>
                        <Form
                            {...PendaftaranController.index.form()}
                            options={{ preserveScroll: true }}
                            className="flex flex-wrap items-center gap-2"
                        >
                            <Input name="nama" placeholder="Cari nama siswa" defaultValue={filter.nama} className="w-56" />
                            <select name="kegiatan" defaultValue={filter.kegiatan ?? ''} className={kelasPilihan}>
                                <option value="">Semua kegiatan</option>
                                <option value="les">Les tambahan</option>
                                <option value="belum_assign">Belum di-assign kelas paralel</option>
                                {kegiatan.map((k) => (
                                    <option key={k.id} value={k.id}>
                                        {k.nama}
                                    </option>
                                ))}
                            </select>
                            <select name="status" defaultValue={filter.status ?? ''} className={kelasPilihan}>
                                <option value="">Semua status</option>
                                <option value="aktif">Aktif</option>
                                <option value="berhenti">Berhenti</option>
                                <option value="pindah">Pindah</option>
                            </select>
                            <Button type="submit" variant="secondary">
                                Terapkan
                            </Button>
                        </Form>

                        <div className="overflow-x-auto rounded-xl border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        <th className="p-3">Siswa</th>
                                        <th className="p-3">Kegiatan</th>
                                        <th className="p-3">No HP orang tua</th>
                                        <th className="p-3">Tanggal daftar</th>
                                        <th className="p-3">Status</th>
                                        <th className="p-3" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {pendaftaran.data.map((p) => {
                                        const bisaAssign =
                                            bisaMengubah && p.status === 'aktif' && p.induk_id !== null && kelasParalel[p.induk_id] !== undefined;

                                        return (
                                            <tr key={p.id} className="border-t align-top">
                                                <td className="p-3">
                                                    <div className="font-medium">{p.siswa.nama}</div>
                                                    <div className="text-xs text-muted-foreground">{p.kelas}</div>
                                                </td>
                                                <td className="p-3">
                                                    {p.kegiatan}
                                                    {p.perlu_assign && (
                                                        <div className="text-xs text-amber-600">Belum di-assign kelas paralel</div>
                                                    )}
                                                </td>
                                                <td className="p-3 text-muted-foreground">{p.orang_tua ?? '–'}</td>
                                                <td className="p-3 whitespace-nowrap">{tanggal(p.tanggal_daftar)}</td>
                                                <td className="p-3">
                                                    <LabelStatus status={p.status} />
                                                    {p.tanggal_efektif_berhenti && (
                                                        <div className="mt-1 text-xs text-muted-foreground">
                                                            mulai {tanggal(p.tanggal_efektif_berhenti)}
                                                        </div>
                                                    )}
                                                    {p.alasan_berhenti && (
                                                        <div className="text-xs text-muted-foreground">{p.alasan_berhenti}</div>
                                                    )}
                                                </td>
                                                <td className="p-3">
                                                    {bisaMengubah && p.status === 'aktif' && (
                                                        <div className="flex flex-col items-start gap-1">
                                                            {bisaAssign && (
                                                                <Button size="sm" variant="outline" onClick={() => setDiassign(p)}>
                                                                    {p.perlu_assign ? 'Assign kelas paralel' : 'Ganti kelas paralel'}
                                                                </Button>
                                                            )}
                                                            {p.jenis === 'ekstrakurikuler' && (
                                                                <Button size="sm" variant="outline" onClick={() => setDipindah(p)}>
                                                                    Pindah
                                                                </Button>
                                                            )}
                                                            <Button size="sm" variant="ghost" onClick={() => setDihentikan(p)}>
                                                                Berhenti
                                                            </Button>
                                                        </div>
                                                    )}
                                                </td>
                                            </tr>
                                        );
                                    })}
                                    {pendaftaran.data.length === 0 && (
                                        <tr>
                                            <td colSpan={6} className="p-6 text-center text-muted-foreground">
                                                Tidak ada pendaftaran yang cocok dengan filter.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>

                        <Paginasi data={pendaftaran} />
                    </>
                ) : (
                    <BelumMemenuhi data={belumMemenuhi} dibebaskan={dibebaskan} bisaMengubah={bisaMengubah} />
                )}
            </div>

            <DialogAssign
                pendaftaran={diassign}
                pilihan={diassign?.induk_id ? (kelasParalel[diassign.induk_id] ?? []) : []}
                onClose={() => setDiassign(null)}
            />
            <DialogBerhenti pendaftaran={dihentikan} saranTanggal={saranTanggal} onClose={() => setDihentikan(null)} />
            <DialogPindah pendaftaran={dipindah} kegiatan={kegiatan} saranTanggal={saranTanggal} onClose={() => setDipindah(null)} />
        </>
    );
}

function TombolTab({ aktif, onClick, children }: { aktif: boolean; onClick: () => void; children: React.ReactNode }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={cn(
                '-mb-px border-b-2 px-3 py-2 text-sm',
                aktif ? 'border-primary font-medium' : 'border-transparent text-muted-foreground hover:text-foreground',
            )}
        >
            {children}
        </button>
    );
}

// Laporan FR-33: siswa yang belum terdaftar di kegiatan yang diwajibkan, untuk ditindaklanjuti TU lewat input manual.
// Siswa berkondisi khusus (mis. sakit) bisa dibebaskan per tahun ajaran sehingga tidak muncul lagi di sini (#30)
function BelumMemenuhi({
    data,
    dibebaskan,
    bisaMengubah,
}: {
    data: Props['belumMemenuhi'];
    dibebaskan: Props['dibebaskan'];
    bisaMengubah: boolean;
}) {
    const [dibebaskanBaru, setDibebaskanBaru] = useState<Props['belumMemenuhi'][number] | null>(null);

    function cabut(siswaKelasId: number) {
        router.delete(PendaftaranController.cabutBebas.url(siswaKelasId), { preserveScroll: true });
    }

    return (
        <div className="flex flex-col gap-6">
            <div className="flex flex-col gap-3">
                <p className="text-sm text-muted-foreground">
                    Siswa tahun ajaran aktif yang belum terdaftar di kegiatan yang diwajibkan sekolah (saat ini: minimal satu
                    ekstrakurikuler; kelas 6 di semester genap tidak wajib). Bina Karakter dan Pramuka tidak dihitung. Siswa
                    berkondisi khusus dapat dibebaskan untuk tahun ajaran ini.
                </p>
                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Siswa</th>
                                <th className="p-3">Kelas</th>
                                <th className="p-3">Belum terdaftar di</th>
                                <th className="p-3" />
                            </tr>
                        </thead>
                        <tbody>
                            {data.map((b) => (
                                <tr key={b.siswa.id} className="border-t">
                                    <td className="p-3 font-medium">{b.siswa.nama}</td>
                                    <td className="p-3">{b.kelas}</td>
                                    <td className="p-3">{b.kurang.join(', ')}</td>
                                    <td className="p-3">
                                        {bisaMengubah && (
                                            <div className="flex gap-2">
                                                <Button size="sm" variant="outline" asChild>
                                                    <Link href={create({ query: { siswa: b.siswa.id } })}>Daftarkan</Link>
                                                </Button>
                                                {b.kurang.includes('ekstrakurikuler') && (
                                                    <Button size="sm" variant="ghost" onClick={() => setDibebaskanBaru(b)}>
                                                        Bebaskan
                                                    </Button>
                                                )}
                                            </div>
                                        )}
                                    </td>
                                </tr>
                            ))}
                            {data.length === 0 && (
                                <tr>
                                    <td colSpan={4} className="p-6 text-center text-muted-foreground">
                                        Semua siswa sudah memenuhi kewajiban pendaftaran.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>

            {dibebaskan.length > 0 && (
                <div className="flex flex-col gap-3">
                    <h3 className="text-sm font-medium">Dibebaskan dari kewajiban ekskul tahun ini</h3>
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full text-sm">
                            <tbody>
                                {dibebaskan.map((d) => (
                                    <tr key={d.siswa_kelas_id} className="border-t first:border-t-0">
                                        <td className="p-3 font-medium">{d.siswa.nama}</td>
                                        <td className="p-3">{d.kelas}</td>
                                        <td className="p-3 text-muted-foreground">{d.alasan}</td>
                                        <td className="p-3 text-right">
                                            {bisaMengubah && (
                                                <Button size="sm" variant="ghost" onClick={() => cabut(d.siswa_kelas_id)}>
                                                    Cabut
                                                </Button>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}

            <Dialog open={dibebaskanBaru !== null} onOpenChange={(buka) => !buka && setDibebaskanBaru(null)}>
                <DialogContent>
                    {dibebaskanBaru && (
                        <Form
                            {...PendaftaranController.bebaskan.form(dibebaskanBaru.siswa_kelas_id)}
                            options={{ preserveScroll: true }}
                            onSuccess={() => setDibebaskanBaru(null)}
                            className="flex flex-col gap-4"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <DialogHeader>
                                        <DialogTitle>Bebaskan dari kewajiban ekskul</DialogTitle>
                                        <DialogDescription>
                                            {dibebaskanBaru.siswa.nama} ({dibebaskanBaru.kelas}) tidak akan muncul lagi di daftar
                                            ini selama tahun ajaran berjalan. Pembebasan bisa dicabut kapan saja.
                                        </DialogDescription>
                                    </DialogHeader>
                                    <Input name="alasan" placeholder="Alasan, mis. kondisi kesehatan" autoFocus />
                                    <InputError message={errors.alasan} />
                                    <DialogFooter>
                                        <Button type="button" variant="ghost" onClick={() => setDibebaskanBaru(null)}>
                                            Batal
                                        </Button>
                                        <Button type="submit" disabled={processing}>
                                            Bebaskan
                                        </Button>
                                    </DialogFooter>
                                </>
                            )}
                        </Form>
                    )}
                </DialogContent>
            </Dialog>
        </div>
    );
}

// Dialog assign kelas paralel (FR-27); kuota kelas tujuan penuh = peringatan yang bisa dikonfirmasi
function DialogAssign({
    pendaftaran,
    pilihan,
    onClose,
}: {
    pendaftaran: Baris | null;
    pilihan: KelasParalel[];
    onClose: () => void;
}) {
    const [tujuan, setTujuan] = useState('');
    const kelasTujuan = pilihan.find((k) => String(k.id) === tujuan);
    const penuh = kelasTujuan?.kuota != null && kelasTujuan.terisi >= kelasTujuan.kuota;

    function tutup() {
        setTujuan('');
        onClose();
    }

    return (
        <Dialog open={pendaftaran !== null} onOpenChange={(buka) => !buka && tutup()}>
            <DialogContent>
                {pendaftaran && (
                    <Form
                        {...PendaftaranController.assign.form(pendaftaran.id)}
                        options={{ preserveScroll: true }}
                        onSuccess={tutup}
                        className="flex flex-col gap-4"
                    >
                        {({ processing, errors }) => (
                            <>
                                <DialogHeader>
                                    <DialogTitle>Assign kelas paralel</DialogTitle>
                                    <DialogDescription>
                                        {pendaftaran.siswa.nama} ({pendaftaran.kelas}) · terdaftar di {pendaftaran.kegiatan}. Pembagian
                                        kelas ditentukan pengajar setelah melihat jumlah pendaftar; koordinasikan dulu sebelum
                                        meng-assign.
                                    </DialogDescription>
                                </DialogHeader>

                                <select
                                    name="kelas_paralel_id"
                                    value={tujuan}
                                    onChange={(e) => setTujuan(e.target.value)}
                                    className={kelasPilihan}
                                >
                                    <option value="">Pilih kelas paralel</option>
                                    {pilihan.map((k) => (
                                        <option key={k.id} value={k.id}>
                                            {k.nama} ({k.terisi}/{k.kuota ?? '∞'})
                                        </option>
                                    ))}
                                </select>

                                {penuh && (
                                    <label className="flex items-center gap-2 text-sm text-amber-700 dark:text-amber-300">
                                        <input type="checkbox" name="abaikan_kuota" value="1" className="size-4 accent-primary" />
                                        Kuota {kelasTujuan.nama} penuh, tetap assign
                                    </label>
                                )}

                                <InputError message={errors.kelas_paralel_id ?? errors.kelas_paralel ?? errors.kuota} />

                                <DialogFooter>
                                    <Button type="button" variant="ghost" onClick={tutup}>
                                        Batal
                                    </Button>
                                    <Button type="submit" disabled={processing || tujuan === ''}>
                                        Simpan
                                    </Button>
                                </DialogFooter>
                            </>
                        )}
                    </Form>
                )}
            </DialogContent>
        </Dialog>
    );
}

PendaftaranIndex.layout = {
    breadcrumbs: [{ title: 'Pendaftaran', href: index() }],
};
