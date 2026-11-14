import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import LaporanController from '@/actions/App/Domains/LesEkskul/Http/Controllers/LaporanController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { AksiTransfer, DialogTolakTransfer } from '@/components/les-ekskul/aksi-transfer';
import type { TransferRingkas } from '@/components/les-ekskul/aksi-transfer';
import { DaftarLaporanHarian } from '@/components/les-ekskul/daftar-laporan-harian';
import type { LaporanHarianBaris } from '@/components/les-ekskul/daftar-laporan-harian';
import { LabelStatus } from '@/components/les-ekskul/label-status';
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
import { hariTanggal, namaBulan, rupiah, tanggal } from '@/lib/format';
import { index } from '@/routes/les-ekskul/laporan-harian';
import { show } from '@/routes/les-ekskul/periode';
import type { Auth } from '@/types';

type Periode = { id: number; bulan: string; tanggal_mulai: string; tanggal_selesai: string; status: string };

type Props = {
    daftarPeriode: { id: number; bulan: string; status: string }[];
    periode: Periode | null;
    laporan: LaporanHarianBaris[];
    tindakan: boolean; // mode "perlu tindakan saya" (dibuka dari beranda/badge), lintas periode
    menunggu: number;
    bisaAjukanSemua: number; // jumlah laporan draft/ditolak sampai kemarin (TU)
};

const kelasPilihan = 'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';

