import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import LaporanController from '@/actions/App/Domains/LesEkskul/Http/Controllers/LaporanController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { DaftarLaporanHarian } from '@/components/les-ekskul/daftar-laporan-harian';
import type { LaporanHarianBaris } from '@/components/les-ekskul/daftar-laporan-harian';
import { LabelStatus } from '@/components/les-ekskul/label-status';
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
import { namaBulan, rupiah, tanggal, waktu } from '@/lib/format';
import { index as laporanHarian } from '@/routes/les-ekskul/laporan-harian';
import { index } from '@/routes/les-ekskul/periode';
import type { Auth } from '@/types';

type Props = {
    periode: {
        id: number;
        bulan: string;
        tanggal_mulai: string;
        tanggal_selesai: string;
        status: 'terbuka' | 'diajukan' | 'ditutup';
        diajukan_oleh: string | null;
        diajukan_at: string | null;
        alasan_dikembalikan: string | null;
        dikembalikan_oleh: string | null;
        ditutup_oleh: string | null;
        ditutup_at: string | null;
    };
    laporan: LaporanHarianBaris[];
    ringkasan: {
        jumlah_transaksi: number;
        total: number;
        total_cash: number;
        total_transfer: number;
        laporan_belum_disetujui: number;
        transfer_belum_selesai: number;
        diakui_honor: number;
    };
    hambatanAjukan: string[];
    hambatanTutupBuku: string[];
};

