import { Head, router, useForm, usePage } from '@inertiajs/react';
import { CalendarOff } from 'lucide-react';
import { useEffect, useState } from 'react';
import KegiatanLiburController from '@/actions/App/Domains/LesEkskul/Http/Controllers/KegiatanLiburController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { namaBulan, rupiah, tanggal } from '@/lib/format';
import { index } from '@/routes/les-ekskul/kegiatan-libur';

type Libur = {
    id: number;
    kegiatan: string;
    termasuk_kelas_paralel: boolean;
    bulan: string; // "2026-09"
    alasan: string;
    jumlah_tagihan: number;
    dialihkan: number;
    refund: number;
    dicatat_oleh: string;
    dicatat: string;
    bisa_dibatalkan: boolean;
};
type PilihanKegiatan = { kunci: string; nama: string; kelompok: string };
type Pratinjau = {
    kegiatan: string;
    bulan: string;
    jumlah_tagihan: number;
    dialihkan: number;
    refund: number;
    rincian: { siswa: string; kegiatan: string | null; dibayar: number; dialihkan: number; refund: number }[];
};
type Props = {
    tahunAjaran: { id: number; nama: string } | null;
    libur: Libur[];
    pilihanKegiatan: PilihanKegiatan[];
    pilihanBulan: string[];
    bisaUbah: boolean;
};

const kelasPilihan = 'h-9 w-full rounded-md border border-input bg-transparent px-2 text-sm shadow-xs';