export default function LaporanHarianIndex({ daftarPeriode, periode, laporan, tindakan, menunggu, bisaAjukanSemua }: Props) {
    const { auth, errors } = usePage<{ auth: Auth; errors: Record<string, string> }>().props;
    const tu = auth.roles.includes('tu');
    const bendahara = auth.roles.includes('bendahara');
    const [ditolak, setDitolak] = useState<LaporanHarianBaris | null>(null);
    const [transferDitolak, setTransferDitolak] = useState<TransferRingkas | null>(null);

    const kirim = (url: string, pesan?: string) => {
        if (!pesan || confirm(pesan)) {
            router.post(url, {}, { preserveScroll: true });
        }
    };

    // Tombol sesuai status laporan dan role (FR-12, Ringkasan v1.11 #15); aturan yang sama dijaga LaporanService
    function aksi(l: LaporanHarianBaris) {
        return (
            <>
                {tu && (l.status === 'draft' || l.status === 'ditolak') && (
                    <Button size="sm" onClick={() => kirim(LaporanController.ajukanHarian.url(l.id))}>
                        Ajukan
                    </Button>
                )}
                {tu && l.status === 'diajukan' && (
                    <Button size="sm" variant="outline" onClick={() => kirim(LaporanController.tarikHarian.url(l.id))}>
                        Tarik kembali
                    </Button>
                )}
                {bendahara && siapDisetujui(l).length > 0 && (
                    // Untuk hari ketika semua transfer sudah cocok dengan mutasi rekening (#32)
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() =>
                            kirim(
                                LaporanController.setujuiTransferHarian.url(l.id),
                                `Setujui ${siapDisetujui(l).length} transfer senilai ${rupiah(totalNominal(siapDisetujui(l)))} pada ${hariTanggal(l.tanggal)}? Pastikan semuanya sudah cocok dengan mutasi rekening.`,
                            )
                        }
                    >
                        Setujui {siapDisetujui(l).length} transfer
                    </Button>
                )}
                {bendahara && l.status === 'diajukan' && (
                    <>
                        <Button size="sm" onClick={() => kirim(LaporanController.setujuiHarian.url(l.id))}>
                            Setujui
                        </Button>
                        <Button size="sm" variant="outline" onClick={() => setDitolak(l)}>
                            Tolak
                        </Button>
                    </>
                )}
            </>
        );
    }

    return (
        <>
            <Head title="Laporan Harian" />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Laporan Harian"
                        description={`Laporan uang masuk per hari: TU mengajukan, Bendahara menyetujui. ${menunggu} laporan sedang menunggu persetujuan Bendahara.`}
                    />
                    {periode && (
                        <Button variant="outline" asChild>
                            <Link href={show(periode.id)}>Laporan bulanan {namaBulan(periode.bulan)}</Link>
                        </Button>
                    )}
                </div>

                <div className="flex flex-wrap items-center gap-3">
                    <select
                        value={periode?.id ?? ''}
                        onChange={(e) => router.get(index({ query: { periode: e.target.value } }).url, {}, { preserveScroll: true })}
                        className={kelasPilihan}
                    >
                        {daftarPeriode.map((p) => (
                            <option key={p.id} value={p.id}>
                                Periode {namaBulan(p.bulan)}
                            </option>
                        ))}
                    </select>
                    {periode && (
                        <span className="flex items-center gap-2 text-sm text-muted-foreground">
                            {tanggal(periode.tanggal_mulai)} – {tanggal(periode.tanggal_selesai)}
                            <LabelStatus status={periode.status} />
                        </span>
                    )}
                </div>

                {tindakan && (
                    <div className="flex flex-wrap items-center justify-between gap-2 rounded-md border border-sky-300 bg-sky-50 p-3 text-sm text-sky-900 dark:border-sky-800 dark:bg-sky-950/40 dark:text-sky-200">
                        <span>
                            Menampilkan {laporan.length} laporan yang perlu tindakan Anda dari semua periode yang belum ditutup
                            {tu && ' (belum diajukan, atau ada transfer yang perlu diverifikasi)'}
                            {bendahara && ' (menunggu persetujuan, atau ada transfer yang perlu disetujui)'}.
                        </span>
                        <Link href={index()} className="font-medium underline underline-offset-4">
                            Tampilkan semua
                        </Link>
                    </div>
                )}

                {tu && bisaAjukanSemua > 0 && (
                    <Button
                        variant="outline"
                        className="self-start"
                        onClick={() =>
                            kirim(
                                LaporanController.ajukanSemuaHarian.url(),
                                `Ajukan ${bisaAjukanSemua} laporan harian sampai kemarin ke Bendahara? Laporan hari ini tidak ikut.`,
                            )
                        }
                    >
                        Ajukan semua laporan sampai kemarin ({bisaAjukanSemua})
                    </Button>
                )}

                <InputError message={errors.laporan ?? errors.transaksi} />

                {tu && (
                    <p className="text-sm text-muted-foreground">
                        Ajukan laporan setelah transaksi hari itu selesai dicatat. Untuk menambah transaksi susulan ke laporan yang
                        sedang diajukan, tarik kembali dulu. Laporan yang sudah disetujui tidak dibuka lagi; transaksi susulannya
                        masuk laporan hari kerja berikutnya dengan penanda "dicatat terlambat".
                    </p>
                )}

                <DaftarLaporanHarian
                    laporan={laporan}
                    aksi={tu || bendahara ? aksi : undefined}
                    aksiTransaksi={
                        tu || bendahara
                            ? (t) => <AksiTransfer t={t} roles={auth.roles} onTolak={setTransferDitolak} />
                            : undefined
                    }
                    terbuka={tindakan}
                />

                <p className="text-sm text-muted-foreground">
                    Total periode: <strong>{rupiah(laporan.reduce((jumlah, l) => jumlah + l.total, 0))}</strong> dari{' '}
                    {laporan.reduce((jumlah, l) => jumlah + l.jumlah, 0)} transaksi.
                </p>
            </div>

            <DialogTolakTransfer transaksi={transferDitolak} onClose={() => setTransferDitolak(null)} />

            <Dialog open={ditolak !== null} onOpenChange={(buka) => !buka && setDitolak(null)}>
                <DialogContent>
                    {ditolak && (
                        <Form
                            {...LaporanController.tolakHarian.form(ditolak.id)}
                            options={{ preserveScroll: true }}
                            onSuccess={() => setDitolak(null)}
                            className="flex flex-col gap-4"
                        >
                            {({ processing, errors: e }) => (
                                <>
                                    <DialogHeader>
                                        <DialogTitle>Tolak laporan {hariTanggal(ditolak.tanggal)}</DialogTitle>
                                        <DialogDescription>
                                            Laporan dikembalikan ke TU untuk diperbaiki lalu diajukan ulang. Transaksinya tidak
                                            terhapus.
                                        </DialogDescription>
                                    </DialogHeader>
                                    <Input name="alasan" placeholder="Alasan, mis. total cash tidak cocok dengan uang di laci" autoFocus />
                                    <InputError message={e.alasan ?? e.laporan} />
                                    <DialogFooter>
                                        <Button type="button" variant="ghost" onClick={() => setDitolak(null)}>
                                            Batal
                                        </Button>
                                        <Button type="submit" variant="destructive" disabled={processing}>
                                            Tolak laporan
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

// Transfer yang sudah diverifikasi TU dan menunggu Bendahara
function siapDisetujui(l: LaporanHarianBaris) {
    return l.transaksi.filter((t) => t.metode === 'transfer' && t.status === 'terverifikasi' && t.status_approval_bendahara === 'belum_dicek');
}

function totalNominal(daftar: { nominal: number }[]) {
    return daftar.reduce((jumlah, t) => jumlah + t.nominal, 0);
}

LaporanHarianIndex.layout = {
    breadcrumbs: [{ title: 'Laporan Harian', href: index() }],
};