export default function PeriodeShow({ periode, laporan, ringkasan, hambatanAjukan, hambatanTutupBuku }: Props) {
    const { auth, errors } = usePage<{ auth: Auth; errors: Record<string, string> }>().props;
    const tu = auth.roles.includes('tu');
    const bendahara = auth.roles.includes('bendahara');
    const [dikembalikan, setDikembalikan] = useState(false);

    const kirim = (url: string, pesan?: string) => {
        if (!pesan || confirm(pesan)) {
            router.post(url, {}, { preserveScroll: true });
        }
    };

    return (
        <>
            <Head title={`Laporan ${namaBulan(periode.bulan)}`} />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title={`Laporan Bulanan ${namaBulan(periode.bulan)}`}
                        description={`Periode ${tanggal(periode.tanggal_mulai)} – ${tanggal(periode.tanggal_selesai)}. Total dihitung otomatis dari transaksi dan tidak dapat diedit; koreksi dilakukan lewat transaksinya.`}
                    />
                    <Button variant="outline" asChild>
                        <Link href={laporanHarian({ query: { periode: periode.id } })}>Kelola laporan harian</Link>
                    </Button>
                </div>

                <div className="grid gap-4 md:grid-cols-4">
                    <Ringkasan judul="Total pemasukan" isi={rupiah(ringkasan.total)} keterangan={`${ringkasan.jumlah_transaksi} transaksi`} />
                    <Ringkasan judul="Cash" isi={rupiah(ringkasan.total_cash)} />
                    <Ringkasan judul="Transfer & online" isi={rupiah(ringkasan.total_transfer)} />
                    <Ringkasan
                        judul={periode.status === 'ditutup' ? 'Diakui untuk honor' : 'Transfer belum selesai'}
                        isi={periode.status === 'ditutup' ? rupiah(ringkasan.diakui_honor) : `${ringkasan.transfer_belum_selesai} transfer`}
                    />
                </div>

                <Card>
                    <CardHeader className="flex flex-row items-center justify-between gap-4">
                        <CardTitle className="flex items-center gap-2">
                            Status laporan bulanan <LabelStatus status={periode.status} />
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-3 text-sm">
                        {periode.status === 'terbuka' && periode.alasan_dikembalikan && (
                            <p className="rounded-md border border-rose-300 bg-rose-50 p-3 text-rose-800 dark:border-rose-800 dark:bg-rose-950/40 dark:text-rose-200">
                                Dikembalikan {periode.dikembalikan_oleh}: {periode.alasan_dikembalikan}
                            </p>
                        )}
                        {periode.status === 'diajukan' && periode.diajukan_at && (
                            <p>
                                Diajukan {periode.diajukan_oleh} pada {waktu(periode.diajukan_at)}. Menunggu Bendahara menyetujui dan
                                menutup buku.
                            </p>
                        )}
                        {periode.status === 'ditutup' && periode.ditutup_at && (
                            <p>
                                Buku ditutup {periode.ditutup_oleh} pada {waktu(periode.ditutup_at)}. Pembayaran sebesar{' '}
                                {rupiah(ringkasan.diakui_honor)} diakui sebagai dasar honor periode ini; bayar di muka untuk bulan
                                berikutnya menunggu tutup buku bulannya.
                            </p>
                        )}

                        {periode.status === 'terbuka' && tu && (
                            <Langkah
                                label="Ajukan laporan bulanan ke Bendahara"
                                hambatan={hambatanAjukan}
                                onClick={() => kirim(LaporanController.ajukanBulanan.url(periode.id))}
                            />
                        )}

                        {periode.status === 'diajukan' && (
                            <div className="flex flex-wrap items-start gap-3">
                                {bendahara && (
                                    <Langkah
                                        label="Setujui & Tutup Buku"
                                        hambatan={hambatanTutupBuku}
                                        onClick={() =>
                                            kirim(
                                                LaporanController.tutupBuku.url(periode.id),
                                                `Tutup buku periode ${namaBulan(periode.bulan)}? Setelah ditutup, laporan tidak bisa dibuka kembali.`,
                                            )
                                        }
                                    />
                                )}
                                {bendahara && (
                                    <Button variant="outline" onClick={() => setDikembalikan(true)}>
                                        Kembalikan ke TU
                                    </Button>
                                )}
                                {tu && (
                                    <Button variant="outline" onClick={() => kirim(LaporanController.tarikBulanan.url(periode.id))}>
                                        Tarik kembali
                                    </Button>
                                )}
                            </div>
                        )}

                        <InputError message={errors.periode} />
                    </CardContent>
                </Card>

                <div className="flex flex-col gap-2">
                    <h3 className="font-medium">Rincian per hari</h3>
                    <DaftarLaporanHarian laporan={laporan} />
                </div>
            </div>

            <Dialog open={dikembalikan} onOpenChange={setDikembalikan}>
                <DialogContent>
                    <Form
                        {...LaporanController.kembalikanBulanan.form(periode.id)}
                        options={{ preserveScroll: true }}
                        onSuccess={() => setDikembalikan(false)}
                        className="flex flex-col gap-4"
                    >
                        {({ processing, errors: e }) => (
                            <>
                                <DialogHeader>
                                    <DialogTitle>Kembalikan laporan {namaBulan(periode.bulan)} ke TU</DialogTitle>
                                    <DialogDescription>
                                        Status kembali terbuka; TU memperbaiki lalu mengajukan ulang. Alasannya tampil di halaman ini.
                                    </DialogDescription>
                                </DialogHeader>
                                <Input name="alasan" placeholder="Alasan, mis. total transfer tidak cocok dengan mutasi rekening" autoFocus />
                                <InputError message={e.alasan ?? e.periode} />
                                <DialogFooter>
                                    <Button type="button" variant="ghost" onClick={() => setDikembalikan(false)}>
                                        Batal
                                    </Button>
                                    <Button type="submit" variant="destructive" disabled={processing}>
                                        Kembalikan
                                    </Button>
                                </DialogFooter>
                            </>
                        )}
                    </Form>
                </DialogContent>
            </Dialog>
        </>
    );
}

// Tombol aksi yang nonaktif selama ada hambatan; hambatannya ditampilkan supaya pengguna tahu apa yang harus diselesaikan
function Langkah({ label, hambatan, onClick }: { label: string; hambatan: string[]; onClick: () => void }) {
    return (
        <div className="flex flex-col gap-2">
            <Button className="self-start" disabled={hambatan.length > 0} onClick={onClick}>
                {label}
            </Button>
            {hambatan.length > 0 && (
                <ul className="list-disc pl-5 text-xs text-muted-foreground">
                    {hambatan.map((h) => (
                        <li key={h}>{h}</li>
                    ))}
                </ul>
            )}
        </div>
    );
}

function Ringkasan({ judul, isi, keterangan }: { judul: string; isi: string; keterangan?: string }) {
    return (
        <Card className="gap-2 py-4">
            <CardHeader className="px-4">
                <CardTitle className="text-sm font-normal text-muted-foreground">{judul}</CardTitle>
            </CardHeader>
            <CardContent className="px-4">
                <div className="text-2xl font-semibold">{isi}</div>
                {keterangan && <div className="text-xs text-muted-foreground">{keterangan}</div>}
            </CardContent>
        </Card>
    );
}

PeriodeShow.layout = {
    breadcrumbs: [
        { title: 'Periode Laporan', href: index() },
        { title: 'Laporan Bulanan', href: index() },
    ],
};