// Kegiatan tidak berlangsung sebulan penuh (FR-23, Ringkasan v1.11 #48): TU menandai, Bendahara & Kepala Sekolah melihat
export default function KegiatanLibur({ tahunAjaran, libur, pilihanKegiatan, pilihanBulan, bisaUbah }: Props) {
    const [dibuka, setDibuka] = useState(false);

    function batalkan(l: Libur) {
        if (confirm(`Batalkan penandaan ${l.kegiatan} ${namaBulan(l.bulan)}? Tagihan bulan itu akan ditagih kembali.`)) {
            router.delete(KegiatanLiburController.destroy.url(l.id), { preserveScroll: true });
        }
    }

    return (
        <>
            <Head title="Kegiatan Tidak Berlangsung" />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Heading
                        title="Kegiatan Tidak Berlangsung"
                        description={`Kegiatan yang libur sebulan penuh${tahunAjaran ? ` di tahun ajaran ${tahunAjaran.nama}` : ''}. Tagihan bulan itu dibatalkan; uang yang sudah dibayar dialihkan ke bulan berikutnya.`}
                    />
                    {bisaUbah && tahunAjaran && (
                        <Button onClick={() => setDibuka(true)}>
                            <CalendarOff /> Tandai tidak berlangsung
                        </Button>
                    )}
                </div>

                {libur.length === 0 ? (
                    <p className="rounded-xl border p-6 text-center text-sm text-muted-foreground">Belum ada kegiatan yang ditandai tidak berlangsung.</p>
                ) : (
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3">Bulan</th>
                                    <th className="p-3">Kegiatan</th>
                                    <th className="p-3">Alasan</th>
                                    <th className="p-3 text-right">Tagihan dibatalkan</th>
                                    <th className="p-3 text-right">Dialihkan</th>
                                    <th className="p-3 text-right">Refund</th>
                                    <th className="p-3">Dicatat</th>
                                    <th className="p-3" />
                                </tr>
                            </thead>
                            <tbody>
                                {libur.map((l) => (
                                    <tr key={l.id} className="border-t align-top">
                                        <td className="p-3 whitespace-nowrap capitalize">{namaBulan(l.bulan)}</td>
                                        <td className="p-3">
                                            {l.kegiatan}
                                            {l.termasuk_kelas_paralel && <span className="block text-xs text-muted-foreground">semua kelas paralel</span>}
                                        </td>
                                        <td className="p-3">{l.alasan}</td>
                                        <td className="p-3 text-right tabular-nums">{l.jumlah_tagihan}</td>
                                        <td className="p-3 text-right tabular-nums">{l.dialihkan > 0 ? rupiah(l.dialihkan) : '–'}</td>
                                        <td className="p-3 text-right tabular-nums">{l.refund > 0 ? rupiah(l.refund) : '–'}</td>
                                        <td className="p-3 text-xs text-muted-foreground">
                                            {l.dicatat_oleh}
                                            <span className="block">{tanggal(l.dicatat)}</span>
                                        </td>
                                        <td className="p-3 text-right">
                                            {bisaUbah && l.bisa_dibatalkan && (
                                                <Button size="sm" variant="ghost" onClick={() => batalkan(l)}>
                                                    Batalkan
                                                </Button>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                <p className="text-xs text-muted-foreground">
                    Penandaan hanya bisa dibatalkan bila belum ada uang yang dialihkan atau direfund karenanya dan bulannya belum tutup buku.
                </p>
            </div>

            {bisaUbah && (
                <DialogTandai buka={dibuka} onTutup={() => setDibuka(false)} pilihanKegiatan={pilihanKegiatan} pilihanBulan={pilihanBulan} />
            )}
        </>
    );
}

// Form tandai: pilih kegiatan & bulan → pratinjau dampaknya → simpan
function DialogTandai({
    buka,
    onTutup,
    pilihanKegiatan,
    pilihanBulan,
}: {
    buka: boolean;
    onTutup: () => void;
    pilihanKegiatan: PilihanKegiatan[];
    pilihanBulan: string[];
}) {
    const { flash } = usePage();
    const form = useForm({ kegiatan: '', bulan: pilihanBulan[0] ?? '', alasan: '' });
    const [pratinjau, setPratinjau] = useState<Pratinjau | null>(null);

    // Hasil pratinjau dikirim sekali lewat flash
    useEffect(() => {
        const hasil = (flash as { pratinjau?: Pratinjau }).pratinjau;

        if (hasil) {
            setPratinjau(hasil);
        }
    }, [flash]);

    const kelompok = [...new Set(pilihanKegiatan.map((k) => k.kelompok))];

    // Pratinjau lama tidak berlaku lagi bila kegiatan/bulan diganti
    function ubah(field: 'kegiatan' | 'bulan', nilai: string) {
        form.setData(field, nilai);
        setPratinjau(null);
    }

    function tutup() {
        form.reset();
        form.clearErrors();
        setPratinjau(null);
        onTutup();
    }

    function lihatPratinjau() {
        form.post(KegiatanLiburController.pratinjau.url(), { preserveScroll: true, preserveState: true });
    }

    function simpan() {
        form.post(KegiatanLiburController.store.url(), { preserveScroll: true, onSuccess: tutup });
    }

    return (
        <Dialog open={buka} onOpenChange={(b) => !b && tutup()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>Tandai kegiatan tidak berlangsung</DialogTitle>
                    <DialogDescription>Sebulan penuh. Ekskul "semua kelas" berlaku juga untuk seluruh kelas paralelnya.</DialogDescription>
                </DialogHeader>

                <div className="grid gap-4">
                    <div className="grid items-start gap-4 sm:grid-cols-[2fr_1fr]">
                        <div className="grid gap-2">
                            <Label htmlFor="kegiatan">Kegiatan</Label>
                            <select id="kegiatan" value={form.data.kegiatan} onChange={(e) => ubah('kegiatan', e.target.value)} className={kelasPilihan}>
                                <option value="">Pilih kegiatan…</option>
                                {kelompok.map((g) => (
                                    <optgroup key={g} label={g}>
                                        {pilihanKegiatan
                                            .filter((k) => k.kelompok === g)
                                            .map((k) => (
                                                <option key={k.kunci} value={k.kunci}>
                                                    {k.nama}
                                                </option>
                                            ))}
                                    </optgroup>
                                ))}
                            </select>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="bulan">Bulan</Label>
                            <select id="bulan" value={form.data.bulan} onChange={(e) => ubah('bulan', e.target.value)} className={kelasPilihan}>
                                {pilihanBulan.map((b) => (
                                    <option key={b} value={b}>
                                        {namaBulan(b)}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>
                    {/* Pesan bisa panjang (mis. nama siswa yang transfernya belum diverifikasi): selebar dialog */}
                    <InputError message={form.errors.kegiatan ?? form.errors.bulan} />

                    <div className="grid gap-2">
                        <Label htmlFor="alasan">Alasan</Label>
                        <Input
                            id="alasan"
                            placeholder="mis. pelatih berhalangan selama sebulan"
                            value={form.data.alasan}
                            onChange={(e) => form.setData('alasan', e.target.value)}
                        />
                        <InputError message={form.errors.alasan} />
                    </div>

                    {pratinjau && <HasilPratinjau p={pratinjau} />}
                </div>

                <DialogFooter>
                    <Button type="button" variant="ghost" onClick={tutup}>
                        Batal
                    </Button>
                    {pratinjau ? (
                        <Button onClick={simpan} disabled={form.processing}>
                            Simpan penandaan
                        </Button>
                    ) : (
                        <Button onClick={lihatPratinjau} disabled={form.processing || !form.data.kegiatan}>
                            Lihat pratinjau
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function HasilPratinjau({ p }: { p: Pratinjau }) {
    return (
        <div className="flex flex-col gap-3 rounded-md border bg-muted/30 p-3 text-sm">
            <p>
                <span className="font-medium">{p.kegiatan}</span> · <span className="capitalize">{namaBulan(p.bulan)}</span>
            </p>
            <dl className="grid grid-cols-3 gap-2 text-center">
                <div className="rounded-md border bg-background p-2">
                    <dt className="text-xs text-muted-foreground">Tagihan dibatalkan</dt>
                    <dd className="font-medium">{p.jumlah_tagihan}</dd>
                </div>
                <div className="rounded-md border bg-background p-2">
                    <dt className="text-xs text-muted-foreground">Uang dialihkan</dt>
                    <dd className="font-medium">{rupiah(p.dialihkan)}</dd>
                </div>
                <div className="rounded-md border bg-background p-2">
                    <dt className="text-xs text-muted-foreground">Refund</dt>
                    <dd className="font-medium">{rupiah(p.refund)}</dd>
                </div>
            </dl>

            {p.rincian.length === 0 ? (
                <p className="text-muted-foreground">Belum ada tagihan bulan ini; tagihannya tidak akan dibuat.</p>
            ) : (
                <div className="max-h-56 overflow-y-auto rounded-md border bg-background">
                    <table className="w-full text-xs">
                        <thead className="sticky top-0 bg-muted text-left">
                            <tr>
                                <th className="p-2">Siswa</th>
                                <th className="p-2 text-right">Sudah dibayar</th>
                                <th className="p-2 text-right">Dialihkan</th>
                                <th className="p-2 text-right">Refund</th>
                            </tr>
                        </thead>
                        <tbody>
                            {p.rincian.map((r, i) => (
                                <tr key={i} className="border-t">
                                    <td className="p-2">
                                        {r.siswa}
                                        {r.kegiatan && <span className="block text-muted-foreground">{r.kegiatan}</span>}
                                    </td>
                                    <td className="p-2 text-right tabular-nums">{r.dibayar > 0 ? rupiah(r.dibayar) : '–'}</td>
                                    <td className="p-2 text-right tabular-nums">{r.dialihkan > 0 ? rupiah(r.dialihkan) : '–'}</td>
                                    <td className="p-2 text-right tabular-nums">{r.refund > 0 ? rupiah(r.refund) : '–'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {p.refund > 0 && <p className="text-xs text-sekolah-tertunda">Refund akan muncul di halaman Refund untuk dikembalikan ke orang tua.</p>}
        </div>
    );
}

KegiatanLibur.layout = {
    breadcrumbs: [{ title: 'Kegiatan Tidak Berlangsung', href: index() }],
};
